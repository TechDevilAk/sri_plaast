<?php
// add-purchase.php
session_start();
$currentPage = 'add-purchase';
$pageTitle = 'Add New Purchase';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can create purchases
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

// ---------- Get current user ID safely ----------
function getCurrentUserId($conn) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return 0;
    }
    
    $user_id = (int)$_SESSION['user_id'];
    
    $result = mysqli_query($conn, "SELECT id FROM users WHERE id = $user_id LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        return $user_id;
    }
    
    return 0;
}

// ---------- Save bank transaction for purchase payment ----------
function saveBankTransaction($conn, $data) {
    $bank_account_id = (int)$data['bank_account_id'];
    $transaction_date = $data['transaction_date'];
    $transaction_type = $data['transaction_type'];
    $reference_type = $data['reference_type'];
    $reference_id = (int)$data['reference_id'];
    $reference_number = mysqli_real_escape_string($conn, $data['reference_number']);
    $party_name = mysqli_real_escape_string($conn, $data['party_name']);
    $party_type = $data['party_type'];
    $description = mysqli_real_escape_string($conn, $data['description']);
    $amount = (float)$data['amount'];
    $payment_method = $data['payment_method'];
    $status = 'completed';
    $cheque_number = isset($data['cheque_number']) ? mysqli_real_escape_string($conn, $data['cheque_number']) : '';
    $cheque_date = isset($data['cheque_date']) && !empty($data['cheque_date']) ? "'" . mysqli_real_escape_string($conn, $data['cheque_date']) . "'" : 'NULL';
    $cheque_bank = isset($data['cheque_bank']) ? mysqli_real_escape_string($conn, $data['cheque_bank']) : '';
    $upi_ref_no = isset($data['upi_ref_no']) ? mysqli_real_escape_string($conn, $data['upi_ref_no']) : '';
    $transaction_ref_no = isset($data['transaction_ref_no']) ? mysqli_real_escape_string($conn, $data['transaction_ref_no']) : '';
    $notes = isset($data['notes']) ? mysqli_real_escape_string($conn, $data['notes']) : '';
    $created_by = getCurrentUserId($conn);
    $created_by_sql = $created_by > 0 ? $created_by : 'NULL';

    $query = "
        INSERT INTO bank_transactions 
        (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, 
         reference_number, party_name, party_type, description, amount, payment_method, 
         status, cheque_number, cheque_date, cheque_bank, upi_ref_no, transaction_ref_no, 
         notes, created_by) 
        VALUES (
            $bank_account_id, '$transaction_date', '$transaction_type', '$reference_type', $reference_id,
            '$reference_number', '$party_name', '$party_type', '$description', $amount, '$payment_method',
            '$status', '$cheque_number', $cheque_date, '$cheque_bank', '$upi_ref_no', '$transaction_ref_no',
            '$notes', $created_by_sql
        )
    ";
    
    if (mysqli_query($conn, $query)) {
        $transaction_id = mysqli_insert_id($conn);
        
        // Update bank account balance (purchase payment is money out)
        $balance_query = "SELECT current_balance FROM bank_accounts WHERE id = $bank_account_id";
        $balance_result = mysqli_query($conn, $balance_query);
        $current_balance = mysqli_fetch_assoc($balance_result)['current_balance'];
        
        // For purchase payments (money out)
        $new_balance = $current_balance - $amount;
        
        $update_balance = "UPDATE bank_accounts SET current_balance = $new_balance WHERE id = $bank_account_id";
        mysqli_query($conn, $update_balance);
        
        return $transaction_id;
    }
    
    return false;
}

// ---------- Get last used bank account for user ----------
function getLastUsedBankAccount($conn, $user_id) {
    // First check if there's a cookie with last selection
    if (isset($_COOKIE['last_purchase_bank_account'])) {
        $account_id = (int)$_COOKIE['last_purchase_bank_account'];
        $result = mysqli_query($conn, "SELECT * FROM bank_accounts WHERE id = $account_id AND status = 1");
        if ($row = mysqli_fetch_assoc($result)) {
            return $row;
        }
    }
    
    // If no cookie or account not found, return default account
    $result = mysqli_query($conn, "SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, id DESC LIMIT 1");
    return mysqli_fetch_assoc($result);
}

