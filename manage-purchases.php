<?php
// purchases.php
session_start();
$currentPage = 'purchases';
$pageTitle = 'Manage Purchases';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view purchases
checkRoleAccess(['admin']);

header_remove("X-Powered-By");

// --------------------------
// Helper Functions
// --------------------------
function money2($n) {
    return number_format((float)$n, 2, '.', '');
}

function getPaymentStatus($total, $paid) {
    if ($paid >= $total) {
        return ['badge' => 'success', 'text' => 'Paid'];
    } elseif ($paid > 0) {
        return ['badge' => 'warning', 'text' => 'Partial'];
    } else {
        return ['badge' => 'danger', 'text' => 'Unpaid'];
    }
}

// --------------------------
// Handle Delete Purchase
// --------------------------
if (isset($_POST['action']) && $_POST['action'] === 'delete_purchase' && isset($_POST['purchase_id'])) {
    $delete_id = intval($_POST['purchase_id']);
    
    // Check if purchase exists
    $check = $conn->prepare("SELECT purchase_no FROM purchase WHERE id = ?");
    $check->bind_param("i", $delete_id);
    $check->execute();
    $result = $check->get_result();
    $purchase = $result->fetch_assoc();
    
    if ($purchase) {
        $conn->begin_transaction();
        
        try {
            // Get all items to revert stock
            $items = $conn->prepare("SELECT cat_id, qty FROM purchase_item WHERE purchase_id = ?");
            $items->bind_param("i", $delete_id);
            $items->execute();
            $items_result = $items->get_result();
            
            // Revert category quantities
            while ($item = $items_result->fetch_assoc()) {
                $revert = $conn->prepare("
                    UPDATE category 
                    SET total_quantity = total_quantity - ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $revert->bind_param("di", $item['qty'], $item['cat_id']);
                $revert->execute();
                $revert->close();
            }
            
            // Delete from gst_credit_table
            $del_gst = $conn->prepare("DELETE FROM gst_credit_table WHERE purchase_id = ?");
            $del_gst->bind_param("i", $delete_id);
            $del_gst->execute();
            $del_gst->close();
            
            // Delete payment history
            $del_payments = $conn->prepare("DELETE FROM purchase_payment_history WHERE purchase_id = ?");
            $del_payments->bind_param("i", $delete_id);
            $del_payments->execute();
            $del_payments->close();
            
            // Delete purchase items
            $del_items = $conn->prepare("DELETE FROM purchase_item WHERE purchase_id = ?");
            $del_items->bind_param("i", $delete_id);
            $del_items->execute();
            $del_items->close();
            
            // Delete purchase
            $del_purchase = $conn->prepare("DELETE FROM purchase WHERE id = ?");
            $del_purchase->bind_param("i", $delete_id);
            $del_purchase->execute();
            $del_purchase->close();
            
            // Log activity
            $log_desc = "Deleted purchase #{$purchase['purchase_no']}";
            $log_stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)");
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            
            $conn->commit();
            $success_message = "Purchase deleted successfully.";
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Failed to delete purchase: " . $e->getMessage();
        }
    }
}

// --------------------------
// Filters and Pagination
// --------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$supplier_filter = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$gst_type_filter = isset($_GET['gst_type']) ? $_GET['gst_type'] : '';

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(p.purchase_no LIKE ? OR s.supplier_name LIKE ? OR p.invoice_num LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

if ($supplier_filter > 0) {
    $where[] = "p.supplier_id = ?";
    $params[] = $supplier_filter;
    $types .= "i";
}

if (!empty($status_filter)) {
    if ($status_filter === 'paid') {
        $where[] = "p.paid_amount >= p.total";
    } elseif ($status_filter === 'partial') {
        $where[] = "p.paid_amount > 0 AND p.paid_amount < p.total";
    } elseif ($status_filter === 'unpaid') {
        $where[] = "(p.paid_amount IS NULL OR p.paid_amount = 0)";
    }
}

if (!empty($gst_type_filter)) {
    $where[] = "p.gst_type = ?";
    $params[] = $gst_type_filter;
    $types .= "s";
}

if (!empty($from_date)) {
    $where[] = "DATE(p.purchase_date) >= ?";
    $params[] = $from_date;
    $types .= "s";
}

if (!empty($to_date)) {
    $where[] = "DATE(p.purchase_date) <= ?";
    $params[] = $to_date;
    $types .= "s";
}

$where_clause = implode(" AND ", $where);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as cnt 
              FROM purchase p 
              LEFT JOIN suppliers s ON p.supplier_id = s.id 
              WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$totalCount = $count_stmt->get_result()->fetch_assoc()['cnt'];
$total_pages = ceil($totalCount / $limit);

// Get purchases for current page
$sql = "SELECT p.*, s.supplier_name, s.phone, s.gst_number as supplier_gst,
               (SELECT COUNT(*) FROM purchase_item WHERE purchase_id = p.id) as item_count,
               (SELECT SUM(paid_amount) FROM purchase_payment_history WHERE purchase_id = p.id) as total_paid
        FROM purchase p 
        LEFT JOIN suppliers s ON p.supplier_id = s.id 
        WHERE $where_clause 
        ORDER BY p.purchase_date DESC, p.id DESC 
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types . "ii", ...array_merge($params, [$limit, $offset]));
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$purchases = $stmt->get_result();

