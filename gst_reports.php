<?php
session_start();
$currentPage = 'gst_reports';
$pageTitle = 'GST Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view reports, but only admin can export sensitive data
checkRoleAccess(['admin', 'sale']);

// Helper function to build query string
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return count($params) ? '?' . http_build_query($params) : '';
}

// Format helpers
function formatCurrency($amount) {
    return '₹' . number_format((float)$amount, 2);
}

function formatNumber($number, $decimals = 2) {
    return number_format((float)$number, $decimals);
}

function getGstType($is_gst) {
    return ((int)$is_gst === 1) ? 'GST' : 'Non-GST';
}

$is_admin = ($_SESSION['user_role'] === 'admin');

// Handle report generation based on filters
$reportType = $_GET['report_type'] ?? 'sales'; // sales, purchase, gst_credit, summary
$filterDateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$filterDateTo = $_GET['date_to'] ?? date('Y-m-d');
$filterGstOnly = isset($_GET['gst_only']) ? (int)$_GET['gst_only'] : 1; // Default to GST only
$filterCustomer = $_GET['customer_id'] ?? '';
$filterSupplier = $_GET['supplier_id'] ?? '';

// Date validation
if (strtotime($filterDateFrom) > strtotime($filterDateTo)) {
    $error = "From Date cannot be later than To Date";
    $filterDateTo = $filterDateFrom;
}

// Get customers for filter
$customers = $conn->query("SELECT id, customer_name, gst_number FROM customers ORDER BY customer_name ASC");

// Get suppliers for filter (for purchase reports)
$suppliers = $conn->query("SELECT id, supplier_name, gst_number FROM suppliers ORDER BY supplier_name ASC");

// Get all HSN codes for summary
$hsnCodes = $conn->query("SELECT DISTINCT hsn FROM gst WHERE status = 1 ORDER BY hsn");

// Initialize report data arrays
$salesData = [];
$purchaseData = [];
$gstCreditData = [];
$hsnSummary = [];
$monthlyData = [];

// Build common date condition - FIXED: Added table alias to created_at
$dateCondition = "DATE(i.created_at) BETWEEN ? AND ?";
$dateParams = [$filterDateFrom, $filterDateTo];
$dateTypes = "ss";

// Fetch Sales GST Report
if ($reportType === 'sales' || $reportType === 'summary') {
    $salesWhere = "1=1 AND COALESCE(i.is_gst,1) = 1"; // Default to GST invoices
    if (!$filterGstOnly) {
        $salesWhere = "1=1"; // Include non-GST if requested
    }
    
    $salesParams = $dateParams;
    $salesTypes = $dateTypes;
    
    if (!empty($filterCustomer)) {
        $salesWhere .= " AND i.customer_id = ?";
        $salesParams[] = (int)$filterCustomer;
        $salesTypes .= "i";
    }
    
    $salesSql = "SELECT 
                    i.id,
                    i.inv_num,
                    i.created_at,
                    i.customer_id,
                    i.customer_name,
                    i.subtotal,
                    i.overall_discount,
                    i.overall_discount_type,
                    i.total,
                    i.taxable,
                    i.cgst,
                    i.cgst_amount,
                    i.sgst,
                    i.sgst_amount,
                    i.is_gst,
                    c.customer_name AS master_customer_name,
                    c.gst_number AS customer_gst,
                    (SELECT SUM(quantity) FROM invoice_item WHERE invoice_id = i.id) as total_qty,
                    (SELECT COUNT(*) FROM invoice_item WHERE invoice_id = i.id) as item_count
                FROM invoice i
                LEFT JOIN customers c ON i.customer_id = c.id
                WHERE $salesWhere AND $dateCondition
                ORDER BY i.created_at DESC";
    
    $stmt = $conn->prepare($salesSql);
    $stmt->bind_param($salesTypes, ...$salesParams);
    $stmt->execute();
    $salesData = $stmt->get_result();
    $stmt->close();
}

// Fetch Purchase GST Report
if ($reportType === 'purchase' || $reportType === 'summary' || $reportType === 'gst_credit') {
    $purchaseWhere = "1=1";
    $purchaseParams = $dateParams;
    $purchaseTypes = $dateTypes;
    
    if (!empty($filterSupplier)) {
        $purchaseWhere .= " AND p.supplier_id = ?";
        $purchaseParams[] = (int)$filterSupplier;
        $purchaseTypes .= "i";
    }
    
    $purchaseSql = "SELECT 
                        p.id,
                        p.purchase_no,
                        p.invoice_num as supplier_invoice,
                        p.purchase_date,
                        p.cgst,
                        p.cgst_amount,
                        p.sgst,
                        p.sgst_amount,
                        p.total,
                        p.paid_amount,
                        p.gst_type,
                        s.supplier_name,
                        s.gst_number as supplier_gst,
                        (SELECT COUNT(*) FROM purchase_item WHERE purchase_id = p.id) as item_count
                    FROM purchase p
                    LEFT JOIN suppliers s ON p.supplier_id = s.id
                    WHERE $purchaseWhere AND DATE(p.purchase_date) BETWEEN ? AND ?
                    ORDER BY p.purchase_date DESC";
    
    $stmt = $conn->prepare($purchaseSql);
    $stmt->bind_param($purchaseTypes, ...$purchaseParams);
    $stmt->execute();
    $purchaseData = $stmt->get_result();
    $stmt->close();
}

