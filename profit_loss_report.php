<?php
// profit_loss_report.php (BACKEND ONLY)
// Paste this at the top of your profit_loss_report.php before <!DOCTYPE html>

session_start();
$currentPage = 'profit_loss';
$pageTitle = 'Profit & Loss Report';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view reports
checkRoleAccess(['admin', 'sale']);

// -------------------- Helpers --------------------
function formatCurrency($amount) { return '₹' . number_format((float)$amount, 2); }
function formatNumber($number, $decimals = 2) { return number_format((float)$number, $decimals); }
function getProfitMarginClass($margin) {
    if ($margin >= 30) return 'success';
    if ($margin >= 15) return 'info';
    if ($margin >= 5)  return 'warning';
    return 'danger';
}

// Build invoice filters
function buildInvoiceWhere($filterDateFrom, $filterDateTo, $filterCustomer, $filterGstType, &$params, &$types) {
    $where = "1=1";
    $params = [];
    $types  = "";

    if (!empty($filterDateFrom) && !empty($filterDateTo)) {
        $where   .= " AND DATE(i.created_at) BETWEEN ? AND ?";
        $params[] = $filterDateFrom;
        $params[] = $filterDateTo;
        $types   .= "ss";
    }

    if (!empty($filterCustomer) && is_numeric($filterCustomer)) {
        $where   .= " AND i.customer_id = ?";
        $params[] = (int)$filterCustomer;
        $types   .= "i";
    }

    if ($filterGstType === 'gst') {
        $where .= " AND COALESCE(i.is_gst,1) = 1";
    } elseif ($filterGstType === 'non_gst') {
        $where .= " AND COALESCE(i.is_gst,1) = 0";
    }

    return $where;
}

// Build optional date where for other tables
function buildDateWhere($dateField, $from, $to, &$params, &$types) {
    $where = "1=1";
    $params = [];
    $types  = "";
    if (!empty($from) && !empty($to)) {
        $where   .= " AND DATE($dateField) BETWEEN ? AND ?";
        $params[] = $from;
        $params[] = $to;
        $types   .= "ss";
    }
    return $where;
}

// -------------------- Filters --------------------
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to'] ?? '';
$filterCustomer = $_GET['customer_id'] ?? '';
$filterGstType  = $_GET['gst_type'] ?? '';

$success = '';
$error   = '';

// Customers dropdown
$customers = $conn->query("SELECT id, customer_name FROM customers ORDER BY customer_name ASC");

// Invoice where + bind params
$invParams = [];
$invTypes  = "";
$invWhere  = buildInvoiceWhere($filterDateFrom, $filterDateTo, $filterCustomer, $filterGstType, $invParams, $invTypes);

// IMPORTANT: PCS multiplier (use no_of_pcs first; fallback to quantity)
$pcsExpr = "COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)";

// -------------------- 1) SALES + COGS + TAX SUMMARY (PCS BASED) --------------------
$salesSql = "
    SELECT
        COUNT(*) AS invoice_count,
        COALESCE(SUM(inv.total), 0) AS total_sales,
        COALESCE(SUM(inv.subtotal), 0) AS subtotal_sales,
        COALESCE(SUM(inv.cash_received), 0) AS total_collected,
        COALESCE(SUM(inv.pending_amount), 0) AS total_pending,
        COALESCE(SUM(ia.tax), 0) AS total_tax,
        COALESCE(SUM(ia.cogs), 0) AS total_cogs,
        COALESCE(SUM(ia.sales_ex_tax), 0) AS sales_ex_tax
    FROM (
        SELECT i.id, i.total, i.subtotal, i.cash_received, i.pending_amount, i.created_at
        FROM invoice i
        WHERE $invWhere
    ) inv
    LEFT JOIN (
        SELECT
            ii.invoice_id,
            COALESCE(SUM(ii.cgst_amount + ii.sgst_amount), 0) AS tax,
            COALESCE(SUM(ii.total), 0) AS revenue,
            COALESCE(SUM(ii.selling_price * $pcsExpr), 0) AS sales_ex_tax,
            COALESCE(SUM(ii.purchase_price * $pcsExpr), 0) AS cogs
        FROM invoice_item ii
        GROUP BY ii.invoice_id
    ) ia ON ia.invoice_id = inv.id
";

if (!empty($invParams)) {
    $stmt = $conn->prepare($salesSql);
    $stmt->bind_param($invTypes, ...$invParams);
    $stmt->execute();
    $salesStats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $salesStats = $conn->query($salesSql)->fetch_assoc();
}

