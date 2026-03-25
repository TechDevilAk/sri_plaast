<?php
session_start();
$currentPage = 'sales';
$pageTitle = 'View Invoice';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view invoices
checkRoleAccess(['admin', 'sale']);

$error = '';
$success = '';

// Get invoice ID from URL
$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoice_id <= 0) {
    header('Location: sales.php');
    exit;
}

// Fetch invoice details
$invoice_query = $conn->prepare("
    SELECT i.*, c.customer_name, c.phone, c.email, c.address, c.gst_number
    FROM invoice i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.id = ?
");
$invoice_query->bind_param("i", $invoice_id);
$invoice_query->execute();
$invoice = $invoice_query->get_result()->fetch_assoc();

if (!$invoice) {
    header('Location: sales.php');
    exit;
}

// Fetch invoice items
$items_query = $conn->prepare("SELECT * FROM invoice_item WHERE invoice_id = ?");
$items_query->bind_param("i", $invoice_id);
$items_query->execute();
$items = $items_query->get_result();

// ✅ Profit calculation (PCS-based)
// Cost = purchase_price * no_of_pcs
// Sales = selling_price  * no_of_pcs
// Profit = Sales - Cost
$profit_query = $conn->prepare("
    SELECT
        COALESCE(SUM(ii.purchase_price * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)),0) AS cost_value,
        COALESCE(SUM(ii.selling_price  * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)),0) AS sales_value,
        COALESCE(
            SUM(ii.selling_price  * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) -
            SUM(ii.purchase_price * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity))
        ,0) AS profit_value,
        COALESCE(SUM(COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)),0) AS total_pcs
    FROM invoice_item ii
    WHERE ii.invoice_id = ?
");
$profit_query->bind_param("i", $invoice_id);
$profit_query->execute();
$profitStats = $profit_query->get_result()->fetch_assoc();

$cost_value   = (float)($profitStats['cost_value'] ?? 0);
$sales_value  = (float)($profitStats['sales_value'] ?? 0);
$profit_value = (float)($profitStats['profit_value'] ?? 0);
$total_pcs    = (float)($profitStats['total_pcs'] ?? 0);

// Check if user is admin for certain actions
$is_admin = ($_SESSION['user_role'] === 'admin');

// Handle payment collection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_payment') {

    $payment_amount = (float)($_POST['payment_amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';

    if ($payment_amount <= 0) {
        $error = "Please enter a valid payment amount.";
    } elseif ($payment_amount > (float)$invoice['pending_amount']) {
        $error = "Payment amount exceeds pending amount.";
    } else {
        $conn->begin_transaction();

        try {
            $new_pending = (float)$invoice['pending_amount'] - $payment_amount;
            if ($new_pending < 0) $new_pending = 0;

            $update = $conn->prepare("UPDATE invoice SET pending_amount = ? WHERE id = ?");
            $update->bind_param("di", $new_pending, $invoice_id);
            $update->execute();

            // Log activity
            $log_desc = "Payment collected of ₹" . number_format($payment_amount,2) . " for invoice #" . $invoice['inv_num'];
            $log_stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, 'payment', ?)");
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();

            $conn->commit();
            $success = "Payment collected successfully.";

            // Refresh invoice data
            $invoice_query->execute();
            $invoice = $invoice_query->get_result()->fetch_assoc();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to collect payment: " . $e->getMessage();
        }
    }
}