// ==================== BULK IMPORT FUNCTIONALITY ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_import_purchases') {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to import purchases.';
    } else {
        if (isset($_FILES['purchase_csv_file']) && $_FILES['purchase_csv_file']['error'] == 0) {
            $file = $_FILES['purchase_csv_file']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['purchase_csv_file']['name'], PATHINFO_EXTENSION));
            
            if ($file_ext != 'csv') {
                $error = 'Please upload a valid CSV file.';
            } else {
                if (($handle = fopen($file, "r")) !== FALSE) {
                    // Get headers
                    $headers = fgetcsv($handle);
                    
                    // Validate headers
                    $expected_headers = ['supplier_name', 'purchase_date', 'invoice_num', 'category_name', 'quantity_kg', 'price_per_kg', 'gst_rate', 'payment_method', 'payment_amount', 'payment_notes'];
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
                                
                                if (empty($row['category_name'])) {
                                    $import_errors[] = "Row $row_number: Category name is required";
                                    $error_count++;
                                    continue;
                                }
                                
                                if (empty($row['quantity_kg']) || floatval($row['quantity_kg']) <= 0) {
                                    $import_errors[] = "Row $row_number: Valid quantity in kg is required";
                                    $error_count++;
                                    continue;
                                }
                                
                                if (empty($row['price_per_kg']) || floatval($row['price_per_kg']) <= 0) {
                                    $import_errors[] = "Row $row_number: Valid price per kg is required";
                                    $error_count++;
                                    continue;
                                }
                                
                                // Find or create supplier
                                $supplier_name = $row['supplier_name'];
                                $supplier_id = null;
                                
                                $supplier_check = $conn->prepare("SELECT id FROM suppliers WHERE supplier_name = ?");
                                $supplier_check->bind_param("s", $supplier_name);
                                $supplier_check->execute();
                                $supplier_result = $supplier_check->get_result();
                                
                                if ($supplier_result->num_rows > 0) {
                                    $supplier = $supplier_result->fetch_assoc();
                                    $supplier_id = $supplier['id'];
                                } else {
                                    // Create new supplier
                                    $insert_supplier = $conn->prepare("INSERT INTO suppliers (supplier_name) VALUES (?)");
                                    $insert_supplier->bind_param("s", $supplier_name);
                                    if ($insert_supplier->execute()) {
                                        $supplier_id = $insert_supplier->insert_id;
                                    } else {
                                        $import_errors[] = "Row $row_number: Failed to create supplier";
                                        $error_count++;
                                        continue;
                                    }
                                    $insert_supplier->close();
                                }
                                $supplier_check->close();
                                
                                // Find or create category
                                $category_name = $row['category_name'];
                                $category_id = null;
                                $gram_value = 0;
                                
                                $category_check = $conn->prepare("SELECT id, gram_value FROM category WHERE category_name = ?");
                                $category_check->bind_param("s", $category_name);
                                $category_check->execute();
                                $category_result = $category_check->get_result();
                                
                                if ($category_result->num_rows > 0) {
                                    $category = $category_result->fetch_assoc();
                                    $category_id = $category['id'];
                                    $gram_value = $category['gram_value'];
                                } else {
                                    $import_errors[] = "Row $row_number: Category not found. Please add category first.";
                                    $error_count++;
                                    continue;
                                }
                                $category_check->close();
                                
                                if ($gram_value <= 0) {
                                    $import_errors[] = "Row $row_number: Invalid gram value for category";
                                    $error_count++;
                                    continue;
                                }
                                
                                // Calculate quantities and amounts
                                $quantity_kg = floatval($row['quantity_kg']);
                                $price_per_kg = floatval($row['price_per_kg']);
                                $total_price = $quantity_kg * $price_per_kg;
                                
                                // Parse GST rate (e.g., "18" or "18%")
                                $gst_rate_str = str_replace('%', '', $row['gst_rate']);
                                $gst_rate = floatval($gst_rate_str);
                                $cgst_rate = $gst_rate / 2;
                                $sgst_rate = $gst_rate / 2;
                                
                                // Calculate pieces
                                $pcs_per_kg = 1000 / $gram_value;
                                $total_pcs = $pcs_per_kg * $quantity_kg;
                                $price_per_pc = $total_price / $total_pcs;
                                
                                // Calculate GST amounts
                                $cgst_amount = ($total_price * $cgst_rate) / 100;
                                $sgst_amount = ($total_price * $sgst_rate) / 100;
                                $taxable = $total_price;
                                $total_with_gst = $total_price + $cgst_amount + $sgst_amount;
                                
                                // Create unique purchase number
                                $purchase_no = 'BULK-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                                
                                // Insert purchase
                                $purchase_date = !empty($row['purchase_date']) ? date('Y-m-d', strtotime($row['purchase_date'])) : date('Y-m-d');
                                $invoice_num = !empty($row['invoice_num']) ? $row['invoice_num'] : '';
                                
                                $insert_purchase = $conn->prepare("
                                    INSERT INTO purchase (
                                        supplier_id, purchase_no, invoice_num, purchase_date,
                                        cgst, cgst_amount, sgst, sgst_amount, total, gst_type
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'exclusive')
                                ");
                                
                                $insert_purchase->bind_param(
                                    "isssddddd",
                                    $supplier_id, $purchase_no, $invoice_num, $purchase_date,
                                    $cgst_rate, $cgst_amount, $sgst_rate, $sgst_amount,
                                    $total_with_gst
                                );
                                
                                if (!$insert_purchase->execute()) {
                                    $import_errors[] = "Row $row_number: Failed to create purchase - " . $conn->error;
                                    $error_count++;
                                    continue;
                                }
                                
                                $purchase_id = $insert_purchase->insert_id;
                                $insert_purchase->close();
                                
                                // Insert purchase item
                                $insert_item = $conn->prepare("
                                    INSERT INTO purchase_item (
                                        purchase_id, cat_id, cat_name, cat_grm_value, hsn,
                                        taxable, cgst, cgst_amount, sgst, sgst_amount,
                                        purchase_price, total, qty, unit, sec_qty, sec_unit
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                
                                $hsn = ''; // HSN can be fetched from category if needed
                                $unit = 'pcs';
                                $sec_qty = $quantity_kg;
                                $sec_unit = 'kg';
                                
                                $insert_item->bind_param(
                                    "iisdsddddddddsds",
                                    $purchase_id, $category_id, $category_name, $gram_value, $hsn,
                                    $taxable, $cgst_rate, $cgst_amount, $sgst_rate, $sgst_amount,
                                    $price_per_pc, $total_with_gst, $total_pcs, $unit, $sec_qty, $sec_unit
                                );
                                
                                if (!$insert_item->execute()) {
                                    $import_errors[] = "Row $row_number: Failed to create purchase item - " . $conn->error;
                                    $error_count++;
                                    continue;
                                }
                                $insert_item->close();
                                
                                // Update category stock
                                $update_stock = $conn->prepare("
                                    UPDATE category 
                                    SET total_quantity = total_quantity + ?,
                                        purchase_price = ?,
                                        updated_at = CURRENT_TIMESTAMP
                                    WHERE id = ?
                                ");
                                $update_stock->bind_param("ddi", $total_pcs, $price_per_pc, $category_id);
                                $update_stock->execute();
                                $update_stock->close();
                                
                                // Add to GST credit table
                                if ($cgst_amount > 0 || $sgst_amount > 0) {
                                    $total_credit = $cgst_amount + $sgst_amount;
                                    $gst_credit = $conn->prepare("
                                        INSERT INTO gst_credit_table (purchase_id, cgst, sgst, total_credit)
                                        VALUES (?, ?, ?, ?)
                                    ");
                                    $gst_credit->bind_param("iddd", $purchase_id, $cgst_amount, $sgst_amount, $total_credit);
                                    $gst_credit->execute();
                                    $gst_credit->close();
                                }
                                
                                // Add payment if specified
                                if (!empty($row['payment_amount']) && floatval($row['payment_amount']) > 0) {
                                    $payment_amount = floatval($row['payment_amount']);
                                    $payment_method = !empty($row['payment_method']) ? $row['payment_method'] : 'cash';
                                    $payment_notes = !empty($row['payment_notes']) ? $row['payment_notes'] : 'Bulk import payment';
                                    
                                    $insert_payment = $conn->prepare("
                                        INSERT INTO purchase_payment_history (purchase_id, paid_amount, payment_method, notes)
                                        VALUES (?, ?, ?, ?)
                                    ");
                                    $insert_payment->bind_param("idss", $purchase_id, $payment_amount, $payment_method, $payment_notes);
                                    $insert_payment->execute();
                                    $insert_payment->close();
                                }
                                
                                $success_count++;
                            }
                            
                            fclose($handle);
                            
                            if ($error_count == 0) {
                                mysqli_commit($conn);
                                $success = "Bulk import completed successfully! Imported $success_count purchase items.";
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
if (isset($_GET['export']) && $_GET['export'] === 'sample_purchase_csv') {
    // Sample data
    $sample_data = [
        ['supplier_name', 'purchase_date', 'invoice_num', 'category_name', 'quantity_kg', 'price_per_kg', 'gst_rate', 'payment_method', 'payment_amount', 'payment_notes'],
        ['Sample Supplier 1', date('Y-m-d'), 'INV-001', '9.3 grm', '100.5', '120.00', '18', 'bank', '12060.00', 'Full payment'],
        ['Sample Supplier 1', date('Y-m-d'), 'INV-001', '12.3 gram', '75.25', '135.00', '18', 'bank', '10158.75', 'Full payment'],
        ['Sample Supplier 2', date('Y-m-d'), 'INV-002', '19.3Grm Pet Preforms', '50.0', '180.00', '18', 'cash', '9000.00', 'Partial payment'],
        ['Sample Supplier 3', date('Y-m-d'), 'INV-003', '32Grm', '200.0', '95.00', '18', 'upi', '19000.00', ''],
    ];
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="purchase_import_sample.csv"');
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

// ==================== EXPORT PURCHASE TEMPLATE ====================
if (isset($_GET['export']) && $_GET['export'] === 'purchase_template') {
    // Get suppliers for dropdown reference in template
    $suppliers = $conn->query("SELECT supplier_name FROM suppliers ORDER BY supplier_name");
    $supplier_list = [];
    while ($s = $suppliers->fetch_assoc()) {
        $supplier_list[] = $s['supplier_name'];
    }
    
    // Get categories for dropdown reference
    $categories = $conn->query("SELECT category_name FROM category WHERE total_quantity > 0 ORDER BY category_name");
    $category_list = [];
    while ($c = $categories->fetch_assoc()) {
        $category_list[] = $c['category_name'];
    }
    
    // Create template with instructions
    $template_data = [
        ['supplier_name', 'purchase_date', 'invoice_num', 'category_name', 'quantity_kg', 'price_per_kg', 'gst_rate', 'payment_method', 'payment_amount', 'payment_notes'],
        ['INSTRUCTIONS:', 'YYYY-MM-DD', 'Optional', 'Must exist', '>0', '>0', '5,12,18,28', 'cash/card/upi/bank', 'Optional', 'Optional'],
        ['---', '---', '---', '---', '---', '---', '---', '---', '---', '---'],
    ];
    
    // Add some examples
    if (!empty($supplier_list)) {
        $template_data[] = [$supplier_list[0] ?? 'Supplier Name', date('Y-m-d'), 'INV-001', $category_list[0] ?? 'Category Name', '100', '120', '18', 'bank', '12000', 'Full payment'];
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="purchase_import_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Write data
    foreach ($template_data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// Handle Quick Add Category
$quick_add_success = '';
$quick_add_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_add_category') {
    
    $category_name = trim($_POST['category_name'] ?? '');
    $purchase_price = floatval($_POST['purchase_price'] ?? 0);
    $gram_value = floatval($_POST['gram_value'] ?? 0);
    $min_stock_level = floatval($_POST['min_stock_level'] ?? 0);
    $total_quantity = floatval($_POST['total_quantity'] ?? 0);

    if (empty($category_name)) {
        $quick_add_error = 'Category name is required.';
    } else {
        // Check if category exists
        $check = $conn->prepare("SELECT id FROM category WHERE category_name = ?");
        $check->bind_param("s", $category_name);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            $quick_add_error = 'Category already exists. Please choose a different name.';
        } else {
            $stmt = $conn->prepare("INSERT INTO category (category_name, purchase_price, gram_value, min_stock_level, total_quantity) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sdddd", $category_name, $purchase_price, $gram_value, $min_stock_level, $total_quantity);
            
            if ($stmt->execute()) {
                $category_id = $stmt->insert_id;
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', 'Quick added category: " . $conn->real_escape_string($category_name) . "')";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("i", $_SESSION['user_id']);
                $log_stmt->execute();
                
                $quick_add_success = "Category '{$category_name}' added successfully. You can now select it.";
            } else {
                $quick_add_error = "Failed to add category.";
            }
            $stmt->close();
        }
        $check->close();
    }
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
            // Calculate pieces per kg (1000g / gram_value)
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
                    "pcs_per_kg" => $pcs_per_kg
                ]
            ];
        }

        json_response(["results" => $items]);
    }

    json_response(["ok" => false, "message" => "Unknown ajax endpoint"], 404);
}

// --------------------------
// Handle Form Submission
// --------------------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_purchase') {
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $purchase_no = trim($_POST['purchase_no'] ?? '');
    $invoice_num = trim($_POST['invoice_num'] ?? '');
    $purchase_date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
    $gst_type = $_POST['gst_type'] ?? 'exclusive'; // inclusive or exclusive
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
            // Create purchase record
            $stmt = $conn->prepare("
                INSERT INTO purchase (
                    supplier_id, purchase_no, invoice_num, purchase_date,
                    cgst, cgst_amount, sgst, sgst_amount, total, gst_type
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // We'll update these after calculating items
            $temp_cgst = 0;
            $temp_sgst = 0;

            $stmt->bind_param(
                "isssddddds",
                $supplier_id, $purchase_no, $invoice_num, $purchase_date,
                $temp_cgst, $total_cgst, $temp_sgst, $total_sgst,
                $total_amount, $gst_type
            );
            $stmt->execute();
            $purchase_id = $stmt->insert_id;
            $stmt->close();

            // Insert purchase items
            $item_stmt = $conn->prepare("
                INSERT INTO purchase_item (
                    purchase_id, cat_id, cat_name, cat_grm_value, hsn,
                    taxable, cgst, cgst_amount, sgst, sgst_amount,
                    purchase_price, total, qty, unit, sec_qty, sec_unit
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($items as $item) {
                $cat_id = $item['cat_id'];
                $cat_name = $item['cat_name'];
                $cat_grm = $item['gram_value'] ?? 0;
                $hsn = $item['hsn_code'] ?? '';
                $taxable = $item['taxable'];
                $cgst = $item['cgst'] ?? 0;
                $cgst_amt = $item['cgst_amt'];
                $sgst = $item['sgst'] ?? 0;
                $sgst_amt = $item['sgst_amt'];
                $purchase_price = $item['purchase_price']; // Price per piece
                $total = $item['total'];
                $qty = $item['qty']; // In pieces
                $unit = $item['unit'];
                $kg_qty = $item['kg_qty'] ?? 0; // Original kg quantity
                $sec_unit = 'kg';
                $total_taxable += $taxable;
                $total_cgst += $cgst_amt;
                $total_sgst += $sgst_amt;
                $total_amount += $total;

                $item_stmt->bind_param(
                    "iisdsddddddddsds",
                    $purchase_id, $cat_id, $cat_name, $cat_grm, $hsn,
                    $taxable, $cgst, $cgst_amt, $sgst, $sgst_amt,
                    $purchase_price, $total, $qty, $unit, $kg_qty, $sec_unit
                );
                $item_stmt->execute();

                // Store price per piece including GST for category
                $gst_percent = (float)$cgst + (float)$sgst;
                $price_per_pc_incl_gst = (float)$purchase_price; // Already includes GST from calculation

                $update_cat = $conn->prepare("
                    UPDATE category 
                    SET total_quantity = total_quantity + ?,
                        purchase_price = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $update_cat->bind_param("ddi", $qty, $price_per_pc_incl_gst, $cat_id);
                $update_cat->execute();
                $update_cat->close();
            }
            $item_stmt->close();

            // Update purchase with calculated totals
            $update_purchase = $conn->prepare("
                UPDATE purchase 
                SET cgst = ?, cgst_amount = ?, sgst = ?, sgst_amount = ?, total = ?
                WHERE id = ?
            ");
            $cgst_rate = $total_taxable > 0 ? ($total_cgst / $total_taxable * 100) : 0;
            $sgst_rate = $total_taxable > 0 ? ($total_sgst / $total_taxable * 100) : 0;

            $update_purchase->bind_param(
                "dddddi",
                $cgst_rate, $total_cgst, $sgst_rate, $total_sgst,
                $total_amount, $purchase_id
            );
            $update_purchase->execute();
            $update_purchase->close();

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

            // Insert payment history records
            $started_transaction = false;
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
                        
                        // Create bank transaction for UPI, Card, or Bank payments
                        if (in_array($method, ['upi', 'card', 'bank'])) {
                            $bank_account_id = isset($payment['bank_account_id']) ? (int)$payment['bank_account_id'] : 0;
                            
                            if ($bank_account_id > 0) {
                                // Get supplier name
                                $supplier_query = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
                                $supplier_query->bind_param("i", $supplier_id);
                                $supplier_query->execute();
                                $supplier_result = $supplier_query->get_result();
                                $supplier_data = $supplier_result->fetch_assoc();
                                $supplier_name = $supplier_data['supplier_name'] ?? 'Unknown Supplier';
                                $supplier_query->close();
                                
                                $upi_ref_no = isset($payment['upi_ref_no']) ? $payment['upi_ref_no'] : '';
                                $transaction_ref_no = isset($payment['transaction_ref_no']) ? $payment['transaction_ref_no'] : '';
                                
                                $tx_data = [
                                    'bank_account_id' => $bank_account_id,
                                    'transaction_date' => $purchase_date,
                                    'transaction_type' => 'purchase_payment',
                                    'reference_type' => 'purchase',
                                    'reference_id' => $purchase_id,
                                    'reference_number' => $purchase_no,
                                    'party_name' => $supplier_name,
                                    'party_type' => 'supplier',
                                    'description' => "Purchase payment: {$purchase_no} to {$supplier_name}" . ($notes ? " ({$notes})" : ""),
                                    'amount' => $amount,
                                    'payment_method' => $method,
                                    'cheque_number' => '',
                                    'cheque_date' => '',
                                    'cheque_bank' => '',
                                    'upi_ref_no' => $upi_ref_no,
                                    'transaction_ref_no' => $transaction_ref_no ?: $purchase_no . '-' . strtoupper($method),
                                    'notes' => "Payment for purchase via " . strtoupper($method)
                                ];
                                saveBankTransaction($conn, $tx_data);
                            }
                        }
                    }
                }
                $payment_stmt->close();
            }

            // Log activity
            $log_desc = "Created purchase #{$purchase_no} with " . count($items) . " items (Total: ₹" . money2($total_amount) . ")";
            $log_stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)");
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();

            $conn->commit();
            $success = "Purchase created successfully. Purchase #: {$purchase_no}";

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to create purchase: " . $e->getMessage();
        }
    }
}

// Generate default purchase number
$default_purchase_no = 'PUR-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

// Get all GST rates
$gst_rates = $conn->query("SELECT * FROM gst WHERE status = 1 ORDER BY hsn ASC");

// Get all active bank accounts for dropdown
$bank_accounts = $conn->query("SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, account_name ASC");

// Get last used bank account for current user
$current_user_id = getCurrentUserId($conn);
$last_bank_account = getLastUsedBankAccount($conn, $current_user_id);

// Payment methods
$payment_methods = ['cash', 'card', 'upi', 'bank'];
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
        
        .btn-outline-primary {
            border: 1.5px solid var(--primary);
            color: var(--primary);
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 500;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
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
        
        .quick-add-link {
            color: var(--primary);
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .quick-add-link:hover {
            text-decoration: underline;
        }
        
        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .price-per-kg-field {
            background: #f0fdf4;
            border-left: 4px solid #10b981;
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
            align-items: center;
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
        
        .btn-template {
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
        
        .btn-template:hover {
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
        
        #purchase-file-name {
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
        
        .bank-selection-row {
            background: #ecfdf3;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            margin-bottom: 10px;
        }
        
        .bank-selection-label {
            font-weight: 600;
            color: #047857;
            font-size: 12px;
        }
        
        .bank-badge {
            background:#dbeafe; color:#1e40af; padding:4px 8px; border-radius:30px;
            font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px;
        }
        
        @media (max-width: 768px) {
            .card-header-custom {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .preview-grid {
                grid-template-columns: 1fr;
            }
            
            .import-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .import-buttons a,
            .import-buttons button,
            .file-input-wrapper {
                width: 100%;
            }
            
            .file-input-label {
                width: 100%;
                justify-content: center;
            }
        }

        /* SweetAlert2 Custom Styling */
        .swal2-popup {
            border-radius: 16px !important;
            padding: 2rem !important;
        }
        
        .swal2-title {
            font-size: 1.5rem !important;
            font-weight: 600 !important;
        }
        
        .swal2-html-container {
            font-size: 1rem !important;
            color: #64748b !important;
        }
        
        .swal2-confirm {
            background: var(--primary) !important;
            border-radius: 10px !important;
            padding: 10px 30px !important;
            font-weight: 500 !important;
        }
        
        .swal2-cancel {
            border-radius: 10px !important;
            padding: 10px 30px !important;
            font-weight: 500 !important;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Add New Purchase</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Create purchase with kg to pieces conversion</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="?export=sample_purchase_csv" class="btn-sample">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Sample CSV
                    </a>
                    <a href="?export=purchase_template" class="btn-template">
                        <i class="bi bi-download"></i> Template
                    </a>
                    <a href="manage-purchases.php" class="btn-outline-custom">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            <!-- PHP Messages using SweetAlert2 -->
            <?php if ($success): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: <?php echo json_encode($success); ?>,
                        showConfirmButton: true,
                        confirmButtonText: 'OK'
                    }).then((result) => {
                        window.location.href = 'manage-purchases.php';
                    });
                });
            </script>
            <?php endif; ?>

            <?php if ($error): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: <?php echo json_encode($error); ?>,
                        showConfirmButton: true,
                        confirmButtonText: 'OK'
                    });
                });
            </script>
            <?php endif; ?>

            <!-- Quick Add Category Messages -->
            <?php if ($quick_add_success): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'success',
                        title: 'Category Added!',
                        text: <?php echo json_encode($quick_add_success); ?>,
                        showConfirmButton: true,
                        confirmButtonText: 'OK'
                    }).then((result) => {
                        $('#categorySelect').select2('open');
                    });
                });
            </script>
            <?php endif; ?>

            <?php if ($quick_add_error): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: <?php echo json_encode($quick_add_error); ?>,
                        showConfirmButton: true,
                        confirmButtonText: 'OK'
                    });
                });
            </script>
            <?php endif; ?>

            <!-- Bulk Import Section -->
            <div class="import-section">
                <div class="import-title">
                    <i class="bi bi-cloud-upload me-2"></i>Bulk Import Purchases
                </div>
                <form method="POST" action="add-purchase.php" enctype="multipart/form-data" id="bulkImportForm">
                    <input type="hidden" name="action" value="bulk_import_purchases">
                    <div class="import-buttons">
                        <a href="?export=sample_purchase_csv" class="btn-sample">
                            <i class="bi bi-file-earmark-spreadsheet"></i> Download Sample
                        </a>
                        <a href="?export=purchase_template" class="btn-template">
                            <i class="bi bi-download"></i> Template
                        </a>
                        <div class="file-input-wrapper">
                            <label for="purchase_csv_file" class="file-input-label">
                                <i class="bi bi-folder2-open"></i> Choose CSV File
                            </label>
                            <input type="file" name="purchase_csv_file" id="purchase_csv_file" accept=".csv" required onchange="updatePurchaseFileName(this)">
                        </div>
                        <span id="purchase-file-name">No file chosen</span>
                        <button type="submit" class="btn-import" onclick="return confirm('Are you sure you want to import purchases from this CSV? Categories must exist.')">
                            <i class="bi bi-upload"></i> Import Purchases
                        </button>
                    </div>
                </form>
                <div class="mt-3 text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    CSV must have headers: supplier_name, purchase_date, invoice_num, category_name, quantity_kg, price_per_kg, gst_rate, payment_method, payment_amount, payment_notes
                </div>
                <div class="mt-2 text-warning small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Note:</strong> Categories must already exist in the system. Suppliers will be created if they don't exist.
                </div>
            </div>

            <form method="POST" action="add-purchase.php" id="purchaseForm">
                <input type="hidden" name="action" value="create_purchase">
                <input type="hidden" name="items_json" id="items_json" value="[]">

                <!-- Purchase Details Card -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-info-circle me-2"></i>Purchase Details</h5>
                        <div class="gst-toggle">
                            <span class="text-muted me-2">GST:</span>
                            <button type="button" class="btn <?php echo (!isset($_POST['gst_type']) || $_POST['gst_type'] === 'exclusive') ? 'active' : ''; ?>" id="gstExclusiveBtn" onclick="setGSTType('exclusive')">Exclusive</button>
                            <button type="button" class="btn <?php echo (isset($_POST['gst_type']) && $_POST['gst_type'] === 'inclusive') ? 'active' : ''; ?>" id="gstInclusiveBtn" onclick="setGSTType('inclusive')">Inclusive</button>
                            <input type="hidden" name="gst_type" id="gstType" value="exclusive">
                        </div>
                    </div>
                    <div class="card-body-custom">
                        <div class="row g-4">
                            <!-- Supplier Selection -->
                            <div class="col-md-6">
                                <label class="form-label">Select Supplier <span class="text-danger">*</span></label>
                                <select class="form-select" id="supplierSelect" name="supplier_id" style="width:100%" required></select>
                                <div class="row g-2 mt-3" id="supplierInfo" style="display: none;">
                                    <div class="col-md-4">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">Phone</small>
                                            <span class="fw-semibold" id="supplierPhone">-</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">GST</small>
                                            <span class="fw-semibold" id="supplierGST">-</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">Balance</small>
                                            <span class="fw-semibold" id="supplierBalance">₹0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Purchase Number -->
                            <div class="col-md-6">
                                <label class="form-label">Purchase Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="purchase_no" 
                                       value="<?php echo htmlspecialchars($default_purchase_no); ?>" required>
                                <small class="text-muted">Unique identifier for this purchase</small>
                            </div>

                            <!-- Invoice Number -->
                            <div class="col-md-4">
                                <label class="form-label">Supplier Invoice Number</label>
                                <input type="text" class="form-control" name="invoice_num" 
                                       placeholder="Enter supplier invoice number">
                            </div>

                            <!-- Purchase Date -->
                            <div class="col-md-4">
                                <label class="form-label">Purchase Date</label>
                                <input type="date" class="form-control" name="purchase_date" 
                                       value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add Items Card -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-cart-plus me-2"></i>Add Items</h5>
                        <span class="badge bg-light text-dark">kg → pieces conversion</span>
                    </div>
                    <div class="card-body-custom">
                        <div class="row g-4">
                            <!-- Category Selection with Quick Add -->
                            <div class="col-md-4">
                                <div class="category-header">
                                    <label class="form-label">Select Category <span class="text-danger">*</span></label>
                                    <a href="#" class="quick-add-link" data-bs-toggle="modal" data-bs-target="#quickAddCategoryModal">
                                        <i class="bi bi-plus-circle"></i> Add New
                                    </a>
                                </div>
                                <select class="form-select" id="categorySelect" style="width:100%"></select>
                                <div class="mt-2" id="categoryMeta"></div>
                            </div>

                            <!-- GST Selection -->
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

                            <!-- Quantity in KG -->
                            <div class="col-md-2">
                                <label class="form-label">Quantity (kg)</label>
                                <input type="number" class="form-control" id="kgInput" 
                                       step="0.001" min="0.001" disabled>
                            </div>

                            <!-- Price per KG - NEW FIELD -->
                            <div class="col-md-2">
                                <label class="form-label">Price per KG (₹)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" class="form-control price-per-kg-field" id="pricePerKgInput" 
                                           step="0.01" min="0" disabled>
                                </div>
                                <small class="text-muted">Enter to calculate total</small>
                            </div>

                            <!-- Total Purchase Price (calculated or manual) -->
                            <div class="col-md-2">
                                <label class="form-label">Total Price (₹)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" class="form-control" id="totalPriceInput" 
                                           step="0.01" min="0" disabled>
                                </div>
                            </div>

                            <!-- Add Button -->
                            <div class="col-12 d-flex justify-content-end">
                                <button type="button" class="btn btn-primary" id="addItemBtn" disabled>
                                    <i class="bi bi-plus-circle me-2"></i>Add Item
                                </button>
                            </div>
                        </div>

                        <!-- Conversion Preview -->
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
                                <div class="preview-item">
                                    <div class="preview-label">Price per KG</div>
                                    <div class="preview-value" id="previewPricePerKg">₹0.00</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items List Card -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-list-check me-2"></i>Purchase Items</h5>
                        <span class="badge bg-light text-dark" id="itemCount">0 items</span>
                    </div>
                    <div class="card-body-custom">
                        <!-- Items Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered" id="itemsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Category</th>
                                        <th>Gram/pc</th>
                                        <th>KG</th>
                                        <th>Pieces</th>
                                        <th>Price/pc</th>
                                        <th>Price/kg</th>
                                        <th>Taxable</th>
                                        <th>GST</th>
                                        <th>Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <tr>
                                        <td colspan="11" class="text-center py-4 text-muted">
                                            No items added yet
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="7" class="text-end">Totals:</th>
                                        <th id="totalPrice">₹0.00</th>
                                        <th id="totalGST">₹0.00</th>
                                        <th id="totalAmount">₹0.00</th>
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
                            <!-- Payment cards will be added here -->
                        </div>
                        
                        <button type="button" class="add-payment-btn mt-3" onclick="addPayment()">
                            <i class="bi bi-plus-circle me-2"></i>Add Another Payment
                        </button>

                        <!-- Total Section -->
                        <div class="total-section mt-4">
                            <div class="total-row">
                                <span>Total Taxable Amount</span>
                                <span class="fw-semibold" id="totalPurchaseDisplay">₹0.00</span>
                            </div>
                            <div class="total-row">
                                <span>Total GST</span>
                                <span class="fw-semibold" id="totalGSTDisplay">₹0.00</span>
                            </div>
                            <div class="total-row">
                                <span>Total Paid</span>
                                <span class="fw-semibold" id="totalPaidDisplay">₹0.00</span>
                            </div>
                            <div class="total-row">
                                <span>Balance Due</span>
                                <span class="fw-semibold" id="balanceDueDisplay">₹0.00</span>
                            </div>
                            <div class="total-row grand-total">
                                <span>Grand Total (with GST)</span>
                                <span id="grandTotalDisplay">₹0.00</span>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-end gap-3 mt-4">
                            <a href="manage-purchases.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-success" id="submitBtn">
                                <i class="bi bi-check-circle me-2"></i>Create Purchase
                            </button>
                        </div>
                    </div>
    </div>
            </form>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Quick Add Category Modal -->