// Fetch GST Credit Summary (from gst_credit_table)
if ($reportType === 'gst_credit' || $reportType === 'summary') {
    $creditSql = "SELECT 
                    gct.*,
                    p.purchase_no,
                    p.purchase_date,
                    s.supplier_name
                  FROM gst_credit_table gct
                  INNER JOIN purchase p ON gct.purchase_id = p.id
                  LEFT JOIN suppliers s ON p.supplier_id = s.id
                  WHERE DATE(p.purchase_date) BETWEEN ? AND ?
                  ORDER BY p.purchase_date DESC";
    
    $stmt = $conn->prepare($creditSql);
    $stmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $stmt->execute();
    $gstCreditData = $stmt->get_result();
    $stmt->close();
}

// HSN-wise Summary
if ($reportType === 'hsn_summary' || $reportType === 'summary') {
    // Sales by HSN
    $hsnSalesSql = "SELECT 
                        ii.hsn,
                        COUNT(DISTINCT i.id) as invoice_count,
                        SUM(ii.quantity) as total_qty,
                        SUM(ii.taxable) as total_taxable,
                        SUM(ii.cgst_amount) as total_cgst,
                        SUM(ii.sgst_amount) as total_sgst,
                        SUM(ii.total) as total_amount
                    FROM invoice_item ii
                    INNER JOIN invoice i ON ii.invoice_id = i.id
                    WHERE i.is_gst = 1 AND DATE(i.created_at) BETWEEN ? AND ?
                    GROUP BY ii.hsn
                    ORDER BY ii.hsn";
    
    $stmt = $conn->prepare($hsnSalesSql);
    $stmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $stmt->execute();
    $hsnSalesResult = $stmt->get_result();
    
    // Purchase by HSN
    $hsnPurchaseSql = "SELECT 
                            pi.hsn,
                            COUNT(DISTINCT p.id) as purchase_count,
                            SUM(pi.qty) as total_qty,
                            SUM(pi.taxable) as total_taxable,
                            SUM(pi.cgst_amount) as total_cgst,
                            SUM(pi.sgst_amount) as total_sgst,
                            SUM(pi.total) as total_amount
                        FROM purchase_item pi
                        INNER JOIN purchase p ON pi.purchase_id = p.id
                        WHERE DATE(p.purchase_date) BETWEEN ? AND ?
                        GROUP BY pi.hsn
                        ORDER BY pi.hsn";
    
    $stmt = $conn->prepare($hsnPurchaseSql);
    $stmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $stmt->execute();
    $hsnPurchaseResult = $stmt->get_result();
    $stmt->close();
    
    // Combine HSN data
    $hsnMap = [];
    
    while ($row = $hsnSalesResult->fetch_assoc()) {
        $hsnMap[$row['hsn']] = [
            'hsn' => $row['hsn'],
            'sales_invoices' => $row['invoice_count'],
            'sales_qty' => $row['total_qty'],
            'sales_taxable' => $row['total_taxable'],
            'sales_cgst' => $row['total_cgst'],
            'sales_sgst' => $row['total_sgst'],
            'sales_total' => $row['total_amount'],
            'purchase_invoices' => 0,
            'purchase_qty' => 0,
            'purchase_taxable' => 0,
            'purchase_cgst' => 0,
            'purchase_sgst' => 0,
            'purchase_total' => 0
        ];
    }
    
    while ($row = $hsnPurchaseResult->fetch_assoc()) {
        if (isset($hsnMap[$row['hsn']])) {
            $hsnMap[$row['hsn']]['purchase_invoices'] = $row['purchase_count'];
            $hsnMap[$row['hsn']]['purchase_qty'] = $row['total_qty'];
            $hsnMap[$row['hsn']]['purchase_taxable'] = $row['total_taxable'];
            $hsnMap[$row['hsn']]['purchase_cgst'] = $row['total_cgst'];
            $hsnMap[$row['hsn']]['purchase_sgst'] = $row['total_sgst'];
            $hsnMap[$row['hsn']]['purchase_total'] = $row['total_amount'];
        } else {
            $hsnMap[$row['hsn']] = [
                'hsn' => $row['hsn'],
                'sales_invoices' => 0,
                'sales_qty' => 0,
                'sales_taxable' => 0,
                'sales_cgst' => 0,
                'sales_sgst' => 0,
                'sales_total' => 0,
                'purchase_invoices' => $row['purchase_count'],
                'purchase_qty' => $row['total_qty'],
                'purchase_taxable' => $row['total_taxable'],
                'purchase_cgst' => $row['total_cgst'],
                'purchase_sgst' => $row['total_sgst'],
                'purchase_total' => $row['total_amount']
            ];
        }
    }
    
    $hsnSummary = $hsnMap;
}

// Monthly GST Summary - FIXED: Added table alias to created_at
$monthlySql = "SELECT 
                    DATE_FORMAT(i.created_at, '%Y-%m') as month,
                    COUNT(*) as total_invoices,
                    SUM(CASE WHEN i.is_gst = 1 THEN 1 ELSE 0 END) as gst_invoices,
                    SUM(CASE WHEN i.is_gst = 0 THEN 1 ELSE 0 END) as non_gst_invoices,
                    SUM(i.total) as total_sales,
                    SUM(i.taxable) as total_taxable,
                    SUM(i.cgst_amount) as total_cgst,
                    SUM(i.sgst_amount) as total_sgst
                FROM invoice i
                WHERE i.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(i.created_at, '%Y-%m')
                ORDER BY month DESC";

