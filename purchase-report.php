<?php
// purchase-report.php
session_start();
$currentPage = 'purchase-report';
$pageTitle = 'Purchase Report';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view reports
checkRoleAccess(['admin']);

header_remove("X-Powered-By");

// --------------------------
// Helper Functions
// --------------------------
function money2($n) {
    return number_format((float)$n, 2, '.', '');
}

function getMonthName($month) {
    $months = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
    return $months[(int)$month] ?? 'Unknown';
}

// --------------------------
// Get Filter Parameters
// --------------------------
$report_type = $_GET['report_type'] ?? 'summary'; // summary, detailed, monthly, supplier
$from_date = $_GET['from_date'] ?? date('Y-m-01'); // First day of current month
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$item_type = $_GET['item_type'] ?? 'all'; // all, category, product
$payment_status = $_GET['payment_status'] ?? '';
$gst_type = $_GET['gst_type'] ?? '';
$group_by = $_GET['group_by'] ?? 'day'; // day, week, month
$chart_type = $_GET['chart_type'] ?? 'bar'; // bar, line, pie
$export = $_GET['export'] ?? '';

// Build date condition
$date_condition = "DATE(p.purchase_date) BETWEEN '$from_date' AND '$to_date'";

// --------------------------
// Get filter dropdown data
// --------------------------
$suppliers = $conn->query("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name");
$categories = $conn->query("SELECT id, category_name FROM category ORDER BY category_name");
$products = $conn->query("SELECT id, product_name, product_type, primary_unit FROM product ORDER BY product_name");

// --------------------------
// Summary Statistics
// --------------------------
$summary_stats = [];

// Overall summary
$overall_sql = "SELECT 
    COUNT(DISTINCT p.id) as total_purchases,
    COUNT(DISTINCT CASE WHEN pi.product_id IS NOT NULL THEN pi.id ELSE NULL END) as product_items,
    COUNT(DISTINCT CASE WHEN pi.cat_id IS NOT NULL THEN pi.id ELSE NULL END) as category_items,
    COALESCE(SUM(p.total), 0) as total_amount,
    COALESCE(SUM(p.cgst_amount + p.sgst_amount), 0) as total_gst,
    COALESCE(AVG(p.total), 0) as avg_purchase_value,
    COALESCE(SUM(pp.paid_amount), 0) as total_paid,
    COALESCE(SUM(p.total) - SUM(pp.paid_amount), 0) as total_pending
FROM purchase p
LEFT JOIN purchase_item pi ON p.id = pi.purchase_id
LEFT JOIN purchase_payment_history pp ON p.id = pp.purchase_id
WHERE $date_condition";

if ($supplier_id > 0) {
    $overall_sql .= " AND p.supplier_id = $supplier_id";
}

if ($item_type === 'category') {
    $overall_sql .= " AND p.purchase_type = 'category'";
} elseif ($item_type === 'product') {
    $overall_sql .= " AND p.purchase_type = 'product'";
}

$summary_stats['overall'] = $conn->query($overall_sql)->fetch_assoc();

// Payment status breakdown
$status_sql = "SELECT 
    COUNT(CASE WHEN COALESCE(pp.total_paid, 0) >= p.total THEN 1 END) as paid_count,
    COUNT(CASE WHEN COALESCE(pp.total_paid, 0) > 0 AND COALESCE(pp.total_paid, 0) < p.total THEN 1 END) as partial_count,
    COUNT(CASE WHEN COALESCE(pp.total_paid, 0) = 0 THEN 1 END) as unpaid_count,
    COALESCE(SUM(CASE WHEN COALESCE(pp.total_paid, 0) >= p.total THEN p.total END), 0) as paid_amount,
    COALESCE(SUM(CASE WHEN COALESCE(pp.total_paid, 0) > 0 AND COALESCE(pp.total_paid, 0) < p.total THEN p.total END), 0) as partial_amount,
    COALESCE(SUM(CASE WHEN COALESCE(pp.total_paid, 0) = 0 THEN p.total END), 0) as unpaid_amount
FROM purchase p
LEFT JOIN (
    SELECT purchase_id, SUM(paid_amount) as total_paid 
    FROM purchase_payment_history 
    GROUP BY purchase_id
) pp ON p.id = pp.purchase_id
WHERE $date_condition";

if ($supplier_id > 0) {
    $status_sql .= " AND p.supplier_id = $supplier_id";
}

if ($item_type === 'category') {
    $status_sql .= " AND p.purchase_type = 'category'";
} elseif ($item_type === 'product') {
    $status_sql .= " AND p.purchase_type = 'product'";
}

$summary_stats['status'] = $conn->query($status_sql)->fetch_assoc();

// GST type breakdown
$gst_type_sql = "SELECT 
    COUNT(CASE WHEN p.gst_type = 'exclusive' THEN 1 END) as exclusive_count,
    COUNT(CASE WHEN p.gst_type = 'inclusive' THEN 1 END) as inclusive_count,
    COALESCE(SUM(CASE WHEN p.gst_type = 'exclusive' THEN p.total END), 0) as exclusive_amount,
    COALESCE(SUM(CASE WHEN p.gst_type = 'inclusive' THEN p.total END), 0) as inclusive_amount
FROM purchase p
WHERE $date_condition";

if ($supplier_id > 0) {
    $gst_type_sql .= " AND p.supplier_id = $supplier_id";
}

if ($item_type === 'category') {
    $gst_type_sql .= " AND p.purchase_type = 'category'";
} elseif ($item_type === 'product') {
    $gst_type_sql .= " AND p.purchase_type = 'product'";
}

$summary_stats['gst_type'] = $conn->query($gst_type_sql)->fetch_assoc();

// Purchase type breakdown
$type_sql = "SELECT 
    COUNT(CASE WHEN p.purchase_type = 'category' THEN 1 END) as category_count,
    COUNT(CASE WHEN p.purchase_type = 'product' THEN 1 END) as product_count,
    COALESCE(SUM(CASE WHEN p.purchase_type = 'category' THEN p.total END), 0) as category_amount,
    COALESCE(SUM(CASE WHEN p.purchase_type = 'product' THEN p.total END), 0) as product_amount
FROM purchase p
WHERE $date_condition";

if ($supplier_id > 0) {
    $type_sql .= " AND p.supplier_id = $supplier_id";
}

$summary_stats['purchase_type'] = $conn->query($type_sql)->fetch_assoc();

