<?php
session_start();
$currentPage = 'categories';
$pageTitle = 'Categories Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view categories, but only admin can modify
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Handle add category (POST only) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to add categories.';
    } else {
        $category_name = trim($_POST['category_name'] ?? '');
        $purchase_price = floatval($_POST['purchase_price'] ?? 0);
        $gram_value = floatval($_POST['gram_value'] ?? 0);
        $min_stock_level = floatval($_POST['min_stock_level'] ?? 0);
        $total_quantity = floatval($_POST['total_quantity'] ?? 0);

        if (empty($category_name)) {
            $error = 'Category name is required.';
        } else {
            // Check if category exists
            $check = $conn->prepare("SELECT id FROM category WHERE category_name = ?");
            $check->bind_param("s", $category_name);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $error = 'Category already exists. Please choose a different name.';
            } else {
                $stmt = $conn->prepare("INSERT INTO category (category_name, purchase_price, gram_value, min_stock_level, total_quantity) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sdddd", $category_name, $purchase_price, $gram_value, $min_stock_level, $total_quantity);
                
                if ($stmt->execute()) {
                    $category_id = $stmt->insert_id;
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', 'Created new category: " . $conn->real_escape_string($category_name) . "')";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("i", $_SESSION['user_id']);
                    $log_stmt->execute();
                    
                    $success = "Category added successfully.";
                } else {
                    $error = "Failed to add category.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle edit category (POST only) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_category' && isset($_POST['category_id']) && is_numeric($_POST['category_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to edit categories.';
    } else {
        $editId = intval($_POST['category_id']);
        $category_name = trim($_POST['category_name'] ?? '');
        $purchase_price = floatval($_POST['purchase_price'] ?? 0);
        $gram_value = floatval($_POST['gram_value'] ?? 0);
        $min_stock_level = floatval($_POST['min_stock_level'] ?? 0);
        $total_quantity = floatval($_POST['total_quantity'] ?? 0);

        if (empty($category_name)) {
            $error = 'Category name is required.';
        } else {
            // Check if category name exists for other categories
            $check = $conn->prepare("SELECT id FROM category WHERE category_name = ? AND id != ?");
            $check->bind_param("si", $category_name, $editId);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $error = 'Category name already exists. Please choose a different name.';
            } else {
                $stmt = $conn->prepare("UPDATE category SET category_name=?, purchase_price=?, gram_value=?, min_stock_level=?, total_quantity=? WHERE id=?");
                $stmt->bind_param("sddddi", $category_name, $purchase_price, $gram_value, $min_stock_level, $total_quantity, $editId);
                
                if ($stmt->execute()) {
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', 'Updated category: " . $conn->real_escape_string($category_name) . "')";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("i", $_SESSION['user_id']);
                    $log_stmt->execute();
                    
                    $success = "Category updated successfully.";
                } else {
                    $error = "Failed to update category.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle delete category (POST only) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_category' && isset($_POST['category_id']) && is_numeric($_POST['category_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete categories.';
    } else {
        $deleteId = intval($_POST['category_id']);
        
        // Check if category is used in purchase items (since product table doesn't have cat_id)
        $check_purchase = $conn->prepare("SELECT id FROM purchase_item WHERE cat_id = ? LIMIT 1");
        $check_purchase->bind_param("i", $deleteId);
        $check_purchase->execute();
        $check_purchase->store_result();
        
        // Also check in invoice_item if it has cat_id
        $check_invoice = $conn->prepare("SELECT id FROM invoice_item WHERE cat_id = ? LIMIT 1");
        $check_invoice->bind_param("i", $deleteId);
        $check_invoice->execute();
        $check_invoice->store_result();
        
        // Check in quotation_item if it has cat_id
        $check_quotation = $conn->prepare("SELECT id FROM quotation_item WHERE cat_id = ? LIMIT 1");
        $check_quotation->bind_param("i", $deleteId);
        $check_quotation->execute();
        $check_quotation->store_result();
        
        if ($check_purchase->num_rows > 0 || $check_invoice->num_rows > 0 || $check_quotation->num_rows > 0) {
            $error = "Cannot delete category. It is being used in purchase, invoice, or quotation records.";
        } else {
            // Get category name for logging
            $cat_query = $conn->prepare("SELECT category_name FROM category WHERE id = ?");
            $cat_query->bind_param("i", $deleteId);
            $cat_query->execute();
            $cat_result = $cat_query->get_result();
            $cat_data = $cat_result->fetch_assoc();
            $category_name = $cat_data['category_name'] ?? 'Unknown';
            
            $stmt = $conn->prepare("DELETE FROM category WHERE id = ?");
            $stmt->bind_param("i", $deleteId);
            
            if ($stmt->execute()) {
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', 'Deleted category: " . $conn->real_escape_string($category_name) . "')";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("i", $_SESSION['user_id']);
                $log_stmt->execute();
                
                $success = "Category deleted successfully.";
            } else {
                $error = "Failed to delete category.";
            }
            $stmt->close();
        }
        $check_purchase->close();
        $check_invoice->close();
        $check_quotation->close();
    }
}

// Handle stock update (add/subtract) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stock' && isset($_POST['category_id']) && is_numeric($_POST['category_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to update stock.';
    } else {
        $updateId = intval($_POST['category_id']);
        $stock_change = floatval($_POST['stock_change'] ?? 0);
        $operation = $_POST['operation'] ?? 'add'; // 'add' or 'subtract'
        
        if ($stock_change <= 0) {
            $error = "Please enter a valid quantity.";
        } else {
            // Get current stock
            $current_query = $conn->prepare("SELECT category_name, total_quantity FROM category WHERE id = ?");
            $current_query->bind_param("i", $updateId);
            $current_query->execute();
            $current_result = $current_query->get_result();
            $current_data = $current_result->fetch_assoc();
            
            if (!$current_data) {
                $error = "Category not found.";
            } else {
                $new_quantity = $current_data['total_quantity'];
                
                if ($operation === 'add') {
                    $new_quantity += $stock_change;
                    $operation_text = 'added to';
                } else {
                    if ($current_data['total_quantity'] < $stock_change) {
                        $error = "Insufficient stock. Current stock: " . number_format($current_data['total_quantity'], 2);
                    } else {
                        $new_quantity -= $stock_change;
                        $operation_text = 'subtracted from';
                    }
                }
                
                if (empty($error)) {
                    $stmt = $conn->prepare("UPDATE category SET total_quantity = ? WHERE id = ?");
                    $stmt->bind_param("di", $new_quantity, $updateId);
                    
                    if ($stmt->execute()) {
                        // Log activity
                        $log_desc = "Stock " . $operation_text . " category '" . $current_data['category_name'] . "': " . number_format($stock_change, 2) . " units";
                        $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                        $log_stmt->execute();
                        
                        $success = "Stock updated successfully. New quantity: " . number_format($new_quantity, 2) . " units";
                    } else {
                        $error = "Failed to update stock.";
                    }
                    $stmt->close();
                }
            }
            $current_query->close();
        }
    }
}

// Handle bulk import categories
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_import') {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to import categories.';
    } else {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['csv_file']['tmp_name'];
            
            // Read the file content and remove BOM if present
            $content = file_get_contents($file);
            // Remove UTF-8 BOM if present
            if (substr($content, 0, 3) == "\xEF\xBB\xBF") {
                $content = substr($content, 3);
            }
            
            // Create a temporary file with cleaned content
            $temp_file = tmpfile();
            fwrite($temp_file, $content);
            fseek($temp_file, 0);
            
            // Get header row
            $header = fgetcsv($temp_file);
            
            // Clean header values (remove extra spaces, convert to lowercase)
            $header = array_map(function($value) {
                return trim(strtolower($value));
            }, $header);
            
            // Expected header (all lowercase for comparison)
            $expected_header = ['category_name', 'purchase_price', 'gram_value', 'min_stock_level', 'total_quantity'];
            
            // Validate header (check if all expected columns exist)
            $header_valid = true;
            foreach ($expected_header as $expected) {
                if (!in_array($expected, $header)) {
                    $header_valid = false;
                    break;
                }
            }
            
            if (!$header_valid) {
                $error = "Invalid CSV format. Expected columns: " . implode(', ', $expected_header) . ". Found: " . implode(', ', $header);
                fclose($temp_file);
            } else {
                $success_count = 0;
                $error_count = 0;
                $import_errors = [];
                $row_num = 1; // Start from row 1 (after header)
                
                // Get column indexes
                $name_index = array_search('category_name', $header);
                $price_index = array_search('purchase_price', $header);
                $gram_index = array_search('gram_value', $header);
                $min_index = array_search('min_stock_level', $header);
                $qty_index = array_search('total_quantity', $header);
                
                while (($data = fgetcsv($temp_file)) !== FALSE) {
                    $row_num++;
                    
                    // Skip empty rows
                    if (empty(array_filter($data))) {
                        continue;
                    }
                    
                    // Validate row has enough columns
                    if (count($data) < count($expected_header)) {
                        $import_errors[] = "Row $row_num: Insufficient columns";
                        $error_count++;
                        continue;
                    }
                    
                    $category_name = trim($data[$name_index] ?? '');
                    $purchase_price = floatval($data[$price_index] ?? 0);
                    $gram_value = floatval($data[$gram_index] ?? 0);
                    $min_stock_level = floatval($data[$min_index] ?? 0);
                    $total_quantity = floatval($data[$qty_index] ?? 0);
                    
                    if (empty($category_name)) {
                        $import_errors[] = "Row $row_num: Category name is required";
                        $error_count++;
                        continue;
                    }
                    
                    // Check if category already exists
                    $check = $conn->prepare("SELECT id FROM category WHERE category_name = ?");
                    $check->bind_param("s", $category_name);
                    $check->execute();
                    $check->store_result();
                    
                    if ($check->num_rows > 0) {
                        // Update existing category
                        $stmt = $conn->prepare("UPDATE category SET purchase_price = ?, gram_value = ?, min_stock_level = ?, total_quantity = ? WHERE category_name = ?");
                        $stmt->bind_param("dddds", $purchase_price, $gram_value, $min_stock_level, $total_quantity, $category_name);
                        
                        if ($stmt->execute()) {
                            $success_count++;
                            // Log activity
                            $log_desc = "Bulk import updated category: " . $category_name;
                            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'import', ?)";
                            $log_stmt = $conn->prepare($log_query);
                            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                            $log_stmt->execute();
                        } else {
                            $import_errors[] = "Row $row_num: Failed to update - " . $conn->error;
                            $error_count++;
                        }
                        $stmt->close();
                    } else {
                        // Insert new category
                        $stmt = $conn->prepare("INSERT INTO category (category_name, purchase_price, gram_value, min_stock_level, total_quantity) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("sdddd", $category_name, $purchase_price, $gram_value, $min_stock_level, $total_quantity);
                        
                        if ($stmt->execute()) {
                            $success_count++;
                            // Log activity
                            $log_desc = "Bulk import added category: " . $category_name;
                            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'import', ?)";
                            $log_stmt = $conn->prepare($log_query);
                            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                            $log_stmt->execute();
                        } else {
                            $import_errors[] = "Row $row_num: Failed to insert - " . $conn->error;
                            $error_count++;
                        }
                        $stmt->close();
                    }
                    $check->close();
                }
                
                fclose($temp_file);
                
                if ($success_count > 0) {
                    $success = "Bulk import completed: $success_count categories processed successfully.";
                    if ($error_count > 0) {
                        $success .= " $error_count errors occurred.";
                    }
                    
                    // Store errors in session to display
                    if (!empty($import_errors)) {
                        $_SESSION['import_errors'] = $import_errors;
                    }
                } else {
                    $error = "No categories were imported. Please check your CSV file.";
                }
            }
        } else {
            $error = "Please select a CSV file to upload.";
        }
    }
}