$totalSales    = (float)($salesStats['total_sales'] ?? 0);
$totalCogs     = (float)($salesStats['total_cogs'] ?? 0);
$grossProfit   = $totalSales - $totalCogs;
$profitMargin  = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0;

// -------------------- 2) PURCHASE SUMMARY (INFO ONLY) --------------------
$purParams = [];
$purTypes  = "";
$purWhere  = buildDateWhere('p.purchase_date', $filterDateFrom, $filterDateTo, $purParams, $purTypes);

$purchaseSql = "
    SELECT
        COUNT(DISTINCT p.id) AS purchase_count,
        COALESCE(SUM(p.total), 0) AS total_purchases,
        COALESCE(SUM(p.paid_amount), 0) AS total_paid
    FROM purchase p
    WHERE $purWhere
";

if (!empty($purParams)) {
    $purchaseStmt = $conn->prepare($purchaseSql);
    $purchaseStmt->bind_param($purTypes, ...$purParams);
    $purchaseStmt->execute();
    $purchaseStats = $purchaseStmt->get_result()->fetch_assoc();
    $purchaseStmt->close();
} else {
    $purchaseStats = $conn->query($purchaseSql)->fetch_assoc();
}

$totalPurchases = (float)($purchaseStats['total_purchases'] ?? 0);

// -------------------- 3) EXPENSE SUMMARY (NET PROFIT) --------------------
$expParams = [];
$expTypes  = "";
$expWhere  = buildDateWhere('e.expense_date', $filterDateFrom, $filterDateTo, $expParams, $expTypes);

$expenseSql = "
    SELECT
        COUNT(*) AS expense_count,
        COALESCE(SUM(e.amount), 0) AS total_expense
    FROM expense e
    WHERE $expWhere
";

if (!empty($expParams)) {
    $expStmt = $conn->prepare($expenseSql);
    $expStmt->bind_param($expTypes, ...$expParams);
    $expStmt->execute();
    $expenseStats = $expStmt->get_result()->fetch_assoc();
    $expStmt->close();
} else {
    $expenseStats = $conn->query($expenseSql)->fetch_assoc();
}

$totalExpense = (float)($expenseStats['total_expense'] ?? 0);
$netProfit    = $grossProfit - $totalExpense;
$netMargin    = $totalSales > 0 ? ($netProfit / $totalSales) * 100 : 0;

// -------------------- 4) PROFIT BY CATEGORY (PCS BASED) --------------------
$categoryProfitSql = "
    SELECT
        c.id,
        c.category_name,
        COALESCE(c.purchase_price,0) AS purchase_price,
        COUNT(DISTINCT i.id) AS invoice_count,
        COALESCE(SUM($pcsExpr), 0) AS total_pcs_sold,
        COALESCE(SUM(ii.selling_price * $pcsExpr), 0) AS total_sales_value,
        COALESCE(SUM(ii.purchase_price * $pcsExpr), 0) AS total_cost_value,
        COALESCE(SUM(ii.total), 0) AS total_revenue,
        COALESCE(SUM(ii.total) - SUM(ii.purchase_price * $pcsExpr), 0) AS gross_profit
    FROM invoice_item ii
    JOIN invoice i ON i.id = ii.invoice_id
    JOIN category c ON c.id = ii.cat_id
    WHERE $invWhere
    GROUP BY c.id, c.category_name, c.purchase_price
    HAVING total_pcs_sold > 0
    ORDER BY gross_profit DESC
";

if (!empty($invParams)) {
    $categoryStmt = $conn->prepare($categoryProfitSql);
    $categoryStmt->bind_param($invTypes, ...$invParams);
    $categoryStmt->execute();
    $categoryProfit = $categoryStmt->get_result();
    $categoryStmt->close();
} else {
    $categoryProfit = $conn->query($categoryProfitSql);
}

