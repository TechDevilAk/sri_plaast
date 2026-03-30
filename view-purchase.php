<?php
// view-purchase.php
session_start();
$currentPage = 'view-purchase';
$pageTitle = 'View Purchase';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view purchases
checkRoleAccess(['admin', 'sale']);

// Check if purchase ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage-purchases.php');
    exit;
}

$purchase_id = intval($_GET['id']);

// --------------------------
// Helper Functions
// --------------------------
function money2($n) {
    return number_format((float)$n, 2, '.', '');
}

function getPaymentStatus($total, $paid) {
    if ($paid >= $total) {
        return ['badge' => 'success', 'text' => 'Paid'];
    } elseif ($paid > 0) {
        return ['badge' => 'warning', 'text' => 'Partial'];
    } else {
        return ['badge' => 'danger', 'text' => 'Unpaid'];
    }
}

// --------------------------
// Get purchase data
// --------------------------

// Get purchase header with supplier details
$stmt = $conn->prepare("
    SELECT p.*, 
           s.supplier_name, s.phone as supplier_phone, s.email as supplier_email, 
           s.address as supplier_address, s.gst_number as supplier_gst,
           s.bank_name, s.account_number, s.ifsc_code, s.branch, s.upi_id,
           s.opening_balance as supplier_balance
    FROM purchase p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.id = ?
");
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchase = $stmt->get_result()->fetch_assoc();

if (!$purchase) {
    header('Location: manage-purchases.php');
    exit;
}

// Get purchase items with category and product details
$item_stmt = $conn->prepare("
    SELECT pi.*, 
           c.category_name, c.gram_value, c.purchase_price as current_price,
           c.total_quantity as current_stock,
           prod.product_name, prod.product_type, prod.primary_unit as product_unit,
           prod.stock_quantity as product_stock
    FROM purchase_item pi
    LEFT JOIN category c ON pi.cat_id = c.id
    LEFT JOIN product prod ON pi.product_id = prod.id
    WHERE pi.purchase_id = ?
    ORDER BY pi.id ASC
");
$item_stmt->bind_param("i", $purchase_id);
$item_stmt->execute();
$items = $item_stmt->get_result();

// Get payment history
$payment_stmt = $conn->prepare("
    SELECT * FROM purchase_payment_history 
    WHERE purchase_id = ? 
    ORDER BY payment_date ASC
");
$payment_stmt->bind_param("i", $purchase_id);
$payment_stmt->execute();
$payments = $payment_stmt->get_result();

// Calculate totals
$total_paid = 0;
$payment_list = [];
while ($payment = $payments->fetch_assoc()) {
    $total_paid += floatval($payment['paid_amount']);
    $payment_list[] = $payment;
}

// Reset payment pointer for later use
$payments->data_seek(0);

$status = getPaymentStatus($purchase['total'], $total_paid);
$gst_total = floatval($purchase['cgst_amount'] ?? 0) + floatval($purchase['sgst_amount'] ?? 0);
$taxable = floatval($purchase['total']) - $gst_total;
$balance = floatval($purchase['total']) - $total_paid;

// Get activity log for this purchase
$log_stmt = $conn->prepare("
    SELECT al.*, u.name as user_name 
    FROM activity_log al 
    LEFT JOIN users u ON al.user_id = u.id 
    WHERE al.description LIKE ? 
    ORDER BY al.created_at DESC 
    LIMIT 5
");
$search_term = "%{$purchase['purchase_no']}%";
$log_stmt->bind_param("s", $search_term);
$log_stmt->execute();
$activity_logs = $log_stmt->get_result();

// Determine purchase type display
$purchase_type = $purchase['purchase_type'] ?? 'category';
$type_badge_class = ($purchase_type == 'category') ? 'category' : 'product';
$type_icon = ($purchase_type == 'category') ? 'bi-layers' : 'bi-box';
$type_label = ($purchase_type == 'category') ? 'Category Purchase' : 'Product Purchase';
$type_desc = ($purchase_type == 'category') 
    ? 'Raw materials purchased in KG and converted to pieces' 
    : 'Finished products purchased directly in their primary unit';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1e293b;
            --light: #f8fafc;
        }
        
        body {
            background: #f0f4f8;
        }
        
        /* Invoice Styles */
        .invoice-container {
            margin: 0 auto;
        }
        
        .invoice-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .invoice-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 30px;
        }
        
        .invoice-title {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
        }
        
        .invoice-subtitle {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .invoice-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-paid {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
        
        .badge-partial {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
        }
        
        .badge-unpaid {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
        
        .purchase-type-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .purchase-type-badge.category {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
        
        .purchase-type-badge.product {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
        }
        
        .info-section {
            padding: 24px 30px;
            border-bottom: 1px solid #edf2f9;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            width: 36px;
            height: 36px;
            background: #e8f2ff;
            color: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
        }
        
        .info-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .info-value.large {
            font-size: 20px;
        }
        
        .gst-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #f2e8ff;
            color: #8b5cf6;
        }
        
        .gst-badge.exclusive {
            background: #e8f2ff;
            color: #2563eb;
        }
        
        .gst-badge.inclusive {
            background: #fef3c7;
            color: #d97706;
        }
        
        .category-badge {
            background: #f0fdf4;
            color: #16a34a;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .product-badge {
            background: #dbeafe;
            color: #1e40af;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        /* Table Styles */
        .table-container {
            padding: 0 30px 30px 30px;
            overflow-x: auto;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .items-table th {
            background: #f8fafc;
            padding: 16px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .items-table td {
            padding: 16px;
            font-size: 14px;
            border-bottom: 1px solid #edf2f9;
            color: #334155;
        }
        
        .items-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .items-table tfoot {
            background: #f8fafc;
            font-weight: 600;
        }
        
        .items-table tfoot td {
            padding: 16px;
            border-top: 2px solid #e2e8f0;
        }
        
        /* Payment History */
        .payment-timeline {
            padding: 0 30px 30px 30px;
        }
        
        .payment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            margin-bottom: 12px;
            border-left: 4px solid var(--primary);
        }
        
        .payment-item.paid {
            border-left-color: var(--success);
        }
        
        .payment-info {
            flex: 1;
        }
        
        .payment-amount {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .payment-method {
            display: inline-block;
            padding: 4px 12px;
            background: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 12px;
        }
        
        .payment-date {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }
        
        .payment-notes {
            font-size: 12px;
            color: #64748b;
            font-style: italic;
            margin-top: 4px;
        }
        
        /* Summary Card */
        .summary-card {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            border-radius: 20px;
            padding: 24px;
            margin-top: 20px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .summary-row:last-child {
            border-bottom: none;
        }
        
        .summary-label {
            font-size: 14px;
            opacity: 0.8;
        }
        
        .summary-value {
            font-size: 20px;
            font-weight: 600;
        }
        
        .summary-value.total {
            font-size: 28px;
            font-weight: 700;
        }
        
        .summary-badge {
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        
        .btn-action.print {
            background: var(--primary);
            color: white;
        }
        
        .btn-action.edit {
            background: var(--warning);
            color: white;
        }
        
        .btn-action.back {
            background: white;
            color: var(--dark);
            border: 1px solid #e2e8f0;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            text-decoration: none;
            color: white;
        }
        
        .btn-action.back:hover {
            background: #f8fafc;
            color: var(--dark);
        }
        
        /* Status Badge */
        .status-badge {
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-badge.success {
            background: #e3f9f2;
            color: #0b5e42;
        }
        
        .status-badge.warning {
            background: #fff4dd;
            color: #92400e;
        }
        
        .status-badge.danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Activity Log */
        .activity-log {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }
        
        .activity-time {
            font-size: 11px;
            color: #64748b;
        }
        
        /* Detail Row Styles */
        .detail-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 10px;
        }
        
        .detail-badge {
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .invoice-header {
                padding: 20px;
            }
            
            .info-section {
                padding: 20px;
            }
            
            .table-container {
                padding: 0 20px 20px 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
                justify-content: center;
            }
            
            .payment-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .payment-amount {
                font-size: 16px;
            }
        }
        
        /* Print Styles */
        @media print {
            .app-wrapper .sidebar,
            .app-wrapper .topbar,
            .action-buttons,
            .btn-action,
            .btn-close,
            .footer {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            
            .invoice-card {
                box-shadow: none;
                border: 1px solid #e2e8f0;
            }
            
            .invoice-header {
                background: #f8fafc;
                color: var(--dark);
            }
            
            .summary-card {
                background: #f8fafc;
                color: var(--dark);
                border: 1px solid #e2e8f0;
            }
            
            .summary-row {
                border-bottom: 1px solid #e2e8f0;
            }
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">
            <div class="invoice-container">

                <!-- Header with Actions -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                    <div>
                        <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Purchase Details</h4>
                        <p style="font-size: 14px; color: var(--text-muted); margin: 0;">View complete purchase information</p>
                    </div>
                    <div class="action-buttons">
                        <button onclick="window.print()" class="btn-action print">
                            <i class="bi bi-printer"></i> Print
                        </button>
                        <?php if ($_SESSION['user_role'] === 'admin'): ?>
                        <a href="edit-purchase.php?id=<?php echo $purchase_id; ?>" class="btn-action edit">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <?php endif; ?>
                        <a href="manage-purchases.php" class="btn-action back">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>

                <!-- Main Invoice Card -->
                <div class="invoice-card">
                    <!-- Invoice Header -->
                    <div class="invoice-header">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h1 class="invoice-title">Purchase #<?php echo htmlspecialchars($purchase['purchase_no']); ?></h1>
                                <p class="invoice-subtitle">
                                    <i class="bi bi-calendar me-2"></i><?php echo date('F d, Y', strtotime($purchase['purchase_date'] ?? $purchase['created_at'])); ?>
                                    <?php if (!empty($purchase['invoice_num'])): ?>
                                        <span class="ms-4"><i class="bi bi-receipt me-2"></i>Supplier Invoice: <?php echo htmlspecialchars($purchase['invoice_num']); ?></span>
                                    <?php endif; ?>
                                </p>
                                <!-- Purchase Type Badge -->
                                <div class="purchase-type-badge <?php echo $type_badge_class; ?>">
                                    <i class="bi <?php echo $type_icon; ?> me-2"></i>
                                    <?php echo $type_label; ?>
                                    <small class="ms-2">(<?php echo $type_desc; ?>)</small>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <div class="invoice-badge <?php echo $status['badge'] === 'success' ? 'badge-paid' : ($status['badge'] === 'warning' ? 'badge-partial' : 'badge-unpaid'); ?>">
                                    <i class="bi bi-circle-fill me-2" style="font-size: 8px;"></i>
                                    <?php echo $status['text']; ?>
                                </div>
                                <div class="mt-3">
                                    <span class="gst-badge <?php echo $purchase['gst_type'] ?? 'exclusive'; ?>">
                                        GST <?php echo ucfirst($purchase['gst_type'] ?? 'Exclusive'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Supplier Information -->
                    <div class="info-section">
                        <h5 class="section-title">
                            <i class="bi bi-truck"></i>
                            Supplier Details
                        </h5>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Supplier Name</div>
                                <div class="info-value large"><?php echo htmlspecialchars($purchase['supplier_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Phone</div>
                                <div class="info-value"><?php echo htmlspecialchars($purchase['supplier_phone'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Email</div>
                                <div class="info-value"><?php echo htmlspecialchars($purchase['supplier_email'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">GST Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($purchase['supplier_gst'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Address</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($purchase['supplier_address'] ?? 'N/A')); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Opening Balance</div>
                                <div class="info-value">₹<?php echo money2($purchase['supplier_balance'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Bank Details (if available) -->
                    <?php if (!empty($purchase['bank_name']) || !empty($purchase['upi_id'])): ?>
                    <div class="info-section">
                        <h5 class="section-title">
                            <i class="bi bi-bank"></i>
                            Bank Details
                        </h5>
                        <div class="info-grid">
                            <?php if (!empty($purchase['bank_name'])): ?>
                            <div class="info-item">
                                <div class="info-label">Bank Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($purchase['bank_name']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($purchase['account_number'])): ?>
                            <div class="info-item">
                                <div class="info-label">Account Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($purchase['account_number']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($purchase['ifsc_code'])): ?>
                            <div class="info-item">
                                <div class="info-label">IFSC Code</div>
                                <div class="info-value"><?php echo htmlspecialchars($purchase['ifsc_code']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($purchase['branch'])): ?>
                            <div class="info-item">
                                <div class="info-label">Branch</div>
                                <div class="info-value"><?php echo htmlspecialchars($purchase['branch']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($purchase['upi_id'])): ?>
                            <div class="info-item">
                                <div class="info-label">UPI ID</div>
                                <div class="info-value"><?php echo htmlspecialchars($purchase['upi_id']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Items Table -->
                    <div class="table-container">
                        <h5 class="section-title">
                            <i class="bi bi-list-check"></i>
                            Purchase Items
                        </h5>
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Type</th>
                                    <th>Item Name</th>
                                    <th>Details</th>
                                    <th>Quantity</th>
                                    <th>Unit</th>
                                    <th>Price/Unit</th>
                                    <th>Taxable</th>
                                    <th>GST</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $item_count = 0;
                                $total_taxable = 0;
                                $total_gst = 0;
                                $total_amount = 0;
                                
                                if ($items && $items->num_rows > 0): 
                                    while ($item = $items->fetch_assoc()): 
                                        $item_count++;
                                        $item_type = $item['product_id'] ? 'product' : 'category';
                                        $type_badge = ($item_type == 'category') 
                                            ? '<span class="category-badge"><i class="bi bi-layers"></i> Category</span>'
                                            : '<span class="product-badge"><i class="bi bi-box"></i> Product</span>';
                                        $item_name = ($item_type == 'category') 
                                            ? $item['category_name'] 
                                            : $item['product_name'];
                                        
                                        $taxable = floatval($item['taxable']);
                                        $cgst_amt = floatval($item['cgst_amount']);
                                        $sgst_amt = floatval($item['sgst_amount']);
                                        $item_total = floatval($item['total']);
                                        
                                        $total_taxable += $taxable;
                                        $total_gst += ($cgst_amt + $sgst_amt);
                                        $total_amount += $item_total;
                                        
                                        if ($item_type == 'category') {
                                            $details = "{$item['cat_grm_value']} g/pc • " . floatval($item['sec_qty']) . " kg = " . round(floatval($item['qty'])) . " pcs";
                                            $quantity = round(floatval($item['qty']));
                                            $unit = 'pcs';
                                            $price_unit = money2($item['purchase_price']);
                                        } else {
                                            $unit = $item['product_unit'] ?? 'pcs';
                                            $details = "Direct Product Purchase";
                                            $quantity = floatval($item['qty']);
                                            $price_unit = money2($item['purchase_price']);
                                        }
                                ?>
                                     <tr>
                                        <td><?php echo $item_count; ?></td>
                                        <td><?php echo $type_badge; ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($item_name); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars($details); ?></td>
                                        <td class="text-end"><?php echo number_format($quantity, 2); ?></td>
                                        <td class="text-end"><?php echo htmlspecialchars($unit); ?></td>
                                        <td class="text-end">₹<?php echo $price_unit; ?></td>
                                        <td class="text-end">₹<?php echo money2($taxable); ?></td>
                                        <td class="text-end">
                                            <?php echo floatval($item['cgst']) + floatval($item['sgst']); ?>%<br>
                                            <small class="text-muted">₹<?php echo money2($cgst_amt + $sgst_amt); ?></small>
                                        </td>
                                        <td class="text-end fw-bold">₹<?php echo money2($item_total); ?></td>
                                     </tr>
                                <?php 
                                    endwhile; 
                                else: 
                                ?>
                                     <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">
                                            No items found in this purchase
                                        </td>
                                     </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                 <tr>
                                    <td colspan="7" class="text-end fw-bold">Totals:</td>
                                    <td class="text-end fw-bold">₹<?php echo money2($total_taxable); ?></td>
                                    <td class="text-end fw-bold">₹<?php echo money2($total_gst); ?></td>
                                    <td class="text-end fw-bold">₹<?php echo money2($total_amount); ?></td>
                                 </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Payment History -->
                    <div class="payment-timeline">
                        <h5 class="section-title">
                            <i class="bi bi-credit-card"></i>
                            Payment History
                        </h5>
                        
                        <?php if (count($payment_list) > 0): ?>
                            <?php foreach ($payment_list as $payment): ?>
                                <div class="payment-item paid">
                                    <div class="payment-info">
                                        <div class="d-flex align-items-center flex-wrap gap-2">
                                            <span class="payment-amount">₹<?php echo money2($payment['paid_amount']); ?></span>
                                            <span class="payment-method"><?php echo ucfirst($payment['payment_method']); ?></span>
                                        </div>
                                        <div class="payment-date">
                                            <i class="bi bi-clock me-1"></i>
                                            <?php echo date('d M Y, h:i A', strtotime($payment['payment_date'])); ?>
                                        </div>
                                        <?php if (!empty($payment['notes'])): ?>
                                            <div class="payment-notes">
                                                <i class="bi bi-chat me-1"></i>
                                                <?php echo htmlspecialchars($payment['notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-credit-card" style="font-size: 32px;"></i>
                                <p class="mt-2">No payment records found</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Summary Section -->
                    <div class="info-section">
                        <div class="summary-card">
                            <div class="summary-row">
                                <span class="summary-label">Subtotal (Taxable)</span>
                                <span class="summary-value">₹<?php echo money2($total_taxable); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Total GST (CGST + SGST)</span>
                                <span class="summary-value">₹<?php echo money2($total_gst); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Total Purchase Amount</span>
                                <span class="summary-value">₹<?php echo money2($total_amount); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Total Paid</span>
                                <span class="summary-value">₹<?php echo money2($total_paid); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Balance Due</span>
                                <span class="summary-value <?php echo $balance > 0 ? 'text-warning' : 'text-success'; ?>">
                                    ₹<?php echo money2($balance); ?>
                                </span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Payment Status</span>
                                <span class="summary-badge <?php echo $status['badge'] === 'success' ? 'badge-paid' : ($status['badge'] === 'warning' ? 'badge-partial' : 'badge-unpaid'); ?>">
                                    <?php echo $status['text']; ?>
                                </span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Purchase Type</span>
                                <span class="summary-value"><?php echo $type_label; ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Created On</span>
                                <span class="summary-value"><?php echo date('d M Y, h:i A', strtotime($purchase['created_at'])); ?></span>
                            </div>
                            <?php if (!empty($purchase['updated_at']) && $purchase['updated_at'] != $purchase['created_at']): ?>
                            <div class="summary-row">
                                <span class="summary-label">Last Updated</span>
                                <span class="summary-value"><?php echo date('d M Y, h:i A', strtotime($purchase['updated_at'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Activity Log -->
                    <?php if ($activity_logs && $activity_logs->num_rows > 0): ?>
                    <div class="info-section">
                        <h5 class="section-title">
                            <i class="bi bi-clock-history"></i>
                            Recent Activity
                        </h5>
                        <div class="activity-log">
                            <?php while ($log = $activity_logs->fetch_assoc()): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <?php if ($log['action'] === 'create'): ?>
                                            <i class="bi bi-plus-circle"></i>
                                        <?php elseif ($log['action'] === 'update'): ?>
                                            <i class="bi bi-pencil"></i>
                                        <?php elseif ($log['action'] === 'delete'): ?>
                                            <i class="bi bi-trash"></i>
                                        <?php else: ?>
                                            <i class="bi bi-info-circle"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <?php echo ucfirst(htmlspecialchars($log['action'] ?? 'Action')); ?> - 
                                            <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?>
                                        </div>
                                        <div class="activity-time">
                                            <i class="bi bi-clock me-1"></i>
                                            <?php echo date('d M Y, h:i A', strtotime($log['created_at'])); ?>
                                        </div>
                                        <?php if (!empty($log['description'])): ?>
                                            <div class="text-muted small mt-1">
                                                <?php echo htmlspecialchars($log['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Footer Note -->
                    <div class="text-center py-3 text-muted" style="font-size: 12px; border-top: 1px solid #edf2f9;">
                        <i class="bi bi-printer me-1"></i> This is a computer generated document. No signature is required.
                    </div>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
    // Print functionality
    document.querySelector('.btn-action.print')?.addEventListener('click', function() {
        window.print();
    });
</script>

</body>
</html>