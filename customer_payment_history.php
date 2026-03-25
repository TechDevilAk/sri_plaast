<?php
session_start();
$currentPage = 'customers';
$pageTitle = 'Customer Payment History';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view, but only admin can modify payments
checkRoleAccess(['admin', 'sale']);

// Get customer ID from URL
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if ($customer_id <= 0) {
    header('Location: customers.php');
    exit;
}

// Get customer details
$customer_query = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$customer_query->bind_param("i", $customer_id);
$customer_query->execute();
$customer_result = $customer_query->get_result();

if ($customer_result->num_rows == 0) {
    header('Location: customers.php');
    exit;
}

$customer = $customer_result->fetch_assoc();

$success = '';
$error = '';

// Handle single invoice payment collection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_payment') {
    // Check if user is admin for payment operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to collect payments.';
    } else {
        $invoice_id = intval($_POST['invoice_id']);
        $paid_amount = floatval($_POST['paid_amount']);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        
        // Get invoice details
        $invoice_query = $conn->prepare("SELECT inv_num, total, cash_received, pending_amount FROM invoice WHERE id = ? AND customer_id = ?");
        $invoice_query->bind_param("ii", $invoice_id, $customer_id);
        $invoice_query->execute();
        $invoice_result = $invoice_query->get_result();
        
        if ($invoice_result->num_rows > 0) {
            $invoice = $invoice_result->fetch_assoc();
            
            if ($paid_amount > 0 && $paid_amount <= $invoice['pending_amount']) {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    $new_paid = $invoice['cash_received'] + $paid_amount;
                    $new_pending = $invoice['pending_amount'] - $paid_amount;
                    
                    // Update invoice
                    $update = $conn->prepare("UPDATE invoice SET cash_received = ?, pending_amount = ?, payment_method = ? WHERE id = ?");
                    $update->bind_param("ddsi", $new_paid, $new_pending, $payment_method, $invoice_id);
                    $update->execute();
                    
                    // Log activity
                    $log_desc = "Payment collected of ₹" . number_format($paid_amount, 2) . " for invoice #" . $invoice['inv_num'] . " from customer: " . $customer['customer_name'];
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'payment', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $conn->commit();
                    $success = "Payment of ₹" . number_format($paid_amount, 2) . " collected successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to collect payment: " . $e->getMessage();
                }
            } else {
                $error = "Invalid payment amount. Maximum allowed: ₹" . number_format($invoice['pending_amount'], 2);
            }
        } else {
            $error = "Invoice not found.";
        }
    }
}

// Handle overall pending payment collection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_overall_pending') {
    // Check if user is admin for payment operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to collect payments.';
    } else {
        $payment_method = $_POST['payment_method'] ?? 'cash';
        
        // Get all pending invoices for this customer
        $pending_query = $conn->prepare("SELECT id, inv_num, total, cash_received, pending_amount FROM invoice WHERE customer_id = ? AND pending_amount > 0");
        $pending_query->bind_param("i", $customer_id);
        $pending_query->execute();
        $pending_result = $pending_query->get_result();
        
        if ($pending_result->num_rows > 0) {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                $total_collected = 0;
                
                while ($invoice = $pending_result->fetch_assoc()) {
                    $new_paid = $invoice['total'];
                    $new_pending = 0;
                    
                    // Update invoice
                    $update = $conn->prepare("UPDATE invoice SET cash_received = ?, pending_amount = ?, payment_method = ? WHERE id = ?");
                    $update->bind_param("ddsi", $new_paid, $new_pending, $payment_method, $invoice['id']);
                    $update->execute();
                    
                    $total_collected += $invoice['pending_amount'];
                    
                    // Log individual payment
                    $log_desc = "Payment collected of ₹" . number_format($invoice['pending_amount'], 2) . " for invoice #" . $invoice['inv_num'] . " from customer: " . $customer['customer_name'];
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'payment', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                }
                
                $conn->commit();
                $success = "Overall pending payment of ₹" . number_format($total_collected, 2) . " collected successfully.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to collect overall payment: " . $e->getMessage();
            }
        } else {
            $error = "No pending invoices found for this customer.";
        }
    }
}

