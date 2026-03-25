<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can export reports
checkRoleAccess(['admin', 'sale']);

// Helper function to format currency
function formatCurrency($amount) {
    return '₹' . number_format((float)$amount, 2);
}

function formatNumber($number, $decimals = 2) {
    return number_format((float)$number, $decimals);
}

// Get filter parameters
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterCustomer = $_GET['customer_id'] ?? '';
$filterGstType = $_GET['gst_type'] ?? '';
$exportType = $_GET['type'] ?? 'summary'; // summary, categories, products, customers, items

// Build where clause for date range - only if dates are provided
$dateWhere = "1=1";
$params = [];
$types = "";

if (!empty($filterDateFrom) && !empty($filterDateTo)) {
    $dateWhere = "DATE(i.created_at) BETWEEN ? AND ?";
    $params = [$filterDateFrom, $filterDateTo];
    $types = "ss";
}

if (!empty($filterCustomer) && is_numeric($filterCustomer)) {
    $dateWhere .= " AND i.customer_id = ?";
    $params[] = (int)$filterCustomer;
    $types .= "i";
}

if ($filterGstType === 'gst') {
    $dateWhere .= " AND COALESCE(i.is_gst,1) = 1";
} elseif ($filterGstType === 'non_gst') {
    $dateWhere .= " AND COALESCE(i.is_gst,1) = 0";
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="profit_loss_report_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Start output
echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head>';
echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
echo '<title>Profit & Loss Report</title>';
echo '<style>';
echo 'td { border: 1px solid #000; }';
echo 'th { background: #f0f0f0; font-weight: bold; border: 1px solid #000; }';
echo '.profit { color: green; }';
echo '.loss { color: red; }';
echo '.header { background: #4CAF50; color: white; font-size: 16px; }';
echo '.subheader { background: #e0e0e0; }';
echo '</style>';
echo '</head>';
echo '<body>';

// Report Header
echo '<table border="1" cellpadding="3" cellspacing="0" style="border-collapse: collapse;">';

// Company Info
echo '<tr><td colspan="10" class="header" style="text-align: center; font-size: 18px; padding: 10px;">';
echo 'Sri Plaast - Profit & Loss Report';
echo '</td></tr>';

// Date Range
echo '<tr><td colspan="10" style="text-align: center; padding: 5px;">';
if (!empty($filterDateFrom) && !empty($filterDateTo)) {
    echo 'Period: ' . date('d M Y', strtotime($filterDateFrom)) . ' to ' . date('d M Y', strtotime($filterDateTo));
} else {
    echo 'Period: All Time';
}
echo '</td></tr>';

// Filter Info
$filterInfo = [];
if (!empty($filterCustomer)) {
    $custQuery = $conn->prepare("SELECT customer_name FROM customers WHERE id = ?");
    $custQuery->bind_param("i", $filterCustomer);
    $custQuery->execute();
    $custResult = $custQuery->get_result();
    $custData = $custResult->fetch_assoc();
    $filterInfo[] = "Customer: " . ($custData['customer_name'] ?? '');
    $custQuery->close();
}
if ($filterGstType === 'gst') {
    $filterInfo[] = "GST Invoices Only";
} elseif ($filterGstType === 'non_gst') {
    $filterInfo[] = "Non-GST Invoices Only";
}
if (!empty($filterInfo)) {
    echo '<tr><td colspan="10" style="text-align: center; padding: 5px;">Filters: ' . implode(' | ', $filterInfo) . '</td></tr>';
}

echo '<tr><td colspan="10" style="text-align: center; padding: 5px;">Generated on: ' . date('d M Y h:i A') . '</td></tr>';
echo '<tr><td colspan="10" style="height: 10px;"></td></tr>';

// ==================== SUMMARY STATS ====================
// Total Sales
$salesSql = "SELECT 
                COUNT(DISTINCT i.id) as invoice_count,
                COALESCE(SUM(i.total), 0) as total_sales,
                COALESCE(SUM(i.subtotal), 0) as subtotal_sales,
                COALESCE(SUM(i.cash_received), 0) as total_collected,
                COALESCE(SUM(i.pending_amount), 0) as total_pending,
                (SELECT COALESCE(SUM(ii.cgst_amount + ii.sgst_amount), 0) 
                 FROM invoice_item ii 
                 WHERE ii.invoice_id = i.id) as total_tax
             FROM invoice i
             WHERE $dateWhere";

if (!empty($params)) {
    $stmt = $conn->prepare($salesSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $salesStats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $result = $conn->query($salesSql);
    $salesStats = $result->fetch_assoc();
}

// Total Purchases
$purchaseSql = "SELECT 
                   COUNT(DISTINCT p.id) as purchase_count,
                   COALESCE(SUM(p.total), 0) as total_purchases,
                   COALESCE(SUM(p.paid_amount), 0) as total_paid
                FROM purchase p";

if (!empty($filterDateFrom) && !empty($filterDateTo)) {
    $purchaseSql .= " WHERE DATE(p.purchase_date) BETWEEN ? AND ?";
    $purchaseStmt = $conn->prepare($purchaseSql);
    $purchaseStmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $purchaseStmt->execute();
    $purchaseStats = $purchaseStmt->get_result()->fetch_assoc();
    $purchaseStmt->close();
} else {
    $result = $conn->query($purchaseSql);
    $purchaseStats = $result->fetch_assoc();
}

$totalSales = (float)($salesStats['total_sales'] ?? 0);
$totalPurchases = (float)($purchaseStats['total_purchases'] ?? 0);
$grossProfit = $totalSales - $totalPurchases;
$profitMargin = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0;

// Summary Stats Table
echo '<tr><th colspan="10" class="subheader" style="text-align: left; padding: 8px;">SUMMARY STATISTICS</th></tr>';
echo '<tr>';
echo '<th>Metric</th>';
echo '<th>Value</th>';
echo '<th>Count</th>';
echo '<th>Details</th>';
echo '<th colspan="6"></th>';
echo '</tr>';

echo '<tr>';
echo '<td>Total Sales Revenue</td>';
echo '<td>' . formatCurrency($totalSales) . '</td>';
echo '<td>' . (int)($salesStats['invoice_count'] ?? 0) . ' invoices</td>';
echo '<td>Tax: ' . formatCurrency($salesStats['total_tax'] ?? 0) . '</td>';
echo '<td colspan="6"></td>';
echo '</tr>';

echo '<tr>';
echo '<td>Total Purchase Cost</td>';
echo '<td>' . formatCurrency($totalPurchases) . '</td>';
echo '<td>' . (int)($purchaseStats['purchase_count'] ?? 0) . ' purchases</td>';
echo '<td>Paid: ' . formatCurrency($purchaseStats['total_paid'] ?? 0) . '</td>';
echo '<td colspan="6"></td>';
echo '</tr>';

echo '<tr>';
echo '<td><strong>Gross Profit / Loss</strong></td>';
echo '<td class="' . ($grossProfit >= 0 ? 'profit' : 'loss') . '"><strong>' . formatCurrency($grossProfit) . '</strong></td>';
echo '<td>Margin: ' . formatNumber($profitMargin, 1) . '%</td>';
echo '<td>Collected: ' . formatCurrency($salesStats['total_collected'] ?? 0) . '</td>';
echo '<td colspan="6"></td>';
echo '</tr>';

echo '<tr><td colspan="10" style="height: 10px;"></td></tr>';

// ==================== GST CREDIT SUMMARY ====================
$gstCreditSql = "SELECT 
                    COALESCE(SUM(gct.cgst + gct.sgst), 0) as total_gst_credit,
                    COALESCE(SUM((
                        SELECT COALESCE(SUM(ii.cgst_amount + ii.sgst_amount), 0)
                        FROM invoice_item ii
                        JOIN invoice i ON ii.invoice_id = i.id
                    )), 0) as total_gst_collected,
                    COALESCE(SUM((
                        SELECT COALESCE(SUM(ii.cgst_amount + ii.sgst_amount), 0)
                        FROM invoice_item ii
                        JOIN invoice i ON ii.invoice_id = i.id
                    )) - SUM(gct.cgst + gct.sgst), 0) as net_gst_payable
                 FROM gst_credit_table gct
                 LEFT JOIN purchase p ON gct.purchase_id = p.id";

$gstStmt = $conn->prepare($gstCreditSql);
$gstStmt->execute();
$gstStats = $gstStmt->get_result()->fetch_assoc();
$gstStmt->close();

if ((float)($gstStats['total_gst_credit'] ?? 0) > 0) {
    echo '<tr><th colspan="10" class="subheader" style="text-align: left; padding: 8px;">GST SUMMARY</th></tr>';
    echo '<tr>';
    echo '<th>GST Credit Available</th>';
    echo '<th>GST Collected</th>';
    echo '<th>Net GST Payable</th>';
    echo '<th colspan="7"></th>';
    echo '</tr>';
    echo '<tr>';
    echo '<td>' . formatCurrency($gstStats['total_gst_credit'] ?? 0) . '</td>';
    echo '<td>' . formatCurrency($gstStats['total_gst_collected'] ?? 0) . '</td>';
    echo '<td class="' . (($gstStats['net_gst_payable'] ?? 0) > 0 ? 'loss' : 'profit') . '">' . formatCurrency($gstStats['net_gst_payable'] ?? 0) . '</td>';
    echo '<td colspan="7"></td>';
    echo '</tr>';
    echo '<tr><td colspan="10" style="height: 10px;"></td></tr>';
}

// ==================== MONTHLY SUMMARY ====================
$trendSql = "SELECT 
                DATE_FORMAT(i.created_at, '%Y-%m') as period,
                COUNT(DISTINCT i.id) as invoice_count,
                COALESCE(SUM(i.total), 0) as sales,
                COALESCE(SUM(i.subtotal), 0) as subtotal,
                COALESCE(SUM((
                    SELECT COALESCE(SUM(ii.cgst_amount + ii.sgst_amount), 0)
                    FROM invoice_item ii
                    WHERE ii.invoice_id = i.id
                )), 0) as tax,
                COALESCE(SUM((
                    SELECT COALESCE(SUM(cat.purchase_price * ii.quantity), 0)
                    FROM invoice_item ii
                    LEFT JOIN category cat ON ii.cat_id = cat.id
                    WHERE ii.invoice_id = i.id
                )), 0) as cogs,
                COALESCE(SUM(i.total) - (
                    SELECT COALESCE(SUM(cat.purchase_price * ii.quantity), 0)
                    FROM invoice_item ii
                    LEFT JOIN category cat ON ii.cat_id = cat.id
                    WHERE ii.invoice_id = i.id
                ), 0) as profit
             FROM invoice i";

if (!empty($params)) {
    $trendSql .= " WHERE $dateWhere";
}

$trendSql .= " GROUP BY period
               ORDER BY MIN(i.created_at) DESC";

if (!empty($params)) {
    $trendStmt = $conn->prepare($trendSql);
    $trendStmt->bind_param($types, ...$params);
    $trendStmt->execute();
    $trendData = $trendStmt->get_result();
    $trendStmt->close();
} else {
    $trendData = $conn->query($trendSql);
}

echo '<tr><th colspan="10" class="subheader" style="text-align: left; padding: 8px;">MONTHLY PROFIT & LOSS SUMMARY</th></tr>';
echo '<tr>';
echo '<th>Period</th>';
echo '<th>Invoices</th>';
echo '<th>Subtotal</th>';
echo '<th>GST</th>';
echo '<th>Total Sales</th>';
echo '<th>COGS</th>';
echo '<th>Profit/Loss</th>';
echo '<th>Margin %</th>';
echo '<th colspan="2"></th>';
echo '</tr>';

$totalProfit = 0;
if ($trendData && $trendData->num_rows > 0) {
    while ($row = $trendData->fetch_assoc()) {
        $profit = (float)($row['profit'] ?? 0);
        $sales = (float)($row['sales'] ?? 0);
        $margin = $sales > 0 ? ($profit / $sales) * 100 : 0;
        $totalProfit += $profit;
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['period']) . '</td>';
        echo '<td>' . (int)($row['invoice_count']) . '</td>';
        echo '<td>' . formatCurrency($row['subtotal'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($row['tax'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($sales) . '</td>';
        echo '<td>' . formatCurrency($row['cogs'] ?? 0) . '</td>';
        echo '<td class="' . ($profit >= 0 ? 'profit' : 'loss') . '">' . formatCurrency($profit) . '</td>';
        echo '<td>' . formatNumber($margin, 1) . '%</td>';
        echo '<td colspan="2"></td>';
        echo '</tr>';
    }
    
    // Totals row
    echo '<tr style="font-weight: bold; background: #f0f0f0;">';
    echo '<td><strong>TOTAL</strong></td>';
    echo '<td>' . (int)($salesStats['invoice_count'] ?? 0) . '</td>';
    echo '<td>' . formatCurrency($salesStats['subtotal_sales'] ?? 0) . '</td>';
    echo '<td>' . formatCurrency($salesStats['total_tax'] ?? 0) . '</td>';
    echo '<td>' . formatCurrency($totalSales) . '</td>';
    echo '<td>' . formatCurrency($totalPurchases) . '</td>';
    echo '<td class="' . ($grossProfit >= 0 ? 'profit' : 'loss') . '">' . formatCurrency($grossProfit) . '</td>';
    echo '<td>' . formatNumber($profitMargin, 1) . '%</td>';
    echo '<td colspan="2"></td>';
    echo '</tr>';
} else {
    echo '<tr><td colspan="10" style="text-align: center;">No data available</td></tr>';
}

echo '<tr><td colspan="10" style="height: 20px;"></td></tr>';

// ==================== PROFIT BY CATEGORY ====================
$categoryProfitSql = "SELECT 
                        c.id,
                        c.category_name,
                        c.purchase_price,
                        COUNT(DISTINCT i.id) as invoice_count,
                        COALESCE(SUM(ii.quantity), 0) as total_qty_sold,
                        COALESCE(SUM(ii.selling_price * ii.quantity), 0) as total_sales_value,
                        COALESCE(SUM(c.purchase_price * ii.quantity), 0) as total_cost_value,
                        COALESCE(SUM(ii.total), 0) as total_revenue,
                        COALESCE(SUM(ii.total) - SUM(c.purchase_price * ii.quantity), 0) as gross_profit
                     FROM category c
                     LEFT JOIN invoice_item ii ON c.id = ii.cat_id
                     LEFT JOIN invoice i ON ii.invoice_id = i.id";

if (!empty($params)) {
    $categoryProfitSql .= " WHERE $dateWhere";
}

$categoryProfitSql .= " GROUP BY c.id, c.category_name, c.purchase_price
                        HAVING total_qty_sold > 0 OR total_sales_value > 0
                        ORDER BY gross_profit DESC";

if (!empty($params)) {
    $categoryStmt = $conn->prepare($categoryProfitSql);
    $categoryStmt->bind_param($types, ...$params);
    $categoryStmt->execute();
    $categoryProfit = $categoryStmt->get_result();
    $categoryStmt->close();
} else {
    $categoryProfit = $conn->query($categoryProfitSql);
}

echo '<tr><th colspan="10" class="subheader" style="text-align: left; padding: 8px;">PROFIT BY CATEGORY</th></tr>';
echo '<tr>';
echo '<th>Category</th>';
echo '<th>Invoices</th>';
echo '<th>Qty Sold</th>';
echo '<th>Purchase Price</th>';
echo '<th>Sales Value</th>';
echo '<th>Cost Value</th>';
echo '<th>Revenue</th>';
echo '<th>Gross Profit</th>';
echo '<th>Margin %</th>';
echo '<th></th>';
echo '</tr>';

if ($categoryProfit && $categoryProfit->num_rows > 0) {
    while ($cat = $categoryProfit->fetch_assoc()) {
        $revenue = (float)($cat['total_revenue'] ?? 0);
        $cost = (float)($cat['total_cost_value'] ?? 0);
        $profit = $revenue - $cost;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($cat['category_name']) . '</td>';
        echo '<td>' . (int)($cat['invoice_count']) . '</td>';
        echo '<td>' . formatNumber($cat['total_qty_sold'] ?? 0, 0) . '</td>';
        echo '<td>' . formatCurrency($cat['purchase_price'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($cat['total_sales_value'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($cost) . '</td>';
        echo '<td>' . formatCurrency($revenue) . '</td>';
        echo '<td class="' . ($profit >= 0 ? 'profit' : 'loss') . '">' . formatCurrency($profit) . '</td>';
        echo '<td>' . formatNumber($margin, 1) . '%</td>';
        echo '<td></td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="10" style="text-align: center;">No category data available</td></tr>';
}

echo '<tr><td colspan="10" style="height: 20px;"></td></tr>';

// ==================== PROFIT BY PRODUCT ====================
$productProfitSql = "SELECT 
                        p.id,
                        p.product_name,
                        p.hsn_code,
                        COUNT(DISTINCT i.id) as invoice_count,
                        COALESCE(SUM(ii.quantity), 0) as total_qty_sold,
                        COALESCE(AVG(ii.selling_price), 0) as avg_selling_price,
                        COALESCE(SUM(ii.total), 0) as total_revenue,
                        COALESCE(SUM(ii.total) - (SUM(ii.quantity) * c.purchase_price), 0) as estimated_profit
                     FROM product p
                     LEFT JOIN invoice_item ii ON p.id = ii.product_id
                     LEFT JOIN category c ON ii.cat_id = c.id
                     LEFT JOIN invoice i ON ii.invoice_id = i.id";

if (!empty($params)) {
    $productProfitSql .= " WHERE $dateWhere";
}

$productProfitSql .= " GROUP BY p.id, p.product_name, p.hsn_code
                       HAVING total_qty_sold > 0
                       ORDER BY estimated_profit DESC";

if (!empty($params)) {
    $productStmt = $conn->prepare($productProfitSql);
    $productStmt->bind_param($types, ...$params);
    $productStmt->execute();
    $productProfit = $productStmt->get_result();
    $productStmt->close();
} else {
    $productProfit = $conn->query($productProfitSql);
}

echo '<tr><th colspan="10" class="subheader" style="text-align: left; padding: 8px;">PROFIT BY PRODUCT</th></tr>';
echo '<tr>';
echo '<th>Product</th>';
echo '<th>HSN</th>';
echo '<th>Invoices</th>';
echo '<th>Qty Sold</th>';
echo '<th>Avg Price</th>';
echo '<th>Revenue</th>';
echo '<th>Est. Profit</th>';
echo '<th>Margin %</th>';
echo '<th colspan="2"></th>';
echo '</tr>';

if ($productProfit && $productProfit->num_rows > 0) {
    while ($prod = $productProfit->fetch_assoc()) {
        $revenue = (float)($prod['total_revenue'] ?? 0);
        $profit = (float)($prod['estimated_profit'] ?? 0);
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($prod['product_name']) . '</td>';
        echo '<td>' . htmlspecialchars($prod['hsn_code'] ?: '-') . '</td>';
        echo '<td>' . (int)($prod['invoice_count']) . '</td>';
        echo '<td>' . formatNumber($prod['total_qty_sold'] ?? 0, 0) . '</td>';
        echo '<td>' . formatCurrency($prod['avg_selling_price'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($revenue) . '</td>';
        echo '<td class="' . ($profit >= 0 ? 'profit' : 'loss') . '">' . formatCurrency($profit) . '</td>';
        echo '<td>' . formatNumber($margin, 1) . '%</td>';
        echo '<td colspan="2"></td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="10" style="text-align: center;">No product data available</td></tr>';
}

echo '<tr><td colspan="10" style="height: 20px;"></td></tr>';

// ==================== PROFIT BY CUSTOMER ====================
$customerProfitSql = "SELECT 
                        c.id,
                        c.customer_name,
                        c.phone,
                        COUNT(DISTINCT i.id) as invoice_count,
                        COALESCE(SUM(i.total), 0) as total_purchases,
                        COALESCE(SUM(i.pending_amount), 0) as pending_amount,
                        COALESCE(SUM((
                            SELECT COALESCE(SUM(ii.total - (cat.purchase_price * ii.quantity)), 0)
                            FROM invoice_item ii
                            LEFT JOIN category cat ON ii.cat_id = cat.id
                            WHERE ii.invoice_id = i.id
                        )), 0) as estimated_profit
                     FROM customers c
                     LEFT JOIN invoice i ON c.id = i.customer_id";

if (!empty($params)) {
    $customerProfitSql .= " WHERE $dateWhere";
}

$customerProfitSql .= " GROUP BY c.id, c.customer_name, c.phone
                        HAVING invoice_count > 0
                        ORDER BY estimated_profit DESC";

if (!empty($params)) {
    $customerStmt = $conn->prepare($customerProfitSql);
    $customerStmt->bind_param($types, ...$params);
    $customerStmt->execute();
    $customerProfit = $customerStmt->get_result();
    $customerStmt->close();
} else {
    $customerProfit = $conn->query($customerProfitSql);
}

echo '<tr><th colspan="10" class="subheader" style="text-align: left; padding: 8px;">PROFIT BY CUSTOMER</th></tr>';
echo '<tr>';
echo '<th>Customer</th>';
echo '<th>Phone</th>';
echo '<th>Invoices</th>';
echo '<th>Total Purchases</th>';
echo '<th>Pending</th>';
echo '<th>Est. Profit</th>';
echo '<th>Margin %</th>';
echo '<th colspan="3"></th>';
echo '</tr>';

if ($customerProfit && $customerProfit->num_rows > 0) {
    while ($cust = $customerProfit->fetch_assoc()) {
        $purchases = (float)($cust['total_purchases'] ?? 0);
        $profit = (float)($cust['estimated_profit'] ?? 0);
        $margin = $purchases > 0 ? ($profit / $purchases) * 100 : 0;
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($cust['customer_name']) . '</td>';
        echo '<td>' . htmlspecialchars($cust['phone'] ?: '-') . '</td>';
        echo '<td>' . (int)($cust['invoice_count']) . '</td>';
        echo '<td>' . formatCurrency($purchases) . '</td>';
        echo '<td>' . formatCurrency($cust['pending_amount'] ?? 0) . '</td>';
        echo '<td class="' . ($profit >= 0 ? 'profit' : 'loss') . '">' . formatCurrency($profit) . '</td>';
        echo '<td>' . formatNumber($margin, 1) . '%</td>';
        echo '<td colspan="3"></td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="10" style="text-align: center;">No customer data available</td></tr>';
}

echo '<tr><td colspan="10" style="height: 20px;"></td></tr>';

// ==================== TOP PROFITABLE ITEMS ====================
$topItemsSql = "SELECT 
                    COALESCE(ii.product_name, ii.cat_name) as item_name,
                    ii.hsn,
                    c.category_name,
                    SUM(ii.quantity) as qty_sold,
                    AVG(ii.selling_price) as avg_price,
                    SUM(ii.total) as revenue,
                    COALESCE(SUM(c.purchase_price * ii.quantity), 0) as cost,
                    SUM(ii.total) - COALESCE(SUM(c.purchase_price * ii.quantity), 0) as profit,
                    CASE 
                        WHEN SUM(c.purchase_price * ii.quantity) > 0 
                        THEN (SUM(ii.total) - SUM(c.purchase_price * ii.quantity)) / SUM(c.purchase_price * ii.quantity) * 100
                        ELSE 0 
                    END as profit_percentage
                FROM invoice_item ii
                LEFT JOIN category c ON ii.cat_id = c.id
                LEFT JOIN invoice i ON ii.invoice_id = i.id";

if (!empty($params)) {
    $topItemsSql .= " WHERE $dateWhere";
}

$topItemsSql .= " GROUP BY COALESCE(ii.product_name, ii.cat_name), ii.hsn, c.category_name
                  HAVING revenue > 0 AND profit > 0
                  ORDER BY profit DESC
                  LIMIT 20";

if (!empty($params)) {
    $topItemsStmt = $conn->prepare($topItemsSql);
    $topItemsStmt->bind_param($types, ...$params);
    $topItemsStmt->execute();
    $topItems = $topItemsStmt->get_result();
    $topItemsStmt->close();
} else {
    $topItems = $conn->query($topItemsSql);
}

echo '<tr><th colspan="10" class="subheader" style="text-align: left; padding: 8px;">TOP 20 PROFITABLE ITEMS</th></tr>';
echo '<tr>';
echo '<th>Item</th>';
echo '<th>Category</th>';
echo '<th>HSN</th>';
echo '<th>Qty Sold</th>';
echo '<th>Avg Price</th>';
echo '<th>Revenue</th>';
echo '<th>Cost</th>';
echo '<th>Profit</th>';
echo '<th>Margin %</th>';
echo '<th></th>';
echo '</tr>';

if ($topItems && $topItems->num_rows > 0) {
    while ($item = $topItems->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($item['item_name']) . '</td>';
        echo '<td>' . htmlspecialchars($item['category_name'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($item['hsn'] ?: '-') . '</td>';
        echo '<td>' . formatNumber($item['qty_sold'] ?? 0, 0) . '</td>';
        echo '<td>' . formatCurrency($item['avg_price'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($item['revenue'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($item['cost'] ?? 0) . '</td>';
        echo '<td class="profit">' . formatCurrency($item['profit'] ?? 0) . '</td>';
        echo '<td>' . formatNumber($item['profit_percentage'] ?? 0, 1) . '%</td>';
        echo '<td></td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="10" style="text-align: center;">No profitable items found</td></tr>';
}

echo '<tr><td colspan="10" style="height: 20px;"></td></tr>';

// ==================== LOSS MAKING ITEMS ====================
$lossItemsSql = "SELECT 
                    COALESCE(ii.product_name, ii.cat_name) as item_name,
                    ii.hsn,
                    c.category_name,
                    SUM(ii.quantity) as qty_sold,
                    AVG(ii.selling_price) as avg_price,
                    SUM(ii.total) as revenue,
                    COALESCE(SUM(c.purchase_price * ii.quantity), 0) as cost,
                    SUM(ii.total) - COALESCE(SUM(c.purchase_price * ii.quantity), 0) as profit
                FROM invoice_item ii
                LEFT JOIN category c ON ii.cat_id = c.id
                LEFT JOIN invoice i ON ii.invoice_id = i.id";

if (!empty($params)) {
    $lossItemsSql .= " WHERE $dateWhere";
}

$lossItemsSql .= " GROUP BY COALESCE(ii.product_name, ii.cat_name), ii.hsn, c.category_name
                  HAVING profit < 0
                  ORDER BY profit ASC
                  LIMIT 10";

if (!empty($params)) {
    $lossItemsStmt = $conn->prepare($lossItemsSql);
    $lossItemsStmt->bind_param($types, ...$params);
    $lossItemsStmt->execute();
    $lossItems = $lossItemsStmt->get_result();
    $lossItemsStmt->close();
} else {
    $lossItems = $conn->query($lossItemsSql);
}

echo '<tr><th colspan="10" class="subheader" style="text-align: left; padding: 8px;">TOP 10 LOSS MAKING ITEMS</th></tr>';
echo '<tr>';
echo '<th>Item</th>';
echo '<th>Category</th>';
echo '<th>HSN</th>';
echo '<th>Qty Sold</th>';
echo '<th>Avg Price</th>';
echo '<th>Revenue</th>';
echo '<th>Cost</th>';
echo '<th>Loss</th>';
echo '<th>Margin %</th>';
echo '<th></th>';
echo '</tr>';

if ($lossItems && $lossItems->num_rows > 0) {
    while ($item = $lossItems->fetch_assoc()) {
        $loss = abs($item['profit'] ?? 0);
        $margin = $item['revenue'] > 0 ? ($loss / $item['revenue']) * 100 : 0;
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($item['item_name']) . '</td>';
        echo '<td>' . htmlspecialchars($item['category_name'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($item['hsn'] ?: '-') . '</td>';
        echo '<td>' . formatNumber($item['qty_sold'] ?? 0, 0) . '</td>';
        echo '<td>' . formatCurrency($item['avg_price'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($item['revenue'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($item['cost'] ?? 0) . '</td>';
        echo '<td class="loss">' . formatCurrency($loss) . '</td>';
        echo '<td>' . formatNumber($margin, 1) . '%</td>';
        echo '<td></td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="10" style="text-align: center; color: green;">No loss-making items found</td></tr>';
}

// Footer
echo '<tr><td colspan="10" style="height: 20px;"></td></tr>';
echo '<tr><td colspan="10" style="text-align: center; font-style: italic;">End of Report</td></tr>';

echo '</table>';
echo '</body>';
echo '</html>';
?>