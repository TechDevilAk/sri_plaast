<?php
session_start();
$currentPage = 'dashboard';
$pageTitle = 'Dashboard';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Get current user info for personalized greeting
$user_id = $_SESSION['user_id'];
$user_query = "SELECT name, role FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

// Get date ranges
$today = date('Y-m-d');
$first_day_month = date('Y-m-01');
$last_day_month = date('Y-m-t');

// ==================== STATS CARDS ====================

// 1. Today's Sales (Invoices)
$today_sales_query = "SELECT COALESCE(COUNT(*), 0) as count, COALESCE(SUM(total), 0) as amount 
                      FROM invoice WHERE DATE(created_at) = ?";
$stmt = $conn->prepare($today_sales_query);
$stmt->bind_param("s", $today);
$stmt->execute();
$today_sales = $stmt->get_result()->fetch_assoc();

// 2. Monthly Sales
$month_sales_query = "SELECT COALESCE(COUNT(*), 0) as count, COALESCE(SUM(total), 0) as amount 
                      FROM invoice WHERE DATE(created_at) BETWEEN ? AND ?";
$stmt = $conn->prepare($month_sales_query);
$stmt->bind_param("ss", $first_day_month, $last_day_month);
$stmt->execute();
$month_sales = $stmt->get_result()->fetch_assoc();

// 3. Total Customers
$total_customers = $conn->query("SELECT COUNT(*) as cnt FROM customers")->fetch_assoc()['cnt'] ?? 0;

// 4. Low Stock Alert
$low_stock_query = "SELECT c.category_name, c.total_quantity, c.min_stock_level, c.gram_value
                    FROM category c
                    WHERE c.total_quantity <= c.min_stock_level AND c.min_stock_level > 0
                    ORDER BY (c.total_quantity / c.min_stock_level) ASC
                    LIMIT 5";
$low_stock_result = $conn->query($low_stock_query);
$low_stock_count = $low_stock_result ? $low_stock_result->num_rows : 0;

// 5. Pending Payments (Credit Sales)
$pending_payments_query = "SELECT COALESCE(COUNT(*), 0) as count, COALESCE(SUM(pending_amount), 0) as amount 
                          FROM invoice WHERE pending_amount > 0";
$pending_payments = $conn->query($pending_payments_query)->fetch_assoc();

// 6. Today's Purchases
$today_purchase_query = "SELECT COALESCE(COUNT(*), 0) as count, COALESCE(SUM(total), 0) as amount 
                         FROM purchase WHERE DATE(created_at) = ?";
$stmt = $conn->prepare($today_purchase_query);
$stmt->bind_param("s", $today);
$stmt->execute();
$today_purchase = $stmt->get_result()->fetch_assoc();

// 7. Total Products (Categories with stock)
$total_products = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity > 0")->fetch_assoc()['cnt'] ?? 0;

// 8. GST Credit Available
$gst_credit_query = "SELECT COALESCE(SUM(total_credit), 0) as credit FROM gst_credit_table";
$gst_credit = $conn->query($gst_credit_query)->fetch_assoc()['credit'] ?? 0;

// ==================== CHARTS DATA ====================

// Last 7 days sales chart
$sales_chart_query = "SELECT DATE(created_at) as date, COUNT(*) as invoice_count, SUM(total) as total_sales 
                       FROM invoice 
                       WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                       GROUP BY DATE(created_at)
                       ORDER BY date ASC";
$sales_chart_result = $conn->query($sales_chart_query);

$chart_labels = [];
$chart_sales = [];
$chart_invoices = [];

while ($row = $sales_chart_result->fetch_assoc()) {
    $chart_labels[] = date('d M', strtotime($row['date']));
    $chart_sales[] = $row['total_sales'];
    $chart_invoices[] = $row['invoice_count'];
}

// Top selling products
$top_products_query = "SELECT p.product_name, SUM(ii.quantity) as total_qty, SUM(ii.total) as total_sales
                       FROM invoice_item ii
                       JOIN product p ON ii.product_id = p.id
                       WHERE ii.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                       GROUP BY ii.product_id
                       ORDER BY total_sales DESC
                       LIMIT 5";
$top_products = $conn->query($top_products_query);

// Recent activities
$recent_activities_query = "SELECT al.*, u.name as user_name 
                            FROM activity_log al
                            JOIN users u ON al.user_id = u.id
                            ORDER BY al.created_at DESC
                            LIMIT 10";
$recent_activities = $conn->query($recent_activities_query);