// Handle delete invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_invoice' && isset($_POST['invoice_id'])) {
    // Check if user is admin for delete operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete invoices.';
    } else {
        $deleteId = intval($_POST['invoice_id']);
        
        // Get invoice details for logging and stock reversal
        $inv_query = $conn->prepare("SELECT inv_num, total FROM invoice WHERE id = ? AND customer_id = ?");
        $inv_query->bind_param("ii", $deleteId, $customer_id);
        $inv_query->execute();
        $inv_result = $inv_query->get_result();
        $inv_data = $inv_result->fetch_assoc();
        
        if ($inv_data) {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get invoice items to reverse stock
                $items_query = $conn->prepare("SELECT cat_id, quantity FROM invoice_item WHERE invoice_id = ?");
                $items_query->bind_param("i", $deleteId);
                $items_query->execute();
                $items_result = $items_query->get_result();
                
                while ($item = $items_result->fetch_assoc()) {
                    // Add back the quantity to category stock
                    if (!empty($item['cat_id'])) {
                        $update_stock = $conn->prepare("UPDATE category SET total_quantity = total_quantity + ? WHERE id = ?");
                        $update_stock->bind_param("di", $item['quantity'], $item['cat_id']);
                        $update_stock->execute();
                    }
                }
                
                // Delete invoice items (cascade will handle due to foreign key)
                $stmt = $conn->prepare("DELETE FROM invoice WHERE id = ?");
                $stmt->bind_param("i", $deleteId);
                
                if ($stmt->execute()) {
                    // Log activity
                    $log_desc = "Deleted invoice: " . $inv_data['inv_num'] . " (Total: ₹" . number_format($inv_data['total'], 2) . ") for customer: " . $customer['customer_name'];
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $conn->commit();
                    $success = "Invoice deleted successfully and stock updated.";
                } else {
                    throw new Exception("Failed to delete invoice");
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to delete invoice: " . $e->getMessage();
            }
        } else {
            $error = "Invoice not found.";
        }
    }
}