// Handle invoice cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_invoice') {

    if (!$is_admin) {
        $error = "Only admins can cancel invoices.";
    } else {
        $conn->begin_transaction();

        try {
            // Get items to restore stock
            $items_query->execute();
            $items_restore = $items_query->get_result();

            while ($item = $items_restore->fetch_assoc()) {
                if (!empty($item['cat_id'])) {
                    // ✅ Restore stock by PCS (no_of_pcs), fallback to quantity
                    $restore_qty = (float)($item['no_of_pcs'] ?? 0);
                    if ($restore_qty <= 0) $restore_qty = (float)($item['quantity'] ?? 0);

                    $update_stock = $conn->prepare("UPDATE category SET total_quantity = total_quantity + ? WHERE id = ?");
                    $update_stock->bind_param("di", $restore_qty, $item['cat_id']);
                    $update_stock->execute();
                }
            }

            // Delete items
            $delete_items = $conn->prepare("DELETE FROM invoice_item WHERE invoice_id = ?");
            $delete_items->bind_param("i", $invoice_id);
            $delete_items->execute();

            // Delete invoice
            $delete_invoice = $conn->prepare("DELETE FROM invoice WHERE id = ?");
            $delete_invoice->bind_param("i", $invoice_id);
            $delete_invoice->execute();

            // Log activity
            $log_desc = "Cancelled invoice #" . $invoice['inv_num'];
            $log_stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, 'cancel', ?)");
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();

            $conn->commit();

            header('Location: sales.php?msg=invoice_cancelled');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to cancel invoice: " . $e->getMessage();
        }
    }
}

// Helper function for payment status
function getPaymentStatus($pending) {
    if ((float)$pending == 0) {
        return ['class' => 'completed', 'text' => 'Paid', 'icon' => 'bi-check-circle'];
    } else {
        return ['class' => 'pending', 'text' => 'Pending', 'icon' => 'bi-clock-history'];
    }
}

