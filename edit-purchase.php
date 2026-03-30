<?php
// edit-purchase.php
session_start();
$currentPage = 'edit-purchase';
$pageTitle = 'Edit Purchase';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can edit purchases
checkRoleAccess(['admin']);

header_remove("X-Powered-By");

// --------------------------
// Helper Functions
// --------------------------
function money2($n) {
    return number_format((float)$n, 2, '.', '');
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Get purchase ID from URL
$purchase_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($purchase_id <= 0) {
    header('Location: manage-purchases.php?error=Invalid purchase ID');
    exit;
}

// --------------------------
// AJAX endpoints
// --------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] !== '') {
    $ajax = $_GET['ajax'];

    // Search suppliers for dropdown
    if ($ajax === 'suppliers') {
        $term = trim($_GET['term'] ?? '');
        $termLike = "%{$term}%";

        $stmt = $conn->prepare("
            SELECT id, supplier_name, phone, gst_number, opening_balance
            FROM suppliers
            WHERE supplier_name LIKE ? OR phone LIKE ? OR gst_number LIKE ?
            ORDER BY supplier_name ASC
            LIMIT 50
        ");
        $stmt->bind_param("sss", $termLike, $termLike, $termLike);
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $label = $row['supplier_name'];
            if (!empty($row['phone'])) $label .= " • " . $row['phone'];
            if (!empty($row['gst_number'])) $label .= " • " . $row['gst_number'];
            if ($row['opening_balance'] != 0) $label .= " • Bal: ₹" . money2($row['opening_balance']);

            $items[] = [
                "id"   => $row['id'],
                "text" => $label,
                "meta" => [
                    "supplier_name" => $row['supplier_name'],
                    "phone" => $row['phone'],
                    "gst_number" => $row['gst_number'],
                    "opening_balance" => $row['opening_balance']
                ]
            ];
        }

        json_response(["results" => $items]);
    }

    // Get supplier details
    if ($ajax === 'supplier_details') {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) json_response(["ok" => false, "message" => "Invalid supplier id"], 400);

        $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) json_response(["ok" => false, "message" => "Supplier not found"], 404);
        json_response(["ok" => true, "supplier" => $row]);
    }

    // Search categories for dropdown (preforms)
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
            $label .= " • " . $row['gram_value'] . "g/pc";
            $label .= " • " . $pcs_per_kg . " pcs/kg";
            $label .= " • Stock: " . $row['total_quantity'] . " pcs";

            $items[] = [
                "id"   => $row['id'],
                "text" => $label,
                "meta" => [
                    "category_name" => $row['category_name'],
                    "purchase_price" => (float)$row['purchase_price'],
                    "gram_value" => (float)$row['gram_value'],
                    "total_quantity" => (float)$row['total_quantity'],
                    "min_stock_level" => (float)$row['min_stock_level'],
                    "pcs_per_kg" => $pcs_per_kg,
                    "item_type" => "category"
                ]
            ];
        }

        json_response(["results" => $items]);
    }

    // Search products for dropdown (direct sale products)
    if ($ajax === 'products') {
        $term = trim($_GET['term'] ?? '');
        $termLike = "%{$term}%";

        $stmt = $conn->prepare("
            SELECT id, product_name, product_type, hsn_code, primary_unit, stock_quantity
            FROM product
            WHERE product_name LIKE ?
            ORDER BY product_name ASC
            LIMIT 50
        ");
        $stmt->bind_param("s", $termLike);
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $type_label = ($row['product_type'] == 'direct') ? 'Direct Sale' : 'Converted Sale';
            $unit = $row['primary_unit'] ?: 'pcs';
            
            $label = $row['product_name'];
            $label .= " • {$type_label}";
            $label .= " • Unit: {$unit}";
            $label .= " • Stock: " . ($row['stock_quantity'] ?? 0) . " {$unit}";

            $items[] = [
                "id"   => $row['id'],
                "text" => $label,
                "meta" => [
                    "product_name" => $row['product_name'],
                    "product_type" => $row['product_type'],
                    "hsn_code" => $row['hsn_code'],
                    "primary_unit" => $row['primary_unit'],
                    "stock_quantity" => (float)$row['stock_quantity'],
                    "item_type" => "product"
                ]
            ];
        }

        json_response(["results" => $items]);
    }

    json_response(["ok" => false, "message" => "Unknown ajax endpoint"], 404);
}

// --------------------------
// Fetch Purchase Data
// --------------------------
$purchase_data = null;
$purchase_items = [];
$purchase_payments = [];

