<?php
// suppliers.php
session_start();
$currentPage = 'suppliers';
$pageTitle = 'Suppliers Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view suppliers, but only admin can modify
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// ==================== BULK IMPORT FUNCTIONALITY ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_import') {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to import suppliers.';
    } else {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
            
            if ($file_ext != 'csv') {
                $error = 'Please upload a valid CSV file.';
            } else {
                if (($handle = fopen($file, "r")) !== FALSE) {
                    // Get headers
                    $headers = fgetcsv($handle);
                    
                    // Validate headers
                    $expected_headers = ['supplier_name', 'phone', 'email', 'address', 'gst_number', 'bank_name', 'account_number', 'ifsc_code', 'branch', 'upi_id', 'opening_balance'];
                    $headers_valid = true;
                    
                    foreach ($expected_headers as $index => $expected) {
                        if (!isset($headers[$index]) || trim($headers[$index]) != $expected) {
                            $headers_valid = false;
                            break;
                        }
                    }
                    
                    if (!$headers_valid) {
                        $error = 'Invalid CSV format. Please use the sample CSV template.';
                    } else {
                        // Begin transaction
                        mysqli_begin_transaction($conn);
                        
                        try {
                            $success_count = 0;
                            $error_count = 0;
                            $import_errors = [];
                            $row_number = 1; // Start from 1 (header is row 0)
                            
                            while (($data = fgetcsv($handle)) !== FALSE) {
                                $row_number++;
                                
                                // Skip empty rows
                                if (count($data) < 2 || empty(trim($data[0]))) {
                                    continue;
                                }
                                
                                // Map data to associative array
                                $row = [];
                                foreach ($headers as $index => $header) {
                                    $row[$header] = isset($data[$index]) ? trim($data[$index]) : '';
                                }
                                
                                // Validate required fields
                                if (empty($row['supplier_name'])) {
                                    $import_errors[] = "Row $row_number: Supplier name is required";
                                    $error_count++;
                                    continue;
                                }
                                
                                // Validate phone format if provided
                                if (!empty($row['phone']) && !preg_match('/^[0-9+]{10,15}$/', $row['phone'])) {
                                    $import_errors[] = "Row $row_number: Invalid phone number format";
                                    $error_count++;
                                    continue;
                                }
                                
                                // Validate email if provided
                                if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                                    $import_errors[] = "Row $row_number: Invalid email format";
                                    $error_count++;
                                    continue;
                                }
                                
                                // Validate GST if provided
                                if (!empty($row['gst_number']) && strlen($row['gst_number']) != 15) {
                                    $import_errors[] = "Row $row_number: GST number must be 15 characters";
                                    $error_count++;
                                    continue;
                                }
                                
                                // Validate opening balance
                                $opening_balance = floatval($row['opening_balance'] ?? 0);
                                
                                // Check if supplier already exists
                                $check = $conn->prepare("SELECT id FROM suppliers WHERE supplier_name = ?");
                                $check->bind_param("s", $row['supplier_name']);
                                $check->execute();
                                $check->store_result();
                                
                                if ($check->num_rows > 0) {
                                    $import_errors[] = "Row $row_number: Supplier already exists with this name";
                                    $error_count++;
                                    $check->close();
                                    continue;
                                }
                                $check->close();
                                
                                // Insert supplier
                                $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, phone, email, address, gst_number, bank_name, account_number, ifsc_code, branch, upi_id, opening_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $stmt->bind_param("ssssssssssd", 
                                    $row['supplier_name'], 
                                    $row['phone'], 
                                    $row['email'], 
                                    $row['address'], 
                                    $row['gst_number'], 
                                    $row['bank_name'], 
                                    $row['account_number'], 
                                    $row['ifsc_code'], 
                                    $row['branch'], 
                                    $row['upi_id'], 
                                    $opening_balance
                                );
                                
                                if ($stmt->execute()) {
                                    $success_count++;
                                    
                                    // Log activity for each successful import
                                    $log_desc = "Imported supplier: " . $row['supplier_name'];
                                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'import', ?)";
                                    $log_stmt = $conn->prepare($log_query);
                                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                                    $log_stmt->execute();
                                    $log_stmt->close();
                                } else {
                                    $import_errors[] = "Row $row_number: Failed to import - " . $conn->error;
                                    $error_count++;
                                }
                                $stmt->close();
                            }
                            
                            fclose($handle);
                            
                            if ($error_count == 0) {
                                mysqli_commit($conn);
                                $success = "Bulk import completed successfully! Imported $success_count suppliers.";
                            } else {
                                // Rollback if there are errors
                                mysqli_rollback($conn);
                                $error = "Import completed with errors. Successful: $success_count, Failed: $error_count.<br>";
                                $error .= implode("<br>", array_slice($import_errors, 0, 10)); // Show first 10 errors
                                if (count($import_errors) > 10) {
                                    $error .= "<br>... and " . (count($import_errors) - 10) . " more errors.";
                                }
                            }
                            
                        } catch (Exception $e) {
                            mysqli_rollback($conn);
                            $error = "Import failed: " . $e->getMessage();
                        }
                    }
                } else {
                    $error = "Failed to open the uploaded file.";
                }
            }
        } else {
            $error = "Please select a CSV file to upload.";
        }
    }
}

