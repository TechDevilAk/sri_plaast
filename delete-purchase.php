<?php
// delete-purchase.php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can delete purchases
checkRoleAccess(['admin']);

header('Content-Type: application/json');

// Check if it's an AJAX request
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Function to return JSON response
function jsonResponse($success, $message, $data = null) {
    global $is_ajax;
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data
    ];
    
    if ($is_ajax) {
        echo json_encode($response);
        exit;
    } else {
        // For non-AJAX requests, store in session and redirect
        session_start();
        $_SESSION['delete_message'] = $response;
        header('Location: purchases.php');
        exit;
    }
}

// Check if purchase ID is provided
if (!isset($_POST['purchase_id']) && !isset($_GET['id'])) {
    jsonResponse(false, 'Purchase ID is required');
}

$purchase_id = isset($_POST['purchase_id']) ? intval($_POST['purchase_id']) : intval($_GET['id']);

if ($purchase_id <= 0) {
    jsonResponse(false, 'Invalid purchase ID');
}

// Verify purchase exists and get details
$check_stmt = $conn->prepare("
    SELECT p.*, s.supplier_name 
    FROM purchase p 
    LEFT JOIN suppliers s ON p.supplier_id = s.id 
    WHERE p.id = ?
");
$check_stmt->bind_param("i", $purchase_id);
$check_stmt->execute();
$result = $check_stmt->get_result();
$purchase = $result->fetch_assoc();

if (!$purchase) {
    jsonResponse(false, 'Purchase not found');
}

// Begin transaction
$conn->begin_transaction();

try {
    // Step 1: Get all purchase items to revert stock
    $items_stmt = $conn->prepare("
        SELECT cat_id, qty, purchase_price, cat_name 
        FROM purchase_item 
        WHERE purchase_id = ?
    ");
    $items_stmt->bind_param("i", $purchase_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    $reverted_items = [];
    
    // Step 2: Revert category quantities (subtract the purchased quantity)
    while ($item = $items_result->fetch_assoc()) {
        $cat_id = $item['cat_id'];
        $qty = $item['qty']; // Quantity in pieces
        
        // Update category total_quantity (subtract the purchased quantity)
        $update_cat = $conn->prepare("
            UPDATE category 
            SET total_quantity = total_quantity - ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update_cat->bind_param("di", $qty, $cat_id);
        
        if (!$update_cat->execute()) {
            throw new Exception("Failed to update category ID: {$cat_id}");
        }
        
        // Check if category went below zero (should not happen, but just in case)
        if ($update_cat->affected_rows === 0) {
            throw new Exception("Category ID {$cat_id} not found");
        }
        
        // Verify stock didn't go negative
        $check_cat = $conn->prepare("SELECT total_quantity FROM category WHERE id = ?");
        $check_cat->bind_param("i", $cat_id);
        $check_cat->execute();
        $cat_result = $check_cat->get_result()->fetch_assoc();
        
        if ($cat_result['total_quantity'] < 0) {
            throw new Exception("Warning: Stock went negative for category ID {$cat_id}. Please check.");
        }
        
        $reverted_items[] = [
            'cat_id' => $cat_id,
            'cat_name' => $item['cat_name'],
            'qty' => $qty
        ];
        
        $update_cat->close();
        $check_cat->close();
    }
    $items_stmt->close();

    // Step 3: Delete from GST credit table
    $del_gst = $conn->prepare("DELETE FROM gst_credit_table WHERE purchase_id = ?");
    $del_gst->bind_param("i", $purchase_id);
    if (!$del_gst->execute()) {
        throw new Exception("Failed to delete GST credit records");
    }
    $del_gst->close();

    // Step 4: Delete payment history
    $del_payments = $conn->prepare("DELETE FROM purchase_payment_history WHERE purchase_id = ?");
    $del_payments->bind_param("i", $purchase_id);
    if (!$del_payments->execute()) {
        throw new Exception("Failed to delete payment history");
    }
    $del_payments->close();

    // Step 5: Delete purchase items
    $del_items = $conn->prepare("DELETE FROM purchase_item WHERE purchase_id = ?");
    $del_items->bind_param("i", $purchase_id);
    if (!$del_items->execute()) {
        throw new Exception("Failed to delete purchase items");
    }
    $del_items->close();

    // Step 6: Delete the purchase record
    $del_purchase = $conn->prepare("DELETE FROM purchase WHERE id = ?");
    $del_purchase->bind_param("i", $purchase_id);
    if (!$del_purchase->execute()) {
        throw new Exception("Failed to delete purchase record");
    }
    $del_purchase->close();

    // Step 7: Log the activity
    $log_desc = "Deleted purchase #{$purchase['purchase_no']} with " . count($reverted_items) . " items. Stock reverted.";
    $log_stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)");
    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
    $log_stmt->execute();
    $log_stmt->close();

    // Commit transaction
    $conn->commit();

    // Prepare success response
    $response_data = [
        'purchase_id' => $purchase_id,
        'purchase_no' => $purchase['purchase_no'],
        'reverted_items' => $reverted_items,
        'total_items' => count($reverted_items)
    ];

    jsonResponse(true, 'Purchase deleted successfully. Stock quantities have been updated.', $response_data);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Log error
    error_log("Delete purchase error: " . $e->getMessage());
    
    jsonResponse(false, 'Failed to delete purchase: ' . $e->getMessage());
}
?>