// -------------------- 5) PROFIT BY PRODUCT (PCS BASED) --------------------
$productProfitSql = "
    SELECT
        p.id,
        p.product_name,
        p.hsn_code,
        COUNT(DISTINCT i.id) AS invoice_count,
        COALESCE(SUM($pcsExpr), 0) AS total_pcs_sold,
        COALESCE(AVG(ii.selling_price), 0) AS avg_selling_price,
        COALESCE(SUM(ii.total), 0) AS total_revenue,
        COALESCE(SUM(ii.total) - SUM(ii.purchase_price * $pcsExpr), 0) AS estimated_profit
    FROM invoice_item ii
    JOIN invoice i ON i.id = ii.invoice_id
    JOIN product p ON p.id = ii.product_id
    WHERE $invWhere
    GROUP BY p.id, p.product_name, p.hsn_code
    HAVING total_pcs_sold > 0
    ORDER BY estimated_profit DESC
";

if (!empty($invParams)) {
    $productStmt = $conn->prepare($productProfitSql);
    $productStmt->bind_param($invTypes, ...$invParams);
    $productStmt->execute();
    $productProfit = $productStmt->get_result();
    $productStmt->close();
} else {
    $productProfit = $conn->query($productProfitSql);
}

// -------------------- 6) PROFIT BY CUSTOMER (PCS BASED) --------------------
$customerProfitSql = "
    SELECT
        cu.id,
        cu.customer_name,
        cu.phone,
        COUNT(DISTINCT i.id) AS invoice_count,
        COALESCE(SUM(i.total), 0) AS total_purchases,
        COALESCE(SUM(i.pending_amount), 0) AS pending_amount,
        COALESCE(SUM(ii.total) - SUM(ii.purchase_price * $pcsExpr), 0) AS estimated_profit
    FROM customers cu
    JOIN invoice i ON i.customer_id = cu.id
    JOIN invoice_item ii ON ii.invoice_id = i.id
    WHERE $invWhere
    GROUP BY cu.id, cu.customer_name, cu.phone
    HAVING invoice_count > 0
    ORDER BY estimated_profit DESC
";

if (!empty($invParams)) {
    $custStmt = $conn->prepare($customerProfitSql);
    $custStmt->bind_param($invTypes, ...$invParams);
    $custStmt->execute();
    $customerProfit = $custStmt->get_result();
    $custStmt->close();
} else {
    $customerProfit = $conn->query($customerProfitSql);
}

// -------------------- 7) MONTHLY TREND (PCS BASED) --------------------
$trendSql = "
    SELECT
        DATE_FORMAT(i.created_at, '%Y-%m') AS period,
        COUNT(DISTINCT i.id) AS invoice_count,
        COALESCE(SUM(i.total), 0) AS sales,
        COALESCE(SUM(i.subtotal), 0) AS subtotal,
        COALESCE(SUM(ii.cgst_amount + ii.sgst_amount), 0) AS tax,
        COALESCE(SUM(ii.purchase_price * $pcsExpr), 0) AS cogs,
        COALESCE(SUM(ii.total) - SUM(ii.purchase_price * $pcsExpr), 0) AS profit
    FROM invoice i
    JOIN invoice_item ii ON ii.invoice_id = i.id
    WHERE $invWhere
    GROUP BY period
    ORDER BY MIN(i.created_at) DESC
";

if (!empty($invParams)) {
    $trendStmt = $conn->prepare($trendSql);
    $trendStmt->bind_param($invTypes, ...$invParams);
    $trendStmt->execute();
    $trendData = $trendStmt->get_result();
    $trendStmt->close();
} else {
    $trendData = $conn->query($trendSql);
}

// -------------------- 8) GST SUMMARY (CREDIT vs COLLECTED) --------------------
// Credit: from gst_credit_table (optionally filter by purchase_date range)
$gstCreditSql = "
    SELECT COALESCE(SUM(gct.cgst + gct.sgst), 0) AS total_gst_credit
    FROM gst_credit_table gct
    LEFT JOIN purchase p ON p.id = gct.purchase_id
    WHERE 1=1
";
$gstCreditParams = [];
$gstCreditTypes  = "";
if (!empty($filterDateFrom) && !empty($filterDateTo)) {
    $gstCreditSql .= " AND DATE(p.purchase_date) BETWEEN ? AND ?";
    $gstCreditParams = [$filterDateFrom, $filterDateTo];
    $gstCreditTypes  = "ss";
}
if (!empty($gstCreditParams)) {
    $gstCStmt = $conn->prepare($gstCreditSql);
    $gstCStmt->bind_param($gstCreditTypes, ...$gstCreditParams);
    $gstCStmt->execute();
    $gstCredit = $gstCStmt->get_result()->fetch_assoc();
    $gstCStmt->close();
} else {
    $gstCredit = $conn->query($gstCreditSql)->fetch_assoc();
}
$totalGstCredit = (float)($gstCredit['total_gst_credit'] ?? 0);