// Handle sample CSV download
if (isset($_GET['action']) && $_GET['action'] === 'download_sample') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="categories_sample.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add headers (no BOM to avoid issues)
    fputcsv($output, ['category_name', 'purchase_price', 'gram_value', 'min_stock_level', 'total_quantity']);
    
    // Add sample data
    fputcsv($output, ['Sample Category 1', '100.00', '500.000', '10.000', '25.000']);
    fputcsv($output, ['Sample Category 2', '150.00', '750.000', '5.000', '15.000']);
    fputcsv($output, ['Sample Category 3', '200.00', '1000.000', '8.000', '30.000']);
    fputcsv($output, ['Electronics', '500.00', '2000.000', '5.000', '20.000']);
    fputcsv($output, ['Furniture', '1000.00', '5000.000', '2.000', '10.000']);
    
    fclose($output);
    exit;
}

// Filters
$filterStock = $_GET['filter_stock'] ?? '';

$where = "1=1";

if ($filterStock === 'low') {
    $where .= " AND total_quantity <= min_stock_level AND min_stock_level > 0";
} elseif ($filterStock === 'out') {
    $where .= " AND total_quantity <= 0";
} elseif ($filterStock === 'in') {
    $where .= " AND total_quantity > min_stock_level";
}

$sql = "SELECT * FROM category WHERE $where ORDER BY category_name ASC";

