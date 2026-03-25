<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can process bulk sales
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $selected_categories_json = $_POST['selected_categories'] ?? '';
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $total_cost = floatval($_POST['total_cost'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $amount_received = floatval($_POST['amount_received'] ?? 0);
    $credit_due_date = $_POST['credit_due_date'] ?? null;
    
    // Validate
    if (!$customer_id) {
        header('Location: bulk_sale.php?error=' . urlencode('Please select a customer'));
        exit;
    }
    
    if (empty($selected_categories_json)) {
        header('Location: bulk_sale.php?error=' . urlencode('No categories selected'));
        exit;
    }
    
    $selected_categories = json_decode($selected_categories_json, true);
    
    if (empty($selected_categories)) {
        header('Location: bulk_sale.php?error=' . urlencode('Invalid category data'));
        exit;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get customer details
        $cust_query = $conn->prepare("SELECT customer_name, opening_balance FROM customers WHERE id = ?");
        $cust_query->bind_param("i", $customer_id);
        $cust_query->execute();
        $cust_result = $cust_query->get_result();
        $customer = $cust_result->fetch_assoc();
        
        if (!$customer) {
            throw new Exception('Customer not found');
        }
        
        // Generate invoice number
        $prefix = 'BULK';
        $year = date('Y');
        $month = date('m');
        // Get last invoice number for this prefix
        $result = $conn->query("SELECT counter_value FROM invoice_counter WHERE prefix = 'BULK'");
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $counter = $row['counter_value'] + 1;
            $conn->query("UPDATE invoice_counter SET counter_value = $counter WHERE prefix = 'BULK'");
        } else {
            $counter = 1;
            $conn->query("INSERT INTO invoice_counter (prefix, counter_value) VALUES ('BULK', 1)");
        }
        $invoice_num = 'BULK' . $year . $month . str_pad($counter, 4, '0', STR_PAD_LEFT);
        
        // Create invoice
        $pending_amount = $total_amount - $amount_received;
        
        $invoice_query = "INSERT INTO invoice (
            inv_num, customer_id, customer_name, subtotal, total, 
            cash_received, pending_amount, payment_method, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $invoice_stmt = $conn->prepare($invoice_query);
        // 8 parameters: s,i,s,d,d,d,s,s
        $invoice_stmt->bind_param(
            "sisdddss",
            $invoice_num,
            $customer_id,
            $customer['customer_name'],
            $total_amount,
            $total_amount,
            $amount_received,
            $pending_amount,
            $payment_method
        );
        
        if (!$invoice_stmt->execute()) {
            throw new Exception('Failed to create invoice: ' . $conn->error);
        }
        
        $invoice_id = $conn->insert_id;
        
        // Process each selected category
        foreach ($selected_categories as $category) {
            $cat_id = $category['id'];
            $kg_qty = floatval($category['kgQty']);
            $pcs_qty = intval($category['pcsQty']);
            $selling_price = floatval($category['sellingPrice']);
            $purchase_price = floatval($category['purchasePrice']);
            $total_selling = floatval($category['totalSelling']);
            $total_cost = floatval($category['totalCost']);
            
            // Get current stock and category details
            $stock_query = $conn->prepare("SELECT total_quantity, category_name, gram_value FROM category WHERE id = ?");
            $stock_query->bind_param("i", $cat_id);
            $stock_query->execute();
            $stock_result = $stock_query->get_result();
            $stock_data = $stock_result->fetch_assoc();
            
            if (!$stock_data) {
                throw new Exception('Category not found: ID ' . $cat_id);
            }
            
            // Check stock again (double validation)
            if ($stock_data['total_quantity'] < $pcs_qty) {
                throw new Exception('Insufficient stock for ' . $stock_data['category_name'] . 
                                  '. Available: ' . $stock_data['total_quantity'] . ' pcs, Required: ' . $pcs_qty . ' pcs');
            }
            
            // Update stock
            $new_stock = $stock_data['total_quantity'] - $pcs_qty;
            $update_stock = $conn->prepare("UPDATE category SET total_quantity = ? WHERE id = ?");
            $update_stock->bind_param("di", $new_stock, $cat_id);
            
            if (!$update_stock->execute()) {
                throw new Exception('Failed to update stock for ' . $stock_data['category_name']);
            }
            
            // Get HSN code from GST table based on category or use default
            // Since product table doesn't have cat_id, we'll use a default HSN or you can
            // modify this based on your business logic
            $hsn = '39233090'; // Default HSN for plastic products
            
            // Optional: You can try to get HSN from gst table if you have a mapping
            // $hsn_query = $conn->query("SELECT hsn FROM gst LIMIT 1");
            // if ($hsn_query->num_rows > 0) {
            //     $hsn_data = $hsn_query->fetch_assoc();
            //     $hsn = $hsn_data['hsn'];
            // }
            
            // Insert invoice item - Note: Using cat_id instead of product_id
            $item_query = "INSERT INTO invoice_item (
                invoice_id, cat_id, cat_name, quantity, unit, no_of_pcs,
                purchase_price, selling_price, total, hsn, taxable, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $unit = 'KG';
            $taxable = $total_selling; // Assuming no tax for bulk sale, modify as needed
            
            $item_stmt = $conn->prepare($item_query);
            
            // 11 parameters type string: i i s d s i d d d s d
            $type_string = "iisdsidddsd";
            
            $item_stmt->bind_param(
                $type_string,
                $invoice_id,
                $cat_id,
                $stock_data['category_name'],
                $kg_qty,
                $unit,
                $pcs_qty,
                $purchase_price,
                $selling_price,
                $total_selling,
                $hsn,
                $taxable
            );
            
            if (!$item_stmt->execute()) {
                throw new Exception('Failed to add invoice item for ' . $stock_data['category_name'] . ': ' . $conn->error);
            }
            
            // Log stock reduction
            $log_desc = "Stock reduced from category '{$stock_data['category_name']}': " . 
                        number_format($pcs_qty, 0) . " pcs (Bulk Sale Invoice: {$invoice_num})";
            $log_query = "INSERT INTO activity_log (user_id, action, description, created_at) VALUES (?, 'update', ?, NOW())";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
        }
        
        // Update customer opening balance if credit sale
        if ($payment_method === 'credit' && $pending_amount > 0) {
            $new_balance = $customer['opening_balance'] + $pending_amount;
            $update_balance = $conn->prepare("UPDATE customers SET opening_balance = ? WHERE id = ?");
            $update_balance->bind_param("di", $new_balance, $customer_id);
            
            if (!$update_balance->execute()) {
                throw new Exception('Failed to update customer balance');
            }
            
            // If credit due date is provided, you might want to store it in a separate table
            if ($credit_due_date) {
                // You can create a credit_dates table or add column to invoice
                // For now, we'll just log it
                $credit_log = "Credit sale with due date: " . $credit_due_date;
                $log_query = "INSERT INTO activity_log (user_id, action, description, created_at) VALUES (?, 'credit', ?, NOW())";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $credit_log);
                $log_stmt->execute();
            }
        }
        
        // Log activity
        $activity_desc = "Created bulk sale invoice {$invoice_num} for customer: {$customer['customer_name']} (Total: ₹" . number_format($total_amount, 2) . ")";
        $activity_query = "INSERT INTO activity_log (user_id, action, description, created_at) VALUES (?, 'create', ?, NOW())";
        $activity_stmt = $conn->prepare($activity_query);
        $activity_stmt->bind_param("is", $_SESSION['user_id'], $activity_desc);
        $activity_stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        // Redirect to invoice view
        header('Location: view_invoice.php?id=' . $invoice_id . '&success=Bulk sale completed successfully');
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
        header('Location: bulk_sale.php?error=' . urlencode($error));
        exit;
    }
} else {
    header('Location: bulk_sale.php');
    exit;
}
?>