// Get all suppliers for filter dropdown
$suppliers = $conn->query("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC");

// Calculate statistics
$stats_sql = "SELECT 
                COUNT(*) as total_purchases,
                COALESCE(SUM(total), 0) as total_value,
                COALESCE(SUM(cgst_amount + sgst_amount), 0) as total_gst,
                COALESCE(AVG(total), 0) as avg_value,
                COUNT(CASE WHEN paid_amount >= total THEN 1 END) as paid_count,
                COUNT(CASE WHEN paid_amount > 0 AND paid_amount < total THEN 1 END) as partial_count,
                COUNT(CASE WHEN paid_amount IS NULL OR paid_amount = 0 THEN 1 END) as unpaid_count
              FROM purchase";
$stats = $conn->query($stats_sql)->fetch_assoc();

// Get recent activity for dashboard
$recent_activity = $conn->query("
    SELECT al.*, u.name as user_name 
    FROM activity_log al 
    LEFT JOIN users u ON al.user_id = u.id 
    WHERE al.action IN ('create', 'update', 'delete') 
    AND al.description LIKE '%purchase%'
    ORDER BY al.created_at DESC 
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" rel="stylesheet" />
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1e293b;
            --light: #f8fafc;
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
            transition: all 0.3s ease;
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
        .stat-icon.info { background: #e1f0ff; color: var(--info); }
        .stat-icon.danger { background: #fee2e2; color: var(--danger); }
        
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
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #edf2f9;
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Purchase Table */
        .purchase-table {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid #edf2f9;
        }
        
        .purchase-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .purchase-table th {
            background: #f8fafc;
            padding: 16px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .purchase-table td {
            padding: 16px;
            font-size: 14px;
            border-bottom: 1px solid #edf2f9;
            color: #334155;
        }
        
        .purchase-table tbody tr:hover {
            background: #f8fafc;
        }
        
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
        
        /* GST Badge */
        .gst-badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: #f2e8ff;
            color: #6b21a8;
        }
        
        .gst-badge.exclusive {
            background: #e8f2ff;
            color: #1e40af;
        }
        
        /* Payment Method Icons */
        .payment-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #475569;
            margin-right: 6px;
        }
        
        /* Action Buttons */
        .action-group {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .action-btn.view { background: var(--info); }
        .action-btn.edit { background: var(--primary); }
        .action-btn.delete { background: var(--danger); }
        
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        /* Mobile Cards */
        .mobile-cards {
            display: none;
        }
        
        .purchase-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
            border: 1px solid #edf2f9;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }
        
        .purchase-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        .purchase-card-title {
            font-weight: 600;
            color: var(--primary);
        }
        
        .purchase-card-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 13px;
        }
        
        .purchase-card-label {
            color: #64748b;
        }
        
        .purchase-card-value {
            font-weight: 500;
            color: #334155;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin-top: 24px;
            flex-wrap: wrap;
        }
        
        .page-item {
            list-style: none;
        }
        
        .page-link {
            display: block;
            padding: 8px 14px;
            border-radius: 8px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #475569;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .page-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .page-link.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
      .table-responsive{
       border-radius: 22px;
    overflow-x: auto;
      }
        
        /* Quick Stats Row */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 20px;
        }
        .card-body-custom{
            gap:30px;
    
        }
        .quick-stat-item {
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px;
            text-align: center;
        }
        .card-header-custom{
       
        .quick-stat-value {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .quick-stat-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
        }
        
        /* Date Range Picker Customization */
        .daterangepicker {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .desktop-table {
                display: none;
            }
            
            .mobile-cards {
                display: block;
            }
            
            .filter-row {
                flex-direction: column;
                gap: 16px;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
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

            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Manage Purchases</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">View, filter and manage all purchase orders</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="add-purchase.php" class="btn-primary-custom">
                        <i class="bi bi-plus-circle"></i> New Purchase
                    </a>
                    <a href="export-purchases.php" class="btn-outline-custom">
                        <i class="bi bi-download"></i> Export
                    </a>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="bi bi-cart-check"></i>
                    </div>
                    <div class="stat-label">Total Purchases</div>
                    <div class="stat-value"><?php echo number_format($stats['total_purchases']); ?></div>
                    <div class="stat-sub">All time purchases</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="bi bi-currency-rupee"></i>
                    </div>
                    <div class="stat-label">Total Value</div>
                    <div class="stat-value">₹<?php echo money2($stats['total_value']); ?></div>
                    <div class="stat-sub">Purchase amount</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="bi bi-percent"></i>
                    </div>
                    <div class="stat-label">GST Credit</div>
                    <div class="stat-value">₹<?php echo money2($stats['total_gst']); ?></div>
                    <div class="stat-sub">Total GST paid</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon info">
                        <i class="bi bi-graph-up"></i>
                    </div>
                    <div class="stat-label">Average Value</div>
                    <div class="stat-value">₹<?php echo money2($stats['avg_value']); ?></div>
                    <div class="stat-sub">Per purchase</div>
                </div>
            </div>

            <!-- Quick Status Stats -->
            <div class="quick-stats mb-4">
                <div class="quick-stat-item">
                    <div class="quick-stat-value"><?php echo $stats['paid_count']; ?></div>
                    <div class="quick-stat-label">
                        <span class="status-badge success">Paid</span>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-value"><?php echo $stats['partial_count']; ?></div>
                    <div class="quick-stat-label">
                        <span class="status-badge warning">Partial</span>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-value"><?php echo $stats['unpaid_count']; ?></div>
                    <div class="quick-stat-label">
                        <span class="status-badge danger">Unpaid</span>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-value"><?php echo $stats['total_purchases'] > 0 ? round(($stats['paid_count']/$stats['total_purchases'])*100, 1) : 0; ?>%</div>
                    <div class="quick-stat-label">Completion Rate</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="manage-purchases.php" id="filterForm">
                    <div class="filter-row">
                        <div class="filter-group">
                            <div class="filter-label">Search</div>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Purchase #, Supplier, Invoice..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Supplier</div>
                            <select class="form-select" name="supplier" id="supplierFilter">
                                <option value="">All Suppliers</option>
                                <?php while ($sup = $suppliers->fetch_assoc()): ?>
                                    <option value="<?php echo $sup['id']; ?>" 
                                        <?php echo $supplier_filter == $sup['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sup['supplier_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Status</div>
                            <select class="form-select" name="status">
                                <option value="">All Status</option>
                                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                <option value="unpaid" <?php echo $status_filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">GST Type</div>
                            <select class="form-select" name="gst_type">
                                <option value="">All</option>
                                <option value="exclusive" <?php echo $gst_type_filter === 'exclusive' ? 'selected' : ''; ?>>Exclusive</option>
                                <option value="inclusive" <?php echo $gst_type_filter === 'inclusive' ? 'selected' : ''; ?>>Inclusive</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Date Range</div>
                            <input type="text" class="form-control" id="dateRange" 
                                   placeholder="Select date range" 
                                   value="<?php echo $from_date && $to_date ? $from_date . ' - ' . $to_date : ''; ?>">
                            <input type="hidden" name="from_date" id="from_date" value="<?php echo $from_date; ?>">
                            <input type="hidden" name="to_date" id="to_date" value="<?php echo $to_date; ?>">
                        </div>

                        <div class="filter-group" style="flex: 0 0 auto;">
                            <div class="filter-label">&nbsp;</div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Filter
                                </button>
                                <a href="manage-purchases.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Clear
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Purchases Table - Desktop View -->
            <div class="purchase-table desktop-table">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Purchase #</th>
                            <th>Supplier</th>
                            <th>Items</th>
                            <th>Taxable</th>
                            <th>GST</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($purchases && $purchases->num_rows > 0): ?>
                            <?php while ($row = $purchases->fetch_assoc()): 
                                $paid = floatval($row['total_paid'] ?? 0);
                                $status = getPaymentStatus($row['total'], $paid);
                                $gst_total = $row['cgst_amount'] + $row['sgst_amount'];
                                $taxable = $row['total'] - $gst_total;
                            ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold"><?php echo date('d/m/Y', strtotime($row['purchase_date'])); ?></span>
                                        <br><small class="text-muted"><?php echo date('h:i A', strtotime($row['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?php echo htmlspecialchars($row['purchase_no']); ?></span>
                                        <?php if (!empty($row['gst_type'])): ?>
                                            <br><span class="gst-badge <?php echo $row['gst_type']; ?>">
                                                <?php echo ucfirst($row['gst_type']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['supplier_name'] ?? 'N/A'); ?></div>
                                        <?php if (!empty($row['phone'])): ?>
                                            <small class="text-muted">
                                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($row['phone']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark"><?php echo $row['item_count']; ?></span>
                                    </td>
                                    <td class="text-end">₹<?php echo money2($taxable); ?></td>
                                    <td class="text-end">
                                        ₹<?php echo money2($gst_total); ?>
                                        <?php if ($row['cgst'] > 0): ?>
                                            <br><small class="text-muted">(<?php echo $row['cgst']; ?>% + <?php echo $row['sgst']; ?>%)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold">₹<?php echo money2($row['total']); ?></td>
                                    <td class="text-end">₹<?php echo money2($paid); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $status['badge']; ?>">
                                            <?php echo $status['text']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <a href="view-purchase.php?id=<?php echo $row['id']; ?>" class="action-btn view" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <!--<a href="edit-purchase.php?id=<?php echo $row['id']; ?>" class="action-btn edit" title="Edit">-->
                                            <!--    <i class="bi bi-pencil"></i>-->
                                            <!--</a>-->
                                           
                                            <button type="button" class="action-btn delete" title="Delete"
                                                    onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['purchase_no']); ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-5">
                                    <i class="bi bi-inbox" style="font-size: 48px; color: #cbd5e1;"></i>
                                    <p class="mt-3 text-muted">No purchases found matching your criteria</p>
                                    <a href="add-purchase.php" class="btn btn-primary btn-sm">
                                        <i class="bi bi-plus-circle"></i> Create First Purchase
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Cards View -->
            <div class="mobile-cards">
                <?php 
                // Reset pointer for mobile view
                if ($purchases && $purchases->num_rows > 0) {
                    $purchases->data_seek(0);
                    while ($row = $purchases->fetch_assoc()): 
                        $paid = floatval($row['total_paid'] ?? 0);
                        $status = getPaymentStatus($row['total'], $paid);
                        $gst_total = $row['cgst_amount'] + $row['sgst_amount'];
                ?>
                        <div class="purchase-card">
                            <div class="purchase-card-header">
                                <span class="purchase-card-title">#<?php echo htmlspecialchars($row['purchase_no']); ?></span>
                                <span class="status-badge <?php echo $status['badge']; ?>"><?php echo $status['text']; ?></span>
                            </div>
                            
                            <div class="purchase-card-row">
                                <span class="purchase-card-label">Date:</span>
                                <span class="purchase-card-value"><?php echo date('d M Y', strtotime($row['purchase_date'])); ?></span>
                            </div>
                            
                            <div class="purchase-card-row">
                                <span class="purchase-card-label">Supplier:</span>
                                <span class="purchase-card-value"><?php echo htmlspecialchars($row['supplier_name'] ?? 'N/A'); ?></span>
                            </div>
                            
                            <div class="purchase-card-row">
                                <span class="purchase-card-label">Items:</span>
                                <span class="purchase-card-value"><?php echo $row['item_count']; ?> items</span>
                            </div>
                            
                            <div class="purchase-card-row">
                                <span class="purchase-card-label">Total:</span>
                                <span class="purchase-card-value fw-bold">₹<?php echo money2($row['total']); ?></span>
                            </div>
                            
                            <div class="purchase-card-row">
                                <span class="purchase-card-label">GST:</span>
                                <span class="purchase-card-value">₹<?php echo money2($gst_total); ?></span>
                            </div>
                            
                            <div class="purchase-card-row">
                                <span class="purchase-card-label">Paid:</span>
                                <span class="purchase-card-value">₹<?php echo money2($paid); ?></span>
                            </div>
                            
                            <?php if (!empty($row['gst_type'])): ?>
                                <div class="purchase-card-row">
                                    <span class="purchase-card-label">GST Type:</span>
                                    <span class="purchase-card-value">
                                        <span class="gst-badge <?php echo $row['gst_type']; ?>">
                                            <?php echo ucfirst($row['gst_type']); ?>
                                        </span>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-3 d-flex gap-2 justify-content-end">
                                <a href="view-purchase.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <!--<a href="edit-purchase.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">-->
                                <!--    <i class="bi bi-pencil"></i>-->
                                <!--</a>-->
                                
                                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['purchase_no']); ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endwhile; ?>
                    
                <?php } else{ ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 48px; color: #cbd5e1;"></i>
                        <p class="mt-3 text-muted">No purchases found</p>
                    </div>
                <?php }?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav class="pagination-container">
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page-1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $supplier_filter ? '&supplier='.$supplier_filter : ''; ?><?php echo $status_filter ? '&status='.$status_filter : ''; ?><?php echo $gst_type_filter ? '&gst_type='.$gst_type_filter : ''; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i >= $page - 2 && $i <= $page + 2): ?>
                                <li class="page-item">
                                    <a class="page-link <?php echo $i == $page ? 'active' : ''; ?>" 
                                       href="?page=<?php echo $i; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $supplier_filter ? '&supplier='.$supplier_filter : ''; ?><?php echo $status_filter ? '&status='.$status_filter : ''; ?><?php echo $gst_type_filter ? '&gst_type='.$gst_type_filter : ''; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page+1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $supplier_filter ? '&supplier='.$supplier_filter : ''; ?><?php echo $status_filter ? '&status='.$status_filter : ''; ?><?php echo $gst_type_filter ? '&gst_type='.$gst_type_filter : ''; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

            <!-- Recent Activity Section -->
            <?php if ($recent_activity && $recent_activity->num_rows > 0): ?>
                <div class="card-custom mt-4 p-4">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-clock-history me-2"></i>Recent Purchase Activity</h5>
                        <span class="badge bg-light text-dark">Last 10 actions</span>
                    </div>
                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="table table-sm ">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($activity = $recent_activity->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo date('d M h:i A', strtotime($activity['created_at'])); ?></td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($activity['action'] === 'create'): ?>
                                                    <span class="badge bg-success">Create</span>
                                                <?php elseif ($activity['action'] === 'update'): ?>
                                                    <span class="badge bg-info">Update</span>
                                                <?php elseif ($activity['action'] === 'delete'): ?>
                                                    <span class="badge bg-danger">Delete</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete purchase <strong id="deletePurchaseNo"></strong>?</p>
                <p class="text-danger"><small>This action cannot be undone. All associated items, payments, and GST credits will be removed and stock will be reverted.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="purchases.php" id="deleteForm">
                    <input type="hidden" name="action" value="delete_purchase">
                    <input type="hidden" name="purchase_id" id="deletePurchaseId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Purchase</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script>
$(document).ready(function() {
    // ============================================
    // Initialize Select2 for supplier filter
    // ============================================
    $('#supplierFilter').select2({
        placeholder: 'Select supplier',
        allowClear: true,
        width: '100%'
    });
    
    // ============================================
    // Initialize Date Range Picker
    // ============================================
    $('#dateRange').daterangepicker({
        autoUpdateInput: false,
        locale: {
            cancelLabel: 'Clear',
            format: 'YYYY-MM-DD'
        },
        ranges: {
            'Today': [moment(), moment()],
            'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            'Last 7 Days': [moment().subtract(6, 'days'), moment()],
            'This Month': [moment().startOf('month'), moment().endOf('month')],
            'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        }
    });
    
    $('#dateRange').on('apply.daterangepicker', function(ev, picker) {
        $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
        $('#from_date').val(picker.startDate.format('YYYY-MM-DD'));
        $('#to_date').val(picker.endDate.format('YYYY-MM-DD'));
        
        // Auto submit form when date range is selected
        $('#filterForm').submit();
    });
    
    $('#dateRange').on('cancel.daterangepicker', function(ev, picker) {
        $(this).val('');
        $('#from_date').val('');
        $('#to_date').val('');
        
        // Auto submit form when date range is cleared
        $('#filterForm').submit();
    });
    
    // Set initial date range value if exists
    <?php if (!empty($from_date) && !empty($to_date)): ?>
        $('#dateRange').val('<?php echo $from_date; ?> - <?php echo $to_date; ?>');
    <?php endif; ?>

    // ============================================
    // Auto-submit filter on change
    // ============================================
    $('#supplierFilter, select[name="status"], select[name="gst_type"]').on('change', function() {
        $('#filterForm').submit();
    });

    // ============================================
    // Search input with debounce (submit after typing stops)
    // ============================================
    let searchTimer;
    $('input[name="search"]').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            $('#filterForm').submit();
        }, 800); // Wait 800ms after user stops typing
    });

    // ============================================
    // Initialize tooltips
    // ============================================
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // ============================================
    // Table row click to view purchase (except action buttons)
    // ============================================
    $('.purchase-table tbody tr').on('click', function(e) {
        // Don't redirect if clicking on action buttons or links
        if (!$(e.target).closest('a, button').length) {
            var purchaseId = $(this).data('id');
            if (purchaseId) {
                window.location.href = 'view-purchase.php?id=' + purchaseId;
            }
        }
    });

    // ============================================
    // Mobile view toggle (optional)
    // ============================================
    function checkMobileView() {
        if ($(window).width() <= 768) {
            $('.desktop-table').hide();
            $('.mobile-cards').show();
        } else {
            $('.desktop-table').show();
            $('.mobile-cards').hide();
        }
    }
    
    checkMobileView();
    $(window).resize(checkMobileView);

    // ============================================
    // Export functionality with loading state
    // ============================================
    window.exportPurchases = function() {
        // Show loading spinner
        const exportBtn = $('a[href*="export-purchases.php"]');
        const originalText = exportBtn.html();
        exportBtn.html('<span class="spinner-border spinner-border-sm me-2"></span>Exporting...').addClass('disabled');
        
        // Redirect to export
        window.location.href = 'export-purchases.php?' + $('#filterForm').serialize();
        
        // Restore button after a delay
        setTimeout(() => {
            exportBtn.html(originalText).removeClass('disabled');
        }, 3000);
    };

    // ============================================
    // Print purchase function
    // ============================================
    window.printPurchase = function(id) {
        window.open('print-purchase.php?id=' + id, '_blank');
    };

});

// ============================================
// Delete confirmation with AJAX
// ============================================
function confirmDelete(id, purchaseNo) {
    // Check if SweetAlert2 is available
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete Purchase?',
            html: `
                <div class="text-start">
                    <p>Are you sure you want to delete purchase <strong>#${purchaseNo}</strong>?</p>
                    <div class="alert alert-danger p-2" style="font-size: 13px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>This action cannot be undone!</strong>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">The following will happen:</small>
                        <ul class="text-danger mt-2" style="font-size: 13px;">
                            <li><i class="bi bi-dash-circle me-2"></i>Purchase record will be permanently deleted</li>
                            <li><i class="bi bi-dash-circle me-2"></i>Stock quantities will be reduced by the purchased amount</li>
                            <li><i class="bi bi-dash-circle me-2"></i>All payment records will be removed</li>
                            <li><i class="bi bi-dash-circle me-2"></i>GST credits will be removed</li>
                        </ul>
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="bi bi-trash me-2"></i>Yes, delete it!',
            cancelButtonText: '<i class="bi bi-x-circle me-2"></i>Cancel',
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                deletePurchaseAJAX(id, purchaseNo);
            }
        });
    } else {
        // Fallback to Bootstrap modal
        $('#deletePurchaseId').val(id);
        $('#deletePurchaseNo').text(purchaseNo);
        
        // Update modal message with stock warning
        $('#deleteModal .modal-body').html(`
            <p>Are you sure you want to delete purchase <strong>#${purchaseNo}</strong>?</p>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                This will reduce stock quantities and remove all associated records.
            </div>
            <p class="text-danger"><small>This action cannot be undone.</small></p>
        `);
        
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }
}

// ============================================
// AJAX Delete Function
// ============================================
function deletePurchaseAJAX(id, purchaseNo) {
    // Show loading state
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Deleting Purchase...',
            html: `
                <div class="text-center">
                    <div class="spinner-border text-danger mb-3" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p>Please wait while we delete the purchase and update stock.</p>
                    <small class="text-muted">Purchase #${purchaseNo}</small>
                </div>
            `,
            allowOutsideClick: false,
            showConfirmButton: false,
            showCancelButton: false
        });
    }

    // Create form data
    const formData = new FormData();
    formData.append('purchase_id', id);
    formData.append('ajax', '1');

    // Send AJAX request
    fetch('delete-purchase.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Show success message
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Deleted Successfully!',
                    html: `
                        <div class="text-center">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 48px;"></i>
                            <p class="mt-3">${data.message}</p>
                            <div class="bg-light p-3 rounded text-start mt-3">
                                <small class="text-muted d-block mb-2">Delete Summary:</small>
                                <div class="d-flex justify-content-between">
                                    <span>Purchase #:</span>
                                    <strong>${data.data.purchase_no}</strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Items Reverted:</span>
                                    <strong>${data.data.total_items}</strong>
                                </div>
                                ${data.data.reverted_items ? `
                                    <div class="mt-2">
                                        <small class="text-muted">Stock updates:</small>
                                        ${data.data.reverted_items.map(item => `
                                            <div class="d-flex justify-content-between small">
                                                <span>${item.cat_name}:</span>
                                                <span>-${item.qty} pcs</span>
                                            </div>
                                        `).join('')}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    `,
                    icon: 'success',
                    showConfirmButton: true,
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'OK'
                }).then(() => {
                    // Remove the deleted row from table without reloading
                    removePurchaseRow(id);
                });
            } else {
                alert(data.message);
                location.reload();
            }
        } else {
            // Show error message
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Error!',
                    html: `
                        <div class="text-center">
                            <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size: 48px;"></i>
                            <p class="mt-3 text-danger">${data.message}</p>
                            <small class="text-muted">Please try again or contact support.</small>
                        </div>
                    `,
                    icon: 'error',
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Try Again'
                });
            } else {
                alert('Error: ' + data.message);
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Error!',
                html: `
                    <div class="text-center">
                        <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size: 48px;"></i>
                        <p class="mt-3">An unexpected error occurred.</p>
                        <small class="text-muted">${error.message}</small>
                    </div>
                `,
                icon: 'error',
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'OK'
            });
        } else {
            alert('An unexpected error occurred. Please try again.');
        }
    });
}