// Recent invoices
$recent_invoices_query = "SELECT i.*, c.customer_name 
                          FROM invoice i
                          LEFT JOIN customers c ON i.customer_id = c.id
                          ORDER BY i.created_at DESC
                          LIMIT 10";
$recent_invoices = $conn->query($recent_invoices_query);

// Recent purchases
$recent_purchases_query = "SELECT p.*, s.supplier_name 
                           FROM purchase p
                           LEFT JOIN suppliers s ON p.supplier_id = s.id
                           ORDER BY p.created_at DESC
                           LIMIT 5";
$recent_purchases = $conn->query($recent_purchases_query);

// Category wise stock distribution
$stock_distribution_query = "SELECT category_name, total_quantity 
                             FROM category 
                             WHERE total_quantity > 0 
                             ORDER BY total_quantity DESC 
                             LIMIT 8";
$stock_distribution = $conn->query($stock_distribution_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <!-- Chart.js for graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .dashboard-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card-large {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #eef2f6;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card-large:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.08);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .stat-icon-lg {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-icon-lg.blue { background: #e8f2ff; color: #2463eb; }
        .stat-icon-lg.green { background: #e2f7e9; color: #16a34a; }
        .stat-icon-lg.orange { background: #fff4e5; color: #f59e0b; }
        .stat-icon-lg.purple { background: #f2e8ff; color: #8b5cf6; }
        .stat-icon-lg.red { background: #ffe8e8; color: #dc2626; }
        .stat-icon-lg.teal { background: #e0f2f1; color: #0d9488; }
        .stat-icon-lg.indigo { background: #e0e7ff; color: #4f46e5; }
        .stat-icon-lg.pink { background: #fce7f3; color: #db2777; }
        
        .stat-title {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
        }
        
        .stat-value-lg {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }
        
        .stat-sub {
            font-size: 13px;
            color: #94a3b8;
            margin-top: 4px;
        }
        
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 24px;
        }
        
        .two-column-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .three-column-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #eef2f6;
            overflow: hidden;
        }
        
        .card-header-custom {
            padding: 16px 20px;
            border-bottom: 1px solid #eef2f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafbfc;
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
        
        .card-body-custom {
            padding: 20px;
        }
        
        .low-stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eef2f6;
        }
        
        .low-stock-item:last-child {
            border-bottom: none;
        }
        
        .stock-name {
            font-weight: 500;
            color: #1e293b;
        }
        
        .stock-level {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .stock-bar {
            width: 100px;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .stock-bar-fill {
            height: 100%;
            background: #f59e0b;
            border-radius: 3px;
        }
        
        .stock-value {
            font-size: 13px;
            font-weight: 500;
            color: #1e293b;
            min-width: 70px;
            text-align: right;
        }
        
        .activity-item {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #eef2f6;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 500;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .activity-meta {
            font-size: 12px;
            color: #94a3b8;
            display: flex;
            gap: 12px;
        }
        
        .badge-success {
            background: #e2f7e9;
            color: #16a34a;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-warning {
            background: #fff4e5;
            color: #f59e0b;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-danger {
            background: #ffe8e8;
            color: #dc2626;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-info {
            background: #e8f2ff;
            color: #2463eb;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #2463eb 0%, #8b5cf6 100%);
            border-radius: 20px;
            padding: 30px;
            color: white;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        .welcome-banner h2 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }
        
        .welcome-banner p {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }
        
        .btn-light-custom {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 500;
            transition: all 0.3s;
            position: relative;
            z-index: 1;
        }
        
        .btn-light-custom:hover {
            background: white;
            color: #2463eb;
        }
        
        @media (max-width: 768px) {
            .two-column-grid,
            .three-column-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content" style="padding: 24px;">

            <!-- Welcome Banner with Date -->
            <div class="welcome-banner">
                <h2>Welcome back, <?php echo htmlspecialchars($user_name); ?>! 👋</h2>
                <p><?php echo date('l, F j, Y'); ?> • Here's what's happening with your business today.</p>
                <a href="new-sale.php" class="btn btn-light-custom">
                    <i class="bi bi-plus-circle me-2"></i>Create New Invoice
                </a>
            </div>

            <!-- Stats Cards Grid -->
            <div class="dashboard-stats-grid">
                <!-- Today's Sales -->
                <div class="stat-card-large">
                    <div class="stat-header">
                        <div class="stat-icon-lg blue">
                            <i class="bi bi-cart3"></i>
                        </div>
                        <div>
                            <div class="stat-title">Today's Sales</div>
                            <div class="stat-value-lg">₹<?php echo number_format($today_sales['amount'] ?? 0, 2); ?></div>
                            <div class="stat-sub"><?php echo $today_sales['count'] ?? 0; ?> invoices today</div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Sales -->
                <div class="stat-card-large">
                    <div class="stat-header">
                        <div class="stat-icon-lg green">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div>
                            <div class="stat-title">Monthly Sales</div>
                            <div class="stat-value-lg">₹<?php echo number_format($month_sales['amount'] ?? 0, 2); ?></div>
                            <div class="stat-sub"><?php echo $month_sales['count'] ?? 0; ?> invoices this month</div>
                        </div>
                    </div>
                </div>

                <!-- Pending Payments -->
                <div class="stat-card-large">
                    <div class="stat-header">
                        <div class="stat-icon-lg orange">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div>
                            <div class="stat-title">Pending Payments</div>
                            <div class="stat-value-lg">₹<?php echo number_format($pending_payments['amount'] ?? 0, 2); ?></div>
                            <div class="stat-sub"><?php echo $pending_payments['count'] ?? 0; ?> credit invoices</div>
                        </div>
                    </div>
                </div>

                <!-- Low Stock Alert -->
                <div class="stat-card-large">
                    <div class="stat-header">
                        <div class="stat-icon-lg red">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div>
                            <div class="stat-title">Low Stock Alert</div>
                            <div class="stat-value-lg"><?php echo $low_stock_count; ?></div>
                            <div class="stat-sub">items below minimum level</div>
                        </div>
                    </div>
                </div>

                <!-- Total Customers -->
                <div class="stat-card-large">
                    <div class="stat-header">
                        <div class="stat-icon-lg purple">
                            <i class="bi bi-people"></i>
                        </div>
                        <div>
                            <div class="stat-title">Total Customers</div>
                            <div class="stat-value-lg"><?php echo number_format($total_customers); ?></div>
                            <div class="stat-sub">active customers</div>
                        </div>
                    </div>
                </div>

                <!-- Total Products -->
                <div class="stat-card-large">
                    <div class="stat-header">
                        <div class="stat-icon-lg teal">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div>
                            <div class="stat-title">Products in Stock</div>
                            <div class="stat-value-lg"><?php echo number_format($total_products); ?></div>
                            <div class="stat-sub">active products</div>
                        </div>
                    </div>
                </div>

                <!-- Today's Purchase -->
                <div class="stat-card-large">
                    <div class="stat-header">
                        <div class="stat-icon-lg indigo">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div>
                            <div class="stat-title">Today's Purchase</div>
                            <div class="stat-value-lg">₹<?php echo number_format($today_purchase['amount'] ?? 0, 2); ?></div>
                            <div class="stat-sub"><?php echo $today_purchase['count'] ?? 0; ?> purchases</div>
                        </div>
                    </div>
                </div>

                <!-- GST Credit -->
                <div class="stat-card-large">
                    <div class="stat-header">
                        <div class="stat-icon-lg pink">
                            <i class="bi bi-piggy-bank"></i>
                        </div>
                        <div>
                            <div class="stat-title">GST Credit</div>
                            <div class="stat-value-lg">₹<?php echo number_format($gst_credit, 2); ?></div>
                            <div class="stat-sub">available credit</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="two-column-grid">
                <!-- Sales Chart -->
                <div class="chart-container">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h5 style="margin: 0; font-weight: 600;">Sales Overview (Last 7 Days)</h5>
                        <div>
                            <span class="badge-info" style="padding: 4px 8px; border-radius: 20px;">Revenue</span>
                        </div>
                    </div>
                    <canvas id="salesChart" style="width: 100%; height: 300px;"></canvas>
                </div>

                <!-- Low Stock Alert -->
                <div class="dashboard-card">
                    <div class="card-header-custom">
                        <div>
                            <h5>Low Stock Alert</h5>
                            <p>Items below minimum stock level</p>
                        </div>
                        <a href="stocks.php" style="color: #2463eb; text-decoration: none; font-size: 13px;">View All</a>
                    </div>
                    <div class="card-body-custom">
                        <?php if ($low_stock_result && $low_stock_result->num_rows > 0): ?>
                            <?php while ($item = $low_stock_result->fetch_assoc()): 
                                $percentage = ($item['total_quantity'] / $item['min_stock_level']) * 100;
                                $bar_color = $percentage <= 25 ? '#dc2626' : ($percentage <= 50 ? '#f59e0b' : '#f59e0b');
                            ?>
                                <div class="low-stock-item">
                                    <div>
                                        <div class="stock-name"><?php echo htmlspecialchars($item['category_name']); ?></div>
                                        <div style="font-size: 12px; color: #64748b;">
                                            Min: <?php echo number_format($item['min_stock_level'], 2); ?> kg
                                        </div>
                                    </div>
                                    <div class="stock-level">
                                        <div class="stock-bar">
                                            <div class="stock-bar-fill" style="width: <?php echo min($percentage, 100); ?>%; background: <?php echo $bar_color; ?>;"></div>
                                        </div>
                                        <div class="stock-value">
                                            <?php echo number_format($item['total_quantity'], 2); ?> kg
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 30px 20px; color: #64748b;">
                                <i class="bi bi-check-circle" style="font-size: 40px; color: #16a34a; margin-bottom: 10px; display: block;"></i>
                                <p>All stock levels are healthy!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Top Selling Products & Stock Distribution -->
            <div class="two-column-grid">
                <!-- Top Selling Products -->
                <div class="dashboard-card">
                    <div class="card-header-custom">
                        <div>
                            <h5>Top Selling Products</h5>
                            <p>Last 30 days performance</p>
                        </div>
                    </div>
                    <div class="card-body-custom">
                        <?php if ($top_products && $top_products->num_rows > 0): ?>
                            <?php while ($product = $top_products->fetch_assoc()): ?>
                                <div class="activity-item">
                                    <div class="activity-icon" style="background: #e8f2ff; color: #2463eb;">
                                        <i class="bi bi-box"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title"><?php echo htmlspecialchars($product['product_name']); ?></div>
                                        <div class="activity-meta">
                                            <span><i class="bi bi-cart"></i> <?php echo number_format($product['total_qty'], 2); ?> units</span>
                                            <span><i class="bi bi-currency-rupee"></i> <?php echo number_format($product['total_sales'], 2); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 30px; color: #64748b;">
                                No sales data available
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stock Distribution -->
                <div class="dashboard-card">
                    <div class="card-header-custom">
                        <div>
                            <h5>Stock Distribution</h5>
                            <p>Category wise stock levels</p>
                        </div>
                    </div>
                    <div class="card-body-custom">
                        <?php if ($stock_distribution && $stock_distribution->num_rows > 0): ?>
                            <?php while ($stock = $stock_distribution->fetch_assoc()): ?>
                                <div class="low-stock-item">
                                    <span class="stock-name"><?php echo htmlspecialchars($stock['category_name']); ?></span>
                                    <span class="stock-value"><?php echo number_format($stock['total_quantity'], 2); ?> kg</span>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 30px; color: #64748b;">
                                No stock data available
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Invoices and Purchases -->
            <div class="two-column-grid">
                <!-- Recent Invoices -->
                <div class="dashboard-card">
                    <div class="card-header-custom">
                        <div>
                            <h5>Recent Invoices</h5>
                            <p>Latest sales transactions</p>
                        </div>
                        <a href="invoices.php" style="color: #2463eb; text-decoration: none; font-size: 13px;">View All</a>
                    </div>
                    <div class="card-body-custom" style="padding: 0;">
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead style="background: #f8fafc; font-size: 12px; color: #64748b;">
                                    <tr>
                                        <th style="padding: 12px 20px; text-align: left;">Invoice #</th>
                                        <th style="padding: 12px 20px; text-align: left;">Customer</th>
                                        <th style="padding: 12px 20px; text-align: left;">Amount</th>
                                        <th style="padding: 12px 20px; text-align: left;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_invoices && $recent_invoices->num_rows > 0): ?>
                                        <?php while ($inv = $recent_invoices->fetch_assoc()): ?>
                                            <tr style="border-bottom: 1px solid #eef2f6;">
                                                <td style="padding: 12px 20px; font-weight: 500;"><?php echo htmlspecialchars($inv['inv_num']); ?></td>
                                                <td style="padding: 12px 20px;"><?php echo htmlspecialchars($inv['customer_name'] ?? 'Walk-in Customer'); ?></td>
                                                <td style="padding: 12px 20px; font-weight: 500;">₹<?php echo number_format($inv['total'], 2); ?></td>
                                                <td style="padding: 12px 20px;">
                                                    <?php if ($inv['pending_amount'] > 0): ?>
                                                        <span class="badge-warning">Pending: ₹<?php echo number_format($inv['pending_amount'], 2); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge-success">Paid</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="padding: 30px; text-align: center; color: #64748b;">No invoices yet</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Purchases -->
                <div class="dashboard-card">
                    <div class="card-header-custom">
                        <div>
                            <h5>Recent Purchases</h5>
                            <p>Latest stock purchases</p>
                        </div>
                        <a href="manage-purchases.php" style="color: #2463eb; text-decoration: none; font-size: 13px;">View All</a>
                    </div>
                    <div class="card-body-custom" style="padding: 0;">
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead style="background: #f8fafc; font-size: 12px; color: #64748b;">
                                    <tr>
                                        <th style="padding: 12px 20px; text-align: left;">Purchase #</th>
                                        <th style="padding: 12px 20px; text-align: left;">Supplier</th>
                                        <th style="padding: 12px 20px; text-align: left;">Amount</th>
                                        <th style="padding: 12px 20px; text-align: left;">GST</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_purchases && $recent_purchases->num_rows > 0): ?>
                                        <?php while ($pur = $recent_purchases->fetch_assoc()): ?>
                                            <tr style="border-bottom: 1px solid #eef2f6;">
                                                <td style="padding: 12px 20px; font-weight: 500;"><?php echo htmlspecialchars($pur['purchase_no']); ?></td>
                                                <td style="padding: 12px 20px;"><?php echo htmlspecialchars($pur['supplier_name'] ?? 'N/A'); ?></td>
                                                <td style="padding: 12px 20px; font-weight: 500;">₹<?php echo number_format($pur['total'], 2); ?></td>
                                                <td style="padding: 12px 20px;">
                                                    <span class="badge-info">CGST: <?php echo $pur['cgst']; ?>%</span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="padding: 30px; text-align: center; color: #64748b;">No purchases yet</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="dashboard-card" style="margin-top: 24px;">
                <div class="card-header-custom">
                    <div>
                        <h5>Recent Activities</h5>
                        <p>Latest system activities</p>
                    </div>
                </div>
                <div class="card-body-custom">
                    <div style="display: grid; gap: 12px;">
                        <?php if ($recent_activities && $recent_activities->num_rows > 0): ?>
                            <?php while ($activity = $recent_activities->fetch_assoc()): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <?php
                                        $icon = 'bi-info-circle';
                                        if (strpos($activity['action'], 'login') !== false) $icon = 'bi-box-arrow-in-right';
                                        elseif (strpos($activity['action'], 'create') !== false) $icon = 'bi-plus-circle';
                                        elseif (strpos($activity['action'], 'update') !== false) $icon = 'bi-pencil';
                                        elseif (strpos($activity['action'], 'delete') !== false) $icon = 'bi-trash';
                                        ?>
                                        <i class="bi <?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars($activity['user_name']); ?> 
                                            <?php echo htmlspecialchars($activity['action']); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <span><?php echo htmlspecialchars($activity['description']); ?></span>
                                            <span><?php echo date('d M Y, h:i A', strtotime($activity['created_at'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 30px; color: #64748b;">
                                No recent activities
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 24px;">
                <a href="new-sale.php" style="background: #e8f2ff; color: #2463eb; padding: 16px; border-radius: 12px; text-decoration: none; text-align: center;">
                    <i class="bi bi-plus-circle" style="font-size: 24px; display: block; margin-bottom: 8px;"></i>
                    <span style="font-weight: 500;">New Invoice</span>
                </a>
                <a href="add-purchase.php" style="background: #e2f7e9; color: #16a34a; padding: 16px; border-radius: 12px; text-decoration: none; text-align: center;">
                    <i class="bi bi-cart-plus" style="font-size: 24px; display: block; margin-bottom: 8px;"></i>
                    <span style="font-weight: 500;">New Purchase</span>
                </a>
                <a href="customers.php" style="background: #f2e8ff; color: #8b5cf6; padding: 16px; border-radius: 12px; text-decoration: none; text-align: center;">
                    <i class="bi bi-people" style="font-size: 24px; display: block; margin-bottom: 8px;"></i>
                    <span style="font-weight: 500;">Add Customer</span>
                </a>
                <a href="suppliers.php" style="background: #fff4e5; color: #f59e0b; padding: 16px; border-radius: 12px; text-decoration: none; text-align: center;">
                    <i class="bi bi-truck" style="font-size: 24px; display: block; margin-bottom: 8px;"></i>
                    <span style="font-weight: 500;">Add Supplier</span>
                </a>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<?php include 'includes/scripts.php'; ?>

<!-- Chart.js Initialization -->
<script>

</script>

</body>
</html>