// Top suppliers
$top_suppliers_sql = "SELECT 
    s.id,
    s.supplier_name,
    COUNT(p.id) as purchase_count,
    COUNT(CASE WHEN p.purchase_type = 'category' THEN 1 END) as category_count,
    COUNT(CASE WHEN p.purchase_type = 'product' THEN 1 END) as product_count,
    COALESCE(SUM(p.total), 0) as total_amount,
    COALESCE(AVG(p.total), 0) as avg_amount,
    COALESCE(SUM(pp.paid_amount), 0) as paid_amount
FROM suppliers s
LEFT JOIN purchase p ON s.id = p.supplier_id AND $date_condition
LEFT JOIN purchase_payment_history pp ON p.id = pp.purchase_id
WHERE s.id IS NOT NULL";

if ($supplier_id > 0) {
    $top_suppliers_sql .= " AND s.id = $supplier_id";
}

if ($item_type === 'category') {
    $top_suppliers_sql .= " AND p.purchase_type = 'category'";
} elseif ($item_type === 'product') {
    $top_suppliers_sql .= " AND p.purchase_type = 'product'";
}

$top_suppliers_sql .= " GROUP BY s.id, s.supplier_name
    HAVING purchase_count > 0
    ORDER BY total_amount DESC
    LIMIT 10";

$top_suppliers = $conn->query($top_suppliers_sql);

// --------------------------
// Chart Data
// --------------------------
$chart_data = [];

if ($group_by === 'day') {
    $chart_sql = "SELECT 
        DATE(p.purchase_date) as date_label,
        COUNT(DISTINCT p.id) as purchase_count,
        COUNT(CASE WHEN p.purchase_type = 'category' THEN 1 END) as category_count,
        COUNT(CASE WHEN p.purchase_type = 'product' THEN 1 END) as product_count,
        COALESCE(SUM(p.total), 0) as total_amount,
        COALESCE(SUM(CASE WHEN p.purchase_type = 'category' THEN p.total END), 0) as category_amount,
        COALESCE(SUM(CASE WHEN p.purchase_type = 'product' THEN p.total END), 0) as product_amount,
        COALESCE(SUM(p.cgst_amount + p.sgst_amount), 0) as gst_amount,
        COALESCE(SUM(pp.paid_amount), 0) as paid_amount
    FROM purchase p
    LEFT JOIN purchase_payment_history pp ON p.id = pp.purchase_id
    WHERE $date_condition";
    
    if ($supplier_id > 0) {
        $chart_sql .= " AND p.supplier_id = $supplier_id";
    }
    
    if ($category_id > 0) {
        $chart_sql .= " AND EXISTS (SELECT 1 FROM purchase_item pi WHERE pi.purchase_id = p.id AND pi.cat_id = $category_id)";
    }
    
    if ($product_id > 0) {
        $chart_sql .= " AND EXISTS (SELECT 1 FROM purchase_item pi WHERE pi.purchase_id = p.id AND pi.product_id = $product_id)";
    }
    
    if ($item_type === 'category') {
        $chart_sql .= " AND p.purchase_type = 'category'";
    } elseif ($item_type === 'product') {
        $chart_sql .= " AND p.purchase_type = 'product'";
    }
    
    $chart_sql .= " GROUP BY DATE(p.purchase_date)
        ORDER BY p.purchase_date ASC";
        
} elseif ($group_by === 'week') {
    $chart_sql = "SELECT 
        CONCAT(YEAR(p.purchase_date), '-W', WEEK(p.purchase_date)) as date_label,
        MIN(p.purchase_date) as week_start,
        COUNT(DISTINCT p.id) as purchase_count,
        COUNT(CASE WHEN p.purchase_type = 'category' THEN 1 END) as category_count,
        COUNT(CASE WHEN p.purchase_type = 'product' THEN 1 END) as product_count,
        COALESCE(SUM(p.total), 0) as total_amount,
        COALESCE(SUM(CASE WHEN p.purchase_type = 'category' THEN p.total END), 0) as category_amount,
        COALESCE(SUM(CASE WHEN p.purchase_type = 'product' THEN p.total END), 0) as product_amount,
        COALESCE(SUM(p.cgst_amount + p.sgst_amount), 0) as gst_amount,
        COALESCE(SUM(pp.paid_amount), 0) as paid_amount
    FROM purchase p
    LEFT JOIN purchase_payment_history pp ON p.id = pp.purchase_id
    WHERE $date_condition";
    
    if ($supplier_id > 0) {
        $chart_sql .= " AND p.supplier_id = $supplier_id";
    }
    
    if ($category_id > 0) {
        $chart_sql .= " AND EXISTS (SELECT 1 FROM purchase_item pi WHERE pi.purchase_id = p.id AND pi.cat_id = $category_id)";
    }
    
    if ($product_id > 0) {
        $chart_sql .= " AND EXISTS (SELECT 1 FROM purchase_item pi WHERE pi.purchase_id = p.id AND pi.product_id = $product_id)";
    }
    
    if ($item_type === 'category') {
        $chart_sql .= " AND p.purchase_type = 'category'";
    } elseif ($item_type === 'product') {
        $chart_sql .= " AND p.purchase_type = 'product'";
    }
    
    $chart_sql .= " GROUP BY YEAR(p.purchase_date), WEEK(p.purchase_date)
        ORDER BY week_start ASC";
        
} else { // month
    $chart_sql = "SELECT 
        DATE_FORMAT(p.purchase_date, '%Y-%m') as date_label,
        MONTH(p.purchase_date) as month_num,
        YEAR(p.purchase_date) as year_num,
        COUNT(DISTINCT p.id) as purchase_count,
        COUNT(CASE WHEN p.purchase_type = 'category' THEN 1 END) as category_count,
        COUNT(CASE WHEN p.purchase_type = 'product' THEN 1 END) as product_count,
        COALESCE(SUM(p.total), 0) as total_amount,
        COALESCE(SUM(CASE WHEN p.purchase_type = 'category' THEN p.total END), 0) as category_amount,
        COALESCE(SUM(CASE WHEN p.purchase_type = 'product' THEN p.total END), 0) as product_amount,
        COALESCE(SUM(p.cgst_amount + p.sgst_amount), 0) as gst_amount,
        COALESCE(SUM(pp.paid_amount), 0) as paid_amount
    FROM purchase p
    LEFT JOIN purchase_payment_history pp ON p.id = pp.purchase_id
    WHERE $date_condition";
    
    if ($supplier_id > 0) {
        $chart_sql .= " AND p.supplier_id = $supplier_id";
    }
    
    if ($category_id > 0) {
        $chart_sql .= " AND EXISTS (SELECT 1 FROM purchase_item pi WHERE pi.purchase_id = p.id AND pi.cat_id = $category_id)";
    }
    
    if ($product_id > 0) {
        $chart_sql .= " AND EXISTS (SELECT 1 FROM purchase_item pi WHERE pi.purchase_id = p.id AND pi.product_id = $product_id)";
    }
    
    if ($item_type === 'category') {
        $chart_sql .= " AND p.purchase_type = 'category'";
    } elseif ($item_type === 'product') {
        $chart_sql .= " AND p.purchase_type = 'product'";
    }
    
    $chart_sql .= " GROUP BY YEAR(p.purchase_date), MONTH(p.purchase_date)
        ORDER BY year_num ASC, month_num ASC";
}

