<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can import products
checkRoleAccess(['admin']);

// Check if downloading template
if (isset($_GET['download_template']) && $_GET['download_template'] == 1) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="product_import_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    // Headers with product_type column
    fputcsv($output, [
        'product_name',
        'product_type',
        'hsn_code',
        'primary_qty',
        'primary_unit',
        'sec_qty',
        'sec_unit'
    ]);
    
    // Example data
    fputcsv($output, [
        'Sample Product',
        'direct',
        '39233090',
        '1',
        'bag',
        '107',
        'pcs'
    ]);
    
    fputcsv($output, [
        'Another Product',
        'converted',
        '39235010',
        '1',
        'box',
        '50',
        'pcs'
    ]);
    
    fclose($output);
    exit;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $response = ['success' => false, 'imported' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
    
    $file = $_FILES['import_file'];
    
    // Check file type
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_ext !== 'csv') {
        $response['message'] = 'Only CSV files are allowed.';
        echo json_encode($response);
        exit;
    }
    
    // Open file
    if (($handle = fopen($file['tmp_name'], 'r')) !== FALSE) {
        // Read headers
        $headers = fgetcsv($handle);
        
        // Expected headers with product_type
        $expected_headers = ['product_name', 'product_type', 'hsn_code', 'primary_qty', 'primary_unit', 'sec_qty', 'sec_unit'];
        
        // Map columns
        $column_map = [];
        foreach ($expected_headers as $index => $expected) {
            $column_map[$expected] = array_search($expected, $headers);
        }
        
        // Check if product_type column exists (optional, default to 'direct')
        $has_product_type = $column_map['product_type'] !== false;
        
        $row_number = 1;
        while (($row = fgetcsv($handle)) !== FALSE) {
            $row_number++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Get values
            $product_name = $column_map['product_name'] !== false ? trim($row[$column_map['product_name']]) : '';
            $product_type = $has_product_type && $column_map['product_type'] !== false ? strtolower(trim($row[$column_map['product_type']])) : 'direct';
            $hsn_code = $column_map['hsn_code'] !== false ? trim($row[$column_map['hsn_code']]) : '';
            $primary_qty = $column_map['primary_qty'] !== false ? floatval($row[$column_map['primary_qty']]) : 0;
            $primary_unit = $column_map['primary_unit'] !== false ? trim($row[$column_map['primary_unit']]) : '';
            $sec_qty = $column_map['sec_qty'] !== false ? floatval($row[$column_map['sec_qty']]) : 0;
            $sec_unit = $column_map['sec_unit'] !== false ? trim($row[$column_map['sec_unit']]) : '';
            
            // Validate product type
            if (!in_array($product_type, ['direct', 'converted'])) {
                $response['failed']++;
                $response['errors'][] = "Row {$row_number}: Invalid product_type '{$product_type}'. Must be 'direct' or 'converted'.";
                continue;
            }
            
            // Validate required fields
            if (empty($product_name)) {
                $response['failed']++;
                $response['errors'][] = "Row {$row_number}: Product name is required.";
                continue;
            }
            
            if (empty($primary_unit)) {
                $response['failed']++;
                $response['errors'][] = "Row {$row_number}: Primary unit is required for product '{$product_name}'.";
                continue;
            }
            
            // Check if product exists
            $check_stmt = $conn->prepare("SELECT id FROM product WHERE product_name = ?");
            $check_stmt->bind_param("s", $product_name);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                // Update existing product
                $check_stmt->bind_result($product_id);
                $check_stmt->fetch();
                
                $update_stmt = $conn->prepare("UPDATE product SET product_type=?, hsn_code=?, primary_qty=?, primary_unit=?, sec_qty=?, sec_unit=? WHERE id=?");
                $update_stmt->bind_param("ssdsdsi", $product_type, $hsn_code, $primary_qty, $primary_unit, $sec_qty, $sec_unit, $product_id);
                
                if ($update_stmt->execute()) {
                    $response['updated']++;
                    
                    // Log activity
                    $type_label = ($product_type == 'direct') ? 'Direct Sale' : 'Converted Sale';
                    $log_desc = "Bulk updated product: {$product_name} (Type: {$type_label}, HSN: " . ($hsn_code ?: 'N/A') . ")";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'bulk_update', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                } else {
                    $response['failed']++;
                    $response['errors'][] = "Row {$row_number}: Failed to update product '{$product_name}'.";
                }
                $update_stmt->close();
            } else {
                // Insert new product
                $insert_stmt = $conn->prepare("INSERT INTO product (product_name, product_type, hsn_code, primary_qty, primary_unit, sec_qty, sec_unit) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insert_stmt->bind_param("sssdsds", $product_name, $product_type, $hsn_code, $primary_qty, $primary_unit, $sec_qty, $sec_unit);
                
                if ($insert_stmt->execute()) {
                    $response['imported']++;
                    
                    // Log activity
                    $type_label = ($product_type == 'direct') ? 'Direct Sale' : 'Converted Sale';
                    $log_desc = "Bulk imported product: {$product_name} (Type: {$type_label}, HSN: " . ($hsn_code ?: 'N/A') . ")";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'bulk_import', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                } else {
                    $response['failed']++;
                    $response['errors'][] = "Row {$row_number}: Failed to insert product '{$product_name}'.";
                }
                $insert_stmt->close();
            }
            $check_stmt->close();
        }
        
        fclose($handle);
        
        $response['success'] = true;
        $response['message'] = "Import completed. Imported: {$response['imported']}, Updated: {$response['updated']}, Failed: {$response['failed']}";
        
        echo json_encode($response);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to open file.']);
        exit;
    }
}

// If accessed directly without POST
header('Location: products.php');
exit;
?>