<div class="modal fade" id="quickAddCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="add-purchase.php#categorySelect" id="quickAddCategoryForm">
                <input type="hidden" name="action" value="quick_add_category">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2 text-primary"></i>
                        Quick Add Category
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="category_name" class="form-control" required placeholder="Enter category name" id="quickCatName">
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Purchase Price (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="purchase_price" class="form-control" step="0.01" min="0" value="0.00" id="quickCatPrice">
                            </div>
                            <small class="text-muted">Cost per piece</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gram Value </label>
                            <div class="input-group">
                                <span class="input-group-text">g</span>
                                <input type="number" name="gram_value" class="form-control" step="0.001" min="0" value="0.000" id="quickCatGram">
                            </div>
                            <small class="text-muted">Weight per piece</small>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Initial Stock (piece)</label>
                            <div class="input-group">
                                <span class="input-group-text">pcs</span>
                                <input type="number" name="total_quantity" class="form-control" step="0.001" min="0" value="0.000" id="quickCatStock">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Min Stock Level (piece)</label>
                            <div class="input-group">
                                <span class="input-group-text">pcs</span>
                                <input type="number" name="min_stock_level" class="form-control" step="0.001" min="0" value="0.000" id="quickCatMin">
                            </div>
                            <small class="text-muted">Alert when stock below</small>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3 mb-0 py-2" style="font-size: 13px;">
                        <i class="bi bi-info-circle me-1"></i>
                        After adding, you can select this category from the dropdown above.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="quickAddSubmit">
                        <i class="bi bi-save me-2"></i>Add Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Function to update file name display for purchase import
function updatePurchaseFileName(input) {
    var fileName = input.files[0] ? input.files[0].name : 'No file chosen';
    document.getElementById('purchase-file-name').textContent = fileName;
}

(function() {
    // --------------------------
    // State
    // --------------------------
    let selectedCategory = null;
    let items = [];
    let gstType = 'exclusive'; // 'exclusive' or 'inclusive'
    
    // Last used bank account from PHP
    const lastBankAccount = <?php echo json_encode($last_bank_account); ?>;
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

    // --------------------------
    // Helpers
    // --------------------------
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function money2(n) {
        n = parseFloat(n || 0);
        return n.toFixed(2);
    }

    // --------------------------
    // Select2 Initialization
    // --------------------------
    $('#supplierSelect').select2({
        placeholder: 'Search supplier by name, phone or GST...',
        allowClear: true,
        ajax: {
            url: 'add-purchase.php?ajax=suppliers',
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
            url: 'add-purchase.php?ajax=categories',
            dataType: 'json',
            delay: 250,
            data: function(params) { return { term: params.term || '' }; },
            processResults: function(data) { return data; }
        }
    });

    // --------------------------
    // GST Type Toggle
    // --------------------------
    window.setGSTType = function(type) {
        gstType = (type === 'inclusive') ? 'inclusive' : 'exclusive';
        $('#gstType').val(gstType);

        if (gstType === 'exclusive') {
            $('#gstExclusiveBtn').addClass('active');
            $('#gstInclusiveBtn').removeClass('active');
        } else {
            $('#gstInclusiveBtn').addClass('active');
            $('#gstExclusiveBtn').removeClass('active');
        }

        // Recalculate all items when type changes
        recalculateAllItems();
    };

    // --------------------------
    // Supplier Selection
    // --------------------------
    $('#supplierSelect').on('select2:select', function(e) {
        const data = e.params.data;
        const meta = data.meta || {};

        $('#supplierInfo').show();
        $('#supplierPhone').text(meta.phone || '-');
        $('#supplierGST').text(meta.gst_number || '-');
        $('#supplierBalance').text('₹' + money2(meta.opening_balance || 0));
    });

    $('#supplierSelect').on('select2:clear', function() {
        $('#supplierInfo').hide();
    });

    // --------------------------
    // Category Selection (SHOW CURRENT PRICE WITH GST)
    // --------------------------
    function renderCategoryMeta() {
        if (!selectedCategory) return;

        const meta = selectedCategory.meta || {};
        const currentIncl = parseFloat(meta.purchase_price || 0);

        // read current GST dropdown
        const gstValues = ($('#gstSelect').val() || '0,0').split(',');
        const cgst = parseFloat(gstValues[0]) || 0;
        const sgst = parseFloat(gstValues[1]) || 0;
        const gstPercent = cgst + sgst;

        // show excl derived (for info)
        let currentExcl = currentIncl;
        if (gstPercent > 0) currentExcl = currentIncl / (1 + gstPercent / 100);

        $('#categoryMeta').html(`
            <span class="badge bg-info text-white me-2">
                <i class="bi bi-box"></i> Stock: ${(meta.total_quantity || 0)} pcs
            </span>
            <span class="badge bg-success text-white me-2">
                <i class="bi bi-tag"></i> Current Price (Incl GST): ₹${money2(currentIncl)}/pc
            </span>
            <span class="badge bg-light text-dark">
                Excl GST: ₹${money2(currentExcl)}/pc (GST ${money2(gstPercent)}%)
            </span>
        `);
    }

    $('#categorySelect').on('select2:select', function(e) {
        selectedCategory = e.params.data;
        renderCategoryMeta();

        $('#kgInput').prop('disabled', false);
        $('#pricePerKgInput').prop('disabled', false);
        $('#totalPriceInput').prop('disabled', false);

        checkAddButton();
        updateConversionPreview();
    });

    $('#categorySelect').on('select2:clear', function() {
        selectedCategory = null;
        $('#categoryMeta').empty();
        $('#kgInput').prop('disabled', true).val('');
        $('#pricePerKgInput').prop('disabled', true).val('');
        $('#totalPriceInput').prop('disabled', true).val('');
        $('#addItemBtn').prop('disabled', true);
        $('#conversionPreview').hide();
    });

    // Re-render meta whenever GST dropdown changes
    $('#gstSelect').on('change', function() {
        if (selectedCategory) renderCategoryMeta();
        // also line preview changes (since gst affects totals)
        updateConversionPreview();
    });

    // --------------------------
    // Price Calculation Logic (kg, price/kg, total)
    // --------------------------
    $('#kgInput, #pricePerKgInput, #totalPriceInput').on('input', function() {
        const sourceId = $(this).attr('id');
        const kg = parseFloat($('#kgInput').val()) || 0;
        const pricePerKg = parseFloat($('#pricePerKgInput').val()) || 0;
        let totalPrice = parseFloat($('#totalPriceInput').val()) || 0;

        if (sourceId === 'pricePerKgInput' && pricePerKg > 0 && kg > 0) {
            totalPrice = pricePerKg * kg;
            $('#totalPriceInput').val(totalPrice.toFixed(2));
        } else if (sourceId === 'totalPriceInput' && totalPrice > 0 && kg > 0) {
            const calculatedPricePerKg = totalPrice / kg;
            $('#pricePerKgInput').val(calculatedPricePerKg.toFixed(2));
        } else if (sourceId === 'kgInput' && pricePerKg > 0) {
            totalPrice = pricePerKg * kg;
            $('#totalPriceInput').val(totalPrice.toFixed(2));
        }

        checkAddButton();
        updateConversionPreview();
    });

    function checkAddButton() {
        const kg = parseFloat($('#kgInput').val() || 0);
        const totalPrice = parseFloat($('#totalPriceInput').val() || 0);
        $('#addItemBtn').prop('disabled', !(selectedCategory && kg > 0 && totalPrice > 0));
    }

    // --------------------------
    // GST Calculator
    // totalPrice is the user-entered "Total Price" field.
    // If gstType == inclusive => totalPrice includes GST.
    // If gstType == exclusive => totalPrice excludes GST.
    // --------------------------
    function calculateGST(totalPrice, cgst, sgst) {
        const totalGstPercent = (parseFloat(cgst) || 0) + (parseFloat(sgst) || 0);

        if (gstType === 'inclusive') {
            if (totalGstPercent <= 0) {
                return { taxable: totalPrice, cgst_amt: 0, sgst_amt: 0, total: totalPrice };
            }
            const gstFactor = 1 + (totalGstPercent / 100);
            const baseAmount = totalPrice / gstFactor;
            const gstAmount = totalPrice - baseAmount;
            return {
                taxable: baseAmount,
                cgst_amt: gstAmount / 2,
                sgst_amt: gstAmount / 2,
                total: totalPrice
            };
        } else {
            // exclusive
            const cgst_amt = (totalPrice * (parseFloat(cgst) || 0)) / 100;
            const sgst_amt = (totalPrice * (parseFloat(sgst) || 0)) / 100;
            return {
                taxable: totalPrice,
                cgst_amt: cgst_amt,
                sgst_amt: sgst_amt,
                total: totalPrice + cgst_amt + sgst_amt
            };
        }
    }

    // --------------------------
    // Conversion Preview
    // --------------------------
    function updateConversionPreview() {
        if (!selectedCategory) return;

        const meta = selectedCategory.meta || {};
        const kg = parseFloat($('#kgInput').val() || 0);
        const totalPrice = parseFloat($('#totalPriceInput').val() || 0);
        const pricePerKg = parseFloat($('#pricePerKgInput').val() || 0);

        if (!(kg > 0 && totalPrice > 0 && parseFloat(meta.gram_value) > 0)) {
            $('#conversionPreview').hide();
            return;
        }

        const gramValue = parseFloat(meta.gram_value);
        const pcsPerKg = 1000 / gramValue;
        const totalPcs = pcsPerKg * kg;
        
        // Calculate price per piece based on total amount
        const pricePerPc = totalPrice / totalPcs;

        $('#previewGram').text(gramValue.toFixed(3) + ' g');
        $('#previewPcsPerKg').text(pcsPerKg.toFixed(2) + ' pcs');
        $('#previewPcs').text(totalPcs.toFixed(2) + ' pcs');
        $('#previewPricePerPc').text('₹' + money2(pricePerPc));
        $('#previewPricePerKg').text('₹' + money2(pricePerKg > 0 ? pricePerKg : (totalPrice / kg)));

        $('#conversionPreview').show();
    }

    // --------------------------
    // Add Item
    // --------------------------
    $('#addItemBtn').click(function() {
        if (!selectedCategory) return;

        const catMeta = selectedCategory.meta || {};
        const kg = parseFloat($('#kgInput').val()) || 0;
        const totalPriceInput = parseFloat($('#totalPriceInput').val()) || 0;

        if (!(kg > 0 && totalPriceInput > 0)) return;

        if (!catMeta.gram_value || parseFloat(catMeta.gram_value) <= 0) {
            Swal.fire({ icon: 'error', title: 'Invalid Category', text: 'Invalid gram value for this category' });
            return;
        }

        // GST selected
        const gstValues = ($('#gstSelect').val() || '0,0').split(',');
        const cgst = parseFloat(gstValues[0]) || 0;
        const sgst = parseFloat(gstValues[1]) || 0;

        // Calculate pcs + per pc
        const pcs_per_kg = 1000 / parseFloat(catMeta.gram_value);
        const total_pcs = pcs_per_kg * kg;
        
        // GST result based on gstType + entered totalPriceInput
        const gstResult = calculateGST(totalPriceInput, cgst, sgst);
        
        // Calculate price per piece from total amount (including GST)
        const price_per_pc = gstResult.total / total_pcs;

        const item = {
            id: Date.now() + Math.random(),
            cat_id: selectedCategory.id,
            cat_name: (catMeta.category_name || (selectedCategory.text || '').split('•')[0].trim()),
            gram_value: parseFloat(catMeta.gram_value) || 0,
            hsn_code: catMeta.hsn_code || '',
            cgst: cgst,
            sgst: sgst,
            cgst_amt: gstResult.cgst_amt,
            sgst_amt: gstResult.sgst_amt,
            taxable: gstResult.taxable,
            total: gstResult.total,
            kg_qty: kg,
            qty: total_pcs,
            unit: 'pcs',
            purchase_price: price_per_pc,        // per pc (based on total amount)
            price_per_kg: totalPriceInput / kg,   // per kg
            _base_entered: totalPriceInput        // store base entered amount for recalculation
        };

        items.push(item);
        renderItems();

        // Clear input
        $('#kgInput').val('');
        $('#pricePerKgInput').val('');
        $('#totalPriceInput').val('');
        $('#categorySelect').val(null).trigger('change');
        selectedCategory = null;
        $('#conversionPreview').hide();
        $('#addItemBtn').prop('disabled', true);
    });

    // --------------------------
    // Render Items + Totals
    // --------------------------
    function renderItems() {
        const tbody = $('#itemsBody');
        tbody.empty();

        if (items.length === 0) {
            tbody.html('<tr><td colspan="11" class="text-center py-4 text-muted">No items added yet</td></tr>');

            $('#totalPrice').text('₹0.00');
            $('#totalGST').text('₹0.00');
            $('#totalAmount').text('₹0.00');

            $('#totalPurchaseDisplay').text('₹0.00');
            $('#totalGSTDisplay').text('₹0.00');
            $('#grandTotalDisplay').text('₹0.00');
            $('#itemCount').text('0 items');

            $('#items_json').val(JSON.stringify(items));
            updatePaymentTotals();
            return;
        }

        let totalTaxable = 0;
        let totalGstAmt = 0;
        let totalAmount = 0;

        items.forEach((item, index) => {
            totalTaxable += (parseFloat(item.taxable) || 0);
            totalGstAmt += (parseFloat(item.cgst_amt) || 0) + (parseFloat(item.sgst_amt) || 0);
            totalAmount += (parseFloat(item.total) || 0);

            const row = `
                <tr>
                    <td>${index + 1}</td>
                    <td class="fw-semibold">${escapeHtml(item.cat_name)}</td>
                    <td class="text-end">${(parseFloat(item.gram_value) || 0).toFixed(3)} g</td>
                    <td class="text-end">${(parseFloat(item.kg_qty) || 0).toFixed(3)} kg</td>
                    <td class="text-end">${(parseFloat(item.qty) || 0).toFixed(2)} pcs</td>
                    <td class="text-end">₹${money2(item.purchase_price)}</td>
                    <td class="text-end">₹${money2(item.price_per_kg)}</td>
                    <td class="text-end">₹${money2(item.taxable)}</td>
                    <td class="text-end">
                        ${(parseFloat(item.cgst) + parseFloat(item.sgst)).toFixed(2)}%<br>
                        <small>₹${money2((parseFloat(item.cgst_amt)||0) + (parseFloat(item.sgst_amt)||0))}</small>
                    </td>
                    <td class="text-end fw-bold">₹${money2(item.total)}</td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-danger" onclick="removeItem(${index})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            tbody.append(row);
        });

        $('#totalPrice').text('₹' + money2(totalTaxable));
        $('#totalGST').text('₹' + money2(totalGstAmt));
        $('#totalAmount').text('₹' + money2(totalAmount));

        $('#totalPurchaseDisplay').text('₹' + money2(totalTaxable));
        $('#totalGSTDisplay').text('₹' + money2(totalGstAmt));
        $('#grandTotalDisplay').text('₹' + money2(totalAmount));

        $('#itemCount').text(items.length + ' items');
        $('#items_json').val(JSON.stringify(items));

        updatePaymentTotals();
    }

    // --------------------------
    // Recalculate All Items when GST Type changes
    // --------------------------
    function recalculateAllItems() {
        if (items.length === 0) return;

        items = items.map(it => {
            const cgst = parseFloat(it.cgst || 0);
            const sgst = parseFloat(it.sgst || 0);

            // Use stored base entered amount
            let baseEntered = parseFloat(it._base_entered || 0);
            if (!(baseEntered > 0)) {
                // derive if not stored
                baseEntered = (gstType === 'inclusive')
                    ? parseFloat(it.total || 0)
                    : parseFloat(it.taxable || 0);
            }

            // Now compute according to NEW gstType
            const gstResult = calculateGST(baseEntered, cgst, sgst);
            
            // Recalculate price per piece from new total
            const totalPcs = parseFloat(it.qty || 0);
            const new_price_per_pc = totalPcs > 0 ? gstResult.total / totalPcs : 0;

            return {
                ...it,
                _base_entered: baseEntered,
                taxable: gstResult.taxable,
                cgst_amt: gstResult.cgst_amt,
                sgst_amt: gstResult.sgst_amt,
                total: gstResult.total,
                purchase_price: new_price_per_pc
            };
        });

        renderItems();
    }

    // --------------------------
    // Remove Item
    // --------------------------
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

    // --------------------------
    // Payment Functions with Bank Account Support
    // --------------------------
    window.addPayment = function(amount = '', method = 'cash', notes = '', bankAccountId = null) {
        const paymentId = Date.now() + Math.random();
        
        // Determine if bank fields should be shown
        const showBankFields = (method === 'upi' || method === 'card' || method === 'bank');
        
        // Generate bank account dropdown HTML
        let bankAccountHtml = '';
        if (showBankFields) {
            const selectedId = bankAccountId || (lastBankAccount ? lastBankAccount.id : '');
            
            bankAccountHtml = `
                <div class="row mt-2">
                    <div class="col-md-12">
                        <div class="bank-selection-row">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="bank-selection-label"><i class="bi bi-bank"></i> Bank Account</label>
                                    <select class="form-select form-select-sm" name="payments[${paymentId}][bank_account_id]">
                                        <option value="">Select Bank Account</option>
                                        ${bankAccounts.map(acc => 
                                            `<option value="${acc.id}" ${selectedId == acc.id ? 'selected' : ''}>
                                                ${escapeHtml(acc.account_name)} - ${escapeHtml(acc.bank_name)} (Balance: ₹${parseFloat(acc.current_balance).toFixed(2)})
                                                ${acc.is_default ? ' [Default]' : ''}
                                            </option>`
                                        ).join('')}
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">UPI Ref/Trx ID</label>
                                    <input type="text" class="form-control form-control-sm" 
                                           name="payments[${paymentId}][upi_ref_no]" placeholder="Reference">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Transaction Ref</label>
                                    <input type="text" class="form-control form-control-sm" 
                                           name="payments[${paymentId}][transaction_ref_no]" placeholder="Transaction ID">
                                </div>
                            </div>
                            <div class="row mt-1">
                                <div class="col-md-12">
                                    <small class="text-muted"><i class="bi bi-info-circle"></i> Transaction will be recorded in selected bank account</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        const paymentHtml = `
            <div class="payment-card" id="payment-${paymentId}">
                <span class="remove-payment" onclick="removePayment('${paymentId}')">
                    <i class="bi bi-x-lg"></i>
                </span>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Amount (₹)</label>
                        <input type="number" class="form-control form-control-sm payment-amount"
                               name="payments[${paymentId}][amount]" step="0.01" min="0"
                               value="${amount}" required onchange="updatePaymentTotals()" onkeyup="updatePaymentTotals()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Method</label>
                        <select class="form-select form-select-sm payment-method" name="payments[${paymentId}][method]" onchange="toggleBankFields(this, '${paymentId}')">
                            <option value="cash" ${method === 'cash' ? 'selected' : ''}>Cash</option>
                            <option value="card" ${method === 'card' ? 'selected' : ''}>Card</option>
                            <option value="upi" ${method === 'upi' ? 'selected' : ''}>UPI</option>
                            <option value="bank" ${method === 'bank' ? 'selected' : ''}>Bank Transfer</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Notes</label>
                        <input type="text" class="form-control form-control-sm"
                               name="payments[${paymentId}][notes]" placeholder="Optional" value="${escapeHtml(notes)}">
                    </div>
                </div>
                <div id="bank-fields-${paymentId}">
                    ${bankAccountHtml}
                </div>
            </div>
        `;

        $('#paymentsContainer').append(paymentHtml);
        
        // Save bank account selection to cookie when changed
        $(`#payment-${paymentId} select[name$="[bank_account_id]"]`).on('change', function() {
            const accountId = this.value;
            if (accountId) {
                document.cookie = "last_purchase_bank_account=" + accountId + "; path=/; max-age=" + (30 * 24 * 60 * 60);
            }
        });
        
        updatePaymentTotals();
    };

    window.toggleBankFields = function(select, paymentId) {
        const method = select.value;
        const bankFieldsDiv = document.getElementById('bank-fields-' + paymentId);
        
        if (method === 'upi' || method === 'card' || method === 'bank') {
            // Check if bank fields already exist
            if (!bankFieldsDiv.querySelector('.bank-selection-row')) {
                // Create bank fields
                const selectedId = lastBankAccount ? lastBankAccount.id : '';
                
                let bankHtml = `
                    <div class="row mt-2">
                        <div class="col-md-12">
                            <div class="bank-selection-row">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="bank-selection-label"><i class="bi bi-bank"></i> Bank Account</label>
                                        <select class="form-select form-select-sm" name="payments[${paymentId}][bank_account_id]">
                                            <option value="">Select Bank Account</option>
                `;
                
                bankAccounts.forEach(acc => {
                    bankHtml += `<option value="${acc.id}" ${selectedId == acc.id ? 'selected' : ''}>
                                    ${escapeHtml(acc.account_name)} - ${escapeHtml(acc.bank_name)} (Balance: ₹${parseFloat(acc.current_balance).toFixed(2)})
                                    ${acc.is_default ? ' [Default]' : ''}
                                </option>`;
                });
                
                bankHtml += `
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">UPI Ref/Trx ID</label>
                                        <input type="text" class="form-control form-control-sm" 
                                               name="payments[${paymentId}][upi_ref_no]" placeholder="Reference">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Transaction Ref</label>
                                        <input type="text" class="form-control form-control-sm" 
                                               name="payments[${paymentId}][transaction_ref_no]" placeholder="Transaction ID">
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-md-12">
                                        <small class="text-muted"><i class="bi bi-info-circle"></i> Transaction will be recorded in selected bank account</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                bankFieldsDiv.innerHTML = bankHtml;
                
                // Add change event to save cookie
                $(`#payment-${paymentId} select[name$="[bank_account_id]"]`).on('change', function() {
                    const accountId = this.value;
                    if (accountId) {
                        document.cookie = "last_purchase_bank_account=" + accountId + "; path=/; max-age=" + (30 * 24 * 60 * 60);
                    }
                });
            }
        } else {
            // Remove bank fields
            bankFieldsDiv.innerHTML = '';
        }
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

        $('#totalPaidDisplay').text('₹' + money2(totalPaid));
        $('#balanceDueDisplay').text('₹' + money2(balance));
    }

    // Update payment totals on input
    $(document).on('input', 'input[name$="[amount]"]', function() {
        updatePaymentTotals();
    });

    // --------------------------
    // Form Validation
    // --------------------------
    $('#purchaseForm').submit(function(e) {
        if (items.length === 0) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'No Items', text: 'Please add at least one item to the purchase.' });
            return false;
        }

        const supplierId = $('#supplierSelect').val();
        if (!supplierId) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'No Supplier', text: 'Please select a supplier.' });
            return false;
        }

        // Validate bank accounts for UPI/Card/Bank payments
        let bankValidationError = false;
        $('.payment-card').each(function() {
            const method = $(this).find('.payment-method').val();
            if (method === 'upi' || method === 'card' || method === 'bank') {
                const bankAccountId = $(this).find('select[name$="[bank_account_id]"]').val();
                const amount = parseFloat($(this).find('input[name$="[amount]"]').val() || 0);
                
                if (amount > 0 && !bankAccountId) {
                    bankValidationError = true;
                    $(this).addClass('border border-danger');
                } else {
                    $(this).removeClass('border border-danger');
                }
            }
        });

        if (bankValidationError) {
            e.preventDefault();
            Swal.fire({ 
                icon: 'error', 
                title: 'Bank Account Required', 
                text: 'Please select a bank account for all UPI, Card, or Bank payments.'
            });
            return false;
        }

        // Collect payments with amount > 0
        const paymentData = [];
        $('.payment-card').each(function() {
            const amount = parseFloat($(this).find('input[name$="[amount]"]').val() || 0);
            if (amount > 0) {
                const method = $(this).find('.payment-method').val();
                const bankAccountId = $(this).find('select[name$="[bank_account_id]"]').val();
                const upiRefNo = $(this).find('input[name$="[upi_ref_no]"]').val() || '';
                const transactionRefNo = $(this).find('input[name$="[transaction_ref_no]"]').val() || '';
                
                paymentData.push({
                    amount: amount.toFixed(2),
                    method: method,
                    notes: $(this).find('input[name$="[notes]"]').val() || '',
                    bank_account_id: bankAccountId || '',
                    upi_ref_no: upiRefNo,
                    transaction_ref_no: transactionRefNo
                });
            }
        });

        // Clear existing dynamic payment fields and append clean indexed fields
        $('input[name^="payments["]').remove();

        paymentData.forEach((p, i) => {
            $('<input>', { type:'hidden', name:`payments[${i}][amount]`, value:p.amount }).appendTo('#purchaseForm');
            $('<input>', { type:'hidden', name:`payments[${i}][method]`, value:p.method }).appendTo('#purchaseForm');
            $('<input>', { type:'hidden', name:`payments[${i}][notes]`,  value:p.notes }).appendTo('#purchaseForm');
            
            if (p.bank_account_id) {
                $('<input>', { type:'hidden', name:`payments[${i}][bank_account_id]`, value:p.bank_account_id }).appendTo('#purchaseForm');
                $('<input>', { type:'hidden', name:`payments[${i}][upi_ref_no]`, value:p.upi_ref_no }).appendTo('#purchaseForm');
                $('<input>', { type:'hidden', name:`payments[${i}][transaction_ref_no]`, value:p.transaction_ref_no }).appendTo('#purchaseForm');
            }
        });

        // Disable submit
        $('#submitBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');
    });

    // --------------------------
    // Quick Add Category Form
    // --------------------------
    $('#quickAddCategoryForm').submit(function() {
        $('#quickAddSubmit').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Adding...');
    });

    // --------------------------
    // Default Payment
    // --------------------------
    setTimeout(() => { 
        // Add default payment with last used bank account if available
        const defaultBankId = lastBankAccount ? lastBankAccount.id : null;
        addPayment('', 'cash', '', defaultBankId); 
    }, 400);

})();
</script>
</body>
</html>