// Collected: from invoice items filtered
$gstCollectedSql = "
    SELECT COALESCE(SUM(ii.cgst_amount + ii.sgst_amount), 0) AS total_gst_collected
    FROM invoice_item ii
    JOIN invoice i ON i.id = ii.invoice_id
    WHERE $invWhere
";
if (!empty($invParams)) {
    $gstColStmt = $conn->prepare($gstCollectedSql);
    $gstColStmt->bind_param($invTypes, ...$invParams);
    $gstColStmt->execute();
    $gstCollected = $gstColStmt->get_result()->fetch_assoc();
    $gstColStmt->close();
} else {
    $gstCollected = $conn->query($gstCollectedSql)->fetch_assoc();
}
$totalGstCollected = (float)($gstCollected['total_gst_collected'] ?? 0);

$netGstPayable = $totalGstCollected - $totalGstCredit;

// -------------------- 9) TOP PROFITABLE ITEMS (PCS BASED) --------------------
$topItemsSql = "
    SELECT
        ii.product_name AS item_name,
        ii.hsn,
        ii.cat_name AS category_name,
        SUM($pcsExpr) AS pcs_sold,
        AVG(ii.selling_price) AS avg_price,
        SUM(ii.total) AS revenue,
        SUM(ii.purchase_price * $pcsExpr) AS cost,
        SUM(ii.total) - SUM(ii.purchase_price * $pcsExpr) AS profit,
        CASE
            WHEN SUM(ii.purchase_price * $pcsExpr) > 0
            THEN (SUM(ii.total) - SUM(ii.purchase_price * $pcsExpr)) / SUM(ii.purchase_price * $pcsExpr) * 100
            ELSE 0
        END AS profit_percentage
    FROM invoice_item ii
    JOIN invoice i ON i.id = ii.invoice_id
    WHERE $invWhere
    GROUP BY ii.product_name, ii.hsn, ii.cat_name
    HAVING revenue > 0
    ORDER BY profit DESC
    LIMIT 20
";

if (!empty($invParams)) {
    $topStmt = $conn->prepare($topItemsSql);
    $topStmt->bind_param($invTypes, ...$invParams);
    $topStmt->execute();
    $topItems = $topStmt->get_result();
    $topStmt->close();
} else {
    $topItems = $conn->query($topItemsSql);
}

// -------------------- 10) LOSS ITEMS (PCS BASED) --------------------
$lossItemsSql = "
    SELECT
        ii.product_name AS item_name,
        ii.hsn,
        ii.cat_name AS category_name,
        SUM($pcsExpr) AS pcs_sold,
        AVG(ii.selling_price) AS avg_price,
        SUM(ii.total) AS revenue,
        SUM(ii.purchase_price * $pcsExpr) AS cost,
        SUM(ii.total) - SUM(ii.purchase_price * $pcsExpr) AS profit
    FROM invoice_item ii
    JOIN invoice i ON i.id = ii.invoice_id
    WHERE $invWhere
    GROUP BY ii.product_name, ii.hsn, ii.cat_name
    HAVING profit < 0
    ORDER BY profit ASC
    LIMIT 10
";

if (!empty($invParams)) {
    $lossStmt = $conn->prepare($lossItemsSql);
    $lossStmt->bind_param($invTypes, ...$invParams);
    $lossStmt->execute();
    $lossItems = $lossStmt->get_result();
    $lossStmt->close();
} else {
    $lossItems = $conn->query($lossItemsSql);
}

