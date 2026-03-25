<?php
// edit_invoice.php
session_start();
$currentPage = 'edit-invoice';
$pageTitle   = 'Edit Invoice';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can edit invoices
checkRoleAccess(['admin']);

$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($edit_id <= 0) {
    header("Location: invoices.php");
    exit;
}

// Fetch invoice details
$inv_query = mysqli_query($conn, "SELECT * FROM invoice WHERE id = $edit_id");
$invoice = mysqli_fetch_assoc($inv_query);

if (!$invoice) {
    header("Location: invoices.php");
    exit;
}

// Fetch invoice items (raw from DB)
$items_query = mysqli_query($conn, "SELECT * FROM invoice_item WHERE invoice_id = $edit_id");
$items_db = [];
while ($row = mysqli_fetch_assoc($items_query)) {
    $items_db[] = $row;
}

// ✅ Normalize DB items to match JS structure (IMPORTANT FIX)
$items = [];
foreach ($items_db as $it) {
    $items[] = [
        'product_id'    => (int)($it['product_id'] ?? 0),
        'product_name'  => $it['product_name'] ?? '',
        'cat_id'        => (int)($it['cat_id'] ?? 0),
        'cat_name'      => $it['cat_name'] ?? '',
        'unit'          => $it['unit'] ?? '',
        'qty'           => (float)($it['quantity'] ?? 0),
        'pcs_per_bag'   => 0, // not stored in invoice_item
        'converted_qty' => (float)($it['no_of_pcs'] ?? 0),
        'no_of_pcs'     => (float)($it['no_of_pcs'] ?? 0),
        'rate'          => (float)($it['selling_price'] ?? 0),
        'total'         => (float)($it['total'] ?? 0),
        'taxable'       => (float)($it['taxable'] ?? 0),
        'hsn_code'      => $it['hsn'] ?? '',
        'cgst'          => (float)($it['cgst'] ?? 0),
        'sgst'          => (float)($it['sgst'] ?? 0),
        'cgst_amt'      => (float)($it['cgst_amount'] ?? 0),
        'sgst_amt'      => (float)($it['sgst_amount'] ?? 0),
    ];
}

// Fetch customers for dropdown
$customers = mysqli_query($conn, "SELECT id, customer_name, phone, email, address, gst_number FROM customers ORDER BY customer_name ASC");

