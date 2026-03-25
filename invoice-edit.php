<?php
session_start();
$currentPage = 'sales';
$pageTitle = 'Edit Invoice';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can edit invoices
checkRoleAccess(['admin']);

header_remove("X-Powered-By");

$error = '';
$success = '';

// Get invoice ID from URL
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoice_id) {
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
$items_query = $conn->prepare("
    SELECT * FROM invoice_item 
    WHERE invoice_id = ?
");
$items_query->bind_param("i", $invoice_id);
$items_query->execute();
$items = $items_query->get_result();

// Get all categories for dropdown
$categories = $conn->query("SELECT id, category_name, purchase_price, gram_value, total_quantity FROM category ORDER BY category_name ASC");

// Get all GST rates
$gst_rates = $conn->query("SELECT * FROM gst WHERE status = 1 ORDER BY hsn ASC");

// Get customers for dropdown
$customers = $conn->query("SELECT id, customer_name, phone, gst_number FROM customers ORDER BY customer_name ASC");

// Helper functions
function money2($n) {
    return number_format((float)$n, 2, '.', '');
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// --------------------------
// AJAX endpoints
// --------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] !== '') {
    $ajax = $_GET['ajax'];

    // Search categories for dropdown
    if ($ajax === 'categories') {
        $term = trim($_GET['term'] ?? '');
        $termLike = "%{$term}%";

        $stmt = $conn->prepare("
            SELECT id, category_name, purchase_price, gram_value, total_quantity, min_stock_level
            FROM category
            WHERE category_name LIKE ? 
            ORDER BY category_name ASC
            LIMIT 50
        ");
        $stmt->bind_param("s", $termLike);
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $pcs_per_kg = $row['gram_value'] > 0 ? round(1000 / $row['gram_value'], 2) : 0;
            
            $label = $row['category_name'];
            $label .= " (" . number_format($row['gram_value'], 3) . " g/pc";
            if ($pcs_per_kg > 0) {
                $label .= ", " . number_format($pcs_per_kg, 2) . " pcs/kg";
            }
            $label .= ", Stock: " . number_format($row['total_quantity'], 2) . " pcs)";

            $items[] = [
                "id"   => $row['id'],
                "text" => $label,
                "meta" => [
                    "category_name" => $row['category_name'],
                    "purchase_price" => (float)$row['purchase_price'],
                    "gram_value" => (float)$row['gram_value'],
                    "total_quantity" => (float)$row['total_quantity'],
                    "min_stock_level" => (float)$row['min_stock_level'],
                    "pcs_per_kg" => $pcs_per_kg
                ]
            ];
        }

        json_response(["results" => $items]);
    }

    // Search customers for dropdown
    if ($ajax === 'customers') {
        $term = trim($_GET['term'] ?? '');
        $termLike = "%{$term}%";

        $stmt = $conn->prepare("
            SELECT id, customer_name, phone, gst_number
            FROM customers
            WHERE customer_name LIKE ? OR phone LIKE ? OR gst_number LIKE ?
            ORDER BY customer_name ASC
            LIMIT 50
        ");
        $stmt->bind_param("sss", $termLike, $termLike, $termLike);
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $label = $row['customer_name'];
            if (!empty($row['phone'])) $label .= " • " . $row['phone'];
            if (!empty($row['gst_number'])) $label .= " • " . $row['gst_number'];

            $items[] = [
                "id"   => $row['id'],
                "text" => $label,
                "meta" => [
                    "customer_name" => $row['customer_name'],
                    "phone" => $row['phone'],
                    "gst_number" => $row['gst_number']
                ]
            ];
        }

        json_response(["results" => $items]);
    }

    json_response(["ok" => false, "message" => "Unknown ajax endpoint"], 404);
}

// --------------------------
// Handle form submission
// --------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_invoice') {
    
    $conn->begin_transaction();
    
    try {
        // Get form data
        $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $cash_received = floatval($_POST['cash_received'] ?? 0);
        $change_give = floatval($_POST['change_give'] ?? 0);
        $overall_discount = floatval($_POST['overall_discount'] ?? 0);
        $discount_type = $_POST['discount_type'] ?? 'fixed';
        $notes = trim($_POST['notes'] ?? '');
        
        // Get items from JSON
        $items_json = $_POST['items_json'] ?? '[]';
        $new_items = json_decode($items_json, true);
        
        if (empty($new_items)) {
            throw new Exception("At least one item is required.");
        }
        
        // Calculate totals
        $subtotal = 0;
        $cgst_total = 0;
        $sgst_total = 0;
        
        foreach ($new_items as $item) {
            $subtotal += floatval($item['taxable'] ?? 0);
            $cgst_total += floatval($item['cgst_amt'] ?? 0);
            $sgst_total += floatval($item['sgst_amt'] ?? 0);
        }
        
        // Apply overall discount
        if ($overall_discount > 0) {
            if ($discount_type === 'percentage') {
                $discount_amount = ($subtotal * $overall_discount) / 100;
            } else {
                $discount_amount = $overall_discount;
            }
            $subtotal -= $discount_amount;
        } else {
            $discount_amount = 0;
        }
        
        $total = $subtotal + $cgst_total + $sgst_total;
        $pending_amount = $total - $cash_received;
        if ($pending_amount < 0) $pending_amount = 0;
        
        // Update invoice
        $update_stmt = $conn->prepare("
            UPDATE invoice SET 
                customer_id = ?,
                invoice_date = ?,
                payment_method = ?,
                subtotal = ?,
                cgst_amount = ?,
                sgst_amount = ?,
                overall_discount = ?,
                discount_type = ?,
                discount_amount = ?,
                total = ?,
                cash_received = ?,
                change_give = ?,
                pending_amount = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $update_stmt->bind_param("issddddddddddssi", 
            $customer_id,
            $invoice_date,
            $payment_method,
            $subtotal,
            $cgst_total,
            $sgst_total,
            $overall_discount,
            $discount_type,
            $discount_amount,
            $total,
            $cash_received,
            $change_give,
            $pending_amount,
            $notes,
            $invoice_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update invoice: " . $conn->error);
        }
        
        // Get old items to restore stock
        $old_items = $conn->prepare("SELECT * FROM invoice_item WHERE invoice_id = ?");
        $old_items->bind_param("i", $invoice_id);
        $old_items->execute();
        $old_items_result = $old_items->get_result();
        
        // Restore stock for old items
        while ($old_item = $old_items_result->fetch_assoc()) {
            if ($old_item['cat_id']) {
                $restore_stmt = $conn->prepare("UPDATE category SET total_quantity = total_quantity + ? WHERE id = ?");
                $restore_stmt->bind_param("di", $old_item['quantity'], $old_item['cat_id']);
                $restore_stmt->execute();
            }
        }
        
        // Delete old items
        $delete_items = $conn->prepare("DELETE FROM invoice_item WHERE invoice_id = ?");
        $delete_items->bind_param("i", $invoice_id);
        $delete_items->execute();
        
        // Insert new items
        $insert_item = $conn->prepare("
            INSERT INTO invoice_item (
                invoice_id, cat_id, cat_name, product_name, hsn,
                quantity, unit, selling_price, discount, discount_type,
                taxable, cgst, sgst, cgst_amount, sgst_amount, total
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($new_items as $item) {
            $cat_id = !empty($item['cat_id']) ? intval($item['cat_id']) : null;
            $cat_name = $item['cat_name'] ?? '';
            $product_name = $item['product_name'] ?? $cat_name;
            $hsn = $item['hsn_code'] ?? '';
            $quantity = floatval($item['quantity'] ?? 0);
            $unit = $item['unit'] ?? 'pcs';
            $selling_price = floatval($item['selling_price'] ?? 0);
            $discount = floatval($item['discount'] ?? 0);
            $discount_type = $item['discount_type'] ?? 'fixed';
            $taxable = floatval($item['taxable'] ?? 0);
            $cgst = floatval($item['cgst'] ?? 0);
            $sgst = floatval($item['sgst'] ?? 0);
            $cgst_amt = floatval($item['cgst_amt'] ?? 0);
            $sgst_amt = floatval($item['sgst_amt'] ?? 0);
            $total = floatval($item['total'] ?? 0);
            
            $insert_item->bind_param("iisssddddddddddd",
                $invoice_id, $cat_id, $cat_name, $product_name, $hsn,
                $quantity, $unit, $selling_price, $discount, $discount_type,
                $taxable, $cgst, $sgst, $cgst_amt, $sgst_amt, $total
            );
            
            if (!$insert_item->execute()) {
                throw new Exception("Failed to insert item: " . $conn->error);
            }
            
            // Deduct stock for new items
            if ($cat_id) {
                $deduct_stmt = $conn->prepare("UPDATE category SET total_quantity = total_quantity - ? WHERE id = ?");
                $deduct_stmt->bind_param("di", $quantity, $cat_id);
                $deduct_stmt->execute();
            }
        }
        
        // Log activity
        $log_desc = "Updated invoice #" . $invoice['inv_num'];
        $log_stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)");
        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
        $log_stmt->execute();
        
        $conn->commit();
        
        // Redirect to view page
        header("Location: invoice-view.php?id=" . $invoice_id . "&updated=1");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

// Prepare items for JavaScript
$items_array = [];
$items->data_seek(0);
while ($item = $items->fetch_assoc()) {
    $items_array[] = [
        'id' => $item['id'],
        'cat_id' => $item['cat_id'],
        'cat_name' => $item['cat_name'],
        'product_name' => $item['product_name'],
        'hsn_code' => $item['hsn'],
        'quantity' => (float)$item['quantity'],
        'unit' => $item['unit'],
        'selling_price' => (float)$item['selling_price'],
        'discount' => (float)$item['discount'],
        'discount_type' => $item['discount_type'],
        'taxable' => (float)$item['taxable'],
        'cgst' => (float)$item['cgst'],
        'sgst' => (float)$item['sgst'],
        'cgst_amt' => (float)$item['cgst_amount'],
        'sgst_amt' => (float)$item['sgst_amount'],
        'total' => (float)$item['total']
    ];
}
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
        
        .edit-container {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            border: 1px solid #eef2f6;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--primary);
            font-size: 20px;
        }
        
        .form-label {
            font-weight: 500;
            color: #475569;
            font-size: 13px;
            margin-bottom: 5px;
        }
        
        .form-control, .form-select {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .input-group-text {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            color: #64748b;
        }
        
        .select2-container--default .select2-selection--single {
            height: 46px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 46px;
            padding-left: 14px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 46px;
        }
        
        .item-row {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid #e2e8f0;
            position: relative;
        }
        
        .remove-item {
            position: absolute;
            top: 10px;
            right: 10px;
            color: var(--danger);
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .remove-item:hover {
            background: #fee2e2;
        }
        
        .item-summary {
            background: white;
            border-radius: 8px;
            padding: 10px;
            margin-top: 15px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            border: 1px solid #e2e8f0;
        }
        
        .summary-item {
            flex: 1;
            min-width: 150px;
        }
        
        .summary-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
        }
        
        .summary-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .add-item-btn {
            border: 2px dashed #cbd5e1;
            background: white;
            padding: 15px;
            border-radius: 12px;
            width: 100%;
            color: #64748b;
            font-weight: 500;
            transition: all 0.2s;
            margin-top: 15px;
        }
        
        .add-item-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: #f8fafc;
        }
        
        .totals-card {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            border-radius: 16px;
            padding: 25px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .total-row:last-child {
            border-bottom: none;
        }
        
        .grand-total {
            font-size: 24px;
            font-weight: 700;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid rgba(255,255,255,0.2);
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
        
        .badge-info {
            background: #dbeafe;
            color: #2563eb;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .stock-warning {
            background: #fee2e2;
            color: #dc2626;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            margin-left: 8px;
        }
        
        @media (max-width: 768px) {
            .edit-container {
                padding: 20px;
            }
            
            .item-row {
                padding: 15px;
            }
            
            .remove-item {
                position: relative;
                top: 0;
                right: 0;
                text-align: right;
                margin-bottom: 10px;
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

            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Edit Invoice</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Update invoice #<?php echo htmlspecialchars($invoice['inv_num']); ?></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="invoice-view.php?id=<?php echo $invoice_id; ?>" class="btn-outline-custom">
                        <i class="bi bi-eye"></i> View Invoice
                    </a>
                    <a href="sales.php" class="btn-outline-custom">
                        <i class="bi bi-arrow-left"></i> Back to Sales
                    </a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Action Bar -->
            <div class="action-bar">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-pencil-square text-primary" style="font-size: 24px;"></i>
                    <div>
                        <strong class="d-block">Editing Invoice #<?php echo htmlspecialchars($invoice['inv_num']); ?></strong>
                        <small class="text-muted">Changes will be saved as a new version</small>
                    </div>
                </div>
                <div class="text-muted">
                    <i class="bi bi-calendar me-1"></i> Created: <?php echo date('d M Y', strtotime($invoice['created_at'])); ?>
                </div>
            </div>

            <!-- Edit Form -->
            <div class="edit-container">
                <form method="POST" action="invoice-edit.php?id=<?php echo $invoice_id; ?>" id="invoiceForm">
                    <input type="hidden" name="action" value="update_invoice">
                    <input type="hidden" name="items_json" id="items_json" value='<?php echo json_encode($items_array); ?>'>

                    <!-- Customer Details Section -->
                    <div class="section-title">
                        <i class="bi bi-person"></i>
                        Customer Details
                    </div>
                    
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Select Customer</label>
                            <select class="form-select" id="customerSelect" name="customer_id" style="width:100%">
                                <option value="">Walk-in Customer</option>
                                <?php 
                                if ($customers && $customers->num_rows > 0) {
                                    while ($customer = $customers->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $customer['id']; ?>" <?php echo $invoice['customer_id'] == $customer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['customer_name'] . ' • ' . ($customer['phone'] ?? '')); ?>
                                    </option>
                                <?php 
                                    endwhile; 
                                } 
                                ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Invoice Date</label>
                            <input type="date" name="invoice_date" class="form-control" value="<?php echo $invoice['invoice_date'] ?? date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash" <?php echo $invoice['payment_method'] == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="card" <?php echo $invoice['payment_method'] == 'card' ? 'selected' : ''; ?>>Card</option>
                                <option value="upi" <?php echo $invoice['payment_method'] == 'upi' ? 'selected' : ''; ?>>UPI</option>
                                <option value="bank" <?php echo $invoice['payment_method'] == 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="credit" <?php echo $invoice['payment_method'] == 'credit' ? 'selected' : ''; ?>>Credit</option>
                            </select>
                        </div>
                    </div>

                    <!-- Items Section -->
                    <div class="section-title mt-4">
                        <i class="bi bi-cart"></i>
                        Invoice Items
                    </div>

                    <div id="itemsContainer">
                        <!-- Items will be dynamically added here via JavaScript -->
                    </div>

                    <button type="button" class="add-item-btn" id="addItemBtn">
                        <i class="bi bi-plus-circle me-2"></i>Add Another Item
                    </button>

                    <!-- Discount Section -->
                    <div class="row mt-4">
                        <div class="col-md-4 offset-md-8">
                            <div class="card p-3" style="background: #f8fafc;">
                                <label class="form-label">Overall Discount</label>
                                <div class="input-group mb-2">
                                    <input type="number" name="overall_discount" class="form-control" step="0.01" min="0" value="<?php echo $invoice['overall_discount'] ?? 0; ?>" id="overallDiscount">
                                    <select name="discount_type" class="form-select" style="max-width: 100px;" id="discountType">
                                        <option value="fixed" <?php echo ($invoice['discount_type'] ?? 'fixed') == 'fixed' ? 'selected' : ''; ?>>₹</option>
                                        <option value="percentage" <?php echo ($invoice['discount_type'] ?? 'fixed') == 'percentage' ? 'selected' : ''; ?>>%</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Details -->
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label">Cash Received</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="cash_received" class="form-control" step="0.01" min="0" value="<?php echo $invoice['cash_received'] ?? 0; ?>" id="cashReceived">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Change Give</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="change_give" class="form-control" step="0.01" min="0" value="<?php echo $invoice['change_give'] ?? 0; ?>" id="changeGive" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mt-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($invoice['notes'] ?? ''); ?></textarea>
                    </div>

                    <!-- Totals Section -->
                    <div class="row mt-4">
                        <div class="col-md-5 offset-md-7">
                            <div class="totals-card">
                                <div class="total-row">
                                    <span>Subtotal:</span>
                                    <span class="fw-semibold" id="displaySubtotal">₹0.00</span>
                                </div>
                                <div class="total-row">
                                    <span>CGST:</span>
                                    <span class="fw-semibold" id="displayCGST">₹0.00</span>
                                </div>
                                <div class="total-row">
                                    <span>SGST:</span>
                                    <span class="fw-semibold" id="displaySGST">₹0.00</span>
                                </div>
                                <div class="total-row">
                                    <span>Discount:</span>
                                    <span class="fw-semibold text-warning" id="displayDiscount">₹0.00</span>
                                </div>
                                <div class="total-row grand-total">
                                    <span>Grand Total:</span>
                                    <span class="fw-bold" id="displayTotal">₹0.00</span>
                                </div>
                                <div class="total-row mt-2" style="border-top: 1px solid rgba(255,255,255,0.2);">
                                    <span>Pending:</span>
                                    <span class="fw-semibold" id="displayPending">₹0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="d-flex justify-content-end gap-3 mt-4">
                        <a href="invoice-view.php?id=<?php echo $invoice_id; ?>" class="btn btn-outline-secondary px-4 py-2">
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-success px-5 py-2" id="submitBtn">
                            <i class="bi bi-check-circle me-2"></i>Update Invoice
                        </button>
                    </div>
                </form>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Item Template -->
<template id="itemTemplate">
    <div class="item-row" data-item-id="">
        <div class="remove-item">
            <i class="bi bi-trash"></i> Remove
        </div>
        
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Category/Product <span class="text-danger">*</span></label>
                <select class="form-select category-select" style="width:100%"></select>
                <div class="stock-warning d-none mt-1"></div>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">HSN Code</label>
                <input type="text" class="form-control hsn-code" readonly>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                <input type="number" class="form-control quantity" step="0.01" min="0.01" value="1.00">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Unit</label>
                <select class="form-select unit">
                    <option value="pcs">Pieces</option>
                    <option value="kg">Kg</option>
                    <option value="box">Box</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Price/Unit <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">₹</span>
                    <input type="number" class="form-control price" step="0.01" min="0" value="0.00">
                </div>
            </div>
        </div>
        
        <div class="row g-3 mt-2">
            <div class="col-md-3">
                <label class="form-label">Discount</label>
                <div class="input-group">
                    <input type="number" class="form-control discount" step="0.01" min="0" value="0">
                    <select class="form-select discount-type" style="max-width: 80px;">
                        <option value="fixed">₹</option>
                        <option value="percentage">%</option>
                    </select>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">GST Rate</label>
                <select class="form-select gst-rate">
                    <option value="0,0">No GST</option>
                    <?php 
                    if ($gst_rates && $gst_rates->num_rows > 0) {
                        $gst_rates->data_seek(0);
                        while ($gst = $gst_rates->fetch_assoc()): 
                    ?>
                        <option value="<?php echo $gst['cgst'] . ',' . $gst['sgst']; ?>" data-hsn="<?php echo $gst['hsn']; ?>">
                            <?php echo $gst['hsn']; ?> - CGST: <?php echo $gst['cgst']; ?>% + SGST: <?php echo $gst['sgst']; ?>%
                        </option>
                    <?php 
                        endwhile; 
                    } 
                    ?>
                </select>
            </div>
        </div>
        
        <div class="item-summary">
            <div class="summary-item">
                <div class="summary-label">Taxable</div>
                <div class="summary-value taxable-amount">₹0.00</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">CGST</div>
                <div class="summary-value cgst-amount">₹0.00</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">SGST</div>
                <div class="summary-value sgst-amount">₹0.00</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Total</div>
                <div class="summary-value item-total fw-bold">₹0.00</div>
            </div>
        </div>
    </div>
</template>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
(function() {
    // --------------------------
    // State
    // --------------------------
    let items = <?php echo json_encode($items_array); ?>;
    let categories = [];
    let gstRates = [];
    let itemCounter = items.length;
    
    // --------------------------
    // Initialize Select2
    // --------------------------
    function initializeCategorySelect(selectElement) {
        $(selectElement).select2({
            placeholder: 'Search category...',
            allowClear: true,
            minimumInputLength: 1,
            ajax: {
                url: window.location.href + '&ajax=categories',
                dataType: 'json',
                delay: 300,
                data: function(params) {
                    return { term: params.term || '' };
                },
                processResults: function(data) {
                    return data;
                },
                cache: true
            },
            language: {
                inputTooShort: function() {
                    return 'Please enter 1 or more characters';
                }
            },
            templateResult: formatCategoryResult,
            templateSelection: formatCategorySelection
        });
    }
    
    function formatCategoryResult(category) {
        if (category.loading) return category.text;
        if (!category.meta) return category.text;
        
        return $(`
            <div>
                <div class="fw-semibold">${category.meta.category_name}</div>
                <div class="small text-muted">
                    Gram: ${category.meta.gram_value.toFixed(3)}g | 
                    Stock: ${category.meta.total_quantity.toFixed(2)} pcs |
                    Price: ₹${category.meta.purchase_price.toFixed(2)}
                </div>
            </div>
        `);
    }
    
    function formatCategorySelection(category) {
        if (!category.id) return category.text;
        return category.meta ? category.meta.category_name : category.text;
    }
    
    // --------------------------
    // Render Items
    // --------------------------
    function renderItems() {
        const container = $('#itemsContainer');
        container.empty();
        
        if (items.length === 0) {
            container.html('<div class="text-center py-4 text-muted">No items added. Click "Add Item" to start.</div>');
        } else {
            items.forEach((item, index) => {
                addItemToDOM(item, index);
            });
        }
        
        updateTotals();
        updateItemsJSON();
    }
    
    // --------------------------
    // Add Item to DOM
    // --------------------------
    function addItemToDOM(itemData, index) {
        const template = document.getElementById('itemTemplate');
        const clone = template.content.cloneNode(true);
        const itemRow = clone.querySelector('.item-row');
        
        itemRow.dataset.itemId = itemData.id || 'new_' + Date.now() + index;
        
        // Set values
        const categorySelect = itemRow.querySelector('.category-select');
        const quantity = itemRow.querySelector('.quantity');
        const unit = itemRow.querySelector('.unit');
        const price = itemRow.querySelector('.price');
        const discount = itemRow.querySelector('.discount');
        const discountType = itemRow.querySelector('.discount-type');
        const gstRate = itemRow.querySelector('.gst-rate');
        const hsnCode = itemRow.querySelector('.hsn-code');
        
        // Set values from itemData
        quantity.value = itemData.quantity || 1;
        unit.value = itemData.unit || 'pcs';
        price.value = itemData.selling_price || 0;
        discount.value = itemData.discount || 0;
        discountType.value = itemData.discount_type || 'fixed';
        
        if (itemData.cgst !== undefined && itemData.sgst !== undefined) {
            $(gstRate).val(itemData.cgst + ',' + itemData.sgst);
        }
        
        if (itemData.hsn_code) {
            hsnCode.value = itemData.hsn_code;
        }
        
        // Add to container
        document.getElementById('itemsContainer').appendChild(clone);
        
        // Initialize category select
        initializeCategorySelect(categorySelect);
        
        // If we have cat_id, pre-select it
        if (itemData.cat_id) {
            const option = new Option(itemData.cat_name, itemData.cat_id, true, true);
            $(categorySelect).append(option).trigger('change');
            
            // Manually trigger change to load meta data
            setTimeout(() => {
                $(categorySelect).trigger({
                    type: 'select2:select',
                    params: {
                        data: {
                            id: itemData.cat_id,
                            text: itemData.cat_name,
                            meta: {
                                category_name: itemData.cat_name,
                                gram_value: itemData.gram_value || 0,
                                purchase_price: itemData.purchase_price || itemData.selling_price || 0
                            }
                        }
                    }
                });
            }, 500);
        }
        
        // Attach event listeners
        attachItemEvents(itemRow);
    }
    
    // --------------------------
    // Attach Item Events
    // --------------------------
    function attachItemEvents(row) {
        const categorySelect = $(row).find('.category-select');
        const quantity = $(row).find('.quantity');
        const price = $(row).find('.price');
        const discount = $(row).find('.discount');
        const discountType = $(row).find('.discount-type');
        const gstRate = $(row).find('.gst-rate');
        const hsnCode = $(row).find('.hsn-code');
        const stockWarning = $(row).find('.stock-warning');
        
        // Category selection
        categorySelect.on('select2:select', function(e) {
            const data = e.params.data;
            const meta = data.meta || {};
            
            // Update HSN from GST selection
            const gstOption = gstRate.find('option:selected');
            if (gstOption.data('hsn')) {
                hsnCode.val(gstOption.data('hsn'));
            }
            
            // Set default price if not set
            if (price.val() == 0 && meta.purchase_price) {
                price.val(meta.purchase_price);
            }
            
            calculateItem(row);
        });
        
        // Input events
        quantity.on('input', () => calculateItem(row));
        price.on('input', () => calculateItem(row));
        discount.on('input', () => calculateItem(row));
        discountType.on('change', () => calculateItem(row));
        gstRate.on('change', function() {
            const selected = $(this).find('option:selected');
            if (selected.data('hsn')) {
                hsnCode.val(selected.data('hsn'));
            }
            calculateItem(row);
        });
        
        // Remove item
        $(row).find('.remove-item').on('click', function() {
            if (confirm('Remove this item?')) {
                $(row).remove();
                updateItemsFromDOM();
                updateTotals();
            }
        });
        
        // Initial calculation
        calculateItem(row);
    }
    
    // --------------------------
    // Calculate Item Totals
    // --------------------------
    function calculateItem(row) {
        const quantity = parseFloat($(row).find('.quantity').val()) || 0;
        const price = parseFloat($(row).find('.price').val()) || 0;
        const discount = parseFloat($(row).find('.discount').val()) || 0;
        const discountType = $(row).find('.discount-type').val();
        const gstValue = $(row).find('.gst-rate').val() || '0,0';
        const [cgst, sgst] = gstValue.split(',').map(Number);
        
        // Calculate subtotal
        let subtotal = quantity * price;
        
        // Apply discount
        let discountAmount = 0;
        if (discount > 0) {
            if (discountType === 'percentage') {
                discountAmount = (subtotal * discount) / 100;
            } else {
                discountAmount = discount;
            }
        }
        
        const taxable = subtotal - discountAmount;
        
        // Calculate GST
        const cgstAmt = (taxable * cgst) / 100;
        const sgstAmt = (taxable * sgst) / 100;
        const total = taxable + cgstAmt + sgstAmt;
        
        // Update display
        $(row).find('.taxable-amount').text('₹' + taxable.toFixed(2));
        $(row).find('.cgst-amount').text('₹' + cgstAmt.toFixed(2));
        $(row).find('.sgst-amount').text('₹' + sgstAmt.toFixed(2));
        $(row).find('.item-total').text('₹' + total.toFixed(2));
        
        // Check stock warning
        const categorySelect = $(row).find('.category-select');
        const selectedData = categorySelect.select2('data')[0];
        if (selectedData && selectedData.meta) {
            const stock = selectedData.meta.total_quantity || 0;
            const stockWarning = $(row).find('.stock-warning');
            
            if (quantity > stock) {
                stockWarning.removeClass('d-none').text('⚠️ Insufficient stock! Available: ' + stock.toFixed(2));
            } else {
                stockWarning.addClass('d-none');
            }
        }
        
        updateTotals();
    }
    
    // --------------------------
    // Update Totals
    // --------------------------
    function updateTotals() {
        let subtotal = 0;
        let totalCGST = 0;
        let totalSGST = 0;
        let totalAmount = 0;
        
        $('.item-row').each(function() {
            const taxable = parseFloat($(this).find('.taxable-amount').text().replace('₹', '')) || 0;
            const cgst = parseFloat($(this).find('.cgst-amount').text().replace('₹', '')) || 0;
            const sgst = parseFloat($(this).find('.sgst-amount').text().replace('₹', '')) || 0;
            const total = parseFloat($(this).find('.item-total').text().replace('₹', '')) || 0;
            
            subtotal += taxable;
            totalCGST += cgst;
            totalSGST += sgst;
            totalAmount += total;
        });
        
        // Apply overall discount
        const overallDiscount = parseFloat($('#overallDiscount').val()) || 0;
        const discountType = $('#discountType').val();
        let discountAmount = 0;
        
        if (overallDiscount > 0) {
            if (discountType === 'percentage') {
                discountAmount = (subtotal * overallDiscount) / 100;
            } else {
                discountAmount = overallDiscount;
            }
        }
        
        const afterDiscount = subtotal - discountAmount;
        const grandTotal = afterDiscount + totalCGST + totalSGST;
        
        // Update display
        $('#displaySubtotal').text('₹' + subtotal.toFixed(2));
        $('#displayCGST').text('₹' + totalCGST.toFixed(2));
        $('#displaySGST').text('₹' + totalSGST.toFixed(2));
        $('#displayDiscount').text('-₹' + discountAmount.toFixed(2));
        $('#displayTotal').text('₹' + grandTotal.toFixed(2));
        
        // Calculate pending
        const cashReceived = parseFloat($('#cashReceived').val()) || 0;
        const pending = Math.max(0, grandTotal - cashReceived);
        $('#displayPending').text('₹' + pending.toFixed(2));
        
        // Calculate change
        if (cashReceived > grandTotal) {
            $('#changeGive').val((cashReceived - grandTotal).toFixed(2));
        } else {
            $('#changeGive').val('0.00');
        }
        
        updateItemsFromDOM();
    }
    
    // --------------------------
    // Update Items from DOM
    // --------------------------
    function updateItemsFromDOM() {
        const newItems = [];
        
        $('.item-row').each(function(index) {
            const categorySelect = $(this).find('.category-select');
            const selectedData = categorySelect.select2('data')[0];
            
            if (!selectedData || !selectedData.id) return;
            
            const quantity = parseFloat($(this).find('.quantity').val()) || 0;
            const unit = $(this).find('.unit').val();
            const price = parseFloat($(this).find('.price').val()) || 0;
            const discount = parseFloat($(this).find('.discount').val()) || 0;
            const discountType = $(this).find('.discount-type').val();
            const gstValue = $(this).find('.gst-rate').val() || '0,0';
            const [cgst, sgst] = gstValue.split(',').map(Number);
            const hsnCode = $(this).find('.hsn-code').val();
            
            const taxable = parseFloat($(this).find('.taxable-amount').text().replace('₹', '')) || 0;
            const cgstAmt = parseFloat($(this).find('.cgst-amount').text().replace('₹', '')) || 0;
            const sgstAmt = parseFloat($(this).find('.sgst-amount').text().replace('₹', '')) || 0;
            const total = parseFloat($(this).find('.item-total').text().replace('₹', '')) || 0;
            
            newItems.push({
                id: 'item_' + Date.now() + index,
                cat_id: selectedData.id,
                cat_name: selectedData.meta ? selectedData.meta.category_name : selectedData.text,
                product_name: selectedData.meta ? selectedData.meta.category_name : selectedData.text,
                hsn_code: hsnCode,
                quantity: quantity,
                unit: unit,
                selling_price: price,
                discount: discount,
                discount_type: discountType,
                taxable: taxable,
                cgst: cgst,
                sgst: sgst,
                cgst_amt: cgstAmt,
                sgst_amt: sgstAmt,
                total: total
            });
        });
        
        items = newItems;
        updateItemsJSON();
    }
    
    // --------------------------
    // Update Items JSON
    // --------------------------
    function updateItemsJSON() {
        $('#items_json').val(JSON.stringify(items));
    }
    
    // --------------------------
    // Add New Item
    // --------------------------
    $('#addItemBtn').click(function() {
        const newItem = {
            id: 'new_' + Date.now(),
            quantity: 1,
            unit: 'pcs',
            selling_price: 0,
            discount: 0,
            discount_type: 'fixed',
            cgst: 0,
            sgst: 0
        };
        
        addItemToDOM(newItem, items.length);
    });
    
    // --------------------------
    // Event Listeners
    // --------------------------
    $('#overallDiscount, #discountType, #cashReceived').on('input change', function() {
        updateTotals();
    });
    
    // --------------------------
    // Form Submission
    // --------------------------
    $('#invoiceForm').submit(function(e) {
        updateItemsFromDOM();
        
        if (items.length === 0) {
            e.preventDefault();
            alert('Please add at least one item to the invoice.');
            return false;
        }
        
        // Check for stock warnings
        let hasStockIssue = false;
        $('.stock-warning').each(function() {
            if (!$(this).hasClass('d-none')) {
                hasStockIssue = true;
            }
        });
        
        if (hasStockIssue) {
            if (!confirm('Some items have insufficient stock. Continue anyway?')) {
                e.preventDefault();
                return false;
            }
        }
        
        $('#submitBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Updating...');
    });
    
    // --------------------------
    // Initialize
    // --------------------------
    renderItems();
    
})();
</script>

</body>
</html>