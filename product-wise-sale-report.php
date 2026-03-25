<?php
// product-wise-sale-report.php
session_start();
$currentPage = 'product-wise-sale-report';
$pageTitle = 'Product Wise Sale Report';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view this report
checkRoleAccess(['admin']);

$success = '';
$error = '';

// Helper function to build query string with current filters
function buildQueryString($exclude = []) {
    $params = $_GET;
    
    $allFilters = [
        'filter_date_from', 'filter_date_to', 'filter_category', 
        'filter_product', 'filter_customer', 'filter_month',
        'filter_payment_method', 'filter_group_by'
    ];
    
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    
    $filteredParams = [];
    foreach ($params as $key => $value) {
        if (in_array($key, $allFilters) && !empty($value)) {
            $filteredParams[$key] = $value;
        }
    }
    
    return count($filteredParams) ? '?' . http_build_query($filteredParams) : '';
}

// -------------------------
// Handle Export Functionality
// -------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['excel', 'csv', 'pdf'])) {
    $export_type = $_GET['export'];
    
    // Get filter values
    $filterDateFrom = $_GET['filter_date_from'] ?? date('Y-m-01');
    $filterDateTo = $_GET['filter_date_to'] ?? date('Y-m-t');
    $filterCategory = $_GET['filter_category'] ?? '';
    $filterProduct = $_GET['filter_product'] ?? '';
    $filterCustomer = $_GET['filter_customer'] ?? '';
    $filterMonth = $_GET['filter_month'] ?? '';
    $filterPaymentMethod = $_GET['filter_payment_method'] ?? '';
    $filterGroupBy = $_GET['filter_group_by'] ?? 'category'; // category, product, date

    $where = "1=1";
    $params = [];
    $types = "";

    // Date filters
    if (!empty($filterMonth) && $filterMonth !== 'all') {
        $year = substr($filterMonth, 0, 4);
        $month = substr($filterMonth, 5, 2);
        $where .= " AND YEAR(i.created_at) = ? AND MONTH(i.created_at) = ?";
        $params[] = $year;
        $params[] = $month;
        $types .= "ii";
    } else {
        if (!empty($filterDateFrom)) {
            $where .= " AND DATE(i.created_at) >= ?";
            $params[] = $filterDateFrom;
            $types .= "s";
        }
        if (!empty($filterDateTo)) {
            $where .= " AND DATE(i.created_at) <= ?";
            $params[] = $filterDateTo;
            $types .= "s";
        }
    }

    // Category filter
    if (!empty($filterCategory) && $filterCategory !== 'all') {
        $where .= " AND ii.cat_id = ?";
        $params[] = (int)$filterCategory;
        $types .= "i";
    }

    // Product filter
    if (!empty($filterProduct) && $filterProduct !== 'all') {
        $where .= " AND ii.product_id = ?";
        $params[] = (int)$filterProduct;
        $types .= "i";
    }

    // Customer filter
    if (!empty($filterCustomer) && $filterCustomer !== 'all') {
        $where .= " AND i.customer_id = ?";
        $params[] = (int)$filterCustomer;
        $types .= "i";
    }

    // Payment method filter
    if (!empty($filterPaymentMethod) && $filterPaymentMethod !== 'all') {
        $where .= " AND i.payment_method = ?";
        $params[] = $filterPaymentMethod;
        $types .= "s";
    }

    // Build the query based on group by selection
    switch($filterGroupBy) {
        case 'category':
            $groupBy = "ii.cat_id, ii.cat_name";
            $selectCols = "ii.cat_id, ii.cat_name as group_name, 'Category' as group_type";
            $orderBy = "ii.cat_name ASC";
            break;
        case 'product':
            $groupBy = "ii.product_id, ii.product_name, ii.cat_id, ii.cat_name";
            $selectCols = "ii.product_id, ii.product_name as group_name, ii.cat_id, ii.cat_name, 'Product' as group_type";
            $orderBy = "ii.product_name ASC";
            break;
        case 'date':
            $groupBy = "DATE(i.created_at)";
            $selectCols = "DATE(i.created_at) as sale_date, 'Date' as group_type, 
                          DATE_FORMAT(i.created_at, '%d-%m-%Y') as group_name";
            $orderBy = "sale_date DESC";
            break;
        default:
            $groupBy = "ii.cat_id, ii.cat_name";
            $selectCols = "ii.cat_id, ii.cat_name as group_name, 'Category' as group_type";
            $orderBy = "ii.cat_name ASC";
    }

    $sql = "
        SELECT 
            $selectCols,
            COUNT(DISTINCT i.id) as invoice_count,
            COUNT(DISTINCT i.customer_id) as customer_count,
            SUM(COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) as total_quantity,
            SUM(ii.selling_price * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) as total_sales,
            SUM(ii.purchase_price * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) as total_cost,
            SUM((ii.selling_price - ii.purchase_price) * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) as total_profit,
            AVG(ii.selling_price) as avg_selling_price,
            AVG(ii.purchase_price) as avg_purchase_price,
            SUM(ii.cgst_amount + ii.sgst_amount) as total_gst
        FROM invoice_item ii
        INNER JOIN invoice i ON ii.invoice_id = i.id
        WHERE $where
        GROUP BY $groupBy
        ORDER BY $orderBy
    ";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    // Prepare data array
    $data = [];
    $grand_total = [
        'total_quantity' => 0,
        'total_sales' => 0,
        'total_cost' => 0,
        'total_profit' => 0,
        'total_gst' => 0,
        'invoice_count' => 0,
        'customer_count' => 0
    ];

    while ($row = $result->fetch_assoc()) {
        $profit_margin = $row['total_sales'] > 0 ? ($row['total_profit'] / $row['total_sales'] * 100) : 0;
        
        $data[] = [
            'Group Type' => $row['group_type'],
            'Group Name' => $row['group_name'],
            'Category' => $row['cat_name'] ?? '-',
            'Invoices' => $row['invoice_count'],
            'Customers' => $row['customer_count'],
            'Quantity' => $row['total_quantity'],
            'Sales Amount' => $row['total_sales'],
            'Cost Amount' => $row['total_cost'],
            'Profit' => $row['total_profit'],
            'Profit Margin %' => round($profit_margin, 2),
            'GST Amount' => $row['total_gst'],
            'Avg Selling Price' => $row['avg_selling_price'],
            'Avg Purchase Price' => $row['avg_purchase_price']
        ];

        $grand_total['total_quantity'] += $row['total_quantity'];
        $grand_total['total_sales'] += $row['total_sales'];
        $grand_total['total_cost'] += $row['total_cost'];
        $grand_total['total_profit'] += $row['total_profit'];
        $grand_total['total_gst'] += $row['total_gst'];
        $grand_total['invoice_count'] += $row['invoice_count'];
        $grand_total['customer_count'] += $row['customer_count'];
    }

    // Handle export based on type
    $filename = "product_wise_sales_" . date('Y-m-d');
    
    switch($export_type) {
        case 'csv':
        case 'excel':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
            
            // Header
            fputcsv($output, ['PRODUCT WISE SALE REPORT']);
            fputcsv($output, ['Generated on: ' . date('d-m-Y H:i:s')]);
            
            // Filters
            fputcsv($output, ['FILTERS APPLIED:']);
            $filter_info = [];
            if (!empty($filterDateFrom) && !empty($filterDateTo)) {
                $filter_info[] = "Date Range: $filterDateFrom to $filterDateTo";
            }
            if (!empty($filterMonth) && $filterMonth != 'all') {
                $filter_info[] = "Month: " . date('F Y', strtotime($filterMonth . '-01'));
            }
            if (!empty($filterCategory) && $filterCategory != 'all') {
                $cat_name = $conn->query("SELECT category_name FROM category WHERE id = " . (int)$filterCategory)->fetch_assoc()['category_name'];
                $filter_info[] = "Category: $cat_name";
            }
            if (!empty($filterProduct) && $filterProduct != 'all') {
                $prod_name = $conn->query("SELECT product_name FROM product WHERE id = " . (int)$filterProduct)->fetch_assoc()['product_name'];
                $filter_info[] = "Product: $prod_name";
            }
            if (!empty($filterCustomer) && $filterCustomer != 'all') {
                $cust_name = $conn->query("SELECT customer_name FROM customers WHERE id = " . (int)$filterCustomer)->fetch_assoc()['customer_name'];
                $filter_info[] = "Customer: $cust_name";
            }
            if (!empty($filterPaymentMethod) && $filterPaymentMethod != 'all') {
                $filter_info[] = "Payment Method: " . ucfirst($filterPaymentMethod);
            }
            $filter_info[] = "Grouped By: " . ucfirst($filterGroupBy);
            
            if (empty(array_filter($filter_info))) {
                fputcsv($output, ['No filters applied - All records']);
            } else {
                foreach ($filter_info as $info) {
                    fputcsv($output, [$info]);
                }
            }
            
            fputcsv($output, []);
            
            // Data headers
            if (!empty($data)) {
                fputcsv($output, array_keys($data[0]));
                
                // Data rows
                foreach ($data as $row) {
                    $formatted_row = $row;
                    $formatted_row['Sales Amount'] = '₹' . number_format($row['Sales Amount'], 2);
                    $formatted_row['Cost Amount'] = '₹' . number_format($row['Cost Amount'], 2);
                    $formatted_row['Profit'] = '₹' . number_format($row['Profit'], 2);
                    $formatted_row['GST Amount'] = '₹' . number_format($row['GST Amount'], 2);
                    $formatted_row['Avg Selling Price'] = '₹' . number_format($row['Avg Selling Price'], 2);
                    $formatted_row['Avg Purchase Price'] = '₹' . number_format($row['Avg Purchase Price'], 2);
                    fputcsv($output, $formatted_row);
                }
                
                fputcsv($output, []);
                
                // Grand Total
                $grand_profit_margin = $grand_total['total_sales'] > 0 ? 
                    ($grand_total['total_profit'] / $grand_total['total_sales'] * 100) : 0;
                
                fputcsv($output, ['GRAND TOTAL']);
                fputcsv($output, ['Total Quantity:', number_format($grand_total['total_quantity'], 2)]);
                fputcsv($output, ['Total Sales:', '₹' . number_format($grand_total['total_sales'], 2)]);
                fputcsv($output, ['Total Cost:', '₹' . number_format($grand_total['total_cost'], 2)]);
                fputcsv($output, ['Total Profit:', '₹' . number_format($grand_total['total_profit'], 2)]);
                fputcsv($output, ['Overall Profit Margin:', number_format($grand_profit_margin, 2) . '%']);
                fputcsv($output, ['Total GST:', '₹' . number_format($grand_total['total_gst'], 2)]);
                fputcsv($output, ['Total Invoices:', $grand_total['invoice_count']]);
                fputcsv($output, ['Unique Customers:', $grand_total['customer_count']]);
            }
            
            fclose($output);
            exit;
            
        case 'pdf':
            ob_start();
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Product Wise Sale Report</title>
                <style>
                    body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
                    h1 { color: #2463eb; text-align: center; margin-bottom: 5px; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .filters { background: #f8fafc; padding: 15px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #2463eb; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 10px; }
                    th { background: #2463eb; color: white; padding: 8px; text-align: left; }
                    td { border: 1px solid #ddd; padding: 6px; }
                    tr:nth-child(even) { background: #f8fafc; }
                    .summary { background: #e8f2ff; padding: 15px; border-radius: 8px; margin: 20px 0; }
                    .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 15px; }
                    .summary-item { background: white; padding: 10px; border-radius: 6px; text-align: center; }
                    .summary-label { font-size: 10px; color: #64748b; text-transform: uppercase; }
                    .summary-value { font-size: 16px; font-weight: bold; color: #1e293b; }
                    .text-right { text-align: right; }
                    .text-success { color: #059669; font-weight: bold; }
                    .text-danger { color: #dc2626; font-weight: bold; }
                    .footer { text-align: center; margin-top: 30px; font-size: 9px; color: #64748b; }
                    .badge { padding: 2px 6px; border-radius: 4px; font-size: 9px; }
                    .badge-category { background: #e0f2fe; color: #0369a1; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>PRODUCT WISE SALE REPORT</h1>
                    <p>Generated on: <?php echo date('d-m-Y H:i:s'); ?></p>
                </div>
                
                <div class="filters">
                    <strong>📊 Applied Filters:</strong>
                    <ul style="margin: 8px 0 0; padding-left: 20px;">
                        <?php if (!empty($filterDateFrom) && !empty($filterDateTo)): ?>
                            <li>Date Range: <?php echo $filterDateFrom; ?> to <?php echo $filterDateTo; ?></li>
                        <?php endif; ?>
                        <?php if (!empty($filterMonth) && $filterMonth != 'all'): ?>
                            <li>Month: <?php echo date('F Y', strtotime($filterMonth . '-01')); ?></li>
                        <?php endif; ?>
                        <?php if (!empty($filterCategory) && $filterCategory != 'all'): ?>
                            <?php $cat_name = $conn->query("SELECT category_name FROM category WHERE id = " . (int)$filterCategory)->fetch_assoc()['category_name']; ?>
                            <li>Category: <?php echo $cat_name; ?></li>
                        <?php endif; ?>
                        <?php if (!empty($filterProduct) && $filterProduct != 'all'): ?>
                            <?php $prod_name = $conn->query("SELECT product_name FROM product WHERE id = " . (int)$filterProduct)->fetch_assoc()['product_name']; ?>
                            <li>Product: <?php echo $prod_name; ?></li>
                        <?php endif; ?>
                        <?php if (!empty($filterCustomer) && $filterCustomer != 'all'): ?>
                            <?php $cust_name = $conn->query("SELECT customer_name FROM customers WHERE id = " . (int)$filterCustomer)->fetch_assoc()['customer_name']; ?>
                            <li>Customer: <?php echo $cust_name; ?></li>
                        <?php endif; ?>
                        <?php if (!empty($filterPaymentMethod) && $filterPaymentMethod != 'all'): ?>
                            <li>Payment Method: <?php echo ucfirst($filterPaymentMethod); ?></li>
                        <?php endif; ?>
                        <li>Grouped By: <?php echo ucfirst($filterGroupBy); ?></li>
                    </ul>
                </div>
                
                <!-- Summary Cards -->
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-label">Total Quantity</div>
                        <div class="summary-value"><?php echo number_format($grand_total['total_quantity'], 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Total Sales</div>
                        <div class="summary-value">₹<?php echo number_format($grand_total['total_sales'], 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Total Profit</div>
                        <div class="summary-value text-success">₹<?php echo number_format($grand_total['total_profit'], 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Profit Margin</div>
                        <div class="summary-value"><?php echo $grand_total['total_sales'] > 0 ? number_format(($grand_total['total_profit'] / $grand_total['total_sales'] * 100), 2) : 0; ?>%</div>
                    </div>
                </div>
                
                <!-- Data Table -->
                <table>
                    <thead>
                        <tr>
                            <th>Group</th>
                            <th>Category</th>
                            <th>Invoices</th>
                            <th>Customers</th>
                            <th>Qty</th>
                            <th>Sales (₹)</th>
                            <th>Cost (₹)</th>
                            <th>Profit (₹)</th>
                            <th>Margin %</th>
                            <th>GST (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $row): 
                            $margin_class = $row['Profit Margin %'] >= 20 ? 'text-success' : ($row['Profit Margin %'] >= 10 ? '' : 'text-danger');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['Group Name']); ?></td>
                            <td><?php echo htmlspecialchars($row['Category']); ?></td>
                            <td class="text-right"><?php echo $row['Invoices']; ?></td>
                            <td class="text-right"><?php echo $row['Customers']; ?></td>
                            <td class="text-right"><?php echo number_format($row['Quantity'], 2); ?></td>
                            <td class="text-right">₹<?php echo number_format($row['Sales Amount'], 2); ?></td>
                            <td class="text-right">₹<?php echo number_format($row['Cost Amount'], 2); ?></td>
                            <td class="text-right <?php echo $row['Profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                ₹<?php echo number_format($row['Profit'], 2); ?>
                            </td>
                            <td class="text-right <?php echo $margin_class; ?>">
                                <?php echo number_format($row['Profit Margin %'], 2); ?>%
                            </td>
                            <td class="text-right">₹<?php echo number_format($row['GST Amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #f1f5f9; font-weight: bold;">
                            <td colspan="4">GRAND TOTAL</td>
                            <td class="text-right"><?php echo number_format($grand_total['total_quantity'], 2); ?></td>
                            <td class="text-right">₹<?php echo number_format($grand_total['total_sales'], 2); ?></td>
                            <td class="text-right">₹<?php echo number_format($grand_total['total_cost'], 2); ?></td>
                            <td class="text-right text-success">₹<?php echo number_format($grand_total['total_profit'], 2); ?></td>
                            <td class="text-right"><?php echo $grand_total['total_sales'] > 0 ? number_format(($grand_total['total_profit'] / $grand_total['total_sales'] * 100), 2) : 0; ?>%</td>
                            <td class="text-right">₹<?php echo number_format($grand_total['total_gst'], 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
                
                <div class="footer">
                    <p>This is a computer-generated report. Valid without signature.</p>
                    <p>Generated by Sri Plaast ERP System</p>
                </div>
            </body>
            </html>
            <?php
            $html = ob_get_clean();
            
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="' . $filename . '.html"');
            echo $html;
            exit;
    }
}

// -------------------------
// Filters
// -------------------------
$filterDateFrom = $_GET['filter_date_from'] ?? date('Y-m-01');
$filterDateTo = $_GET['filter_date_to'] ?? date('Y-m-t');
$filterCategory = $_GET['filter_category'] ?? '';
$filterProduct = $_GET['filter_product'] ?? '';
$filterCustomer = $_GET['filter_customer'] ?? '';
$filterMonth = $_GET['filter_month'] ?? '';
$filterPaymentMethod = $_GET['filter_payment_method'] ?? '';
$filterGroupBy = $_GET['filter_group_by'] ?? 'category'; // category, product, date

$where = "1=1";
$params = [];
$types = "";

// Date filters
if (!empty($filterMonth) && $filterMonth !== 'all') {
    $year = substr($filterMonth, 0, 4);
    $month = substr($filterMonth, 5, 2);
    $where .= " AND YEAR(i.created_at) = ? AND MONTH(i.created_at) = ?";
    $params[] = $year;
    $params[] = $month;
    $types .= "ii";
} else {
    if (!empty($filterDateFrom)) {
        $where .= " AND DATE(i.created_at) >= ?";
        $params[] = $filterDateFrom;
        $types .= "s";
    }
    if (!empty($filterDateTo)) {
        $where .= " AND DATE(i.created_at) <= ?";
        $params[] = $filterDateTo;
        $types .= "s";
    }
}

// Category filter
if (!empty($filterCategory) && $filterCategory !== 'all') {
    $where .= " AND ii.cat_id = ?";
    $params[] = (int)$filterCategory;
    $types .= "i";
}

// Product filter
if (!empty($filterProduct) && $filterProduct !== 'all') {
    $where .= " AND ii.product_id = ?";
    $params[] = (int)$filterProduct;
    $types .= "i";
}

// Customer filter
if (!empty($filterCustomer) && $filterCustomer !== 'all') {
    $where .= " AND i.customer_id = ?";
    $params[] = (int)$filterCustomer;
    $types .= "i";
}

// Payment method filter
if (!empty($filterPaymentMethod) && $filterPaymentMethod !== 'all') {
    $where .= " AND i.payment_method = ?";
    $params[] = $filterPaymentMethod;
    $types .= "s";
}

// Build the query based on group by selection
switch($filterGroupBy) {
    case 'category':
        $groupBy = "ii.cat_id, ii.cat_name";
        $selectCols = "ii.cat_id, ii.cat_name, NULL as product_id, NULL as product_name, NULL as sale_date";
        $orderBy = "ii.cat_name ASC";
        break;
    case 'product':
        $groupBy = "ii.product_id, ii.product_name, ii.cat_id, ii.cat_name";
        $selectCols = "ii.cat_id, ii.cat_name, ii.product_id, ii.product_name, NULL as sale_date";
        $orderBy = "ii.product_name ASC";
        break;
    case 'date':
        $groupBy = "DATE(i.created_at)";
        $selectCols = "NULL as cat_id, NULL as cat_name, NULL as product_id, NULL as product_name, 
                      DATE(i.created_at) as sale_date, DATE_FORMAT(i.created_at, '%d-%m-%Y') as sale_date_formatted";
        $orderBy = "sale_date DESC";
        break;
    default:
        $groupBy = "ii.cat_id, ii.cat_name";
        $selectCols = "ii.cat_id, ii.cat_name, NULL as product_id, NULL as product_name, NULL as sale_date";
        $orderBy = "ii.cat_name ASC";
}

$sql = "
    SELECT 
        $selectCols,
        COUNT(DISTINCT i.id) as invoice_count,
        COUNT(DISTINCT i.customer_id) as customer_count,
        SUM(COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) as total_quantity,
        SUM(ii.selling_price * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) as total_sales,
        SUM(ii.purchase_price * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) as total_cost,
        SUM((ii.selling_price - ii.purchase_price) * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) as total_profit,
        AVG(ii.selling_price) as avg_selling_price,
        AVG(ii.purchase_price) as avg_purchase_price,
        SUM(ii.cgst_amount + ii.sgst_amount) as total_gst
    FROM invoice_item ii
    INNER JOIN invoice i ON ii.invoice_id = i.id
    WHERE $where
    GROUP BY $groupBy
    ORDER BY $orderBy
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

// Get categories for filter
$categories = $conn->query("SELECT id, category_name FROM category ORDER BY category_name ASC");

// Get products for filter
$products = $conn->query("SELECT id, product_name FROM product ORDER BY product_name ASC");

// Get customers for filter
$customers = $conn->query("SELECT id, customer_name FROM customers ORDER BY customer_name ASC");

// Get available months for filter
$months_query = $conn->query("
    SELECT DISTINCT 
        DATE_FORMAT(created_at, '%Y-%m') as month_value,
        DATE_FORMAT(created_at, '%M %Y') as month_name
    FROM invoice 
    ORDER BY created_at DESC
");
$available_months = $months_query->fetch_all(MYSQLI_ASSOC);

// Calculate grand totals
$grand_total = [
    'total_quantity' => 0,
    'total_sales' => 0,
    'total_cost' => 0,
    'total_profit' => 0,
    'total_gst' => 0,
    'invoice_count' => 0,
    'customer_count' => 0
];

if ($result) {
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        $grand_total['total_quantity'] += $row['total_quantity'];
        $grand_total['total_sales'] += $row['total_sales'];
        $grand_total['total_cost'] += $row['total_cost'];
        $grand_total['total_profit'] += $row['total_profit'];
        $grand_total['total_gst'] += $row['total_gst'];
        $grand_total['invoice_count'] += $row['invoice_count'];
        $grand_total['customer_count'] += $row['customer_count'];
    }
    $result->data_seek(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .report-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #eef2f6;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .report-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #eef2f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-box {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 20px;
        }
        
        .export-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .export-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            border-radius: 8px;
            z-index: 1000;
            border: 1px solid #eef2f6;
        }
        
        .export-dropdown:hover .export-dropdown-content {
            display: block;
        }
        
        .export-dropdown-content a {
            color: #1e293b;
            padding: 12px 16px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            transition: background 0.2s;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .export-dropdown-content a:hover {
            background: #f8fafc;
        }
        
        .export-btn {
            background: #10b981;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .export-btn:hover {
            background: #059669;
        }
        
        .group-badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .profit-positive {
            color: #059669;
            font-weight: 600;
        }
        
        .profit-negative {
            color: #dc2626;
            font-weight: 600;
        }
        
        .table-custom td {
            vertical-align: middle;
        }
        
        .text-right {
            text-align: right;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .mobile-card {
                background: white;
                border: 1px solid #eef2f6;
                border-radius: 12px;
                padding: 16px;
                margin-bottom: 12px;
            }
            
            .mobile-card-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                padding-bottom: 8px;
                border-bottom: 1px dashed #eef2f6;
            }
            
            .mobile-card-row:last-child {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }
            
            .mobile-card-label {
                font-size: 12px;
                color: #64748b;
            }
            
            .mobile-card-value {
                font-size: 13px;
                font-weight: 500;
                color: #1e293b;
            }
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Product Wise Sale Report</h4>
                    <p style="font-size: 14px; color: var(--text-muted; margin: 0;">Analyze sales performance by product, category, and date</p>
                </div>
                <div class="d-flex gap-2">
                    <!-- Export Dropdown -->
                    <div class="export-dropdown">
                        <button class="export-btn">
                            <i class="bi bi-download"></i> Export Report
                            <i class="bi bi-chevron-down" style="font-size: 12px;"></i>
                        </button>
                        <div class="export-dropdown-content">
                            <a href="?export=csv<?php echo buildQueryString(['export']); ?>">
                                <i class="bi bi-file-earmark-spreadsheet" style="color: #059669;"></i> Export as CSV
                            </a>
                            <a href="?export=excel<?php echo buildQueryString(['export']); ?>">
                                <i class="bi bi-file-excel" style="color: #16a34a;"></i> Export as Excel
                            </a>
                            <a href="?export=pdf<?php echo buildQueryString(['export']); ?>">
                                <i class="bi bi-file-pdf" style="color: #dc2626;"></i> Export as PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Stats Cards -->
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo number_format($grand_total['total_quantity'], 2); ?></div>
                            <div class="stat-label">Total Quantity Sold</div>
                        </div>
                        <div class="stat-icon blue" style="width: 48px; height: 48px;">
                            <i class="bi bi-box-seam"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($grand_total['total_sales'], 2); ?></div>
                            <div class="stat-label">Total Sales</div>
                        </div>
                        <div class="stat-icon green" style="width: 48px; height: 48px;">
                            <i class="bi bi-currency-rupee"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-success">₹<?php echo number_format($grand_total['total_profit'], 2); ?></div>
                            <div class="stat-label">Total Profit</div>
                        </div>
                        <div class="stat-icon purple" style="width: 48px; height: 48px;">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                    </div>
                    <?php 
                    $margin = $grand_total['total_sales'] > 0 ? 
                        ($grand_total['total_profit'] / $grand_total['total_sales'] * 100) : 0;
                    ?>
                    <div class="mt-2 text-muted" style="font-size: 12px;">
                        Margin: <?php echo number_format($margin, 2); ?>%
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo $grand_total['invoice_count']; ?></div>
                            <div class="stat-label">Total Invoices</div>
                        </div>
                        <div class="stat-icon orange" style="width: 48px; height: 48px;">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-muted" style="font-size: 12px;">
                        <?php echo $grand_total['customer_count']; ?> unique customers
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="product-wise-sale-report.php" class="row g-3">
                    <!-- Month Filter -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-calendar-month"></i> Select Month
                        </label>
                        <select name="filter_month" class="form-select">
                            <option value="">Custom Range</option>
                            <?php foreach ($available_months as $month): ?>
                                <option value="<?php echo $month['month_value']; ?>" 
                                    <?php echo ($filterMonth == $month['month_value']) ? 'selected' : ''; ?>>
                                    <?php echo $month['month_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="filter_date_from" class="form-control" 
                               value="<?php echo htmlspecialchars($filterDateFrom); ?>"
                               <?php echo (!empty($filterMonth)) ? 'disabled' : ''; ?>>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="filter_date_to" class="form-control" 
                               value="<?php echo htmlspecialchars($filterDateTo); ?>"
                               <?php echo (!empty($filterMonth)) ? 'disabled' : ''; ?>>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Group By</label>
                        <select name="filter_group_by" class="form-select">
                            <option value="category" <?php echo $filterGroupBy == 'category' ? 'selected' : ''; ?>>Category</option>
                            <option value="product" <?php echo $filterGroupBy == 'product' ? 'selected' : ''; ?>>Product</option>
                            <option value="date" <?php echo $filterGroupBy == 'date' ? 'selected' : ''; ?>>Date</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <select name="filter_category" class="form-select">
                            <option value="all">All Categories</option>
                            <?php
                            if ($categories && $categories->num_rows > 0) {
                                while ($cat = $categories->fetch_assoc()):
                            ?>
                                <option value="<?php echo (int)$cat['id']; ?>" <?php echo ((string)$filterCategory === (string)$cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php
                                endwhile;
                            }
                            ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Product</label>
                        <select name="filter_product" class="form-select">
                            <option value="all">All Products</option>
                            <?php
                            $products->data_seek(0);
                            if ($products && $products->num_rows > 0) {
                                while ($prod = $products->fetch_assoc()):
                            ?>
                                <option value="<?php echo (int)$prod['id']; ?>" <?php echo ((string)$filterProduct === (string)$prod['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prod['product_name']); ?>
                                </option>
                            <?php
                                endwhile;
                            }
                            ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="filter_customer" class="form-select">
                            <option value="all">All Customers</option>
                            <?php
                            if ($customers && $customers->num_rows > 0) {
                                while ($cust = $customers->fetch_assoc()):
                            ?>
                                <option value="<?php echo (int)$cust['id']; ?>" <?php echo ((string)$filterCustomer === (string)$cust['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cust['customer_name']); ?>
                                </option>
                            <?php
                                endwhile;
                            }
                            ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Payment Method</label>
                        <select name="filter_payment_method" class="form-select">
                            <option value="all">All</option>
                            <option value="cash" <?php echo $filterPaymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="card" <?php echo $filterPaymentMethod === 'card' ? 'selected' : ''; ?>>Card</option>
                            <option value="upi"  <?php echo $filterPaymentMethod === 'upi'  ? 'selected' : ''; ?>>UPI</option>
                            <option value="bank" <?php echo $filterPaymentMethod === 'bank' ? 'selected' : ''; ?>>Bank</option>
                            <option value="credit" <?php echo $filterPaymentMethod === 'credit' ? 'selected' : ''; ?>>Credit</option>
                        </select>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-funnel"></i> Generate Report
                        </button>
                        <a href="product-wise-sale-report.php" class="btn-outline-custom">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Report Table -->
            <div class="report-card">
                <div class="report-header">
                    <h5 class="mb-0">
                        <i class="bi bi-table me-2"></i>
                        <?php 
                        $group_label = '';
                        switch($filterGroupBy) {
                            case 'category': $group_label = 'Category Wise'; break;
                            case 'product': $group_label = 'Product Wise'; break;
                            case 'date': $group_label = 'Date Wise'; break;
                            default: $group_label = 'Category Wise';
                        }
                        echo $group_label . ' Sales Report';
                        ?>
                    </h5>
                    <span class="group-badge">
                        <?php echo $result ? $result->num_rows : 0; ?> records found
                    </span>
                </div>

                <!-- Desktop Table View -->
                <div class="desktop-table" style="overflow-x: auto; padding: 20px;">
                    <table class="table-custom" id="salesTable">
                        <thead>
                            <tr>
                                <?php if ($filterGroupBy == 'category'): ?>
                                    <th>Category</th>
                                <?php elseif ($filterGroupBy == 'product'): ?>
                                    <th>Product</th>
                                    <th>Category</th>
                                <?php else: ?>
                                    <th>Date</th>
                                <?php endif; ?>
                                <th class="text-right">Invoices</th>
                                <th class="text-right">Customers</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Sales (₹)</th>
                                <th class="text-right">Cost (₹)</th>
                                <th class="text-right">Profit (₹)</th>
                                <th class="text-right">Margin %</th>
                                <th class="text-right">GST (₹)</th>
                                <th class="text-right">Avg Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): 
                                    $profit_margin = $row['total_sales'] > 0 ? 
                                        ($row['total_profit'] / $row['total_sales'] * 100) : 0;
                                ?>
                                    <tr>
                                        <?php if ($filterGroupBy == 'category'): ?>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['cat_name'] ?: 'Uncategorized'); ?></strong>
                                            </td>
                                        <?php elseif ($filterGroupBy == 'product'): ?>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['product_name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['cat_name'] ?: '-'); ?></td>
                                        <?php else: ?>
                                            <td>
                                                <strong><?php echo $row['sale_date_formatted']; ?></strong>
                                            </td>
                                        <?php endif; ?>
                                        
                                        <td class="text-right"><?php echo (int)$row['invoice_count']; ?></td>
                                        <td class="text-right"><?php echo (int)$row['customer_count']; ?></td>
                                        <td class="text-right"><?php echo number_format($row['total_quantity'], 2); ?></td>
                                        <td class="text-right fw-semibold">₹<?php echo number_format($row['total_sales'], 2); ?></td>
                                        <td class="text-right">₹<?php echo number_format($row['total_cost'], 2); ?></td>
                                        <td class="text-right <?php echo $row['total_profit'] >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                            ₹<?php echo number_format($row['total_profit'], 2); ?>
                                        </td>
                                        <td class="text-right <?php echo $profit_margin >= 20 ? 'profit-positive' : ($profit_margin >= 10 ? '' : 'profit-negative'); ?>">
                                            <?php echo number_format($profit_margin, 2); ?>%
                                        </td>
                                        <td class="text-right">₹<?php echo number_format($row['total_gst'], 2); ?></td>
                                        <td class="text-right">
                                            <small>₹<?php echo number_format($row['avg_selling_price'], 2); ?></small>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                
                                <!-- Grand Total Row -->
                                <tr style="background: #f1f5f9; font-weight: bold;">
                                    <td colspan="<?php echo ($filterGroupBy == 'product') ? '3' : '2'; ?>">
                                        GRAND TOTAL
                                    </td>
                                    <td class="text-right"><?php echo $grand_total['invoice_count']; ?></td>
                                    <td class="text-right"><?php echo $grand_total['customer_count']; ?></td>
                                    <td class="text-right"><?php echo number_format($grand_total['total_quantity'], 2); ?></td>
                                    <td class="text-right">₹<?php echo number_format($grand_total['total_sales'], 2); ?></td>
                                    <td class="text-right">₹<?php echo number_format($grand_total['total_cost'], 2); ?></td>
                                    <td class="text-right profit-positive">₹<?php echo number_format($grand_total['total_profit'], 2); ?></td>
                                    <td class="text-right">
                                        <?php 
                                        $overall_margin = $grand_total['total_sales'] > 0 ? 
                                            ($grand_total['total_profit'] / $grand_total['total_sales'] * 100) : 0;
                                        echo number_format($overall_margin, 2); ?>%
                                    </td>
                                    <td class="text-right">₹<?php echo number_format($grand_total['total_gst'], 2); ?></td>
                                    <td class="text-right">-</td>
                                </tr>
                            <?php else: ?>
                                <tr><td colspan="<?php echo ($filterGroupBy == 'product') ? '11' : '10'; ?>" class="text-center text-muted py-4">
                                    No data found for the selected filters.
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php
                        $result->data_seek(0);
                        while ($row = $result->fetch_assoc()):
                            $profit_margin = $row['total_sales'] > 0 ? 
                                ($row['total_profit'] / $row['total_sales'] * 100) : 0;
                        ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header d-flex justify-content-between align-items-center mb-2">
                                    <?php if ($filterGroupBy == 'category'): ?>
                                        <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($row['cat_name'] ?: 'Uncategorized'); ?></h6>
                                    <?php elseif ($filterGroupBy == 'product'): ?>
                                        <div>
                                            <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($row['product_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($row['cat_name'] ?: '-'); ?></small>
                                        </div>
                                    <?php else: ?>
                                        <h6 class="fw-bold mb-0"><?php echo $row['sale_date_formatted']; ?></h6>
                                    <?php endif; ?>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Invoices / Customers</span>
                                    <span class="mobile-card-value">
                                        <?php echo $row['invoice_count']; ?> / <?php echo $row['customer_count']; ?>
                                    </span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Quantity</span>
                                    <span class="mobile-card-value"><?php echo number_format($row['total_quantity'], 2); ?></span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Sales</span>
                                    <span class="mobile-card-value fw-semibold">₹<?php echo number_format($row['total_sales'], 2); ?></span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Cost</span>
                                    <span class="mobile-card-value">₹<?php echo number_format($row['total_cost'], 2); ?></span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Profit</span>
                                    <span class="mobile-card-value <?php echo $row['total_profit'] >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                        ₹<?php echo number_format($row['total_profit'], 2); ?>
                                        (<?php echo number_format($profit_margin, 1); ?>%)
                                    </span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">GST</span>
                                    <span class="mobile-card-value">₹<?php echo number_format($row['total_gst'], 2); ?></span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Avg Price</span>
                                    <span class="mobile-card-value">₹<?php echo number_format($row['avg_selling_price'], 2); ?></span>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        
                        <!-- Mobile Grand Total -->
                        <div class="mobile-card" style="background: #f1f5f9;">
                            <h6 class="fw-bold mb-3">GRAND TOTAL</h6>
                            <div class="mobile-card-row">
                                <span class="mobile-card-label">Quantity</span>
                                <span class="mobile-card-value"><?php echo number_format($grand_total['total_quantity'], 2); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span class="mobile-card-label">Sales</span>
                                <span class="mobile-card-value fw-bold">₹<?php echo number_format($grand_total['total_sales'], 2); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span class="mobile-card-label">Profit</span>
                                <span class="mobile-card-value profit-positive">₹<?php echo number_format($grand_total['total_profit'], 2); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span class="mobile-card-label">Margin</span>
                                <span class="mobile-card-value">
                                    <?php 
                                    $overall_margin = $grand_total['total_sales'] > 0 ? 
                                        ($grand_total['total_profit'] / $grand_total['total_sales'] * 100) : 0;
                                    echo number_format($overall_margin, 2); ?>%
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-bar-chart d-block mb-2" style="font-size: 48px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No data found</div>
                            <div style="font-size: 13px;">
                                Try changing your filters or select a different date range
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    $('#salesTable').DataTable({
        pageLength: 25,
        order: [],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ records",
            info: "Showing _START_ to _END_ of _TOTAL_ records",
            emptyTable: "No data available"
        },
        columnDefs: [
            { orderable: false, targets: [] }
        ],
        dom: '<"top"lf>rt<"bottom"ip><"clear">'
    });

    // Handle month filter disabling date range
    $('select[name="filter_month"]').change(function() {
        const monthVal = $(this).val();
        if (monthVal) {
            $('input[name="filter_date_from"]').prop('disabled', true).val('');
            $('input[name="filter_date_to"]').prop('disabled', true).val('');
        } else {
            $('input[name="filter_date_from"]').prop('disabled', false).val('<?php echo date('Y-m-01'); ?>');
            $('input[name="filter_date_to"]').prop('disabled', false).val('<?php echo date('Y-m-t'); ?>');
        }
    });

    // Initial check
    if ($('select[name="filter_month"]').val()) {
        $('input[name="filter_date_from"]').prop('disabled', true);
        $('input[name="filter_date_to"]').prop('disabled', true);
    }
});
</script>
</body>
</html>