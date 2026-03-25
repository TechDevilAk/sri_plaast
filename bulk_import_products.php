<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can import products
checkRoleAccess(['admin']);

$response = ['success' => false, 'message' => '', 'imported' => 0, 'failed' => 0, 'errors' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $response['message'] = 'File upload failed. Error code: ' . $file['error'];
        echo json_encode($response);
        exit;
    }
    
    // Check file type
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, ['csv', 'xlsx', 'xls'])) {
        $response['message'] = 'Invalid file type. Please upload CSV or Excel file.';
        echo json_encode($response);
        exit;
    }
    
    // Process based on file type
    if ($file_ext === 'csv') {
        $result = processCSV($file['tmp_name'], $conn);
    } else {
        // For Excel files, you'll need PHPExcel or similar library
        // For now, we'll handle CSV only
        $response['message'] = 'Excel files (.xlsx, .xls) require additional library. Please use CSV format.';
        echo json_encode($response);
        exit;
    }
    
    echo json_encode($result);
    exit;
}

// Handle template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="product_import_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['Product Name', 'HSN Code', 'CGST %', 'SGST %', 'Primary Qty', 'Primary Unit', 'Secondary Qty', 'Secondary Unit']);
    
    // Add sample data
    fputcsv($output, ['Sample Product 1', '123456', '9', '9', '1', 'bag', '107', 'pcs']);
    fputcsv($output, ['Sample Product 2', '789012', '6', '6', '1', 'box', '24', 'bottle']);
    fputcsv($output, ['Sample Product 3', '', '0', '0', '1', 'kg', '0', '']);
    fputcsv($output, ['Sample Product 4', '345678', '12', '12', '1', 'pack', '50', 'pcs']);
    fputcsv($output, ['Sample Product 5', '901234', '2.5', '2.5', '1', 'liter', '0', '']);
    
    // Add instructions
    fputcsv($output, []);
    fputcsv($output, ['INSTRUCTIONS:']);
    fputcsv($output, ['- Product Name is required']);
    fputcsv($output, ['- Primary Unit is required (kg, bag, box, pcs, liter, etc.)']);
    fputcsv($output, ['- Leave Secondary Qty as 0 if no secondary unit']);
    fputcsv($output, ['- For existing products, data will be updated based on Product Name']);
    fputcsv($output, ['- HSN Code with GST will be auto-created if not exists']);
    fputcsv($output, ['- CGST and SGST should be entered separately (e.g., 9 and 9 for 18% total)']);
    
    fclose($output);
    exit;
}