$categories = $conn->query($sql);

// Stats
$totalCount = $conn->query("SELECT COUNT(*) as cnt FROM category")->fetch_assoc()['cnt'];
$lowStockCount = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity <= min_stock_level AND min_stock_level > 0")->fetch_assoc()['cnt'];
$outStockCount = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity <= 0")->fetch_assoc()['cnt'];
$totalStockValue = $conn->query("SELECT SUM(purchase_price * total_quantity) as total FROM category")->fetch_assoc()['total'];

// Format helpers
function formatQuantity($quantity, $unit = 'PCS') {
    if ($quantity === null || $quantity == 0) {
        return '0.00 ' . $unit;
    }
    return number_format($quantity, 2) . ' ' . $unit;
}

function formatPrice($price) {
    if ($price === null || $price == 0) {
        return '₹0.00';
    }
    return '₹' . number_format($price, 2);
}

// Stock status helper
function getStockStatus($current, $min) {
    if ($current <= 0) {
        return ['class' => 'cancelled', 'text' => 'Out of Stock'];
    } elseif ($current <= $min) {
        return ['class' => 'pending', 'text' => 'Low Stock'];
    } else {
        return ['class' => 'completed', 'text' => 'In Stock'];
    }
}

// Check if user is admin for action buttons
$is_admin = ($_SESSION['user_role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .stock-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .stock-indicator.high { background: #10b981; }
        .stock-indicator.medium { background: #f59e0b; }
        .stock-indicator.low { background: #ef4444; }
        
        .progress-sm {
            height: 6px;
            border-radius: 3px;
        }
        
        .value-highlight {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .value-muted {
            color: var(--text-muted);
            font-size: 12px;
        }
        
        .stock-bar-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stock-bar {
            flex: 1;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .stock-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .stock-bar-fill.critical { background: #ef4444; }
        .stock-bar-fill.warning { background: #f59e0b; }
        .stock-bar-fill.good { background: #10b981; }
        
        .category-stats-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #eef2f6;
        }
        
        .stat-label-sm {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 4px;
        }
        
        .stat-value-sm {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .quick-stock-form {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .quick-stock-input {
            width: 80px;
            padding: 4px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
        }
        
        .quick-stock-btn {
            padding: 4px 8px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
        }
        
        .quick-stock-btn.add {
            background: #10b981;
            color: white;
        }
        
        .quick-stock-btn.subtract {
            background: #ef4444;
            color: white;
        }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }

        /* Bulk import styles */
        .import-errors {
            max-height: 200px;
            overflow-y: auto;
            background: #fff1f0;
            border: 1px solid #ffccc7;
            border-radius: 6px;
            padding: 10px;
            margin-top: 10px;
            font-size: 12px;
        }
        
        .import-errors ul {
            margin: 0;
            padding-left: 20px;
            color: #cf1322;
        }
        
        .btn-outline-success {
            color: #10b981;
            border-color: #10b981;
        }
        
        .btn-outline-success:hover {
            background: #10b981;
            color: white;
        }
        
        .bulk-import-card {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 16px;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Categories Management</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage product categories and stock levels</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($is_admin): ?>
                        <!-- Bulk Import Button -->
                        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#bulkImportModal">
                            <i class="bi bi-upload"></i> Bulk Import
                        </button>
                        
                        <!-- Download Sample Button -->
                        <a href="?action=download_sample" class="btn btn-outline-secondary">
                            <i class="bi bi-download"></i> Sample CSV
                        </a>
                        
                        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addCategoryModal" data-testid="button-add-category">
                            <i class="bi bi-plus-circle"></i> Add New Category
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
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['import_errors'])): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <strong><i class="bi bi-exclamation-triangle-fill me-1"></i> Import Errors:</strong>
                    <div class="import-errors">
                        <ul>
                            <?php foreach ($_SESSION['import_errors'] as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['import_errors']); ?>
            <?php endif; ?>

            <!-- Bulk Import Info Card -->
            <?php if ($is_admin): ?>
                <div class="bulk-import-card">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle-fill text-primary"></i>
                        <span class="text-muted">Bulk import allows you to add or update multiple categories at once. </span>
                        <a href="?action=download_sample" class="text-primary ms-2">
                            <i class="bi bi-file-earmark-spreadsheet"></i> Download sample CSV
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-total">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon blue">
                                <i class="bi bi-tags"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Total Categories</div>
                                <div class="stat-value" data-testid="stat-value-total"><?php echo $totalCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-low-stock">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon orange">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Low Stock Alert</div>
                                <div class="stat-value" data-testid="stat-value-low"><?php echo $lowStockCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-out-stock">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon red">
                                <i class="bi bi-x-circle"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Out of Stock</div>
                                <div class="stat-value" data-testid="stat-value-out"><?php echo $outStockCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-value">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon green">
                                <i class="bi bi-currency-rupee"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Stock Value</div>
                                <div class="stat-value" data-testid="stat-value-total"><?php echo formatPrice($totalStockValue); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="dashboard-card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-3 flex-wrap filter-bar-inner">
                        <div class="d-flex gap-1 flex-wrap filter-tabs">
                            <a href="categories.php" class="btn btn-sm <?php echo !$filterStock ? 'btn-primary' : 'btn-outline-secondary'; ?>" data-testid="filter-all">
                                All <span class="badge bg-white text-dark ms-1"><?php echo $totalCount; ?></span>
                            </a>
                            <a href="categories.php?filter_stock=low" class="btn btn-sm <?php echo $filterStock === 'low' ? 'btn-warning' : 'btn-outline-secondary'; ?>" data-testid="filter-low">
                                Low Stock <span class="badge bg-white text-dark ms-1"><?php echo $lowStockCount; ?></span>
                            </a>
                            <a href="categories.php?filter_stock=out" class="btn btn-sm <?php echo $filterStock === 'out' ? 'btn-danger' : 'btn-outline-secondary'; ?>" data-testid="filter-out">
                                Out of Stock <span class="badge bg-white text-dark ms-1"><?php echo $outStockCount; ?></span>
                            </a>
                            <a href="categories.php?filter_stock=in" class="btn btn-sm <?php echo $filterStock === 'in' ? 'btn-success' : 'btn-outline-secondary'; ?>" data-testid="filter-in">
                                In Stock <span class="badge bg-white text-dark ms-1"><?php echo $totalCount - $lowStockCount - $outStockCount; ?></span>
                            </a>
                        </div>
                        <div class="ms-auto">
                            <a href="categories.php" class="btn btn-sm btn-outline-secondary" data-testid="clear-filters">
                                <i class="bi bi-x-circle"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Categories Table -->
            <div class="dashboard-card" data-testid="categories-table">
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="categoriesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Category Name</th>
                                <th>Purchase Price</th>
                                <th>Gram Value</th>
                                <th>Current Stock</th>
                                <th>Min Stock Level</th>
                                <th>Stock Status</th>
                                <th>Stock Value</th>
                                <th>Last Updated</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Quick Stock</th>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($categories && $categories->num_rows > 0): ?>
                                <?php while ($category = $categories->fetch_assoc()): 
                                    $stock_status = getStockStatus($category['total_quantity'], $category['min_stock_level']);
                                    $stock_percentage = $category['min_stock_level'] > 0 ? min(($category['total_quantity'] / $category['min_stock_level']) * 100, 100) : 100;
                                    $bar_class = $stock_percentage <= 25 ? 'critical' : ($stock_percentage <= 75 ? 'warning' : 'good');
                                ?>
                                    <tr data-testid="row-category-<?php echo $category['id']; ?>">
                                        <td><span class="order-id">#<?php echo $category['id']; ?></span></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($category['category_name']); ?></td>
                                        <td><?php echo formatPrice($category['purchase_price']); ?></td>
                                        <td><?php echo formatQuantity($category['gram_value'], ''); ?></td>
                                        <td>
                                            <div class="stock-bar-container">
                                                <span class="fw-semibold"><?php echo formatQuantity($category['total_quantity']); ?></span>
                                                <div class="stock-bar">
                                                    <div class="stock-bar-fill <?php echo $bar_class; ?>" style="width: <?php echo $stock_percentage; ?>%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo formatQuantity($category['min_stock_level']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $stock_status['class']; ?>">
                                                <span class="dot"></span>
                                                <?php echo $stock_status['text']; ?>
                                            </span>
                                        </td>
                                        <td class="fw-semibold"><?php echo formatPrice($category['purchase_price'] * $category['total_quantity']); ?></td>
                                        <td style="color: var(--text-muted); white-space: nowrap;"><?php echo date('M d, Y', strtotime($category['updated_at'])); ?></td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="quick-stock-form">
                                                    <form method="POST" action="categories.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="display: flex; gap: 5px;" onsubmit="return validateStockForm(this)">
                                                        <input type="hidden" name="action" value="update_stock">
                                                        <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                        <input type="hidden" name="operation" value="add">
                                                        <input type="number" name="stock_change" class="quick-stock-input" placeholder="Qty" step="0.001" min="0.001" required>
                                                        <button type="submit" class="quick-stock-btn add" title="Add Stock">
                                                            <i class="bi bi-plus"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="categories.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="display: flex; gap: 5px;" onsubmit="return validateStockForm(this)">
                                                        <input type="hidden" name="action" value="update_stock">
                                                        <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                        <input type="hidden" name="operation" value="subtract">
                                                        <input type="number" name="stock_change" class="quick-stock-input" placeholder="Qty" step="0.001" min="0.001" required>
                                                        <button type="submit" class="quick-stock-btn subtract" title="Remove Stock">
                                                            <i class="bi bi-dash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <!-- Edit -->
                                                    <button class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 3px 8px;" 
                                                            data-bs-toggle="modal" data-bs-target="#editCategoryModal<?php echo $category['id']; ?>" 
                                                            data-testid="button-edit-<?php echo $category['id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    
                                                    <!-- Delete -->
                                                    <form method="POST" action="categories.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this category? This action cannot be undone.')">
                                                        <input type="hidden" name="action" value="delete_category">
                                                        <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" 
                                                                data-testid="button-delete-<?php echo $category['id']; ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Edit Category Modal -->
                                    <div class="modal fade" id="editCategoryModal<?php echo $category['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="categories.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" data-testid="form-edit-category-<?php echo $category['id']; ?>">
                                                    <input type="hidden" name="action" value="edit_category">
                                                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit Category</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Category Name <span class="text-danger">*</span></label>
                                                            <input type="text" name="category_name" class="form-control" value="<?php echo htmlspecialchars($category['category_name']); ?>" required data-testid="input-edit-name-<?php echo $category['id']; ?>">
                                                        </div>
                                                        <div class="row g-3 mb-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label">Purchase Price (₹)</label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">₹</span>
                                                                    <input type="number" name="purchase_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($category['purchase_price']); ?>" data-testid="input-edit-price-<?php echo $category['id']; ?>">
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Gram Value </label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text"></span>
                                                                    <input type="number" name="gram_value" class="form-control" step="0.001" min="0" value="<?php echo htmlspecialchars($category['gram_value']); ?>" data-testid="input-edit-gram-<?php echo $category['id']; ?>">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row g-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label">Current Stock (piece)</label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">piece</span>
                                                                    <input type="number" name="total_quantity" class="form-control" step="0.001" min="0" value="<?php echo htmlspecialchars($category['total_quantity']); ?>" data-testid="input-edit-quantity-<?php echo $category['id']; ?>">
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Min Stock Level (piece)</label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">piece</span>
                                                                    <input type="number" name="min_stock_level" class="form-control" step="0.001" min="0" value="<?php echo htmlspecialchars($category['min_stock_level']); ?>" data-testid="input-edit-min-<?php echo $category['id']; ?>">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary" data-testid="button-save-edit-<?php echo $category['id']; ?>">Save Changes</button>
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
                    <?php
                        // Reset result pointer for mobile view
                        if ($categories && $categories->num_rows > 0) {
                            $categories->data_seek(0);
                        }
                    ?>
                    <?php if ($categories && $categories->num_rows > 0): ?>
                        <?php while ($mCategory = $categories->fetch_assoc()): 
                            $stock_status = getStockStatus($mCategory['total_quantity'], $mCategory['min_stock_level']);
                            $stock_percentage = $mCategory['min_stock_level'] > 0 ? min(($mCategory['total_quantity'] / $mCategory['min_stock_level']) * 100, 100) : 100;
                        ?>
                            <div class="mobile-card" data-testid="mobile-card-category-<?php echo $mCategory['id']; ?>">
                                <div class="mobile-card-header">
                                    <div>
                                        <span class="order-id">#<?php echo $mCategory['id']; ?></span>
                                        <span class="customer-name ms-2 fw-semibold"><?php echo htmlspecialchars($mCategory['category_name']); ?></span>
                                    </div>
                                    <span class="status-badge <?php echo $stock_status['class']; ?>">
                                        <span class="dot"></span>
                                        <?php echo $stock_status['text']; ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Purchase Price</span>
                                    <span class="mobile-card-value"><?php echo formatPrice($mCategory['purchase_price']); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Gram Value</span>
                                    <span class="mobile-card-value"><?php echo formatQuantity($mCategory['gram_value'], ''); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Current Stock</span>
                                    <span class="mobile-card-value">
                                        <span class="fw-semibold"><?php echo formatQuantity($mCategory['total_quantity']); ?></span>
                                        <div class="stock-bar mt-1" style="width: 100%;">
                                            <div class="stock-bar-fill <?php echo $stock_percentage <= 25 ? 'critical' : ($stock_percentage <= 75 ? 'warning' : 'good'); ?>" style="width: <?php echo $stock_percentage; ?>%;"></div>
                                        </div>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Min Stock Level</span>
                                    <span class="mobile-card-value"><?php echo formatQuantity($mCategory['min_stock_level']); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Stock Value</span>
                                    <span class="mobile-card-value fw-semibold" style="color: var(--primary);"><?php echo formatPrice($mCategory['purchase_price'] * $mCategory['total_quantity']); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Last Updated</span>
                                    <span class="mobile-card-value"><?php echo date('M d, Y', strtotime($mCategory['updated_at'])); ?></span>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                    <div class="mobile-card-actions flex-wrap">
                                        <div class="d-flex gap-2 mb-2 w-100">
                                            <form method="POST" action="categories.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="flex: 1;" onsubmit="return validateStockForm(this)">
                                                <input type="hidden" name="action" value="update_stock">
                                                <input type="hidden" name="category_id" value="<?php echo $mCategory['id']; ?>">
                                                <input type="hidden" name="operation" value="add">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="stock_change" class="form-control" placeholder="Qty" step="0.001" min="0.001" required>
                                                    <button type="submit" class="btn btn-success" type="button">
                                                        <i class="bi bi-plus"></i> Add
                                                    </button>
                                                </div>
                                            </form>
                                            <form method="POST" action="categories.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="flex: 1;" onsubmit="return validateStockForm(this)">
                                                <input type="hidden" name="action" value="update_stock">
                                                <input type="hidden" name="category_id" value="<?php echo $mCategory['id']; ?>">
                                                <input type="hidden" name="operation" value="subtract">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="stock_change" class="form-control" placeholder="Qty" step="0.001" min="0.001" required>
                                                    <button type="submit" class="btn btn-danger" type="button">
                                                        <i class="bi bi-dash"></i> Remove
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                        
                                        <div class="d-flex gap-2 w-100">
                                            <button class="btn btn-sm btn-outline-primary flex-fill" data-bs-toggle="modal" data-bs-target="#editCategoryModal<?php echo $mCategory['id']; ?>">
                                                <i class="bi bi-pencil me-1"></i>Edit
                                            </button>
                                            
                                            <form method="POST" action="categories.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="flex: 1;" 
                                                  onsubmit="return confirm('Delete this category permanently?')">
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="category_id" value="<?php echo $mCategory['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                    <i class="bi bi-trash me-1"></i>Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-tags d-block mb-2" style="font-size: 36px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No categories found</div>
                            <div style="font-size: 13px;">
                                <?php if ($filterStock): ?>
                                    Try changing your filters or <a href="categories.php">view all categories</a>
                                <?php else: ?>
                                    <?php if ($is_admin): ?>
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#addCategoryModal">Add your first category</a> or 
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#bulkImportModal">bulk import</a> to get started
                                    <?php else: ?>
                                        No categories available
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

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="categories.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" data-testid="form-add-category">
                <input type="hidden" name="action" value="add_category">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="category_name" class="form-control" required placeholder="Enter category name" data-testid="input-add-name">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Purchase Price (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="purchase_price" class="form-control" step="0.01" min="0" value="0.00" data-testid="input-add-price">
                            </div>
                            <small class="text-muted">Cost per (piece)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gram Value </label>
                            <div class="input-group">
                                <span class="input-group-text"></span>
                                <input type="number" name="gram_value" class="form-control" step="0.001" min="0" value="0.000" data-testid="input-add-gram">
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Initial Stock (piece)</label>
                            <div class="input-group">
                                <span class="input-group-text">piece</span>
                                <input type="number" name="total_quantity" class="form-control" step="0.001" min="0" value="0.000" data-testid="input-add-quantity">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Min Stock Level (piece)</label>
                            <div class="input-group">
                                <span class="input-group-text">piece</span>
                                <input type="number" name="min_stock_level" class="form-control" step="0.001" min="0" value="0.000" data-testid="input-add-min">
                            </div>
                            <small class="text-muted">Alert when stock below this</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-testid="button-submit-add-category">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Import Modal -->
<div class="modal fade" id="bulkImportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="categories.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" enctype="multipart/form-data" data-testid="form-bulk-import">
                <input type="hidden" name="action" value="bulk_import">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Import Categories</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Instructions:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Upload a CSV file with the following columns: <code>category_name, purchase_price, gram_value, min_stock_level, total_quantity</code></li>
                            <li>The first row should be the header row (column names)</li>
                            <li>If a category name already exists, it will be updated with the new values</li>
                            <li>New categories will be added automatically</li>
                            <li>Decimal values should use dot (.) as decimal separator</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Download Sample Template</label>
                        <div>
                            <a href="?action=download_sample" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-download"></i> Download sample CSV
                            </a>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select CSV File <span class="text-danger">*</span></label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required data-testid="input-csv-file">
                        <small class="text-muted">Only CSV files are allowed</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" data-testid="button-submit-bulk-import">
                        <i class="bi bi-upload"></i> Import Categories
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    $('#categoriesTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search categories:",
            lengthMenu: "Show _MENU_ categories",
            info: "Showing _START_ to _END_ of _TOTAL_ categories",
            emptyTable: "No categories available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: [-1, -2] }
            <?php else: ?>
            { orderable: false, targets: [] }
            <?php endif; ?>
        ]
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert-dismissible').alert('close');
    }, 5000);
});

// Validate stock form
function validateStockForm(form) {
    const input = form.querySelector('input[name="stock_change"]');
    const value = parseFloat(input.value);
    
    if (isNaN(value) || value <= 0) {
        alert('Please enter a valid positive quantity.');
        return false;
    }
    
    // Check if subtracting and show warning if removing large quantity
    const operation = form.querySelector('input[name="operation"]')?.value;
    if (operation === 'subtract') {
        const row = form.closest('tr');
        if (row) {
            const stockCell = row.querySelector('td:nth-child(5) .fw-semibold');
            if (stockCell) {
                const currentStock = parseFloat(stockCell.textContent);
                if (value > currentStock) {
                    alert('Cannot remove more than current stock quantity.');
                    return false;
                }
            }
        }
    }
    
    return true;
}

// Validate file upload
document.getElementById('bulkImportModal')?.addEventListener('submit', function(e) {
    const fileInput = document.querySelector('input[name="csv_file"]');
    if (fileInput && fileInput.files.length > 0) {
        const fileName = fileInput.files[0].name;
        const fileExt = fileName.split('.').pop().toLowerCase();
        if (fileExt !== 'csv') {
            e.preventDefault();
            alert('Please upload only CSV files.');
            return false;
        }
    }
});
</script>
</body>
</html>