// Helper function for payment method
function getPaymentMethodBadge($method) {
    switch($method) {
        case 'cash':   return ['class' => 'success', 'icon' => 'bi-cash-stack', 'text' => 'Cash'];
        case 'card':   return ['class' => 'primary', 'icon' => 'bi-credit-card', 'text' => 'Card'];
        case 'upi':    return ['class' => 'info', 'icon' => 'bi-phone', 'text' => 'UPI'];
        case 'bank':   return ['class' => 'warning', 'icon' => 'bi-bank', 'text' => 'Bank'];
        case 'credit': return ['class' => 'danger', 'icon' => 'bi-clock-history', 'text' => 'Credit'];
        case 'mixed':  return ['class' => 'secondary', 'icon' => 'bi-shuffle', 'text' => 'Mixed'];
        default:       return ['class' => 'secondary', 'icon' => 'bi-question-circle', 'text' => ucfirst((string)$method)];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        /* Invoice View Specific Styles */
        .invoice-container {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            border: 1px solid #eef2f6;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
        }

        .invoice-title {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 5px;
        }

        .invoice-subtitle {
            font-size: 14px;
            color: #64748b;
        }

        .invoice-badge {
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .invoice-badge.paid {
            background: #dcfce7;
            color: #16a34a;
        }

        .invoice-badge.pending {
            background: #fee2e2;
            color: #dc2626;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-card {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #e2e8f0;
        }

        .info-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
        }

        .info-small {
            font-size: 14px;
            color: #475569;
        }

        .amount-box {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            border-radius: 16px;
            padding: 25px;
        }

        .amount-label {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .amount-value {
            font-size: 36px;
            font-weight: 700;
            line-height: 1.2;
        }

        .amount-small {
            font-size: 16px;
            opacity: 0.9;
        }

        .table-invoice {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table-invoice th {
            background: #f8fafc;
            padding: 16px 20px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }

        .table-invoice td {
            padding: 16px 20px;
            border-bottom: 1px solid #eef2f6;
            font-size: 14px;
        }

        .table-invoice tbody tr:hover td {
            background-color: #f8fafc;
        }

        .summary-box {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #e2e8f0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .summary-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .summary-label {
            color: #475569;
            font-size: 14px;
        }

        .summary-value {
            font-weight: 600;
            color: #0f172a;
        }

        .summary-total {
            font-size: 20px;
            font-weight: 700;
            color: #2563eb;
        }

        .action-bar {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            border: 1px solid #eef2f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn-action {
            padding: 10px 20px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .payment-section {
            background: #f0f9ff;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            border: 1px solid #bae6fd;
        }

        .badge-gst {
            background: #e6f0ff;
            color: #2563eb;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
        }

        .signature-area {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            padding: 0 20px;
        }

        .signature-line {
            width: 200px;
            border-top: 1px solid #cbd5e1;
            margin-bottom: 5px;
        }

        @media print {
            .no-print { display: none !important; }
            .invoice-container { box-shadow: none; border: 1px solid #ddd; }
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-badge.completed { background: #dcfce7; color: #16a34a; }
        .status-badge.pending { background: #fee2e2; color: #dc2626; }
        .status-badge.cancelled { background: #f1f5f9; color: #64748b; }

        .method-badge {
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .method-badge.success { background: #dcfce7; color: #16a34a; }
        .method-badge.primary { background: #dbeafe; color: #2563eb; }
        .method-badge.info { background: #cffafe; color: #0891b2; }
        .method-badge.warning { background: #fef3c7; color: #d97706; }
        .method-badge.danger { background: #fee2e2; color: #dc2626; }
        .method-badge.secondary { background: #f1f5f9; color: #475569; }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Invoice Details</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">View and manage invoice #<?php echo htmlspecialchars($invoice['inv_num']); ?></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="sales.php" class="btn-outline-custom">
                        <i class="bi bi-arrow-left"></i> Back to Sales
                    </a>
                    <button onclick="window.print()" class="btn-outline-custom">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <?php if ($is_admin && (float)$invoice['pending_amount'] > 0): ?>
                        <a href="invoice-edit.php?id=<?php echo $invoice_id; ?>" class="btn-outline-custom">
                            <i class="bi bi-pencil-square"></i> Edit
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ((float)$invoice['pending_amount'] > 0): ?>
            <div class="action-bar no-print">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-exclamation-circle text-danger" style="font-size: 24px;"></i>
                    <div>
                        <strong class="d-block">Pending Payment: ₹<?php echo number_format((float)$invoice['pending_amount'], 2); ?></strong>
                        <small class="text-muted">This invoice has pending payment</small>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-success btn-action" onclick="showPaymentModal()">
                        <i class="bi bi-cash-stack"></i> Collect Payment
                    </button>
                    <?php if ($is_admin): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel this invoice? Stock will be restored.')">
                            <input type="hidden" name="action" value="cancel_invoice">
                            <button type="submit" class="btn btn-danger btn-action">
                                <i class="bi bi-x-circle"></i> Cancel Invoice
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="invoice-container">

                <div class="invoice-header">
                    <div>
                        <h1 class="invoice-title">INVOICE</h1>
                        <div class="invoice-subtitle">#<?php echo htmlspecialchars($invoice['inv_num']); ?></div>
                    </div>
                    <div>
                        <?php $status = getPaymentStatus($invoice['pending_amount']); ?>
                        <span class="invoice-badge <?php echo $status['class']; ?>">
                            <i class="bi <?php echo $status['icon']; ?>"></i>
                            <?php echo $status['text']; ?>
                        </span>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label">Invoice Details</div>
                        <div class="info-value"><?php echo htmlspecialchars($invoice['inv_num']); ?></div>
                        <div class="info-small">
                            <i class="bi bi-calendar me-1"></i> <?php echo date('d M Y', strtotime($invoice['created_at'])); ?><br>
                            <i class="bi bi-clock me-1"></i> <?php echo date('h:i A', strtotime($invoice['created_at'])); ?>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Bill To</div>
                        <div class="info-value"><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></div>
                        <div class="info-small">
                            <?php if (!empty($invoice['phone'])): ?>
                                <i class="bi bi-telephone me-1"></i> <?php echo htmlspecialchars($invoice['phone']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['email'])): ?>
                                <i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($invoice['email']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['gst_number'])): ?>
                                <i class="bi bi-building me-1"></i> GST: <?php echo htmlspecialchars($invoice['gst_number']); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Payment Details</div>
                        <?php $method = getPaymentMethodBadge($invoice['payment_method']); ?>
                        <div class="info-value">
                            <span class="method-badge <?php echo $method['class']; ?>">
                                <i class="bi <?php echo $method['icon']; ?>"></i>
                                <?php echo $method['text']; ?>
                            </span>
                        </div>
                        <div class="info-small mt-2">
                            <div class="d-flex justify-content-between">
                                <span>Received:</span>
                                <span class="fw-semibold">₹<?php echo number_format((float)$invoice['cash_received'], 2); ?></span>
                            </div>
                            <?php if ((float)$invoice['change_give'] > 0): ?>
                            <div class="d-flex justify-content-between">
                                <span>Change:</span>
                                <span class="fw-semibold">₹<?php echo number_format((float)$invoice['change_give'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ((float)$invoice['pending_amount'] > 0): ?>
                            <div class="d-flex justify-content-between text-danger">
                                <span>Pending:</span>
                                <span class="fw-semibold">₹<?php echo number_format((float)$invoice['pending_amount'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="amount-box">
                        <div class="amount-label">Grand Total</div>
                        <div class="amount-value">₹<?php echo number_format((float)$invoice['total'], 2); ?></div>
                        <div class="amount-small">(Inclusive of all taxes)</div>
                    </div>
                </div>

                <!-- ✅ Profit summary line -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Total PCS Sold</div>
                            <div class="info-value"><?php echo number_format($total_pcs, 0); ?> pcs</div>
                            <div class="info-small">Based on no_of_pcs</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Investment (Cost)</div>
                            <div class="info-value">₹<?php echo number_format($cost_value, 2); ?></div>
                            <div class="info-small">purchase_price × pcs</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card">
                            <div class="info-label">Profit</div>
                            <div class="info-value" style="color: <?php echo $profit_value >= 0 ? '#16a34a' : '#dc2626'; ?>;">
                                ₹<?php echo number_format($profit_value, 2); ?>
                            </div>
                            <div class="info-small">selling_price × pcs − cost</div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive mt-4">
                    <table class="table-invoice">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>HSN</th>
                                <th class="text-center">Qty</th>
                                <th class="text-center">Unit</th>
                                <th class="text-center">PCS</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Discount</th>
                                <th class="text-end">Taxable</th>
                                <th class="text-center">CGST</th>
                                <th class="text-center">SGST</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $counter = 1;
                            $items->data_seek(0);
                            while ($item = $items->fetch_assoc()):
                                $pcs = (float)($item['no_of_pcs'] ?? 0);
                                if ($pcs <= 0) $pcs = (float)($item['quantity'] ?? 0);
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><span class="fw-semibold"><?php echo htmlspecialchars($item['product_name']); ?></span></td>
                                    <td><?php echo htmlspecialchars($item['cat_name']); ?></td>
                                    <td><span class="badge-gst"><?php echo htmlspecialchars($item['hsn']); ?></span></td>
                                    <td class="text-center"><?php echo number_format((float)$item['quantity'], 2); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($item['unit']); ?></td>
                                    <td class="text-center"><?php echo number_format($pcs, 0); ?></td>
                                    <td class="text-end">₹<?php echo number_format((float)$item['selling_price'], 2); ?></td>
                                    <td class="text-end">
                                        <?php if ((float)$item['discount'] > 0): ?>
                                            <?php echo $item['discount_type'] === 'percentage' ? $item['discount'].'%' : '₹'.number_format((float)$item['discount'], 2); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">₹<?php echo number_format((float)$item['taxable'], 2); ?></td>
                                    <td class="text-center"><?php echo (float)$item['cgst']; ?>% (₹<?php echo number_format((float)$item['cgst_amount'], 2); ?>)</td>
                                    <td class="text-center"><?php echo (float)$item['sgst']; ?>% (₹<?php echo number_format((float)$item['sgst_amount'], 2); ?>)</td>
                                    <td class="text-end fw-semibold">₹<?php echo number_format((float)$item['total'], 2); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <div class="row mt-4">
                    <div class="col-md-7">
                        <div class="info-card h-100">
                            <div class="info-label mb-2">Notes</div>
                            <p style="color: #475569; font-size: 14px; margin: 0;">
                                <?php if ((float)$invoice['pending_amount'] > 0): ?>
                                    <i class="bi bi-exclamation-circle text-warning me-1"></i>
                                    Payment pending: ₹<?php echo number_format((float)$invoice['pending_amount'], 2); ?><br>
                                <?php endif; ?>
                                <i class="bi bi-info-circle text-info me-1"></i>
                                This is a computer generated invoice - no signature required.<br>
                                <small class="text-muted">Created on <?php echo date('d M Y h:i A', strtotime($invoice['created_at'])); ?></small>
                            </p>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="summary-box">
                            <h6 class="fw-semibold mb-3">Invoice Summary</h6>
                            <div class="summary-row">
                                <span class="summary-label">Subtotal:</span>
                                <span class="summary-value">₹<?php echo number_format((float)$invoice['subtotal'], 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">CGST:</span>
                                <span class="summary-value">₹<?php echo number_format((float)$invoice['cgst_amount'], 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">SGST:</span>
                                <span class="summary-value">₹<?php echo number_format((float)$invoice['sgst_amount'], 2); ?></span>
                            </div>
                            <?php if ((float)$invoice['overall_discount'] > 0): ?>
                            <div class="summary-row">
                                <span class="summary-label">Overall Discount:</span>
                                <span class="summary-value text-success">-₹<?php echo number_format((float)$invoice['overall_discount'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="summary-row">
                                <span class="summary-label">Investment (Cost):</span>
                                <span class="summary-value">₹<?php echo number_format($cost_value, 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Profit:</span>
                                <span class="summary-value" style="color: <?php echo $profit_value >= 0 ? '#16a34a' : '#dc2626'; ?>;">
                                    ₹<?php echo number_format($profit_value, 2); ?>
                                </span>
                            </div>
                            <div class="summary-row mt-2 pt-2 border-top">
                                <span class="summary-label fw-bold">Grand Total:</span>
                                <span class="summary-total">₹<?php echo number_format((float)$invoice['total'], 2); ?></span>
                            </div>
                            <?php if ((float)$invoice['pending_amount'] > 0): ?>
                            <div class="summary-row mt-2">
                                <span class="summary-label text-danger">Pending Amount:</span>
                                <span class="summary-value text-danger fw-bold">₹<?php echo number_format((float)$invoice['pending_amount'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="signature-area">
                    <div>
                        <div class="signature-line"></div>
                        <small class="text-muted">Authorized Signatory</small>
                    </div>
                    <div>
                        <div class="signature-line"></div>
                        <small class="text-muted">Customer Signature</small>
                    </div>
                </div>

                <div class="mt-4 pt-3 text-center text-muted" style="border-top: 1px solid #eef2f6;">
                    <small>Thank you for your business!</small>
                </div>
            </div>

            <?php if ((float)$invoice['pending_amount'] > 0): ?>
            <div class="payment-section no-print mt-4">
                <h6 class="fw-semibold mb-3"><i class="bi bi-cash-stack me-2"></i>Collect Payment</h6>
                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="collect_payment">
                    <div class="col-md-4">
                        <label class="form-label">Payment Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" name="payment_amount" class="form-control" step="0.01" min="0.01" max="<?php echo (float)$invoice['pending_amount']; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="upi">UPI</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-check-circle me-2"></i>Process Payment
                        </button>
                    </div>
                </form>
                <small class="text-muted d-block mt-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Pending amount: ₹<?php echo number_format((float)$invoice['pending_amount'], 2); ?>
                </small>
            </div>
            <?php endif; ?>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="collect_payment">
                <div class="modal-header">
                    <h5 class="modal-title">Collect Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Invoice Number</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($invoice['inv_num']); ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pending Amount</label>
                        <input type="text" class="form-control" value="₹<?php echo number_format((float)$invoice['pending_amount'], 2); ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" name="payment_amount" class="form-control" step="0.01" min="0.01" max="<?php echo (float)$invoice['pending_amount']; ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="upi">UPI</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
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

<?php include 'includes/scripts.php'; ?>
<script>
function showPaymentModal() {
    $('#paymentModal').modal('show');
}
</script>
</body>
</html>