// ============================================
// Remove purchase row from table without reload
// ============================================
function removePurchaseRow(id) {
    // Remove from desktop table
    $(`tr[data-id="${id}"]`).fadeOut(300, function() {
        $(this).remove();
        
        // Check if table is empty
        if ($('#itemsBody tr').length === 0) {
            $('#itemsBody').html('<tr><td colspan="10" class="text-center py-4 text-muted">No purchases found</td></tr>');
        }
        
        // Update statistics (you might want to fetch new stats via AJAX)
        updateStatistics();
    });
    
    // Remove from mobile cards
    $(`.purchase-card[data-id="${id}"]`).fadeOut(300, function() {
        $(this).remove();
    });
}

// ============================================
// Update statistics after delete (AJAX)
// ============================================
function updateStatistics() {
    // You can implement this to fetch updated stats via AJAX
    // For now, just reload the stats section
    $.ajax({
        url: 'get-purchase-stats.php',
        method: 'GET',
        success: function(response) {
            // Update stats cards with new data
            // This requires a separate endpoint to fetch updated statistics
        },
        error: function() {
            // If stats update fails, reload the page after a delay
            setTimeout(() => {
                location.reload();
            }, 2000);
        }
    });
}

// ============================================
// Bulk delete function (if needed)
// ============================================
function bulkDelete() {
    const selectedIds = [];
    $('input[name="purchase_ids[]"]:checked').each(function() {
        selectedIds.push($(this).val());
    });
    
    if (selectedIds.length === 0) {
        Swal.fire({
            title: 'No Selection',
            text: 'Please select at least one purchase to delete.',
            icon: 'warning',
            confirmButtonColor: '#6c757d'
        });
        return;
    }
    
    Swal.fire({
        title: 'Bulk Delete?',
        html: `Are you sure you want to delete <strong>${selectedIds.length}</strong> purchases?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete all',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Implement bulk delete AJAX
            bulkDeleteAJAX(selectedIds);
        }
    });
}

// ============================================
// Intercept Bootstrap modal delete form
// ============================================
$(document).ready(function() {
    $('#deleteForm').on('submit', function(e) {
        e.preventDefault();
        
        const id = $('#deletePurchaseId').val();
        const purchaseNo = $('#deletePurchaseNo').text();
        
        // Close the Bootstrap modal
        var deleteModal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
        if (deleteModal) {
            deleteModal.hide();
        }
        
        // Use AJAX delete
        deletePurchaseAJAX(id, purchaseNo);
    });

    // ============================================
    // Add data-id attribute to table rows
    // ============================================
    $('.purchase-table tbody tr').each(function() {
        const deleteBtn = $(this).find('.action-btn.delete');
        if (deleteBtn.length) {
            const onclickAttr = deleteBtn.attr('onclick');
            if (onclickAttr) {
                const match = onclickAttr.match(/confirmDelete\((\d+)/);
                if (match && match[1]) {
                    $(this).attr('data-id', match[1]);
                }
            }
        }
    });

    // ============================================
    // Add data-id attribute to mobile cards
    // ============================================
    $('.purchase-card').each(function() {
        const deleteBtn = $(this).find('.btn-danger');
        if (deleteBtn.length) {
            const onclickAttr = deleteBtn.attr('onclick');
            if (onclickAttr) {
                const match = onclickAttr.match(/confirmDelete\((\d+)/);
                if (match && match[1]) {
                    $(this).attr('data-id', match[1]);
                }
            }
        }
    });

    // ============================================
    // Keyboard shortcuts
    // ============================================
    $(document).on('keydown', function(e) {
        // Alt + N for new purchase
        if (e.altKey && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'add-purchase.php';
        }
        
        // Alt + E for export
        if (e.altKey && e.key === 'e') {
            e.preventDefault();
            exportPurchases();
        }
        
        // Escape to clear filters
        if (e.key === 'Escape' && !$('input, select').is(':focus')) {
            window.location.href = 'purchases.php';
        }
    });

    // ============================================
    // Show/hide filter panel on mobile
    // ============================================
    $('#toggleFilters').on('click', function() {
        $('.filter-section').slideToggle();
    });

    // ============================================
    // Initialize tooltips for action buttons
    // ============================================
    $('.action-btn').each(function() {
        const title = $(this).attr('title');
        if (title) {
            $(this).attr('data-bs-toggle', 'tooltip');
            $(this).attr('data-bs-placement', 'top');
            $(this).attr('title', title);
        }
    });

    // ============================================
    // Refresh button
    // ============================================
    $('#refreshData').on('click', function() {
        location.reload();
    });

    // ============================================
    // Print all purchases (optional)
    // ============================================
    $('#printAll').on('click', function() {
        window.open('print-purchases-list.php?' + $('#filterForm').serialize(), '_blank');
    });
});
</script>

<!-- Add this CSS to style the delete modal -->
<style>
/* SweetAlert2 customizations */
.swal2-popup {
    border-radius: 20px !important;
    padding: 20px !important;
}

.swal2-title {
    color: #1e293b !important;
    font-size: 24px !important;
}

.swal2-html-container {
    color: #475569 !important;
}

.swal2-confirm {
    border-radius: 10px !important;
    padding: 10px 30px !important;
    font-weight: 600 !important;
}

.swal2-cancel {
    border-radius: 10px !important;
    padding: 10px 30px !important;
    font-weight: 600 !important;
}

/* Custom animation for row removal */
@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}

.fade-out {
    animation: fadeOut 0.3s ease forwards;
}

/* Tooltips */
.tooltip {
    font-size: 12px;
}

.tooltip-inner {
    border-radius: 8px;
    padding: 6px 12px;
}

/* Keyboard shortcut hint */
.shortcut-hint {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: rgba(0,0,0,0.8);
    color: white;
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 12px;
    z-index: 1000;
    opacity: 0.5;
    transition: opacity 0.3s;
}

.shortcut-hint:hover {
    opacity: 1;
}

.shortcut-hint kbd {
    background: #4a5568;
    border-radius: 4px;
    padding: 2px 6px;
    margin: 0 2px;
}
</style>


</body>
</html>