<?php
session_start();
$currentPage = 'bulk_sale';
$pageTitle = 'Bulk Category Sale';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can create bulk sales
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Get all categories with stock
$categories_query = "SELECT id, category_name, gram_value, purchase_price, total_quantity 
                     FROM category 
                     WHERE total_quantity > 0 
                     ORDER BY category_name ASC";
$categories = $conn->query($categories_query);

// Get customers for dropdown
$customers_query = "SELECT id, customer_name, phone, opening_balance FROM customers ORDER BY customer_name ASC";
$customers = $conn->query($customers_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Category Sale - Silver Exchange</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #1e293b;
            --light: #f8fafc;
            --text-primary: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --card-bg: #ffffff;
            --body-bg: #f1f5f9;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--body-bg);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* Full Page Layout */
        .full-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Navigation */
        .top-nav {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 20px;
        }

        .brand-text h5 {
            font-weight: 700;
            margin: 0;
            color: var(--text-primary);
            font-size: 1.1rem;
        }

        .brand-text p {
            margin: 0;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn-back {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--light);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-back:hover {
            background: var(--border-color);
            color: var(--text-primary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            background: var(--light);
            border-radius: 8px;
            cursor: pointer;
        }

        .user-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            max-width: 1600px;
            margin: 0 auto;
            width: 100%;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h2 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            font-size: 2rem;
        }

        .page-header p {
            color: var(--text-muted);
            font-size: 1rem;
            margin: 0;
        }

        /* Cards */
        .dashboard-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: all 0.3s ease;
            margin-bottom: 24px;
        }

        .dashboard-card:hover {
            box-shadow: 0 8px 30px rgba(0,0,0,0.05);
        }

        .card-header {
            padding: 20px 24px;
            background: white;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header h5 {
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
            font-size: 1.1rem;
        }

        .card-header p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin: 5px 0 0 0;
        }

        .card-body {
            padding: 24px;
        }

        /* Category Select Card */
        .category-select-card {
            background: var(--light);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
        }

        /* Calculation Preview */
        .calculation-preview {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .calculation-preview .preview-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .calculation-preview .preview-item:last-child {
            border-bottom: none;
        }
        
        .calculation-preview .total-amount {
            font-size: 24px;
            font-weight: 700;
            margin-top: 10px;
            text-align: right;
        }
        
        .stock-info {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 14px;
            margin-top: 8px;
        }
        
        .stock-info.warning {
            background: #ffebee;
            color: #c62828;
        }
        
        .formula-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            margin-left: 10px;
        }
        
        .selected-category-item {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .selected-category-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .remove-category {
            position: absolute;
            top: 10px;
            right: 10px;
            color: var(--danger);
            cursor: pointer;
            background: none;
            border: none;
            font-size: 18px;
        }
        
        .pcs-calculation {
            background: var(--light);
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .pcs-calculation .formula {
            font-family: monospace;
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 4px;
        }
        
        .btn-bulk-sale {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .btn-bulk-sale:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
            color: white;
        }

        .btn-bulk-sale:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-add-category {
            background: var(--success);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-add-category:hover {
            background: #059669;
            color: white;
        }
        
        .category-select, .selling-price-input {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
        }
        
        .category-select:focus, .selling-price-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        /* Select2 Custom Styling */
        .select2-container--bootstrap-5 .select2-selection {
            min-height: 45px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
        }
        
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            line-height: 45px;
            padding-left: 12px;
        }
        
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__arrow {
            height: 45px;
        }
        
        .profit-badge {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 20px;
            background: #e8f5e9;
            color: #2e7d32;
            display: inline-block;
        }
        
        .profit-badge.loss {
            background: #ffebee;
            color: #c62828;
        }
        
        .customer-info {
            background: var(--light);
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            font-size: 13px;
            border: 1px solid var(--border-color);
        }
        
        .customer-info-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
        }
        
        .margin-info {
            border-top: 1px dashed var(--border-color);
            margin-top: 10px;
            padding-top: 10px;
        }
        
        .selling-price-wrapper {
            position: relative;
        }
        
        .selling-price-wrapper .currency-symbol {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-weight: 500;
            z-index: 1;
        }
        
        .selling-price-wrapper input {
            padding-left: 28px !important;
        }

        /* Form Controls */
        .form-label {
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        /* Alerts */
        .alert {
            border-radius: 12px;
            padding: 16px 20px;
            border: none;
            margin-bottom: 24px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Footer */
        .footer {
            background: white;
            padding: 20px 30px;
            border-top: 1px solid var(--border-color);
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .top-nav {
                padding: 1rem;
                flex-direction: column;
                gap: 10px;
            }
            
            .nav-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .main-content {
                padding: 20px 15px;
            }
            
            .page-header h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="full-page">
        <!-- Top Navigation -->
        <nav class="top-nav">
            <div class="nav-brand">
                <div class="brand-logo">SE</div>
                <div class="brand-text">
                    <h5>Silver Exchange</h5>
                    <p>Bulk Category Sale</p>
                </div>
            </div>
            <div class="nav-actions">
                <a href="invoices.php" class="btn-back">
                    <i class="bi bi-arrow-left"></i>
                    <span>Back to Invoices</span>
                </a>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div>
                        <small style="color: var(--text-muted);">Welcome,</small>
                        <div style="font-weight: 600; font-size: 0.9rem;"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h2>Bulk Category Sale (KG Wise)</h2>
                <p>Sell categories in kilograms - automatically converts to pieces</p>
            </div>

            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Left Column - Category Selection -->
                <div class="col-lg-8">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h5>Select Categories for Bulk Sale</h5>
                            <p>Add categories and specify quantity in KG - system will calculate pieces</p>
                        </div>
                        <div class="card-body">
                            <!-- Category Selection Form -->
                            <div class="category-select-card">
                                <form id="addCategoryForm" onsubmit="event.preventDefault(); addCategory();">
                                    <div class="row g-3">
                                        <div class="col-md-5">
                                            <label class="form-label">Select Category</label>
                                            <select class="category-select form-select" id="categorySelect" required style="width: 100%;">
                                                <option value="">Choose category...</option>
                                                <?php 
                                                $categories->data_seek(0);
                                                while ($cat = $categories->fetch_assoc()): 
                                                ?>
                                                    <?php 
                                                    $max_kg = ($cat['total_quantity'] * $cat['gram_value']) / 1000;
                                                    ?>
                                                    <option value="<?php echo $cat['id']; ?>" 
                                                            data-name="<?php echo htmlspecialchars($cat['category_name']); ?>"
                                                            data-gram="<?php echo $cat['gram_value']; ?>"
                                                            data-purchase-price="<?php echo $cat['purchase_price']; ?>"
                                                            data-stock="<?php echo $cat['total_quantity']; ?>"
                                                            data-max-kg="<?php echo number_format($max_kg, 2); ?>">
                                                        <?php echo htmlspecialchars($cat['category_name']); ?> 
                                                        (<?php echo number_format($cat['gram_value'], 3); ?>g - 
                                                        Stock: <?php echo number_format($cat['total_quantity']); ?> pcs)
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Selling Price (₹/pcs)</label>
                                            <div class="selling-price-wrapper">
                                                <span class="currency-symbol">₹</span>
                                                <input type="number" class="form-control" id="sellingPrice" step="0.01" min="0" placeholder="Price per pcs" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Quantity (KG) <span class="formula-badge">1000/gram * KG = PCS</span></label>
                                            <input type="number" class="form-control" id="kgQuantity" step="0.001" min="0.001" placeholder="Enter KG" required>
                                            <small class="text-muted" id="maxKgHint"></small>
                                        </div>
                                    </div>
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <button type="submit" class="btn-add-category w-100">
                                                <i class="bi bi-plus-circle"></i> Add Category to Sale
                                            </button>
                                        </div>
                                    </div>
                                    <div id="categoryInfo" class="mt-2 small" style="display: none;">
                                        <span id="categoryGram"></span> | 
                                        <span id="categoryStock"></span> | 
                                        <span id="categoryPurchasePrice"></span>
                                    </div>
                                </form>
                            </div>

                            <!-- Selected Categories List -->
                            <div id="selectedCategoriesContainer" class="mt-4">
                                <h6 class="fw-semibold mb-3">Selected Categories <span class="text-muted" id="selectedCount">(0 items)</span></h6>
                                <div id="selectedCategoriesList">
                                    <!-- Selected categories will be added here dynamically -->
                                </div>
                                <div id="emptySelection" class="text-center py-5" style="display: block;">
                                    <i class="bi bi-cart-plus text-muted" style="font-size: 48px;"></i>
                                    <p class="text-muted mt-2">No categories selected. Add categories from above.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Customer & Preview -->
                <div class="col-lg-4">
                    <!-- Customer Selection -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h5>Customer Details</h5>
                        </div>
                        <div class="card-body">
                            <form id="bulkSaleForm" method="POST" action="process_bulk_sale.php">
                                <div class="mb-3">
                                    <label class="form-label">Select Customer <span class="text-danger">*</span></label>
                                    <select class="form-select customer-select" name="customer_id" id="customerSelect" required style="width: 100%;">
                                        <option value="">Choose customer...</option>
                                        <?php 
                                        $customers->data_seek(0);
                                        while ($cust = $customers->fetch_assoc()): 
                                        ?>
                                            <option value="<?php echo $cust['id']; ?>" 
                                                    data-phone="<?php echo htmlspecialchars($cust['phone']); ?>"
                                                    data-balance="<?php echo $cust['opening_balance']; ?>">
                                                <?php echo htmlspecialchars($cust['customer_name']); ?> 
                                                <?php if (!empty($cust['phone'])): ?>- <?php echo htmlspecialchars($cust['phone']); ?><?php endif; ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <div id="customerInfo" class="customer-info" style="display: none;">
                                        <div class="customer-info-item">
                                            <span>Phone:</span>
                                            <span id="customerPhone" class="fw-semibold"></span>
                                        </div>
                                        <div class="customer-info-item">
                                            <span>Opening Balance:</span>
                                            <span id="customerBalance" class="fw-semibold">₹0.00</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Hidden input for selected categories data -->
                                <input type="hidden" name="selected_categories" id="selectedCategoriesInput">
                                
                                <!-- Hidden input for total amount -->
                                <input type="hidden" name="total_amount" id="totalAmountInput">
                                
                                <!-- Hidden input for total purchase cost -->
                                <input type="hidden" name="total_cost" id="totalCostInput">

                                <!-- Calculation Preview -->
                                <div class="calculation-preview">
                                    <h6 class="mb-3" style="color: rgba(255,255,255,0.9);">Sale Summary</h6>
                                    <div id="previewItems">
                                        <div class="preview-item">
                                            <span>Total Categories:</span>
                                            <span id="previewCategoryCount">0</span>
                                        </div>
                                        <div class="preview-item">
                                            <span>Total Pieces:</span>
                                            <span id="previewTotalPcs">0</span>
                                        </div>
                                        <div class="preview-item">
                                            <span>Total Weight (KG):</span>
                                            <span id="previewTotalKg">0.000</span>
                                        </div>
                                        <div class="preview-item">
                                            <span>Total Cost:</span>
                                            <span id="previewTotalCost">₹0.00</span>
                                        </div>
                                        <div class="preview-item">
                                            <span>Total Selling:</span>
                                            <span id="previewSubtotal">₹0.00</span>
                                        </div>
                                        <div class="preview-item" style="border-top: 1px solid rgba(255,255,255,0.3); margin-top: 5px; padding-top: 8px;">
                                            <span>Profit Margin:</span>
                                            <span id="previewProfit" class="fw-bold">₹0.00 (0%)</span>
                                        </div>
                                    </div>
                                    <div class="total-amount">
                                        Total: <span id="previewTotal">₹0.00</span>
                                    </div>
                                </div>

                                <!-- Payment Section -->
                                <div class="mb-3">
                                    <label class="form-label">Payment Method</label>
                                    <select class="form-select" name="payment_method" id="paymentMethod">
                                        <option value="cash">Cash</option>
                                        <option value="upi">UPI</option>
                                        <option value="card">Card</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="credit">Credit</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Amount Received</label>
                                    <input type="number" class="form-control" name="amount_received" step="0.01" min="0" value="0" id="amountReceived">
                                </div>

                                <div class="mb-3" id="dueDateSection" style="display: none;">
                                    <label class="form-label">Credit Due Date</label>
                                    <input type="date" class="form-control" name="credit_due_date" id="creditDueDate">
                                </div>

                                <button type="submit" class="btn-bulk-sale" id="processSaleBtn" disabled>
                                    <i class="bi bi-lightning-charge"></i> Process Bulk Sale
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Formula Info -->
                    <div class="dashboard-card">
                        <div class="card-body">
                            <h6 class="fw-semibold mb-2">Conversion Formula</h6>
                            <div class="pcs-calculation">
                                <span class="formula">Pieces = (1000 ÷ Gram per piece) × KG</span>
                                <div class="mt-2 small">
                                    <strong>Example:</strong><br>
                                    If 1 piece = 12.3g, then for 10 KG:<br>
                                    (1000 ÷ 12.3) × 10 = 813 pieces
                                </div>
                            </div>
                            <div class="mt-3 small text-muted">
                                <i class="bi bi-info-circle"></i> Selling price is per piece. Profit calculation shows your margin.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="footer">
            <p>&copy; 2024 Silver Exchange. All rights reserved.</p>
        </footer>
    </div>

    <!-- jQuery and Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
    // Initialize Select2
    $(document).ready(function() {
        $('#categorySelect').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Search category...',
            allowClear: true,
            dropdownParent: $('#addCategoryForm')
        });
        
        $('#customerSelect').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Search customer...',
            allowClear: true,
            dropdownParent: $('#bulkSaleForm')
        });
    });

    // Store selected categories
    let selectedCategories = [];

    // Update category info when selected
    document.getElementById('categorySelect').addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const infoDiv = document.getElementById('categoryInfo');
        
        if (this.value) {
            const gram = selected.dataset.gram;
            const stock = selected.dataset.stock;
            const purchasePrice = selected.dataset.purchasePrice;
            const maxKg = selected.dataset.maxKg;
            
            document.getElementById('categoryGram').innerHTML = `<strong>Gram:</strong> ${gram}g`;
            document.getElementById('categoryStock').innerHTML = `<strong>Stock:</strong> ${stock} pcs`;
            document.getElementById('categoryPurchasePrice').innerHTML = `<strong>Purchase Price:</strong> ₹${purchasePrice}/pcs`;
            document.getElementById('maxKgHint').innerHTML = `Max available: ${maxKg} KG`;
            
            // Suggest selling price (purchase price + 20% margin as example)
            const suggestedPrice = (parseFloat(purchasePrice) * 1.2).toFixed(2);
            document.getElementById('sellingPrice').placeholder = `Suggested: ₹${suggestedPrice}`;
            
            infoDiv.style.display = 'block';
        } else {
            infoDiv.style.display = 'none';
            document.getElementById('maxKgHint').innerHTML = '';
            document.getElementById('sellingPrice').placeholder = 'Price per pcs';
        }
    });

    // Show customer info when selected
    document.getElementById('customerSelect').addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const infoDiv = document.getElementById('customerInfo');
        
        if (this.value) {
            const phone = selected.dataset.phone || 'N/A';
            const balance = parseFloat(selected.dataset.balance || 0);
            
            document.getElementById('customerPhone').innerText = phone;
            document.getElementById('customerBalance').innerHTML = `₹${balance.toFixed(2)}`;
            infoDiv.style.display = 'block';
        } else {
            infoDiv.style.display = 'none';
        }
    });

    // Show/hide due date for credit payment
    document.getElementById('paymentMethod').addEventListener('change', function() {
        const dueDateSection = document.getElementById('dueDateSection');
        if (this.value === 'credit') {
            dueDateSection.style.display = 'block';
            // Set default due date to 30 days from now
            const dueDate = new Date();
            dueDate.setDate(dueDate.getDate() + 30);
            document.getElementById('creditDueDate').valueAsDate = dueDate;
        } else {
            dueDateSection.style.display = 'none';
        }
    });

    // Add category function
    function addCategory() {
        const categorySelect = document.getElementById('categorySelect');
        const sellingPriceInput = document.getElementById('sellingPrice');
        const kgInput = document.getElementById('kgQuantity');
        
        if (!categorySelect.value) {
            alert('Please select a category');
            return;
        }
        
        if (!sellingPriceInput.value || parseFloat(sellingPriceInput.value) <= 0) {
            alert('Please enter a valid selling price');
            return;
        }
        
        if (!kgInput.value || parseFloat(kgInput.value) <= 0) {
            alert('Please enter KG quantity');
            return;
        }
        
        const selected = categorySelect.options[categorySelect.selectedIndex];
        const categoryId = categorySelect.value;
        const categoryName = selected.dataset.name;
        const gramValue = parseFloat(selected.dataset.gram);
        const purchasePrice = parseFloat(selected.dataset.purchasePrice);
        const sellingPrice = parseFloat(sellingPriceInput.value);
        const stockPcs = parseFloat(selected.dataset.stock);
        const kgQty = parseFloat(kgInput.value);
        
        // Calculate pieces using formula: (1000 / gram) * kg
        const pcsPerKg = 1000 / gramValue;
        const requiredPcs = pcsPerKg * kgQty;
        const roundedPcs = Math.ceil(requiredPcs); // Round up to avoid fractional pieces
        
        // Check if enough stock
        if (roundedPcs > stockPcs) {
            alert(`Insufficient stock! Available: ${stockPcs} pcs, Required: ${roundedPcs} pcs`);
            return;
        }
        
        // Check if already added
        if (selectedCategories.find(c => c.id === categoryId)) {
            alert('This category is already added. Please remove it first to add again.');
            return;
        }
        
        // Calculate totals
        const totalCost = roundedPcs * purchasePrice;
        const totalSelling = roundedPcs * sellingPrice;
        
        // Add to array
        selectedCategories.push({
            id: categoryId,
            name: categoryName,
            gram: gramValue,
            purchasePrice: purchasePrice,
            sellingPrice: sellingPrice,
            kgQty: kgQty,
            pcsQty: roundedPcs,
            totalCost: totalCost,
            totalSelling: totalSelling
        });
        
        // Clear inputs
        categorySelect.value = '';
        $('#categorySelect').trigger('change'); // Reset Select2
        sellingPriceInput.value = '';
        kgInput.value = '';
        document.getElementById('categoryInfo').style.display = 'none';
        document.getElementById('maxKgHint').innerHTML = '';
        
        // Update UI
        renderSelectedCategories();
        updatePreview();
    }

    // Remove category function
    function removeCategory(index) {
        selectedCategories.splice(index, 1);
        renderSelectedCategories();
        updatePreview();
    }

    // Render selected categories
    function renderSelectedCategories() {
        const container = document.getElementById('selectedCategoriesList');
        const emptyDiv = document.getElementById('emptySelection');
        const countSpan = document.getElementById('selectedCount');
        
        if (selectedCategories.length === 0) {
            container.innerHTML = '';
            emptyDiv.style.display = 'block';
            countSpan.innerHTML = '(0 items)';
            return;
        }
        
        emptyDiv.style.display = 'none';
        countSpan.innerHTML = `(${selectedCategories.length} items)`;
        
        let html = '';
        selectedCategories.forEach((cat, index) => {
            const profitPerPcs = cat.sellingPrice - cat.purchasePrice;
            const profitClass = profitPerPcs >= 0 ? '' : 'loss';
            
            html += `
                <div class="selected-category-item">
                    <button class="remove-category" onclick="removeCategory(${index})">
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="fw-semibold mb-0">${cat.name}</h6>
                        <span class="badge bg-primary">₹${cat.sellingPrice.toFixed(2)}/pcs</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <small class="text-muted d-block">KG Quantity:</small>
                            <span class="fw-semibold">${cat.kgQty.toFixed(3)} KG</span>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Pieces:</small>
                            <span class="fw-semibold">${cat.pcsQty} pcs</span>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <small class="text-muted d-block">Purchase Cost:</small>
                            <span>₹${cat.totalCost.toFixed(2)}</span>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Selling Amount:</small>
                            <span class="fw-bold" style="color: #10b981;">₹${cat.totalSelling.toFixed(2)}</span>
                        </div>
                    </div>
                    <div class="pcs-calculation mt-2">
                        <span class="formula">Formula: (1000 ÷ ${cat.gram}g) × ${cat.kgQty}KG = ${cat.pcsQty} pcs</span>
                    </div>
                    <div class="margin-info">
                        <span class="profit-badge ${profitClass}">
                            Profit per pcs: ₹${profitPerPcs.toFixed(2)}
                        </span>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    // Update preview function
    function updatePreview() {
        const totalCategories = selectedCategories.length;
        const totalPcs = selectedCategories.reduce((sum, cat) => sum + cat.pcsQty, 0);
        const totalKg = selectedCategories.reduce((sum, cat) => sum + cat.kgQty, 0);
        const totalCost = selectedCategories.reduce((sum, cat) => sum + cat.totalCost, 0);
        const totalSelling = selectedCategories.reduce((sum, cat) => sum + cat.totalSelling, 0);
        const profit = totalSelling - totalCost;
        const profitPercentage = totalCost > 0 ? ((profit / totalCost) * 100).toFixed(1) : 0;
        
        document.getElementById('previewCategoryCount').innerText = totalCategories;
        document.getElementById('previewTotalPcs').innerText = totalPcs.toLocaleString();
        document.getElementById('previewTotalKg').innerText = totalKg.toFixed(3);
        document.getElementById('previewTotalCost').innerHTML = `₹${totalCost.toFixed(2)}`;
        document.getElementById('previewSubtotal').innerHTML = `₹${totalSelling.toFixed(2)}`;
        document.getElementById('previewProfit').innerHTML = `₹${profit.toFixed(2)} (${profitPercentage}%)`;
        document.getElementById('previewTotal').innerHTML = `₹${totalSelling.toFixed(2)}`;
        
        document.getElementById('totalAmountInput').value = totalSelling.toFixed(2);
        document.getElementById('totalCostInput').value = totalCost.toFixed(2);
        
        // Enable/disable process button
        const processBtn = document.getElementById('processSaleBtn');
        processBtn.disabled = totalCategories === 0;
        
        // Update hidden input with selected categories data
        document.getElementById('selectedCategoriesInput').value = JSON.stringify(selectedCategories);
        
        // Update profit color
        const profitSpan = document.getElementById('previewProfit');
        if (profit < 0) {
            profitSpan.style.color = '#ef4444';
        } else {
            profitSpan.style.color = '#10b981';
        }
    }

    // Handle amount received change
    document.getElementById('amountReceived').addEventListener('input', function(e) {
        const received = parseFloat(e.target.value) || 0;
        const total = parseFloat(document.getElementById('totalAmountInput').value) || 0;
        
        if (received >= total) {
            document.getElementById('processSaleBtn').innerHTML = '<i class="bi bi-check-circle"></i> Process Sale (Paid Full)';
        } else if (received > 0) {
            document.getElementById('processSaleBtn').innerHTML = '<i class="bi bi-clock"></i> Process Sale (Partial Payment)';
        } else {
            document.getElementById('processSaleBtn').innerHTML = '<i class="bi bi-lightning-charge"></i> Process Bulk Sale';
        }
    });

    // Form submission validation
    document.getElementById('bulkSaleForm').addEventListener('submit', function(e) {
        if (selectedCategories.length === 0) {
            e.preventDefault();
            alert('Please add at least one category to sell.');
            return false;
        }
        
        const customerId = document.querySelector('select[name="customer_id"]').value;
        if (!customerId) {
            e.preventDefault();
            alert('Please select a customer.');
            return false;
        }
        
        const paymentMethod = document.getElementById('paymentMethod').value;
        if (paymentMethod === 'credit') {
            const dueDate = document.getElementById('creditDueDate').value;
            if (!dueDate) {
                e.preventDefault();
                alert('Please select credit due date.');
                return false;
            }
        }
        
        return true;
    });
    </script>
</body>
</html>