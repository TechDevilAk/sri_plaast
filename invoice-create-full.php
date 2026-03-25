<?php
session_start();
$currentPage = 'invoice-create';
$pageTitle = 'Create Invoice';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can create invoices
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Get customers for dropdown
$customers = $conn->query("SELECT id, customer_name, phone, gst_number FROM customers ORDER BY customer_name ASC");

// Get categories with stock (for inventory tracking)
$categories_query = "SELECT id, category_name, purchase_price, gram_value, 
                     total_quantity as available_stock, min_stock_level 
                     FROM category 
                     WHERE total_quantity > 0 
                     ORDER BY category_name ASC";
$categories = $conn->query($categories_query);

// Get all products (for item selection)
$products_query = "SELECT * FROM product ORDER BY product_name ASC";
$products = $conn->query($products_query);

// Get GST rates
$gst_rates = $conn->query("SELECT * FROM gst WHERE status = 1 ORDER BY hsn ASC");

// Get invoice settings
$invoice_settings = $conn->query("SELECT * FROM invoice_setting LIMIT 1")->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    
    $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
    $customer_name = trim($_POST['customer_name'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $cash_received = floatval($_POST['cash_received'] ?? 0);
    $overall_discount = floatval($_POST['overall_discount'] ?? 0);
    $overall_discount_type = $_POST['overall_discount_type'] ?? 'amount';
    $notes = trim($_POST['notes'] ?? '');
    
    // Get invoice items
    $category_ids = $_POST['category_id'] ?? [];
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $selling_prices = $_POST['selling_price'] ?? [];
    $discounts = $_POST['discount'] ?? [];
    $discount_types = $_POST['discount_type'] ?? [];
    
    // Validate at least one item
    if (empty($category_ids) || count(array_filter($category_ids)) == 0) {
        $error = "Please add at least one item to the invoice.";
    } elseif (empty($customer_name) && !$customer_id) {
        $error = "Please select or enter a customer name.";
    } else {
        $conn->begin_transaction();
        
        try {
            // If new customer, insert into customers table
            if (!$customer_id && !empty($customer_name)) {
                $insert_cust = $conn->prepare("INSERT INTO customers (customer_name) VALUES (?)");
                $insert_cust->bind_param("s", $customer_name);
                if (!$insert_cust->execute()) {
                    throw new Exception("Failed to save customer.");
                }
                $customer_id = $insert_cust->insert_id;
                $insert_cust->close();
            }
            
            // Generate invoice number
            $prefix = $invoice_settings['invoice_prefix'] ?? 'INV';
            $start = $invoice_settings['invoice_start'] ?? 1;
            
            $last_inv = $conn->query("SELECT inv_num FROM invoice WHERE inv_num LIKE '$prefix%' ORDER BY id DESC LIMIT 1");
            if ($last_inv && $last_inv->num_rows > 0) {
                $last = $last_inv->fetch_assoc();
                $last_num = intval(str_replace($prefix, '', $last['inv_num']));
                $new_num = $last_num + 1;
            } else {
                $new_num = $start;
            }
            $invoice_number = $prefix . str_pad($new_num, 5, '0', STR_PAD_LEFT);
            
            // Calculate totals
            $subtotal = 0;
            $total_taxable = 0;
            $total_cgst = 0;
            $total_sgst = 0;
            
            // Prepare invoice items array for later insertion
            $invoice_items = [];
            
            foreach ($category_ids as $index => $category_id) {
                if (empty($category_id)) continue;
                
                $product_id = intval($product_ids[$index] ?? 0);
                $quantity = floatval($quantities[$index] ?? 0);
                $selling_price = floatval($selling_prices[$index] ?? 0);
                $discount = floatval($discounts[$index] ?? 0);
                $discount_type = $discount_types[$index] ?? 'amount';
                
                if ($quantity <= 0 || $selling_price <= 0) continue;
                
                // Get category details
                $cat_query = $conn->prepare("SELECT * FROM category WHERE id = ?");
                $cat_query->bind_param("i", $category_id);
                $cat_query->execute();
                $category_data = $cat_query->get_result()->fetch_assoc();
                
                if (!$category_data) {
                    throw new Exception("Category not found.");
                }
                
                // Check stock availability
                if ($category_data['total_quantity'] < $quantity) {
                    throw new Exception("Insufficient stock for " . $category_data['category_name'] . ". Available: " . $category_data['total_quantity'] . " kg");
                }
                
                // Get product details if selected
                $product_name = '';
                $product_unit = '';
                if ($product_id > 0) {
                    $prod_query = $conn->prepare("SELECT product_name, primary_unit FROM product WHERE id = ?");
                    $prod_query->bind_param("i", $product_id);
                    $prod_query->execute();
                    $product_data = $prod_query->get_result()->fetch_assoc();
                    $product_name = $product_data['product_name'] ?? '';
                    $product_unit = $product_data['primary_unit'] ?? 'kg';
                } else {
                    $product_name = $category_data['category_name'];
                    $product_unit = 'kg';
                }
                
                // Calculate item total
                $item_total = $quantity * $selling_price;
                
                // Apply discount
                $discount_amount = 0;
                if ($discount > 0) {
                    if ($discount_type === 'percentage') {
                        $discount_amount = ($item_total * $discount) / 100;
                    } else {
                        $discount_amount = $discount;
                    }
                }
                $item_total_after_discount = $item_total - $discount_amount;
                
                // Calculate taxable amount (after discount)
                $taxable = $item_total_after_discount;
                
                // Get GST rates (you can customize this logic)
                $cgst_rate = 2.5; // Default 2.5%
                $sgst_rate = 2.5; // Default 2.5%
                
                $cgst_amount = ($taxable * $cgst_rate) / 100;
                $sgst_amount = ($taxable * $sgst_rate) / 100;
                
                // Update stock
                $new_stock = $category_data['total_quantity'] - $quantity;
                $update_stock = $conn->prepare("UPDATE category SET total_quantity = ? WHERE id = ?");
                $update_stock->bind_param("di", $new_stock, $category_id);
                if (!$update_stock->execute()) {
                    throw new Exception("Failed to update stock for " . $category_data['category_name']);
                }
                
                // Store item for later insertion
                $invoice_items[] = [
                    'product_id' => $product_id,
                    'product_name' => $product_name,
                    'cat_id' => $category_id,
                    'cat_name' => $category_data['category_name'],
                    'quantity' => $quantity,
                    'unit' => $product_unit,
                    'purchase_price' => $category_data['purchase_price'],
                    'selling_price' => $selling_price,
                    'discount' => $discount,
                    'discount_type' => $discount_type,
                    'total' => $item_total_after_discount + $cgst_amount + $sgst_amount,
                    'hsn' => '', // Add HSN if available
                    'taxable' => $taxable,
                    'cgst' => $cgst_rate,
                    'cgst_amount' => $cgst_amount,
                    'sgst' => $sgst_rate,
                    'sgst_amount' => $sgst_amount
                ];
                
                $subtotal += $item_total;
                $total_taxable += $taxable;
                $total_cgst += $cgst_amount;
                $total_sgst += $sgst_amount;
            }
            
            // Apply overall discount
            $overall_discount_amount = 0;
            if ($overall_discount > 0) {
                if ($overall_discount_type === 'percentage') {
                    $overall_discount_amount = ($subtotal * $overall_discount) / 100;
                } else {
                    $overall_discount_amount = $overall_discount;
                }
            }
            
            $grand_total = $subtotal - $overall_discount_amount + $total_cgst + $total_sgst;
            
            // Calculate change and pending
            $change_give = 0;
            $pending_amount = 0;
            
            if ($payment_method === 'credit') {
                $pending_amount = $grand_total;
            } else {
                if ($cash_received > $grand_total) {
                    $change_give = $cash_received - $grand_total;
                } elseif ($cash_received < $grand_total) {
                    $pending_amount = $grand_total - $cash_received;
                }
            }
            
            // Calculate CGST and SGST rates safely
            $cgst_rate_final = ($total_cgst > 0 && $total_taxable > 0) ? ($total_cgst / $total_taxable * 100) : 0;
            $sgst_rate_final = ($total_sgst > 0 && $total_taxable > 0) ? ($total_sgst / $total_taxable * 100) : 0;
            
            // Insert invoice - Fixed with variables
            $insert_invoice = $conn->prepare("INSERT INTO invoice 
                (inv_num, customer_id, customer_name, subtotal, overall_discount, overall_discount_type, 
                 total, taxable, cgst, cgst_amount, sgst, sgst_amount, cash_received, change_give, 
                 pending_amount, payment_method) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            // Create variables to avoid reference issues
            $inv_num = $invoice_number;
            $cust_id = $customer_id;
            $cust_name_val = $customer_name;
            $sub_val = $subtotal;
            $ov_discount_val = $overall_discount;
            $ov_discount_type_val = $overall_discount_type;
            $g_total_val = $grand_total;
            $tax_val = $total_taxable;
            $cgst_val = $cgst_rate_final;
            $cgst_amt_val = $total_cgst;
            $sgst_val = $sgst_rate_final;
            $sgst_amt_val = $total_sgst;
            $cash_val = $cash_received;
            $change_val = $change_give;
            $pending_val = $pending_amount;
            $pay_method_val = $payment_method;
            
            $insert_invoice->bind_param(
                "sisddddddddddddds", 
                $inv_num, 
                $cust_id, 
                $cust_name_val, 
                $sub_val, 
                $ov_discount_val, 
                $ov_discount_type_val, 
                $g_total_val, 
                $tax_val, 
                $cgst_val, 
                $cgst_amt_val,
                $sgst_val, 
                $sgst_amt_val,
                $cash_val, 
                $change_val, 
                $pending_val, 
                $pay_method_val
            );
            
            if (!$insert_invoice->execute()) {
                throw new Exception("Failed to create invoice: " . $insert_invoice->error);
            }
            
            $invoice_id = $insert_invoice->insert_id;
            
            // Insert invoice items - Fixed
            foreach ($invoice_items as $item) {
                $insert_item = $conn->prepare("INSERT INTO invoice_item 
                    (invoice_id, product_id, product_name, cat_id, cat_name, quantity, unit, 
                     purchase_price, selling_price, discount, discount_type, total, hsn, 
                     taxable, cgst, cgst_amount, sgst, sgst_amount) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                // Extract item values to variables
                $inv_id = $invoice_id;
                $prod_id = $item['product_id'];
                $prod_name = $item['product_name'];
                $cat_id = $item['cat_id'];
                $cat_name = $item['cat_name'];
                $qty = $item['quantity'];
                $unit = $item['unit'];
                $purchase = $item['purchase_price'];
                $selling = $item['selling_price'];
                $disc = $item['discount'];
                $disc_type = $item['discount_type'];
                $tot = $item['total'];
                $hsn_code = $item['hsn'];
                $taxable_val = $item['taxable'];
                $cgst_rate_val = $item['cgst'];
                $cgst_amt_val2 = $item['cgst_amount'];
                $sgst_rate_val = $item['sgst'];
                $sgst_amt_val2 = $item['sgst_amount'];
                
                $insert_item->bind_param(
                    "iisisdidddsdddddd", 
                    $inv_id, 
                    $prod_id, 
                    $prod_name, 
                    $cat_id, 
                    $cat_name, 
                    $qty, 
                    $unit,
                    $purchase, 
                    $selling, 
                    $disc, 
                    $disc_type, 
                    $tot, 
                    $hsn_code,
                    $taxable_val, 
                    $cgst_rate_val, 
                    $cgst_amt_val2, 
                    $sgst_rate_val, 
                    $sgst_amt_val2
                );
                
                if (!$insert_item->execute()) {
                    throw new Exception("Failed to add invoice item: " . $insert_item->error);
                }
            }
            
            // Log activity
            $log_desc = "Created invoice #$invoice_number for ₹$grand_total";
            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            
            $conn->commit();
            
            // Redirect to invoice view
            header("Location: invoice-view.php?id=$invoice_id");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

// Helper function to format currency
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Sri Plaast</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2463eb;
            --primary-dark: #1e4fba;
            --primary-light: #e8f2ff;
            --success: #10b981;
            --success-light: #e0f2e7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fff3e0;
            --info: #6366f1;
            --info-light: #e0e7ff;
            --text-primary: #1e293b;
            --text-muted: #64748b;
            --border-color: #eef2f6;
            --bg-light: #f8fafc;
        }
        
        body {
            background: var(--bg-light);
            color: var(--text-primary);
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            margin: 0;
            padding: 0;
        }
        
        .full-frame {
            min-height: 100vh;
            padding: 20px;
            background: var(--bg-light);
        }
        
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-header h4 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }
        
        .page-header p {
            font-size: 14px;
            color: var(--text-muted);
            margin: 4px 0 0 0;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: all 0.2s;
        }
        
        .dashboard-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .card-header-custom {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-light);
        }
        
        .card-header-custom h5 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }
        
        .card-header-custom p {
            font-size: 13px;
            color: var(--text-muted);
            margin: 4px 0 0 0;
        }
        
        .card-body-custom {
            padding: 20px;
        }
        
        .product-row {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid var(--border-color);
            position: relative;
            transition: all 0.2s;
        }
        
        .product-row:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(36,99,235,0.1);
        }
        
        .product-row:last-child {
            margin-bottom: 0;
        }
        
        .remove-row {
            position: absolute;
            top: 12px;
            right: 12px;
            color: var(--danger);
            cursor: pointer;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            background: var(--danger-light);
            border: none;
            transition: all 0.2s;
            z-index: 10;
        }
        
        .remove-row:hover {
            background: var(--danger);
            color: white;
        }
        
        .btn-primary-custom {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
            cursor: pointer;
        }
        
        .btn-primary-custom:hover {
            background: var(--primary-dark);
            color: white;
            transform: translateY(-1px);
        }
        
        .btn-outline-custom {
            background: transparent;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
            cursor: pointer;
        }
        
        .btn-outline-custom:hover {
            background: var(--bg-light);
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .btn-success-custom {
            background: var(--success);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-success-custom:hover {
            background: #0f766e;
            transform: translateY(-1px);
        }
        
        .form-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 6px;
        }
        
        .form-control, .form-select {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(36,99,235,0.1);
        }
        
        .summary-card {
            background: linear-gradient(135deg, white 0%, var(--bg-light) 100%);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            position: sticky;
            top: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed var(--border-color);
            font-size: 14px;
        }
        
        .summary-total {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            padding-top: 15px;
            margin-top: 10px;
            border-top: 2px solid var(--border-color);
        }
        
        .payment-option {
            border: 2px solid transparent;
            transition: all 0.2s;
            cursor: pointer;
            border-radius: 12px;
        }
        
        .payment-option:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        
        .payment-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        
        .stock-warning {
            color: var(--danger);
            font-size: 11px;
            margin-top: 4px;
            font-weight: 500;
        }
        
        .gst-badge {
            background: var(--success-light);
            color: var(--success);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .info-text {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .text-primary { color: var(--primary) !important; }
        .text-success { color: var(--success) !important; }
        .text-danger { color: var(--danger) !important; }
        .bg-light-custom { background: var(--bg-light); }
        
        .badge-stock {
            background: var(--bg-light);
            color: var(--text-muted);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .full-frame {
                padding: 10px;
            }
            
            .page-header {
                padding: 16px;
            }
            
            .summary-card {
                position: static;
                margin-top: 20px;
            }
            
            .product-row {
                padding: 15px;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <div class="full-frame">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4 class="fw-bold mb-1">Create New Invoice</h4>
                <p>Select category (for stock) and product (optional) to create invoice</p>
            </div>
            <div class="header-actions">
                <button type="button" class="btn-outline-custom" onclick="window.close()">
                    <i class="bi bi-x-lg"></i> Close
                </button>
                <a href="invoices.php" class="btn-outline-custom" target="_blank">
                    <i class="bi bi-receipt"></i> View Invoices
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="invoice-create-full.php" id="invoiceForm">
            <input type="hidden" name="action" value="create_invoice">
            
            <div class="row g-4">
                <!-- Left Column: Invoice Items -->
                <div class="col-lg-8">
                    <!-- Customer Information -->
                    <div class="dashboard-card mb-4">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-person me-2"></i>Customer Information</h5>
                        </div>
                        <div class="card-body-custom">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Select Customer</label>
                                    <select name="customer_id" class="form-select" id="customerSelect" onchange="selectCustomer()">
                                        <option value="">-- Walk-in Customer --</option>
                                        <?php 
                                        if ($customers && $customers->num_rows > 0) {
                                            while ($customer = $customers->fetch_assoc()): 
                                        ?>
                                            <option value="<?php echo $customer['id']; ?>" 
                                                    data-name="<?php echo htmlspecialchars($customer['customer_name']); ?>"
                                                    data-phone="<?php echo htmlspecialchars($customer['phone']); ?>"
                                                    data-gst="<?php echo htmlspecialchars($customer['gst_number']); ?>">
                                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                                                <?php if ($customer['phone']): ?> (<?php echo $customer['phone']; ?>)<?php endif; ?>
                                            </option>
                                        <?php 
                                            endwhile; 
                                        } 
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Or Enter New Customer</label>
                                    <input type="text" name="customer_name" class="form-control" id="customerName" 
                                           placeholder="Customer name" oninput="clearCustomerSelect()">
                                </div>
                                <div class="col-md-6" id="customerPhone" style="display: none;">
                                    <label class="form-label">Phone</label>
                                    <input type="text" class="form-control" readonly id="displayPhone">
                                </div>
                                <div class="col-md-6" id="customerGST" style="display: none;">
                                    <label class="form-label">GST Number</label>
                                    <input type="text" class="form-control" readonly id="displayGST">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Products Section -->
                    <div class="dashboard-card mb-4">
                        <div class="card-header-custom d-flex justify-content-between align-items-center">
                            <div>
                                <h5><i class="bi bi-cart me-2"></i>Invoice Items</h5>
                                <p class="mb-0">Select category (stock will be deducted) and optional product</p>
                            </div>
                            <button type="button" class="btn-primary-custom btn-sm" onclick="addProductRow()">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                        </div>
                        <div class="card-body-custom">
                            <div id="productRows">
                                <!-- Product rows will be added here dynamically -->
                            </div>
                            <div id="noProducts" class="text-center py-5" style="color: #94a3b8;">
                                <i class="bi bi-box-seam" style="font-size: 48px;"></i>
                                <p class="mt-2">Click "Add Item" to start building invoice</p>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="dashboard-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-pencil me-2"></i>Notes</h5>
                        </div>
                        <div class="card-body-custom">
                            <textarea name="notes" class="form-control" rows="3" placeholder="Any additional notes for this invoice..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Summary & Payment -->
                <div class="col-lg-4">
                    <div class="summary-card">
                        <h5 class="fw-semibold mb-3">Invoice Summary</h5>
                        
                        <div class="summary-row">
                            <span>Subtotal:</span>
                            <span class="fw-semibold" id="summarySubtotal">₹0.00</span>
                        </div>
                        
                        <div class="summary-row">
                            <span>Total Discount:</span>
                            <span class="fw-semibold text-danger" id="summaryDiscount">₹0.00</span>
                        </div>
                        
                        <div class="summary-row">
                            <span>Total Tax (CGST+SGST):</span>
                            <span class="fw-semibold" id="summaryTax">₹0.00</span>
                        </div>
                        
                        <div class="summary-total d-flex justify-content-between">
                            <span>Grand Total:</span>
                            <span class="text-primary" id="summaryTotal">₹0.00</span>
                        </div>

                        <hr class="my-3">

                        <!-- Overall Discount -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Overall Discount</label>
                            <div class="input-group">
                                <input type="number" name="overall_discount" class="form-control" step="0.01" min="0" value="0" id="overallDiscount" oninput="calculateSummary()">
                                <select name="overall_discount_type" class="form-select" style="max-width: 100px;" id="discountType" onchange="calculateSummary()">
                                    <option value="amount">₹</option>
                                    <option value="percentage">%</option>
                                </select>
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <div class="d-flex flex-column gap-2">
                                <label class="payment-option d-flex align-items-center gap-3 p-3 rounded-2 border">
                                    <input type="radio" name="payment_method" value="cash" class="form-check-input m-0" checked onchange="togglePaymentFields(this)">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-cash-stack" style="font-size: 20px; color: #10b981;"></i>
                                        <div>
                                            <div class="fw-semibold">Cash</div>
                                            <div class="info-text">Instant payment</div>
                                        </div>
                                    </div>
                                </label>
                                <label class="payment-option d-flex align-items-center gap-3 p-3 rounded-2 border">
                                    <input type="radio" name="payment_method" value="card" class="form-check-input m-0" onchange="togglePaymentFields(this)">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-credit-card" style="font-size: 20px; color: #8b5cf6;"></i>
                                        <div>
                                            <div class="fw-semibold">Card</div>
                                            <div class="info-text">Credit/Debit Card</div>
                                        </div>
                                    </div>
                                </label>
                                <label class="payment-option d-flex align-items-center gap-3 p-3 rounded-2 border">
                                    <input type="radio" name="payment_method" value="upi" class="form-check-input m-0" onchange="togglePaymentFields(this)">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-phone" style="font-size: 20px; color: #2463eb;"></i>
                                        <div>
                                            <div class="fw-semibold">UPI</div>
                                            <div class="info-text">Google Pay, PhonePe, etc.</div>
                                        </div>
                                    </div>
                                </label>
                                <label class="payment-option d-flex align-items-center gap-3 p-3 rounded-2 border">
                                    <input type="radio" name="payment_method" value="bank" class="form-check-input m-0" onchange="togglePaymentFields(this)">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-bank" style="font-size: 20px; color: #f59e0b;"></i>
                                        <div>
                                            <div class="fw-semibold">Bank Transfer</div>
                                            <div class="info-text">NEFT/RTGS/IMPS</div>
                                        </div>
                                    </div>
                                </label>
                                <label class="payment-option d-flex align-items-center gap-3 p-3 rounded-2 border">
                                    <input type="radio" name="payment_method" value="credit" class="form-check-input m-0" onchange="togglePaymentFields(this)">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-clock-history" style="font-size: 20px; color: #ef4444;"></i>
                                        <div>
                                            <div class="fw-semibold">Credit</div>
                                            <div class="info-text">Payment later</div>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Cash Received (for non-credit payments) -->
                        <div id="cashReceivedField" class="mb-3">
                            <label class="form-label fw-semibold">Cash Received</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="cash_received" class="form-control" step="0.01" min="0" value="0" id="cashReceived" oninput="calculateChange()">
                            </div>
                        </div>

                        <!-- Change to give -->
                        <div id="changeField" class="mb-3" style="display: none;">
                            <label class="form-label text-success">Change to Give</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="text" class="form-control text-success fw-bold" id="changeAmount" value="0.00" readonly>
                            </div>
                        </div>

                        <!-- Pending Amount -->
                        <div id="pendingField" class="mb-3" style="display: none;">
                            <label class="form-label text-danger">Pending Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="text" class="form-control text-danger fw-bold" id="pendingAmount" value="0.00" readonly>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary-custom w-100 py-3 mt-3" onclick="return validateForm()">
                            <i class="bi bi-check-circle me-2"></i> Create Invoice
                        </button>
                        
                        <button type="button" class="btn-outline-custom w-100 py-2 mt-2" onclick="window.close()">
                            <i class="bi bi-x-lg me-2"></i> Cancel
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Template for product row -->
    <template id="productRowTemplate">
        <div class="product-row" data-row-index="">
            <button type="button" class="remove-row" onclick="removeProductRow(this)">
                <i class="bi bi-x"></i>
            </button>
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Select Category <span class="text-danger">*</span></label>
                    <select name="category_id[]" class="form-select category-select" required onchange="categorySelected(this)">
                        <option value="">-- Select Category (Stock will be deducted) --</option>
                        <?php 
                        if ($categories && $categories->num_rows > 0) {
                            $categories->data_seek(0);
                            while ($category = $categories->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $category['id']; ?>" 
                                    data-stock="<?php echo $category['available_stock']; ?>"
                                    data-price="<?php echo $category['purchase_price']; ?>"
                                    data-name="<?php echo htmlspecialchars($category['category_name']); ?>">
                                <?php echo htmlspecialchars($category['category_name']); ?> 
                                (Stock: <?php echo number_format($category['available_stock'], 2); ?> kg)
                            </option>
                        <?php 
                            endwhile; 
                        } 
                        ?>
                    </select>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Select Product (Optional)</label>
                    <select name="product_id[]" class="form-select product-select">
                        <option value="">-- No Product (Use Category Name) --</option>
                        <?php 
                        if ($products && $products->num_rows > 0) {
                            $products->data_seek(0);
                            while ($product = $products->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $product['id']; ?>" 
                                    data-unit="<?php echo $product['primary_unit']; ?>">
                                <?php echo htmlspecialchars($product['product_name']); ?> 
                                (<?php echo $product['primary_unit']; ?>)
                            </option>
                        <?php 
                            endwhile; 
                        } 
                        ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Available Stock</label>
                    <input type="text" class="form-control stock-display" readonly placeholder="Select category">
                    <div class="stock-warning" id="stockWarning"></div>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Quantity (kg) <span class="text-danger">*</span></label>
                    <input type="number" name="quantity[]" class="form-control quantity" step="0.001" min="0.001" required oninput="calculateRowTotal(this)">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Selling Price (₹/kg) <span class="text-danger">*</span></label>
                    <input type="number" name="selling_price[]" class="form-control selling-price" step="0.01" min="0" required oninput="calculateRowTotal(this)">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Discount</label>
                    <div class="input-group">
                        <input type="number" name="discount[]" class="form-control discount" step="0.01" min="0" value="0" oninput="calculateRowTotal(this)">
                        <select name="discount_type[]" class="form-select discount-type" style="max-width: 70px;" onchange="calculateRowTotal(this)">
                            <option value="amount">₹</option>
                            <option value="percentage">%</option>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="gst-badge">
                            <i class="bi bi-patch-check"></i>
                            GST: 5% (CGST: 2.5%, SGST: 2.5%)
                        </span>
                        <span class="fw-bold">Row Total: <span class="row-total">₹0.00</span></span>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    let categoryData = {};
    let productData = {};
    let rowCount = 0;

    // Initialize category data from PHP
    <?php 
    if ($categories && $categories->num_rows > 0) {
        $categories->data_seek(0);
        while ($category = $categories->fetch_assoc()): 
    ?>
    categoryData[<?php echo $category['id']; ?>] = {
        id: <?php echo $category['id']; ?>,
        name: '<?php echo addslashes($category['category_name']); ?>',
        stock: <?php echo $category['available_stock']; ?>,
        price: <?php echo $category['purchase_price']; ?>
    };
    <?php 
        endwhile; 
    } 
    ?>

    // Initialize product data from PHP
    <?php 
    if ($products && $products->num_rows > 0) {
        $products->data_seek(0);
        while ($product = $products->fetch_assoc()): 
    ?>
    productData[<?php echo $product['id']; ?>] = {
        id: <?php echo $product['id']; ?>,
        name: '<?php echo addslashes($product['product_name']); ?>',
        unit: '<?php echo $product['primary_unit']; ?>'
    };
    <?php 
        endwhile; 
    } 
    ?>

    // Add new product row
    function addProductRow() {
        const template = document.getElementById('productRowTemplate');
        const clone = template.content.cloneNode(true);
        const row = clone.querySelector('.product-row');
        const index = rowCount++;
        
        row.setAttribute('data-row-index', index);
        
        document.getElementById('productRows').appendChild(clone);
        document.getElementById('noProducts').style.display = 'none';
        
        updateRowIndices();
    }

    // Remove product row
    function removeProductRow(button) {
        if (confirm('Remove this item from invoice?')) {
            const row = button.closest('.product-row');
            row.remove();
            
            if (document.querySelectorAll('.product-row').length === 0) {
                document.getElementById('noProducts').style.display = 'block';
            }
            
            updateRowIndices();
            calculateSummary();
        }
    }

    // Update row indices after removal
    function updateRowIndices() {
        const rows = document.querySelectorAll('.product-row');
        rows.forEach((row, index) => {
            row.setAttribute('data-row-index', index);
        });
    }

    // Category selected
    function categorySelected(select) {
        const row = select.closest('.product-row');
        const categoryId = select.value;
        const stockDisplay = row.querySelector('.stock-display');
        const quantityInput = row.querySelector('.quantity');
        const sellingPrice = row.querySelector('.selling-price');
        const stockWarning = row.querySelector('#stockWarning');
        
        if (categoryId && categoryData[categoryId]) {
            const category = categoryData[categoryId];
            
            // Update stock info
            stockDisplay.value = category.stock + ' kg';
            
            // Set default selling price
            sellingPrice.value = category.price.toFixed(2);
            
            // Set max quantity
            quantityInput.max = category.stock;
            
            // Clear warning
            stockWarning.innerHTML = '';
        } else {
            stockDisplay.value = 'Select category';
            sellingPrice.value = '';
            quantityInput.max = '';
            stockWarning.innerHTML = '';
        }
        
        calculateRowTotal(select);
    }

    // Calculate row total
    function calculateRowTotal(element) {
        const row = element.closest('.product-row');
        const categorySelect = row.querySelector('.category-select');
        const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
        const price = parseFloat(row.querySelector('.selling-price').value) || 0;
        const discount = parseFloat(row.querySelector('.discount').value) || 0;
        const discountType = row.querySelector('.discount-type').value;
        const rowTotalSpan = row.querySelector('.row-total');
        const stockWarning = row.querySelector('#stockWarning');
        
        // Validate stock
        if (categorySelect.value) {
            const category = categoryData[categorySelect.value];
            if (category && quantity > category.stock) {
                stockWarning.innerHTML = '⚠️ Quantity exceeds available stock! Max: ' + category.stock + ' kg';
                row.querySelector('.quantity').value = category.stock;
                calculateRowTotal(row.querySelector('.quantity'));
                return;
            } else {
                stockWarning.innerHTML = '';
            }
        }
        
        let subtotal = quantity * price;
        let discountAmount = 0;
        
        if (discount > 0) {
            if (discountType === 'percentage') {
                discountAmount = (subtotal * discount) / 100;
            } else {
                discountAmount = discount;
            }
        }
        
        // GST calculation (5% total)
        const taxable = subtotal - discountAmount;
        const gstAmount = (taxable * 5) / 100;
        
        const total = taxable + gstAmount;
        
        rowTotalSpan.textContent = '₹' + total.toFixed(2);
        
        calculateSummary();
    }

    // Calculate summary totals
    function calculateSummary() {
        let subtotal = 0;
        let totalDiscount = 0;
        let totalTax = 0;
        let grandTotal = 0;
        
        document.querySelectorAll('.product-row').forEach(row => {
            const categorySelect = row.querySelector('.category-select');
            if (!categorySelect.value) return;
            
            const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
            const price = parseFloat(row.querySelector('.selling-price').value) || 0;
            const discount = parseFloat(row.querySelector('.discount').value) || 0;
            const discountType = row.querySelector('.discount-type').value;
            
            const rowSubtotal = quantity * price;
            subtotal += rowSubtotal;
            
            let discountAmount = 0;
            if (discount > 0) {
                if (discountType === 'percentage') {
                    discountAmount = (rowSubtotal * discount) / 100;
                } else {
                    discountAmount = discount;
                }
            }
            totalDiscount += discountAmount;
            
            // GST calculation (5% total)
            const taxable = rowSubtotal - discountAmount;
            const gstAmount = (taxable * 5) / 100;
            totalTax += gstAmount;
        });
        
        // Apply overall discount
        const overallDiscount = parseFloat(document.getElementById('overallDiscount').value) || 0;
        const discountType = document.getElementById('discountType').value;
        
        let overallDiscountAmount = 0;
        if (overallDiscount > 0) {
            if (discountType === 'percentage') {
                overallDiscountAmount = (subtotal * overallDiscount) / 100;
            } else {
                overallDiscountAmount = overallDiscount;
            }
        }
        
        grandTotal = subtotal - overallDiscountAmount + totalTax;
        
        // Update summary display
        document.getElementById('summarySubtotal').textContent = '₹' + subtotal.toFixed(2);
        document.getElementById('summaryDiscount').textContent = '₹' + (totalDiscount + overallDiscountAmount).toFixed(2);
        document.getElementById('summaryTax').textContent = '₹' + totalTax.toFixed(2);
        document.getElementById('summaryTotal').textContent = '₹' + grandTotal.toFixed(2);
        
        calculateChange();
    }

    // Calculate change and pending
    function calculateChange() {
        const total = parseFloat(document.getElementById('summaryTotal').textContent.replace('₹', '')) || 0;
        const cashReceived = parseFloat(document.getElementById('cashReceived').value) || 0;
        const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value || 'cash';
        
        const changeField = document.getElementById('changeField');
        const pendingField = document.getElementById('pendingField');
        const changeAmount = document.getElementById('changeAmount');
        const pendingAmount = document.getElementById('pendingAmount');
        
        if (paymentMethod === 'credit') {
            changeField.style.display = 'none';
            pendingField.style.display = 'block';
            pendingAmount.value = total.toFixed(2);
        } else {
            if (cashReceived > total) {
                changeField.style.display = 'block';
                pendingField.style.display = 'none';
                changeAmount.value = (cashReceived - total).toFixed(2);
            } else if (cashReceived < total && cashReceived > 0) {
                changeField.style.display = 'none';
                pendingField.style.display = 'block';
                pendingAmount.value = (total - cashReceived).toFixed(2);
            } else {
                changeField.style.display = 'none';
                pendingField.style.display = 'none';
            }
        }
    }

    // Toggle payment fields
    function togglePaymentFields(element) {
        const paymentMethod = element.value;
        const cashReceivedField = document.getElementById('cashReceivedField');
        const cashReceived = document.getElementById('cashReceived');
        
        // Update selected style
        document.querySelectorAll('.payment-option').forEach(opt => {
            opt.classList.remove('selected');
        });
        element.closest('.payment-option').classList.add('selected');
        
        if (paymentMethod === 'credit') {
            cashReceivedField.style.display = 'none';
            cashReceived.value = 0;
        } else {
            cashReceivedField.style.display = 'block';
        }
        
        calculateChange();
    }

    // Customer selection
    function selectCustomer() {
        const select = document.getElementById('customerSelect');
        const selected = select.options[select.selectedIndex];
        const customerName = document.getElementById('customerName');
        const customerPhone = document.getElementById('customerPhone');
        const customerGST = document.getElementById('customerGST');
        
        if (select.value) {
            customerName.value = selected.dataset.name || '';
            customerName.readOnly = true;
            
            if (selected.dataset.phone) {
                document.getElementById('displayPhone').value = selected.dataset.phone;
                customerPhone.style.display = 'block';
            } else {
                customerPhone.style.display = 'none';
            }
            
            if (selected.dataset.gst) {
                document.getElementById('displayGST').value = selected.dataset.gst;
                customerGST.style.display = 'block';
            } else {
                customerGST.style.display = 'none';
            }
        } else {
            customerName.value = '';
            customerName.readOnly = false;
            customerPhone.style.display = 'none';
            customerGST.style.display = 'none';
        }
    }

    // Clear customer select
    function clearCustomerSelect() {
        document.getElementById('customerSelect').value = '';
        document.getElementById('customerPhone').style.display = 'none';
        document.getElementById('customerGST').style.display = 'none';
    }

    // Validate form before submission
    function validateForm() {
        const rows = document.querySelectorAll('.product-row');
        
        if (rows.length === 0) {
            alert('Please add at least one item to the invoice.');
            return false;
        }
        
        // Check each row has category selected and valid quantity
        for (let row of rows) {
            const category = row.querySelector('.category-select').value;
            const quantity = row.querySelector('.quantity').value;
            const price = row.querySelector('.selling-price').value;
            
            if (!category) {
                alert('Please select a category for all rows.');
                return false;
            }
            
            if (!quantity || quantity <= 0) {
                alert('Please enter valid quantity for all items.');
                return false;
            }
            
            if (!price || price <= 0) {
                alert('Please enter valid selling price for all items.');
                return false;
            }
        }
        
        // Check customer
        const customerSelect = document.getElementById('customerSelect').value;
        const customerName = document.getElementById('customerName').value;
        
        if (!customerSelect && !customerName) {
            alert('Please select or enter a customer name.');
            return false;
        }
        
        return confirm('Create this invoice?');
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Set default payment method selected style
        const defaultPayment = document.querySelector('input[name="payment_method"][value="cash"]');
        if (defaultPayment) {
            defaultPayment.closest('.payment-option').classList.add('selected');
        }
    });

    // Handle window close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (confirm('Close invoice creation?')) {
                window.close();
            }
        }
    });
    </script>
</body>
</html>