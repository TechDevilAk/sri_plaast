<?php
session_start();
$currentPage = 'customers';
$pageTitle = 'Customer Payment Statement';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view
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

// Date filters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01'); // First day of current month
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d'); // Today

// Get all invoices for this customer
$invoices_query = $conn->prepare("
    SELECT * FROM invoice 
    WHERE customer_id = ? 
    ORDER BY created_at ASC
");
$invoices_query->bind_param("i", $customer_id);
$invoices_query->execute();
$invoices = $invoices_query->get_result();

// Calculate opening balance (total of all transactions before from_date)
$opening_balance_query = "
    SELECT 
        COALESCE(SUM(total), 0) as total_invoiced_before,
        COALESCE(SUM(cash_received), 0) as total_paid_before
    FROM invoice 
    WHERE customer_id = ? AND DATE(created_at) < ?
";

$opening_stmt = $conn->prepare($opening_balance_query);
$opening_stmt->bind_param("is", $customer_id, $from_date);
$opening_stmt->execute();
$opening_result = $opening_stmt->get_result();
$opening_data = $opening_result->fetch_assoc();

$opening_balance = $customer['opening_balance'] + ($opening_data['total_invoiced_before'] - $opening_data['total_paid_before']);

// Calculate summary for the period
$period_summary_query = "
    SELECT 
        COALESCE(SUM(total), 0) as period_invoiced,
        COALESCE(SUM(cash_received), 0) as period_paid,
        COUNT(*) as invoice_count,
        SUM(CASE WHEN cash_received > 0 THEN 1 ELSE 0 END) as payment_count
    FROM invoice 
    WHERE customer_id = ? AND DATE(created_at) BETWEEN ? AND ?
";

$period_stmt = $conn->prepare($period_summary_query);
$period_stmt->bind_param("iss", $customer_id, $from_date, $to_date);
$period_stmt->execute();
$period_result = $period_stmt->get_result();
$period_data = $period_result->fetch_assoc();

// Calculate closing balance
$closing_balance = $opening_balance + $period_data['period_invoiced'] - $period_data['period_paid'];

// Get all invoices for detailed table
$all_invoices_query = $conn->prepare("
    SELECT i.*, 
           (SELECT COUNT(*) FROM invoice_item WHERE invoice_id = i.id) as item_count
    FROM invoice i 
    WHERE i.customer_id = ? 
    ORDER BY i.created_at DESC
");
$all_invoices_query->bind_param("i", $customer_id);
$all_invoices_query->execute();
$all_invoices = $all_invoices_query->get_result();

// Format helpers
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function formatDate($date) {
    return date('d M Y', strtotime($date));
}

function getStatusBadge($pending_amount) {
    if ($pending_amount == 0) {
        return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Paid</span>';
    } else {
        return '<span class="badge bg-warning text-dark"><i class="bi bi-clock-history"></i> Partial</span>';
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
        /* Bank Statement Style */
        .statement-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            color: white;
        }
        
        .customer-info-statement {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .balance-card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 16px 24px;
            text-align: center;
            min-width: 200px;
        }
        
        .balance-label {
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 5px;
        }
        
        .balance-amount {
            font-size: 24px;
            font-weight: 700;
        }
        
        .balance-positive {
            color: #10b981;
        }
        
        .balance-negative {
            color: #f87171;
        }
        
        .date-filter-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
        }
        
        .summary-card.inflow {
            border-left: 4px solid #10b981;
        }
        
        .summary-card.outflow {
            border-left: 4px solid #ef4444;
        }
        
        .summary-card.balance {
            border-left: 4px solid #3b82f6;
        }
        
        .summary-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 5px;
        }
        
        .summary-value {
            font-size: 20px;
            font-weight: 600;
        }
        
        .statement-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            width: 100%;
        }
        
        .statement-table th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 13px;
            color: #475569;
            padding: 15px 12px;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
        }
        
        .statement-table td {
            padding: 12px;
            border-bottom: 1px solid #eef2f6;
            font-size: 13px;
        }
        
        .statement-table tr:last-child td {
            border-bottom: none;
        }
        
        .transaction-debit {
            color: #ef4444;
            font-weight: 600;
            text-align: right;
        }
        
        .transaction-credit {
            color: #10b981;
            font-weight: 600;
            text-align: right;
        }
        
        .transaction-ref {
            font-family: monospace;
            font-weight: 600;
            color: #3b82f6;
        }
        
        .transaction-desc {
            color: #1e293b;
        }
        
        .balance-column {
            font-weight: 600;
            text-align: right;
        }
        
        .closing-row {
            background: #f1f5f9;
            font-weight: 700;
        }
        
        .closing-row td {
            border-top: 2px solid #94a3b8;
        }
        
        .nav-tabs-custom {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
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
        
        .print-header {
            display: none;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .print-header {
                display: block;
                margin-bottom: 20px;
                padding: 20px;
                background: #f8fafc;
            }
            .statement-table {
                width: 100%;
            }
        }
        
        .opening-balance-row {
            background: #f8fafc;
            font-weight: 600;
        }
        
        .opening-balance-row td {
            border-bottom: 2px solid #cbd5e1;
        }
        
        /* Table column widths */
        .statement-table th:nth-child(1) { width: 12%; }
        .statement-table th:nth-child(2) { width: 15%; }
        .statement-table th:nth-child(3) { width: 38%; }
        .statement-table th:nth-child(4) { width: 12%; text-align: right; }
        .statement-table th:nth-child(5) { width: 12%; text-align: right; }
        .statement-table th:nth-child(6) { width: 11%; text-align: right; }
        
        .text-right {
            text-align: right;
        }
        
        .dashboard-card {
            width: 100%;
            overflow-x: auto;
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <!-- Page Header with Navigation Tabs -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div class="d-flex align-items-center gap-3">
                    <a href="customers.php" class="back-button no-print">
                        <i class="bi bi-arrow-left"></i> Back to Customers
                    </a>
                    <div>
                        <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Customer Statement</h4>
                        <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Bank style statement for <?php echo htmlspecialchars($customer['customer_name']); ?></p>
                    </div>
                </div>
                
                <!-- Navigation Tabs -->
                <div class="nav-tabs-custom no-print">
                    <a href="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>" class="nav-tab-custom">
                        <i class="bi bi-list-ul"></i> Payment History
                    </a>
                    <a href="customer_pay_history.php?customer_id=<?php echo $customer_id; ?>" class="nav-tab-custom active">
                        <i class="bi bi-bank"></i> Payment Statement
                    </a>
                </div>
            </div>

            <!-- Print Header -->
            <div class="print-header">
                <h2>Customer Statement</h2>
                <p><strong>Customer:</strong> <?php echo htmlspecialchars($customer['customer_name']); ?></p>
                <p><strong>Period:</strong> <?php echo formatDate($from_date); ?> to <?php echo formatDate($to_date); ?></p>
                <p><strong>Generated on:</strong> <?php echo date('d M Y h:i A'); ?></p>
            </div>

            <!-- Customer Header -->
            <div class="statement-header no-print">
                <div class="customer-info-statement">
                    <div>
                        <h4 style="margin: 0 0 8px 0;"><?php echo htmlspecialchars($customer['customer_name']); ?></h4>
                        <p style="margin: 0; opacity: 0.8;">Customer ID: #<?php echo $customer['id']; ?></p>
                        
                        <div style="display: flex; gap: 20px; margin-top: 15px;">
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
                    
                    <div class="balance-card">
                        <div class="balance-label">Opening Balance</div>
                        <div class="balance-amount <?php echo $opening_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                            <?php echo formatCurrency($opening_balance); ?>
                        </div>
                        <div style="font-size: 11px; opacity: 0.7;">as on <?php echo formatDate($from_date); ?></div>
                    </div>
                </div>
            </div>

            <!-- Date Filter -->
            <div class="date-filter-card no-print">
                <form method="GET" action="customer_pay_history.php" class="row g-3">
                    <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                    
                    <div class="col-md-4">
                        <label class="form-label">From Date</label>
                        <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>" required>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">To Date</label>
                        <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>" required>
                    </div>
                    
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-filter"></i> Apply Filter
                        </button>
                        <a href="customer_pay_history.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Summary Cards -->
            <div class="summary-cards no-print">
                <div class="summary-card inflow">
                    <div class="summary-label">Total Invoiced (Period)</div>
                    <div class="summary-value"><?php echo formatCurrency($period_data['period_invoiced']); ?></div>
                    <small class="text-muted"><?php echo $period_data['invoice_count']; ?> invoices</small>
                </div>
                
                <div class="summary-card outflow">
                    <div class="summary-label">Total Paid (Period)</div>
                    <div class="summary-value"><?php echo formatCurrency($period_data['period_paid']); ?></div>
                    <small class="text-muted"><?php echo $period_data['payment_count']; ?> payments</small>
                </div>
                
                <div class="summary-card balance">
                    <div class="summary-label">Closing Balance</div>
                    <div class="summary-value <?php echo $closing_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                        <?php echo formatCurrency($closing_balance); ?>
                    </div>
                    <small class="text-muted">as on <?php echo formatDate($to_date); ?></small>
                </div>
            </div>

            <!-- Statement Table -->
            <div class="dashboard-card">
                <div class="table-responsive" style="width: 100%; overflow-x: auto;">
                    <table class="statement-table" id="statementTable" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reference No.</th>
                                <th>Description</th>
                                <th class="text-right">Debit (INR)</th>
                                <th class="text-right">Credit (INR)</th>
                                <th class="text-right">Balance (INR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Opening Balance Row -->
                            <tr class="opening-balance-row">
                                <td><?php echo formatDate($from_date); ?></td>
                                <td>---</td>
                                <td><strong>Opening Balance</strong></td>
                                <td class="text-right">---</td>
                                <td class="text-right">---</td>
                                <td class="balance-column text-right <?php echo $opening_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                    <?php echo formatCurrency($opening_balance); ?>
                                </td>
                            </tr>
                            
                            <?php 
                            if ($invoices && $invoices->num_rows > 0):
                                $running_balance = $opening_balance;
                                
                                // Get all invoices
                                $invoices->data_seek(0);
                                $all_invoice_rows = [];
                                while ($row = $invoices->fetch_assoc()) {
                                    $all_invoice_rows[] = $row;
                                }
                                
                                // Create transaction array
                                $transactions = [];
                                
                                foreach ($all_invoice_rows as $invoice) {
                                    // Skip if outside date range
                                    $invoice_date = date('Y-m-d', strtotime($invoice['created_at']));
                                    if ($invoice_date < $from_date || $invoice_date > $to_date) {
                                        continue;
                                    }
                                    
                                    // Add invoice as debit
                                    $transactions[] = [
                                        'date' => $invoice['created_at'],
                                        'ref' => $invoice['inv_num'],
                                        'description' => 'Invoice #' . $invoice['inv_num'],
                                        'debit' => $invoice['total'],
                                        'credit' => 0,
                                        'type' => 'invoice'
                                    ];
                                    
                                    // Add payment as credit if payment exists
                                    if ($invoice['cash_received'] > 0) {
                                        $payment_desc = 'Payment received - ' . ucfirst($invoice['payment_method']);
                                        if ($invoice['cash_received'] < $invoice['total']) {
                                            $payment_desc .= ' (Partial)';
                                        }
                                        
                                        $transactions[] = [
                                            'date' => $invoice['created_at'],
                                            'ref' => $invoice['inv_num'],
                                            'description' => $payment_desc,
                                            'debit' => 0,
                                            'credit' => $invoice['cash_received'],
                                            'type' => 'payment'
                                        ];
                                    }
                                }
                                
                                // Sort transactions by date and type (invoices first, then payments on same day)
                                usort($transactions, function($a, $b) {
                                    if ($a['date'] == $b['date']) {
                                        // Invoices (debits) should come before payments (credits) on same day
                                        if ($a['debit'] > 0 && $b['credit'] > 0) return -1;
                                        if ($a['credit'] > 0 && $b['debit'] > 0) return 1;
                                        return 0;
                                    }
                                    return strtotime($a['date']) - strtotime($b['date']);
                                });
                                
                                // Display transactions
                                foreach ($transactions as $transaction):
                                    if ($transaction['debit'] > 0) {
                                        $running_balance += $transaction['debit'];
                                    } else {
                                        $running_balance -= $transaction['credit'];
                                    }
                            ?>
                                <tr>
                                    <td><?php echo formatDate($transaction['date']); ?></td>
                                    <td class="transaction-ref"><?php echo htmlspecialchars($transaction['ref']); ?></td>
                                    <td class="transaction-desc"><?php echo $transaction['description']; ?></td>
                                    <td class="transaction-debit text-right">
                                        <?php echo $transaction['debit'] > 0 ? formatCurrency($transaction['debit']) : '-'; ?>
                                    </td>
                                    <td class="transaction-credit text-right">
                                        <?php echo $transaction['credit'] > 0 ? formatCurrency($transaction['credit']) : '-'; ?>
                                    </td>
                                    <td class="balance-column text-right <?php echo $running_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                        <?php echo formatCurrency($running_balance); ?>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">
                                        <i class="bi bi-inbox" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                                        No transactions found for the selected period
                                    </td>
                                </tr>
                            <?php endif; ?>
                            
                            <!-- Closing Balance Row -->
                            <tr class="closing-row">
                                <td><?php echo formatDate($to_date); ?></td>
                                <td>---</td>
                                <td><strong>Closing Balance</strong></td>
                                <td class="text-right"><strong><?php echo formatCurrency($period_data['period_invoiced']); ?></strong></td>
                                <td class="text-right"><strong><?php echo formatCurrency($period_data['period_paid']); ?></strong></td>
                                <td class="balance-column text-right <?php echo $closing_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                    <strong><?php echo formatCurrency($closing_balance); ?></strong>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if (!empty($transactions)): ?>
                        <div style="background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                            <div class="d-flex justify-content-between">
                                <span>Opening Balance:</span>
                                <span class="<?php echo $opening_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?> fw-bold">
                                    <?php echo formatCurrency($opening_balance); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php 
                        $running_balance = $opening_balance;
                        foreach ($transactions as $transaction):
                            if ($transaction['debit'] > 0) {
                                $running_balance += $transaction['debit'];
                            } else {
                                $running_balance -= $transaction['credit'];
                            }
                        ?>
                            <div class="mobile-card" style="margin-bottom: 10px;">
                                <div class="mobile-card-header">
                                    <span class="transaction-ref"><?php echo htmlspecialchars($transaction['ref']); ?></span>
                                    <span><?php echo formatDate($transaction['date']); ?></span>
                                </div>
                                
                                <div class="mobile-card-body">
                                    <div style="margin-bottom: 8px;"><?php echo $transaction['description']; ?></div>
                                    
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <small class="text-muted">Debit:</small>
                                            <div class="transaction-debit"><?php echo $transaction['debit'] > 0 ? formatCurrency($transaction['debit']) : '-'; ?></div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Credit:</small>
                                            <div class="transaction-credit"><?php echo $transaction['credit'] > 0 ? formatCurrency($transaction['credit']) : '-'; ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-2 pt-2" style="border-top: 1px dashed #e2e8f0;">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Balance:</span>
                                            <span class="<?php echo $running_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?> fw-bold">
                                                <?php echo formatCurrency($running_balance); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div style="background: #f1f5f9; padding: 12px; border-radius: 8px; margin-top: 15px;">
                            <div class="d-flex justify-content-between fw-bold">
                                <span>Closing Balance:</span>
                                <span class="<?php echo $closing_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                    <?php echo formatCurrency($closing_balance); ?>
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-bank d-block mb-2" style="font-size: 48px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No transactions found</div>
                            <div style="font-size: 13px;">
                                No payment activity for the selected period.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Detailed Invoices Table -->
            <div class="dashboard-card mt-4">
                <div class="card-header py-3" style="background: white; border-bottom: 1px solid #eef2f6;">
                    <h5 class="mb-0 fw-semibold" style="font-size: 16px;">
                        <i class="bi bi-receipt me-2" style="color: #3b82f6;"></i>
                        Detailed Invoice History
                    </h5>
                </div>
                <div class="table-responsive" style="width: 100%; overflow-x: auto;">
                    <table class="table-custom" id="invoicesTable" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Paid</th>
                                <th class="text-right">Pending</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($all_invoices && $all_invoices->num_rows > 0): ?>
                                <?php while ($invoice = $all_invoices->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="fw-semibold" style="color: #3b82f6;"><?php echo htmlspecialchars($invoice['inv_num']); ?></span></td>
                                        <td><?php echo formatDate($invoice['created_at']); ?></td>
                                        <td class="text-center"><?php echo $invoice['item_count']; ?></td>
                                        <td class="text-right fw-semibold"><?php echo formatCurrency($invoice['total']); ?></td>
                                        <td class="text-right" style="color: #10b981;"><?php echo formatCurrency($invoice['cash_received']); ?></td>
                                        <td class="text-right" style="color: <?php echo $invoice['pending_amount'] > 0 ? '#dc2626' : '#64748b'; ?>;">
                                            <?php echo formatCurrency($invoice['pending_amount']); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                <i class="bi bi-<?php 
                                                    echo $invoice['payment_method'] == 'cash' ? 'cash' : 
                                                        ($invoice['payment_method'] == 'card' ? 'credit-card' : 
                                                        ($invoice['payment_method'] == 'upi' ? 'phone' : 'bank')); 
                                                ?> me-1"></i>
                                                <?php echo ucfirst($invoice['payment_method']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo getStatusBadge($invoice['pending_amount']); ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-info" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize statement table with export buttons
    $('#statementTable').DataTable({
        pageLength: 100,
        order: [],
        searching: true,
        paging: true,
        info: true,
        language: {
            search: "Search transactions:",
            lengthMenu: "Show _MENU_ transactions",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions",
            emptyTable: "No transactions available"
        },
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                title: 'Statement_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $customer['customer_name']); ?>',
                className: 'btn btn-sm btn-outline-success',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5],
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
                title: 'Statement_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $customer['customer_name']); ?>',
                className: 'btn btn-sm btn-outline-primary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5],
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
                title: 'Customer Statement - <?php echo htmlspecialchars($customer['customer_name']); ?>',
                className: 'btn btn-sm btn-outline-danger',
                orientation: 'landscape',
                pageSize: 'A4',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5]
                },
                customize: function(doc) {
                    // Add header information to PDF
                    doc.content.splice(0, 0, {
                        text: 'Customer Statement',
                        style: 'header'
                    });
                    doc.content.splice(1, 0, {
                        text: 'Customer: <?php echo htmlspecialchars($customer['customer_name']); ?>',
                        style: 'subheader'
                    });
                    doc.content.splice(2, 0, {
                        text: 'Period: <?php echo formatDate($from_date); ?> to <?php echo formatDate($to_date); ?>',
                        style: 'subheader'
                    });
                    doc.content.splice(3, 0, {
                        text: 'Generated on: <?php echo date('d M Y h:i A'); ?>',
                        style: 'subheader'
                    });
                    doc.content.splice(4, 0, {
                        text: ' ',
                        style: 'spacer'
                    });
                }
            },
            {
                extend: 'print',
                text: '<i class="bi bi-printer"></i> Print',
                className: 'btn btn-sm btn-outline-secondary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5]
                },
                customize: function(win) {
                    $(win.document.body).prepend(`
                        <div style="text-align: center; margin-bottom: 20px; padding: 20px; background: #f8fafc;">
                            <h2>Customer Statement</h2>
                            <p><strong>Customer:</strong> <?php echo htmlspecialchars($customer['customer_name']); ?></p>
                            <p><strong>Period:</strong> <?php echo formatDate($from_date); ?> to <?php echo formatDate($to_date); ?></p>
                            <p><strong>Generated on:</strong> <?php echo date('d M Y h:i A'); ?></p>
                        </div>
                    `);
                }
            }
        ]
    });

    // Initialize invoices table
    $('#invoicesTable').DataTable({
        pageLength: 10,
        order: [[1, 'desc']],
        language: {
            search: "Search invoices:",
            lengthMenu: "Show _MENU_ invoices",
            info: "Showing _START_ to _END_ of _TOTAL_ invoices",
            emptyTable: "No invoices available"
        },
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                title: 'Invoices_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $customer['customer_name']); ?>',
                className: 'btn btn-sm btn-outline-success',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7],
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
                title: 'Invoices_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $customer['customer_name']); ?>',
                className: 'btn btn-sm btn-outline-primary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7],
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
            }
        ]
    });
});

// Print statement function
function printStatement() {
    window.print();
}

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
</script>
</body>
</html>