// Fetch products for adding items
$products = mysqli_query($conn, "
    SELECT p.id, p.product_name, p.hsn_code, p.primary_unit, p.sec_unit, p.sec_qty,
           COALESCE(g.cgst,0) AS cgst, COALESCE(g.sgst,0) AS sgst
    FROM product p
    LEFT JOIN gst g ON p.hsn_code = g.hsn AND g.status = 1
    ORDER BY p.product_name ASC
");

// Fetch categories
$categories = mysqli_query($conn, "SELECT id, category_name, purchase_price, total_quantity FROM category ORDER BY category_name ASC");

// Handle form submission
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['action']) && $_POST['action'] === 'update_invoice') {
        // Get form data
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $overall_discount = (float)($_POST['overall_discount'] ?? 0);
        $overall_discount_type = $_POST['overall_discount_type'] ?? 'amount';

        // Payment fields
        $cash_amount = (float)($_POST['cash_amount'] ?? 0);
        $upi_amount = (float)($_POST['upi_amount'] ?? 0);
        $card_amount = (float)($_POST['card_amount'] ?? 0);
        $bank_amount = (float)($_POST['bank_amount'] ?? 0);
        $cheque_amount = (float)($_POST['cheque_amount'] ?? 0);
        $credit_amount = (float)($_POST['credit_amount'] ?? 0);

        $cheque_number = escape($conn, trim($_POST['cheque_number'] ?? ''));
        $cheque_date = escape($conn, trim($_POST['cheque_date'] ?? ''));
        $cheque_bank = escape($conn, trim($_POST['cheque_bank'] ?? ''));
        $credit_due_date = escape($conn, trim($_POST['credit_due_date'] ?? ''));
        $credit_notes = escape($conn, trim($_POST['credit_notes'] ?? ''));

        // Shipping fields
        $shipping_address = escape($conn, trim($_POST['shipping_address'] ?? ''));
        $shipping_charges = (float)($_POST['shipping_charges'] ?? 0);
        $shipping_method = escape($conn, trim($_POST['shipping_method'] ?? ''));
        $delivery_date = escape($conn, trim($_POST['delivery_date'] ?? ''));
        $delivery_time = escape($conn, trim($_POST['delivery_time'] ?? ''));
        $tracking_number = escape($conn, trim($_POST['tracking_number'] ?? ''));

        // Items from hidden field
        $items_json = $_POST['items_json'] ?? '[]';
        $items_data = json_decode($items_json, true);

        if (!is_array($items_data) || count($items_data) === 0) {
            $error = "No items to save.";
        }

        if ($error === '') {
            // Calculate totals
            $subtotal = 0;
            $taxable_total = 0;
            $cgst_total = 0;
            $sgst_total = 0;

            foreach ($items_data as $item) {
                $subtotal += (float)($item['taxable'] ?? 0);
                $taxable_total += (float)($item['taxable'] ?? 0);
                $cgst_total += (float)($item['cgst_amt'] ?? 0);
                $sgst_total += (float)($item['sgst_amt'] ?? 0);
            }

            // Apply overall discount
            $overall_disc_amt = 0;
            if ($overall_discount > 0) {
                if ($overall_discount_type === 'percentage') {
                    $overall_disc_amt = ($subtotal * $overall_discount) / 100;
                } else {
                    $overall_disc_amt = $overall_discount;
                }
            }

            $net_after_discount = $subtotal - $overall_disc_amt;
            $factor = ($subtotal > 0) ? ($net_after_discount / $subtotal) : 1;

            $taxable_final = $taxable_total * $factor;
            $cgst_final = $cgst_total * $factor;
            $sgst_final = $sgst_total * $factor;
            $grand_total = $taxable_final + $cgst_final + $sgst_final + $shipping_charges;

            // Payment calculations
            $total_received = $cash_amount + $upi_amount + $card_amount + $bank_amount + $cheque_amount + $credit_amount;

            // Determine payment method
            $payment_parts = 0;
            foreach ([$cash_amount, $upi_amount, $card_amount, $bank_amount, $cheque_amount, $credit_amount] as $amt) {
                if ($amt > 0) $payment_parts++;
            }

            if ($payment_parts <= 1) {
                if ($cash_amount > 0) $payment_method = 'cash';
                elseif ($upi_amount > 0) $payment_method = 'upi';
                elseif ($card_amount > 0) $payment_method = 'card';
                elseif ($bank_amount > 0) $payment_method = 'bank';
                elseif ($cheque_amount > 0) $payment_method = 'cheque';
                elseif ($credit_amount > 0) $payment_method = 'credit';
                else $payment_method = 'credit';
            } else {
                $payment_method = 'mixed';
            }

            $pending_amount = ($total_received >= $grand_total) ? 0 : ($grand_total - $total_received);
            $change_give = ($total_received > $grand_total) ? ($total_received - $grand_total) : 0;

            // Format dates for SQL
            if ($cheque_date === '') $cheque_date = 'NULL';
            else $cheque_date = "'$cheque_date'";

            if ($credit_due_date === '') $credit_due_date = 'NULL';
            else $credit_due_date = "'$credit_due_date'";

            if ($delivery_date === '') $delivery_date = 'NULL';
            else $delivery_date = "'$delivery_date'";

            if ($delivery_time === '') $delivery_time = 'NULL';
            else $delivery_time = "'$delivery_time'";

            // Begin transaction
            mysqli_begin_transaction($conn);

            try {
                // Update invoice
                $update = mysqli_query($conn, "
                    UPDATE invoice SET
                        customer_id = $customer_id,
                        subtotal = $subtotal,
                        overall_discount = $overall_disc_amt,
                        overall_discount_type = '$overall_discount_type',
                        total = $grand_total,
                        taxable = $taxable_final,
                        cgst_amount = $cgst_final,
                        sgst_amount = $sgst_final,
                        cash_received = $total_received,
                        change_give = $change_give,
                        pending_amount = $pending_amount,
                        payment_method = '$payment_method',
                        cash_amount = $cash_amount,
                        upi_amount = $upi_amount,
                        card_amount = $card_amount,
                        bank_amount = $bank_amount,
                        cheque_amount = $cheque_amount,
                        credit_amount = $credit_amount,
                        cheque_number = '$cheque_number',
                        cheque_date = $cheque_date,
                        cheque_bank = '$cheque_bank',
                        credit_due_date = $credit_due_date,
                        credit_notes = '$credit_notes',
                        shipping_address = '$shipping_address',
                        shipping_charges = $shipping_charges,
                        shipping_method = '$shipping_method',
                        delivery_date = $delivery_date,
                        delivery_time = $delivery_time,
                        tracking_number = '$tracking_number'
                    WHERE id = $edit_id
                ");

                if (!$update) {
                    throw new Exception("Failed to update invoice: " . mysqli_error($conn));
                }

                // Delete old items
                mysqli_query($conn, "DELETE FROM invoice_item WHERE invoice_id = $edit_id");

                // Insert new items (from JS structure)
                foreach ($items_data as $item) {
                    $product_id = (int)($item['product_id'] ?? 0);
                    $product_name = escape($conn, $item['product_name'] ?? '');
                    $cat_id = (int)($item['cat_id'] ?? 0);
                    $cat_name = escape($conn, $item['cat_name'] ?? '');
                    $qty = (float)($item['qty'] ?? 0);
                    $unit = escape($conn, $item['unit'] ?? '');
                    $no_of_pcs = (float)($item['converted_qty'] ?? 0);
                    $rate = (float)($item['rate'] ?? 0);

                    $total = (float)($item['total'] ?? 0) * $factor;
                    $hsn = escape($conn, $item['hsn_code'] ?? '');
                    $taxable = (float)($item['taxable'] ?? 0) * $factor;
                    $cgst = (float)($item['cgst'] ?? 0);
                    $cgst_amt = (float)($item['cgst_amt'] ?? 0) * $factor;
                    $sgst = (float)($item['sgst'] ?? 0);
                    $sgst_amt = (float)($item['sgst_amt'] ?? 0) * $factor;

                    $insert = mysqli_query($conn, "
                        INSERT INTO invoice_item
                        (
                            invoice_id, product_id, product_name, cat_id, cat_name,
                            quantity, unit, no_of_pcs, selling_price, total, hsn,
                            taxable, cgst, cgst_amount, sgst, sgst_amount
                        )
                        VALUES
                        (
                            $edit_id, $product_id, '$product_name', $cat_id, '$cat_name',
                            $qty, '$unit', $no_of_pcs, $rate, $total, '$hsn',
                            $taxable, $cgst, $cgst_amt, $sgst, $sgst_amt
                        )
                    ");

                    if (!$insert) {
                        throw new Exception("Failed to save item: " . mysqli_error($conn));
                    }
                }

                // Log activity
                $log_desc = "Updated invoice {$invoice['inv_num']} (Total: ₹" . number_format($grand_total, 2) . ")";
                mysqli_query($conn, "INSERT INTO activity_log (user_id, action, description) VALUES ({$_SESSION['user_id']}, 'update', '$log_desc')");

                mysqli_commit($conn);
                $success = "Invoice updated successfully.";

                // Refresh invoice + items
                $inv_query = mysqli_query($conn, "SELECT * FROM invoice WHERE id = $edit_id");
                $invoice = mysqli_fetch_assoc($inv_query);

                $items_query = mysqli_query($conn, "SELECT * FROM invoice_item WHERE invoice_id = $edit_id");
                $items_db = [];
                while ($row = mysqli_fetch_assoc($items_query)) $items_db[] = $row;

                $items = [];
                foreach ($items_db as $it) {
                    $items[] = [
                        'product_id'    => (int)($it['product_id'] ?? 0),
                        'product_name'  => $it['product_name'] ?? '',
                        'cat_id'        => (int)($it['cat_id'] ?? 0),
                        'cat_name'      => $it['cat_name'] ?? '',
                        'unit'          => $it['unit'] ?? '',
                        'qty'           => (float)($it['quantity'] ?? 0),
                        'pcs_per_bag'   => 0,
                        'converted_qty' => (float)($it['no_of_pcs'] ?? 0),
                        'no_of_pcs'     => (float)($it['no_of_pcs'] ?? 0),
                        'rate'          => (float)($it['selling_price'] ?? 0),
                        'total'         => (float)($it['total'] ?? 0),
                        'taxable'       => (float)($it['taxable'] ?? 0),
                        'hsn_code'      => $it['hsn'] ?? '',
                        'cgst'          => (float)($it['cgst'] ?? 0),
                        'sgst'          => (float)($it['sgst'] ?? 0),
                        'cgst_amt'      => (float)($it['cgst_amount'] ?? 0),
                        'sgst_amt'      => (float)($it['sgst_amount'] ?? 0),
                    ];
                }

            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
            }
        }
    }
}

// Helper functions
function escape($conn, $str) {
    return mysqli_real_escape_string($conn, $str);
}
function formatMoney($amount) {
    return '&#8377;' . number_format((float)$amount, 2);
}
function getPaymentMethodIcon($method) {
    $icons = [
        'cash' => 'cash',
        'card' => 'credit-card',
        'upi' => 'phone',
        'bank' => 'bank',
        'cheque' => 'journal-check',
        'credit' => 'journal-bookmark-fill',
        'mixed' => 'shuffle'
    ];
    return $icons[$method] ?? 'wallet2';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',-apple-system,sans-serif;background:#f0f4f8;color:#1e293b;font-size:13px;}
        .page-container{padding:20px;max-width:1400px;margin:0 auto;}
        .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:15px;}
        .page-header h1{font-size:24px;font-weight:700;color:#0f172a;margin:0;}
        .page-header .invoice-badge{background:#fef3c7;color:#92400e;padding:6px 12px;border-radius:30px;font-size:13px;font-weight:600;}
        .nav-buttons{display:flex;gap:10px;}
        .btn-nav{padding:8px 16px;border-radius:30px;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:8px;text-decoration:none;transition:all .2s;}
        .btn-nav-back{background:white;color:#475569;border:1px solid #e2e8f0;}
        .btn-nav-close{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
        .btn-nav-print{background:#2563eb;color:white;border:1px solid #1e4fbd;}
        .card{background:white;border-radius:16px;padding:20px;margin-bottom:20px;box-shadow:0 4px 12px rgba(0,0,0,.05);border:1px solid #eef2f6;}
        .card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:15px;padding-bottom:10px;border-bottom:1px solid #eef2f6;}
        .card-header h5{font-size:15px;font-weight:700;margin:0;color:#0f172a;}
        .card-header .badge{background:#e6f0ff;color:#2563eb;padding:4px 10px;border-radius:30px;font-size:11px;font-weight:600;}
        .form-label{font-weight:600;font-size:12px;color:#475569;margin-bottom:5px;}
        .form-control,.form-select{border:1px solid #dbe3eb;border-radius:10px;padding:8px 12px;font-size:13px;min-height:40px;}
        .form-control:focus,.form-select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none;}
        .select2-container--default .select2-selection--single{border:1px solid #dbe3eb !important;border-radius:10px !important;height:40px !important;padding:5px 12px !important;}
        .info-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:15px;}
        .info-label{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;}
        .info-value{font-weight:600;font-size:15px;color:#0f172a;}
        .table{width:100%;border-collapse:collapse;font-size:12px;}
        .table th{background:#f8fafc;font-weight:700;color:#475569;padding:12px 8px;border-bottom:1px solid #e2e8f0;}
        .table td{padding:10px 8px;border-bottom:1px solid #eef2f6;vertical-align:middle;}
        .payment-method-badge{background:#f1f5f9;padding:4px 10px;border-radius:20px;font-size:11px;display:inline-flex;align-items:center;gap:5px;}
        .total-box{background:#f8fafc;border-radius:12px;padding:15px;margin-top:15px;}
        .total-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed #e2e8f0;}
        .total-row:last-child{border-bottom:none;font-weight:700;font-size:16px;color:#0f172a;}
        .action-buttons{display:flex;justify-content:flex-end;gap:12px;margin-top:20px;}
        .btn-primary{background:#2563eb;color:white;padding:10px 24px;border-radius:10px;font-weight:600;border:none;}
        .btn-primary:hover{background:#1e4fbd;}
        .btn-secondary{background:white;color:#475569;padding:10px 24px;border-radius:10px;font-weight:600;border:1px solid #e2e8f0;}
        .btn-secondary:hover{background:#f8fafc;}
        .alert{border-radius:12px;padding:12px 16px;margin-bottom:20px;}
        .alert-success{background:#d1fae5;color:#065f46;}
        .alert-danger{background:#fee2e2;color:#991b1b;}
        .btn-remove-item{background:#ef4444;color:white;padding:4px 8px;border-radius:6px;font-size:11px;border:none;}
        .btn-remove-item:hover{background:#dc2626;}
        .item-add-section{background:#f8fafc;border-radius:12px;padding:15px;margin-top:15px;border:1px solid #e2e8f0;}
        @media (max-width:768px){.page-container{padding:10px}.action-buttons{flex-direction:column}.btn-primary,.btn-secondary{width:100%}}
        .unit-badge{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:10px;}
    </style>
</head>
<body>
<div class="page-container">

    <div class="page-header">
        <div>
            <h1>Edit Invoice #<?php echo htmlspecialchars($invoice['inv_num']); ?></h1>
            <span class="invoice-badge">
                <i class="bi bi-pencil-fill"></i> Editing Mode
            </span>
        </div>
        <div class="nav-buttons">
            <a href="print_invoice.php?id=<?php echo $edit_id; ?>" target="_blank" class="btn-nav btn-nav-print">
                <i class="bi bi-printer"></i> Print
            </a>
            <a href="invoices.php" class="btn-nav btn-nav-back">
                <i class="bi bi-arrow-left"></i> Back to Invoices
            </a>
            <a href="invoices.php" class="btn-nav btn-nav-close">
                <i class="bi bi-x-lg"></i> Close
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="edit_invoice.php?id=<?php echo $edit_id; ?>" id="editForm">
        <input type="hidden" name="action" value="update_invoice">
        <!-- ✅ items_json now contains normalized items -->
        <input type="hidden" name="items_json" id="items_json" value='<?php echo json_encode($items); ?>'>

        <!-- Customer Information -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-person me-2"></i>Customer Information</h5>
                <span class="badge">Required</span>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Select Customer</label>
                    <select class="form-select" name="customer_id" id="customerSelect" required>
                        <option value="">-- Select Customer --</option>
                        <?php while ($cust = mysqli_fetch_assoc($customers)): ?>
                            <option value="<?php echo $cust['id']; ?>"
                                <?php echo ($cust['id'] == $invoice['customer_id']) ? 'selected' : ''; ?>
                                data-phone="<?php echo htmlspecialchars($cust['phone'] ?? ''); ?>"
                                data-email="<?php echo htmlspecialchars($cust['email'] ?? ''); ?>"
                                data-gst="<?php echo htmlspecialchars($cust['gst_number'] ?? ''); ?>"
                                data-address="<?php echo htmlspecialchars($cust['address'] ?? ''); ?>">
                                <?php echo htmlspecialchars($cust['customer_name']); ?>
                                <?php if (!empty($cust['phone'])): ?> - <?php echo htmlspecialchars($cust['phone']); ?><?php endif; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <div class="info-box">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-label">Phone</div>
                                <div class="info-value" id="displayPhone"><?php echo htmlspecialchars($invoice['customer_phone'] ?? '-'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Email</div>
                                <div class="info-value" id="displayEmail"><?php echo htmlspecialchars($invoice['customer_email'] ?? '-'); ?></div>
                            </div>
                            <div class="col-md-6 mt-2">
                                <div class="info-label">GST Number</div>
                                <div class="info-value" id="displayGst"><?php echo htmlspecialchars($invoice['customer_gst'] ?? '-'); ?></div>
                            </div>
                            <div class="col-md-6 mt-2">
                                <div class="info-label">Address</div>
                                <div class="info-value" id="displayAddress"><?php echo htmlspecialchars($invoice['customer_address'] ?? '-'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shipping Details -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-truck me-2"></i>Shipping Details</h5>
                <span class="badge">Optional</span>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Shipping Address</label>
                    <textarea class="form-control" name="shipping_address" rows="2"><?php echo htmlspecialchars($invoice['shipping_address'] ?? ''); ?></textarea>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Shipping Charges</label>
                    <input type="number" class="form-control" name="shipping_charges" value="<?php echo $invoice['shipping_charges'] ?? 0; ?>" step="0.01" min="0" id="shippingCharges">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Shipping Method</label>
                    <select class="form-select" name="shipping_method">
                        <option value="">Select</option>
                        <option value="Standard" <?php echo (($invoice['shipping_method'] ?? '') == 'Standard') ? 'selected' : ''; ?>>Standard</option>
                        <option value="Express" <?php echo (($invoice['shipping_method'] ?? '') == 'Express') ? 'selected' : ''; ?>>Express</option>
                        <option value="Same Day" <?php echo (($invoice['shipping_method'] ?? '') == 'Same Day') ? 'selected' : ''; ?>>Same Day</option>
                        <option value="Courier" <?php echo (($invoice['shipping_method'] ?? '') == 'Courier') ? 'selected' : ''; ?>>Courier</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Delivery Date</label>
                    <input type="date" class="form-control" name="delivery_date" value="<?php echo htmlspecialchars($invoice['delivery_date'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tracking Number</label>
                    <input type="text" class="form-control" name="tracking_number" value="<?php echo htmlspecialchars($invoice['tracking_number'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Add New Item -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-plus-circle me-2"></i>Add New Item</h5>
            </div>
            <div class="item-add-section">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Product</label>
                        <select class="form-select" id="addProductSelect">
                            <option value="">Select Product</option>
                            <?php
                            mysqli_data_seek($products, 0);
                            while ($prod = mysqli_fetch_assoc($products)):
                            ?>
                                <option value="<?php echo $prod['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($prod['product_name']); ?>"
                                    data-hsn="<?php echo htmlspecialchars($prod['hsn_code'] ?? ''); ?>"
                                    data-primary-unit="<?php echo htmlspecialchars($prod['primary_unit'] ?? ''); ?>"
                                    data-sec-unit="<?php echo htmlspecialchars($prod['sec_unit'] ?? ''); ?>"
                                    data-sec-qty="<?php echo $prod['sec_qty'] ?? 0; ?>"
                                    data-cgst="<?php echo $prod['cgst'] ?? 0; ?>"
                                    data-sgst="<?php echo $prod['sgst'] ?? 0; ?>">
                                    <?php echo htmlspecialchars($prod['product_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" id="addCategorySelect">
                            <option value="">Select Category</option>
                            <?php
                            mysqli_data_seek($categories, 0);
                            while ($cat = mysqli_fetch_assoc($categories)):
                            ?>
                                <option value="<?php echo $cat['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($cat['category_name']); ?>"
                                    data-rate="<?php echo $cat['purchase_price']; ?>">
                                    <?php echo htmlspecialchars($cat['category_name']); ?> (₹<?php echo $cat['purchase_price']; ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Unit</label>
                        <select class="form-select" id="addUnitSelect" disabled>
                            <option value="">Select Unit</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Pcs/Bag</label>
                        <input type="number" class="form-control" id="addPcsPerBag" step="0.001" min="0" disabled>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Qty</label>
                        <input type="number" class="form-control" id="addQty" step="0.001" min="0" disabled>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Rate</label>
                        <input type="number" class="form-control" id="addRate" step="0.01" min="0" disabled>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <button type="button" class="btn btn-primary w-100" id="addItemBtn" disabled>Add</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Items (✅ now JS renders only) -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-list-check me-2"></i>Invoice Items</h5>
                <span class="badge">Items: <span id="itemCount"><?php echo count($items); ?></span></span>
            </div>

            <div class="table-responsive">
                <table class="table" id="itemsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Pieces</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Taxable</th>
                            <th class="text-end">CGST</th>
                            <th class="text-end">SGST</th>
                            <th class="text-end">Total</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody"></tbody>
                </table>
            </div>
        </div>

        <!-- Discount & Payment -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-calculator me-2"></i>Discount & Payment</h5>
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Overall Discount</label>
                    <input type="number" class="form-control" name="overall_discount" value="<?php echo $invoice['overall_discount'] ?? 0; ?>" step="0.01" min="0" id="overallDiscount">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Discount Type</label>
                    <select class="form-select" name="overall_discount_type" id="discountType">
                        <option value="amount" <?php echo (($invoice['overall_discount_type'] ?? 'amount') == 'amount') ? 'selected' : ''; ?>>Amount (₹)</option>
                        <option value="percentage" <?php echo (($invoice['overall_discount_type'] ?? '') == 'percentage') ? 'selected' : ''; ?>>Percentage (%)</option>
                    </select>
                </div>
            </div>

            <div class="row g-3 mt-2">
                <div class="col-md-2">
                    <label class="form-label">Cash</label>
                    <input type="number" class="form-control payment-input" name="cash_amount" value="<?php echo $invoice['cash_amount'] ?? 0; ?>" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label">UPI</label>
                    <input type="number" class="form-control payment-input" name="upi_amount" value="<?php echo $invoice['upi_amount'] ?? 0; ?>" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Card</label>
                    <input type="number" class="form-control payment-input" name="card_amount" value="<?php echo $invoice['card_amount'] ?? 0; ?>" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bank</label>
                    <input type="number" class="form-control payment-input" name="bank_amount" value="<?php echo $invoice['bank_amount'] ?? 0; ?>" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cheque</label>
                    <input type="number" class="form-control payment-input" name="cheque_amount" value="<?php echo $invoice['cheque_amount'] ?? 0; ?>" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Credit</label>
                    <input type="number" class="form-control payment-input" name="credit_amount" value="<?php echo $invoice['credit_amount'] ?? 0; ?>" step="0.01" min="0">
                </div>
            </div>

            <div class="row g-3 mt-2" id="chequeDetails" style="display: <?php echo (($invoice['cheque_amount'] ?? 0) > 0) ? 'flex' : 'none'; ?>;">
                <div class="col-md-3">
                    <label class="form-label">Cheque Number</label>
                    <input type="text" class="form-control" name="cheque_number" value="<?php echo htmlspecialchars($invoice['cheque_number'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cheque Date</label>
                    <input type="date" class="form-control" name="cheque_date" value="<?php echo htmlspecialchars($invoice['cheque_date'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cheque Bank</label>
                    <input type="text" class="form-control" name="cheque_bank" value="<?php echo htmlspecialchars($invoice['cheque_bank'] ?? ''); ?>">
                </div>
            </div>

            <div class="row g-3 mt-2" id="creditDetails" style="display: <?php echo (($invoice['credit_amount'] ?? 0) > 0) ? 'flex' : 'none'; ?>;">
                <div class="col-md-3">
                    <label class="form-label">Credit Due Date</label>
                    <input type="date" class="form-control" name="credit_due_date" value="<?php echo htmlspecialchars($invoice['credit_due_date'] ?? ''); ?>">
                </div>
                <div class="col-md-9">
                    <label class="form-label">Credit Notes</label>
                    <input type="text" class="form-control" name="credit_notes" value="<?php echo htmlspecialchars($invoice['credit_notes'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Summary -->
        <div class="card">
            <div class="total-box">
                <div class="total-row">
                    <span>Subtotal (Taxable):</span>
                    <span id="subtotal"><?php echo formatMoney($invoice['subtotal'] ?? 0); ?></span>
                </div>
                <div class="total-row">
                    <span>Discount:</span>
                    <span id="discountAmount" class="text-danger">-<?php echo formatMoney($invoice['overall_discount'] ?? 0); ?></span>
                </div>
                <div class="total-row">
                    <span>CGST:</span>
                    <span id="cgstTotal"><?php echo formatMoney($invoice['cgst_amount'] ?? 0); ?></span>
                </div>
                <div class="total-row">
                    <span>SGST:</span>
                    <span id="sgstTotal"><?php echo formatMoney($invoice['sgst_amount'] ?? 0); ?></span>
                </div>
                <div class="total-row">
                    <span>Shipping:</span>
                    <span id="shippingTotal"><?php echo formatMoney($invoice['shipping_charges'] ?? 0); ?></span>
                </div>
                <div class="total-row">
                    <span>Grand Total:</span>
                    <span class="fw-bold" id="grandTotal"><?php echo formatMoney($invoice['total'] ?? 0); ?></span>
                </div>
                <div class="total-row mt-3">
                    <span>Paid:</span>
                    <span class="text-success" id="paidAmount"><?php echo formatMoney($invoice['cash_received'] ?? 0); ?></span>
                </div>
                <div class="total-row">
                    <span>Pending:</span>
                    <span class="text-danger" id="pendingAmount"><?php echo formatMoney($invoice['pending_amount'] ?? 0); ?></span>
                </div>
                <div class="total-row">
                    <span>Payment Method:</span>
                    <span class="payment-method-badge" id="paymentMethodDisplay">
                        <i class="bi bi-<?php echo getPaymentMethodIcon($invoice['payment_method'] ?? 'cash'); ?>"></i>
                        <?php echo ucfirst($invoice['payment_method'] ?? 'cash'); ?>
                    </span>
                </div>
            </div>

            <div class="action-buttons">
                <button type="submit" class="btn-primary">
                    <i class="bi bi-check-circle me-2"></i> Update Invoice
                </button>
                <a href="invoices.php" class="btn-secondary">
                    <i class="bi bi-x-circle me-2"></i> Cancel
                </a>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// ✅ Global items array (now matches expected keys)
let items = <?php echo json_encode($items); ?>;

$(document).ready(function() {

    // Select2
    $('#customerSelect').select2({ placeholder: 'Search customer...', allowClear: true });
    $('#addProductSelect').select2({ placeholder: 'Select product...', width: '100%' });
    $('#addCategorySelect').select2({ placeholder: 'Select category...', width: '100%' });

    $('#customerSelect').on('change', function() {
        const selected = $(this).find('option:selected');
        $('#displayPhone').text(selected.data('phone') || '-');
        $('#displayEmail').text(selected.data('email') || '-');
        $('#displayGst').text(selected.data('gst') || '-');
        $('#displayAddress').text(selected.data('address') || '-');
    });

    // Product change
    $('#addProductSelect').on('change', function() {
        const selected = $(this).find('option:selected');
        const unitSelect = $('#addUnitSelect');
        const pcsPerBag = $('#addPcsPerBag');
        const qty = $('#addQty');

        unitSelect.empty().append('<option value="">Select Unit</option>');

        const primaryUnit = selected.data('primary-unit');
        const secUnit = selected.data('sec-unit');
        const secQty = selected.data('sec-qty');

        if (primaryUnit) unitSelect.append(`<option value="${primaryUnit}">${primaryUnit}</option>`);
        if (secUnit) unitSelect.append(`<option value="${secUnit}">${secUnit}</option>`);

        if (primaryUnit || secUnit) {
            unitSelect.prop('disabled', false);
            pcsPerBag.val(secQty || 0).prop('disabled', false);
            qty.prop('disabled', false);
        } else {
            unitSelect.prop('disabled', true);
            pcsPerBag.prop('disabled', true);
            qty.prop('disabled', true);
        }

        updateAddItemButton();
    });

    // Category change
    $('#addCategorySelect').on('change', function() {
        const selected = $(this).find('option:selected');
        const rate = selected.data('rate');
        $('#addRate').val(rate || 0).prop('disabled', false);
        updateAddItemButton();
    });

    function updateAddItemButton() {
        const product = $('#addProductSelect').val();
        const category = $('#addCategorySelect').val();
        const unit = $('#addUnitSelect').val();
        const qty = parseFloat($('#addQty').val()) || 0;
        const rate = parseFloat($('#addRate').val()) || 0;

        $('#addItemBtn').prop('disabled', !(product && category && unit && qty > 0 && rate > 0));
    }

    $('#addUnitSelect, #addQty, #addRate').on('change input', updateAddItemButton);

    // Add item
    $('#addItemBtn').on('click', function() {
        const productSelect = $('#addProductSelect').find('option:selected');
        const categorySelect = $('#addCategorySelect').find('option:selected');

        const newItem = {
            product_id: parseInt($('#addProductSelect').val()),
            product_name: productSelect.data('name') || '',
            cat_id: parseInt($('#addCategorySelect').val()),
            cat_name: categorySelect.data('name') || '',
            unit: $('#addUnitSelect').val() || '',
            qty: parseFloat($('#addQty').val()) || 0,
            pcs_per_bag: parseFloat($('#addPcsPerBag').val()) || 0,
            converted_qty: 0,
            no_of_pcs: 0,
            rate: parseFloat($('#addRate').val()) || 0,
            total: 0,
            taxable: 0,
            hsn_code: productSelect.data('hsn') || '',
            cgst: parseFloat(productSelect.data('cgst')) || 0,
            sgst: parseFloat(productSelect.data('sgst')) || 0,
            cgst_amt: 0,
            sgst_amt: 0
        };

        const primaryUnit = productSelect.data('primary-unit');
        const pcsPerBag = newItem.pcs_per_bag || productSelect.data('sec-qty') || 0;

        if (newItem.unit === primaryUnit) newItem.converted_qty = newItem.qty * pcsPerBag;
        else newItem.converted_qty = newItem.qty;

        newItem.no_of_pcs = newItem.converted_qty;

        const totalAmount = newItem.converted_qty * newItem.rate;
        const isGst = <?php echo (int)($invoice['is_gst'] ?? 1); ?>;

        if (isGst && (newItem.cgst + newItem.sgst) > 0) {
            const gstFactor = 1 + ((newItem.cgst + newItem.sgst) / 100);
            newItem.taxable = totalAmount / gstFactor;
            const gstAmount = totalAmount - newItem.taxable;
            newItem.cgst_amt = gstAmount / 2;
            newItem.sgst_amt = gstAmount / 2;
            newItem.total = totalAmount;
        } else {
            newItem.taxable = totalAmount;
            newItem.cgst_amt = 0;
            newItem.sgst_amt = 0;
            newItem.total = totalAmount;
        }

        items.push(newItem);
        renderItems();

        // reset
        $('#addQty').val('');
        $('#addProductSelect').val(null).trigger('change');
        $('#addCategorySelect').val(null).trigger('change');
        $('#addUnitSelect').empty().append('<option value="">Select Unit</option>').prop('disabled', true);
        $('#addPcsPerBag').val('').prop('disabled', true);
        $('#addRate').val('').prop('disabled', true);
        $('#addItemBtn').prop('disabled', true);
    });

    // Render items
    function renderItems() {
        const tbody = $('#itemsTableBody');
        tbody.empty();

        if (!items || items.length === 0) {
            tbody.append('<tr><td colspan="12" class="text-center text-muted py-4">No items added yet</td></tr>');
        } else {
            items.forEach((item, idx) => {
                const qty = (parseFloat(item.qty) || 0).toFixed(2);
                const pcs = (parseFloat(item.converted_qty) || 0).toFixed(2);

                const row = `
                    <tr data-index="${idx}">
                        <td>${idx + 1}</td>
                        <td>${escapeHtml(item.product_name)}</td>
                        <td>${escapeHtml(item.cat_name)}</td>
                        <td><span class="unit-badge">${escapeHtml(item.unit)}</span></td>
                        <td class="text-end">${qty}</td>
                        <td class="text-end">${pcs}</td>
                        <td class="text-end">${formatMoney(item.rate)}</td>
                        <td class="text-end">${formatMoney(item.taxable)}</td>
                        <td class="text-end">${formatMoney(item.cgst_amt)}</td>
                        <td class="text-end">${formatMoney(item.sgst_amt)}</td>
                        <td class="text-end fw-bold">${formatMoney(item.total)}</td>
                        <td class="text-center">
                            <button type="button" class="btn-remove-item" onclick="removeItem(${idx})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
                tbody.append(row);
            });
        }

        $('#itemCount').text(items.length);
        $('#items_json').val(JSON.stringify(items));
        calculateTotals();
    }

    window.removeItem = function(index) {
        if (confirm('Remove this item?')) {
            items.splice(index, 1);
            renderItems();
        }
    };

    function formatMoney(amount) {
        return '₹' + (parseFloat(amount || 0)).toFixed(2);
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function calculateTotals() {
        let subtotal = 0, cgst = 0, sgst = 0;

        items.forEach(item => {
            subtotal += parseFloat(item.taxable || 0);
            cgst += parseFloat(item.cgst_amt || 0);
            sgst += parseFloat(item.sgst_amt || 0);
        });

        const discount = parseFloat($('#overallDiscount').val()) || 0;
        const discountType = $('#discountType').val();

        let discountAmt = 0;
        if (discount > 0) discountAmt = (discountType === 'percentage') ? (subtotal * discount / 100) : discount;

        const shipping = parseFloat($('#shippingCharges').val()) || 0;
        const grandTotal = (subtotal - discountAmt) + cgst + sgst + shipping;

        let paid = 0;
        $('.payment-input').each(function() {
            paid += parseFloat($(this).val()) || 0;
        });

        const pending = Math.max(0, grandTotal - paid);

        $('#subtotal').text(formatMoney(subtotal));
        $('#discountAmount').text('-' + formatMoney(discountAmt));
        $('#cgstTotal').text(formatMoney(cgst));
        $('#sgstTotal').text(formatMoney(sgst));
        $('#shippingTotal').text(formatMoney(shipping));
        $('#grandTotal').text(formatMoney(grandTotal));
        $('#paidAmount').text(formatMoney(paid));
        $('#pendingAmount').text(formatMoney(pending));

        updatePaymentMethod();
    }

    function updatePaymentMethod() {
        const cash = parseFloat($('input[name="cash_amount"]').val()) || 0;
        const upi = parseFloat($('input[name="upi_amount"]').val()) || 0;
        const card = parseFloat($('input[name="card_amount"]').val()) || 0;
        const bank = parseFloat($('input[name="bank_amount"]').val()) || 0;
        const cheque = parseFloat($('input[name="cheque_amount"]').val()) || 0;
        const credit = parseFloat($('input[name="credit_amount"]').val()) || 0;

        const payments = [cash, upi, card, bank, cheque, credit];
        const activePayments = payments.filter(p => p > 0).length;

        let method = 'credit';
        let icon = 'journal-bookmark-fill';

        if (activePayments > 1) { method = 'mixed'; icon = 'shuffle'; }
        else if (activePayments === 1) {
            if (cash > 0) { method = 'cash'; icon = 'cash'; }
            else if (upi > 0) { method = 'upi'; icon = 'phone'; }
            else if (card > 0) { method = 'card'; icon = 'credit-card'; }
            else if (bank > 0) { method = 'bank'; icon = 'bank'; }
            else if (cheque > 0) { method = 'cheque'; icon = 'journal-check'; }
        }

        $('#paymentMethodDisplay').html(`<i class="bi bi-${icon}"></i> ${method.charAt(0).toUpperCase() + method.slice(1)}`);
    }

    $('#overallDiscount, #discountType, #shippingCharges, .payment-input').on('input change', calculateTotals);

    $('input[name="cheque_amount"]').on('input change', function() {
        $('#chequeDetails').toggle((parseFloat($(this).val()) || 0) > 0);
    });

    $('input[name="credit_amount"]').on('input change', function() {
        $('#creditDetails').toggle((parseFloat($(this).val()) || 0) > 0);
    });

    // Initial render
    renderItems();

    $('#editForm').on('submit', function(e) {
        if (!items || items.length === 0) {
            e.preventDefault();
            alert('Please add at least one item.');
            return false;
        }
        if (!$('#customerSelect').val()) {
            e.preventDefault();
            alert('Please select a customer.');
            return false;
        }
    });
});
</script>
</body>
</html>