$chart_result = $conn->query($chart_sql);
while ($row = $chart_result->fetch_assoc()) {
    if ($group_by === 'month') {
        $row['display_label'] = getMonthName($row['month_num']) . ' ' . $row['year_num'];
    } elseif ($group_by === 'week') {
        $row['display_label'] = 'Week ' . date('W', strtotime($row['week_start'])) . ' (' . date('d M', strtotime($row['week_start'])) . ')';
    } else {
        $row['display_label'] = date('d M Y', strtotime($row['date_label']));
    }
    $chart_data[] = $row;
}

// --------------------------
// Category-wise Purchase Summary
// --------------------------
$category_sql = "SELECT 
    c.id,
    c.category_name,
    c.gram_value,
    COUNT(DISTINCT pi.purchase_id) as purchase_count,
    SUM(pi.qty) as total_pieces,
    SUM(pi.sec_qty) as total_kg,
    COALESCE(AVG(pi.purchase_price), 0) as avg_price_per_piece,
    COALESCE(SUM(pi.total), 0) as total_amount,
    COALESCE(SUM(pi.cgst_amount + pi.sgst_amount), 0) as total_gst
FROM category c
LEFT JOIN purchase_item pi ON c.id = pi.cat_id
LEFT JOIN purchase p ON pi.purchase_id = p.id AND $date_condition AND p.purchase_type = 'category'
WHERE 1=1";

if ($category_id > 0) {
    $category_sql .= " AND c.id = $category_id";
}

if ($supplier_id > 0) {
    $category_sql .= " AND p.supplier_id = $supplier_id";
}

$category_sql .= " GROUP BY c.id, c.category_name, c.gram_value
    HAVING purchase_count > 0
    ORDER BY total_amount DESC";

$category_summary = $conn->query($category_sql);

// --------------------------
// Product-wise Purchase Summary
// --------------------------
$product_sql = "SELECT 
    pr.id,
    pr.product_name,
    pr.product_type,
    pr.primary_unit,
    COUNT(DISTINCT pi.purchase_id) as purchase_count,
    SUM(pi.qty) as total_quantity,
    COALESCE(AVG(pi.purchase_price), 0) as avg_price_per_unit,
    COALESCE(SUM(pi.total), 0) as total_amount,
    COALESCE(SUM(pi.cgst_amount + pi.sgst_amount), 0) as total_gst
FROM product pr
LEFT JOIN purchase_item pi ON pr.id = pi.product_id
LEFT JOIN purchase p ON pi.purchase_id = p.id AND $date_condition AND p.purchase_type = 'product'
WHERE 1=1";

if ($product_id > 0) {
    $product_sql .= " AND pr.id = $product_id";
}

if ($supplier_id > 0) {
    $product_sql .= " AND p.supplier_id = $supplier_id";
}

$product_sql .= " GROUP BY pr.id, pr.product_name, pr.product_type, pr.primary_unit
    HAVING purchase_count > 0
    ORDER BY total_amount DESC";

$product_summary = $conn->query($product_sql);

// --------------------------
// Detailed Purchase Data
// --------------------------
$detailed_sql = "SELECT 
    p.id,
    p.purchase_no,
    p.invoice_num,
    p.purchase_date,
    p.total as purchase_total,
    p.cgst_amount,
    p.sgst_amount,
    p.gst_type,
    p.purchase_type,
    s.supplier_name,
    s.phone as supplier_phone,
    COUNT(DISTINCT pi.id) as item_count,
    COUNT(DISTINCT CASE WHEN pi.cat_id IS NOT NULL THEN pi.id END) as category_item_count,
    COUNT(DISTINCT CASE WHEN pi.product_id IS NOT NULL THEN pi.id END) as product_item_count,
    COALESCE(SUM(pp.paid_amount), 0) as paid_amount
FROM purchase p
LEFT JOIN suppliers s ON p.supplier_id = s.id
LEFT JOIN purchase_item pi ON p.id = pi.purchase_id
LEFT JOIN purchase_payment_history pp ON p.id = pp.purchase_id
WHERE $date_condition";

if ($supplier_id > 0) {
    $detailed_sql .= " AND p.supplier_id = $supplier_id";
}

if ($category_id > 0) {
    $detailed_sql .= " AND EXISTS (SELECT 1 FROM purchase_item pi2 WHERE pi2.purchase_id = p.id AND pi2.cat_id = $category_id)";
}

if ($product_id > 0) {
    $detailed_sql .= " AND EXISTS (SELECT 1 FROM purchase_item pi2 WHERE pi2.purchase_id = p.id AND pi2.product_id = $product_id)";
}

if ($item_type === 'category') {
    $detailed_sql .= " AND p.purchase_type = 'category'";
} elseif ($item_type === 'product') {
    $detailed_sql .= " AND p.purchase_type = 'product'";
}

if (!empty($payment_status)) {
    $detailed_sql .= " HAVING " . ($payment_status === 'paid' ? "paid_amount >= p.total" : 
                      ($payment_status === 'partial' ? "paid_amount > 0 AND paid_amount < p.total" : 
                      "paid_amount = 0"));
}

if (!empty($gst_type)) {
    $detailed_sql .= " AND p.gst_type = '$gst_type'";
}

$detailed_sql .= " GROUP BY p.id
    ORDER BY p.purchase_date DESC";

$detailed_purchases = $conn->query($detailed_sql);

