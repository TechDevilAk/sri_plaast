<?php
session_start();
$currentPage = 'products';
$pageTitle = 'Products Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view products, but only admin can modify
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Get all GST rates for HSN dropdown
$gst_rates = $conn->query("SELECT hsn, cgst, sgst, igst FROM gst WHERE status = 1 ORDER BY hsn ASC");

// Handle add product (POST only) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to add products.';
    } else {
        $product_name = trim($_POST['product_name'] ?? '');
        $product_type = isset($_POST['product_type']) ? trim($_POST['product_type']) : 'direct';
        
        // Handle HSN code and GST
        if (isset($_POST['hsn_code_type'])) {
            if ($_POST['hsn_code_type'] === 'existing') {
                $hsn_code = trim($_POST['existing_hsn'] ?? '');
            } else {
                $hsn_code = trim($_POST['custom_hsn'] ?? '');
                $cgst = floatval($_POST['cgst'] ?? 0);
                $sgst = floatval($_POST['sgst'] ?? 0);
                $igst = $cgst + $sgst;
                
                // Insert new GST rate if it doesn't exist
                if (!empty($hsn_code) && ($cgst > 0 || $sgst > 0)) {
                    $check_gst = $conn->prepare("SELECT id FROM gst WHERE hsn = ?");
                    $check_gst->bind_param("s", $hsn_code);
                    $check_gst->execute();
                    $check_gst->store_result();
                    
                    if ($check_gst->num_rows == 0) {
                        $insert_gst = $conn->prepare("INSERT INTO gst (hsn, cgst, sgst, igst, status) VALUES (?, ?, ?, ?, 1)");
                        $insert_gst->bind_param("sddd", $hsn_code, $cgst, $sgst, $igst);
                        $insert_gst->execute();
                        $insert_gst->close();
                        
                        // Log GST creation
                        $log_desc = "Added new GST rate: HSN {$hsn_code} (CGST: {$cgst}%, SGST: {$sgst}%, IGST: {$igst}%)";
                        $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                        $log_stmt->execute();
                    }
                    $check_gst->close();
                }
            }
        } else {
            $hsn_code = trim($_POST['hsn_code'] ?? '');
        }
        
        // Handle primary unit (either from select or custom)
        if (isset($_POST['primary_unit_temp']) && $_POST['primary_unit_temp'] === 'custom') {
            $primary_unit = trim($_POST['primary_unit'] ?? '');
        } else {
            $primary_unit = trim($_POST['primary_unit'] ?? '');
        }
        
        // Handle secondary unit (either from select or custom)
        if (isset($_POST['sec_unit_temp']) && $_POST['sec_unit_temp'] === 'custom') {
            $sec_unit = trim($_POST['sec_unit'] ?? '');
        } else {
            $sec_unit = trim($_POST['sec_unit'] ?? '');
        }
        
        $primary_qty = floatval($_POST['primary_qty'] ?? 0);
        $sec_qty = floatval($_POST['sec_qty'] ?? 0);

        if (empty($product_name)) {
            $error = 'Product name is required.';
        } else {
            // Check if product exists
            $check = $conn->prepare("SELECT id FROM product WHERE product_name = ?");
            $check->bind_param("s", $product_name);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $error = 'Product already exists. Please choose a different name.';
            } else {
                $stmt = $conn->prepare("INSERT INTO product (product_name, product_type, hsn_code, primary_qty, primary_unit, sec_qty, sec_unit) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssdsds", $product_name, $product_type, $hsn_code, $primary_qty, $primary_unit, $sec_qty, $sec_unit);
                
                if ($stmt->execute()) {
                    $product_id = $stmt->insert_id;
                    
                    // Log activity
                    $type_label = ($product_type == 'direct') ? 'Direct Sale' : 'Converted Sale';
                    $log_desc = "Created new product: " . $product_name . " (Type: {$type_label}, HSN: " . ($hsn_code ?: 'N/A') . ")";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $success = "Product added successfully.";
                } else {
                    $error = "Failed to add product.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle edit product (POST only) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_product' && isset($_POST['product_id']) && is_numeric($_POST['product_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to edit products.';
    } else {
        $editId = intval($_POST['product_id']);
        $product_name = trim($_POST['product_name'] ?? '');
        $product_type = isset($_POST['product_type']) ? trim($_POST['product_type']) : 'direct';
        
        // Handle HSN code and GST
        if (isset($_POST['hsn_code_type'])) {
            if ($_POST['hsn_code_type'] === 'existing') {
                $hsn_code = trim($_POST['existing_hsn'] ?? '');
            } else {
                $hsn_code = trim($_POST['custom_hsn'] ?? '');
                $cgst = floatval($_POST['cgst'] ?? 0);
                $sgst = floatval($_POST['sgst'] ?? 0);
                $igst = $cgst + $sgst;
                
                // Insert new GST rate if it doesn't exist
                if (!empty($hsn_code) && ($cgst > 0 || $sgst > 0)) {
                    $check_gst = $conn->prepare("SELECT id FROM gst WHERE hsn = ?");
                    $check_gst->bind_param("s", $hsn_code);
                    $check_gst->execute();
                    $check_gst->store_result();
                    
                    if ($check_gst->num_rows == 0) {
                        $insert_gst = $conn->prepare("INSERT INTO gst (hsn, cgst, sgst, igst, status) VALUES (?, ?, ?, ?, 1)");
                        $insert_gst->bind_param("sddd", $hsn_code, $cgst, $sgst, $igst);
                        $insert_gst->execute();
                        $insert_gst->close();
                        
                        // Log GST creation
                        $log_desc = "Added new GST rate: HSN {$hsn_code} (CGST: {$cgst}%, SGST: {$sgst}%, IGST: {$igst}%)";
                        $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                        $log_stmt->execute();
                    }
                    $check_gst->close();
                }
            }
        } else {
            $hsn_code = trim($_POST['hsn_code'] ?? '');
        }
        
        // Handle primary unit (either from select or custom)
        if (isset($_POST['primary_unit_temp']) && $_POST['primary_unit_temp'] === 'custom') {
            $primary_unit = trim($_POST['primary_unit'] ?? '');
        } else {
            $primary_unit = trim($_POST['primary_unit'] ?? '');
        }
        
        // Handle secondary unit (either from select or custom)
        if (isset($_POST['sec_unit_temp']) && $_POST['sec_unit_temp'] === 'custom') {
            $sec_unit = trim($_POST['sec_unit'] ?? '');
        } else {
            $sec_unit = trim($_POST['sec_unit'] ?? '');
        }
        
        $primary_qty = floatval($_POST['primary_qty'] ?? 0);
        $sec_qty = floatval($_POST['sec_qty'] ?? 0);

        if (empty($product_name)) {
            $error = 'Product name is required.';
        } else {
            // Check if product name exists for other products
            $check = $conn->prepare("SELECT id FROM product WHERE product_name = ? AND id != ?");
            $check->bind_param("si", $product_name, $editId);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $error = 'Product name already exists. Please choose a different name.';
            } else {
                $stmt = $conn->prepare("UPDATE product SET product_name=?, product_type=?, hsn_code=?, primary_qty=?, primary_unit=?, sec_qty=?, sec_unit=? WHERE id=?");
                $stmt->bind_param("sssdsdsi", $product_name, $product_type, $hsn_code, $primary_qty, $primary_unit, $sec_qty, $sec_unit, $editId);
                
                if ($stmt->execute()) {
                    // Log activity
                    $type_label = ($product_type == 'direct') ? 'Direct Sale' : 'Converted Sale';
                    $log_desc = "Updated product: " . $product_name . " (Type: {$type_label}, HSN: " . ($hsn_code ?: 'N/A') . ")";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $success = "Product updated successfully.";
                } else {
                    $error = "Failed to update product.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle delete product (POST only) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product' && isset($_POST['product_id']) && is_numeric($_POST['product_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete products.';
    } else {
        $deleteId = intval($_POST['product_id']);
        
        // Check if product is used in invoice items
        $check_invoice = $conn->prepare("SELECT id FROM invoice_item WHERE product_id = ? LIMIT 1");
        $check_invoice->bind_param("i", $deleteId);
        $check_invoice->execute();
        $check_invoice->store_result();
        
        if ($check_invoice->num_rows > 0) {
            $error = "Cannot delete product. It has been used in invoices.";
        } else {
            // Get product name for logging
            $prod_query = $conn->prepare("SELECT product_name, product_type, hsn_code FROM product WHERE id = ?");
            $prod_query->bind_param("i", $deleteId);
            $prod_query->execute();
            $prod_result = $prod_query->get_result();
            $prod_data = $prod_result->fetch_assoc();
            $product_name = $prod_data['product_name'] ?? 'Unknown';
            $product_type = $prod_data['product_type'] ?? 'direct';
            $hsn_code = $prod_data['hsn_code'] ?? '';
            $type_label = ($product_type == 'direct') ? 'Direct Sale' : 'Converted Sale';
            
            $stmt = $conn->prepare("DELETE FROM product WHERE id = ?");
            $stmt->bind_param("i", $deleteId);
            
            if ($stmt->execute()) {
                // Log activity
                $log_desc = "Deleted product: " . $product_name . " (Type: {$type_label}, HSN: " . ($hsn_code ?: 'N/A') . ")";
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $success = "Product deleted successfully.";
            } else {
                $error = "Failed to delete product.";
            }
            $stmt->close();
        }
        $check_invoice->close();
    }
}

// Get all products with HSN info
$sql = "SELECT p.*, 
        g.cgst, g.sgst, g.igst 
        FROM product p 
        LEFT JOIN gst g ON p.hsn_code = g.hsn AND g.status = 1
        ORDER BY p.product_name ASC";
$products = $conn->query($sql);

// Stats
$totalCount = $conn->query("SELECT COUNT(*) as cnt FROM product")->fetch_assoc()['cnt'];
$directCount = $conn->query("SELECT COUNT(*) as cnt FROM product WHERE product_type = 'direct'")->fetch_assoc()['cnt'];
$convertedCount = $conn->query("SELECT COUNT(*) as cnt FROM product WHERE product_type = 'converted'")->fetch_assoc()['cnt'];
$withSecondaryCount = $conn->query("SELECT COUNT(*) as cnt FROM product WHERE sec_qty > 0 AND sec_unit != ''")->fetch_assoc()['cnt'];
$withoutSecondaryCount = $totalCount - $withSecondaryCount;
$withHSNCount = $conn->query("SELECT COUNT(*) as cnt FROM product WHERE hsn_code IS NOT NULL AND hsn_code != ''")->fetch_assoc()['cnt'];
$withoutHSNCount = $totalCount - $withHSNCount;

// Get total products used in invoices
$usedInInvoices = $conn->query("SELECT COUNT(DISTINCT product_id) as cnt FROM invoice_item WHERE product_id IS NOT NULL")->fetch_assoc()['cnt'];

// Most common primary unit
$unitStats = $conn->query("SELECT primary_unit, COUNT(*) as cnt FROM product WHERE primary_unit != '' GROUP BY primary_unit ORDER BY cnt DESC LIMIT 1");
$commonUnit = $unitStats->fetch_assoc();

// Most common HSN
$hsnStats = $conn->query("SELECT hsn_code, COUNT(*) as cnt FROM product WHERE hsn_code IS NOT NULL AND hsn_code != '' GROUP BY hsn_code ORDER BY cnt DESC LIMIT 1");
$commonHSN = $hsnStats->fetch_assoc();

// Check if user is admin for action buttons
$is_admin = ($_SESSION['user_role'] === 'admin');

// List of standard units for dropdown
$piece_units = ['pcs', 'box', 'bag', 'bottle', 'can', 'pack', 'pair'];

// Function to check if unit is standard
function isStandardUnit($unit, $standard_units) {
    return in_array($unit, $standard_units);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .product-unit-badge {
            background: #e8f2ff;
            color: #2463eb;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .product-unit-badge.secondary {
            background: #f0fdf4;
            color: #16a34a;
        }
        
        .product-type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .product-type-badge.direct {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .product-type-badge.converted {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .hsn-badge {
            background: #f2e8ff;
            color: #8b5cf6;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            font-family: monospace;
        }
        
        .gst-tag {
            background: #fee2e2;
            color: #dc2626;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 500;
            margin-left: 5px;
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
        
        .product-combo {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .combo-arrow {
            color: #94a3b8;
            font-size: 14px;
        }
        
        .example-text {
            background: #f8fafc;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            color: #64748b;
        }
        
        .hsn-select {
            font-family: monospace;
        }
        
        .unit-group-label {
            font-size: 11px;
            color: #64748b;
            margin-top: 8px;
            margin-bottom: 4px;
            font-weight: 600;
        }
        
        .custom-unit-input {
            margin-top: 8px;
            border-top: 1px dashed #e2e8f0;
            padding-top: 8px;
        }
        
        .gst-input-group {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            border: 1px solid #e2e8f0;
        }
        
        .gst-total {
            background: #e8f2ff;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            color: #2463eb;
        }
        
        .hsn-type-selector {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .hsn-type-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bulk-import-btn {
            background: #10b981;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .bulk-import-btn:hover {
            background: #059669;
            color: white;
        }

        .import-progress {
            display: none;
            margin-top: 15px;
        }
        
        .progress-bar-import {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: #10b981;
            width: 0%;
            transition: width 0.3s;
        }
        
        .import-results {
            margin-top: 15px;
            padding: 15px;
            border-radius: 8px;
            display: none;
        }
        
        .import-results.success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .import-results.error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .error-list {
            max-height: 200px;
            overflow-y: auto;
            font-size: 12px;
            margin-top: 10px;
            padding: 10px;
            background: white;
            border-radius: 6px;
        }
        
        .product-type-selector {
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .product-type-option {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        
        .product-type-option:last-child {
            margin-bottom: 0;
        }
        
        .product-type-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .product-type-option label {
            cursor: pointer;
            margin: 0;
            font-weight: 500;
        }
        
        .product-type-desc {
            font-size: 11px;
            color: #64748b;
            margin-left: 28px;
            margin-top: -4px;
            margin-bottom: 8px;
        }
        
        .product-type-badge-filter {
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .product-type-badge-filter:hover {
            opacity: 0.8;
            transform: scale(1.02);
        }
        
        .filter-active {
            border: 2px solid #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Products Management</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage products, units, HSN codes, and product types</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($is_admin): ?>
                        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#bulkImportModal" data-testid="button-bulk-import">
                            <i class="bi bi-upload"></i> Bulk Import
                        </button>
                        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addProductModal" data-testid="button-add-product">
                            <i class="bi bi-plus-circle"></i> Add New Product
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

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-2">
                    <div class="stat-card" data-testid="stat-total">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon blue">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Total Products</div>
                                <div class="stat-value" data-testid="stat-value-total"><?php echo $totalCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="stat-card stat-direct-clickable" data-filter-type="direct" style="cursor: pointer;" data-testid="stat-direct">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon green">
                                <i class="bi bi-cart"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Direct Sale</div>
                                <div class="stat-value" data-testid="stat-value-direct"><?php echo $directCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="stat-card stat-converted-clickable" data-filter-type="converted" style="cursor: pointer;" data-testid="stat-converted">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon orange">
                                <i class="bi bi-arrow-repeat"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Converted Sale</div>
                                <div class="stat-value" data-testid="stat-value-converted"><?php echo $convertedCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="stat-card" data-testid="stat-with-secondary">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon purple">
                                <i class="bi bi-layers"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Multi-Unit Products</div>
                                <div class="stat-value" data-testid="stat-value-secondary"><?php echo $withSecondaryCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="stat-card" data-testid="stat-with-hsn">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon teal">
                                <i class="bi bi-upc-scan"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">With HSN Code</div>
                                <div class="stat-value" data-testid="stat-value-hsn"><?php echo $withHSNCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="stat-card" data-testid="stat-invoices">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon red">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Used in Invoices</div>
                                <div class="stat-value" data-testid="stat-value-invoices"><?php echo $usedInInvoices; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Type Filter Buttons -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="dashboard-card p-3">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <span class="fw-semibold" style="color: var(--text-primary);">Filter by Product Type:</span>
                            <button class="btn btn-sm btn-outline-primary filter-btn active" data-filter="all">
                                <i class="bi bi-grid-3x3-gap-fill me-1"></i> All Products
                            </button>
                            <button class="btn btn-sm btn-outline-primary filter-btn" data-filter="direct">
                                <i class="bi bi-cart me-1"></i> Direct Sale
                            </button>
                            <button class="btn btn-sm btn-outline-primary filter-btn" data-filter="converted">
                                <i class="bi bi-arrow-repeat me-1"></i> Converted Sale
                            </button>
                            <div class="ms-auto">
                                <span class="text-muted" style="font-size: 12px;">
                                    <i class="bi bi-info-circle"></i> Click on stats cards above to filter
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Products Table -->
            <div class="dashboard-card" data-testid="products-table">
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="productsTable">
                        <thead>
                             <tr>
                                <th>#</th>
                                <th>Product Name</th>
                                <th>Type</th>
                                <th>HSN Code</th>
                                <th>GST</th>
                                <th>Primary Unit</th>
                                <th>Secondary Unit</th>
                                <th>Example</th>
                                <th>Created</th>
                                <th>Last Updated</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                              </tr>
                        </thead>
                        <tbody>
                            <?php if ($products && $products->num_rows > 0): ?>
                                <?php while ($product = $products->fetch_assoc()): 
                                    $example = '';
                                    if ($product['primary_qty'] > 0 && !empty($product['primary_unit'])) {
                                        $example .= number_format($product['primary_qty'], 0) . ' ' . $product['primary_unit'];
                                    }
                                    if ($product['sec_qty'] > 0 && !empty($product['sec_unit'])) {
                                        $example .= ' = ' . number_format($product['sec_qty'], 0) . ' ' . $product['sec_unit'];
                                    }
                                    $total_gst = ($product['cgst'] ?? 0) + ($product['sgst'] ?? 0);
                                    $type_class = ($product['product_type'] == 'direct') ? 'direct' : 'converted';
                                    $type_label = ($product['product_type'] == 'direct') ? 'Direct Sale' : 'Converted Sale';
                                    $type_icon = ($product['product_type'] == 'direct') ? 'bi-cart' : 'bi-arrow-repeat';
                                ?>
                                    <tr data-product-type="<?php echo $product['product_type']; ?>" data-testid="row-product-<?php echo $product['id']; ?>">
                                        <td><span class="order-id">#<?php echo $product['id']; ?></span></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($product['product_name']); ?></td>
                                        <td>
                                            <span class="product-type-badge <?php echo $type_class; ?>">
                                                <i class="bi <?php echo $type_icon; ?> me-1"></i>
                                                <?php echo $type_label; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($product['hsn_code'])): ?>
                                                <span class="hsn-badge">
                                                    <i class="bi bi-upc-scan me-1"></i>
                                                    <?php echo htmlspecialchars($product['hsn_code']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($total_gst > 0): ?>
                                                <span class="gst-tag">
                                                    <?php echo number_format($total_gst, 1); ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($product['primary_unit'])): ?>
                                                <span class="product-unit-badge">
                                                    <i class="bi bi-box me-1"></i>
                                                    <?php echo htmlspecialchars($product['primary_unit']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($product['sec_unit'])): ?>
                                                <span class="product-unit-badge secondary">
                                                    <i class="bi bi-layers me-1"></i>
                                                    <?php echo htmlspecialchars($product['sec_unit']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($example)): ?>
                                                <span class="example-text">
                                                    <i class="bi bi-info-circle me-1"></i>
                                                    <?php echo $example; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color: var(--text-muted); white-space: nowrap;"><?php echo date('M d, Y', strtotime($product['created_at'])); ?></td>
                                        <td style="color: var(--text-muted); white-space: nowrap;"><?php echo date('M d, Y', strtotime($product['updated_at'])); ?></td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <!-- Edit -->
                                                    <button class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 3px 8px;" 
                                                            data-bs-toggle="modal" data-bs-target="#editProductModal<?php echo $product['id']; ?>" 
                                                            data-testid="button-edit-<?php echo $product['id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    
                                                    <!-- Delete -->
                                                    <form method="POST" action="products.php" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this product? This action cannot be undone.')">
                                                        <input type="hidden" name="action" value="delete_product">
                                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" 
                                                                data-testid="button-delete-<?php echo $product['id']; ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                             </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Edit Product Modal -->
                                    <div class="modal fade" id="editProductModal<?php echo $product['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <form method="POST" action="products.php" data-testid="form-edit-product-<?php echo $product['id']; ?>">
                                                    <input type="hidden" name="action" value="edit_product">
                                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit Product</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Product Name <span class="text-danger">*</span></label>
                                                            <input type="text" name="product_name" class="form-control" value="<?php echo htmlspecialchars($product['product_name']); ?>" required data-testid="input-edit-name-<?php echo $product['id']; ?>">
                                                        </div>
                                                        
                                                        <!-- Product Type Selection -->
                                                        <div class="product-type-selector">
                                                            <label class="form-label fw-semibold mb-2">Product Type <span class="text-danger">*</span></label>
                                                            <div class="product-type-option">
                                                                <input type="radio" name="product_type" id="edit_direct_<?php echo $product['id']; ?>" value="direct" <?php echo ($product['product_type'] == 'direct') ? 'checked' : ''; ?>>
                                                                <label for="edit_direct_<?php echo $product['id']; ?>">Direct Sale Product</label>
                                                            </div>
                                                            <div class="product-type-desc">
                                                                <i class="bi bi-info-circle"></i> Products sold directly to customers
                                                            </div>
                                                            <div class="product-type-option">
                                                                <input type="radio" name="product_type" id="edit_converted_<?php echo $product['id']; ?>" value="converted" <?php echo ($product['product_type'] == 'converted') ? 'checked' : ''; ?>>
                                                                <label for="edit_converted_<?php echo $product['id']; ?>">Converted Sale Product</label>
                                                            </div>
                                                            <div class="product-type-desc">
                                                                <i class="bi bi-info-circle"></i> Products that are converted from raw materials or other products
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="hsn-type-selector">
                                                            <div class="hsn-type-option">
                                                                <input type="radio" name="hsn_code_type" id="hsn_existing_<?php echo $product['id']; ?>" value="existing" class="hsn-type-radio" data-target="<?php echo $product['id']; ?>" <?php echo (in_array($product['hsn_code'], array_column($gst_rates->fetch_all(MYSQLI_ASSOC), 'hsn')) || empty($product['hsn_code'])) ? 'checked' : ''; ?>>
                                                                <label for="hsn_existing_<?php echo $product['id']; ?>">Select Existing HSN</label>
                                                            </div>
                                                            <div class="hsn-type-option">
                                                                <input type="radio" name="hsn_code_type" id="hsn_custom_<?php echo $product['id']; ?>" value="custom" class="hsn-type-radio" data-target="<?php echo $product['id']; ?>" <?php echo (!in_array($product['hsn_code'], array_column($gst_rates->fetch_all(MYSQLI_ASSOC), 'hsn')) && !empty($product['hsn_code'])) ? 'checked' : ''; ?>>
                                                                <label for="hsn_custom_<?php echo $product['id']; ?>">Enter Custom HSN with GST</label>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3 existing-hsn-section" id="existing_hsn_section_<?php echo $product['id']; ?>" style="display: <?php echo (in_array($product['hsn_code'], array_column($gst_rates->fetch_all(MYSQLI_ASSOC), 'hsn')) || empty($product['hsn_code'])) ? 'block' : 'none'; ?>;">
                                                            <label class="form-label">HSN Code</label>
                                                            <select name="existing_hsn" class="form-select hsn-select">
                                                                <option value="">-- Select HSN Code --</option>
                                                                <?php 
                                                                if ($gst_rates && $gst_rates->num_rows > 0) {
                                                                    $gst_rates->data_seek(0);
                                                                    while ($gst = $gst_rates->fetch_assoc()): 
                                                                        $selected = ($product['hsn_code'] == $gst['hsn']) ? 'selected' : '';
                                                                ?>
                                                                    <option value="<?php echo $gst['hsn']; ?>" <?php echo $selected; ?>>
                                                                        <?php echo $gst['hsn']; ?> (CGST: <?php echo $gst['cgst']; ?>% + SGST: <?php echo $gst['sgst']; ?>%)
                                                                    </option>
                                                                <?php 
                                                                    endwhile; 
                                                                } 
                                                                ?>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="custom-hsn-section" id="custom_hsn_section_<?php echo $product['id']; ?>" style="display: <?php echo (!in_array($product['hsn_code'], array_column($gst_rates->fetch_all(MYSQLI_ASSOC), 'hsn')) && !empty($product['hsn_code'])) ? 'block' : 'none'; ?>;">
                                                            <div class="mb-3">
                                                                <label class="form-label">Custom HSN Code</label>
                                                                <input type="text" name="custom_hsn" class="form-control" placeholder="Enter HSN code" value="<?php echo (!in_array($product['hsn_code'], array_column($gst_rates->fetch_all(MYSQLI_ASSOC), 'hsn')) && !empty($product['hsn_code'])) ? htmlspecialchars($product['hsn_code']) : ''; ?>">
                                                            </div>
                                                            
                                                            <div class="gst-input-group">
                                                                <div class="row g-3">
                                                                    <div class="col-md-6">
                                                                        <label class="form-label">CGST %</label>
                                                                        <input type="number" name="cgst" class="form-control gst-input" step="0.01" min="0" max="100" value="<?php echo $product['cgst'] ?? 0; ?>" data-target="<?php echo $product['id']; ?>" id="cgst_<?php echo $product['id']; ?>">
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label">SGST %</label>
                                                                        <input type="number" name="sgst" class="form-control gst-input" step="0.01" min="0" max="100" value="<?php echo $product['sgst'] ?? 0; ?>" data-target="<?php echo $product['id']; ?>" id="sgst_<?php echo $product['id']; ?>">
                                                                    </div>
                                                                </div>
                                                                <div class="mt-3 gst-total" id="gst_total_<?php echo $product['id']; ?>">
                                                                    Total GST: <?php echo number_format(($product['cgst'] ?? 0) + ($product['sgst'] ?? 0), 2); ?>%
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="row g-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label">Primary Quantity</label>
                                                                <input type="number" name="primary_qty" class="form-control" step="0.001" min="0" value="<?php echo htmlspecialchars($product['primary_qty']); ?>" data-testid="input-edit-primary-qty-<?php echo $product['id']; ?>">
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Primary Unit</label>
                                                                <select name="primary_unit" class="form-select" id="editPrimaryUnit<?php echo $product['id']; ?>" data-testid="select-edit-primary-unit-<?php echo $product['id']; ?>">
                                                                    <option value="">-- Select Unit --</option>
                                                                    <optgroup label="Weight">
                                                                        <option value="kg" <?php echo ($product['primary_unit'] == 'kg') ? 'selected' : ''; ?>>Kilogram (kg)</option>
                                                                        <option value="g" <?php echo ($product['primary_unit'] == 'g') ? 'selected' : ''; ?>>Gram (g)</option>
                                                                        <option value="mg" <?php echo ($product['primary_unit'] == 'mg') ? 'selected' : ''; ?>>Milligram (mg)</option>
                                                                        <option value="ton" <?php echo ($product['primary_unit'] == 'ton') ? 'selected' : ''; ?>>Ton (ton)</option>
                                                                        <option value="lb" <?php echo ($product['primary_unit'] == 'lb') ? 'selected' : ''; ?>>Pound (lb)</option>
                                                                    </optgroup>
                                                                    <optgroup label="Volume">
                                                                        <option value="l" <?php echo ($product['primary_unit'] == 'l') ? 'selected' : ''; ?>>Liter (l)</option>
                                                                        <option value="ml" <?php echo ($product['primary_unit'] == 'ml') ? 'selected' : ''; ?>>Milliliter (ml)</option>
                                                                        <option value="gal" <?php echo ($product['primary_unit'] == 'gal') ? 'selected' : ''; ?>>Gallon (gal)</option>
                                                                        <option value="oz" <?php echo ($product['primary_unit'] == 'oz') ? 'selected' : ''; ?>>Fluid Ounce (oz)</option>
                                                                    </optgroup>
                                                                    <optgroup label="Pieces">
                                                                        <option value="pcs" <?php echo ($product['primary_unit'] == 'pcs') ? 'selected' : ''; ?>>Pieces (pcs)</option>
                                                                        <option value="doz" <?php echo ($product['primary_unit'] == 'doz') ? 'selected' : ''; ?>>Dozen (doz)</option>
                                                                        <option value="box" <?php echo ($product['primary_unit'] == 'box') ? 'selected' : ''; ?>>Box (box)</option>
                                                                        <option value="bag" <?php echo ($product['primary_unit'] == 'bag') ? 'selected' : ''; ?>>Bag (bag)</option>
                                                                        <option value="bottle" <?php echo ($product['primary_unit'] == 'bottle') ? 'selected' : ''; ?>>Bottle</option>
                                                                        <option value="can" <?php echo ($product['primary_unit'] == 'can') ? 'selected' : ''; ?>>Can</option>
                                                                        <option value="pack" <?php echo ($product['primary_unit'] == 'pack') ? 'selected' : ''; ?>>Pack</option>
                                                                        <option value="set" <?php echo ($product['primary_unit'] == 'set') ? 'selected' : ''; ?>>Set</option>
                                                                        <option value="pair" <?php echo ($product['primary_unit'] == 'pair') ? 'selected' : ''; ?>>Pair</option>
                                                                    </optgroup>
                                                                    <optgroup label="Length">
                                                                        <option value="m" <?php echo ($product['primary_unit'] == 'm') ? 'selected' : ''; ?>>Meter (m)</option>
                                                                        <option value="cm" <?php echo ($product['primary_unit'] == 'cm') ? 'selected' : ''; ?>>Centimeter (cm)</option>
                                                                        <option value="mm" <?php echo ($product['primary_unit'] == 'mm') ? 'selected' : ''; ?>>Millimeter (mm)</option>
                                                                        <option value="ft" <?php echo ($product['primary_unit'] == 'ft') ? 'selected' : ''; ?>>Feet (ft)</option>
                                                                        <option value="yd" <?php echo ($product['primary_unit'] == 'yd') ? 'selected' : ''; ?>>Yard (yd)</option>
                                                                    </optgroup>
                                                                    <optgroup label="Area">
                                                                        <option value="sqm" <?php echo ($product['primary_unit'] == 'sqm') ? 'selected' : ''; ?>>Square Meter (sqm)</option>
                                                                        <option value="sqft" <?php echo ($product['primary_unit'] == 'sqft') ? 'selected' : ''; ?>>Square Feet (sqft)</option>
                                                                        <option value="acre" <?php echo ($product['primary_unit'] == 'acre') ? 'selected' : ''; ?>>Acre</option>
                                                                    </optgroup>
                                                                    <option value="custom">-- Custom Unit --</option>
                                                                </select>
                                                                <input type="text" name="custom_primary_unit" class="form-control mt-2" id="editCustomPrimaryUnit<?php echo $product['id']; ?>" style="display: <?php echo (!in_array($product['primary_unit'], ['kg','g','mg','ton','lb','l','ml','gal','oz','pcs','doz','box','bag','bottle','can','pack','set','pair','m','cm','mm','ft','yd','sqm','sqft','acre']) && !empty($product['primary_unit'])) ? 'block' : 'none'; ?>;" placeholder="Enter custom primary unit" value="<?php echo (!in_array($product['primary_unit'], ['kg','g','mg','ton','lb','l','ml','gal','oz','pcs','doz','box','bag','bottle','can','pack','set','pair','m','cm','mm','ft','yd','sqm','sqft','acre']) && !empty($product['primary_unit'])) ? htmlspecialchars($product['primary_unit']) : ''; ?>">
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="row g-3 mt-2">
                                                            <div class="col-md-6">
                                                                <label class="form-label">Secondary Quantity</label>
                                                                <input type="number" name="sec_qty" class="form-control" step="0.001" min="0" value="<?php echo htmlspecialchars($product['sec_qty']); ?>" placeholder="Optional" data-testid="input-edit-sec-qty-<?php echo $product['id']; ?>">
                                                                <small class="text-muted">Leave as 0 if not applicable</small>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Secondary Unit</label>
                                                                <select name="sec_unit" class="form-select" id="editSecUnit<?php echo $product['id']; ?>" data-testid="select-edit-sec-unit-<?php echo $product['id']; ?>">
                                                                    <option value="">-- Select Unit --</option>
                                                                    <optgroup label="Pieces">
                                                                        <option value="pcs" <?php echo ($product['sec_unit'] == 'pcs') ? 'selected' : ''; ?>>Pieces (pcs)</option>
                                                                        <option value="box" <?php echo ($product['sec_unit'] == 'box') ? 'selected' : ''; ?>>Box (box)</option>
                                                                        <option value="bag" <?php echo ($product['sec_unit'] == 'bag') ? 'selected' : ''; ?>>Bag (bag)</option>
                                                                        <option value="bottle" <?php echo ($product['sec_unit'] == 'bottle') ? 'selected' : ''; ?>>Bottle</option>
                                                                        <option value="can" <?php echo ($product['sec_unit'] == 'can') ? 'selected' : ''; ?>>Can</option>
                                                                        <option value="pack" <?php echo ($product['sec_unit'] == 'pack') ? 'selected' : ''; ?>>Pack</option>
                                                                        <option value="pair" <?php echo ($product['sec_unit'] == 'pair') ? 'selected' : ''; ?>>Pair</option>
                                                                    </optgroup>
                                                                    <option value="custom">-- Custom Unit --</option>
                                                                </select>
                                                                <input type="text" name="custom_sec_unit" class="form-control mt-2" id="editCustomSecUnit<?php echo $product['id']; ?>" style="display: <?php echo (!in_array($product['sec_unit'], ['kg','g','mg','ton','lb','l','ml','gal','oz','pcs','doz','box','bag','bottle','can','pack','set','pair','m','cm','mm','ft','yd','sqm','sqft','acre']) && !empty($product['sec_unit'])) ? 'block' : 'none'; ?>;" placeholder="Enter custom secondary unit" value="<?php echo (!in_array($product['sec_unit'], ['kg','g','mg','ton','lb','l','ml','gal','oz','pcs','doz','box','bag','bottle','can','pack','set','pair','m','cm','mm','ft','yd','sqm','sqft','acre']) && !empty($product['sec_unit'])) ? htmlspecialchars($product['sec_unit']) : ''; ?>">
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="alert alert-info mt-3 mb-0" style="font-size: 12px;">
                                                            <i class="bi bi-info-circle"></i> 
                                                            <strong>Example:</strong> For "1 bag = 107 pcs", set Primary Qty=1, Primary Unit=bag, Secondary Qty=107, Secondary Unit=pcs
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary" data-testid="button-save-edit-<?php echo $product['id']; ?>">Save Changes</button>
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
                        if ($products && $products->num_rows > 0) {
                            $products->data_seek(0);
                        }
                    ?>
                    <?php if ($products && $products->num_rows > 0): ?>
                        <?php while ($mProduct = $products->fetch_assoc()): 
                            $example = '';
                            if ($mProduct['primary_qty'] > 0 && !empty($mProduct['primary_unit'])) {
                                $example .= number_format($mProduct['primary_qty'], 0) . ' ' . $mProduct['primary_unit'];
                            }
                            if ($mProduct['sec_qty'] > 0 && !empty($mProduct['sec_unit'])) {
                                $example .= ' = ' . number_format($mProduct['sec_qty'], 0) . ' ' . $mProduct['sec_unit'];
                            }
                            $total_gst = ($mProduct['cgst'] ?? 0) + ($mProduct['sgst'] ?? 0);
                            $type_class = ($mProduct['product_type'] == 'direct') ? 'direct' : 'converted';
                            $type_label = ($mProduct['product_type'] == 'direct') ? 'Direct Sale' : 'Converted Sale';
                            $type_icon = ($mProduct['product_type'] == 'direct') ? 'bi-cart' : 'bi-arrow-repeat';
                        ?>
                            <div class="mobile-card" data-product-type="<?php echo $mProduct['product_type']; ?>" data-testid="mobile-card-product-<?php echo $mProduct['id']; ?>">
                                <div class="mobile-card-header">
                                    <div>
                                        <span class="order-id">#<?php echo $mProduct['id']; ?></span>
                                        <span class="customer-name ms-2 fw-semibold"><?php echo htmlspecialchars($mProduct['product_name']); ?></span>
                                    </div>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Product Type</span>
                                    <span class="mobile-card-value">
                                        <span class="product-type-badge <?php echo $type_class; ?>">
                                            <i class="bi <?php echo $type_icon; ?> me-1"></i>
                                            <?php echo $type_label; ?>
                                        </span>
                                    </span>
                                </div>
                                
                                <?php if (!empty($mProduct['hsn_code'])): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">HSN Code</span>
                                    <span class="mobile-card-value">
                                        <span class="hsn-badge"><?php echo htmlspecialchars($mProduct['hsn_code']); ?></span>
                                        <?php if ($total_gst > 0): ?>
                                            <span class="gst-tag ms-1"><?php echo number_format($total_gst, 1); ?>%</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Primary Unit</span>
                                    <span class="mobile-card-value">
                                        <?php if (!empty($mProduct['primary_unit'])): ?>
                                            <span class="product-unit-badge">
                                                <?php echo htmlspecialchars($mProduct['primary_unit']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Secondary Unit</span>
                                    <span class="mobile-card-value">
                                        <?php if (!empty($mProduct['sec_unit'])): ?>
                                            <span class="product-unit-badge secondary">
                                                <?php echo htmlspecialchars($mProduct['sec_unit']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <?php if (!empty($example)): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Example</span>
                                    <span class="mobile-card-value">
                                        <span class="example-text"><?php echo $example; ?></span>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Created</span>
                                    <span class="mobile-card-value"><?php echo date('M d, Y', strtotime($mProduct['created_at'])); ?></span>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                    <div class="mobile-card-actions">
                                        <button class="btn btn-sm btn-outline-primary flex-fill" data-bs-toggle="modal" data-bs-target="#editProductModal<?php echo $mProduct['id']; ?>">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
                                        
                                        <form method="POST" action="products.php" style="flex: 1;" 
                                              onsubmit="return confirm('Delete this product permanently?')">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="product_id" value="<?php echo $mProduct['id']; ?>">
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
                            <i class="bi bi-box-seam d-block mb-2" style="font-size: 36px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No products found</div>
                            <div style="font-size: 13px;">
                                <?php if ($is_admin): ?>
                                    <a href="#" data-bs-toggle="modal" data-bs-target="#addProductModal">Add your first product</a> to get started
                                <?php else: ?>
                                    No products available
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

<!-- Add Product Modal with Custom HSN and GST -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="products.php" data-testid="form-add-product">
                <input type="hidden" name="action" value="add_product">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Product Name <span class="text-danger">*</span></label>
                        <input type="text" name="product_name" class="form-control" required placeholder="Enter product name" data-testid="input-add-name">
                    </div>
                    
                    <!-- Product Type Selection -->
                    <div class="product-type-selector">
                        <label class="form-label fw-semibold mb-2">Product Type <span class="text-danger">*</span></label>
                        <div class="product-type-option">
                            <input type="radio" name="product_type" id="add_direct" value="direct" checked>
                            <label for="add_direct">Direct Sale Product</label>
                        </div>
                        <div class="product-type-desc">
                            <i class="bi bi-info-circle"></i> Products sold directly to customers (e.g., finished goods)
                        </div>
                        <div class="product-type-option">
                            <input type="radio" name="product_type" id="add_converted" value="converted">
                            <label for="add_converted">Converted Sale Product</label>
                        </div>
                        <div class="product-type-desc">
                            <i class="bi bi-info-circle"></i> Products that are converted from raw materials or other products (e.g., processed goods)
                        </div>
                    </div>
                    
                    <div class="hsn-type-selector">
                        <div class="hsn-type-option">
                            <input type="radio" name="hsn_code_type" id="hsn_existing_add" value="existing" class="hsn-type-radio-add" data-target="add" checked>
                            <label for="hsn_existing_add">Select Existing HSN</label>
                        </div>
                        <div class="hsn-type-option">
                            <input type="radio" name="hsn_code_type" id="hsn_custom_add" value="custom" class="hsn-type-radio-add" data-target="add">
                            <label for="hsn_custom_add">Enter Custom HSN with GST</label>
                        </div>
                    </div>
                    
                    <div class="mb-3 existing-hsn-section" id="existing_hsn_section_add" style="display: block;">
                        <label class="form-label">HSN Code</label>
                        <select name="existing_hsn" class="form-select hsn-select">
                            <option value="">-- Select HSN Code --</option>
                            <?php 
                            if ($gst_rates && $gst_rates->num_rows > 0) {
                                $gst_rates->data_seek(0);
                                while ($gst = $gst_rates->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $gst['hsn']; ?>">
                                    <?php echo $gst['hsn']; ?> (CGST: <?php echo $gst['cgst']; ?>% + SGST: <?php echo $gst['sgst']; ?>%)
                                </option>
                            <?php 
                                endwhile; 
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="custom-hsn-section" id="custom_hsn_section_add" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Custom HSN Code</label>
                            <input type="text" name="custom_hsn" class="form-control" placeholder="Enter HSN code">
                        </div>
                        
                        <div class="gst-input-group">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">CGST %</label>
                                    <input type="number" name="cgst" class="form-control gst-input-add" step="0.01" min="0" max="100" value="0" id="cgst_add">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">SGST %</label>
                                    <input type="number" name="sgst" class="form-control gst-input-add" step="0.01" min="0" max="100" value="0" id="sgst_add">
                                </div>
                            </div>
                            <div class="mt-3 gst-total" id="gst_total_add">
                                Total GST: 0.00%
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Primary Quantity</label>
                            <input type="number" name="primary_qty" class="form-control" step="0.001" min="0" value="1.000" data-testid="input-add-primary-qty">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Primary Unit</label>
                            <select name="primary_unit" class="form-select" id="addPrimaryUnit" data-testid="select-add-primary-unit">
                                <option value="">-- Select Unit --</option>
                                <optgroup label="Weight">
                                    <option value="kg">Kilogram (kg)</option>
                                    <option value="g">Gram (g)</option>
                                    <option value="mg">Milligram (mg)</option>
                                    <option value="ton">Ton (ton)</option>
                                    <option value="lb">Pound (lb)</option>
                                </optgroup>
                                <optgroup label="Volume">
                                    <option value="l">Liter (l)</option>
                                    <option value="ml">Milliliter (ml)</option>
                                    <option value="gal">Gallon (gal)</option>
                                    <option value="oz">Fluid Ounce (oz)</option>
                                </optgroup>
                                <optgroup label="Pieces">
                                    <option value="pcs">Pieces (pcs)</option>
                                    <option value="doz">Dozen (doz)</option>
                                    <option value="box">Box (box)</option>
                                    <option value="bag" selected>Bag (bag)</option>
                                    <option value="bottle">Bottle</option>
                                    <option value="can">Can</option>
                                    <option value="pack">Pack</option>
                                    <option value="set">Set</option>
                                    <option value="pair">Pair</option>
                                </optgroup>
                                <optgroup label="Length">
                                    <option value="m">Meter (m)</option>
                                    <option value="cm">Centimeter (cm)</option>
                                    <option value="mm">Millimeter (mm)</option>
                                    <option value="ft">Feet (ft)</option>
                                    <option value="yd">Yard (yd)</option>
                                </optgroup>
                                <optgroup label="Area">
                                    <option value="sqm">Square Meter (sqm)</option>
                                    <option value="sqft">Square Feet (sqft)</option>
                                    <option value="acre">Acre</option>
                                </optgroup>
                                <option value="custom">-- Custom Unit --</option>
                            </select>
                            <input type="text" name="custom_primary_unit" class="form-control mt-2" id="addCustomPrimaryUnit" style="display: none;" placeholder="Enter custom primary unit">
                        </div>
                    </div>
                    
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label">Secondary Quantity</label>
                            <input type="number" name="sec_qty" class="form-control" step="0.001" min="0" value="0.000" placeholder="Optional" data-testid="input-add-sec-qty">
                            <small class="text-muted">Leave as 0 if not applicable</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Secondary Unit</label>
                            <select name="sec_unit" class="form-select" id="addSecUnit" data-testid="select-add-sec-unit">
                                <option value="">-- Select Unit --</option>
                                <optgroup label="Pieces">
                                    <option value="pcs">Pieces (pcs)</option>
                                    <option value="box">Box (box)</option>
                                    <option value="bag">Bag (bag)</option>
                                    <option value="bottle">Bottle</option>
                                    <option value="can">Can</option>
                                    <option value="pack">Pack</option>
                                    <option value="pair">Pair</option>
                                </optgroup>
                                <option value="custom">-- Custom Unit --</option>
                            </select>
                            <input type="text" name="custom_sec_unit" class="form-control mt-2" id="addCustomSecUnit" style="display: none;" placeholder="Enter custom secondary unit">
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3 mb-2" style="font-size: 12px;">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Example:</strong> For "1 bag = 107 pcs", set Primary Qty=1, Primary Unit=bag, Secondary Qty=107, Secondary Unit=pcs
                    </div>
                    
                    <div class="alert alert-warning mt-2 mb-0" style="font-size: 11px;">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Leave secondary unit empty if product has only one unit of measurement.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-testid="button-submit-add-product">Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Import Modal -->
<div class="modal fade" id="bulkImportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Import Products</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Instructions:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Download the template CSV file first</li>
                        <li>Fill in your product data</li>
                        <li>Upload the completed CSV file</li>
                        <li>Existing products will be updated based on Product Name</li>
                        <li>New products will be created</li>
                        <li>HSN codes with GST will be auto-created if not exists</li>
                        <li><strong>Product Type:</strong> Use "direct" or "converted" in the CSV (default: direct)</li>
                    </ul>
                </div>
                
                <div class="text-center mb-4">
                    <a href="bulk_import_products.php?download_template=1" class="btn btn-outline-success">
                        <i class="bi bi-download"></i> Download Template CSV
                    </a>
                </div>
                
                <form id="bulkImportForm" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Select CSV File</label>
                        <input type="file" name="import_file" class="form-control" accept=".csv" required>
                        <small class="text-muted">Only CSV files are supported</small>
                    </div>
                    
                    <div class="import-progress">
                        <div class="progress-bar-import">
                            <div class="progress-fill" id="importProgress"></div>
                        </div>
                        <p class="text-center mt-2" id="importStatus">Processing...</p>
                    </div>
                    
                    <div class="import-results" id="importResults"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" form="bulkImportForm" class="btn btn-success" id="importBtn">
                    <i class="bi bi-upload"></i> Import Products
                </button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    var productsTable = $('#productsTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search products:",
            lengthMenu: "Show _MENU_ products",
            info: "Showing _START_ to _END_ of _TOTAL_ products",
            emptyTable: "No products available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: -1 }
            <?php else: ?>
            { orderable: false, targets: [] }
            <?php endif; ?>
        ]
    });

    // Product Type Filter Function
    function filterProductsByType(type) {
        if (type === 'all') {
            productsTable.column(2).search('').draw();
        } else {
            // Use regex to match the product type in the table
            var searchValue = type === 'direct' ? 'Direct Sale' : 'Converted Sale';
            productsTable.column(2).search(searchValue, true, false).draw();
        }
    }

    // Filter buttons click handler
    $('.filter-btn').click(function() {
        var filterType = $(this).data('filter');
        
        // Update active state
        $('.filter-btn').removeClass('active');
        $(this).addClass('active');
        
        // Apply filter
        filterProductsByType(filterType);
    });
    
    // Stats card click handler
    $('.stat-direct-clickable').click(function() {
        $('.filter-btn[data-filter="direct"]').click();
    });
    
    $('.stat-converted-clickable').click(function() {
        $('.filter-btn[data-filter="converted"]').click();
    });
    
    // Handle HSN type selection for add modal
    $('.hsn-type-radio-add').change(function() {
        if ($(this).val() === 'custom') {
            $('#existing_hsn_section_add').hide();
            $('#custom_hsn_section_add').show();
        } else {
            $('#existing_hsn_section_add').show();
            $('#custom_hsn_section_add').hide();
        }
    });
    
    // Calculate total GST for add modal
    function calculateGSTAdd() {
        const cgst = parseFloat($('#cgst_add').val()) || 0;
        const sgst = parseFloat($('#sgst_add').val()) || 0;
        const total = cgst + sgst;
        $('#gst_total_add').text('Total GST: ' + total.toFixed(2) + '%');
    }
    
    $('#cgst_add, #sgst_add').on('input', calculateGSTAdd);
    
    // Handle HSN type selection for edit modals
    $('.hsn-type-radio').change(function() {
        const target = $(this).data('target');
        if ($(this).val() === 'custom') {
            $('#existing_hsn_section_' + target).hide();
            $('#custom_hsn_section_' + target).show();
        } else {
            $('#existing_hsn_section_' + target).show();
            $('#custom_hsn_section_' + target).hide();
        }
    });
    
    // Calculate total GST for edit modals
    $('.gst-input').on('input', function() {
        const target = $(this).data('target');
        const cgst = parseFloat($('#cgst_' + target).val()) || 0;
        const sgst = parseFloat($('#sgst_' + target).val()) || 0;
        const total = cgst + sgst;
        $('#gst_total_' + target).text('Total GST: ' + total.toFixed(2) + '%');
    });

    // Handle custom unit for primary unit in add modal
    $('#addPrimaryUnit').change(function() {
        if ($(this).val() === 'custom') {
            $('#addCustomPrimaryUnit').show();
        } else {
            $('#addCustomPrimaryUnit').hide();
        }
    });
    
    // Handle custom unit for secondary unit in add modal
    $('#addSecUnit').change(function() {
        if ($(this).val() === 'custom') {
            $('#addCustomSecUnit').show();
        } else {
            $('#addCustomSecUnit').hide();
        }
    });
    
    // Handle custom unit for primary unit in edit modals
    $('select[id^="editPrimaryUnit"]').each(function() {
        $(this).change(function() {
            var modalId = $(this).attr('id').replace('editPrimaryUnit', '');
            if ($(this).val() === 'custom') {
                $('#editCustomPrimaryUnit' + modalId).show();
            } else {
                $('#editCustomPrimaryUnit' + modalId).hide();
            }
        });
    });
    
    // Handle custom unit for secondary unit in edit modals
    $('select[id^="editSecUnit"]').each(function() {
        $(this).change(function() {
            var modalId = $(this).attr('id').replace('editSecUnit', '');
            if ($(this).val() === 'custom') {
                $('#editCustomSecUnit' + modalId).show();
            } else {
                $('#editCustomSecUnit' + modalId).hide();
            }
        });
    });

    // Bulk Import Form Submission
    $('#bulkImportForm').on('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        
        // Show progress
        $('.import-progress').show();
        $('#importResults').hide();
        $('#importBtn').prop('disabled', true);
        
        $.ajax({
            url: 'bulk_import_products.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percent = (e.loaded / e.total) * 100;
                        $('#importProgress').css('width', percent + '%');
                        $('#importStatus').text('Uploading: ' + Math.round(percent) + '%');
                    }
                });
                return xhr;
            },
            success: function(response) {
                $('#importProgress').css('width', '100%');
                $('#importStatus').text('Processing complete!');
                
                setTimeout(function() {
                    if (response.success) {
                        var resultClass = response.imported > 0 && response.failed === 0 ? 'success' : 'error';
                        var resultHtml = '<div class="import-results ' + resultClass + '" style="display: block;">';
                        resultHtml += '<h6><i class="bi bi-' + (response.imported > 0 ? 'check-circle' : 'exclamation-triangle') + '"></i> Import Results</h6>';
                        resultHtml += '<p><strong>Imported:</strong> ' + response.imported + ' | <strong>Updated:</strong> ' + (response.updated || 0) + ' | <strong>Failed:</strong> ' + response.failed + '</p>';
                        
                        if (response.errors && response.errors.length > 0) {
                            resultHtml += '<div class="error-list"><strong>Errors:</strong><ul>';
                            $.each(response.errors, function(i, error) {
                                resultHtml += '<li>' + error + '</li>';
                            });
                            resultHtml += '</ul></div>';
                        }
                        
                        resultHtml += '</div>';
                        
                        $('#importResults').html(resultHtml).show();
                        
                        // Reload page after successful import
                        if (response.imported > 0 || response.updated > 0) {
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        }
                    } else {
                        $('#importResults').html('<div class="import-results error" style="display: block;"><i class="bi bi-exclamation-triangle"></i> ' + response.message + '</div>').show();
                    }
                }, 500);
            },
            error: function() {
                $('#importResults').html('<div class="import-results error" style="display: block;"><i class="bi bi-exclamation-triangle"></i> Upload failed. Please try again.</div>').show();
            },
            complete: function() {
                $('#importBtn').prop('disabled', false);
            }
        });
    });

    // Reset import modal when closed
    $('#bulkImportModal').on('hidden.bs.modal', function() {
        $('#bulkImportForm')[0].reset();
        $('.import-progress').hide();
        $('#importResults').hide();
        $('#importProgress').css('width', '0%');
    });
});
</script>
</body>
</html>