function processCSV($filepath, $conn) {
    $result = [
        'success' => true,
        'message' => '',
        'imported' => 0,
        'failed' => 0,
        'errors' => []
    ];
    
    $handle = fopen($filepath, 'r');
    $row = 1;
    
    // Get header row
    $headers = fgetcsv($handle);
    $expected_headers = ['Product Name', 'HSN Code', 'CGST %', 'SGST %', 'Primary Qty', 'Primary Unit', 'Secondary Qty', 'Secondary Unit'];
    
    // Validate headers
    if (count($headers) != count($expected_headers)) {
        $result['success'] = false;
        $result['message'] = 'Invalid CSV format. Expected ' . count($expected_headers) . ' columns but found ' . count($headers) . '. Please use the template provided.';
        fclose($handle);
        return $result;
    }
    
    // Check if headers match (case-insensitive)
    foreach ($headers as $index => $header) {
        if (trim(strtolower($header)) !== strtolower($expected_headers[$index])) {
            $result['success'] = false;
            $result['message'] = 'Invalid CSV header. Expected "' . $expected_headers[$index] . '" but found "' . $header . '". Please use the template provided.';
            fclose($handle);
            return $result;
        }
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        while (($data = fgetcsv($handle)) !== FALSE) {
            $row++;
            
            // Skip empty rows
            if (empty(array_filter($data))) {
                continue;
            }
            
            // Ensure we have enough columns
            while (count($data) < 8) {
                $data[] = '';
            }
            
            // Map data to variables
            $product_name = trim($data[0] ?? '');
            $hsn_code = trim($data[1] ?? '');
            $cgst = floatval($data[2] ?? 0);
            $sgst = floatval($data[3] ?? 0);
            $primary_qty = floatval($data[4] ?? 1);
            $primary_unit = trim($data[5] ?? '');
            $sec_qty = floatval($data[6] ?? 0);
            $sec_unit = trim($data[7] ?? '');
            
            // Validate required fields
            if (empty($product_name)) {
                $result['failed']++;
                $result['errors'][] = "Row $row: Product Name is required";
                continue;
            }
            
            if (empty($primary_unit)) {
                $result['failed']++;
                $result['errors'][] = "Row $row: Primary Unit is required for product '$product_name'";
                continue;
            }
            
            // Check if product exists
            $check = $conn->prepare("SELECT id FROM product WHERE product_name = ?");
            $check->bind_param("s", $product_name);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                // Update existing product
                $check->close();
                
                // Handle GST for HSN code
                if (!empty($hsn_code) && ($cgst > 0 || $sgst > 0)) {
                    $igst = $cgst + $sgst;
                    
                    // Check if GST rate exists
                    $check_gst = $conn->prepare("SELECT id FROM gst WHERE hsn = ?");
                    $check_gst->bind_param("s", $hsn_code);
                    $check_gst->execute();
                    $check_gst->store_result();
                    
                    if ($check_gst->num_rows == 0) {
                        $insert_gst = $conn->prepare("INSERT INTO gst (hsn, cgst, sgst, igst, status) VALUES (?, ?, ?, ?, 1)");
                        $insert_gst->bind_param("sddd", $hsn_code, $cgst, $sgst, $igst);
                        $insert_gst->execute();
                        $insert_gst->close();
                    }
                    $check_gst->close();
                }
                
                $update = $conn->prepare("UPDATE product SET hsn_code=?, primary_qty=?, primary_unit=?, sec_qty=?, sec_unit=?, updated_at = NOW() WHERE product_name=?");
                $update->bind_param("sdsdss", $hsn_code, $primary_qty, $primary_unit, $sec_qty, $sec_unit, $product_name);
                
                if ($update->execute()) {
                    $result['imported']++;
                    
                    // Log activity
                    $log_desc = "Bulk updated product: $product_name";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'bulk_update', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                } else {
                    $result['failed']++;
                    $result['errors'][] = "Row $row: Failed to update product '$product_name' - " . $conn->error;
                }
                $update->close();
                
            } else {
                // Insert new product
                $check->close();
                
                // Handle GST for HSN code
                if (!empty($hsn_code) && ($cgst > 0 || $sgst > 0)) {
                    $igst = $cgst + $sgst;
                    
                    // Check if GST rate exists
                    $check_gst = $conn->prepare("SELECT id FROM gst WHERE hsn = ?");
                    $check_gst->bind_param("s", $hsn_code);
                    $check_gst->execute();
                    $check_gst->store_result();
                    
                    if ($check_gst->num_rows == 0) {
                        $insert_gst = $conn->prepare("INSERT INTO gst (hsn, cgst, sgst, igst, status) VALUES (?, ?, ?, ?, 1)");
                        $insert_gst->bind_param("sddd", $hsn_code, $cgst, $sgst, $igst);
                        $insert_gst->execute();
                        $insert_gst->close();
                    }
                    $check_gst->close();
                }
                
                $insert = $conn->prepare("INSERT INTO product (product_name, hsn_code, primary_qty, primary_unit, sec_qty, sec_unit) VALUES (?, ?, ?, ?, ?, ?)");
                $insert->bind_param("ssdsds", $product_name, $hsn_code, $primary_qty, $primary_unit, $sec_qty, $sec_unit);
                
                if ($insert->execute()) {
                    $result['imported']++;
                    
                    // Log activity
                    $log_desc = "Bulk imported product: $product_name";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'bulk_import', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                } else {
                    $result['failed']++;
                    $result['errors'][] = "Row $row: Failed to import product '$product_name' - " . $conn->error;
                }
                $insert->close();
            }
        }
        
        fclose($handle);
        
        // Commit transaction
        $conn->commit();
        
        $result['message'] = "Import completed. Imported: {$result['imported']}, Failed: {$result['failed']}";
        
    } catch (Exception $e) {
        $conn->rollback();
        $result['success'] = false;
        $result['message'] = 'Database error: ' . $e->getMessage();
    }
    
    return $result;
}
?>