// ==================== EXPORT SAMPLE CSV ====================
if (isset($_GET['export']) && $_GET['export'] === 'sample_csv') {
    // Sample data
    $sample_data = [
        ['supplier_name', 'phone', 'email', 'address', 'gst_number', 'bank_name', 'account_number', 'ifsc_code', 'branch', 'upi_id', 'opening_balance'],
        ['Sample Supplier 1', '9876543210', 'supplier1@example.com', '123 Industrial Area, City', '27AAPFU0939F1ZV', 'State Bank of India', '12345678901', 'SBIN0001234', 'Main Branch', 'supplier1@okhdfcbank', '5000.00'],
        ['Sample Supplier 2', '9876543211', 'supplier2@example.com', '456 Factory Road, Town', '33BFTPS8180L1ZI', 'HDFC Bank', '98765432109', 'HDFC0004321', 'Industrial Area', 'supplier2@oksbi', '2500.50'],
        ['Sample Supplier 3', '9876543212', '', '789 Business Park, Village', '', 'ICICI Bank', '56789012345', 'ICIC0005678', 'Downtown', '', '0.00'],
    ];
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="supplier_import_sample.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Write data
    foreach ($sample_data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// ==================== EXPORT ALL SUPPLIERS AS CSV ====================
if (isset($_GET['export']) && $_GET['export'] === 'all_suppliers') {
    // Get all suppliers
    $result = $conn->query("SELECT supplier_name, phone, email, address, gst_number, bank_name, account_number, ifsc_code, branch, upi_id, opening_balance FROM suppliers ORDER BY supplier_name");
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="all_suppliers_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['supplier_name', 'phone', 'email', 'address', 'gst_number', 'bank_name', 'account_number', 'ifsc_code', 'branch', 'upi_id', 'opening_balance']);
    
    // Add data
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// Handle add supplier (POST only) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_supplier') {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to add suppliers.';
    } else {
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $ifsc_code = trim($_POST['ifsc_code'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $upi_id = trim($_POST['upi_id'] ?? '');
        $opening_balance = floatval($_POST['opening_balance'] ?? 0);

        if (empty($supplier_name)) {
            $error = 'Supplier name is required.';
        } else {
            // Check if supplier exists
            $check = $conn->prepare("SELECT id FROM suppliers WHERE supplier_name = ?");
            $check->bind_param("s", $supplier_name);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $error = 'Supplier already exists. Please choose a different name.';
            } else {
                $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, phone, email, address, gst_number, bank_name, account_number, ifsc_code, branch, upi_id, opening_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssssssd", $supplier_name, $phone, $email, $address, $gst_number, $bank_name, $account_number, $ifsc_code, $branch, $upi_id, $opening_balance);
                
                if ($stmt->execute()) {
                    $supplier_id = $stmt->insert_id;
                    
                    // Log activity
                    $log_desc = "Created new supplier: " . $supplier_name . " (Phone: " . ($phone ?: 'N/A') . ")";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $success = "Supplier added successfully.";
                } else {
                    $error = "Failed to add supplier.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle edit supplier (POST only) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_supplier' && isset($_POST['supplier_id']) && is_numeric($_POST['supplier_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to edit suppliers.';
    } else {
        $editId = intval($_POST['supplier_id']);
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $ifsc_code = trim($_POST['ifsc_code'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $upi_id = trim($_POST['upi_id'] ?? '');
        $opening_balance = floatval($_POST['opening_balance'] ?? 0);

        if (empty($supplier_name)) {
            $error = 'Supplier name is required.';
        } else {
            // Check if supplier name exists for other suppliers
            $check = $conn->prepare("SELECT id FROM suppliers WHERE supplier_name = ? AND id != ?");
            $check->bind_param("si", $supplier_name, $editId);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $error = 'Supplier name already exists. Please choose a different name.';
            } else {
                $stmt = $conn->prepare("UPDATE suppliers SET supplier_name=?, phone=?, email=?, address=?, gst_number=?, bank_name=?, account_number=?, ifsc_code=?, branch=?, upi_id=?, opening_balance=? WHERE id=?");
                $stmt->bind_param("ssssssssssdi", $supplier_name, $phone, $email, $address, $gst_number, $bank_name, $account_number, $ifsc_code, $branch, $upi_id, $opening_balance, $editId);
                
                if ($stmt->execute()) {
                    // Log activity
                    $log_desc = "Updated supplier: " . $supplier_name;
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $success = "Supplier updated successfully.";
                } else {
                    $error = "Failed to update supplier.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle delete supplier (POST only) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_supplier' && isset($_POST['supplier_id']) && is_numeric($_POST['supplier_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete suppliers.';
    } else {
        $deleteId = intval($_POST['supplier_id']);
        
        // Check if supplier is used in purchases
        $check_purchase = $conn->prepare("SELECT id FROM purchase WHERE supplier_id = ? LIMIT 1");
        $check_purchase->bind_param("i", $deleteId);
        $check_purchase->execute();
        $check_purchase->store_result();
        
        if ($check_purchase->num_rows > 0) {
            $error = "Cannot delete supplier. They have purchase records.";
        } else {
            // Get supplier name for logging
            $sup_query = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $sup_query->bind_param("i", $deleteId);
            $sup_query->execute();
            $sup_result = $sup_query->get_result();
            $sup_data = $sup_result->fetch_assoc();
            $supplier_name = $sup_data['supplier_name'] ?? 'Unknown';
            
            $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->bind_param("i", $deleteId);
            
            if ($stmt->execute()) {
                // Log activity
                $log_desc = "Deleted supplier: " . $supplier_name;
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $success = "Supplier deleted successfully.";
            } else {
                $error = "Failed to delete supplier.";
            }
            $stmt->close();
        }
        $check_purchase->close();
    }
}

// Handle AJAX request for supplier details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_supplier' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $supplier = $result->fetch_assoc();
        echo json_encode(['success' => true, 'supplier' => $supplier]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Supplier not found']);
    }
    exit;
}

// Filters
$filterGST = isset($_GET['filter_gst']) ? $_GET['filter_gst'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where = "1=1";
$params = [];
$types = "";

if ($filterGST === 'with') {
    $where .= " AND gst_number IS NOT NULL AND gst_number != ''";
} elseif ($filterGST === 'without') {
    $where .= " AND (gst_number IS NULL OR gst_number = '')";
}

if (!empty($search)) {
    $where .= " AND (supplier_name LIKE ? OR phone LIKE ? OR email LIKE ? OR gst_number LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $types .= "ssss";
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as cnt FROM suppliers WHERE $where";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$totalCount = $count_stmt->get_result()->fetch_assoc()['cnt'];

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$total_pages = ceil($totalCount / $limit);

// Get suppliers
$sql = "SELECT * FROM suppliers WHERE $where ORDER BY supplier_name ASC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types . "ii", ...array_merge($params, [$limit, $offset]));
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$suppliers = $stmt->get_result();

// Stats
$totalSuppliers = $conn->query("SELECT COUNT(*) as cnt FROM suppliers")->fetch_assoc()['cnt'];
$withGSTCount = $conn->query("SELECT COUNT(*) as cnt FROM suppliers WHERE gst_number IS NOT NULL AND gst_number != ''")->fetch_assoc()['cnt'];
$withoutGSTCount = $totalSuppliers - $withGSTCount;
$withBankCount = $conn->query("SELECT COUNT(*) as cnt FROM suppliers WHERE bank_name IS NOT NULL AND bank_name != ''")->fetch_assoc()['cnt'];
$totalBalance = $conn->query("SELECT SUM(opening_balance) as total FROM suppliers")->fetch_assoc()['total'];

// Purchase stats
$purchaseCount = $conn->query("SELECT COUNT(DISTINCT supplier_id) as cnt FROM purchase")->fetch_assoc()['cnt'];

// Check if user is admin for action buttons
$is_admin = ($_SESSION['user_role'] === 'admin');

// Helper function to format GST number with mask
function formatGST($gst) {
    if (empty($gst)) return '-';
    // Simple mask - show first 2 and last 2 characters
    if (strlen($gst) > 4) {
        return substr($gst, 0, 2) . '****' . substr($gst, -2);
    }
    return $gst;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
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
        
        .supplier-badge.gst {
            background: #f2e8ff;
            color: #8b5cf6;
            font-family: monospace;
        }
        
        .supplier-badge.bank {
            background: #f0fdf4;
            color: #16a34a;
        }
        
        .balance-positive {
            color: #10b981;
            font-weight: 600;
        }
        
        .balance-negative {
            color: #ef4444;
            font-weight: 600;
        }
        
        .stats-mini-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            border: 1px solid #eef2f6;
            height: 100%;
        }
        
        .stats-mini-value {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .stats-mini-label {
            font-size: 12px;
            color: #64748b;
        }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .contact-info small {
            color: #64748b;
            font-size: 11px;
        }
        
        .bank-detail {
            background: #f8fafc;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            color: #475569;
            display: inline-block;
        }
        
        .gst-chip {
            background: #f2e8ff;
            color: #8b5cf6;
            padding: 2px 8px;
            border-radius: 16px;
            font-size: 11px;
            font-family: monospace;
            display: inline-block;
        }
        
        .filter-tabs .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .search-box input {
            flex: 1;
            padding: 10px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
        }
        
        .search-box button {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
        }
        
        /* View Modal Styles */
        .info-grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .info-card-view {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
        }
        
        .info-title {
            font-size: 14px;
            font-weight: 600;
            color: #2563eb;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .info-label {
            width: 100px;
            color: #64748b;
            font-weight: 500;
        }
        
        .info-value {
            flex: 1;
            color: #1e293b;
            font-weight: 500;
        }
        
        .detail-chip {
            background: white;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .detail-chip i {
            color: #2563eb;
            margin-right: 4px;
        }
        
        .badge-gst {
            background: #f2e8ff;
            color: #8b5cf6;
            padding: 2px 8px;
            border-radius: 16px;
            font-size: 11px;
            font-family: monospace;
        }
        
        .badge-bank {
            background: #f0fdf4;
            color: #16a34a;
            padding: 2px 8px;
            border-radius: 16px;
            font-size: 11px;
        }

        /* Bulk Import Section Styles */
        .import-section {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .import-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 15px;
        }
        
        .import-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn-import {
            background: #10b981;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-import:hover {
            background: #059669;
            transform: translateY(-2px);
        }
        
        .btn-sample {
            background: #8b5cf6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-sample:hover {
            background: #7c3aed;
            transform: translateY(-2px);
            color: white;
        }
        
        .btn-export {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-export:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            color: white;
        }
        
        .file-input-wrapper {
            position: relative;
            display: inline-block;
        }
        
        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: -9999px;
            opacity: 0;
        }
        
        .file-input-label {
            background: #64748b;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .file-input-label:hover {
            background: #475569;
            transform: translateY(-2px);
        }
        
        #file-name {
            margin-left: 10px;
            font-size: 13px;
            color: #475569;
        }
        
        .import-errors {
            margin-top: 15px;
            padding: 15px;
            background: #fee2e2;
            border: 1px solid #ef4444;
            border-radius: 8px;
            color: #991b1b;
            font-size: 13px;
            max-height: 200px;
            overflow-y: auto;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Suppliers Management</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage your suppliers, their contact details, and banking information</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($is_admin): ?>
                        <a href="?export=all_suppliers" class="btn-export">
                            <i class="bi bi-download"></i> Export All
                        </a>
                        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addSupplierModal" data-testid="button-add-supplier">
                            <i class="bi bi-plus-circle"></i> Add New
                        </button>
                    <?php else: ?>
                        <span class="permission-badge"><i class="bi bi-eye"></i> View Only Mode</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Bulk Import Section (only visible to admin) -->
            <?php if ($is_admin): ?>
            <div class="import-section">
                <div class="import-title">
                    <i class="bi bi-cloud-upload me-2"></i>Bulk Import Suppliers
                </div>
                <form method="POST" action="suppliers.php" enctype="multipart/form-data" id="bulkImportForm">
                    <input type="hidden" name="action" value="bulk_import">
                    <div class="import-buttons">
                        <a href="?export=sample_csv" class="btn-sample">
                            <i class="bi bi-file-earmark-spreadsheet"></i> Download Sample CSV
                        </a>
                        <div class="file-input-wrapper">
                            <label for="csv_file" class="file-input-label">
                                <i class="bi bi-folder2-open"></i> Choose CSV File
                            </label>
                            <input type="file" name="csv_file" id="csv_file" accept=".csv" required onchange="updateFileName(this)">
                        </div>
                        <span id="file-name">No file chosen</span>
                        <button type="submit" class="btn-import" onclick="return confirm('Are you sure you want to import suppliers from this CSV?')">
                            <i class="bi bi-upload"></i> Import Suppliers
                        </button>
                    </div>
                </form>
                <div class="mt-3 text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    CSV must have headers: supplier_name, phone, email, address, gst_number, bank_name, account_number, ifsc_code, branch, upi_id, opening_balance
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-total">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon blue">
                                <i class="bi bi-truck"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Total Suppliers</div>
                                <div class="stat-value" data-testid="stat-value-total"><?php echo $totalSuppliers; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-with-gst">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon purple">
                                <i class="bi bi-upc-scan"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">With GST</div>
                                <div class="stat-value" data-testid="stat-value-gst"><?php echo $withGSTCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-with-bank">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon green">
                                <i class="bi bi-bank"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">With Bank Details</div>
                                <div class="stat-value" data-testid="stat-value-bank"><?php echo $withBankCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-purchases">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon orange">
                                <i class="bi bi-cart-check"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Active in Purchases</div>
                                <div class="stat-value" data-testid="stat-value-purchases"><?php echo $purchaseCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Row 2 - Mini Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stats-mini-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stats-mini-value"><?php echo $totalSuppliers > 0 ? round(($withGSTCount/$totalSuppliers)*100, 1) : 0; ?>%</div>
                                <div class="stats-mini-label">Suppliers with GST</div>
                            </div>
                            <div>
                                <div class="progress" style="width: 150px; height: 8px;">
                                    <div class="progress-bar bg-purple" style="width: <?php echo $totalSuppliers > 0 ? ($withGSTCount/$totalSuppliers)*100 : 0; ?>%; background: #8b5cf6;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-mini-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stats-mini-value"><?php echo $totalBalance ? '₹' . number_format($totalBalance, 2) : '₹0.00'; ?></div>
                                <div class="stats-mini-label">Total Opening Balance</div>
                            </div>
                            <div class="text-end">
                                <div class="stats-mini-value"><?php echo $totalSuppliers; ?></div>
                                <div class="stats-mini-label">Suppliers</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-mini-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stats-mini-value"><?php echo $withoutGSTCount; ?></div>
                                <div class="stats-mini-label">Without GST</div>
                            </div>
                            <div>
                                <a href="?filter_gst=without" class="btn btn-sm btn-outline-secondary">View</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter and Search Bar -->
            <div class="dashboard-card mb-4">
                <div class="card-body py-3">
                    <!-- Filter Tabs -->
                    <div class="d-flex align-items-center gap-3 flex-wrap filter-bar-inner mb-3">
                        <div class="d-flex gap-1 flex-wrap filter-tabs">
                            <a href="suppliers.php" class="btn btn-sm <?php echo empty($filterGST) && empty($search) ? 'btn-primary' : 'btn-outline-secondary'; ?>" data-testid="filter-all">
                                All <span class="badge bg-white text-dark ms-1"><?php echo $totalSuppliers; ?></span>
                            </a>
                            <a href="?filter_gst=with" class="btn btn-sm <?php echo $filterGST === 'with' ? 'btn-purple' : 'btn-outline-secondary'; ?>" data-testid="filter-with-gst" style="<?php echo $filterGST === 'with' ? 'background: #8b5cf6; color: white;' : ''; ?>">
                                With GST <span class="badge bg-white text-dark ms-1"><?php echo $withGSTCount; ?></span>
                            </a>
                            <a href="?filter_gst=without" class="btn btn-sm <?php echo $filterGST === 'without' ? 'btn-warning' : 'btn-outline-secondary'; ?>" data-testid="filter-without-gst">
                                Without GST <span class="badge bg-white text-dark ms-1"><?php echo $withoutGSTCount; ?></span>
                            </a>
                        </div>
                        <div class="ms-auto">
                            <?php if (!empty($filterGST) || !empty($search)): ?>
                                <a href="suppliers.php" class="btn btn-sm btn-outline-secondary" data-testid="clear-filters">
                                    <i class="bi bi-x-circle"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Search Box -->
                    <form method="GET" class="search-box">
                        <?php if (!empty($filterGST)): ?>
                            <input type="hidden" name="filter_gst" value="<?php echo htmlspecialchars($filterGST); ?>">
                        <?php endif; ?>
                        <input type="text" name="search" placeholder="Search by name, phone, email, or GST number..." 
                               value="<?php echo htmlspecialchars($search); ?>" data-testid="search-input">
                        <button type="submit" data-testid="search-button">
                            <i class="bi bi-search me-2"></i>Search
                        </button>
                    </form>
                </div>
            </div>

            <!-- Suppliers Table -->
            <div class="dashboard-card" data-testid="suppliers-table">
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="suppliersTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Supplier Name</th>
                                <th>Contact Info</th>
                                <th>GST Number</th>
                                <th>Bank Details</th>
                                <th>Opening Balance</th>
                                <th>Created</th>
                                <th>Last Updated</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($suppliers && $suppliers->num_rows > 0): ?>
                                <?php while ($supplier = $suppliers->fetch_assoc()): 
                                    $balance_class = $supplier['opening_balance'] >= 0 ? 'balance-positive' : 'balance-negative';
                                ?>
                                    <tr data-testid="row-supplier-<?php echo $supplier['id']; ?>">
                                        <td><span class="order-id">#<?php echo $supplier['id']; ?></span></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($supplier['supplier_name']); ?></td>
                                        <td>
                                            <div class="contact-info">
                                                <?php if (!empty($supplier['phone'])): ?>
                                                    <span><i class="bi bi-telephone me-1" style="font-size: 11px;"></i> <?php echo htmlspecialchars($supplier['phone']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($supplier['email'])): ?>
                                                    <span><i class="bi bi-envelope me-1" style="font-size: 11px;"></i> <?php echo htmlspecialchars($supplier['email']); ?></span>
                                                <?php endif; ?>
                                                <?php if (empty($supplier['phone']) && empty($supplier['email'])): ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($supplier['gst_number'])): ?>
                                                <span class="gst-chip">
                                                    <i class="bi bi-upc-scan me-1"></i>
                                                    <?php echo formatGST($supplier['gst_number']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($supplier['bank_name']) || !empty($supplier['upi_id'])): ?>
                                                <div class="d-flex flex-column gap-1">
                                                    <?php if (!empty($supplier['bank_name'])): ?>
                                                        <span class="bank-detail">
                                                            <i class="bi bi-bank me-1"></i>
                                                            <?php echo htmlspecialchars($supplier['bank_name']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($supplier['upi_id'])): ?>
                                                        <span class="bank-detail">
                                                            <i class="bi bi-phone me-1"></i>
                                                            <?php echo htmlspecialchars($supplier['upi_id']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="<?php echo $balance_class; ?>">
                                            ₹<?php echo number_format($supplier['opening_balance'], 2); ?>
                                        </td>
                                        <td style="color: var(--text-muted); white-space: nowrap;"><?php echo date('M d, Y', strtotime($supplier['created_at'])); ?></td>
                                        <td style="color: var(--text-muted); white-space: nowrap;"><?php echo date('M d, Y', strtotime($supplier['updated_at'])); ?></td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <!-- View -->
                                                    <button class="btn btn-sm btn-outline-info" style="font-size: 12px; padding: 3px 8px;" 
                                                            onclick="viewSupplier(<?php echo $supplier['id']; ?>)"
                                                            data-testid="button-view-<?php echo $supplier['id']; ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <!-- Edit -->
                                                    <button class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 3px 8px;" 
                                                            data-bs-toggle="modal" data-bs-target="#editSupplierModal<?php echo $supplier['id']; ?>" 
                                                            data-testid="button-edit-<?php echo $supplier['id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    
                                                    <!-- Delete -->
                                                    <form method="POST" action="suppliers.php<?php echo buildQueryString(); ?>" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this supplier? This will fail if they have purchase records.')">
                                                        <input type="hidden" name="action" value="delete_supplier">
                                                        <input type="hidden" name="supplier_id" value="<?php echo $supplier['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" 
                                                                data-testid="button-delete-<?php echo $supplier['id']; ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Edit Supplier Modal -->
                                    <div class="modal fade" id="editSupplierModal<?php echo $supplier['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <form method="POST" action="suppliers.php<?php echo buildQueryString(); ?>" data-testid="form-edit-supplier-<?php echo $supplier['id']; ?>">
                                                    <input type="hidden" name="action" value="edit_supplier">
                                                    <input type="hidden" name="supplier_id" value="<?php echo $supplier['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit Supplier</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <!-- Basic Information -->
                                                        <h6 class="mb-3" style="color: var(--primary);">Basic Information</h6>
                                                        <div class="row g-3 mb-4">
                                                            <div class="col-md-6">
                                                                <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                                                <input type="text" name="supplier_name" class="form-control" value="<?php echo htmlspecialchars($supplier['supplier_name']); ?>" required data-testid="input-edit-name-<?php echo $supplier['id']; ?>">
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Phone</label>
                                                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($supplier['phone']); ?>" data-testid="input-edit-phone-<?php echo $supplier['id']; ?>">
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Email</label>
                                                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($supplier['email']); ?>" data-testid="input-edit-email-<?php echo $supplier['id']; ?>">
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">GST Number</label>
                                                                <input type="text" name="gst_number" class="form-control" value="<?php echo htmlspecialchars($supplier['gst_number']); ?>" placeholder="22AAAAA0000A1Z5" data-testid="input-edit-gst-<?php echo $supplier['id']; ?>">
                                                            </div>
                                                            <div class="col-12">
                                                                <label class="form-label">Address</label>
                                                                <textarea name="address" class="form-control" rows="2" data-testid="input-edit-address-<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['address']); ?></textarea>
                                                            </div>
                                                        </div>

                                                        <!-- Bank Details -->
                                                        <h6 class="mb-3" style="color: var(--primary);">Bank Details</h6>
                                                        <div class="row g-3 mb-4">
                                                            <div class="col-md-6">
                                                                <label class="form-label">Bank Name</label>
                                                                <input type="text" name="bank_name" class="form-control" value="<?php echo htmlspecialchars($supplier['bank_name']); ?>" data-testid="input-edit-bank-<?php echo $supplier['id']; ?>">
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Account Number</label>
                                                                <input type="text" name="account_number" class="form-control" value="<?php echo htmlspecialchars($supplier['account_number']); ?>" data-testid="input-edit-account-<?php echo $supplier['id']; ?>">
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label">IFSC Code</label>
                                                                <input type="text" name="ifsc_code" class="form-control" value="<?php echo htmlspecialchars($supplier['ifsc_code']); ?>" data-testid="input-edit-ifsc-<?php echo $supplier['id']; ?>">
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label">Branch</label>
                                                                <input type="text" name="branch" class="form-control" value="<?php echo htmlspecialchars($supplier['branch']); ?>" data-testid="input-edit-branch-<?php echo $supplier['id']; ?>">
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label">UPI ID</label>
                                                                <input type="text" name="upi_id" class="form-control" value="<?php echo htmlspecialchars($supplier['upi_id']); ?>" data-testid="input-edit-upi-<?php echo $supplier['id']; ?>">
                                                            </div>
                                                        </div>

                                                        <!-- Financial Information -->
                                                        <h6 class="mb-3" style="color: var(--primary);">Financial Information</h6>
                                                        <div class="row g-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label">Opening Balance (₹)</label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">₹</span>
                                                                    <input type="number" name="opening_balance" class="form-control" step="0.01" value="<?php echo htmlspecialchars($supplier['opening_balance']); ?>" data-testid="input-edit-balance-<?php echo $supplier['id']; ?>">
                                                                </div>
                                                                <small class="text-muted">Can be positive or negative</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary" data-testid="button-save-edit-<?php echo $supplier['id']; ?>">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $is_admin ? 9 : 8; ?>" class="text-center py-4 text-muted">
                                        No suppliers found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php
                        // Reset result pointer for mobile view
                        if ($suppliers && $suppliers->num_rows > 0) {
                            $suppliers->data_seek(0);
                        }
                    ?>
                    <?php if ($suppliers && $suppliers->num_rows > 0): ?>
                        <?php while ($mSupplier = $suppliers->fetch_assoc()): 
                            $balance_class = $mSupplier['opening_balance'] >= 0 ? 'balance-positive' : 'balance-negative';
                        ?>
                            <div class="mobile-card" data-testid="mobile-card-supplier-<?php echo $mSupplier['id']; ?>">
                                <div class="mobile-card-header">
                                    <div>
                                        <span class="order-id">#<?php echo $mSupplier['id']; ?></span>
                                        <span class="customer-name ms-2 fw-semibold"><?php echo htmlspecialchars($mSupplier['supplier_name']); ?></span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($mSupplier['phone']) || !empty($mSupplier['email'])): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Contact</span>
                                    <span class="mobile-card-value">
                                        <?php if (!empty($mSupplier['phone'])): ?>
                                            <div><i class="bi bi-telephone me-1"></i> <?php echo htmlspecialchars($mSupplier['phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($mSupplier['email'])): ?>
                                            <div><i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($mSupplier['email']); ?></div>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($mSupplier['gst_number'])): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">GST</span>
                                    <span class="mobile-card-value">
                                        <span class="gst-chip"><?php echo formatGST($mSupplier['gst_number']); ?></span>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($mSupplier['address'])): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Address</span>
                                    <span class="mobile-card-value"><?php echo htmlspecialchars($mSupplier['address']); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($mSupplier['bank_name']) || !empty($mSupplier['upi_id'])): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Bank</span>
                                    <span class="mobile-card-value">
                                        <?php if (!empty($mSupplier['bank_name'])): ?>
                                            <div><i class="bi bi-bank me-1"></i> <?php echo htmlspecialchars($mSupplier['bank_name']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($mSupplier['upi_id'])): ?>
                                            <div><i class="bi bi-phone me-1"></i> <?php echo htmlspecialchars($mSupplier['upi_id']); ?></div>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Opening Balance</span>
                                    <span class="mobile-card-value <?php echo $balance_class; ?>">
                                        ₹<?php echo number_format($mSupplier['opening_balance'], 2); ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Added</span>
                                    <span class="mobile-card-value"><?php echo date('M d, Y', strtotime($mSupplier['created_at'])); ?></span>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                    <div class="mobile-card-actions">
                                        <button class="btn btn-sm btn-outline-info flex-fill" onclick="viewSupplier(<?php echo $mSupplier['id']; ?>)">
                                            <i class="bi bi-eye me-1"></i>View
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary flex-fill" data-bs-toggle="modal" data-bs-target="#editSupplierModal<?php echo $mSupplier['id']; ?>">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
                                        <form method="POST" action="suppliers.php<?php echo buildQueryString(); ?>" style="flex: 1;" 
                                              onsubmit="return confirm('Delete this supplier permanently? This will fail if they have purchase records.')">
                                            <input type="hidden" name="action" value="delete_supplier">
                                            <input type="hidden" name="supplier_id" value="<?php echo $mSupplier['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                <i class="bi bi-trash me-1"></i>Delete
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-truck d-block mb-2" style="font-size: 36px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No suppliers found</div>
                            <div style="font-size: 13px;">
                                <?php if (!empty($filterGST) || !empty($search)): ?>
                                    Try changing your filters or <a href="suppliers.php">view all suppliers</a>
                                <?php elseif ($is_admin): ?>
                                    <a href="#" data-bs-toggle="modal" data-bs-target="#addSupplierModal">Add your first supplier</a> to get started
                                <?php else: ?>
                                    No suppliers available
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination" style="padding: 20px 24px; justify-content: center;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?><?php echo !empty($filterGST) ? '&filter_gst='.$filterGST : ''; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>" 
                               class="page-link <?php echo $page == $i ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="suppliers.php<?php echo buildQueryString(); ?>" data-testid="form-add-supplier">
                <input type="hidden" name="action" value="add_supplier">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Basic Information -->
                    <h6 class="mb-3" style="color: var(--primary);">Basic Information</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                            <input type="text" name="supplier_name" class="form-control" required placeholder="Enter supplier name" data-testid="input-add-name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="Phone number" data-testid="input-add-phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="Email address" data-testid="input-add-email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">GST Number</label>
                            <input type="text" name="gst_number" class="form-control" placeholder="22AAAAA0000A1Z5" data-testid="input-add-gst">
                            <small class="text-muted">15 characters GSTIN format</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="Full address" data-testid="input-add-address"></textarea>
                        </div>
                    </div>

                    <!-- Bank Details -->
                    <h6 class="mb-3" style="color: var(--primary);">Bank Details</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="Bank name" data-testid="input-add-bank">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" class="form-control" placeholder="Account number" data-testid="input-add-account">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IFSC Code</label>
                            <input type="text" name="ifsc_code" class="form-control" placeholder="IFSC code" data-testid="input-add-ifsc">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Branch</label>
                            <input type="text" name="branch" class="form-control" placeholder="Branch name" data-testid="input-add-branch">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">UPI ID</label>
                            <input type="text" name="upi_id" class="form-control" placeholder="UPI ID" data-testid="input-add-upi">
                        </div>
                    </div>

                    <!-- Financial Information -->
                    <h6 class="mb-3" style="color: var(--primary);">Financial Information</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Opening Balance (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="opening_balance" class="form-control" step="0.01" value="0.00" data-testid="input-add-balance">
                            </div>
                            <small class="text-muted">Initial balance (positive or negative)</small>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3 mb-0" style="font-size: 12px;">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Note:</strong> Bank details and GST number are optional but recommended for purchase transactions.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-testid="button-submit-add-supplier">Add Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Supplier Modal -->
<div class="modal fade" id="viewSupplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-truck me-2" style="color: #2563eb;"></i>
                    Supplier Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewSupplierContent">
                <!-- Content loaded via AJAX -->
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading supplier details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    // Initialize any tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
});

// Function to update file name display
function updateFileName(input) {
    var fileName = input.files[0] ? input.files[0].name : 'No file chosen';
    document.getElementById('file-name').textContent = fileName;
}

// View supplier details
function viewSupplier(id) {
    // Show the modal with loading spinner
    $('#viewSupplierModal').modal('show');
    
    // Make AJAX request to get supplier details
    $.ajax({
        url: 'suppliers.php?ajax=get_supplier&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let s = response.supplier;
                let balanceClass = parseFloat(s.opening_balance) >= 0 ? 'balance-positive' : 'balance-negative';
                
                // Format the HTML for display
                let html = `
                    <div class="info-grid-view">
                        <!-- Basic Information Card -->
                        <div class="info-card-view">
                            <div class="info-title">
                                <i class="bi bi-info-circle me-2"></i>Basic Information
                            </div>
                            <div class="info-row">
                                <span class="info-label">Name:</span>
                                <span class="info-value">${escapeHtml(s.supplier_name)}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone:</span>
                                <span class="info-value">
                                    ${s.phone ? 
                                        `<span class="detail-chip"><i class="bi bi-telephone"></i> ${escapeHtml(s.phone)}</span>` : 
                                        '<span class="text-muted">-</span>'}
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email:</span>
                                <span class="info-value">
                                    ${s.email ? 
                                        `<span class="detail-chip"><i class="bi bi-envelope"></i> ${escapeHtml(s.email)}</span>` : 
                                        '<span class="text-muted">-</span>'}
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">GST No.:</span>
                                <span class="info-value">
                                    ${s.gst_number ? 
                                        `<span class="badge-gst"><i class="bi bi-upc-scan"></i> ${escapeHtml(s.gst_number)}</span>` : 
                                        '<span class="text-muted">-</span>'}
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Address:</span>
                                <span class="info-value">${s.address ? escapeHtml(s.address) : '-'}</span>
                            </div>
                        </div>
                        
                        <!-- Bank Details Card -->
                        <div class="info-card-view">
                            <div class="info-title">
                                <i class="bi bi-bank me-2"></i>Bank Details
                            </div>
                            <div class="info-row">
                                <span class="info-label">Bank Name:</span>
                                <span class="info-value">${s.bank_name ? escapeHtml(s.bank_name) : '-'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Account No.:</span>
                                <span class="info-value">${s.account_number ? escapeHtml(s.account_number) : '-'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">IFSC Code:</span>
                                <span class="info-value">${s.ifsc_code ? escapeHtml(s.ifsc_code) : '-'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Branch:</span>
                                <span class="info-value">${s.branch ? escapeHtml(s.branch) : '-'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">UPI ID:</span>
                                <span class="info-value">${s.upi_id ? escapeHtml(s.upi_id) : '-'}</span>
                            </div>
                        </div>
                        
                        <!-- Financial Information Card -->
                        <div class="info-card-view">
                            <div class="info-title">
                                <i class="bi bi-currency-rupee me-2"></i>Financial Information
                            </div>
                            <div class="info-row">
                                <span class="info-label">Opening Balance:</span>
                                <span class="info-value ${balanceClass}">
                                    <strong>₹${parseFloat(s.opening_balance).toFixed(2)}</strong>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Created:</span>
                                <span class="info-value">${new Date(s.created_at).toLocaleDateString()} at ${new Date(s.created_at).toLocaleTimeString()}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Last Updated:</span>
                                <span class="info-value">${new Date(s.updated_at).toLocaleDateString()} at ${new Date(s.updated_at).toLocaleTimeString()}</span>
                            </div>
                        </div>
                    </div>
                `;
                $('#viewSupplierContent').html(html);
            } else {
                $('#viewSupplierContent').html(`
                    <div class="alert alert-danger m-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Failed to load supplier details.
                    </div>
                `);
            }
        },
        error: function() {
            $('#viewSupplierContent').html(`
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    An error occurred while loading supplier details.
                </div>
            `);
        }
    });
}

// Helper function to build query string with current filters
function buildQueryString() {
    let params = new URLSearchParams(window.location.search);
    let str = params.toString();
    return str ? '?' + str : '';
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>
<?php
// Helper function to build query string (duplicated for PHP side)
function buildQueryString() {
    $params = $_GET;
    return count($params) ? '?' . http_build_query($params) : '';
}
?>