// Get all invoices for this customer with payment details
$invoices_query = $conn->prepare("
    SELECT i.*, 
           (SELECT COUNT(*) FROM invoice_item WHERE invoice_id = i.id) as item_count
    FROM invoice i 
    WHERE i.customer_id = ? 
    ORDER BY i.created_at DESC
");
$invoices_query->bind_param("i", $customer_id);
$invoices_query->execute();
$invoices = $invoices_query->get_result();

// Calculate totals
$total_billed = 0;
$total_paid = 0;
$total_pending = 0;

$invoices_data = [];
while ($inv = $invoices->fetch_assoc()) {
    $invoices_data[] = $inv;
    $total_billed += $inv['total'];
    $total_paid += $inv['cash_received'];
    $total_pending += $inv['pending_amount'];
}

// Format helpers
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function getPaymentStatusBadge($pending_amount) {
    if ($pending_amount == 0) {
        return '<span class="paid-badge"><i class="bi bi-check-circle"></i> Paid</span>';
    } else if ($pending_amount > 0 && $pending_amount < 100) {
        return '<span class="pending-badge" style="background: #fef3c7; color: #d97706;"><i class="bi bi-clock-history"></i> Partial</span>';
    } else {
        return '<span class="pending-badge"><i class="bi bi-exclamation-circle"></i> Pending</span>';
    }
}

$is_admin = ($_SESSION['user_role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
    <style>
        /* Same styling as invoices.php */
        .invoice-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, #2463eb 0%, #1e4fbd 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
        }
        
        .invoice-avatar.small {
            width: 32px;
            height: 32px;
            font-size: 14px;
        }
        
        .invoice-info-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .invoice-number-text {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        
        .invoice-meta-text {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card-custom {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        
        .stat-card-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .stat-value-large {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }
        
        .pending-badge {
            background: #fee2e2;
            color: #dc2626;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .paid-badge {
            background: #dcfce7;
            color: #16a34a;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .customer-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            color: white;
        }
        
        .customer-header h4 {
            margin: 0 0 8px 0;
            font-weight: 600;
        }
        
        .customer-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }
        
        .customer-contact {
            display: flex;
            gap: 20px;
            margin-top: 15px;
        }
        
        .customer-contact span {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            background: rgba(255,255,255,0.1);
            padding: 6px 12px;
            border-radius: 20px;
        }
        
        .overall-pending-card {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .overall-pending-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .overall-pending-amount {
            font-size: 24px;
            font-weight: 700;
            color: #b45309;
        }
        
        .payment-method-selector {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .payment-method-option {
            flex: 1;
            min-width: 80px;
        }
        
        .payment-method-option input[type="radio"] {
            display: none;
        }
        
        .payment-method-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 5px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 12px;
        }
        
        .payment-method-option input[type="radio"]:checked + label {
            border-color: #2463eb;
            background: #eef2ff;
            color: #2463eb;
        }
        
        .payment-method-option label i {
            font-size: 20px;
            margin-bottom: 4px;
        }
        
        .back-button {
            background: white;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
         .back-button2 {
            background:blue;
            color: white;
            border: 1px solid #e2e8f0;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            margin-right: 300px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-button:hover {
            background: #f8fafc;
        }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        /* Collect Payment Button */
        .collect-payment-btn {
            background: #10b981;
            color: white;
            border: none;
        }
        
        .collect-payment-btn:hover {
            background: #059669;
            color: white;
        }
        .nav-tabs-custom {
    display: flex;
    gap: 10px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 10px;
}

.nav-tab-custom {
    padding: 8px 20px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    color: #64748b;
    transition: all 0.2s;
}

.nav-tab-custom:hover {
    background: #f1f5f9;
    color: #1e293b;
}

.nav-tab-custom.active {
    background: #3b82f6;
    color: white;
}

.nav-tab-custom i {
    margin-right: 8px;
}
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <!-- Page Header with Back Button and Navigation Tabs -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="customers.php" class="back-button">
            <i class="bi bi-arrow-left"></i> Back to Customers
        </a>
        <div>
            <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Payment History</h4>
            <p style="font-size: 14px; color: var(--text-muted); margin: 0;">View and manage customer payments</p>
        </div>
    </div>
    
    <!-- Navigation Tabs -->
    <div class="nav-tabs-custom">
        <a href="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>" class="nav-tab-custom active">
            <i class="bi bi-list-ul"></i> Payment History
        </a>
        <a href="customer_pay_history.php?customer_id=<?php echo $customer_id; ?>" class="nav-tab-custom">
            <i class="bi bi-bank"></i> payment Statement
        </a>
    </div>
    
    <?php if (!$is_admin): ?>
        <span class="permission-badge"><i class="bi bi-eye"></i> View Only Mode</span>
    <?php endif; ?>
</div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Customer Header -->
            <div class="customer-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <h4><?php echo htmlspecialchars($customer['customer_name']); ?></h4>
                        <p>Customer ID: #<?php echo $customer['id']; ?></p>
                        
                        <div class="customer-contact">
                            <?php if (!empty($customer['phone'])): ?>
                                <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($customer['phone']); ?></span>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['email'])): ?>
                                <span><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($customer['email']); ?></span>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['gst_number'])): ?>
                                <span><i class="bi bi-file-text"></i> <?php echo htmlspecialchars($customer['gst_number']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="background: rgba(255,255,255,0.1); padding: 12px 20px; border-radius: 12px; text-align: center;">
                        <div style="font-size: 12px; opacity: 0.8;">Opening Balance</div>
                        <div style="font-size: 20px; font-weight: 600;"><?php echo formatCurrency($customer['opening_balance']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo formatCurrency($total_billed); ?></div>
                            <div class="stat-label">Total Billed</div>
                        </div>
                        <div class="stat-icon blue" style="width: 48px; height: 48px;">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo formatCurrency($total_paid); ?></div>
                            <div class="stat-label">Total Paid</div>
                        </div>
                        <div class="stat-icon green" style="width: 48px; height: 48px;">
                            <i class="bi bi-cash"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large" style="color: #dc2626;"><?php echo formatCurrency($total_pending); ?></div>
                            <div class="stat-label">Total Pending</div>
                        </div>
                        <div class="stat-icon orange" style="width: 48px; height: 48px;">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo count($invoices_data); ?></div>
                            <div class="stat-label">Total Invoices</div>
                        </div>
                        <div class="stat-icon purple" style="width: 48px; height: 48px;">
                            <i class="bi bi-file-text"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overall Pending Section -->
            <?php if ($total_pending > 0 && $is_admin): ?>
                <div class="overall-pending-card">
                    <div class="overall-pending-info">
                        <div>
                            <div style="font-size: 14px; color: #92400e; margin-bottom: 5px;">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                Overall Pending Amount
                            </div>
                            <div class="overall-pending-amount"><?php echo formatCurrency($total_pending); ?></div>
                        </div>
                        
                        <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#overallPaymentModal">
                            <i class="bi bi-cash-stack me-2"></i>
                            Collect Overall Pending
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Invoices Table -->
            <div class="dashboard-card" data-testid="invoices-table">
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="paymentHistoryTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Invoice Details</th>
                                <th>Items</th>
                                <th>Total Amount</th>
                                <th>Paid Amount</th>
                                <th>Pending Amount</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                                <th>Date</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($invoices_data)): ?>
                                <?php foreach ($invoices_data as $invoice): ?>
                                    <tr>
                                        <td><span class="order-id">#<?php echo $invoice['id']; ?></span></td>
                                        <td>
                                            <div class="invoice-info-cell">
                                                <div class="invoice-avatar small">INV</div>
                                                <div>
                                                    <div class="invoice-number-text"><?php echo htmlspecialchars($invoice['inv_num']); ?></div>
                                                    <div class="invoice-meta-text">
                                                        <i class="bi bi-box-seam"></i> <?php echo $invoice['item_count']; ?> items
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center"><?php echo $invoice['item_count']; ?></td>
                                        <td class="fw-semibold"><?php echo formatCurrency($invoice['total']); ?></td>
                                        <td class="fw-semibold" style="color: #10b981;"><?php echo formatCurrency($invoice['cash_received']); ?></td>
                                        <td class="fw-semibold" style="color: <?php echo $invoice['pending_amount'] > 0 ? '#dc2626' : '#64748b'; ?>;">
                                            <?php echo formatCurrency($invoice['pending_amount']); ?>
                                        </td>
                                        <td>
                                            <span class="payment-method-badge">
                                                <i class="bi bi-<?php 
                                                    echo $invoice['payment_method'] == 'cash' ? 'cash' : 
                                                        ($invoice['payment_method'] == 'card' ? 'credit-card' : 
                                                        ($invoice['payment_method'] == 'upi' ? 'phone' : 
                                                        ($invoice['payment_method'] == 'bank' ? 'bank' : 'journal-bookmark-fill'))); 
                                                ?>"></i>
                                                <?php echo ucfirst($invoice['payment_method']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo getPaymentStatusBadge($invoice['pending_amount']); ?>
                                        </td>
                                        <td style="color: var(--text-muted); white-space: nowrap;">
                                            <?php echo date('d M Y', strtotime($invoice['created_at'])); ?>
                                            <div class="text-muted" style="font-size: 10px;"><?php echo date('h:i A', strtotime($invoice['created_at'])); ?></div>
                                        </td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <!-- View Invoice -->
                                                    <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-info" style="font-size: 12px; padding: 3px 8px;" title="View Invoice">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    
                                                    <!-- Print Invoice -->
                                                    <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size: 12px; padding: 3px 8px;" title="Print Invoice">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                    
                                                    <!-- Collect Payment (if pending) -->
                                                    <?php if ($invoice['pending_amount'] > 0): ?>
                                                        <button class="btn btn-sm collect-payment-btn" style="font-size: 12px; padding: 3px 8px;" 
                                                                data-bs-toggle="modal" data-bs-target="#paymentModal<?php echo $invoice['id']; ?>" 
                                                                title="Collect Payment">
                                                            <i class="bi bi-cash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Delete Invoice -->
                                                    <form method="POST" action="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>" 
                                                          style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this invoice? This will reverse the stock and cannot be undone.')">
                                                        <input type="hidden" name="action" value="delete_invoice">
                                                        <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" 
                                                                title="Delete Invoice">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Payment Collection Modal -->
                                    <?php if ($invoice['pending_amount'] > 0): ?>
                                        <div class="modal fade" id="paymentModal<?php echo $invoice['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST" action="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>">
                                                        <input type="hidden" name="action" value="collect_payment">
                                                        <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                        
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">
                                                                <i class="bi bi-cash me-2"></i>
                                                                Collect Payment - <?php echo $invoice['inv_num']; ?>
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Invoice Total</label>
                                                                <input type="text" class="form-control" value="<?php echo formatCurrency($invoice['total']); ?>" readonly disabled>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Already Paid</label>
                                                                <input type="text" class="form-control" value="<?php echo formatCurrency($invoice['cash_received']); ?>" readonly disabled>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Pending Amount</label>
                                                                <input type="text" class="form-control bg-light text-danger fw-bold" 
                                                                       value="<?php echo formatCurrency($invoice['pending_amount']); ?>" readonly disabled>
                                                            </div>
                                                            
                                                            <hr>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                                                <div class="payment-method-selector">
                                                                    <div class="payment-method-option">
                                                                        <input type="radio" name="payment_method" id="cash<?php echo $invoice['id']; ?>" value="cash" checked>
                                                                        <label for="cash<?php echo $invoice['id']; ?>">
                                                                            <i class="bi bi-cash"></i>
                                                                            <span>Cash</span>
                                                                        </label>
                                                                    </div>
                                                                    
                                                                    <div class="payment-method-option">
                                                                        <input type="radio" name="payment_method" id="card<?php echo $invoice['id']; ?>" value="card">
                                                                        <label for="card<?php echo $invoice['id']; ?>">
                                                                            <i class="bi bi-credit-card"></i>
                                                                            <span>Card</span>
                                                                        </label>
                                                                    </div>
                                                                    
                                                                    <div class="payment-method-option">
                                                                        <input type="radio" name="payment_method" id="upi<?php echo $invoice['id']; ?>" value="upi">
                                                                        <label for="upi<?php echo $invoice['id']; ?>">
                                                                            <i class="bi bi-phone"></i>
                                                                            <span>UPI</span>
                                                                        </label>
                                                                    </div>
                                                                    
                                                                    <div class="payment-method-option">
                                                                        <input type="radio" name="payment_method" id="bank<?php echo $invoice['id']; ?>" value="bank">
                                                                        <label for="bank<?php echo $invoice['id']; ?>">
                                                                            <i class="bi bi-bank"></i>
                                                                            <span>Bank</span>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Amount to Collect <span class="text-danger">*</span></label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">₹</span>
                                                                    <input type="number" name="paid_amount" class="form-control" 
                                                                           step="0.01" min="0.01" max="<?php echo $invoice['pending_amount']; ?>" 
                                                                           value="<?php echo $invoice['pending_amount']; ?>" required>
                                                                </div>
                                                                <small class="text-muted">Maximum: <?php echo formatCurrency($invoice['pending_amount']); ?></small>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success">Collect Payment</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if (!empty($invoices_data)): ?>
                        <?php foreach ($invoices_data as $invoice): ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="invoice-avatar small">INV</div>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($invoice['inv_num']); ?></div>
                                            <div style="font-size: 11px; color: var(--text-muted);">
                                                <?php echo date('d M Y', strtotime($invoice['created_at'])); ?> • <?php echo $invoice['item_count']; ?> items
                                            </div>
                                        </div>
                                    </div>
                                    <?php echo getPaymentStatusBadge($invoice['pending_amount']); ?>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Total Amount</span>
                                    <span class="mobile-card-value fw-bold"><?php echo formatCurrency($invoice['total']); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Paid Amount</span>
                                    <span class="mobile-card-value" style="color: #10b981;"><?php echo formatCurrency($invoice['cash_received']); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Pending Amount</span>
                                    <span class="mobile-card-value" style="color: <?php echo $invoice['pending_amount'] > 0 ? '#dc2626' : '#64748b'; ?>;">
                                        <?php echo formatCurrency($invoice['pending_amount']); ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Payment Method</span>
                                    <span class="mobile-card-value">
                                        <span class="payment-method-badge">
                                            <i class="bi bi-<?php 
                                                echo $invoice['payment_method'] == 'cash' ? 'cash' : 
                                                    ($invoice['payment_method'] == 'card' ? 'credit-card' : 
                                                    ($invoice['payment_method'] == 'upi' ? 'phone' : 'bank')); 
                                            ?>"></i>
                                            <?php echo ucfirst($invoice['payment_method']); ?>
                                        </span>
                                    </span>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                    <div class="mobile-card-actions">
                                        <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-info flex-fill">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        
                                        <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary flex-fill">
                                            <i class="bi bi-printer me-1"></i>Print
                                        </a>
                                        
                                        <?php if ($invoice['pending_amount'] > 0): ?>
                                            <button class="btn btn-sm collect-payment-btn flex-fill" data-bs-toggle="modal" data-bs-target="#paymentModal<?php echo $invoice['id']; ?>">
                                                <i class="bi bi-cash me-1"></i>Collect
                                            </button>
                                        <?php endif; ?>
                                        
                                        <form method="POST" action="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>" 
                                              style="flex: 1;" onsubmit="return confirm('Delete this invoice? This will reverse stock.')">
                                            <input type="hidden" name="action" value="delete_invoice">
                                            <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                <i class="bi bi-trash me-1"></i>Delete
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-receipt d-block mb-2" style="font-size: 48px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No invoices found</div>
                            <div style="font-size: 13px;">
                                This customer has no invoice history yet.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Overall Payment Modal -->
<?php if ($total_pending > 0 && $is_admin): ?>
    <div class="modal fade" id="overallPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>">
                    <input type="hidden" name="action" value="collect_overall_pending">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-cash-stack me-2"></i>
                            Collect Overall Pending
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Customer</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($customer['customer_name']); ?>" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Total Pending Amount</label>
                            <input type="text" class="form-control bg-light text-danger fw-bold" 
                                   value="<?php echo formatCurrency($total_pending); ?>" readonly disabled>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            This will mark all pending invoices as paid. Total amount to collect: <?php echo formatCurrency($total_pending); ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <div class="payment-method-selector">
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="overall_cash" value="cash" checked>
                                    <label for="overall_cash">
                                        <i class="bi bi-cash"></i>
                                        <span>Cash</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="overall_card" value="card">
                                    <label for="overall_card">
                                        <i class="bi bi-credit-card"></i>
                                        <span>Card</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="overall_upi" value="upi">
                                    <label for="overall_upi">
                                        <i class="bi bi-phone"></i>
                                        <span>UPI</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="overall_bank" value="bank">
                                    <label for="overall_bank">
                                        <i class="bi bi-bank"></i>
                                        <span>Bank</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to collect all pending payments?')">
                            <i class="bi bi-check-circle me-2"></i>
                            Collect ₹<?php echo number_format($total_pending, 2); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    $('#paymentHistoryTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search invoices:",
            lengthMenu: "Show _MENU_ invoices",
            info: "Showing _START_ to _END_ of _TOTAL_ invoices",
            emptyTable: "No invoices available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: -1 }
            <?php endif; ?>
        ],
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                title: 'Payment_History_<?php echo $customer['customer_name']; ?>',
                className: 'btn btn-sm btn-outline-success',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 8],
                    format: {
                        body: function(data, row, column, node) {
                            // Remove ₹ symbol and commas for numeric fields
                            if (column === 3 || column === 4 || column === 5) {
                                return data.replace(/[₹,]/g, '');
                            }
                            return data;
                        }
                    }
                }
            },
            {
                extend: 'csvHtml5',
                text: '<i class="bi bi-file-earmark-spreadsheet"></i> CSV',
                title: 'Payment_History_<?php echo $customer['customer_name']; ?>',
                className: 'btn btn-sm btn-outline-primary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 8],
                    format: {
                        body: function(data, row, column, node) {
                            // Remove ₹ symbol and commas for numeric fields
                            if (column === 3 || column === 4 || column === 5) {
                                return data.replace(/[₹,]/g, '');
                            }
                            return data;
                        }
                    }
                }
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="bi bi-file-earmark-pdf"></i> PDF',
                title: 'Payment History - <?php echo $customer['customer_name']; ?>',
                className: 'btn btn-sm btn-outline-danger',
                orientation: 'landscape',
                pageSize: 'A4',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 8]
                }
            },
            {
                extend: 'print',
                text: '<i class="bi bi-printer"></i> Print',
                className: 'btn btn-sm btn-outline-secondary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 8]
                }
            }
        ]
    });
});

// Validate payment amount
function validatePayment(input, maxAmount) {
    let value = parseFloat(input.value) || 0;
    if (value > maxAmount) {
        input.value = maxAmount;
        alert('Amount cannot exceed pending amount: ₹' + maxAmount.toFixed(2));
    }
    if (value < 0.01) {
        input.value = 0.01;
    }
}

// Format currency for display
function formatCurrency(amount) {
    return '₹' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}
</script>
</body>
</html>