// Get purchase details
$stmt = $conn->prepare("
    SELECT p.*, s.supplier_name, s.phone, s.gst_number, s.opening_balance
    FROM purchase p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.id = ?
");
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchase_data = $stmt->get_result()->fetch_assoc();

if (!$purchase_data) {
    header('Location: manage-purchases.php?error=Purchase not found');
    exit;
}

// Get purchase items with category and product info
$item_stmt = $conn->prepare("
    SELECT pi.*, 
           c.category_name, c.gram_value,
           prod.product_name, prod.primary_unit, prod.product_type
    FROM purchase_item pi
    LEFT JOIN category c ON pi.cat_id = c.id
    LEFT JOIN product prod ON pi.product_id = prod.id
    WHERE pi.purchase_id = ?
    ORDER BY pi.id ASC
");
$item_stmt->bind_param("i", $purchase_id);
$item_stmt->execute();
$purchase_items = $item_stmt->get_result();

// Get payment history
$payment_stmt = $conn->prepare("
    SELECT * FROM purchase_payment_history
    WHERE purchase_id = ?
    ORDER BY payment_date ASC
");
$payment_stmt->bind_param("i", $purchase_id);
$payment_stmt->execute();
$purchase_payments = $payment_stmt->get_result();

// --------------------------
// Handle Form Submission
// --------------------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_purchase') {
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $purchase_no = trim($_POST['purchase_no'] ?? '');
    $invoice_num = trim($_POST['invoice_num'] ?? '');
    $purchase_date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
    $gst_type = $_POST['gst_type'] ?? 'exclusive';
    $items_json = $_POST['items_json'] ?? '';
    $items = json_decode($items_json, true);
    
    // Payment details array
    $payments = [];
    if (isset($_POST['payments']) && is_array($_POST['payments'])) {
        $payments = $_POST['payments'];
    }

    if ($supplier_id <= 0) {
        $error = "Please select a supplier.";
    } elseif (empty($purchase_no)) {
        $error = "Purchase number is required.";
    } elseif (!is_array($items) || count($items) === 0) {
        $error = "Please add at least one item.";
    } else {
        // Calculate totals
        $total_taxable = 0;
        $total_cgst = 0;
        $total_sgst = 0;
        $total_amount = 0;

        $conn->begin_transaction();

        try {
            // Get original items to revert stock
            $orig_items = $conn->prepare("SELECT cat_id, product_id, qty, unit, item_type FROM purchase_item WHERE purchase_id = ?");
            $orig_items->bind_param("i", $purchase_id);
            $orig_items->execute();
            $orig_result = $orig_items->get_result();
            
            // Revert original quantities
            while ($orig = $orig_result->fetch_assoc()) {
                if ($orig['cat_id']) {
                    // Revert category stock
                    $revert_cat = $conn->prepare("
                        UPDATE category 
                        SET total_quantity = total_quantity - ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $revert_cat->bind_param("di", $orig['qty'], $orig['cat_id']);
                    $revert_cat->execute();
                    $revert_cat->close();
                } else if ($orig['product_id']) {
                    // Revert product stock
                    $revert_product = $conn->prepare("
                        UPDATE product 
                        SET stock_quantity = stock_quantity - ?
                        WHERE id = ?
                    ");
                    $revert_product->bind_param("di", $orig['qty'], $orig['product_id']);
                    $revert_product->execute();
                    $revert_product->close();
                }
            }
            $orig_items->close();

            // Delete existing items and GST credit
            $del_items = $conn->prepare("DELETE FROM purchase_item WHERE purchase_id = ?");
            $del_items->bind_param("i", $purchase_id);
            $del_items->execute();
            $del_items->close();

            $del_gst = $conn->prepare("DELETE FROM gst_credit_table WHERE purchase_id = ?");
            $del_gst->bind_param("i", $purchase_id);
            $del_gst->execute();
            $del_gst->close();

            // Delete existing payment history
            $del_payments = $conn->prepare("DELETE FROM purchase_payment_history WHERE purchase_id = ?");
            $del_payments->bind_param("i", $purchase_id);
            $del_payments->execute();
            $del_payments->close();

            // Determine purchase type
            $has_category = false;
            $has_product = false;
            foreach ($items as $item) {
                if ($item['item_type'] == 'category') $has_category = true;
                if ($item['item_type'] == 'product') $has_product = true;
            }
            $purchase_type = ($has_category) ? 'category' : 'product';

            // Update purchase record
            $update_purchase = $conn->prepare("
                UPDATE purchase SET
                    supplier_id = ?, purchase_no = ?, invoice_num = ?, purchase_date = ?,
                    gst_type = ?, purchase_type = ?
                WHERE id = ?
            ");
            $update_purchase->bind_param(
                "isssssi",
                $supplier_id, $purchase_no, $invoice_num, $purchase_date,
                $gst_type, $purchase_type, $purchase_id
            );
            $update_purchase->execute();
            $update_purchase->close();

            // Insert new purchase items
            $item_stmt = $conn->prepare("
                INSERT INTO purchase_item (
                    purchase_id, cat_id, product_id, cat_name, cat_grm_value, hsn,
                    taxable, cgst, cgst_amount, sgst, sgst_amount,
                    purchase_price, total, qty, unit, sec_qty, sec_unit
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($items as $item) {
                $cat_id = ($item['item_type'] == 'category') ? $item['cat_id'] : null;
                $product_id = ($item['item_type'] == 'product') ? $item['product_id'] : null;
                $cat_name = $item['cat_name'] ?? '';
                $cat_grm = $item['gram_value'] ?? 0;
                $hsn = $item['hsn_code'] ?? '';
                $taxable = $item['taxable'];
                $cgst = $item['cgst'] ?? 0;
                $cgst_amt = $item['cgst_amt'];
                $sgst = $item['sgst'] ?? 0;
                $sgst_amt = $item['sgst_amt'];
                $purchase_price = $item['purchase_price'];
                $total = $item['total'];
                $qty = $item['qty'];
                $unit = $item['unit'];
                $kg_qty = $item['kg_qty'] ?? 0;
                $sec_unit = $item['sec_unit'] ?? '';
                
                $total_taxable += $taxable;
                $total_cgst += $cgst_amt;
                $total_sgst += $sgst_amt;
                $total_amount += $total;

                $item_stmt->bind_param(
                    "iiisdsddddddddddsds",
                    $purchase_id, $cat_id, $product_id, $cat_name, $cat_grm, $hsn,
                    $taxable, $cgst, $cgst_amt, $sgst, $sgst_amt,
                    $purchase_price, $total, $qty, $unit, $kg_qty, $sec_unit
                );
                $item_stmt->execute();

                // Update stock with new quantities
                if ($item['item_type'] == 'category') {
                    $update_cat = $conn->prepare("
                        UPDATE category 
                        SET total_quantity = total_quantity + ?,
                            purchase_price = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $update_cat->bind_param("ddi", $qty, $purchase_price, $cat_id);
                    $update_cat->execute();
                    $update_cat->close();
                } else if ($item['item_type'] == 'product') {
                    $update_product = $conn->prepare("
                        UPDATE product 
                        SET stock_quantity = stock_quantity + ?
                        WHERE id = ?
                    ");
                    $update_product->bind_param("di", $qty, $product_id);
                    $update_product->execute();
                    $update_product->close();
                }
            }
            $item_stmt->close();

            // Update purchase with calculated totals
            $update_totals = $conn->prepare("
                UPDATE purchase 
                SET cgst = ?, cgst_amount = ?, sgst = ?, sgst_amount = ?, total = ?
                WHERE id = ?
            ");
            $cgst_rate = $total_taxable > 0 ? ($total_cgst / $total_taxable * 100) : 0;
            $sgst_rate = $total_taxable > 0 ? ($total_sgst / $total_taxable * 100) : 0;

            $update_totals->bind_param(
                "dddddi",
                $cgst_rate, $total_cgst, $sgst_rate, $total_sgst,
                $total_amount, $purchase_id
            );
            $update_totals->execute();
            $update_totals->close();

            // Add to GST credit table if applicable
            if ($total_cgst > 0 || $total_sgst > 0) {
                $gst_credit = $conn->prepare("
                    INSERT INTO gst_credit_table (purchase_id, cgst, sgst, total_credit)
                    VALUES (?, ?, ?, ?)
                ");
                $total_credit = $total_cgst + $total_sgst;
                $gst_credit->bind_param("iddd", $purchase_id, $total_cgst, $total_sgst, $total_credit);
                $gst_credit->execute();
                $gst_credit->close();
            }

            // Insert new payment history records
            if (!empty($payments)) {
                $payment_stmt = $conn->prepare("
                    INSERT INTO purchase_payment_history (purchase_id, paid_amount, payment_method, notes)
                    VALUES (?, ?, ?, ?)
                ");
                
                foreach ($payments as $payment) {
                    $amount = floatval($payment['amount'] ?? 0);
                    $method = $payment['method'] ?? 'cash';
                    $notes = $payment['notes'] ?? '';
                    
                    if ($amount > 0) {
                        $payment_stmt->bind_param("idss", $purchase_id, $amount, $method, $notes);
                        $payment_stmt->execute();
                    }
                }
                $payment_stmt->close();
            }

            // Log activity
            $log_desc = "Updated purchase #{$purchase_no} (ID: {$purchase_id})";
            $log_stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)");
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();

            $conn->commit();
            $success = "Purchase updated successfully.";

            // Refresh purchase data
            $stmt = $conn->prepare("SELECT p.*, s.supplier_name, s.phone, s.gst_number, s.opening_balance FROM purchase p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
            $stmt->bind_param("i", $purchase_id);
            $stmt->execute();
            $purchase_data = $stmt->get_result()->fetch_assoc();

            // Refresh items
            $item_stmt = $conn->prepare("
                SELECT pi.*, 
                       c.category_name, c.gram_value,
                       prod.product_name, prod.primary_unit, prod.product_type
                FROM purchase_item pi
                LEFT JOIN category c ON pi.cat_id = c.id
                LEFT JOIN product prod ON pi.product_id = prod.id
                WHERE pi.purchase_id = ?
                ORDER BY pi.id ASC
            ");
            $item_stmt->bind_param("i", $purchase_id);
            $item_stmt->execute();
            $purchase_items = $item_stmt->get_result();

            // Refresh payments
            $payment_stmt = $conn->prepare("SELECT * FROM purchase_payment_history WHERE purchase_id = ? ORDER BY payment_date ASC");
            $payment_stmt->bind_param("i", $purchase_id);
            $payment_stmt->execute();
            $purchase_payments = $payment_stmt->get_result();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to update purchase: " . $e->getMessage();
        }
    }
}

// Get all GST rates
$gst_rates = $conn->query("SELECT * FROM gst WHERE status = 1 ORDER BY hsn ASC");

// Get all active bank accounts for dropdown
$bank_accounts = $conn->query("SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, account_name ASC");

// Payment methods
$payment_methods = ['cash', 'card', 'upi', 'bank'];

// Format items for JavaScript
$items_js = [];
if ($purchase_items && $purchase_items->num_rows > 0) {
    while ($item = $purchase_items->fetch_assoc()) {
        $item_type = $item['product_id'] ? 'product' : 'category';
        $items_js[] = [
            'id' => $item['id'],
            'item_type' => $item_type,
            'cat_id' => $item['cat_id'],
            'product_id' => $item['product_id'],
            'cat_name' => $item['category_name'] ?? $item['cat_name'] ?? '',
            'product_name' => $item['product_name'] ?? '',
            'gram_value' => (float)$item['cat_grm_value'],
            'hsn_code' => $item['hsn'],
            'cgst' => (float)$item['cgst'],
            'sgst' => (float)$item['sgst'],
            'cgst_amt' => (float)$item['cgst_amount'],
            'sgst_amt' => (float)$item['sgst_amount'],
            'taxable' => (float)$item['taxable'],
            'total' => (float)$item['total'],
            'kg_qty' => (float)$item['sec_qty'],
            'qty' => (float)$item['qty'],
            'unit' => $item['unit'],
            'purchase_price' => (float)$item['purchase_price'],
            'primary_unit' => $item['primary_unit'] ?? 'pcs'
        ];
    }
    // Reset pointer for later use
    $purchase_items->data_seek(0);
}

// Format payments for JavaScript
$payments_js = [];
if ($purchase_payments && $purchase_payments->num_rows > 0) {
    while ($payment = $purchase_payments->fetch_assoc()) {
        $payments_js[] = [
            'id' => $payment['id'],
            'amount' => (float)$payment['paid_amount'],
            'method' => $payment['payment_method'],
            'notes' => $payment['notes']
        ];
    }
    $purchase_payments->data_seek(0);
}

// Calculate total paid
$total_paid = 0;
foreach ($payments_js as $p) {
    $total_paid += $p['amount'];
}
$balance = $purchase_data['total'] - $total_paid;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .supplier-badge {
            background: #e8f2ff;
            color: #2463eb;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .gst-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid #e2e8f0;
        }
        
        .gst-toggle .btn {
            padding: 6px 20px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .gst-toggle .btn.active {
            background: var(--primary);
            color: white;
        }
        
        .item-type-selector {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            padding: 12px;
            background: #f8fafc;
            border-radius: 12px;
        }
        
        .item-type-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .item-type-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .item-type-option label {
            cursor: pointer;
            margin: 0;
            font-weight: 500;
        }
        
        .item-type-desc {
            font-size: 11px;
            color: #64748b;
            margin-left: 28px;
            margin-top: -4px;
        }
        
        .payment-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            position: relative;
        }
        
        .payment-card .remove-payment {
            position: absolute;
            top: 10px;
            right: 10px;
            color: #ef4444;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
        }
        
        .payment-card .remove-payment:hover {
            background: #fee2e2;
        }
        
        .add-payment-btn {
            border: 2px dashed #cbd5e1;
            background: white;
            padding: 12px;
            border-radius: 12px;
            width: 100%;
            color: #64748b;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .add-payment-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: #f8fafc;
        }
        
        .total-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        }
        
        .conversion-preview {
            background: #f0f9ff;
            border-left: 3px solid #0ea5e9;
            padding: 16px;
            border-radius: 12px;
            margin-top: 16px;
            font-size: 14px;
        }
        
        .preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }
        
        .preview-item {
            background: white;
            padding: 10px;
            border-radius: 8px;
        }
        
        .preview-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
        }
        
        .preview-value {
            font-size: 16px;
            font-weight: 600;
            color: #0c4a6e;
        }
        
        .badge-success {
            background: #10b981;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
        }
        
        .badge-warning {
            background: #f59e0b;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
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
        
        .category-badge {
            background: #f0fdf4;
            color: #16a34a;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 42px;
            padding-left: 14px;
        }
        
        .form-control, .form-select {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        
        .btn-primary {
            background: var(--primary);
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 500;
        }
        
        .btn-success {
            background: #10b981;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .card-custom {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            border: 1px solid #edf2f9;
            margin-bottom: 24px;
        }
        
        .card-header-custom {
            padding: 20px 24px;
            border-bottom: 1px solid #edf2f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-body-custom {
            padding: 24px;
        }
        
        .stock-warning {
            background: #fff3cd;
            border: 1px solid #ffe69c;
            color: #856404;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        
        .product-unit-badge {
            background: #e8f2ff;
            color: #2463eb;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        @media (max-width: 768px) {
            .card-header-custom {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .preview-grid {
                grid-template-columns: 1fr;
            }
            
            .item-type-selector {
                flex-direction: column;
                gap: 12px;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Edit Purchase</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">
                        Editing purchase #<?php echo htmlspecialchars($purchase_data['purchase_no']); ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="view-purchase.php?id=<?php echo $purchase_id; ?>" class="btn-info-custom">
                        <i class="bi bi-eye"></i> View
                    </a>
                    <a href="manage-purchases.php" class="btn-outline-custom">
                        <i class="bi bi-arrow-left"></i> Back to Purchases
                    </a>
                </div>
            </div>

            <!-- Stock Warning -->
            <div class="stock-warning">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Note:</strong> Editing this purchase will revert original stock and add new quantities. 
                Please verify all changes carefully.
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="edit-purchase.php?id=<?php echo $purchase_id; ?>" id="purchaseForm">
                <input type="hidden" name="action" value="update_purchase">
                <input type="hidden" name="items_json" id="items_json" value='<?php echo json_encode($items_js); ?>'>

                <!-- Purchase Details Card -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-info-circle me-2"></i>Purchase Details</h5>
                        <div class="gst-toggle">
                            <span class="text-muted me-2">GST:</span>
                            <button type="button" class="btn <?php echo ($purchase_data['gst_type'] === 'exclusive') ? 'active' : ''; ?>" id="gstExclusiveBtn" onclick="setGSTType('exclusive')">Exclusive</button>
                            <button type="button" class="btn <?php echo ($purchase_data['gst_type'] === 'inclusive') ? 'active' : ''; ?>" id="gstInclusiveBtn" onclick="setGSTType('inclusive')">Inclusive</button>
                            <input type="hidden" name="gst_type" id="gstType" value="<?php echo htmlspecialchars($purchase_data['gst_type'] ?? 'exclusive'); ?>">
                        </div>
                    </div>
                    <div class="card-body-custom">
                        <div class="row g-4">
                            <!-- Supplier Selection -->
                            <div class="col-md-6">
                                <label class="form-label">Select Supplier <span class="text-danger">*</span></label>
                                <select class="form-select" id="supplierSelect" name="supplier_id" style="width:100%" required>
                                    <option value="<?php echo $purchase_data['supplier_id']; ?>" selected>
                                        <?php echo htmlspecialchars($purchase_data['supplier_name'] ?? ''); ?>
                                        <?php if (!empty($purchase_data['phone'])): ?>
                                            • <?php echo htmlspecialchars($purchase_data['phone']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($purchase_data['gst_number'])): ?>
                                            • <?php echo htmlspecialchars($purchase_data['gst_number']); ?>
                                        <?php endif; ?>
                                    </option>
                                </select>
                                <div class="row g-2 mt-3" id="supplierInfo" style="display: <?php echo $purchase_data['supplier_id'] ? 'flex' : 'none'; ?>;">
                                    <div class="col-md-4">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">Phone</small>
                                            <span class="fw-semibold" id="supplierPhone"><?php echo htmlspecialchars($purchase_data['phone'] ?? '-'); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">GST</small>
                                            <span class="fw-semibold" id="supplierGST"><?php echo htmlspecialchars($purchase_data['gst_number'] ?? '-'); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">Balance</small>
                                            <span class="fw-semibold" id="supplierBalance">₹<?php echo money2($purchase_data['opening_balance'] ?? 0); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Purchase Number -->
                            <div class="col-md-6">
                                <label class="form-label">Purchase Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="purchase_no" 
                                       value="<?php echo htmlspecialchars($purchase_data['purchase_no']); ?>" required>
                                <small class="text-muted">Unique identifier for this purchase</small>
                            </div>

                            <!-- Invoice Number -->
                            <div class="col-md-4">
                                <label class="form-label">Supplier Invoice Number</label>
                                <input type="text" class="form-control" name="invoice_num" 
                                       value="<?php echo htmlspecialchars($purchase_data['invoice_num'] ?? ''); ?>"
                                       placeholder="Enter supplier invoice number">
                            </div>

                            <!-- Purchase Date -->
                            <div class="col-md-4">
                                <label class="form-label">Purchase Date</label>
                                <input type="date" class="form-control" name="purchase_date" 
                                       value="<?php echo htmlspecialchars($purchase_data['purchase_date']); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add Items Card -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-cart-plus me-2"></i>Add Items</h5>
                        <span class="badge bg-light text-dark">Category (kg→pieces) or Direct Product</span>
                    </div>
                    <div class="card-body-custom">
                        <!-- Item Type Selector -->
                        <div class="item-type-selector">
                            <div class="item-type-option">
                                <input type="radio" name="item_type_selector" id="item_type_category" value="category" checked>
                                <label for="item_type_category">Category (Preform / Raw Material)</label>
                            </div>
                            <div class="item-type-desc">
                                <i class="bi bi-info-circle"></i> Purchase preforms/raw materials in KG, convert to pieces
                            </div>
                            <div class="item-type-option">
                                <input type="radio" name="item_type_selector" id="item_type_product" value="product">
                                <label for="item_type_product">Direct Sale Product</label>
                            </div>
                            <div class="item-type-desc">
                                <i class="bi bi-info-circle"></i> Purchase finished products directly in their unit (bag, box, etc.)
                            </div>
                        </div>

                        <div id="categoryFields">
                            <div class="row g-4">
                                <div class="col-md-4">
                                    <label class="form-label">Select Category <span class="text-danger">*</span></label>
                                    <select class="form-select" id="categorySelect" style="width:100%"></select>
                                    <div class="mt-2" id="categoryMeta"></div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">GST Rate</label>
                                    <select class="form-select" id="gstSelect">
                                        <option value="0,0">No GST</option>
                                        <?php 
                                        if ($gst_rates && $gst_rates->num_rows > 0) {
                                            while ($gst = $gst_rates->fetch_assoc()): 
                                        ?>
                                            <option value="<?php echo $gst['cgst'] . ',' . $gst['sgst']; ?>">
                                                <?php echo $gst['hsn']; ?> - CGST: <?php echo $gst['cgst']; ?>% + SGST: <?php echo $gst['sgst']; ?>%
                                            </option>
                                        <?php 
                                            endwhile; 
                                        } 
                                        ?>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">Quantity (kg)</label>
                                    <input type="number" class="form-control" id="kgInput" 
                                           step="0.001" min="0.001" disabled>
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">Total Price (₹)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" class="form-control" id="totalPriceInput" 
                                               step="0.01" min="0" disabled>
                                    </div>
                                </div>

                                <div class="col-12 d-flex justify-content-end">
                                    <button type="button" class="btn btn-primary" id="addItemBtn" disabled>
                                        <i class="bi bi-plus-circle me-2"></i>Add Category Item
                                    </button>
                                </div>
                            </div>

                            <div id="conversionPreview" class="conversion-preview" style="display: none;">
                                <h6 class="mb-3"><i class="bi bi-calculator me-2"></i>Conversion Preview</h6>
                                <div class="preview-grid">
                                    <div class="preview-item">
                                        <div class="preview-label">Gram per piece</div>
                                        <div class="preview-value" id="previewGram">0 g</div>
                                    </div>
                                    <div class="preview-item">
                                        <div class="preview-label">Pieces per kg</div>
                                        <div class="preview-value" id="previewPcsPerKg">0 pcs</div>
                                    </div>
                                    <div class="preview-item">
                                        <div class="preview-label">Total pieces</div>
                                        <div class="preview-value" id="previewPcs">0 pcs</div>
                                    </div>
                                    <div class="preview-item">
                                        <div class="preview-label">Price per piece</div>
                                        <div class="preview-value" id="previewPricePerPc">₹0.00</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="productFields" style="display: none;">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="form-label">Select Product <span class="text-danger">*</span></label>
                                    <select class="form-select" id="productSelect" style="width:100%"></select>
                                    <div class="mt-2" id="productMeta"></div>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label" id="productQuantityLabel">Quantity (unit)</label>
                                    <input type="number" class="form-control" id="productQuantityInput" 
                                           step="1" min="1" disabled>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label" id="productPriceLabel">Total Price (₹)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" class="form-control" id="productTotalInput" 
                                               step="0.01" min="0" disabled>
                                    </div>
                                </div>

                                <div class="col-12 d-flex justify-content-end">
                                    <button type="button" class="btn btn-primary" id="addProductItemBtn" disabled>
                                        <i class="bi bi-plus-circle me-2"></i>Add Product Item
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items List Card -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-list-check me-2"></i>Purchase Items</h5>
                        <span class="badge bg-light text-dark" id="itemCount"><?php echo count($items_js); ?> items</span>
                    </div>
                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="itemsTable">
                                <thead class="table-light">
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
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <?php if (count($items_js) > 0): ?>
                                        <?php foreach ($items_js as $index => $item): 
                                            $type_badge = $item['item_type'] == 'category' 
                                                ? '<span class="category-badge"><i class="bi bi-layers"></i> Category</span>'
                                                : '<span class="product-badge"><i class="bi bi-box"></i> Product</span>';
                                            $item_name = $item['item_type'] == 'category' ? $item['cat_name'] : $item['product_name'];
                                            $details = $item['item_type'] == 'category' 
                                                ? "{$item['gram_value']} g/pc • {$item['kg_qty']} kg = " . round($item['qty']) . " pcs"
                                                : "Unit: {$item['unit']}";
                                            $gst_percent = $item['cgst'] + $item['sgst'];
                                        ?>
                                             <tr data-item-index="<?php echo $index; ?>">
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo $type_badge; ?></td>
                                                <td class="fw-semibold"><?php echo htmlspecialchars($item_name); ?></td>
                                                <td class="text-muted small"><?php echo htmlspecialchars($details); ?></td>
                                                <td class="text-end"><?php echo number_format($item['qty'], 2); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars($item['unit']); ?></td>
                                                <td class="text-end">₹<?php echo number_format($item['purchase_price'], 2); ?></td>
                                                <td class="text-end">₹<?php echo number_format($item['taxable'], 2); ?></td>
                                                <td class="text-end">
                                                    <?php echo $gst_percent; ?>%<br>
                                                    <small>₹<?php echo number_format($item['cgst_amt'] + $item['sgst_amt'], 2); ?></small>
                                                </td>
                                                <td class="text-end fw-bold">₹<?php echo number_format($item['total'], 2); ?></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(<?php echo $index; ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center py-4 text-muted">
                                                No items added yet
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="7" class="text-end">Totals:</th>
                                        <th id="totalPrice">₹<?php echo money2($purchase_data['total'] - ($purchase_data['cgst_amount'] + $purchase_data['sgst_amount'])); ?></th>
                                        <th id="totalGST">₹<?php echo money2($purchase_data['cgst_amount'] + $purchase_data['sgst_amount']); ?></th>
                                        <th id="totalAmount">₹<?php echo money2($purchase_data['total']); ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Payment Details Card -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-credit-card me-2"></i>Payment Details</h5>
                        <span class="badge bg-light text-dark">Multiple payments allowed</span>
                    </div>
                    <div class="card-body-custom">
                        <div id="paymentsContainer">
                            <?php if (count($payments_js) > 0): ?>
                                <?php foreach ($payments_js as $index => $payment): ?>
                                    <div class="payment-card" id="payment-<?php echo $payment['id']; ?>">
                                        <span class="remove-payment" onclick="removePayment(<?php echo $payment['id']; ?>)">
                                            <i class="bi bi-x-lg"></i>
                                        </span>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Amount (₹)</label>
                                                <input type="number" class="form-control form-control-sm payment-amount" 
                                                       name="payments[<?php echo $payment['id']; ?>][amount]" 
                                                       value="<?php echo $payment['amount']; ?>"
                                                       step="0.01" min="0" 
                                                       onchange="updatePaymentTotals()" onkeyup="updatePaymentTotals()">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Method</label>
                                                <select class="form-select form-select-sm" name="payments[<?php echo $payment['id']; ?>][method]">
                                                    <?php foreach ($payment_methods as $method): ?>
                                                        <option value="<?php echo $method; ?>" <?php echo $payment['method'] == $method ? 'selected' : ''; ?>>
                                                            <?php echo ucfirst($method); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Notes</label>
                                                <input type="text" class="form-control form-control-sm" 
                                                       name="payments[<?php echo $payment['id']; ?>][notes]" 
                                                       value="<?php echo htmlspecialchars($payment['notes']); ?>"
                                                       placeholder="Optional">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <button type="button" class="add-payment-btn mt-3" onclick="addPayment()">
                            <i class="bi bi-plus-circle me-2"></i>Add Another Payment
                        </button>

                        <!-- Total Section -->
                        <div class="total-section mt-4">
                            <div class="total-row">
                                <span>Total Taxable Amount</span>
                                <span class="fw-semibold" id="totalPurchaseDisplay">₹<?php echo money2($purchase_data['total'] - ($purchase_data['cgst_amount'] + $purchase_data['sgst_amount'])); ?></span>
                            </div>
                            <div class="total-row">
                                <span>Total GST</span>
                                <span class="fw-semibold" id="totalGSTDisplay">₹<?php echo money2($purchase_data['cgst_amount'] + $purchase_data['sgst_amount']); ?></span>
                            </div>
                            <div class="total-row">
                                <span>Total Paid</span>
                                <span class="fw-semibold" id="totalPaidDisplay">₹<?php echo money2($total_paid); ?></span>
                            </div>
                            <div class="total-row">
                                <span>Balance Due</span>
                                <span class="fw-semibold" id="balanceDueDisplay">₹<?php echo money2($balance); ?></span>
                            </div>
                            <div class="total-row grand-total">
                                <span>Grand Total (with GST)</span>
                                <span id="grandTotalDisplay">₹<?php echo money2($purchase_data['total']); ?></span>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-end gap-3 mt-4">
                            <a href="manage-purchases.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-success" id="submitBtn">
                                <i class="bi bi-check-circle me-2"></i>Update Purchase
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
(function() {
    // State Variables
    let selectedCategory = null;
    let selectedProduct = null;
    let items = <?php echo json_encode($items_js); ?>;
    let gstType = '<?php echo addslashes($purchase_data['gst_type'] ?? 'exclusive'); ?>';
    let currentItemType = 'category';
    let nextPaymentId = Date.now() + 1000000;
    
    // Bank accounts data
    const bankAccounts = <?php 
        $bank_accounts_data = [];
        if ($bank_accounts && mysqli_num_rows($bank_accounts) > 0) {
            mysqli_data_seek($bank_accounts, 0);
            while ($acc = mysqli_fetch_assoc($bank_accounts)) {
                $bank_accounts_data[] = $acc;
            }
        }
        echo json_encode($bank_accounts_data); 
    ?>;
    
    // Select2 Initialization
    $('#supplierSelect').select2({
        placeholder: 'Search supplier by name, phone or GST...',
        allowClear: true,
        ajax: {
            url: 'edit-purchase.php?ajax=suppliers',
            dataType: 'json',
            delay: 250,
            data: function(params) { return { term: params.term || '' }; },
            processResults: function(data) { return data; }
        }
    });
    
    $('#categorySelect').select2({
        placeholder: 'Search category...',
        allowClear: true,
        ajax: {
            url: 'edit-purchase.php?ajax=categories',
            dataType: 'json',
            delay: 250,
            data: function(params) { return { term: params.term || '' }; },
            processResults: function(data) { return data; }
        }
    });
    
    $('#productSelect').select2({
        placeholder: 'Search product...',
        allowClear: true,
        ajax: {
            url: 'edit-purchase.php?ajax=products',
            dataType: 'json',
            delay: 250,
            data: function(params) { return { term: params.term || '' }; },
            processResults: function(data) { return data; }
        }
    });
    
    // Set initial supplier selection
    <?php if (!empty($purchase_data['supplier_id'])): ?>
    {
        const supId = <?php echo (int)$purchase_data['supplier_id']; ?>;
        const supName = '<?php echo addslashes($purchase_data['supplier_name'] ?? ''); ?>';
        const supPhone = '<?php echo addslashes($purchase_data['phone'] ?? ''); ?>';
        const supGst = '<?php echo addslashes($purchase_data['gst_number'] ?? ''); ?>';
        const supBal = <?php echo (float)($purchase_data['opening_balance'] ?? 0); ?>;
        
        let text = supName;
        if (supPhone) text += ' • ' + supPhone;
        if (supGst) text += ' • ' + supGst;
        
        const option = new Option(text, supId, true, true);
        $('#supplierSelect').append(option).trigger('change');
        
        $('#supplierInfo').show();
        $('#supplierPhone').text(supPhone || '-');
        $('#supplierGST').text(supGst || '-');
        $('#supplierBalance').text('₹' + supBal.toFixed(2));
    }
    <?php endif; ?>
    
    // GST Type Toggle
    window.setGSTType = function(type) {
        gstType = type;
        $('#gstType').val(type);
        
        if (type === 'exclusive') {
            $('#gstExclusiveBtn').addClass('active');
            $('#gstInclusiveBtn').removeClass('active');
        } else {
            $('#gstInclusiveBtn').addClass('active');
            $('#gstExclusiveBtn').removeClass('active');
        }
        
        recalculateAllItems();
    };
    
    // Item Type Toggle
    function toggleItemType(type) {
        currentItemType = type;
        
        if (type === 'category') {
            $('#categoryFields').show();
            $('#productFields').hide();
            $('#item_type_category').prop('checked', true);
            
            selectedProduct = null;
            $('#productSelect').val(null).trigger('change');
            $('#productQuantityInput').prop('disabled', true).val('');
            $('#productTotalInput').prop('disabled', true).val('');
            $('#addProductItemBtn').prop('disabled', true);
            
            if (selectedCategory) {
                $('#kgInput').prop('disabled', false);
                $('#totalPriceInput').prop('disabled', false);
                checkAddButton();
            } else {
                $('#kgInput').prop('disabled', true);
                $('#totalPriceInput').prop('disabled', true);
            }
        } else {
            $('#categoryFields').hide();
            $('#productFields').show();
            $('#item_type_product').prop('checked', true);
            
            selectedCategory = null;
            $('#categorySelect').val(null).trigger('change');
            $('#conversionPreview').hide();
            $('#kgInput').prop('disabled', true).val('');
            $('#totalPriceInput').prop('disabled', true).val('');
            $('#addItemBtn').prop('disabled', true);
            
            if (selectedProduct) {
                $('#productQuantityInput').prop('disabled', false);
                $('#productTotalInput').prop('disabled', false);
                checkProductAddButton();
                updateProductUnitLabel();
            } else {
                $('#productQuantityInput').prop('disabled', true);
                $('#productTotalInput').prop('disabled', true);
            }
        }
    }
    
    function updateProductUnitLabel() {
        if (selectedProduct && selectedProduct.meta && selectedProduct.meta.primary_unit) {
            const unit = selectedProduct.meta.primary_unit;
            $('#productQuantityLabel').text(`Quantity (${unit})`);
            $('#productPriceLabel').text(`Total Price for ${unit}s (₹)`);
        }
    }
    
    $('#item_type_category').on('change', function() {
        if ($(this).is(':checked')) toggleItemType('category');
    });
    $('#item_type_product').on('change', function() {
        if ($(this).is(':checked')) toggleItemType('product');
    });
    
    // Category Selection
    $('#categorySelect').on('select2:select', function(e) {
        selectedCategory = e.params.data;
        const meta = selectedCategory.meta || {};
        
        $('#categoryMeta').html(`
            <span class="badge bg-info text-white me-2">
                <i class="bi bi-box"></i> Stock: ${meta.total_quantity || 0} pcs
            </span>
            <span class="badge bg-success text-white">
                <i class="bi bi-tag"></i> ₹${(meta.purchase_price || 0).toFixed(2)}/pc
            </span>
        `);
        
        if (currentItemType === 'category') {
            $('#kgInput').prop('disabled', false);
            $('#totalPriceInput').prop('disabled', false);
            checkAddButton();
            updateConversionPreview();
        }
    });
    
    $('#categorySelect').on('select2:clear', function() {
        selectedCategory = null;
        $('#categoryMeta').empty();
        if (currentItemType === 'category') {
            $('#kgInput').prop('disabled', true).val('');
            $('#totalPriceInput').prop('disabled', true).val('');
            $('#addItemBtn').prop('disabled', true);
            $('#conversionPreview').hide();
        }
    });
    
    // Product Selection
    function renderProductMeta() {
        if (!selectedProduct) return;
        const meta = selectedProduct.meta || {};
        const unit = meta.primary_unit || 'pcs';
        
        $('#productMeta').html(`
            <span class="badge bg-info text-white me-2">
                <i class="bi bi-tag"></i> Type: ${meta.product_type === 'direct' ? 'Direct Sale' : 'Converted Sale'}
            </span>
            <span class="product-unit-badge">
                <i class="bi bi-box"></i> Unit: ${unit}
            </span>
            <span class="badge bg-warning text-dark ms-2">
                Stock: ${(meta.stock_quantity || 0).toFixed(2)} ${unit}
            </span>
        `);
        updateProductUnitLabel();
    }
    
    $('#productSelect').on('select2:select', function(e) {
        selectedProduct = e.params.data;
        renderProductMeta();
        
        if (currentItemType === 'product') {
            $('#productQuantityInput').prop('disabled', false);
            $('#productTotalInput').prop('disabled', false);
            checkProductAddButton();
        }
    });
    
    $('#productSelect').on('select2:clear', function() {
        selectedProduct = null;
        $('#productMeta').empty();
        if (currentItemType === 'product') {
            $('#productQuantityInput').prop('disabled', true).val('');
            $('#productTotalInput').prop('disabled', true).val('');
            $('#addProductItemBtn').prop('disabled', true);
        }
        updateProductUnitLabel();
    });
    
    // Input handlers
    $('#kgInput, #totalPriceInput').on('input', function() {
        checkAddButton();
        updateConversionPreview();
    });
    
    $('#productQuantityInput, #productTotalInput').on('input', function() {
        checkProductAddButton();
    });
    
    function checkAddButton() {
        const kg = parseFloat($('#kgInput').val() || 0);
        const totalPrice = parseFloat($('#totalPriceInput').val() || 0);
        $('#addItemBtn').prop('disabled', !(selectedCategory && kg > 0 && totalPrice > 0));
    }
    
    function checkProductAddButton() {
        const qty = parseFloat($('#productQuantityInput').val() || 0);
        const totalPrice = parseFloat($('#productTotalInput').val() || 0);
        $('#addProductItemBtn').prop('disabled', !(selectedProduct && qty > 0 && totalPrice > 0));
    }
    
    function updateConversionPreview() {
        if (!selectedCategory?.meta) return;
        
        const meta = selectedCategory.meta;
        const kg = parseFloat($('#kgInput').val() || 0);
        const totalPrice = parseFloat($('#totalPriceInput').val() || 0);
        
        if (kg > 0 && totalPrice > 0 && meta.gram_value > 0) {
            const pcsPerKg = 1000 / meta.gram_value;
            const totalPcs = pcsPerKg * kg;
            const pricePerPc = totalPrice / totalPcs;
            
            $('#previewGram').text(meta.gram_value + ' g');
            $('#previewPcsPerKg').text(pcsPerKg.toFixed(2) + ' pcs');
            $('#previewPcs').text(totalPcs.toFixed(2) + ' pcs');
            $('#previewPricePerPc').text('₹' + pricePerPc.toFixed(2));
            $('#conversionPreview').show();
        } else {
            $('#conversionPreview').hide();
        }
    }
    
    // GST Calculation
    function calculateGST(baseOrTotal, cgstRate, sgstRate) {
        const totalGstPercent = cgstRate + sgstRate;
        
        if (gstType === 'inclusive') {
            const factor = 1 + (totalGstPercent / 100);
            const taxable = baseOrTotal / factor;
            const gstTotal = baseOrTotal - taxable;
            return {
                taxable: taxable,
                cgst_amt: gstTotal / 2,
                sgst_amt: gstTotal / 2,
                total: baseOrTotal
            };
        } else {
            const cgstAmt = (baseOrTotal * cgstRate) / 100;
            const sgstAmt = (baseOrTotal * sgstRate) / 100;
            return {
                taxable: baseOrTotal,
                cgst_amt: cgstAmt,
                sgst_amt: sgstAmt,
                total: baseOrTotal + cgstAmt + sgstAmt
            };
        }
    }
    
    // Add Category Item
    $('#addItemBtn').on('click', function() {
        if (!selectedCategory) return;
        
        const meta = selectedCategory.meta || {};
        const kg = parseFloat($('#kgInput').val() || 0);
        const totalPrice = parseFloat($('#totalPriceInput').val() || 0);
        
        if (kg <= 0 || totalPrice <= 0) return;
        
        const [cgst, sgst] = ($('#gstSelect').val() || '0,0').split(',').map(Number);
        const pcsPerKg = meta.gram_value > 0 ? 1000 / meta.gram_value : 0;
        const qty = pcsPerKg * kg;
        const pricePerPc = totalPrice / qty;
        const gstCalc = calculateGST(totalPrice, cgst, sgst);
        
        const newItem = {
            id: 'temp_' + Date.now(),
            item_type: 'category',
            cat_id: selectedCategory.id,
            product_id: null,
            cat_name: meta.category_name || selectedCategory.text,
            product_name: null,
            gram_value: meta.gram_value,
            hsn_code: '',
            cgst: cgst,
            sgst: sgst,
            cgst_amt: gstCalc.cgst_amt,
            sgst_amt: gstCalc.sgst_amt,
            taxable: gstCalc.taxable,
            total: gstCalc.total,
            kg_qty: kg,
            qty: qty,
            unit: 'pcs',
            sec_unit: 'kg',
            purchase_price: pricePerPc,
            price_per_kg: totalPrice / kg,
            _base_entered: totalPrice
        };
        
        items.push(newItem);
        renderItems();
        
        $('#kgInput').val('');
        $('#totalPriceInput').val('');
        $('#categorySelect').val(null).trigger('change');
        $('#conversionPreview').hide();
        $('#addItemBtn').prop('disabled', true);
    });
    
    // Add Product Item
    $('#addProductItemBtn').on('click', function() {
        if (!selectedProduct) return;
        
        const meta = selectedProduct.meta || {};
        const qty = parseFloat($('#productQuantityInput').val() || 0);
        const totalPrice = parseFloat($('#productTotalInput').val() || 0);
        
        if (qty <= 0 || totalPrice <= 0) return;
        
        const [cgst, sgst] = ($('#gstSelect').val() || '0,0').split(',').map(Number);
        const pricePerUnit = totalPrice / qty;
        const unit = meta.primary_unit || 'pcs';
        const gstCalc = calculateGST(totalPrice, cgst, sgst);
        
        const newItem = {
            id: 'temp_' + Date.now(),
            item_type: 'product',
            cat_id: null,
            product_id: selectedProduct.id,
            cat_name: null,
            product_name: meta.product_name || selectedProduct.text,
            gram_value: 0,
            hsn_code: meta.hsn_code || '',
            cgst: cgst,
            sgst: sgst,
            cgst_amt: gstCalc.cgst_amt,
            sgst_amt: gstCalc.sgst_amt,
            taxable: gstCalc.taxable,
            total: gstCalc.total,
            kg_qty: 0,
            qty: qty,
            unit: unit,
            sec_unit: '',
            purchase_price: pricePerUnit,
            price_per_kg: 0,
            _base_entered: totalPrice
        };
        
        items.push(newItem);
        renderItems();
        
        $('#productQuantityInput').val('');
        $('#productTotalInput').val('');
        $('#productSelect').val(null).trigger('change');
        $('#addProductItemBtn').prop('disabled', true);
    });
    
    // Render Items
    function renderItems() {
        const tbody = $('#itemsBody');
        tbody.empty();
        
        let totalTaxable = 0;
        let totalGST = 0;
        let totalAmount = 0;
        
        if (items.length === 0) {
            tbody.html('<tr><td colspan="11" class="text-center py-4 text-muted">No items added yet</td></tr>');
        } else {
            items.forEach((item, idx) => {
                totalTaxable += Number(item.taxable || 0);
                totalGST += Number(item.cgst_amt || 0) + Number(item.sgst_amt || 0);
                totalAmount += Number(item.total || 0);
                
                const typeBadge = item.item_type === 'category' 
                    ? '<span class="category-badge"><i class="bi bi-layers"></i> Category</span>'
                    : '<span class="product-badge"><i class="bi bi-box"></i> Product</span>';
                const itemName = item.item_type === 'category' ? item.cat_name : item.product_name;
                const details = item.item_type === 'category' 
                    ? `${item.gram_value} g/pc • ${item.kg_qty} kg = ${Math.round(item.qty)} pcs`
                    : `Unit: ${item.unit}`;
                const gstPercent = (item.cgst + item.sgst).toFixed(2);
                
                const row = `
                    <tr data-item-index="${idx}">
                        <td>${idx + 1}</td>
                        <td>${typeBadge}</td>
                        <td class="fw-semibold">${escapeHtml(itemName)}</td>
                        <td class="text-muted small">${escapeHtml(details)}</td>
                        <td class="text-end">${Number(item.qty).toFixed(2)}</td>
                        <td class="text-end">${escapeHtml(item.unit)}</td>
                        <td class="text-end">₹${Number(item.purchase_price).toFixed(2)}</td>
                        <td class="text-end">₹${Number(item.taxable).toFixed(2)}</td>
                        <td class="text-end">
                            ${gstPercent}%<br>
                            <small>₹${Number(item.cgst_amt + item.sgst_amt).toFixed(2)}</small>
                        </td>
                        <td class="text-end fw-bold">₹${Number(item.total).toFixed(2)}</td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${idx})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
                tbody.append(row);
            });
        }
        
        $('#totalPrice').text('₹' + totalTaxable.toFixed(2));
        $('#totalGST').text('₹' + totalGST.toFixed(2));
        $('#totalAmount').text('₹' + totalAmount.toFixed(2));
        $('#totalPurchaseDisplay').text('₹' + totalTaxable.toFixed(2));
        $('#totalGSTDisplay').text('₹' + totalGST.toFixed(2));
        $('#grandTotalDisplay').text('₹' + totalAmount.toFixed(2));
        $('#itemCount').text(items.length + ' items');
        $('#items_json').val(JSON.stringify(items));
        
        updatePaymentTotals();
    }
    
    function recalculateAllItems() {
        if (items.length === 0) return;
        
        items = items.map(it => {
            const cgst = parseFloat(it.cgst || 0);
            const sgst = parseFloat(it.sgst || 0);
            let baseEntered = parseFloat(it._base_entered || 0);
            
            if (!(baseEntered > 0)) {
                baseEntered = (gstType === 'inclusive')
                    ? parseFloat(it.total || 0)
                    : parseFloat(it.taxable || 0);
            }
            
            const gstResult = calculateGST(baseEntered, cgst, sgst);
            const totalQty = parseFloat(it.qty || 0);
            const new_price_per_unit = totalQty > 0 ? gstResult.total / totalQty : 0;
            
            return {
                ...it,
                _base_entered: baseEntered,
                taxable: gstResult.taxable,
                cgst_amt: gstResult.cgst_amt,
                sgst_amt: gstResult.sgst_amt,
                total: gstResult.total,
                purchase_price: new_price_per_unit
            };
        });
        
        renderItems();
    }
    
    window.removeItem = function(index) {
        Swal.fire({
            title: 'Remove Item?',
            text: 'Are you sure you want to remove this item?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, remove it!'
        }).then((result) => {
            if (result.isConfirmed) {
                items.splice(index, 1);
                renderItems();
                Swal.fire('Removed!', 'Item has been removed.', 'success');
            }
        });
    };
    
    // Payment Functions
    window.addPayment = function() {
        const pid = nextPaymentId++;
        const html = `
            <div class="payment-card" id="payment-${pid}">
                <span class="remove-payment" onclick="removePayment(${pid})">
                    <i class="bi bi-x-lg"></i>
                </span>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Amount (₹)</label>
                        <input type="number" class="form-control form-control-sm payment-amount" 
                               name="payments[${pid}][amount]" step="0.01" min="0"
                               onchange="updatePaymentTotals()" onkeyup="updatePaymentTotals()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Method</label>
                        <select class="form-select form-select-sm" name="payments[${pid}][method]">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="upi">UPI</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Notes</label>
                        <input type="text" class="form-control form-control-sm" 
                               name="payments[${pid}][notes]" placeholder="Optional">
                    </div>
                </div>
            </div>`;
        $('#paymentsContainer').append(html);
        updatePaymentTotals();
    };
    
    window.removePayment = function(id) {
        Swal.fire({
            title: 'Remove Payment?',
            text: 'Are you sure you want to remove this payment?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, remove it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $(`#payment-${id}`).remove();
                updatePaymentTotals();
            }
        });
    };
    
    function updatePaymentTotals() {
        let totalPaid = 0;
        $('input[name$="[amount]"]').each(function() {
            const val = parseFloat($(this).val());
            if (!isNaN(val) && val > 0) totalPaid += val;
        });
        
        const grandTotal = parseFloat($('#totalAmount').text().replace('₹', '') || 0);
        const balance = grandTotal - totalPaid;
        
        $('#totalPaidDisplay').text('₹' + totalPaid.toFixed(2));
        $('#balanceDueDisplay').text('₹' + balance.toFixed(2));
    }
    
    // Form Submit
    $('#purchaseForm').on('submit', function(e) {
        if (items.length === 0) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'No Items', text: 'Please add at least one item to the purchase.' });
            return false;
        }
        
        if (!$('#supplierSelect').val()) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'No Supplier', text: 'Please select a supplier.' });
            return false;
        }
        
        $('#submitBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Updating...');
    });
    
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    
    // Initialize
    toggleItemType('category');
    setGSTType(gstType);
    renderItems();
    updatePaymentTotals();
})();
</script>
</body>
</html>