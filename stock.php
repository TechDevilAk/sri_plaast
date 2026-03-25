<?php
session_start();
$currentPage = 'reports';
$pageTitle = 'Stock Report';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view reports
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Date filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'summary';
$category_filter = $_GET['category_id'] ?? 'all';
$stock_status = $_GET['stock_status'] ?? 'all';

// Get all categories for filter
$categories = $conn->query("SELECT id, category_name FROM category ORDER BY category_name ASC");

// Build category filter for queries
$category_where = "";
if ($category_filter !== 'all') {
    $category_where = " AND c.id = " . intval($category_filter);
}

// Stock status filter
$status_where = "";
if ($stock_status === 'critical') {
    $status_where = " AND c.total_quantity <= (c.min_stock_level * 0.25) AND c.min_stock_level > 0";
} elseif ($stock_status === 'low') {
    $status_where = " AND c.total_quantity <= c.min_stock_level AND c.total_quantity > (c.min_stock_level * 0.25) AND c.min_stock_level > 0";
} elseif ($stock_status === 'normal') {
    $status_where = " AND c.total_quantity > c.min_stock_level AND c.min_stock_level > 0";
} elseif ($stock_status === 'overstock') {
    $status_where = " AND c.total_quantity > (c.min_stock_level * 2) AND c.min_stock_level > 0";
} elseif ($stock_status === 'out') {
    $status_where = " AND c.total_quantity <= 0";
} elseif ($stock_status === 'no_min') {
    $status_where = " AND (c.min_stock_level IS NULL OR c.min_stock_level = 0)";
}

// ==================== SUMMARY STATISTICS ====================

// Current stock summary
$current_stock_query = "SELECT 
    COUNT(*) as total_items,
    COALESCE(SUM(c.total_quantity), 0) as total_quantity,
    COALESCE(SUM(c.purchase_price * c.total_quantity), 0) as total_value,
    COALESCE(AVG(c.purchase_price), 0) as avg_price,
    COALESCE(SUM(c.gram_value * c.total_quantity), 0) as total_gram_value
    FROM category c
    WHERE 1=1 $category_where";
$current_stock = $conn->query($current_stock_query)->fetch_assoc();

// Stock status counts
$status_counts_query = "SELECT
    COUNT(CASE WHEN c.total_quantity <= 0 THEN 1 END) as out_of_stock,
    COUNT(CASE WHEN c.min_stock_level > 0 AND c.total_quantity <= (c.min_stock_level * 0.25) THEN 1 END) as critical,
    COUNT(CASE WHEN c.min_stock_level > 0 AND c.total_quantity <= c.min_stock_level AND c.total_quantity > (c.min_stock_level * 0.25) THEN 1 END) as low,
    COUNT(CASE WHEN c.min_stock_level > 0 AND c.total_quantity > c.min_stock_level AND c.total_quantity <= (c.min_stock_level * 2) THEN 1 END) as normal,
    COUNT(CASE WHEN c.min_stock_level > 0 AND c.total_quantity > (c.min_stock_level * 2) THEN 1 END) as overstock,
    COUNT(CASE WHEN c.min_stock_level IS NULL OR c.min_stock_level = 0 THEN 1 END) as no_minimum
    FROM category c
    WHERE 1=1 $category_where";
$status_counts = $conn->query($status_counts_query)->fetch_assoc();

// ==================== STOCK MOVEMENT REPORT ====================

// Stock movements during date range
$movements_query = "SELECT 
    DATE(al.created_at) as movement_date,
    COUNT(*) as total_movements,
    SUM(CASE WHEN al.description LIKE '%added to%' THEN 1 ELSE 0 END) as additions,
    SUM(CASE WHEN al.description LIKE '%removed from%' OR al.description LIKE '%deducted%' THEN 1 ELSE 0 END) as removals,
    SUM(CASE WHEN al.description LIKE '%bulk_update%' THEN 1 ELSE 0 END) as bulk_updates
    FROM activity_log al
    WHERE al.action IN ('stock_update', 'bulk_update')
    AND DATE(al.created_at) BETWEEN ? AND ?
    GROUP BY DATE(al.created_at)
    ORDER BY movement_date DESC
    LIMIT 30";