// --------------------------
// Handle Export
// --------------------------
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="purchase_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, ['Date', 'Purchase #', 'Type', 'Supplier', 'Items', 'Category Items', 'Product Items', 'Taxable', 'GST', 'Total', 'Paid', 'Balance', 'Status']);
    
    if ($detailed_purchases && $detailed_purchases->num_rows > 0) {
        $detailed_purchases->data_seek(0);
        while ($row = $detailed_purchases->fetch_assoc()) {
            $gst_total = $row['cgst_amount'] + $row['sgst_amount'];
            $taxable = $row['purchase_total'] - $gst_total;
            $balance = $row['purchase_total'] - $row['paid_amount'];
            $status = $balance <= 0 ? 'Paid' : ($row['paid_amount'] > 0 ? 'Partial' : 'Unpaid');
            $type_label = ($row['purchase_type'] == 'category') ? 'Category' : 'Product';
            
            fputcsv($output, [
                date('Y-m-d', strtotime($row['purchase_date'])),
                $row['purchase_no'],
                $type_label,
                $row['supplier_name'],
                $row['item_count'],
                $row['category_item_count'] ?? 0,
                $row['product_item_count'] ?? 0,
                money2($taxable),
                money2($gst_total),
                money2($row['purchase_total']),
                money2($row['paid_amount']),
                money2($balance),
                $status
            ]);
        }
    }
    
    fclose($output);
    exit;
}

