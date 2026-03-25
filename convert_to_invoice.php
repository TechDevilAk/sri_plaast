<?php
// convert_to_invoice.php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

checkRoleAccess(['admin', 'sale']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header("Location: quotations.php");
    exit;
}

// Get quotation details
$query = "SELECT q.*, qi.* FROM quotations q 
          LEFT JOIN quotation_item qi ON q.id = qi.quotation_id 
          WHERE q.id = $id";
$result = mysqli_query($conn, $query);
$quotation = mysqli_fetch_assoc($result);

if (!$quotation) {
    header("Location: quotations.php");
    exit;
}

// Get all items
$items_query = "SELECT * FROM quotation_item WHERE quotation_id = $id";
$items_result = mysqli_query($conn, $items_query);
$items = [];
while ($item = mysqli_fetch_assoc($items_result)) {
    $items[] = $item;
}

// Generate invoice number
$is_gst = $quotation['is_gst'];
$prefix = $is_gst ? 'SP' : 'E';

// Get next invoice number
mysqli_begin_transaction($conn);

$result = mysqli_query($conn, "SELECT id, counter_value FROM invoice_counter WHERE prefix = '$prefix' LIMIT 1 FOR UPDATE");
$row = mysqli_fetch_assoc($result);

if (!$row) {
    $start = 1;
    mysqli_query($conn, "INSERT INTO invoice_counter (prefix, counter_value) VALUES ('$prefix', $start)");
    $counterId = mysqli_insert_id($conn);
    $counterValue = $start;
} else {
    $counterId = $row['id'];
    $counterValue = $row['counter_value'];
}

$num = $counterValue;
$inv_num = $prefix . str_pad($num, 5, "0", STR_PAD_LEFT);

// Check if exists
$check = mysqli_query($conn, "SELECT id FROM invoice WHERE inv_num = '$inv_num' LIMIT 1");
while (mysqli_num_rows($check) > 0) {
    $num++;
    $inv_num = $prefix . str_pad($num, 5, "0", STR_PAD_LEFT);
    $check = mysqli_query($conn, "SELECT id FROM invoice WHERE inv_num = '$inv_num' LIMIT 1");
}

$next = $num + 1;
mysqli_query($conn, "UPDATE invoice_counter SET counter_value = $next WHERE id = $counterId");

// Create invoice
$customer_id = $quotation['customer_id'] ? $quotation['customer_id'] : 'NULL';
$customer_name = mysqli_real_escape_string($conn, $quotation['customer_name']);

$query = "INSERT INTO invoice (
    inv_num, customer_id, customer_name, subtotal, overall_discount, overall_discount_type, total,
    taxable, cgst, cgst_amount, sgst, sgst_amount, cash_received, change_give, pending_amount, payment_method,
    is_gst, cash_amount, upi_amount, card_amount, bank_amount, cheque_amount, credit_amount
) VALUES (
    '$inv_num', $customer_id, '$customer_name', {$quotation['subtotal']}, {$quotation['overall_discount']}, 
    '{$quotation['overall_discount_type']}', {$quotation['total']}, {$quotation['taxable']}, 
    0, {$quotation['cgst_amount']}, 0, {$quotation['sgst_amount']}, 0, 0, {$quotation['total']}, 
    'credit', {$quotation['is_gst']}, 0, 0, 0, 0, 0, 0
)";

if (mysqli_query($conn, $query)) {
    $invoice_id = mysqli_insert_id($conn);
    
    // Insert items
    foreach ($items as $item) {
        $product_id = $item['product_id'] ? $item['product_id'] : 'NULL';
        $cat_id = $item['cat_id'] ? $item['cat_id'] : 'NULL';
        
        $item_query = "INSERT INTO invoice_item (
            invoice_id, product_id, product_name, cat_id, cat_name, quantity, unit,
            purchase_price, selling_price, discount, discount_type, total, hsn,
            taxable, cgst, cgst_amount, sgst, sgst_amount
        ) VALUES (
            $invoice_id, $product_id, '{$item['product_name']}', $cat_id, '{$item['cat_name']}',
            {$item['quantity']}, '{$item['unit']}', 0, {$item['selling_price']}, 0, 'amount',
            {$item['total']}, '{$item['hsn']}', {$item['taxable']}, {$item['cgst']}, 
            {$item['cgst_amount']}, {$item['sgst']}, {$item['sgst_amount']}
        )";
        mysqli_query($conn, $item_query);
        
        // Update stock
        if ($item['cat_id'] && $item['converted_qty'] > 0) {
            mysqli_query($conn, "UPDATE category SET total_quantity = total_quantity - {$item['converted_qty']} 
                                WHERE id = {$item['cat_id']}");
        }
    }
    
    // Update quotation status
    mysqli_query($conn, "UPDATE quotations SET status = 'converted' WHERE id = $id");
    
    // Log activity
    $log_desc = "Converted quotation {$quotation['quotation_num']} to invoice $inv_num";
    mysqli_query($conn, "INSERT INTO activity_log (user_id, action, description) 
                        VALUES ({$_SESSION['user_id']}, 'convert', '$log_desc')");
    
    mysqli_commit($conn);
    
    header("Location: print_invoice.php?id=" . $invoice_id);
    exit;
} else {
    mysqli_rollback($conn);
    header("Location: quotations.php?error=Conversion failed");
    exit;
}
?>