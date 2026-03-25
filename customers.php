<?php
session_start();
$currentPage = 'customers';
$pageTitle = 'Customers Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view customers, but only admin can modify
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// ==================== BULK IMPORT FUNCTIONALITY ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_import') {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to import customers.';
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
                    $expected_headers = ['customer_name', 'phone', 'email', 'address', 'shipping_address', 'gst_number', 'opening_balance'];
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
                                if (empty($row['customer_name'])) {
                                    $import_errors[] = "Row $row_number: Customer name is required";
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
                                
                                // Check if customer already exists (optional - you can remove this if not needed)
                                $check = $conn->prepare("SELECT id FROM customers WHERE customer_name = ? AND phone = ?");
                                $check->bind_param("ss", $row['customer_name'], $row['phone']);
                                $check->execute();
                                $check->store_result();
                                
                                if ($check->num_rows > 0) {
                                    $import_errors[] = "Row $row_number: Customer already exists with this name and phone";
                                    $error_count++;
                                    $check->close();
                                    continue;
                                }
                                $check->close();
                                
                                // Insert customer
                                $stmt = $conn->prepare("INSERT INTO customers (customer_name, phone, email, address, shipping_address, gst_number, opening_balance) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmt->bind_param("ssssssd", 
                                    $row['customer_name'], 
                                    $row['phone'], 
                                    $row['email'], 
                                    $row['address'], 
                                    $row['shipping_address'], 
                                    $row['gst_number'], 
                                    $opening_balance
                                );
                                
                                if ($stmt->execute()) {
                                    $success_count++;
                                    
                                    // Log activity for each successful import (optional - can be commented out for performance)
                                    $log_desc = "Imported customer: " . $row['customer_name'];
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
                                $success = "Bulk import completed successfully! Imported $success_count customers.";
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
        ['customer_name', 'phone', 'email', 'address', 'shipping_address', 'gst_number', 'opening_balance'],
        ['Sample Customer 1', '9876543210', 'customer1@example.com', '123 Main St, City', '456 Shipping St, City', '27AAPFU0939F1ZV', '1000.00'],
        ['Sample Customer 2', '9876543211', 'customer2@example.com', '789 Oak Ave, Town', '', '33BFTPS8180L1ZI', '500.50'],
        ['Sample Customer 3', '9876543212', '', '456 Pine Rd, Village', '456 Pine Rd, Village', '', '0.00'],
    ];
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="customer_import_sample.csv"');
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

// ==================== EXPORT ALL CUSTOMERS AS CSV ====================
if (isset($_GET['export']) && $_GET['export'] === 'all_customers') {
    // Get all customers
    $result = $conn->query("SELECT customer_name, phone, email, address, shipping_address, gst_number, opening_balance FROM customers ORDER BY customer_name");
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="all_customers_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['customer_name', 'phone', 'email', 'address', 'shipping_address', 'gst_number', 'opening_balance']);
    
    // Add data
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// Handle add customer (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer') {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to add customers.';
    } else {
        $customer_name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $shipping_address = trim($_POST['shipping_address'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $opening_balance = floatval($_POST['opening_balance'] ?? 0);

        if (empty($customer_name)) {
            $error = 'Customer name is required.';
        } else {
            // Check if customer exists
            $check = $conn->prepare("SELECT id FROM customers WHERE customer_name = ? AND phone = ?");
            $check->bind_param("ss", $customer_name, $phone);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $error = 'Customer already exists with this name and phone.';
            } else {
                // Updated query with shipping_address
                $stmt = $conn->prepare("INSERT INTO customers (customer_name, phone, email, address, shipping_address, gst_number, opening_balance) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssd", $customer_name, $phone, $email, $address, $shipping_address, $gst_number, $opening_balance);
                
                if ($stmt->execute()) {
                    $customer_id = $stmt->insert_id;
                    
                    // Log activity
                    $log_desc = "Added new customer: " . $customer_name . " (Phone: " . ($phone ?: 'N/A') . ")";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $success = "Customer added successfully.";
                } else {
                    $error = "Failed to add customer.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle edit customer (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_customer' && isset($_POST['customer_id']) && is_numeric($_POST['customer_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to edit customers.';
    } else {
        $editId = intval($_POST['customer_id']);
        $customer_name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $shipping_address = trim($_POST['shipping_address'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $opening_balance = floatval($_POST['opening_balance'] ?? 0);

        if (empty($customer_name)) {
            $error = 'Customer name is required.';
        } else {
            // Check if customer exists for other customers
            $check = $conn->prepare("SELECT id FROM customers WHERE customer_name = ? AND phone = ? AND id != ?");
            $check->bind_param("ssi", $customer_name, $phone, $editId);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $error = 'Customer already exists with this name and phone.';
            } else {
                // Updated query with shipping_address
                $stmt = $conn->prepare("UPDATE customers SET customer_name=?, phone=?, email=?, address=?, shipping_address=?, gst_number=?, opening_balance=? WHERE id=?");
                $stmt->bind_param("ssssssdi", $customer_name, $phone, $email, $address, $shipping_address, $gst_number, $opening_balance, $editId);
                
                if ($stmt->execute()) {
                    // Log activity
                    $log_desc = "Updated customer: " . $customer_name . " (ID: " . $editId . ")";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $success = "Customer updated successfully.";
                } else {
                    $error = "Failed to update customer.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle delete customer (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_customer' && isset($_POST['customer_id']) && is_numeric($_POST['customer_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete customers.';
    } else {
        $deleteId = intval($_POST['customer_id']);
        
        // Check if customer has invoices
        $check_invoices = $conn->prepare("SELECT id FROM invoice WHERE customer_id = ? LIMIT 1");
        $check_invoices->bind_param("i", $deleteId);
        $check_invoices->execute();
        $check_invoices->store_result();
        
        if ($check_invoices->num_rows > 0) {
            $error = "Cannot delete customer. They have existing invoices.";
        } else {
            // Get customer name for logging
            $cust_query = $conn->prepare("SELECT customer_name FROM customers WHERE id = ?");
            $cust_query->bind_param("i", $deleteId);
            $cust_query->execute();
            $cust_result = $cust_query->get_result();
            $cust_data = $cust_result->fetch_assoc();
            $customer_name = $cust_data['customer_name'] ?? 'Unknown';
            
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->bind_param("i", $deleteId);
            
            if ($stmt->execute()) {
                // Log activity
                $log_desc = "Deleted customer: " . $customer_name . " (ID: " . $deleteId . ")";
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $success = "Customer deleted successfully.";
            } else {
                $error = "Failed to delete customer.";
            }
            $stmt->close();
        }
        $check_invoices->close();
    }
}

// Handle AJAX request for customer details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_customer' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Get customer details with invoice summary - updated to include shipping_address
    $stmt = $conn->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM invoice WHERE customer_id = c.id) as invoice_count,
               (SELECT COALESCE(SUM(total), 0) FROM invoice WHERE customer_id = c.id) as total_purchases,
               (SELECT COALESCE(SUM(pending_amount), 0) FROM invoice WHERE customer_id = c.id AND pending_amount > 0) as pending_amount
        FROM customers c 
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $customer = $result->fetch_assoc();
        
        // Get recent invoices for this customer
        $inv_stmt = $conn->prepare("
            SELECT inv_num, created_at, total, pending_amount, payment_method 
            FROM invoice 
            WHERE customer_id = ? 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $inv_stmt->bind_param("i", $id);
        $inv_stmt->execute();
        $invoices = $inv_stmt->get_result();
        
        $invoice_list = [];
        while ($inv = $invoices->fetch_assoc()) {
            $invoice_list[] = $inv;
        }
        
        echo json_encode([
            'success' => true, 
            'customer' => $customer,
            'invoices' => $invoice_list
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
    }
    exit;
}

// Filters
$filterStatus = $_GET['filter_status'] ?? '';
$filterSearch = $_GET['search'] ?? '';

$where = "1=1";
$params = [];
$types = "";

if (!empty($filterSearch)) {
    $where .= " AND (customer_name LIKE ? OR phone LIKE ? OR email LIKE ? OR gst_number LIKE ?)";
    $searchTerm = "%$filterSearch%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ssss";
}

$sql = "SELECT c.*, 
        (SELECT COUNT(*) FROM invoice WHERE customer_id = c.id) as invoice_count,
        (SELECT COALESCE(SUM(total), 0) FROM invoice WHERE customer_id = c.id) as total_purchases,
        (SELECT COALESCE(SUM(pending_amount), 0) FROM invoice WHERE customer_id = c.id AND pending_amount > 0) as pending_amount
        FROM customers c 
        WHERE $where 
        ORDER BY c.customer_name ASC";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $customers = $stmt->get_result();
} else {
    $customers = $conn->query($sql);
}

// Stats
$totalCustomers = $conn->query("SELECT COUNT(*) as cnt FROM customers")->fetch_assoc()['cnt'];
$totalWithPhone = $conn->query("SELECT COUNT(*) as cnt FROM customers WHERE phone IS NOT NULL AND phone != ''")->fetch_assoc()['cnt'];
$totalWithEmail = $conn->query("SELECT COUNT(*) as cnt FROM customers WHERE email IS NOT NULL AND email != ''")->fetch_assoc()['cnt'];
$totalWithGST = $conn->query("SELECT COUNT(*) as cnt FROM customers WHERE gst_number IS NOT NULL AND gst_number != ''")->fetch_assoc()['cnt'];

// Customer with highest purchases
$topCustomer = $conn->query("SELECT c.customer_name, COUNT(i.id) as invoice_count, COALESCE(SUM(i.total), 0) as total_spent 
                             FROM customers c 
                             LEFT JOIN invoice i ON c.id = i.customer_id 
                             GROUP BY c.id 
                             ORDER BY total_spent DESC 
                             LIMIT 1")->fetch_assoc();

// Total pending amount from all customers
$totalPending = $conn->query("SELECT COALESCE(SUM(pending_amount), 0) as total FROM invoice WHERE pending_amount > 0")->fetch_assoc()['total'];

// Format helpers
function formatPhone($phone) {
    if (empty($phone)) return '-';
    // Format Indian phone numbers if needed
    if (strlen($phone) == 10) {
        return substr($phone, 0, 5) . ' ' . substr($phone, 5);
    }
    return $phone;
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

// Check if user is admin for action buttons
$is_admin = ($_SESSION['user_role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }
        
        .customer-avatar.small {
            width: 32px;
            height: 32px;
            font-size: 14px;
        }
        
        .customer-info-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .customer-name-text {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        
        .customer-contact-text {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .contact-badge {
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            color: #475569;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .contact-badge i {
            font-size: 10px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card-custom {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        
        .stat-card-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .stat-value-large {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }
        
        .gst-badge {
            background: #f0fdf4;
            color: #16a34a;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .pending-badge {
            background: #fee2e2;
            color: #dc2626;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
            border: 1px solid #e2e8f0;
            color: #475569;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .filter-tab:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        
        .filter-tab.active {
            background: #2463eb;
            border-color: #2463eb;
            color: white;
        }
        
        .filter-tab.active .badge {
            background: white;
            color: #2463eb;
        }
        
        .quick-stats {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            border: 1px solid #eef2f6;
        }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        /* Action button styles */
        .action-btn-payment {
            background: #10b981;
            color: white;
            border: none;
        }
        
        .action-btn-payment:hover {
            background: #059669;
            color: white;
        }
        
        /* Payment History Button */
        .payment-history-btn {
            background: #8b5cf6;
            color: white;
            border: none;
        }
        
        .payment-history-btn:hover {
            background: #7c3aed;
            color: white;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Customers Management</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage your customer database and track their purchases</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($is_admin): ?>
                        <a href="?export=all_customers" class="btn-export">
                            <i class="bi bi-download"></i> Export All
                        </a>
                        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addCustomerModal" data-testid="button-add-customer">
                            <i class="bi bi-person-plus"></i> Add New
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
                    <i class="bi bi-cloud-upload me-2"></i>Bulk Import Customers
                </div>
                <form method="POST" action="customers.php" enctype="multipart/form-data" id="bulkImportForm">
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
                        <button type="submit" class="btn-import" onclick="return confirm('Are you sure you want to import customers from this CSV?')">
                            <i class="bi bi-upload"></i> Import Customers
                        </button>
                    </div>
                </form>
                <div class="mt-3 text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    CSV must have headers: customer_name, phone, email, address, shipping_address, gst_number, opening_balance
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo $totalCustomers; ?></div>
                            <div class="stat-label">Total Customers</div>
                        </div>
                        <div class="stat-icon blue" style="width: 48px; height: 48px;">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo $totalWithPhone; ?></div>
                            <div class="stat-label">With Phone Number</div>
                        </div>
                        <div class="stat-icon green" style="width: 48px; height: 48px;">
                            <i class="bi bi-telephone"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo $totalWithEmail; ?></div>
                            <div class="stat-label">With Email</div>
                        </div>
                        <div class="stat-icon purple" style="width: 48px; height: 48px;">
                            <i class="bi bi-envelope"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo $totalWithGST; ?></div>
                            <div class="stat-label">With GST Number</div>
                        </div>
                        <div class="stat-icon orange" style="width: 48px; height: 48px;">
                            <i class="bi bi-file-text"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats Row -->
            <div class="quick-stats">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon blue" style="width: 40px; height: 40px;">
                                <i class="bi bi-star"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">Top Customer</div>
                                <div><?php echo htmlspecialchars($topCustomer['customer_name'] ?? 'N/A'); ?></div>
                                <small class="text-muted"><?php echo $topCustomer['invoice_count'] ?? 0; ?> invoices • <?php echo formatCurrency($topCustomer['total_spent'] ?? 0); ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon orange" style="width: 40px; height: 40px;">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">Total Pending</div>
                                <div class="text-danger fw-bold"><?php echo formatCurrency($totalPending); ?></div>
                                <small class="text-muted">Across all customers</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon green" style="width: 40px; height: 40px;">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">Conversion Rate</div>
                                <div><?php echo $totalCustomers > 0 ? round(($totalWithPhone / $totalCustomers) * 100, 1) : 0; ?>%</div>
                                <small class="text-muted">Customers with phone</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="dashboard-card mb-4">
                <div class="card-body py-3">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="filter-tabs">
                                <a href="customers.php" class="filter-tab <?php echo !$filterStatus ? 'active' : ''; ?>">
                                    <i class="bi bi-people"></i> All Customers
                                    <span class="badge bg-white text-dark"><?php echo $totalCustomers; ?></span>
                                </a>
                                <a href="customers.php?filter_status=with_phone" class="filter-tab <?php echo $filterStatus === 'with_phone' ? 'active' : ''; ?>">
                                    <i class="bi bi-telephone"></i> With Phone
                                    <span class="badge bg-white text-dark"><?php echo $totalWithPhone; ?></span>
                                </a>
                                <a href="customers.php?filter_status=with_email" class="filter-tab <?php echo $filterStatus === 'with_email' ? 'active' : ''; ?>">
                                    <i class="bi bi-envelope"></i> With Email
                                    <span class="badge bg-white text-dark"><?php echo $totalWithEmail; ?></span>
                                </a>
                                <a href="customers.php?filter_status=with_gst" class="filter-tab <?php echo $filterStatus === 'with_gst' ? 'active' : ''; ?>">
                                    <i class="bi bi-file-text"></i> With GST
                                    <span class="badge bg-white text-dark"><?php echo $totalWithGST; ?></span>
                                </a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <form method="GET" action="customers.php" class="d-flex gap-2">
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search customers..." value="<?php echo htmlspecialchars($filterSearch); ?>">
                                </div>
                                <?php if ($filterSearch): ?>
                                    <a href="customers.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle"></i>
                                    </a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary">Search</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customers Table -->
            <div class="dashboard-card" data-testid="customers-table">
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="customersTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Contact</th>
                                <th>Opening Balance</th>
                                <th>Invoices</th>
                                <th>Total Purchases</th>
                                <th>Pending Amount</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($customers && $customers->num_rows > 0): ?>
                                <?php while ($customer = $customers->fetch_assoc()): 
                                    $initials = '';
                                    $name_parts = explode(' ', $customer['customer_name']);
                                    foreach ($name_parts as $part) {
                                        if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                                    }
                                    if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                                ?>
                                    <tr data-testid="row-customer-<?php echo $customer['id']; ?>">
                                        <td><span class="order-id">#<?php echo $customer['id']; ?></span></td>
                                        <td>
                                            <div class="customer-info-cell">
                                                <div class="customer-avatar small"><?php echo $initials; ?></div>
                                                <div>
                                                    <div class="customer-name-text"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                                                    <?php if (!empty($customer['address'])): ?>
                                                        <div class="customer-contact-text">
                                                            <i class="bi bi-geo-alt"></i>
                                                            <?php echo htmlspecialchars(substr($customer['address'], 0, 30)) . (strlen($customer['address']) > 30 ? '...' : ''); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($customer['phone'])): ?>
                                                <div class="contact-badge mb-1">
                                                    <i class="bi bi-telephone"></i>
                                                    <?php echo formatPhone($customer['phone']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($customer['email'])): ?>
                                                <div class="contact-badge">
                                                    <i class="bi bi-envelope"></i>
                                                    <?php echo htmlspecialchars($customer['email']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($customer['gst_number'])): ?>
                                                <div class="gst-badge">
                                                    <i class="bi bi-file-text"></i>
                                                    GST
                                                </div>
                                            <?php endif; ?>
                                            <?php if (empty($customer['phone']) && empty($customer['email'])): ?>
                                                <span class="text-muted">No contact</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold"><?php echo formatCurrency($customer['opening_balance']); ?></td>
                                        <td class="text-center"><?php echo $customer['invoice_count']; ?></td>
                                        <td class="fw-semibold"><?php echo formatCurrency($customer['total_purchases']); ?></td>
                                        <td>
                                            <?php if ($customer['pending_amount'] > 0): ?>
                                                <span class="pending-badge">
                                                    <i class="bi bi-exclamation-circle"></i>
                                                    <?php echo formatCurrency($customer['pending_amount']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-1" style="flex-wrap: wrap;">
                                                    <!-- View Customer -->
                                                    <button class="btn btn-sm btn-outline-info" style="font-size: 12px; padding: 3px 8px;" 
                                                            onclick="viewCustomer(<?php echo $customer['id']; ?>)"
                                                            title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <!-- Payment History Button -->
                                                    <a href="customer_payment_history.php?customer_id=<?php echo $customer['id']; ?>" 
                                                       class="btn btn-sm payment-history-btn" 
                                                       style="font-size: 12px; padding: 3px 8px; background: #8b5cf6; color: white;"
                                                       title="Payment History">
                                                        <i class="bi bi-cash-stack"></i>
                                                    </a>
                                                    
                                                    <!-- Edit Customer -->
                                                    <button class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 3px 8px;" 
                                                            data-bs-toggle="modal" data-bs-target="#editCustomerModal<?php echo $customer['id']; ?>" 
                                                            data-testid="button-edit-<?php echo $customer['id']; ?>"
                                                            title="Edit Customer">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    
                                                    <!-- Delete Customer -->
                                                    <?php if ($customer['invoice_count'] == 0): ?>
                                                        <form method="POST" action="customers.php<?php echo buildQueryString(['filter_status', 'search']); ?>" style="display: inline;" 
                                                              onsubmit="return confirm('Are you sure you want to delete this customer? This action cannot be undone.')">
                                                            <input type="hidden" name="action" value="delete_customer">
                                                            <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" 
                                                                    data-testid="button-delete-<?php echo $customer['id']; ?>"
                                                                    title="Delete Customer">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Edit Customer Modal -->
                                    <div class="modal fade" id="editCustomerModal<?php echo $customer['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <form method="POST" action="customers.php<?php echo buildQueryString(['filter_status', 'search']); ?>" data-testid="form-edit-customer-<?php echo $customer['id']; ?>">
                                                    <input type="hidden" name="action" value="edit_customer">
                                                    <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                                    
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="bi bi-pencil-square me-2"></i>
                                                            Edit Customer
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    
                                                    <div class="modal-body">
                                                        <div class="row g-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                                                <input type="text" name="customer_name" class="form-control" 
                                                                       value="<?php echo htmlspecialchars($customer['customer_name']); ?>" required>
                                                            </div>
                                                            
                                                            <div class="col-md-6">
                                                                <label class="form-label">Phone Number</label>
                                                                <input type="tel" name="phone" class="form-control" 
                                                                       value="<?php echo htmlspecialchars($customer['phone']); ?>" 
                                                                       placeholder="e.g., 9876543210">
                                                            </div>
                                                            
                                                            <div class="col-md-6">
                                                                <label class="form-label">Email Address</label>
                                                                <input type="email" name="email" class="form-control" 
                                                                       value="<?php echo htmlspecialchars($customer['email']); ?>" 
                                                                       placeholder="customer@example.com">
                                                            </div>
                                                            
                                                            <div class="col-md-6">
                                                                <label class="form-label">GST Number</label>
                                                                <input type="text" name="gst_number" class="form-control" 
                                                                       value="<?php echo htmlspecialchars($customer['gst_number']); ?>" 
                                                                       placeholder="e.g., 27AAPFU0939F1ZV">
                                                            </div>
                                                            
                                                            <div class="col-md-12">
                                                                <label class="form-label">Billing Address</label>
                                                                <textarea name="address" class="form-control" rows="2" 
                                                                          placeholder="Enter customer billing address"><?php echo htmlspecialchars($customer['address']); ?></textarea>
                                                            </div>
                                                            
                                                            <div class="col-md-12">
                                                                <label class="form-label">Shipping Address</label>
                                                                <textarea name="shipping_address" class="form-control" rows="2" 
                                                                          placeholder="Enter customer shipping address (if different from billing)"><?php echo htmlspecialchars($customer['shipping_address'] ?? ''); ?></textarea>
                                                                <small class="text-muted">Leave blank if same as billing address</small>
                                                            </div>
                                                            
                                                            <div class="col-md-6">
                                                                <label class="form-label">Opening Balance (₹)</label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">₹</span>
                                                                    <input type="number" name="opening_balance" class="form-control" 
                                                                           step="0.01" min="0" value="<?php echo $customer['opening_balance']; ?>">
                                                                </div>
                                                                <small class="text-muted">Initial balance if any</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if ($customers && $customers->num_rows > 0): ?>
                        <?php 
                        $customers->data_seek(0);
                        while ($customer = $customers->fetch_assoc()): 
                            $initials = '';
                            $name_parts = explode(' ', $customer['customer_name']);
                            foreach ($name_parts as $part) {
                                if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                            }
                            if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                        ?>
                            <div class="mobile-card" data-testid="mobile-card-customer-<?php echo $customer['id']; ?>">
                                <div class="mobile-card-header">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="customer-avatar small"><?php echo $initials; ?></div>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                                            <div style="font-size: 11px; color: var(--text-muted);">ID: #<?php echo $customer['id']; ?></div>
                                        </div>
                                    </div>
                                    <?php if ($customer['pending_amount'] > 0): ?>
                                        <span class="pending-badge">
                                            <i class="bi bi-exclamation-circle"></i>
                                            ₹<?php echo number_format($customer['pending_amount'], 0); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Contact</span>
                                    <span class="mobile-card-value">
                                        <?php if (!empty($customer['phone'])): ?>
                                            <div><i class="bi bi-telephone me-1"></i><?php echo formatPhone($customer['phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($customer['email'])): ?>
                                            <div><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($customer['email']); ?></div>
                                        <?php endif; ?>
                                        <?php if (empty($customer['phone']) && empty($customer['email'])): ?>
                                            No contact info
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Opening Balance</span>
                                    <span class="mobile-card-value"><?php echo formatCurrency($customer['opening_balance']); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Invoices</span>
                                    <span class="mobile-card-value"><?php echo $customer['invoice_count']; ?> invoices • <?php echo formatCurrency($customer['total_purchases']); ?></span>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                    <div class="mobile-card-actions">
                                        <button class="btn btn-sm btn-outline-info flex-fill" onclick="viewCustomer(<?php echo $customer['id']; ?>)">
                                            <i class="bi bi-eye me-1"></i>View
                                        </button>
                                        
                                        <a href="customer_payment_history.php?customer_id=<?php echo $customer['id']; ?>" 
                                           class="btn btn-sm payment-history-btn flex-fill" 
                                           style="background: #8b5cf6; color: white;">
                                            <i class="bi bi-cash-stack me-1"></i>Payments
                                        </a>
                                        
                                        <button class="btn btn-sm btn-outline-primary flex-fill" data-bs-toggle="modal" data-bs-target="#editCustomerModal<?php echo $customer['id']; ?>">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
                                        
                                        <?php if ($customer['invoice_count'] == 0): ?>
                                            <form method="POST" action="customers.php<?php echo buildQueryString(['filter_status', 'search']); ?>" 
                                                  style="flex: 1;" onsubmit="return confirm('Delete this customer?')">
                                                <input type="hidden" name="action" value="delete_customer">
                                                <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                    <i class="bi bi-trash me-1"></i>Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-people d-block mb-2" style="font-size: 48px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No customers found</div>
                            <div style="font-size: 13px;">
                                <?php if ($filterSearch): ?>
                                    Try changing your search or <a href="customers.php">view all customers</a>
                                <?php else: ?>
                                    <?php if ($is_admin): ?>
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#addCustomerModal">Add your first customer</a> to get started
                                    <?php else: ?>
                                        No customers available
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="customers.php<?php echo buildQueryString(['filter_status', 'search']); ?>" data-testid="form-add-customer">
                <input type="hidden" name="action" value="add_customer">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus me-2"></i>
                        Add New Customer
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="customer_name" class="form-control" required placeholder="Enter full name">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" placeholder="e.g., 9876543210">
                            <small class="text-muted">Include country code if applicable</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="customer@example.com">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">GST Number</label>
                            <input type="text" name="gst_number" class="form-control" placeholder="e.g., 27AAPFU0939F1ZV">
                            <small class="text-muted">15 characters GSTIN</small>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Billing Address</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="Enter customer billing address"></textarea>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Shipping Address</label>
                            <textarea name="shipping_address" class="form-control" rows="2" placeholder="Enter customer shipping address (if different from billing)"></textarea>
                            <small class="text-muted">Leave blank if same as billing address</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Opening Balance (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="opening_balance" class="form-control" step="0.01" min="0" value="0.00">
                            </div>
                            <small class="text-muted">Initial balance if any (e.g., previous dues)</small>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Customer Details Modal -->
<div class="modal fade" id="viewCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-badge me-2" style="color: #2563eb;"></i>
                    Customer Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewCustomerContent">
                <!-- Content loaded via AJAX -->
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading customer details...</p>
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

<?php
// Helper function to build query string
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return count($params) ? '?' . http_build_query($params) : '';
}
?>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize DataTable with export buttons
    $('#customersTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search customers:",
            lengthMenu: "Show _MENU_ customers",
            info: "Showing _START_ to _END_ of _TOTAL_ customers",
            emptyTable: "No customers available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: -1 }
            <?php endif; ?>
        ],
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                title: 'Customers_List',
                className: 'btn btn-sm btn-outline-success',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6]
                }
            },
            {
                extend: 'csvHtml5',
                text: '<i class="bi bi-file-earmark-spreadsheet"></i> CSV',
                title: 'Customers_List',
                className: 'btn btn-sm btn-outline-primary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6]
                }
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="bi bi-file-earmark-pdf"></i> PDF',
                title: 'Customers List',
                className: 'btn btn-sm btn-outline-danger',
                orientation: 'landscape',
                pageSize: 'A4',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6]
                }
            },
            {
                extend: 'print',
                text: '<i class="bi bi-printer"></i> Print',
                className: 'btn btn-sm btn-outline-secondary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6]
                }
            }
        ]
    });
});

// Function to update file name display
function updateFileName(input) {
    var fileName = input.files[0] ? input.files[0].name : 'No file chosen';
    document.getElementById('file-name').textContent = fileName;
}

// View customer details
function viewCustomer(id) {
    // Show the modal with loading spinner
    $('#viewCustomerModal').modal('show');
    $('#viewCustomerContent').html(`
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading customer details...</p>
        </div>
    `);
    
    // Make AJAX request to get customer details
    $.ajax({
        url: 'customers.php?ajax=get_customer&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let c = response.customer;
                let balanceClass = parseFloat(c.opening_balance) >= 0 ? 'balance-positive' : 'balance-negative';
                
                // Format GST display
                let gstDisplay = '';
                if (c.gst_number && c.gst_number.trim() !== '') {
                    gstDisplay = `
                        <span class="badge-gst" style="background: #f2e8ff; color: #8b5cf6; padding: 4px 10px; border-radius: 16px; font-size: 13px; font-family: monospace;">
                            <i class="bi bi-patch-check me-1"></i>
                            ${escapeHtml(c.gst_number)}
                        </span>
                    `;
                } else {
                    gstDisplay = '<span class="text-muted">Not provided</span>';
                }
                
                // Format addresses
                let billingAddress = c.address ? escapeHtml(c.address) : '-';
                let shippingAddress = c.shipping_address ? escapeHtml(c.shipping_address) : '(Same as billing address)';
                
                // Build invoices HTML if any
                let invoicesHtml = '';
                if (response.invoices && response.invoices.length > 0) {
                    invoicesHtml = '<h6 class="fw-semibold mb-3" style="color: #2563eb;"><i class="bi bi-receipt me-2"></i>Recent Invoices</h6>';
                    response.invoices.forEach(inv => {
                        let statusClass = parseFloat(inv.pending_amount) > 0 ? 'pending' : 'paid';
                        let statusText = parseFloat(inv.pending_amount) > 0 ? 'Pending' : 'Paid';
                        let statusBg = parseFloat(inv.pending_amount) > 0 ? '#fee2e2' : '#dcfce7';
                        let statusColor = parseFloat(inv.pending_amount) > 0 ? '#dc2626' : '#16a34a';
                        
                        invoicesHtml += `
                            <div class="invoice-item" style="background: #f8fafc; border-radius: 10px; padding: 12px; margin-bottom: 10px; border: 1px solid #e2e8f0;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span style="font-weight: 600; color: #2563eb;">${escapeHtml(inv.inv_num)}</span>
                                    <span style="background: ${statusBg}; color: ${statusColor}; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500;">
                                        <i class="bi ${parseFloat(inv.pending_amount) > 0 ? 'bi-clock-history' : 'bi-check-circle'} me-1"></i>
                                        ${statusText}
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <span style="color: #64748b; font-size: 12px;">
                                        <i class="bi bi-calendar me-1"></i>${new Date(inv.created_at).toLocaleDateString()}
                                    </span>
                                    <span style="font-weight: 600; color: #1e293b;">₹${parseFloat(inv.total).toFixed(2)}</span>
                                </div>
                                ${parseFloat(inv.pending_amount) > 0 ? 
                                    `<div class="mt-2" style="font-size: 12px; color: #dc2626;">
                                        <i class="bi bi-exclamation-circle me-1"></i>
                                        Pending: ₹${parseFloat(inv.pending_amount).toFixed(2)}
                                    </div>` : ''}
                            </div>
                        `;
                    });
                } else {
                    invoicesHtml = '<p class="text-muted" style="background: #f8fafc; padding: 20px; border-radius: 10px; text-align: center;"><i class="bi bi-inbox me-2"></i>No invoices found for this customer.</p>';
                }
                
                // Format the HTML for display
                let html = `
                    <div style="padding: 5px;">
                        <!-- Customer Header -->
                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; margin-bottom: 20px; color: white;">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 600;">
                                    ${c.customer_name ? escapeHtml(c.customer_name).charAt(0).toUpperCase() : 'C'}
                                </div>
                                <div>
                                    <h5 style="margin: 0 0 5px 0; font-weight: 600;">${escapeHtml(c.customer_name)}</h5>
                                    <div style="display: flex; gap: 15px; font-size: 13px; opacity: 0.9;">
                                        <span><i class="bi bi-person-badge me-1"></i>ID: #${c.id}</span>
                                        <span><i class="bi bi-calendar me-1"></i>Since ${new Date(c.created_at).toLocaleDateString()}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-3">
                            <!-- Basic Information Card -->
                            <div class="col-md-6">
                                <div style="background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; height: 100%;">
                                    <h6 style="color: #2563eb; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0;">
                                        <i class="bi bi-info-circle me-2"></i>Basic Information
                                    </h6>
                                    <table style="width: 100%; font-size: 14px;">
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b; width: 100px;">Name:</td>
                                            <td style="padding: 6px 0; font-weight: 500; color: #1e293b;">${escapeHtml(c.customer_name)}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b;">Phone:</td>
                                            <td style="padding: 6px 0; font-weight: 500;">
                                                ${c.phone ? 
                                                    `<span style="background: white; padding: 4px 10px; border-radius: 20px; border: 1px solid #e2e8f0;">
                                                        <i class="bi bi-telephone me-1" style="color: #2563eb;"></i> ${escapeHtml(c.phone)}
                                                    </span>` : 
                                                    '<span class="text-muted">-</span>'}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b;">Email:</td>
                                            <td style="padding: 6px 0;">
                                                ${c.email ? 
                                                    `<span style="background: white; padding: 4px 10px; border-radius: 20px; border: 1px solid #e2e8f0;">
                                                        <i class="bi bi-envelope me-1" style="color: #2563eb;"></i> ${escapeHtml(c.email)}
                                                    </span>` : 
                                                    '<span class="text-muted">-</span>'}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b;">GST No.:</td>
                                            <td style="padding: 6px 0;">
                                                ${gstDisplay}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b;">Billing Address:</td>
                                            <td style="padding: 6px 0; color: #1e293b;">${billingAddress}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b;">Shipping Address:</td>
                                            <td style="padding: 6px 0; color: #1e293b;">${shippingAddress}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Financial Information Card -->
                            <div class="col-md-6">
                                <div style="background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; height: 100%;">
                                    <h6 style="color: #2563eb; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0;">
                                        <i class="bi bi-currency-rupee me-2"></i>Financial Summary
                                    </h6>
                                    <table style="width: 100%; font-size: 14px;">
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b; width: 120px;">Opening Balance:</td>
                                            <td style="padding: 6px 0; font-weight: 600; color: ${parseFloat(c.opening_balance) >= 0 ? '#10b981' : '#ef4444'};">
                                                ₹${parseFloat(c.opening_balance).toFixed(2)}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b;">Total Purchases:</td>
                                            <td style="padding: 6px 0; font-weight: 600; color: #1e293b;">
                                                ₹${parseFloat(c.total_purchases || 0).toFixed(2)}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b;">Invoice Count:</td>
                                            <td style="padding: 6px 0; font-weight: 500;">
                                                <span style="background: #dbeafe; color: #2563eb; padding: 4px 10px; border-radius: 20px;">
                                                    ${c.invoice_count || 0} Invoices
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 6px 0; color: #64748b;">Pending Amount:</td>
                                            <td style="padding: 6px 0;">
                                                ${parseFloat(c.pending_amount || 0) > 0 ? 
                                                    `<span style="background: #fee2e2; color: #dc2626; padding: 4px 10px; border-radius: 20px; font-weight: 600;">
                                                        <i class="bi bi-exclamation-circle me-1"></i>₹${parseFloat(c.pending_amount).toFixed(2)}
                                                    </span>` : 
                                                    '<span style="background: #dcfce7; color: #16a34a; padding: 4px 10px; border-radius: 20px;"><i class="bi bi-check-circle me-1"></i>No Pending</span>'}
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Invoices Section -->
                            <div class="col-12">
                                <div style="background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; margin-top: 10px;">
                                    ${invoicesHtml}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                $('#viewCustomerContent').html(html);
            } else {
                $('#viewCustomerContent').html(`
                    <div class="alert alert-danger m-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Failed to load customer details.
                    </div>
                `);
            }
        },
        error: function() {
            $('#viewCustomerContent').html(`
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    An error occurred while loading customer details.
                </div>
            `);
        }
    });
}

// Phone number validation
document.querySelectorAll('input[name="phone"]').forEach(input => {
    input.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9+]/g, '');
    });
});

// GST number validation
document.querySelectorAll('input[name="gst_number"]').forEach(input => {
    input.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
});

// Form validation for add customer
document.querySelector('#addCustomerModal form')?.addEventListener('submit', function(e) {
    const phone = this.querySelector('input[name="phone"]').value;
    const email = this.querySelector('input[name="email"]').value;
    const gst = this.querySelector('input[name="gst_number"]').value;
    
    if (phone && !/^[0-9+]{10,15}$/.test(phone)) {
        e.preventDefault();
        alert('Please enter a valid phone number (10-15 digits, can start with +)');
        return false;
    }
    
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        e.preventDefault();
        alert('Please enter a valid email address');
        return false;
    }
    
    if (gst && gst.length !== 15) {
        e.preventDefault();
        alert('GST number must be 15 characters long');
        return false;
    }
});

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