$stmt = $conn->prepare($movements_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$movements = $stmt->get_result();

// ==================== TOP STOCK ITEMS ====================

// Top 10 items by value
$top_value_query = "SELECT 
    c.id,
    c.category_name,
    c.total_quantity,
    c.purchase_price,
    (c.purchase_price * c.total_quantity) as stock_value,
    c.min_stock_level,
    c.gram_value
    FROM category c
    WHERE c.total_quantity > 0
    $category_where
    ORDER BY stock_value DESC
    LIMIT 10";
$top_value = $conn->query($top_value_query);

// Top 10 items by quantity
$top_quantity_query = "SELECT 
    c.id,
    c.category_name,
    c.total_quantity,
    c.purchase_price,
    (c.purchase_price * c.total_quantity) as stock_value,
    c.min_stock_level
    FROM category c
    WHERE c.total_quantity > 0
    $category_where
    ORDER BY c.total_quantity DESC
    LIMIT 10";
$top_quantity = $conn->query($top_quantity_query);

// ==================== LOW STOCK ALERTS ====================

$low_stock_query = "SELECT 
    c.id,
    c.category_name,
    c.total_quantity,
    c.min_stock_level,
    c.purchase_price,
    (c.purchase_price * c.total_quantity) as stock_value,
    CASE 
        WHEN c.total_quantity <= 0 THEN 'Out of Stock'
        WHEN c.total_quantity <= (c.min_stock_level * 0.25) THEN 'Critical'
        WHEN c.total_quantity <= c.min_stock_level THEN 'Low'
        ELSE 'Normal'
    END as alert_level,
    (c.total_quantity / NULLIF(c.min_stock_level, 0)) * 100 as stock_percentage
    FROM category c
    WHERE c.min_stock_level > 0 
    AND c.total_quantity <= c.min_stock_level
    $category_where
    ORDER BY 
        CASE 
            WHEN c.total_quantity <= 0 THEN 1
            WHEN c.total_quantity <= (c.min_stock_level * 0.25) THEN 2
            ELSE 3
        END,
        (c.total_quantity / NULLIF(c.min_stock_level, 0)) ASC";
$low_stock = $conn->query($low_stock_query);

// ==================== CATEGORY WISE SUMMARY ====================

$category_summary_query = "SELECT 
    c.id,
    c.category_name,
    COUNT(DISTINCT ii.id) as times_sold,
    COALESCE(SUM(ii.quantity), 0) as total_sold_qty,
    COALESCE(SUM(ii.total), 0) as total_sales_value,
    c.total_quantity as current_stock,
    c.purchase_price,
    (c.purchase_price * c.total_quantity) as current_value,
    CASE 
        WHEN c.total_quantity <= 0 THEN 'Out of Stock'
        WHEN c.min_stock_level > 0 AND c.total_quantity <= (c.min_stock_level * 0.25) THEN 'Critical'
        WHEN c.min_stock_level > 0 AND c.total_quantity <= c.min_stock_level THEN 'Low'
        WHEN c.min_stock_level > 0 AND c.total_quantity > (c.min_stock_level * 2) THEN 'Overstock'
        WHEN c.min_stock_level > 0 THEN 'Normal'
        ELSE 'No Minimum'
    END as status
    FROM category c
    LEFT JOIN invoice_item ii ON c.id = ii.cat_id 
        AND ii.created_at BETWEEN '$start_date' AND '$end_date 23:59:59'
    WHERE 1=1 $category_where $status_where
    GROUP BY c.id
    ORDER BY 
        CASE 
            WHEN c.total_quantity <= 0 THEN 1
            WHEN c.min_stock_level > 0 AND c.total_quantity <= (c.min_stock_level * 0.25) THEN 2
            WHEN c.min_stock_level > 0 AND c.total_quantity <= c.min_stock_level THEN 3
            ELSE 4
        END,
        c.category_name ASC";
$category_summary = $conn->query($category_summary_query);

// ==================== MONTHLY STOCK VALUE TREND ====================

$monthly_trend_query = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as transactions,
    SUM(CASE WHEN action = 'stock_update' AND description LIKE '%added%' THEN 1 ELSE 0 END) as additions,
    SUM(CASE WHEN action = 'stock_update' AND description LIKE '%removed%' THEN 1 ELSE 0 END) as removals
    FROM activity_log
    WHERE action IN ('stock_update', 'bulk_update')
    AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 12";
$monthly_trend = $conn->query($monthly_trend_query);

// Helper function for stock status class
function getStockStatusClass($status) {
    switch($status) {
        case 'Critical': return 'cancelled';
        case 'Low': return 'pending';
        case 'Normal': return 'completed';
        case 'Overstock': return 'info';
        case 'Out of Stock': return 'cancelled';
        default: return 'pending';
    }
}

// Helper function to format quantity
function formatQuantity($qty) {
    return number_format($qty, 2) . ' PCS';
}

// Helper function to format currency
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

// Check if user is admin for detailed reports
$is_admin = ($_SESSION['user_role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <!-- Chart.js for graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-icon.blue { background: #e8f2ff; color: #2463eb; }
        .stat-icon.green { background: #e2f7e9; color: #16a34a; }
        .stat-icon.orange { background: #fff4e5; color: #f59e0b; }
        .stat-icon.purple { background: #f2e8ff; color: #8b5cf6; }
        .stat-icon.red { background: #fee2e2; color: #dc2626; }
        
        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 30px;
        }
        
        .status-badge-sm {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-badge-sm.critical { background: #fee2e2; color: #dc2626; }
        .status-badge-sm.low { background: #fff3e0; color: #f59e0b; }
        .status-badge-sm.normal { background: #e0f2e7; color: #10b981; }
        .status-badge-sm.overstock { background: #e0e7ff; color: #6366f1; }
        .status-badge-sm.out { background: #f1f5f9; color: #64748b; }
        
        .progress-sm {
            height: 6px;
            border-radius: 3px;
            background: #e2e8f0;
        }
        
        .progress-bar-critical { background: #dc2626; }
        .progress-bar-low { background: #f59e0b; }
        .progress-bar-normal { background: #10b981; }
        
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 30px;
        }
        
        .report-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #eef2f6;
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .card-header-custom {
            padding: 16px 20px;
            border-bottom: 1px solid #eef2f6;
            background: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header-custom h5 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .card-header-custom p {
            margin: 4px 0 0;
            font-size: 13px;
            color: #64748b;
        }
        
        .table-custom {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-custom th {
            text-align: left;
            padding: 12px 20px;
            background: #f8fafc;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table-custom td {
            padding: 12px 20px;
            border-bottom: 1px solid #eef2f6;
            font-size: 13px;
        }
        
        .table-custom tr:last-child td {
            border-bottom: none;
        }
        
        .table-custom tr:hover td {
            background: #f8fafc;
        }
        
        .value-positive {
            color: #16a34a;
            font-weight: 600;
        }
        
        .value-negative {
            color: #dc2626;
            font-weight: 600;
        }
        
        .btn-primary-custom {
            background: #2463eb;
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
            background: #1e4fba;
            color: white;
            transform: translateY(-1px);
        }
        
        .btn-outline-custom {
            background: transparent;
            color: #1e293b;
            border: 1px solid #eef2f6;
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
            background: #f8fafc;
            border-color: #2463eb;
            color: #2463eb;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-export {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            transition: all 0.2s;
        }
        
        .btn-export:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        
        .btn-export.pdf:hover { color: #dc2626; }
        .btn-export.excel:hover { color: #16a34a; }
        .btn-export.print:hover { color: #2463eb; }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        @media print {
            .no-print { display: none; }
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Stock Report</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Comprehensive analysis of inventory levels, movements, and values</p>
                </div>
                <div class="d-flex gap-2">
                    <div class="export-buttons">
                        <button class="btn-export pdf" onclick="exportToPDF()">
                            <i class="bi bi-file-pdf"></i> PDF
                        </button>
                        <button class="btn-export excel" onclick="exportToExcel()">
                            <i class="bi bi-file-excel"></i> Excel
                        </button>
                        <button class="btn-export print" onclick="window.print()">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </div>
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

            <!-- Filter Section -->
            <div class="filter-section no-print">
                <form method="GET" action="stock.php" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Report Type</label>
                        <select name="report_type" class="form-select">
                            <option value="summary" <?php echo $report_type === 'summary' ? 'selected' : ''; ?>>Summary Report</option>
                            <option value="detailed" <?php echo $report_type === 'detailed' ? 'selected' : ''; ?>>Detailed Report</option>
                            <option value="movements" <?php echo $report_type === 'movements' ? 'selected' : ''; ?>>Stock Movements</option>
                            <option value="alerts" <?php echo $report_type === 'alerts' ? 'selected' : ''; ?>>Stock Alerts</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-select">
                            <option value="all">All Categories</option>
                            <?php 
                            if ($categories && $categories->num_rows > 0) {
                                $categories->data_seek(0);
                                while ($cat = $categories->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php 
                                endwhile; 
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Stock Status</label>
                        <select name="stock_status" class="form-select">
                            <option value="all">All Status</option>
                            <option value="critical" <?php echo $stock_status === 'critical' ? 'selected' : ''; ?>>Critical</option>
                            <option value="low" <?php echo $stock_status === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="normal" <?php echo $stock_status === 'normal' ? 'selected' : ''; ?>>Normal</option>
                            <option value="overstock" <?php echo $stock_status === 'overstock' ? 'selected' : ''; ?>>Overstock</option>
                            <option value="out" <?php echo $stock_status === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                            <option value="no_min" <?php echo $stock_status === 'no_min' ? 'selected' : ''; ?>>No Minimum</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                    </div>
                    
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn-primary-custom w-100">
                            <i class="bi bi-funnel"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Summary Statistics -->
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo $current_stock['total_items']; ?></div>
                            <div class="stat-label">Total Items</div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="bi bi-boxes"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo formatQuantity($current_stock['total_quantity']); ?></div>
                            <div class="stat-label">Total Stock</div>
                        </div>
                        <div class="stat-icon green">
                            <i class="bi bi-cubes"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo formatCurrency($current_stock['total_value']); ?></div>
                            <div class="stat-label">Total Value</div>
                        </div>
                        <div class="stat-icon purple">
                            <i class="bi bi-currency-rupee"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo formatCurrency($current_stock['avg_price']); ?></div>
                            <div class="stat-label">Avg Price/PCS</div>
                        </div>
                        <div class="stat-icon orange">
                            <i class="bi bi-arrow-up-down"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Status Summary -->
            <div class="row g-3 mb-4">
                <div class="col-md-8">
                    <div class="report-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-pie-chart me-2"></i>Stock Status Distribution</h5>
                            <p>Current inventory health</p>
                        </div>
                        <div class="card-body-custom p-4">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <canvas id="statusChart" style="height: 250px;"></canvas>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex flex-column gap-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><span class="status-badge-sm critical me-2">●</span> Critical</span>
                                            <span class="fw-semibold"><?php echo $status_counts['critical']; ?> items</span>
                                        </div>
                                        <div class="progress-sm">
                                            <div class="progress-bar-critical" style="width: <?php echo $current_stock['total_items'] > 0 ? ($status_counts['critical']/$current_stock['total_items']*100) : 0; ?>%;"></div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><span class="status-badge-sm low me-2">●</span> Low Stock</span>
                                            <span class="fw-semibold"><?php echo $status_counts['low']; ?> items</span>
                                        </div>
                                        <div class="progress-sm">
                                            <div class="progress-bar-low" style="width: <?php echo $current_stock['total_items'] > 0 ? ($status_counts['low']/$current_stock['total_items']*100) : 0; ?>%;"></div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><span class="status-badge-sm normal me-2">●</span> Normal</span>
                                            <span class="fw-semibold"><?php echo $status_counts['normal']; ?> items</span>
                                        </div>
                                        <div class="progress-sm">
                                            <div class="progress-bar-normal" style="width: <?php echo $current_stock['total_items'] > 0 ? ($status_counts['normal']/$current_stock['total_items']*100) : 0; ?>%;"></div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><span class="status-badge-sm overstock me-2">●</span> Overstock</span>
                                            <span class="fw-semibold"><?php echo $status_counts['overstock']; ?> items</span>
                                        </div>
                                        <div class="progress-sm">
                                            <div class="progress-bar" style="background: #6366f1; width: <?php echo $current_stock['total_items'] > 0 ? ($status_counts['overstock']/$current_stock['total_items']*100) : 0; ?>%;"></div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><span class="status-badge-sm out me-2">●</span> Out of Stock</span>
                                            <span class="fw-semibold"><?php echo $status_counts['out_of_stock']; ?> items</span>
                                        </div>
                                        <div class="progress-sm">
                                            <div class="progress-bar" style="background: #6b7280; width: <?php echo $current_stock['total_items'] > 0 ? ($status_counts['out_of_stock']/$current_stock['total_items']*100) : 0; ?>%;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="report-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-exclamation-triangle me-2"></i>Quick Alerts</h5>
                            <p>Items needing attention</p>
                        </div>
                        <div class="card-body-custom p-3">
                            <?php 
                            $alert_count = 0;
                            if ($low_stock && $low_stock->num_rows > 0): 
                                while ($alert = $low_stock->fetch_assoc()): 
                                $alert_count++;
                                if ($alert_count > 5) break;
                            ?>
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($alert['category_name']); ?></div>
                                        <small class="text-muted">Stock: <?php echo formatQuantity($alert['total_quantity']); ?></small>
                                    </div>
                                    <span class="status-badge-sm <?php echo getStockStatusClass($alert['alert_level']); ?>">
                                        <?php echo $alert['alert_level']; ?>
                                    </span>
                                </div>
                            <?php 
                                endwhile; 
                            endif; 
                            ?>
                            <?php if ($alert_count == 0): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-check-circle-fill text-success fs-1"></i>
                                    <p class="mt-2">No stock alerts</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($report_type === 'summary' || $report_type === 'detailed'): ?>
            <!-- Category-wise Stock Summary -->
            <div class="report-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-table me-2"></i>Category-wise Stock Summary</h5>
                    <p>Detailed breakdown by category</p>
                </div>
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="stockSummaryTable">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Current Stock</th>
                                <th>Min Level</th>
                                <th>Status</th>
                                <th>Sold (Qty)</th>
                                <th>Sold (Value)</th>
                                <th>Current Value</th>
                                <th>Times Sold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($category_summary && $category_summary->num_rows > 0): ?>
                                <?php while ($item = $category_summary->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($item['category_name']); ?></td>
                                        <td><?php echo formatQuantity($item['current_stock']); ?></td>
                       <td>
    <?php 
    if (isset($item['min_stock_level']) && $item['min_stock_level'] > 0) {
        echo formatQuantity($item['min_stock_level']);
    } else {
        echo '-';
    }
    ?>
</td>
                                        <td>
                                            <span class="status-badge-sm <?php echo getStockStatusClass($item['status']); ?>">
                                                <?php echo $item['status']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo formatQuantity($item['total_sold_qty']); ?></td>
                                        <td><?php echo formatCurrency($item['total_sales_value']); ?></td>
                                        <td class="fw-semibold"><?php echo formatCurrency($item['current_value']); ?></td>
                                        <td class="text-center"><?php echo $item['times_sold']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                               
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if ($category_summary && $category_summary->num_rows > 0): ?>
                        <?php 
                        $category_summary->data_seek(0);
                        while ($item = $category_summary->fetch_assoc()): 
                        ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header">
                                    <div>
                                        <span class="fw-semibold"><?php echo htmlspecialchars($item['category_name']); ?></span>
                                    </div>
                                    <span class="status-badge-sm <?php echo getStockStatusClass($item['status']); ?>">
                                        <?php echo $item['status']; ?>
                                    </span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Current Stock</span>
                                    <span class="mobile-card-value"><?php echo formatQuantity($item['current_stock']); ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Min Level</span>
                                    <span class="mobile-card-value"><?php echo $item['min_stock_level'] > 0 ? formatQuantity($item['min_stock_level']) : '-'; ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Sold (Qty)</span>
                                    <span class="mobile-card-value"><?php echo formatQuantity($item['total_sold_qty']); ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Sold (Value)</span>
                                    <span class="mobile-card-value"><?php echo formatCurrency($item['total_sales_value']); ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Current Value</span>
                                    <span class="mobile-card-value fw-semibold"><?php echo formatCurrency($item['current_value']); ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Times Sold</span>
                                    <span class="mobile-card-value"><?php echo $item['times_sold']; ?> times</span>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-boxes d-block mb-2" style="font-size: 48px;"></i>
                            <p>No data available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($report_type === 'movements' || $report_type === 'detailed'): ?>
            <!-- Stock Movements -->
            <div class="report-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-arrow-left-right me-2"></i>Stock Movements (Last 30 Days)</h5>
                    <p>Daily addition and removal activity</p>
                </div>
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Total Movements</th>
                                <th>Additions</th>
                                <th>Removals</th>
                                <th>Bulk Updates</th>
                                <th>Net Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($movements && $movements->num_rows > 0): ?>
                                <?php while ($move = $movements->fetch_assoc()): 
                                    $net_change = $move['additions'] - $move['removals'];
                                ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($move['movement_date'])); ?></td>
                                        <td class="text-center"><?php echo $move['total_movements']; ?></td>
                                        <td class="value-positive">+<?php echo $move['additions']; ?></td>
                                        <td class="value-negative">-<?php echo $move['removals']; ?></td>
                                        <td class="text-center"><?php echo $move['bulk_updates']; ?></td>
                                        <td class="<?php echo $net_change >= 0 ? 'value-positive' : 'value-negative'; ?>">
                                            <?php echo $net_change >= 0 ? '+' : ''; ?><?php echo $net_change; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No movements in selected period</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Top Items Section -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="report-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-trophy me-2"></i>Top 10 by Value</h5>
                            <p>Highest stock value items</p>
                        </div>
                        <div class="desktop-table" style="overflow-x: auto;">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Stock</th>
                                        <th>Price/PCS</th>
                                        <th>Total Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($top_value && $top_value->num_rows > 0): ?>
                                        <?php while ($item = $top_value->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo htmlspecialchars($item['category_name']); ?></td>
                                                <td><?php echo formatQuantity($item['total_quantity']); ?></td>
                                                <td><?php echo formatCurrency($item['purchase_price']); ?></td>
                                                <td class="fw-semibold text-primary"><?php echo formatCurrency($item['stock_value']); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="report-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-bar-chart me-2"></i>Top 10 by Quantity</h5>
                            <p>Highest quantity items</p>
                        </div>
                        <div class="desktop-table" style="overflow-x: auto;">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Quantity</th>
                                        <th>Price/kg\</th>
                                        <th>Total Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($top_quantity && $top_quantity->num_rows > 0): ?>
                                        <?php while ($item = $top_quantity->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo htmlspecialchars($item['category_name']); ?></td>
                                                <td class="fw-semibold"><?php echo formatQuantity($item['total_quantity']); ?></td>
                                                <td><?php echo formatCurrency($item['purchase_price']); ?></td>
                                                <td><?php echo formatCurrency($item['stock_value']); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($is_admin): ?>
            <!-- Monthly Trend Chart (Admin only) -->
           
            <?php endif; ?>

            <!-- Report Footer -->
            <div class="text-muted text-end mt-4 no-print" style="font-size: 12px;">
                <i class="bi bi-calendar"></i> Generated on: <?php echo date('d M Y h:i A'); ?>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Payment Collection Modal -->
<?php
// Helper function to build query string with current filters
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return count($params) ? '?' . http_build_query($params) : '';
}
?>

<?php include 'includes/scripts.php'; ?>

<!-- Chart.js Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Status Distribution Chart
    const statusCtx = document.getElementById('statusChart')?.getContext('2d');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Critical', 'Low Stock', 'Normal', 'Overstock', 'Out of Stock'],
                datasets: [{
                    data: [
                        <?php echo $status_counts['critical']; ?>,
                        <?php echo $status_counts['low']; ?>,
                        <?php echo $status_counts['normal']; ?>,
                        <?php echo $status_counts['overstock']; ?>,
                        <?php echo $status_counts['out_of_stock']; ?>
                    ],
                    backgroundColor: [
                        '#dc2626',
                        '#f59e0b',
                        '#10b981',
                        '#6366f1',
                        '#6b7280'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                cutout: '70%'
            }
        });
    }

    // Monthly Trend Chart (Admin only)
    <?php if ($is_admin && $monthly_trend && $monthly_trend->num_rows > 0): ?>
    const trendCtx = document.getElementById('trendChart')?.getContext('2d');
    if (trendCtx) {
        const months = [];
        const additions = [];
        const removals = [];
        
        <?php 
        $monthly_trend->data_seek(0);
        while ($trend = $monthly_trend->fetch_assoc()): 
        ?>
        months.push('<?php echo date('M Y', strtotime($trend['month'] . '-01')); ?>');
        additions.push(<?php echo $trend['additions']; ?>);
        removals.push(<?php echo $trend['removals']; ?>);
        <?php endwhile; ?>
        
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Additions',
                        data: additions,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Removals',
                        data: removals,
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#eef2f6'
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});

// Export functions
function exportToPDF() {
    window.print();
}

function exportToExcel() {
    // Collect all table data
    let csv = [];
    let rows = document.querySelectorAll('table tr');
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            row.push(cols[j].innerText);
        }
        csv.push(row.join(','));
    }
    
    // Download CSV
    let csvFile = new Blob([csv.join('\n')], {type: 'text/csv'});
    let downloadLink = document.createElement('a');
    downloadLink.download = 'stock_report_' + new Date().toISOString().slice(0,10) + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

// Initialize DataTable for stock summary
$(document).ready(function() {
    $('#stockSummaryTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc']],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ items",
            info: "Showing _START_ to _END_ of _TOTAL_ items"
        }
    });
});
</script>

</body>
</html>