$monthlyResult = $conn->query($monthlySql);

// Calculate totals
$totalSales = 0;
$totalTaxable = 0;
$totalCGST = 0;
$totalSGST = 0;
$totalIGST = 0;
$totalPurchases = 0;
$purchaseCGST = 0;
$purchaseSGST = 0;

if ($salesData && $salesData->num_rows > 0) {
    $salesData->data_seek(0);
    while ($row = $salesData->fetch_assoc()) {
        if ((int)$row['is_gst'] === 1) {
            $totalSales += (float)$row['total'];
            $totalTaxable += (float)$row['taxable'];
            $totalCGST += (float)$row['cgst_amount'];
            $totalSGST += (float)$row['sgst_amount'];
        }
    }
    $salesData->data_seek(0);
}

if ($purchaseData && $purchaseData->num_rows > 0) {
    $purchaseData->data_seek(0);
    while ($row = $purchaseData->fetch_assoc()) {
        $totalPurchases += (float)$row['total'];
        $purchaseCGST += (float)$row['cgst_amount'];
        $purchaseSGST += (float)$row['sgst_amount'];
    }
    $purchaseData->data_seek(0);
}

$netGSTPayable = ($totalCGST + $totalSGST) - ($purchaseCGST + $purchaseSGST);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css">
    <style>
        .report-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 24px;
        }
        .report-header h1 { font-size: 24px; font-weight: 600; margin-bottom: 8px; }
        .report-header p { color: #94a3b8; margin-bottom: 0; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .stat-value { font-size: 28px; font-weight: 700; color: #1e293b; line-height: 1.2; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 4px; }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px;
        }
        .stat-icon.blue { background: #eef2ff; color: #2463eb; }
        .stat-icon.green { background: #dcfce7; color: #16a34a; }
        .stat-icon.orange { background: #fff3cd; color: #f97316; }
        .stat-icon.purple { background: #f3e8ff; color: #9333ea; }

        .gst-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }
        .gst-badge.gst { background: #dcfce7; color: #166534; }
        .gst-badge.non-gst { background: #fef3c7; color: #92400e; }

        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #eef2f6;
        }
        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            border-bottom: 1px solid #eef2f6;
            padding-bottom: 16px;
        }
        .filter-tab {
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #475569;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .filter-tab:hover { background: #f1f5f9; border-color: #94a3b8; }
        .filter-tab.active {
            background: #2463eb;
            border-color: #2463eb;
            color: white;
        }
        .filter-tab.active .badge { background: white; color: #2463eb; }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #eef2f6;
        }
        .summary-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eef2f6;
        }

        .table-custom th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 13px;
        }
        .table-custom td { font-size: 13px; }

        .chart-container {
            height: 300px;
            margin-bottom: 24px;
        }

        .nav-tabs-custom {
            border-bottom: 2px solid #eef2f6;
            margin-bottom: 20px;
        }
        .nav-tabs-custom .nav-link {
            border: none;
            color: #64748b;
            font-weight: 500;
            padding: 12px 20px;
            margin-right: 4px;
            border-radius: 8px 8px 0 0;
        }
        .nav-tabs-custom .nav-link:hover {
            background: #f8fafc;
            color: #1e293b;
        }
        .nav-tabs-custom .nav-link.active {
            color: #2463eb;
            background: transparent;
            border-bottom: 3px solid #2463eb;
        }

        .total-row {
            background: #f8fafc;
            font-weight: 600;
            border-top: 2px solid #e2e8f0;
        }

        .btn-export {
            background: #16a34a;
            color: white;
            border: none;
        }
        .btn-export:hover { background: #15803d; color: white; }

        .amount-positive { color: #16a34a; }
        .amount-negative { color: #dc2626; }

        .info-note {
            background: #f8fafc;
            border-left: 4px solid #2463eb;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            color: #475569;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <!-- Report Header -->
            <div class="report-header d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-file-earmark-spreadsheet me-2"></i>GST Reports</h1>
                    <p>Comprehensive GST analysis with sales, purchases, and credit summaries</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="export_gst_report.php?<?php echo htmlspecialchars($_SERVER['QUERY_STRING']); ?>" class="btn btn-export">
                        <i class="bi bi-file-earmark-excel"></i> Export Report
                    </a>
                   
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="gst_reports.php" id="reportForm" class="row g-3">
                    <!-- Report Type Tabs -->
                    <div class="col-12">
                        <div class="filter-tabs">
                            <a href="?report_type=sales&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'sales'])); ?>" 
                               class="filter-tab <?php echo $reportType === 'sales' ? 'active' : ''; ?>">
                                <i class="bi bi-cart"></i> Sales GST
                            </a>
                            <a href="?report_type=purchase&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'purchase'])); ?>" 
                               class="filter-tab <?php echo $reportType === 'purchase' ? 'active' : ''; ?>">
                                <i class="bi bi-truck"></i> Purchase GST
                            </a>
                            <a href="?report_type=gst_credit&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'gst_credit'])); ?>" 
                               class="filter-tab <?php echo $reportType === 'gst_credit' ? 'active' : ''; ?>">
                                <i class="bi bi-credit-card"></i> GST Credit
                            </a>
                            <a href="?report_type=hsn_summary&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'hsn_summary'])); ?>" 
                               class="filter-tab <?php echo $reportType === 'hsn_summary' ? 'active' : ''; ?>">
                                <i class="bi bi-grid"></i> HSN Summary
                            </a>
                            <a href="?report_type=summary&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'summary'])); ?>" 
                               class="filter-tab <?php echo $reportType === 'summary' ? 'active' : ''; ?>">
                                <i class="bi bi-pie-chart"></i> Complete Summary
                            </a>
                        </div>
                    </div>

                    <!-- Preserve report type in hidden fields for form submission -->
                    <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($reportType); ?>">

                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filterDateFrom); ?>" required>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filterDateTo); ?>" required>
                    </div>

                    <?php if ($reportType === 'sales' || $reportType === 'summary'): ?>
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-select">
                            <option value="">All Customers</option>
                            <?php if ($customers): $customers->data_seek(0); ?>
                                <?php while ($cust = $customers->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$cust['id']; ?>" <?php echo ((string)$filterCustomer === (string)$cust['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cust['customer_name']); ?>
                                        <?php if (!empty($cust['gst_number'])): ?>
                                            (<?php echo htmlspecialchars($cust['gst_number']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($reportType === 'purchase' || $reportType === 'summary'): ?>
                    <div class="col-md-3">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-select">
                            <option value="">All Suppliers</option>
                            <?php if ($suppliers): $suppliers->data_seek(0); ?>
                                <?php while ($sup = $suppliers->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$sup['id']; ?>" <?php echo ((string)$filterSupplier === (string)$sup['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sup['supplier_name']); ?>
                                        <?php if (!empty($sup['gst_number'])): ?>
                                            (<?php echo htmlspecialchars($sup['gst_number']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="gst_only" id="gstOnly" value="1" <?php echo $filterGstOnly ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="gstOnly">
                                GST Only <small class="text-muted">(Exclude non-GST)</small>
                            </label>
                        </div>
                    </div>

                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Generate
                        </button>
                        <a href="gst_reports.php?report_type=<?php echo $reportType; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-repeat"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Summary Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo formatCurrency($totalSales); ?></div>
                            <div class="stat-label">Total GST Sales</div>
                            <small class="text-muted">Taxable: <?php echo formatCurrency($totalTaxable); ?></small>
                        </div>
                        <div class="stat-icon blue">
                            <i class="bi bi-cart-check"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo formatCurrency($totalCGST + $totalSGST); ?></div>
                            <div class="stat-label">Output GST</div>
                            <small class="text-muted">CGST: <?php echo formatCurrency($totalCGST); ?> | SGST: <?php echo formatCurrency($totalSGST); ?></small>
                        </div>
                        <div class="stat-icon green">
                            <i class="bi bi-arrow-up-circle"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo formatCurrency($purchaseCGST + $purchaseSGST); ?></div>
                            <div class="stat-label">Input GST Credit</div>
                            <small class="text-muted">CGST: <?php echo formatCurrency($purchaseCGST); ?> | SGST: <?php echo formatCurrency($purchaseSGST); ?></small>
                        </div>
                        <div class="stat-icon orange">
                            <i class="bi bi-arrow-down-circle"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value <?php echo $netGSTPayable >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                <?php echo formatCurrency(abs($netGSTPayable)); ?>
                            </div>
                            <div class="stat-label">Net GST <?php echo $netGSTPayable >= 0 ? 'Payable' : 'Refundable'; ?></div>
                            <small class="text-muted">Output - Input</small>
                        </div>
                        <div class="stat-icon purple">
                            <i class="bi bi-calculator"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Content based on type -->
            <?php if ($reportType === 'sales'): ?>
                <!-- Sales GST Report -->
                <div class="summary-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="summary-title mb-0">Sales GST Details</h5>
                        <span class="badge bg-primary">Period: <?php echo date('d M Y', strtotime($filterDateFrom)); ?> - <?php echo date('d M Y', strtotime($filterDateTo)); ?></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table-custom" id="salesGSTTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>GSTIN</th>
                                    <th>Taxable Value</th>
                                    <th>CGST</th>
                                    <th>SGST</th>
                                    <th>Total GST</th>
                                    <th>Invoice Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $salesTotalTaxable = 0;
                                $salesTotalCGST = 0;
                                $salesTotalSGST = 0;
                                $salesTotalAmount = 0;
                                
                                if ($salesData && $salesData->num_rows > 0): 
                                    while ($sale = $salesData->fetch_assoc()): 
                                        if ((int)$sale['is_gst'] === 1 || !$filterGstOnly):
                                            $displayCustomer = $sale['master_customer_name'] ?: ($sale['customer_name'] ?: 'Walk-in Customer');
                                            $customerGST = $sale['customer_gst'] ?? '';
                                            
                                            $salesTotalTaxable += (float)$sale['taxable'];
                                            $salesTotalCGST += (float)$sale['cgst_amount'];
                                            $salesTotalSGST += (float)$sale['sgst_amount'];
                                            $salesTotalAmount += (float)$sale['total'];
                                ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($sale['created_at'])); ?></td>
                                        <td>
                                            <a href="view_invoice.php?id=<?php echo (int)$sale['id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($sale['inv_num']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($displayCustomer); ?></td>
                                        <td>
                                            <?php if (!empty($customerGST)): ?>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($customerGST); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo formatCurrency($sale['taxable']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($sale['cgst_amount']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($sale['sgst_amount']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($sale['cgst_amount'] + $sale['sgst_amount']); ?></td>
                                        <td class="text-end fw-semibold"><?php echo formatCurrency($sale['total']); ?></td>
                                    </tr>
                                <?php 
                                        endif;
                                    endwhile; 
                                else: ?>
                                    
                                <?php endif; ?>
                            </tbody>
                            <?php if ($salesData && $salesData->num_rows > 0): ?>
                            <tfoot class="total-row">
                                <tr>
                                    <th colspan="4" class="text-end">Totals:</th>
                                    <th class="text-end"><?php echo formatCurrency($salesTotalTaxable); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($salesTotalCGST); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($salesTotalSGST); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($salesTotalCGST + $salesTotalSGST); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($salesTotalAmount); ?></th>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

            <?php elseif ($reportType === 'purchase'): ?>
                <!-- Purchase GST Report -->
                <div class="summary-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="summary-title mb-0">Purchase GST Details</h5>
                        <span class="badge bg-primary">Period: <?php echo date('d M Y', strtotime($filterDateFrom)); ?> - <?php echo date('d M Y', strtotime($filterDateTo)); ?></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table-custom" id="purchaseGSTTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Purchase #</th>
                                    <th>Supplier</th>
                                    <th>Supplier GST</th>
                                    <th>Taxable Value</th>
                                    <th>CGST</th>
                                    <th>SGST</th>
                                    <th>Total GST</th>
                                    <th>Purchase Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $purchaseTotalTaxable = 0;
                                $purchaseTotalCGST = 0;
                                $purchaseTotalSGST = 0;
                                $purchaseTotalAmount = 0;
                                
                                if ($purchaseData && $purchaseData->num_rows > 0): 
                                    while ($purchase = $purchaseData->fetch_assoc()): 
                                        $taxable = (float)$purchase['total'] - ((float)$purchase['cgst_amount'] + (float)$purchase['sgst_amount']);
                                        $purchaseTotalTaxable += $taxable;
                                        $purchaseTotalCGST += (float)$purchase['cgst_amount'];
                                        $purchaseTotalSGST += (float)$purchase['sgst_amount'];
                                        $purchaseTotalAmount += (float)$purchase['total'];
                                ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($purchase['purchase_date'])); ?></td>
                                        <td>
                                            <a href="view_purchase.php?id=<?php echo (int)$purchase['id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($purchase['purchase_no']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($purchase['supplier_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if (!empty($purchase['supplier_gst'])): ?>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($purchase['supplier_gst']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo formatCurrency($taxable); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($purchase['cgst_amount']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($purchase['sgst_amount']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($purchase['cgst_amount'] + $purchase['sgst_amount']); ?></td>
                                        <td class="text-end fw-semibold"><?php echo formatCurrency($purchase['total']); ?></td>
                                    </tr>
                                <?php 
                                    endwhile; 
                                else: ?>
                                   
                                <?php endif; ?>
                            </tbody>
                            <?php if ($purchaseData && $purchaseData->num_rows > 0): ?>
                            <tfoot class="total-row">
                                <tr>
                                    <th colspan="4" class="text-end">Totals:</th>
                                    <th class="text-end"><?php echo formatCurrency($purchaseTotalTaxable); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($purchaseTotalCGST); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($purchaseTotalSGST); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($purchaseTotalCGST + $purchaseTotalSGST); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($purchaseTotalAmount); ?></th>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

            <?php elseif ($reportType === 'gst_credit'): ?>
                <!-- GST Credit Report -->
                <div class="summary-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="summary-title mb-0">GST Credit Summary (Input Tax Credit)</h5>
                        <span class="badge bg-primary">Period: <?php echo date('d M Y', strtotime($filterDateFrom)); ?> - <?php echo date('d M Y', strtotime($filterDateTo)); ?></span>
                    </div>

                    <div class="info-note">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        Input Tax Credit (ITC) available from purchases. Can be claimed against output GST liability.
                    </div>

                    <div class="table-responsive">
                        <table class="table-custom" id="gstCreditTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Purchase #</th>
                                    <th>Supplier</th>
                                    <th>CGST Credit</th>
                                    <th>SGST Credit</th>
                                    <th>Total Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $totalCGSTCredit = 0;
                                $totalSGSTCredit = 0;
                                
                                if ($gstCreditData && $gstCreditData->num_rows > 0): 
                                    while ($credit = $gstCreditData->fetch_assoc()): 
                                        $totalCGSTCredit += (float)$credit['cgst'];
                                        $totalSGSTCredit += (float)$credit['sgst'];
                                ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($credit['purchase_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($credit['purchase_no']); ?></td>
                                        <td><?php echo htmlspecialchars($credit['supplier_name'] ?? 'N/A'); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($credit['cgst']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($credit['sgst']); ?></td>
                                        <td class="text-end fw-semibold"><?php echo formatCurrency($credit['total_credit']); ?></td>
                                    </tr>
                                <?php 
                                    endwhile; 
                                else: ?>
                                    
                                <?php endif; ?>
                            </tbody>
                            <?php if ($gstCreditData && $gstCreditData->num_rows > 0): ?>
                            <tfoot class="total-row">
                                <tr>
                                    <th colspan="3" class="text-end">Totals:</th>
                                    <th class="text-end"><?php echo formatCurrency($totalCGSTCredit); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($totalSGSTCredit); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($totalCGSTCredit + $totalSGSTCredit); ?></th>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

            <?php elseif ($reportType === 'hsn_summary'): ?>
                <!-- HSN Summary Report -->
                <div class="summary-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="summary-title mb-0">HSN-wise Summary</h5>
                        <span class="badge bg-primary">Period: <?php echo date('d M Y', strtotime($filterDateFrom)); ?> - <?php echo date('d M Y', strtotime($filterDateTo)); ?></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table-custom" id="hsnSummaryTable">
                            <thead>
                                <tr>
                                    <th rowspan="2">HSN Code</th>
                                    <th colspan="5" class="text-center bg-light">Sales</th>
                                    <th colspan="5" class="text-center bg-light">Purchases</th>
                                    <th rowspan="2">Net Taxable</th>
                                    <th rowspan="2">Net GST</th>
                                </tr>
                                <tr>
                                    <th class="text-center">Invoices</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Taxable</th>
                                    <th class="text-end">CGST</th>
                                    <th class="text-end">SGST</th>
                                    <th class="text-center">Invoices</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Taxable</th>
                                    <th class="text-end">CGST</th>
                                    <th class="text-end">SGST</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $totalSalesTaxable = 0;
                                $totalSalesCGST = 0;
                                $totalSalesSGST = 0;
                                $totalPurchaseTaxable = 0;
                                $totalPurchaseCGST = 0;
                                $totalPurchaseSGST = 0;
                                
                                if (!empty($hsnSummary)): 
                                    foreach ($hsnSummary as $hsn => $data): 
                                        $netTaxable = (float)$data['sales_taxable'] - (float)$data['purchase_taxable'];
                                        $netGST = ((float)$data['sales_cgst'] + (float)$data['sales_sgst']) - ((float)$data['purchase_cgst'] + (float)$data['purchase_sgst']);
                                        
                                        $totalSalesTaxable += (float)$data['sales_taxable'];
                                        $totalSalesCGST += (float)$data['sales_cgst'];
                                        $totalSalesSGST += (float)$data['sales_sgst'];
                                        $totalPurchaseTaxable += (float)$data['purchase_taxable'];
                                        $totalPurchaseCGST += (float)$data['purchase_cgst'];
                                        $totalPurchaseSGST += (float)$data['purchase_sgst'];
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($hsn ?: 'N/A'); ?></strong></td>
                                        <td class="text-center"><?php echo (int)$data['sales_invoices']; ?></td>
                                        <td class="text-center"><?php echo formatNumber($data['sales_qty'], 0); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($data['sales_taxable']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($data['sales_cgst']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($data['sales_sgst']); ?></td>
                                        <td class="text-center"><?php echo (int)$data['purchase_invoices']; ?></td>
                                        <td class="text-center"><?php echo formatNumber($data['purchase_qty'], 0); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($data['purchase_taxable']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($data['purchase_cgst']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($data['purchase_sgst']); ?></td>
                                        <td class="text-end fw-semibold <?php echo $netTaxable >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                            <?php echo formatCurrency($netTaxable); ?>
                                        </td>
                                        <td class="text-end fw-semibold <?php echo $netGST >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                            <?php echo formatCurrency($netGST); ?>
                                        </td>
                                    </tr>
                                <?php 
                                    endforeach; 
                                else: ?>
                                   
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($hsnSummary)): ?>
                            <tfoot class="total-row">
                                <tr>
                                    <th>Totals:</th>
                                    <th class="text-center"><?php echo array_sum(array_column($hsnSummary, 'sales_invoices')); ?></th>
                                    <th class="text-center"><?php echo formatNumber(array_sum(array_column($hsnSummary, 'sales_qty')), 0); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($totalSalesTaxable); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($totalSalesCGST); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($totalSalesSGST); ?></th>
                                    <th class="text-center"><?php echo array_sum(array_column($hsnSummary, 'purchase_invoices')); ?></th>
                                    <th class="text-center"><?php echo formatNumber(array_sum(array_column($hsnSummary, 'purchase_qty')), 0); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($totalPurchaseTaxable); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($totalPurchaseCGST); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($totalPurchaseSGST); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($totalSalesTaxable - $totalPurchaseTaxable); ?></th>
                                    <th class="text-end"><?php echo formatCurrency(($totalSalesCGST + $totalSalesSGST) - ($totalPurchaseCGST + $totalPurchaseSGST)); ?></th>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

            <?php elseif ($reportType === 'summary'): ?>
                <!-- Complete Summary - Tabbed View -->
                <div class="summary-card">
                    <ul class="nav nav-tabs-custom" id="reportTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="monthly-tab" data-bs-toggle="tab" data-bs-target="#monthly" type="button" role="tab">
                                <i class="bi bi-calendar-month"></i> Monthly Summary
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="gst-tab" data-bs-toggle="tab" data-bs-target="#gst" type="button" role="tab">
                                <i class="bi bi-file-earmark-spreadsheet"></i> GST Summary
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales-summary" type="button" role="tab">
                                <i class="bi bi-cart"></i> Sales GST
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="purchase-tab" data-bs-toggle="tab" data-bs-target="#purchase-summary" type="button" role="tab">
                                <i class="bi bi-truck"></i> Purchase GST
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="credit-tab" data-bs-toggle="tab" data-bs-target="#credit-summary" type="button" role="tab">
                                <i class="bi bi-credit-card"></i> GST Credit
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="reportTabsContent">
                        <!-- Monthly Summary Tab -->
                        <div class="tab-pane fade show active" id="monthly" role="tabpanel">
                            <div class="mt-3">
                                <div class="chart-container">
                                    <canvas id="monthlyChart"></canvas>
                                </div>

                                <div class="table-responsive mt-3">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th class="text-center">Total Invoices</th>
                                                <th class="text-center">GST</th>
                                                <th class="text-center">Non-GST</th>
                                                <th class="text-end">Total Sales</th>
                                                <th class="text-end">Taxable</th>
                                                <th class="text-end">CGST</th>
                                                <th class="text-end">SGST</th>
                                                <th class="text-end">Total GST</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $monthlyChartData = [
                                                'labels' => [],
                                                'sales' => [],
                                                'gst' => []
                                            ];
                                            
                                            if ($monthlyResult && $monthlyResult->num_rows > 0):
                                                while ($month = $monthlyResult->fetch_assoc()):
                                                    $monthlyChartData['labels'][] = date('M Y', strtotime($month['month'] . '-01'));
                                                    $monthlyChartData['sales'][] = (float)$month['total_sales'];
                                                    $monthlyChartData['gst'][] = (float)$month['total_cgst'] + (float)$month['total_sgst'];
                                            ?>
                                                <tr>
                                                    <td><strong><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></strong></td>
                                                    <td class="text-center"><?php echo (int)$month['total_invoices']; ?></td>
                                                    <td class="text-center"><?php echo (int)$month['gst_invoices']; ?></td>
                                                    <td class="text-center"><?php echo (int)$month['non_gst_invoices']; ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($month['total_sales']); ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($month['total_taxable']); ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($month['total_cgst']); ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($month['total_sgst']); ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($month['total_cgst'] + $month['total_sgst']); ?></td>
                                                </tr>
                                            <?php 
                                                endwhile;
                                            endif; 
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- GST Summary Tab -->
                        <div class="tab-pane fade" id="gst" role="tabpanel">
                            <div class="mt-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-body">
                                                <h6 class="card-title mb-3">GST Liability Summary</h6>
                                                <table class="table table-sm">
                                                    <tr>
                                                        <td>Total Output GST (Sales)</td>
                                                        <td class="text-end fw-semibold"><?php echo formatCurrency($totalCGST + $totalSGST); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Total Input GST (Purchases)</td>
                                                        <td class="text-end fw-semibold"><?php echo formatCurrency($purchaseCGST + $purchaseSGST); ?></td>
                                                    </tr>
                                                    <tr class="border-top">
                                                        <th>Net GST <?php echo $netGSTPayable >= 0 ? 'Payable' : 'Refundable'; ?></th>
                                                        <th class="text-end <?php echo $netGSTPayable >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                            <?php echo formatCurrency(abs($netGSTPayable)); ?>
                                                        </th>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-body">
                                                <h6 class="card-title mb-3">GST Breakup</h6>
                                                <table class="table table-sm">
                                                    <tr>
                                                        <td>CGST (Sales)</td>
                                                        <td class="text-end"><?php echo formatCurrency($totalCGST); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>SGST (Sales)</td>
                                                        <td class="text-end"><?php echo formatCurrency($totalSGST); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>CGST (Purchases)</td>
                                                        <td class="text-end"><?php echo formatCurrency($purchaseCGST); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>SGST (Purchases)</td>
                                                        <td class="text-end"><?php echo formatCurrency($purchaseSGST); ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sales Summary Tab -->
                        <div class="tab-pane fade" id="sales-summary" role="tabpanel">
                            <div class="mt-3">
                                <?php if ($salesData && $salesData->num_rows > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table-custom">
                                            <thead>
                                                <tr>
                                                    <th>Invoice #</th>
                                                    <th>Date</th>
                                                    <th>Customer</th>
                                                    <th>Taxable</th>
                                                    <th>CGST</th>
                                                    <th>SGST</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $salesData->data_seek(0);
                                                $salesCount = 0;
                                                while ($sale = $salesData->fetch_assoc()):
                                                    if ((int)$sale['is_gst'] === 1 || !$filterGstOnly):
                                                        $salesCount++;
                                                        if ($salesCount > 10) continue;
                                                        $displayCustomer = $sale['master_customer_name'] ?: ($sale['customer_name'] ?: 'Walk-in Customer');
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($sale['inv_num']); ?></td>
                                                        <td><?php echo date('d-m-Y', strtotime($sale['created_at'])); ?></td>
                                                        <td><?php echo htmlspecialchars($displayCustomer); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($sale['taxable']); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($sale['cgst_amount']); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($sale['sgst_amount']); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($sale['total']); ?></td>
                                                    </tr>
                                                <?php 
                                                    endif;
                                                endwhile; 
                                                ?>
                                            </tbody>
                                        </table>
                                        <?php if ($salesCount > 10): ?>
                                            <div class="text-center mt-2">
                                                <a href="?report_type=sales&<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-outline-primary">
                                                    View All Sales
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center text-muted py-3">No sales data available</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Purchase Summary Tab -->
                        <div class="tab-pane fade" id="purchase-summary" role="tabpanel">
                            <div class="mt-3">
                                <?php if ($purchaseData && $purchaseData->num_rows > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table-custom">
                                            <thead>
                                                <tr>
                                                    <th>Purchase #</th>
                                                    <th>Date</th>
                                                    <th>Supplier</th>
                                                    <th>Taxable</th>
                                                    <th>CGST</th>
                                                    <th>SGST</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $purchaseData->data_seek(0);
                                                $purchaseCount = 0;
                                                while ($purchase = $purchaseData->fetch_assoc()):
                                                    $purchaseCount++;
                                                    if ($purchaseCount > 10) continue;
                                                    $taxable = (float)$purchase['total'] - ((float)$purchase['cgst_amount'] + (float)$purchase['sgst_amount']);
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($purchase['purchase_no']); ?></td>
                                                        <td><?php echo date('d-m-Y', strtotime($purchase['purchase_date'])); ?></td>
                                                        <td><?php echo htmlspecialchars($purchase['supplier_name'] ?? 'N/A'); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($taxable); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($purchase['cgst_amount']); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($purchase['sgst_amount']); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($purchase['total']); ?></td>
                                                    </tr>
                                                <?php 
                                                endwhile; 
                                                ?>
                                            </tbody>
                                        </table>
                                        <?php if ($purchaseCount > 10): ?>
                                            <div class="text-center mt-2">
                                                <a href="?report_type=purchase&<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-outline-primary">
                                                    View All Purchases
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center text-muted py-3">No purchase data available</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Credit Summary Tab -->
                        <div class="tab-pane fade" id="credit-summary" role="tabpanel">
                            <div class="mt-3">
                                <?php if ($gstCreditData && $gstCreditData->num_rows > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table-custom">
                                            <thead>
                                                <tr>
                                                    <th>Purchase #</th>
                                                    <th>Date</th>
                                                    <th>Supplier</th>
                                                    <th>CGST Credit</th>
                                                    <th>SGST Credit</th>
                                                    <th>Total Credit</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $gstCreditData->data_seek(0);
                                                $creditCount = 0;
                                                while ($credit = $gstCreditData->fetch_assoc()):
                                                    $creditCount++;
                                                    if ($creditCount > 10) continue;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($credit['purchase_no']); ?></td>
                                                        <td><?php echo date('d-m-Y', strtotime($credit['purchase_date'])); ?></td>
                                                        <td><?php echo htmlspecialchars($credit['supplier_name'] ?? 'N/A'); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($credit['cgst']); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($credit['sgst']); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($credit['total_credit']); ?></td>
                                                    </tr>
                                                <?php 
                                                endwhile; 
                                                ?>
                                            </tbody>
                                        </table>
                                        <?php if ($creditCount > 10): ?>
                                            <div class="text-center mt-2">
                                                <a href="?report_type=gst_credit&<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-outline-primary">
                                                    View All Credits
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center text-muted py-3">No GST credit data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#salesGSTTable, #purchaseGSTTable, #gstCreditTable, #hsnSummaryTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries"
        },
        dom: 'Bfrtip',
        buttons: [
            {
                text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                action: function() {
                    const qs = window.location.search || '';
                    window.location.href = 'export_gst_report.php?format=excel' + (qs ? '&' + qs.substring(1) : '');
                },
                className: 'btn btn-sm btn-outline-success'
            },
            {
                text: '<i class="bi bi-printer"></i> Print',
                extend: 'print',
                className: 'btn btn-sm btn-outline-secondary'
            }
        ]
    });

    // Monthly Chart
    const ctx = document.getElementById('monthlyChart')?.getContext('2d');
    if (ctx) {
        const monthlyData = <?php echo json_encode($monthlyChartData ?? ['labels' => [], 'sales' => [], 'gst' => []]); ?>;
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthlyData.labels,
                datasets: [
                    {
                        label: 'Total Sales (₹)',
                        data: monthlyData.sales,
                        borderColor: '#2463eb',
                        backgroundColor: 'rgba(36, 99, 235, 0.1)',
                        tension: 0.4,
                        yAxisID: 'y'
                    },
                    {
                        label: 'GST Amount (₹)',
                        data: monthlyData.gst,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22, 163, 74, 0.1)',
                        tension: 0.4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Sales Amount (₹)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'GST Amount (₹)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }

    // Date validation
    $('input[name="date_to"]').change(function() {
        const fromDate = $('input[name="date_from"]').val();
        const toDate = $(this).val();
        
        if (fromDate && toDate && toDate < fromDate) {
            alert('To Date cannot be earlier than From Date');
            $(this).val(fromDate);
        }
    });

    $('input[name="date_from"]').change(function() {
        const fromDate = $(this).val();
        const toDate = $('input[name="date_to"]').val();
        
        if (fromDate && toDate && toDate < fromDate) {
            alert('From Date cannot be later than To Date');
            $('input[name="date_to"]').val(fromDate);
        }
    });

    // Auto-submit on filter change
    $('select[name="customer_id"], select[name="supplier_id"], input[name="gst_only"]').change(function() {
        $('#reportForm').submit();
    });

    // Preserve tab on page load
    const hash = window.location.hash;
    if (hash) {
        const tab = document.querySelector(`[data-bs-target="${hash}"]`);
        if (tab) {
            new bootstrap.Tab(tab).show();
        }
    }

    // Update URL hash when tab changes
    const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabEls.forEach(tab => {
        tab.addEventListener('shown.bs.tab', function(e) {
            window.location.hash = e.target.getAttribute('data-bs-target');
        });
    });
});

// Helper function to format numbers with commas
function formatNumber(x, decimals = 2) {
    return parseFloat(x).toFixed(decimals).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}
</script>
</body>
</html>