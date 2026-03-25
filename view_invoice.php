<?php
session_start();
$pageTitle = 'View Invoice';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view invoices
checkRoleAccess(['admin', 'sale']);

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoice_id) {
    header('Location: invoices.php');
    exit;
}

// Get invoice details
$sql = "SELECT i.*, c.customer_name, c.phone, c.email, c.address, c.gst_number,
        (SELECT COUNT(*) FROM invoice_item WHERE invoice_id = i.id) as item_count
        FROM invoice i 
        LEFT JOIN customers c ON i.customer_id = c.id 
        WHERE i.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: invoices.php');
    exit;
}

$invoice = $result->fetch_assoc();

// Get invoice items
$items_sql = "SELECT ii.*, p.product_name, c.category_name, g.cgst as gst_cgst, g.sgst as gst_sgst
              FROM invoice_item ii
              LEFT JOIN product p ON ii.product_id = p.id
              LEFT JOIN category c ON ii.cat_id = c.id
              LEFT JOIN gst g ON ii.hsn = g.hsn
              WHERE ii.invoice_id = ?
              ORDER BY ii.id ASC";

$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("i", $invoice_id);
$items_stmt->execute();
$items = $items_stmt->get_result();

// Get company settings for invoice
$settings = $conn->query("SELECT * FROM invoice_setting LIMIT 1")->fetch_assoc();

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function getPaymentMethodIcon($method) {
    switch($method) {
        case 'cash': return 'bi-cash';
        case 'card': return 'bi-credit-card';
        case 'upi': return 'bi-phone';
        case 'bank': return 'bi-bank';
        default: return 'bi-cash';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .invoice-container {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px dashed #eef2f6;
        }
        
        .company-info h2 {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 5px;
        }
        
        .company-details {
            font-size: 13px;
            color: #64748b;
            line-height: 1.6;
        }
        
        .invoice-title {
            text-align: right;
        }
        
        .invoice-title h1 {
            font-size: 32px;
            font-weight: 700;
            color: #2463eb;
            margin-bottom: 5px;
        }
        
        .invoice-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .badge-paid {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .badge-pending {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
        }
        
        .info-box h4 {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-box .value {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 5px;
        }
        
        .info-box .sub-value {
            font-size: 13px;
            color: #64748b;
        }
        
        .items-table {
            width: 100%;
            margin-bottom: 30px;
        }
        
        .items-table th {
            background: #f8fafc;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #eef2f6;
            font-size: 14px;
        }
        
        .items-table tfoot td {
            background: #f8fafc;
            font-weight: 600;
        }
        
        .total-section {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .total-box {
            width: 350px;
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .total-row.grand-total {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            border-top: 2px solid #e2e8f0;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .payment-info {
            margin-top: 30px;
            padding: 20px;
            background: #f0f9ff;
            border-radius: 12px;
            border: 1px solid #bae6fd;
        }
        
        .payment-info h5 {
            color: #0369a1;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .invoice-container {
                box-shadow: none;
                padding: 0;
            }
        }
        
        .gst-breakdown {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <!-- Action Buttons -->
            <div class="action-buttons no-print mb-4">
                <a href="invoices.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Invoices
                </a>
               
                <a href="print_invoice.php?id=<?php echo $invoice_id; ?>" target="_blank" class="btn btn-info">
                    <i class="bi bi-file-pdf"></i> Print Invoice
                </a>
            </div>

            <!-- Invoice Container -->
            <div class="invoice-container" id="invoice-content">
                <!-- Header -->
                <div class="invoice-header">
                    <div class="company-info">
                        <h2><?php echo htmlspecialchars($settings['company_name'] ?? 'Your Company Name'); ?></h2>
                        <div class="company-details">
                            <?php if (!empty($settings['company_address'])): ?>
                                <div><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($settings['phone'])): ?>
                                <div>Phone: <?php echo htmlspecialchars($settings['phone']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($settings['email'])): ?>
                                <div>Email: <?php echo htmlspecialchars($settings['email']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($settings['gst_number'])): ?>
                                <div>GST: <?php echo htmlspecialchars($settings['gst_number']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="invoice-title">
                        <h1>INVOICE</h1>
                        <div class="invoice-number" style="font-size: 18px; font-weight: 600; color: #475569;">
                            #<?php echo htmlspecialchars($invoice['inv_num']); ?>
                        </div>
                        <div class="invoice-badge <?php echo $invoice['pending_amount'] > 0 ? 'badge-pending' : 'badge-paid'; ?>">
                            <?php echo $invoice['pending_amount'] > 0 ? 'Pending Payment' : 'Paid'; ?>
                        </div>
                    </div>
                </div>

                <!-- Customer & Invoice Info -->
                <div class="info-grid">
                    <div class="info-box">
                        <h4>Bill To:</h4>
                        <div class="value"><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></div>
                        <?php if (!empty($invoice['phone'])): ?>
                            <div class="sub-value">Phone: <?php echo htmlspecialchars($invoice['phone']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['email'])): ?>
                            <div class="sub-value">Email: <?php echo htmlspecialchars($invoice['email']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['address'])): ?>
                            <div class="sub-value"><?php echo nl2br(htmlspecialchars($invoice['address'])); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['gst_number'])): ?>
                            <div class="sub-value">GST: <?php echo htmlspecialchars($invoice['gst_number']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="info-box">
                        <h4>Invoice Details:</h4>
                        <div class="value">Date: <?php echo date('d M Y', strtotime($invoice['created_at'])); ?></div>
                        <div class="sub-value">Time: <?php echo date('h:i A', strtotime($invoice['created_at'])); ?></div>
                        <div class="sub-value">Payment Method: 
                            <span class="payment-method-badge">
                                <i class="bi <?php echo getPaymentMethodIcon($invoice['payment_method']); ?>"></i>
                                <?php echo ucfirst($invoice['payment_method']); ?>
                            </span>
                        </div>
                        <?php if ($invoice['pending_amount'] > 0): ?>
                            <div class="sub-value text-danger">Pending: <?php echo formatCurrency($invoice['pending_amount']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Items Table -->
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Item</th>
                            <th>Category</th>
                            <th>HSN</th>
                            <th>Qty</th>
                            <th>Price</th>
                            <th>Discount</th>
                            <th>Taxable</th>
                            <th>CGST</th>
                            <th>SGST</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sn = 1;
                        $items->data_seek(0);
                        while ($item = $items->fetch_assoc()): 
                        ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><?php echo htmlspecialchars($item['product_name'] ?: $item['cat_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['cat_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($item['hsn'] ?: '-'); ?></td>
                                <td><?php echo number_format($item['quantity'], 3); ?></td>
                                <td><?php echo formatCurrency($item['selling_price']); ?></td>
                                <td>
                                    <?php if ($item['discount'] > 0): ?>
                                        <?php echo $item['discount_type'] === 'percentage' ? $item['discount'] . '%' : formatCurrency($item['discount']); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatCurrency($item['taxable']); ?></td>
                                <td>
                                    <?php echo formatCurrency($item['cgst_amount']); ?>
                                    <?php if ($item['cgst'] > 0): ?>
                                        <div class="gst-breakdown">(<?php echo $item['cgst']; ?>%)</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo formatCurrency($item['sgst_amount']); ?>
                                    <?php if ($item['sgst'] > 0): ?>
                                        <div class="gst-breakdown">(<?php echo $item['sgst']; ?>%)</div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold"><?php echo formatCurrency($item['total']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <!-- Total Section -->
                <div class="total-section">
                    <div class="total-box">
                        <div class="total-row">
                            <span>Subtotal:</span>
                            <span><?php echo formatCurrency($invoice['subtotal']); ?></span>
                        </div>
                        <?php if ($invoice['overall_discount'] > 0): ?>
                            <div class="total-row">
                                <span>Discount:</span>
                                <span>- <?php echo formatCurrency($invoice['overall_discount']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="total-row">
                            <span>CGST:</span>
                            <span><?php echo formatCurrency($invoice['cgst_amount']); ?></span>
                        </div>
                        <div class="total-row">
                            <span>SGST:</span>
                            <span><?php echo formatCurrency($invoice['sgst_amount']); ?></span>
                        </div>
                        <div class="total-row grand-total">
                            <span>Grand Total:</span>
                            <span><?php echo formatCurrency($invoice['total']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Payment Information -->
                <div class="payment-info">
                    <h5><i class="bi bi-cash-stack"></i> Payment Information</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted">Total Amount</small>
                            <div class="fw-bold"><?php echo formatCurrency($invoice['total']); ?></div>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">Cash Received</small>
                            <div class="fw-bold"><?php echo formatCurrency($invoice['cash_received']); ?></div>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">Balance</small>
                            <div class="fw-bold <?php echo $invoice['pending_amount'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo $invoice['pending_amount'] > 0 ? formatCurrency($invoice['pending_amount']) : 'Settled'; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bank Details -->
                <?php if (!empty($settings['bank_name']) || !empty($settings['upi_id'])): ?>
                    <div class="mt-4 pt-3 border-top">
                        <small class="text-muted d-block mb-2">Bank Details:</small>
                        <div class="row">
                            <?php if (!empty($settings['bank_name'])): ?>
                                <div class="col-md-3">
                                    <small>Bank: <?php echo htmlspecialchars($settings['bank_name']); ?></small>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($settings['account_number'])): ?>
                                <div class="col-md-3">
                                    <small>A/c: <?php echo htmlspecialchars($settings['account_number']); ?></small>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($settings['ifsc'])): ?>
                                <div class="col-md-3">
                                    <small>IFSC: <?php echo htmlspecialchars($settings['ifsc']); ?></small>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($settings['upi_id'])): ?>
                                <div class="col-md-3">
                                    <small>UPI: <?php echo htmlspecialchars($settings['upi_id']); ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Terms & Conditions -->
                <div class="mt-4 text-center text-muted" style="font-size: 12px;">
                    <p>This is a computer generated invoice and does not require a signature.</p>
                    <p>Thank you for your business!</p>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
</body>
</html>