if ($export === 'pdf') {
    // Redirect to PDF generation page
    header("Location: export-purchase-report-pdf.php?" . $_SERVER['QUERY_STRING']);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1e293b;
            --light: #f8fafc;
            --category: #10b981;
            --product: #3b82f6;
        }
        
        /* Report Container */
        .report-container {
            padding: 20px 0;
        }
        
        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #edf2f9;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
        }
        
        .filter-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-title i {
            color: var(--primary);
            font-size: 20px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        
        .filter-item {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            border: 1px solid #edf2f9;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.05);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }
        
        .stat-icon.primary { background: #e8f2ff; color: var(--primary); }
        .stat-icon.success { background: #e3f9f2; color: var(--success); }
        .stat-icon.warning { background: #fff4dd; color: var(--warning); }
        .stat-icon.danger { background: #fee2e2; color: var(--danger); }
        .stat-icon.info { background: #e1f0ff; color: var(--info); }
        .stat-icon.category { background: #e3f9f2; color: var(--category); }
        .stat-icon.product { background: #dbeafe; color: var(--product); }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }
        
        .stat-sub {
            font-size: 13px;
            color: #94a3b8;
            margin-top: 4px;
        }
        
        /* Chart Card */
        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #edf2f9;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .chart-controls {
            display: flex;
            gap: 8px;
        }
        
        .chart-container {
            height: 400px;
            position: relative;
        }
        
        /* Summary Cards */
        .summary-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #edf2f9;
        }
        
        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .summary-header h5 {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }
        
        .summary-badge {
            background: #e8f2ff;
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
        }
        
        /* Mini Stats Grid */
        .mini-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .mini-stat {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
        }
        
        .mini-stat-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 4px;
        }
        
        .mini-stat-value {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .mini-stat-trend {
            font-size: 12px;
            margin-top: 4px;
        }
        
        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }
        
        /* Status Badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-badge.success { background: #e3f9f2; color: #0b5e42; }
        .status-badge.warning { background: #fff4dd; color: #92400e; }
        .status-badge.danger { background: #fee2e2; color: #991b1b; }
        .status-badge.info { background: #e1f0ff; color: #1e40af; }
        .status-badge.category { background: #e3f9f2; color: #10b981; }
        .status-badge.product { background: #dbeafe; color: #3b82f6; }
        
        /* Purchase Type Badges */
        .type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .type-badge.category {
            background: #e3f9f2;
            color: #10b981;
        }
        
        .type-badge.product {
            background: #dbeafe;
            color: #3b82f6;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .report-table th {
            background: #f8fafc;
            padding: 14px 16px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .report-table td {
            padding: 14px 16px;
            font-size: 14px;
            border-bottom: 1px solid #edf2f9;
            color: #334155;
        }
        
        .report-table tbody tr:hover {
            background: #f8fafc;
        }
        
        /* Tab Navigation */
        .report-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .report-tab {
            padding: 10px 20px;
            border-radius: 30px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #64748b;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .report-tab:hover {
            background: #f8fafc;
            color: var(--primary);
            border-color: var(--primary);
        }
        
        .report-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-export {
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-export:hover {
            background: #f8fafc;
            color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-export.csv:hover { color: #10b981; border-color: #10b981; }
        .btn-export.pdf:hover { color: #ef4444; border-color: #ef4444; }
        .btn-export.print:hover { color: #6366f1; border-color: #6366f1; }
        
        /* Item Type Selector */
        .item-type-selector {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            padding: 12px;
            background: #f8fafc;
            border-radius: 12px;
        }
        
        .item-type-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .item-type-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .item-type-option label {
            cursor: pointer;
            margin: 0;
            font-weight: 500;
        }
        
        /* Progress Bar */
        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 8px 0;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--success));
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 300px;
            }
            
            .report-tabs {
                flex-direction: column;
            }
            
            .report-tab {
                width: 100%;
                text-align: center;
            }
            
            .export-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .btn-export {
                width: 100%;
                justify-content: center;
            }
            
            .item-type-selector {
                flex-direction: column;
                gap: 8px;
            }
        }
        
        @media print {
            .sidebar, .topbar, .filter-card, .export-buttons, .report-tabs, .footer {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 20px !important;
            }
            
            .chart-card, .summary-card, .stat-card {
                break-inside: avoid;
                border: 1px solid #ddd;
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
            <div class="report-container">

                <!-- Page Header -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                    <div>
                        <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Purchase Report</h4>
                        <p style="font-size: 14px; color: var(--text-muted); margin: 0;">
                            Analyze purchase data with advanced filters and visualizations
                        </p>
                    </div>
                    <div class="export-buttons">
                        <a href="?<?php echo $_SERVER['QUERY_STRING']; ?>&export=csv" class="btn-export csv">
                            <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                        </a>
                        <button onclick="printReport()" class="btn-export print">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </div>
                </div>

                <!-- Report Type Tabs -->
                <div class="report-tabs">
                    <a href="?report_type=summary&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&item_type=<?php echo $item_type; ?>" 
                       class="report-tab <?php echo $report_type === 'summary' ? 'active' : ''; ?>">
                        <i class="bi bi-pie-chart"></i> Summary Report
                    </a>
                    <a href="?report_type=detailed&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&item_type=<?php echo $item_type; ?>" 
                       class="report-tab <?php echo $report_type === 'detailed' ? 'active' : ''; ?>">
                        <i class="bi bi-table"></i> Detailed Report
                    </a>
                    <a href="?report_type=monthly&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&item_type=<?php echo $item_type; ?>" 
                       class="report-tab <?php echo $report_type === 'monthly' ? 'active' : ''; ?>">
                        <i class="bi bi-calendar-month"></i> Monthly Analysis
                    </a>
                    <a href="?report_type=supplier&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&item_type=<?php echo $item_type; ?>" 
                       class="report-tab <?php echo $report_type === 'supplier' ? 'active' : ''; ?>">
                        <i class="bi bi-truck"></i> Supplier Analysis
                    </a>
                </div>

                <!-- Filter Card -->
                <div class="filter-card">
                    <div class="filter-title">
                        <i class="bi bi-funnel"></i>
                        Filter Report Data
                    </div>
                    
                    <form method="GET" action="purchase-report.php" id="reportForm">
                        <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                        
                        <div class="filter-grid">
                            <div class="filter-item">
                                <span class="filter-label">Date Range</span>
                                <input type="text" class="form-control" id="dateRange" 
                                       placeholder="Select date range"
                                       value="<?php echo $from_date; ?> - <?php echo $to_date; ?>">
                                <input type="hidden" name="from_date" id="from_date" value="<?php echo $from_date; ?>">
                                <input type="hidden" name="to_date" id="to_date" value="<?php echo $to_date; ?>">
                            </div>
                            
                            <div class="filter-item">
                                <span class="filter-label">Supplier</span>
                                <select class="form-select" name="supplier_id" id="supplierSelect">
                                    <option value="">All Suppliers</option>
                                    <?php while ($sup = $suppliers->fetch_assoc()): ?>
                                        <option value="<?php echo $sup['id']; ?>" 
                                            <?php echo $supplier_id == $sup['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['supplier_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <span class="filter-label">Item Type</span>
                                <select class="form-select" name="item_type">
                                    <option value="all" <?php echo $item_type === 'all' ? 'selected' : ''; ?>>All Items</option>
                                    <option value="category" <?php echo $item_type === 'category' ? 'selected' : ''; ?>>Categories (Preforms)</option>
                                    <option value="product" <?php echo $item_type === 'product' ? 'selected' : ''; ?>>Products (Finished Goods)</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <span class="filter-label">Payment Status</span>
                                <select class="form-select" name="payment_status">
                                    <option value="">All Status</option>
                                    <option value="paid" <?php echo $payment_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="partial" <?php echo $payment_status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="unpaid" <?php echo $payment_status === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <span class="filter-label">GST Type</span>
                                <select class="form-select" name="gst_type">
                                    <option value="">All Types</option>
                                    <option value="exclusive" <?php echo $gst_type === 'exclusive' ? 'selected' : ''; ?>>Exclusive</option>
                                    <option value="inclusive" <?php echo $gst_type === 'inclusive' ? 'selected' : ''; ?>>Inclusive</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <span class="filter-label">Group By</span>
                                <select class="form-select" name="group_by">
                                    <option value="day" <?php echo $group_by === 'day' ? 'selected' : ''; ?>>Daily</option>
                                    <option value="week" <?php echo $group_by === 'week' ? 'selected' : ''; ?>>Weekly</option>
                                    <option value="month" <?php echo $group_by === 'month' ? 'selected' : ''; ?>>Monthly</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <span class="filter-label">Chart Type</span>
                                <select class="form-select" name="chart_type" id="chartType">
                                    <option value="bar" <?php echo $chart_type === 'bar' ? 'selected' : ''; ?>>Bar Chart</option>
                                    <option value="line" <?php echo $chart_type === 'line' ? 'selected' : ''; ?>>Line Chart</option>
                                    <option value="pie" <?php echo $chart_type === 'pie' ? 'selected' : ''; ?>>Pie Chart</option>
                                </select>
                            </div>
                            
                            <div class="filter-item d-flex align-items-end">
                                <div class="d-flex gap-2 w-100">
                                    <button type="submit" class="btn btn-primary flex-grow-1">
                                        <i class="bi bi-search"></i> Generate
                                    </button>
                                    <a href="purchase-report.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="bi bi-cart-check"></i>
                        </div>
                        <div class="stat-label">Total Purchases</div>
                        <div class="stat-value"><?php echo number_format($summary_stats['overall']['total_purchases'] ?? 0); ?></div>
                        <div class="stat-sub">
                            <?php echo number_format($summary_stats['overall']['category_items'] ?? 0); ?> Categories | 
                            <?php echo number_format($summary_stats['overall']['product_items'] ?? 0); ?> Products
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="bi bi-currency-rupee"></i>
                        </div>
                        <div class="stat-label">Total Amount</div>
                        <div class="stat-value">₹<?php echo money2($summary_stats['overall']['total_amount'] ?? 0); ?></div>
                        <div class="stat-sub">Avg: ₹<?php echo money2($summary_stats['overall']['avg_purchase_value'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="bi bi-percent"></i>
                        </div>
                        <div class="stat-label">GST Credit</div>
                        <div class="stat-value">₹<?php echo money2($summary_stats['overall']['total_gst'] ?? 0); ?></div>
                        <div class="stat-sub">
                            <?php 
                            $gst_percent = $summary_stats['overall']['total_amount'] > 0 
                                ? ($summary_stats['overall']['total_gst'] / $summary_stats['overall']['total_amount'] * 100) 
                                : 0;
                            echo number_format($gst_percent, 1); ?>% of total
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon info">
                            <i class="bi bi-wallet2"></i>
                        </div>
                        <div class="stat-label">Payment Status</div>
                        <div class="stat-value">₹<?php echo money2($summary_stats['overall']['total_paid'] ?? 0); ?></div>
                        <div class="stat-sub">
                            Pending: ₹<?php echo money2($summary_stats['overall']['total_pending'] ?? 0); ?>
                        </div>
                    </div>
                </div>

                <!-- Purchase Type Stats -->
                <div class="mini-stats-grid">
                    <div class="mini-stat">
                        <div class="mini-stat-label">Category Purchases</div>
                        <div class="mini-stat-value"><?php echo $summary_stats['purchase_type']['category_count'] ?? 0; ?></div>
                        <div class="mini-stat-trend trend-up">
                            ₹<?php echo money2($summary_stats['purchase_type']['category_amount'] ?? 0); ?>
                        </div>
                    </div>
                    
                    <div class="mini-stat">
                        <div class="mini-stat-label">Product Purchases</div>
                        <div class="mini-stat-value"><?php echo $summary_stats['purchase_type']['product_count'] ?? 0; ?></div>
                        <div class="mini-stat-trend trend-up">
                            ₹<?php echo money2($summary_stats['purchase_type']['product_amount'] ?? 0); ?>
                        </div>
                    </div>
                    
                    <div class="mini-stat">
                        <div class="mini-stat-label">Paid</div>
                        <div class="mini-stat-value"><?php echo $summary_stats['status']['paid_count'] ?? 0; ?></div>
                        <div class="mini-stat-trend trend-up">
                            ₹<?php echo money2($summary_stats['status']['paid_amount'] ?? 0); ?>
                        </div>
                    </div>
                    
                    <div class="mini-stat">
                        <div class="mini-stat-label">Partial</div>
                        <div class="mini-stat-value"><?php echo $summary_stats['status']['partial_count'] ?? 0; ?></div>
                        <div class="mini-stat-trend trend-down">
                            ₹<?php echo money2($summary_stats['status']['partial_amount'] ?? 0); ?>
                        </div>
                    </div>
                    
                    <div class="mini-stat">
                        <div class="mini-stat-label">Unpaid</div>
                        <div class="mini-stat-value"><?php echo $summary_stats['status']['unpaid_count'] ?? 0; ?></div>
                        <div class="mini-stat-trend trend-down">
                            ₹<?php echo money2($summary_stats['status']['unpaid_amount'] ?? 0); ?>
                        </div>
                    </div>
                    
                    <div class="mini-stat">
                        <div class="mini-stat-label">GST Exclusive</div>
                        <div class="mini-stat-value"><?php echo $summary_stats['gst_type']['exclusive_count'] ?? 0; ?></div>
                        <div class="mini-stat-trend">
                            ₹<?php echo money2($summary_stats['gst_type']['exclusive_amount'] ?? 0); ?>
                        </div>
                    </div>
                </div>

                <!-- Chart Card -->
                <?php if (!empty($chart_data)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <i class="bi bi-graph-up me-2" style="color: var(--primary);"></i>
                            Purchase Trend Analysis
                        </div>
                        <div class="chart-controls">
                            <button class="btn btn-sm btn-outline-primary" onclick="refreshChart()">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="chart-container">
                        <canvas id="purchaseChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Report Content based on type -->
                <?php if ($report_type === 'summary'): ?>
                    
                    <!-- Top Suppliers Summary -->
                    <?php if ($top_suppliers && $top_suppliers->num_rows > 0): ?>
                    <div class="summary-card">
                        <div class="summary-header">
                            <h5><i class="bi bi-trophy me-2" style="color: var(--warning);"></i>Top Suppliers</h5>
                            <span class="summary-badge">By Purchase Value</span>
                        </div>
                        
                        <div class="table-container">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Supplier</th>
                                        <th class="text-center">Total</th>
                                        <th class="text-center">Category</th>
                                        <th class="text-center">Product</th>
                                        <th class="text-end">Total Amount</th>
                                        <th class="text-end">Average</th>
                                        <th class="text-end">Paid</th>
                                        <th class="text-end">Balance</th>
                                        <th>Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rank = 1;
                                    $total_all = $summary_stats['overall']['total_amount'] ?? 1;
                                    while ($sup = $top_suppliers->fetch_assoc()): 
                                        $balance = $sup['total_amount'] - $sup['paid_amount'];
                                        $share = ($sup['total_amount'] / $total_all) * 100;
                                    ?>
                                        <tr>
                                            <td><span class="badge bg-light text-dark">#<?php echo $rank++; ?></span></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($sup['supplier_name']); ?></td>
                                            <td class="text-center"><?php echo $sup['purchase_count']; ?></td>
                                            <td class="text-center">
                                                <span class="type-badge category"><?php echo $sup['category_count']; ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="type-badge product"><?php echo $sup['product_count']; ?></span>
                                            </td>
                                            <td class="text-end fw-bold">₹<?php echo money2($sup['total_amount']); ?></td>
                                            <td class="text-end">₹<?php echo money2($sup['avg_amount']); ?></td>
                                            <td class="text-end text-success">₹<?php echo money2($sup['paid_amount']); ?></td>
                                            <td class="text-end <?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                                ₹<?php echo money2($balance); ?>
                                            </td>
                                            <td>
                                                <div style="width: 100px;">
                                                    <div class="progress-bar-container">
                                                        <div class="progress-bar-fill" style="width: <?php echo $share; ?>%;"></div>
                                                    </div>
                                                    <small class="text-muted"><?php echo number_format($share, 1); ?>%</small>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Category Summary -->
                    <?php if ($category_summary && $category_summary->num_rows > 0 && ($item_type === 'all' || $item_type === 'category')): ?>
                    <div class="summary-card">
                        <div class="summary-header">
                            <h5><i class="bi bi-layers me-2" style="color: var(--category);"></i>Category-wise Purchase Summary (Preforms)</h5>
                            <span class="summary-badge"><?php echo $category_summary->num_rows; ?> categories</span>
                        </div>
                        
                        <div class="table-container">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-center">Purchases</th>
                                        <th class="text-end">Total KG</th>
                                        <th class="text-end">Total Pieces</th>
                                        <th class="text-end">Avg Price/Piece</th>
                                        <th class="text-end">Total Amount</th>
                                        <th class="text-end">GST</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_cat_amount = 0;
                                    while ($cat = $category_summary->fetch_assoc()): 
                                        $total_cat_amount += $cat['total_amount'];
                                    ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                            <td class="text-center"><?php echo $cat['purchase_count']; ?></td>
                                            <td class="text-end"><?php echo number_format($cat['total_kg'], 2); ?> kg</td>
                                            <td class="text-end"><?php echo number_format($cat['total_pieces']); ?> pcs</td>
                                            <td class="text-end">₹<?php echo money2($cat['avg_price_per_piece']); ?></td>
                                            <td class="text-end fw-bold">₹<?php echo money2($cat['total_amount']); ?></td>
                                            <td class="text-end">₹<?php echo money2($cat['total_gst']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="5" class="text-end">Total:</th>
                                        <th class="text-end">₹<?php echo money2($total_cat_amount); ?></th>
                                        <th class="text-end">-</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Product Summary -->
                    <?php if ($product_summary && $product_summary->num_rows > 0 && ($item_type === 'all' || $item_type === 'product')): ?>
                    <div class="summary-card">
                        <div class="summary-header">
                            <h5><i class="bi bi-box-seam me-2" style="color: var(--product);"></i>Product-wise Purchase Summary (Finished Goods)</h5>
                            <span class="summary-badge"><?php echo $product_summary->num_rows; ?> products</span>
                        </div>
                        
                        <div class="table-container">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Type</th>
                                        <th class="text-center">Purchases</th>
                                        <th class="text-end">Total Quantity</th>
                                        <th class="text-end">Unit</th>
                                        <th class="text-end">Avg Price/Unit</th>
                                        <th class="text-end">Total Amount</th>
                                        <th class="text-end">GST</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_prod_amount = 0;
                                    while ($prod = $product_summary->fetch_assoc()): 
                                        $total_prod_amount += $prod['total_amount'];
                                        $type_label = ($prod['product_type'] == 'direct') ? 'Direct Sale' : 'Converted Sale';
                                        $type_class = ($prod['product_type'] == 'direct') ? 'product' : 'category';
                                    ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($prod['product_name']); ?></td>
                                            <td><span class="type-badge <?php echo $type_class; ?>"><?php echo $type_label; ?></span></td>
                                            <td class="text-center"><?php echo $prod['purchase_count']; ?></td>
                                            <td class="text-end"><?php echo number_format($prod['total_quantity'], 2); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars($prod['primary_unit']); ?></td>
                                            <td class="text-end">₹<?php echo money2($prod['avg_price_per_unit']); ?></td>
                                            <td class="text-end fw-bold">₹<?php echo money2($prod['total_amount']); ?></td>
                                            <td class="text-end">₹<?php echo money2($prod['total_gst']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="6" class="text-end">Total:</th>
                                        <th class="text-end">₹<?php echo money2($total_prod_amount); ?></th>
                                        <th class="text-end">-</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php elseif ($report_type === 'detailed' || $report_type === 'supplier'): ?>

                    <!-- Detailed Purchase List -->
                    <div class="summary-card">
                        <div class="summary-header">
                            <h5>
                                <i class="bi bi-list-check me-2" style="color: var(--primary);"></i>
                                <?php echo $report_type === 'supplier' ? 'Supplier-wise Purchases' : 'Detailed Purchase List'; ?>
                            </h5>
                            <span class="summary-badge">
                                <?php echo $detailed_purchases ? $detailed_purchases->num_rows : 0; ?> records
                            </span>
                        </div>
                        
                        <div class="table-container">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Purchase #</th>
                                        <th>Type</th>
                                        <th>Supplier</th>
                                        <th class="text-center">Items</th>
                                        <th class="text-end">Taxable</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Total</th>
                                        <th class="text-end">Paid</th>
                                        <th class="text-end">Balance</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if ($detailed_purchases && $detailed_purchases->num_rows > 0):
                                        $detailed_purchases->data_seek(0);
                                        while ($row = $detailed_purchases->fetch_assoc()):
                                            $gst_total = $row['cgst_amount'] + $row['sgst_amount'];
                                            $taxable = $row['purchase_total'] - $gst_total;
                                            $balance = $row['purchase_total'] - $row['paid_amount'];
                                            $status = $balance <= 0 ? 'success' : ($row['paid_amount'] > 0 ? 'warning' : 'danger');
                                            $status_text = $balance <= 0 ? 'Paid' : ($row['paid_amount'] > 0 ? 'Partial' : 'Unpaid');
                                            $type_label = ($row['purchase_type'] == 'category') ? 'Category' : 'Product';
                                            $type_class = ($row['purchase_type'] == 'category') ? 'category' : 'product';
                                    ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($row['purchase_date'])); ?></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($row['purchase_no']); ?></td>
                                            <td><span class="type-badge <?php echo $type_class; ?>"><?php echo $type_label; ?></span></td>
                                            <td>
                                                <?php echo htmlspecialchars($row['supplier_name']); ?>
                                                <?php if (!empty($row['gst_type'])): ?>
                                                    <br><small class="text-muted">(<?php echo ucfirst($row['gst_type']); ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo $row['item_count']; ?>
                                                <?php if ($row['category_item_count'] > 0): ?>
                                                    <br><small class="text-success">C:<?php echo $row['category_item_count']; ?></small>
                                                <?php endif; ?>
                                                <?php if ($row['product_item_count'] > 0): ?>
                                                    <small class="text-primary">P:<?php echo $row['product_item_count']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">₹<?php echo money2($taxable); ?></td>
                                            <td class="text-end">₹<?php echo money2($gst_total); ?></td>
                                            <td class="text-end fw-bold">₹<?php echo money2($row['purchase_total']); ?></td>
                                            <td class="text-end">₹<?php echo money2($row['paid_amount']); ?></td>
                                            <td class="text-end <?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                                ₹<?php echo money2($balance); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="view-purchase.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-info" target="_blank">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php 
                                        endwhile;
                                    else: 
                                    ?>
                                        <tr>
                                            <td colspan="12" class="text-center py-4 text-muted">
                                                No purchase records found for the selected criteria
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($report_type === 'monthly'): ?>

                    <!-- Monthly Analysis -->
                    <div class="summary-card">
                        <div class="summary-header">
                            <h5><i class="bi bi-calendar-month me-2" style="color: var(--success);"></i>Monthly Purchase Analysis</h5>
                            <span class="summary-badge"><?php echo count($chart_data); ?> months</span>
                        </div>
                        
                        <?php if (!empty($chart_data)): ?>
                            <div class="table-container">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-center">Total</th>
                                            <th class="text-center">Category</th>
                                            <th class="text-center">Product</th>
                                            <th class="text-end">Category Amount</th>
                                            <th class="text-end">Product Amount</th>
                                            <th class="text-end">Total Amount</th>
                                            <th class="text-end">GST</th>
                                            <th class="text-end">Paid</th>
                                            <th>Trend</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $prev_amount = 0;
                                        foreach ($chart_data as $index => $data): 
                                            $trend = $prev_amount > 0 ? (($data['total_amount'] - $prev_amount) / $prev_amount * 100) : 0;
                                            $trend_class = $trend > 0 ? 'trend-up' : ($trend < 0 ? 'trend-down' : '');
                                            $trend_icon = $trend > 0 ? 'bi-arrow-up' : ($trend < 0 ? 'bi-arrow-down' : 'bi-dash');
                                        ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo $data['display_label']; ?></td>
                                                <td class="text-center"><?php echo $data['purchase_count']; ?></td>
                                                <td class="text-center">
                                                    <span class="type-badge category"><?php echo $data['category_count']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="type-badge product"><?php echo $data['product_count']; ?></span>
                                                </td>
                                                <td class="text-end text-success">₹<?php echo money2($data['category_amount']); ?></td>
                                                <td class="text-end text-primary">₹<?php echo money2($data['product_amount']); ?></td>
                                                <td class="text-end fw-bold">₹<?php echo money2($data['total_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money2($data['gst_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money2($data['paid_amount']); ?></td>
                                                <td>
                                                    <span class="<?php echo $trend_class; ?>">
                                                        <i class="bi <?php echo $trend_icon; ?>"></i>
                                                        <?php echo $trend != 0 ? number_format(abs($trend), 1) . '%' : '-'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php 
                                            $prev_amount = $data['total_amount'];
                                        endforeach; 
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>

                <!-- Report Summary Footer -->
                <div class="text-center text-muted mt-3" style="font-size: 12px;">
                    <i class="bi bi-calendar-range me-1"></i>
                    Report Period: <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?>
                    | Generated on: <?php echo date('d M Y, h:i A'); ?>
                    <?php if ($item_type !== 'all'): ?>
                        | Filter: <?php echo $item_type === 'category' ? 'Category Purchases Only' : 'Product Purchases Only'; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('#supplierSelect, #categorySelect, #productSelect').select2({
        placeholder: 'Select option',
        allowClear: true,
        width: '100%'
    });
    
    // Initialize Date Range Picker
    $('#dateRange').daterangepicker({
        autoUpdateInput: true,
        locale: {
            cancelLabel: 'Clear',
            format: 'YYYY-MM-DD'
        },
        ranges: {
            'Today': [moment(), moment()],
            'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            'Last 7 Days': [moment().subtract(6, 'days'), moment()],
            'This Month': [moment().startOf('month'), moment().endOf('month')],
            'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
            'This Quarter': [moment().startOf('quarter'), moment().endOf('quarter')],
            'This Year': [moment().startOf('year'), moment().endOf('year')],
            'Last Year': [moment().subtract(1, 'year').startOf('year'), moment().subtract(1, 'year').endOf('year')]
        }
    });
    
    $('#dateRange').on('apply.daterangepicker', function(ev, picker) {
        $('#from_date').val(picker.startDate.format('YYYY-MM-DD'));
        $('#to_date').val(picker.endDate.format('YYYY-MM-DD'));
        $('#reportForm').submit();
    });
    
    $('#dateRange').on('cancel.daterangepicker', function(ev, picker) {
        $('#from_date').val('');
        $('#to_date').val('');
        $('#reportForm').submit();
    });
    
    // Auto-submit on filter change
    $('#supplierSelect, #categorySelect, #productSelect, select[name="item_type"], select[name="payment_status"], select[name="gst_type"], select[name="group_by"]').on('change', function() {
        $('#reportForm').submit();
    });
});

// Chart initialization
<?php if (!empty($chart_data)): ?>
document.addEventListener('DOMContentLoaded', function() {
    createChart();
});

function createChart() {
    const ctx = document.getElementById('purchaseChart').getContext('2d');
    const chartType = document.getElementById('chartType').value;
    
    const labels = <?php echo json_encode(array_column($chart_data, 'display_label')); ?>;
    const totalAmounts = <?php echo json_encode(array_map(function($val) { return (float)$val; }, array_column($chart_data, 'total_amount'))); ?>;
    const categoryAmounts = <?php echo json_encode(array_map(function($val) { return (float)$val; }, array_column($chart_data, 'category_amount'))); ?>;
    const productAmounts = <?php echo json_encode(array_map(function($val) { return (float)$val; }, array_column($chart_data, 'product_amount'))); ?>;
    const gstAmounts = <?php echo json_encode(array_map(function($val) { return (float)$val; }, array_column($chart_data, 'gst_amount'))); ?>;
    const paidAmounts = <?php echo json_encode(array_map(function($val) { return (float)$val; }, array_column($chart_data, 'paid_amount'))); ?>;
    
    // Destroy existing chart if it exists
    if (window.purchaseChart instanceof Chart) {
        window.purchaseChart.destroy();
    }
    
    let datasets = [];
    
    if (chartType === 'pie') {
        // For pie chart, show total amounts by period
        datasets = [{
            label: 'Purchase Amount',
            data: totalAmounts,
            backgroundColor: [
                '#4361ee', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
                '#ec4899', '#14b8a6', '#f97316', '#6b7280', '#84cc16'
            ],
            borderWidth: 1
        }];
    } else {
        datasets = [
            {
                label: 'Category Purchases (₹)',
                data: categoryAmounts,
                backgroundColor: 'rgba(16, 185, 129, 0.5)',
                borderColor: '#10b981',
                borderWidth: 2,
                tension: 0.4,
                yAxisID: 'y'
            },
            {
                label: 'Product Purchases (₹)',
                data: productAmounts,
                backgroundColor: 'rgba(59, 130, 246, 0.5)',
                borderColor: '#3b82f6',
                borderWidth: 2,
                tension: 0.4,
                yAxisID: 'y'
            },
            {
                label: 'GST Amount (₹)',
                data: gstAmounts,
                backgroundColor: 'rgba(245, 158, 11, 0.5)',
                borderColor: '#f59e0b',
                borderWidth: 2,
                tension: 0.4,
                yAxisID: 'y'
            },
            {
                label: 'Paid Amount (₹)',
                data: paidAmounts,
                backgroundColor: 'rgba(16, 185, 129, 0.3)',
                borderColor: '#10b981',
                borderWidth: 2,
                tension: 0.4,
                yAxisID: 'y'
            }
        ];
    }
    
    window.purchaseChart = new Chart(ctx, {
        type: chartType,
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: true,
                    text: 'Purchase Analysis by <?php echo ucfirst($group_by); ?>'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += '₹' + context.parsed.y.toLocaleString(undefined, {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });
                            return label;
                        }
                    }
                }
            },
            scales: chartType !== 'pie' ? {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₹' + value.toLocaleString();
                        }
                    }
                }
            } : {}
        }
    });
}

function refreshChart() {
    createChart();
}

document.getElementById('chartType').addEventListener('change', function() {
    createChart();
});
<?php endif; ?>

// Print function
function printReport() {
    window.print();
}

// Export functions
function exportCSV() {
    window.location.href = '?<?php echo $_SERVER['QUERY_STRING']; ?>&export=csv';
}
</script>
</body>
</html>