// ---- END BACKEND ----
// Your HTML UI starts after this.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
    <style>
        .profit-loss-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 24px;
        }

        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
            height: 100%;
        }

        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .summary-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .summary-icon.profit { background: #dcfce7; color: #166534; }
        .summary-icon.loss { background: #fee2e2; color: #dc2626; }
        .summary-icon.sales { background: #dbeafe; color: #1e40af; }
        .summary-icon.purchase { background: #fff3cd; color: #856404; }

        .summary-value {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .summary-label { font-size: 14px; color: #64748b; }

        .profit-positive { color: #16a34a; font-weight: 600; }
        .profit-negative { color: #dc2626; font-weight: 600; }

        .margin-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }

        .margin-badge.success { background: #dcfce7; color: #166534; }
        .margin-badge.info { background: #dbeafe; color: #1e40af; }
        .margin-badge.warning { background: #fef9c3; color: #854d0e; }
        .margin-badge.danger { background: #fee2e2; color: #dc2626; }

        .profit-bar {
            height: 6px;
            border-radius: 3px;
            background: #e2e8f0;
            margin-top: 8px;
            overflow: hidden;
        }

        .profit-bar-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
        .profit-bar-fill.positive { background: #16a34a; }
        .profit-bar-fill.negative { background: #dc2626; }

        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #eef2f6;
        }

        .stat-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
            border: 1px solid #eef2f6;
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

        .nav-tabs-custom .nav-link:hover { background: #f8fafc; color: #1e293b; }

        .nav-tabs-custom .nav-link.active {
            color: #2463eb;
            background: white;
            border-bottom: 3px solid #2463eb;
        }

        .table-profit { background: white; border-radius: 12px; overflow: hidden; }
        .table-profit th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 13px;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
        }
        .table-profit td { font-size: 13px; vertical-align: middle; }

        .profit-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-left: 6px;
        }
        .profit-indicator.positive { background: #16a34a; }
        .profit-indicator.negative { background: #dc2626; }

        .btn-group-custom { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-group-custom .btn { padding: 8px 16px; font-size: 13px; border-radius: 8px; }

        @media print {
            .no-print { display: none !important; }
            .summary-card { border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <!-- Header -->
            <div class="profit-loss-header no-print">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold mb-2" style="color:white;">Profit & Loss Report</h4>
                        <p style="color: rgba(255,255,255,0.9); margin: 0;">
                            <i class="bi bi-calendar-range me-1"></i>
                            <?php
                            if (!empty($filterDateFrom) && !empty($filterDateTo)) {
                                echo date('d M Y', strtotime($filterDateFrom)) . ' - ' . date('d M Y', strtotime($filterDateTo));
                            } else {
                                echo 'All Time';
                            }
                            ?>
                        </p>
                    </div>
                    <div class="btn-group-custom">
                        <a href="export_profit_loss.php?<?php echo htmlspecialchars($_SERVER['QUERY_STRING'] ?? ''); ?>" class="btn btn-light">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                        </a>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section no-print">
                <form method="GET" action="profit_loss_report.php" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">From Date <small class="text-muted">(Optional)</small></label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">To Date <small class="text-muted">(Optional)</small></label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filterDateTo); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-select">
                            <option value="">All Customers</option>
                            <?php if ($customers): ?>
                                <?php while ($cust = $customers->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$cust['id']; ?>" <?php echo ((string)$filterCustomer === (string)$cust['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cust['customer_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Invoice Type</label>
                        <select name="gst_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="gst" <?php echo $filterGstType === 'gst' ? 'selected' : ''; ?>>GST Invoices</option>
                            <option value="non_gst" <?php echo $filterGstType === 'non_gst' ? 'selected' : ''; ?>>Non-GST Invoices</option>
                        </select>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-1"></i> Generate Report
                        </button>
                        <a href="profit_loss_report.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle me-1"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Summary Cards -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="summary-card">
                        <div class="summary-icon sales"><i class="bi bi-cart-check"></i></div>
                        <div class="summary-value"><?php echo formatCurrency($totalSales); ?></div>
                        <div class="summary-label">Total Sales (Invoice Total)</div>
                        <div class="d-flex justify-content-between mt-2">
                            <small class="text-muted">Invoices: <?php echo (int)($salesStats['invoice_count'] ?? 0); ?></small>
                            <small class="text-muted">GST: <?php echo formatCurrency($salesStats['total_tax'] ?? 0); ?></small>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="summary-card">
                        <div class="summary-icon purchase"><i class="bi bi-box-seam"></i></div>
                        <div class="summary-value"><?php echo formatCurrency($totalCogs); ?></div>
                        <div class="summary-label">COGS (Sold Cost)</div>
                        <div class="d-flex justify-content-between mt-2">
                            <small class="text-muted">Purchases (Info): <?php echo formatCurrency($totalPurchases); ?></small>
                            <small class="text-muted">Expenses: <?php echo formatCurrency($totalExpense); ?></small>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="summary-card">
                        <div class="summary-icon <?php echo $grossProfit >= 0 ? 'profit' : 'loss'; ?>">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div class="summary-value <?php echo $grossProfit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php echo formatCurrency($grossProfit); ?>
                        </div>
                        <div class="summary-label">Gross Profit (Sales - COGS)</div>
                        <div class="profit-bar">
                            <div class="profit-bar-fill <?php echo $grossProfit >= 0 ? 'positive' : 'negative'; ?>"
                                 style="width: <?php echo min(abs($profitMargin), 100); ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                            <small class="text-muted">Margin: <?php echo formatNumber($profitMargin, 1); ?>%</small>
                            <span class="margin-badge <?php echo getProfitMarginClass($profitMargin); ?>">
                                <?php echo $profitMargin >= 30 ? 'High' : ($profitMargin >= 15 ? 'Medium' : ($profitMargin >= 5 ? 'Low' : 'Critical')); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="summary-card">
                        <div class="summary-icon <?php echo $netProfit >= 0 ? 'profit' : 'loss'; ?>">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                        <div class="summary-value <?php echo $netProfit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php echo formatCurrency($netProfit); ?>
                        </div>
                        <div class="summary-label">Net Profit (Gross - Expenses)</div>
                        <div class="d-flex justify-content-between mt-2">
                            <small class="text-muted">Net Margin: <?php echo formatNumber($netMargin, 1); ?>%</small>
                            <small class="text-muted">Collected: <?php echo formatCurrency($salesStats['total_collected'] ?? 0); ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- GST Summary -->
            <?php if ($totalGstCredit > 0 || $totalGstCollected > 0): ?>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="stat-card">
                            <h6 class="mb-2"><i class="bi bi-receipt-cutoff me-2"></i>GST Summary</h6>
                            <div class="d-flex flex-wrap gap-4">
                                <div>
                                    <small class="text-muted d-block">GST Credit (Purchases)</small>
                                    <span class="fw-semibold"><?php echo formatCurrency($totalGstCredit); ?></span>
                                </div>
                                <div>
                                    <small class="text-muted d-block">GST Collected (Sales)</small>
                                    <span class="fw-semibold"><?php echo formatCurrency($totalGstCollected); ?></span>
                                </div>
                                <div>
                                    <small class="text-muted d-block">Net GST Payable</small>
                                    <span class="fw-semibold <?php echo $netGstPayable > 0 ? 'profit-negative' : 'profit-positive'; ?>">
                                        <?php echo formatCurrency($netGstPayable); ?>
                                    </span>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">
                                Note: GST credit is filtered by purchase date (if date range selected). GST collected follows invoice filters.
                            </small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <ul class="nav nav-tabs-custom no-print" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary" type="button" role="tab">
                        <i class="bi bi-pie-chart me-1"></i> Summary
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">
                        <i class="bi bi-tags me-1"></i> By Category
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">
                        <i class="bi bi-box me-1"></i> By Product
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="customers-tab" data-bs-toggle="tab" data-bs-target="#customers" type="button" role="tab">
                        <i class="bi bi-people me-1"></i> By Customer
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="items-tab" data-bs-toggle="tab" data-bs-target="#items" type="button" role="tab">
                        <i class="bi bi-list-ul me-1"></i> Top/Loss Items
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="reportTabsContent">

                <!-- Summary -->
                <div class="tab-pane fade show active" id="summary" role="tabpanel">
                    <div class="dashboard-card">
                        <div class="table-responsive">
                            <table class="table-profit table" id="summaryTable">
                                <thead>
                                    <tr>
                                        <th>Period</th>
                                        <th class="text-center">Invoices</th>
                                        <th class="text-end">Subtotal</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">COGS</th>
                                        <th class="text-end">Profit/Loss</th>
                                        <th class="text-end">Margin %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($trendData && $trendData->num_rows > 0): ?>
                                        <?php while ($row = $trendData->fetch_assoc()):
                                            $profit = (float)($row['profit'] ?? 0);
                                            $sales  = (float)($row['sales'] ?? 0);
                                            $margin = $sales > 0 ? ($profit / $sales) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($row['period']); ?></strong></td>
                                                <td class="text-center"><?php echo (int)($row['invoice_count'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($row['subtotal'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($row['tax'] ?? 0); ?></td>
                                                <td class="text-end fw-semibold"><?php echo formatCurrency($sales); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($row['cogs'] ?? 0); ?></td>
                                                <td class="text-end <?php echo $profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                                    <?php echo formatCurrency($profit); ?>
                                                    <span class="profit-indicator <?php echo $profit >= 0 ? 'positive' : 'negative'; ?>"></span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="margin-badge <?php echo getProfitMarginClass($margin); ?>">
                                                        <?php echo formatNumber($margin, 1); ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="8" class="text-center text-muted py-4">No data found for selected filters.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="4" class="text-end">Total:</th>
                                        <th class="text-end"><?php echo formatCurrency($totalSales); ?></th>
                                        <th class="text-end"><?php echo formatCurrency($totalCogs); ?></th>
                                        <th class="text-end <?php echo $grossProfit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                            <?php echo formatCurrency($grossProfit); ?>
                                        </th>
                                        <th class="text-end">
                                            <span class="margin-badge <?php echo getProfitMarginClass($profitMargin); ?>">
                                                <?php echo formatNumber($profitMargin, 1); ?>%
                                            </span>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Categories -->
                <div class="tab-pane fade" id="categories" role="tabpanel">
                    <div class="dashboard-card">
                        <div class="table-responsive">
                            <table class="table-profit table" id="categoriesTable">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-center">Invoices</th>
                                        <th class="text-end">Qty Sold</th>
                                        <th class="text-end">Purchase Price</th>
                                        <th class="text-end">Sales Value</th>
                                        <th class="text-end">Cost Value</th>
                                        <th class="text-end">Revenue</th>
                                        <th class="text-end">Gross Profit</th>
                                        <th class="text-end">Margin %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($categoryProfit && $categoryProfit->num_rows > 0): ?>
                                        <?php while ($cat = $categoryProfit->fetch_assoc()):
                                            $revenue = (float)($cat['total_revenue'] ?? 0);
                                            $cost    = (float)($cat['total_cost_value'] ?? 0);
                                            $profit  = $revenue - $cost;
                                            $margin  = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($cat['category_name']); ?></strong></td>
                                                <td class="text-center"><?php echo (int)($cat['invoice_count'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo formatNumber($cat['total_qty_sold'] ?? 0, 0); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($cat['purchase_price'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($cat['total_sales_value'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($cost); ?></td>
                                                <td class="text-end fw-semibold"><?php echo formatCurrency($revenue); ?></td>
                                                <td class="text-end <?php echo $profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                                    <?php echo formatCurrency($profit); ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="margin-badge <?php echo getProfitMarginClass($margin); ?>">
                                                        <?php echo formatNumber($margin, 1); ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="9" class="text-center text-muted py-4">No category data found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Products -->
                <div class="tab-pane fade" id="products" role="tabpanel">
                    <div class="dashboard-card">
                        <div class="table-responsive">
                            <table class="table-profit table" id="productsTable">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>HSN</th>
                                        <th class="text-center">Invoices</th>
                                        <th class="text-end">Qty Sold</th>
                                        <th class="text-end">Avg Price</th>
                                        <th class="text-end">Revenue</th>
                                        <th class="text-end">Est. Profit</th>
                                        <th class="text-end">Margin %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($productProfit && $productProfit->num_rows > 0): ?>
                                        <?php while ($prod = $productProfit->fetch_assoc()):
                                            $revenue = (float)($prod['total_revenue'] ?? 0);
                                            $profit  = (float)($prod['estimated_profit'] ?? 0);
                                            $margin  = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($prod['product_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($prod['hsn_code'] ?: '-'); ?></td>
                                                <td class="text-center"><?php echo (int)($prod['invoice_count'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo formatNumber($prod['total_qty_sold'] ?? 0, 0); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($prod['avg_selling_price'] ?? 0); ?></td>
                                                <td class="text-end fw-semibold"><?php echo formatCurrency($revenue); ?></td>
                                                <td class="text-end <?php echo $profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                                    <?php echo formatCurrency($profit); ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="margin-badge <?php echo getProfitMarginClass($margin); ?>">
                                                        <?php echo formatNumber($margin, 1); ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="8" class="text-center text-muted py-4">No product data found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Customers -->
                <div class="tab-pane fade" id="customers" role="tabpanel">
                    <div class="dashboard-card">
                        <div class="table-responsive">
                            <table class="table-profit table" id="customersTable">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Phone</th>
                                        <th class="text-center">Invoices</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">Pending</th>
                                        <th class="text-end">Est. Profit</th>
                                        <th class="text-end">Margin %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($customerProfit && $customerProfit->num_rows > 0): ?>
                                        <?php while ($cust = $customerProfit->fetch_assoc()):
                                            $purchases = (float)($cust['total_purchases'] ?? 0);
                                            $profit    = (float)($cust['estimated_profit'] ?? 0);
                                            $margin    = $purchases > 0 ? ($profit / $purchases) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($cust['customer_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($cust['phone'] ?: '-'); ?></td>
                                                <td class="text-center"><?php echo (int)($cust['invoice_count'] ?? 0); ?></td>
                                                <td class="text-end fw-semibold"><?php echo formatCurrency($purchases); ?></td>
                                                <td class="text-end <?php echo ((float)($cust['pending_amount'] ?? 0)) > 0 ? 'profit-negative' : ''; ?>">
                                                    <?php echo formatCurrency($cust['pending_amount'] ?? 0); ?>
                                                </td>
                                                <td class="text-end <?php echo $profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                                    <?php echo formatCurrency($profit); ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="margin-badge <?php echo getProfitMarginClass($margin); ?>">
                                                        <?php echo formatNumber($margin, 1); ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="text-center text-muted py-4">No customer data found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Items -->
                <div class="tab-pane fade" id="items" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="dashboard-card h-100">
                                <h6 class="mb-3"><i class="bi bi-trophy text-warning me-2"></i>Top Profitable Items</h6>
                                <div class="table-responsive">
                                    <table class="table-profit table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th>Category</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Revenue</th>
                                                <th class="text-end">Profit</th>
                                                <th class="text-end">Margin</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($topItems && $topItems->num_rows > 0): ?>
                                                <?php while ($item = $topItems->fetch_assoc()):
                                                    if ((float)($item['profit'] ?? 0) <= 0) continue;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($item['category_name'] ?: '-'); ?></td>
                                                        <td class="text-end"><?php echo formatNumber($item['qty_sold'] ?? 0, 0); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($item['revenue'] ?? 0); ?></td>
                                                        <td class="text-end profit-positive"><?php echo formatCurrency($item['profit'] ?? 0); ?></td>
                                                        <td class="text-end">
                                                            <span class="margin-badge success">
                                                                <?php echo formatNumber($item['profit_percentage'] ?? 0, 1); ?>%
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr><td colspan="6" class="text-center text-muted py-4">No data.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="dashboard-card h-100">
                                <h6 class="mb-3"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Loss Making Items</h6>
                                <div class="table-responsive">
                                    <table class="table-profit table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th>Category</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Revenue</th>
                                                <th class="text-end">Loss</th>
                                                <th class="text-end">Margin</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($lossItems && $lossItems->num_rows > 0): ?>
                                                <?php while ($item = $lossItems->fetch_assoc()):
                                                    $rev = (float)($item['revenue'] ?? 0);
                                                    $loss = (float)($item['profit'] ?? 0); // negative
                                                    $margin = $rev > 0 ? (abs($loss) / $rev) * 100 : 0;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($item['category_name'] ?: '-'); ?></td>
                                                        <td class="text-end"><?php echo formatNumber($item['qty_sold'] ?? 0, 0); ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($rev); ?></td>
                                                        <td class="text-end profit-negative"><?php echo formatCurrency(abs($loss)); ?></td>
                                                        <td class="text-end">
                                                            <span class="margin-badge danger"><?php echo formatNumber($margin, 1); ?>%</span>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr><td colspan="6" class="text-center text-muted py-4">No loss items found.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="text-muted text-end small">
                        <i class="bi bi-clock me-1"></i> Report generated on <?php echo date('d M Y h:i A'); ?>
                    </div>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    $('#summaryTable, #categoriesTable, #productsTable, #customersTable').DataTable({
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
                    window.location.href = 'export_profit_loss.php?format=excel' + (qs ? '&' + qs.substring(1) : '');
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

    // Tab persistence
    const activeTab = localStorage.getItem('activeProfitLossTab');
    if (activeTab) {
        $(`#reportTabs button[data-bs-target="${activeTab}"]`).tab('show');
    }
    $('#reportTabs button').on('shown.bs.tab', function(e) {
        localStorage.setItem('activeProfitLossTab', $(e.target).data('bs-target'));
    });
});

// Date validation
function validateDates() {
    const fromDate = $('input[name="date_from"]').val();
    const toDate = $('input[name="date_to"]').val();
    if (fromDate && toDate && toDate < fromDate) {
        alert('To Date cannot be earlier than From Date');
        return false;
    }
    return true;
}
$('form').submit(function(e) {
    if (!validateDates()) e.preventDefault();
});
</script>
</body>
</html>