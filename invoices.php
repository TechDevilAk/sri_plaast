<?php
session_start();
$currentPage = 'invoices';
$pageTitle = 'Invoices Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view invoices, but only admin can modify/delete
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

function getStatusBadge($pending_amount) {
    if ((float)$pending_amount > 0) {
        return '<span class="pending-badge"><i class="bi bi-exclamation-circle"></i> Pending ₹' . number_format((float)$pending_amount, 2) . '</span>';
    } else {
        return '<span class="paid-badge"><i class="bi bi-check-circle"></i> Paid</span>';
    }
}

function getPaymentMethodIcon($method) {
    switch ($method) {
        case 'cash': return 'cash';
        case 'card': return 'credit-card';
        case 'upi': return 'phone';
        case 'bank': return 'bank';
        case 'cheque': return 'journal-check';
        case 'credit': return 'journal-bookmark-fill';
        case 'mixed': return 'shuffle';
        default: return 'wallet2';
    }
}

$success = '';
$error = '';

// Check if user is admin for action buttons
$is_admin = ($_SESSION['user_role'] === 'admin');

// Handle delete invoice (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_invoice' && isset($_POST['invoice_id']) && is_numeric($_POST['invoice_id'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete invoices.';
    } else {
        $deleteId = intval($_POST['invoice_id']);

        $inv_query = $conn->prepare("SELECT inv_num, customer_id, total FROM invoice WHERE id = ?");
        $inv_query->bind_param("i", $deleteId);
        $inv_query->execute();
        $inv_result = $inv_query->get_result();
        $inv_data = $inv_result->fetch_assoc();
        $inv_query->close();

        if ($inv_data) {
            $conn->begin_transaction();
            try {
                // IMPORTANT: reverse stock using converted quantity if available, else quantity
                $items_query = $conn->prepare("SELECT cat_id, quantity FROM invoice_item WHERE invoice_id = ?");
                $items_query->bind_param("i", $deleteId);
                $items_query->execute();
                $items_result = $items_query->get_result();

                while ($item = $items_result->fetch_assoc()) {
                    if (!empty($item['cat_id'])) {
                        $qtyToRestore = (float)$item['quantity'];
                        $update_stock = $conn->prepare("UPDATE category SET total_quantity = total_quantity + ? WHERE id = ?");
                        $update_stock->bind_param("di", $qtyToRestore, $item['cat_id']);
                        if (!$update_stock->execute()) {
                            throw new Exception("Stock reverse failed");
                        }
                        $update_stock->close();
                    }
                }
                $items_query->close();

                $stmt = $conn->prepare("DELETE FROM invoice WHERE id = ?");
                $stmt->bind_param("i", $deleteId);

                if ($stmt->execute()) {
                    $stmt->close();

                    $log_desc = "Deleted invoice: " . $inv_data['inv_num'] . " (Total: ₹" . number_format((float)$inv_data['total'], 2) . ")";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    $log_stmt->close();

                    $conn->commit();
                    $success = "Invoice deleted successfully and stock updated.";
                } else {
                    throw new Exception("Failed to delete invoice");
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to delete invoice: " . $e->getMessage();
            }
        } else {
            $error = "Invoice not found.";
        }
    }
}

// Handle payment update (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_payment' && isset($_POST['invoice_id']) && is_numeric($_POST['invoice_id'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to update payments.';
    } else {
        $invoice_id = intval($_POST['invoice_id']);
        $paid_amount = floatval($_POST['paid_amount'] ?? 0);
        $pending_amount = floatval($_POST['pending_amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';

        if (!in_array($payment_method, ['cash','card','upi','bank','credit','cheque','mixed'], true)) {
            $payment_method = 'cash';
        }

        $stmt = $conn->prepare("UPDATE invoice SET cash_received = ?, pending_amount = ?, payment_method = ? WHERE id = ?");
        $stmt->bind_param("ddsi", $paid_amount, $pending_amount, $payment_method, $invoice_id);

        if ($stmt->execute()) {
            $stmt->close();

            $inv_query = $conn->prepare("SELECT inv_num FROM invoice WHERE id = ?");
            $inv_query->bind_param("i", $invoice_id);
            $inv_query->execute();
            $inv_data = $inv_query->get_result()->fetch_assoc();
            $inv_query->close();

            $log_desc = "Updated payment for invoice: " . ($inv_data['inv_num'] ?? ('#'.$invoice_id)) .
                        " (Paid: ₹" . number_format($paid_amount, 2) .
                        ", Pending: ₹" . number_format($pending_amount, 2) .
                        ", Method: " . ucfirst($payment_method) . ")";
            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'payment', ?)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            $log_stmt->close();

            $success = "Payment updated successfully.";
        } else {
            $error = "Failed to update payment.";
        }
    }
}

// Filters
$filterStatus         = $_GET['filter_status'] ?? '';
$filterCustomer       = $_GET['customer_id'] ?? '';
$filterDateFrom       = $_GET['date_from'] ?? '';
$filterDateTo         = $_GET['date_to'] ?? '';
$filterSearch         = trim($_GET['search'] ?? '');
$filterPaymentMethod  = $_GET['payment_method'] ?? '';
$filterGstType        = $_GET['gst_type'] ?? '';

$where = "1=1";
$params = [];
$types = "";

if ($filterSearch !== '') {
    $where .= " AND (i.inv_num LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ? OR i.customer_name LIKE ?)";
    $searchTerm = "%$filterSearch%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ssss";
}

if (!empty($filterCustomer) && is_numeric($filterCustomer)) {
    $where .= " AND i.customer_id = ?";
    $params[] = (int)$filterCustomer;
    $types .= "i";
}

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

if (!empty($filterPaymentMethod)) {
    $where .= " AND i.payment_method = ?";
    $params[] = $filterPaymentMethod;
    $types .= "s";
}

// GST / Non-GST filter
if ($filterGstType === 'gst') {
    $where .= " AND COALESCE(i.is_gst,1) = 1";
} elseif ($filterGstType === 'non_gst') {
    $where .= " AND COALESCE(i.is_gst,1) = 0";
}

if ($filterStatus === 'pending') {
    $where .= " AND i.pending_amount > 0";
} elseif ($filterStatus === 'paid') {
    $where .= " AND i.pending_amount = 0";
} elseif ($filterStatus === 'today') {
    $where .= " AND DATE(i.created_at) = CURDATE()";
} elseif ($filterStatus === 'week') {
    $where .= " AND YEARWEEK(i.created_at) = YEARWEEK(CURDATE())";
} elseif ($filterStatus === 'month') {
    $where .= " AND MONTH(i.created_at) = MONTH(CURDATE()) AND YEAR(i.created_at) = YEAR(CURDATE())";
}

$sql = "SELECT i.*, c.customer_name AS customer_master_name, c.phone,
        (SELECT COUNT(*) FROM invoice_item WHERE invoice_id = i.id) as item_count
        FROM invoice i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE $where
        ORDER BY i.created_at DESC";

$invoices = null;
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $invoices = $stmt->get_result();
} else {
    $invoices = $conn->query($sql);
}

// Get customers for filter dropdown
$customers = $conn->query("SELECT id, customer_name FROM customers ORDER BY customer_name ASC");

// Function to get filtered stats
function getFilteredStats($conn, $where, $params, $types) {
    // Today's stats with filters
    $todayWhere = $where . " AND DATE(i.created_at) = CURDATE()";
    $todayStats = getStatsQuery($conn, $todayWhere, $params, $types);
    
    // Week stats with filters
    $weekWhere = $where . " AND YEARWEEK(i.created_at) = YEARWEEK(CURDATE())";
    $weekStats = getStatsQuery($conn, $weekWhere, $params, $types);
    
    // Month stats with filters
    $monthWhere = $where . " AND MONTH(i.created_at) = MONTH(CURDATE()) AND YEAR(i.created_at) = YEAR(CURDATE())";
    $monthStats = getStatsQuery($conn, $monthWhere, $params, $types);
    
    // Total stats with filters
    $totalStats = getStatsQuery($conn, $where, $params, $types);
    
    return [$todayStats, $weekStats, $monthStats, $totalStats];
}

function getStatsQuery($conn, $where, $params, $types) {
    $sql = "SELECT COUNT(*) as count, COALESCE(SUM(total), 0) as total, COALESCE(SUM(pending_amount), 0) as pending
            FROM invoice i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE $where";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        $stmt->close();
    } else {
        $result = $conn->query($sql);
        $stats = $result->fetch_assoc();
    }
    
    return $stats;
}

// Get filtered stats
list($todayStats, $weekStats, $monthStats, $totalStats) = getFilteredStats($conn, $where, $params, $types);

// GST stats with filters
$gstWhere = $where;
$gstParams = $params;
$gstTypes = $types;

$gstSql = "SELECT 
    SUM(CASE WHEN COALESCE(i.is_gst,1)=1 THEN 1 ELSE 0 END) AS gst_count,
    SUM(CASE WHEN COALESCE(i.is_gst,1)=0 THEN 1 ELSE 0 END) AS non_gst_count
    FROM invoice i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE $gstWhere";

if (!empty($gstParams)) {
    $stmt = $conn->prepare($gstSql);
    $stmt->bind_param($gstTypes, ...$gstParams);
    $stmt->execute();
    $gstStats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $gstStats = $conn->query($gstSql)->fetch_assoc();
}

// Payment method stats with filters
$paymentSql = "SELECT i.payment_method, COUNT(*) as count, COALESCE(SUM(i.total), 0) as total
               FROM invoice i
               LEFT JOIN customers c ON i.customer_id = c.id
               WHERE $where
               GROUP BY i.payment_method";

if (!empty($params)) {
    $stmt = $conn->prepare($paymentSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $paymentMethods = $stmt->get_result();
    $stmt->close();
} else {
    $paymentMethods = $conn->query($paymentSql);
}

// Top products stats with filters
$topProductsSql = "SELECT
                    COALESCE(ii.product_name, ii.cat_name) as item_name,
                    SUM(ii.quantity) as total_qty,
                    SUM(ii.total) as total_amount
                  FROM invoice_item ii
                  INNER JOIN invoice i ON ii.invoice_id = i.id
                  LEFT JOIN customers c ON i.customer_id = c.id
                  WHERE $where
                  GROUP BY COALESCE(ii.product_name, ii.cat_name)
                  ORDER BY total_qty DESC
                  LIMIT 5";

if (!empty($params)) {
    $stmt = $conn->prepare($topProductsSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $topProducts = $stmt->get_result();
    $stmt->close();
} else {
    $topProducts = $conn->query($topProductsSql);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
    <style>
        .invoice-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, #2463eb 0%, #1e4fbd 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
        }
        .invoice-avatar.small { width: 32px; height: 32px; font-size: 14px; }
        .invoice-info-cell { display: flex; align-items: center; gap: 12px; }
        .invoice-number-text { font-weight: 600; color: var(--text-primary); margin-bottom: 2px; }
        .invoice-meta-text { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 8px; }

        .gst-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
        }
        .gst-badge.gst { background: #dcfce7; color: #166534; }
        .gst-badge.non-gst { background: #fef3c7; color: #92400e; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card-custom {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        .stat-card-custom:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .stat-value-large { font-size: 28px; font-weight: 700; color: #1e293b; line-height: 1.2; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 4px; }

        .pending-badge {
            background: #fee2e2;
            color: #dc2626;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .paid-badge {
            background: #dcfce7;
            color: #16a34a;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-tab {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
            border: 1px solid #e2e8f0;
            color: #475569;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .filter-tab:hover { background: #f8fafc; border-color: #94a3b8; }
        .filter-tab.active { background: #2463eb; border-color: #2463eb; color: white; }
        .filter-tab.active .badge { background: white; color: #2463eb; }

        .quick-stats {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            border: 1px solid #eef2f6;
        }
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        .customer-info { display: flex; align-items: center; gap: 8px; }
        .customer-avatar-small {
            width: 32px; height: 32px; border-radius: 50%;
            background: #f1f5f9; display: flex; align-items: center; justify-content: center;
            font-weight: 600; color: #475569;
        }
        .payment-method-badge {
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            color: #475569;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            border: 1px solid #eef2f6;
        }
        .clear-filter { font-size: 12px; color: #2463eb; text-decoration: none; }
        .clear-filter:hover { text-decoration: underline; }

        .dt-buttons { margin-bottom: 15px; }
        .dt-button { margin-right: 5px; padding: 5px 15px !important; border-radius: 5px !important; font-size: 13px !important; }

        .payment-method-selector { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 5px; }
        .payment-method-option { flex: 1; min-width: 80px; }
        .payment-method-option input[type="radio"] { display: none; }
        .payment-method-option label {
            display: flex; flex-direction: column; align-items: center;
            padding: 10px 5px; background: #f8fafc; border: 2px solid #e2e8f0;
            border-radius: 8px; cursor: pointer; transition: all 0.2s; font-size: 12px;
        }
        .payment-method-option input[type="radio"]:checked + label {
            border-color: #2463eb; background: #eef2ff; color: #2463eb;
        }
        .payment-method-option label i { font-size: 20px; margin-bottom: 4px; }
        .payment-method-option label:hover { border-color: #94a3b8; }

        .btn-export {
            background: #16a34a;
            color: #fff !important;
            border: none;
        }
        .btn-export:hover { background: #15803d; }
        
        .filter-indicator {
            background: #2463eb;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }
        
        .stat-filter-hint {
            font-size: 10px;
            color: #64748b;
            margin-top: 4px;
        }

        /* Edit button styles */
        .btn-edit {
            background: #f59e0b;
            color: white;
            border: none;
        }
        .btn-edit:hover {
            background: #d97706;
        }
        .action-group {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: center;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Invoices Management</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">View and manage all sales invoices (GST / Non-GST)</p>
                    <?php if ($filterSearch || $filterCustomer || $filterDateFrom || $filterDateTo || $filterPaymentMethod || $filterStatus || $filterGstType): ?>
                        <span class="filter-indicator">
                            <i class="bi bi-funnel"></i> Filtered View
                        </span>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <a href="new-sale.php" class="btn-primary-custom">
                        <i class="bi bi-plus-circle"></i> New Sale
                    </a>
                    <a href="export_invoices.php?format=excel<?php echo htmlspecialchars($_SERVER['QUERY_STRING'] ? '&' . $_SERVER['QUERY_STRING'] : ''); ?>" class="btn btn-sm btn-export">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </a>
                    <?php if (!$is_admin): ?>
                        <span class="permission-badge"><i class="bi bi-eye"></i> View Only Mode</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bootstrap Alerts instead of JavaScript alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards - Dynamically updated based on filters -->
            <div class="stats-grid">
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo (int)$todayStats['count']; ?></div>
                            <div class="stat-label">Today's Invoices</div>
                            <small class="text-muted"><?php echo formatCurrency($todayStats['total']); ?></small>
                            <?php if ($filterSearch || $filterCustomer || $filterPaymentMethod || $filterGstType): ?>
                                <div class="stat-filter-hint"><i class="bi bi-funnel"></i> Filtered</div>
                            <?php endif; ?>
                        </div>
                        <div class="stat-icon blue" style="width: 48px; height: 48px;">
                            <i class="bi bi-calendar-day"></i>
                        </div>
                    </div>
                    <?php if ((float)$todayStats['pending'] > 0): ?>
                        <div class="mt-2"><span class="pending-badge">Pending: <?php echo formatCurrency($todayStats['pending']); ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo (int)$weekStats['count']; ?></div>
                            <div class="stat-label">This Week</div>
                            <small class="text-muted"><?php echo formatCurrency($weekStats['total']); ?></small>
                            <?php if ($filterSearch || $filterCustomer || $filterPaymentMethod || $filterGstType): ?>
                                <div class="stat-filter-hint"><i class="bi bi-funnel"></i> Filtered</div>
                            <?php endif; ?>
                        </div>
                        <div class="stat-icon green" style="width: 48px; height: 48px;">
                            <i class="bi bi-calendar-week"></i>
                        </div>
                    </div>
                    <?php if ((float)$weekStats['pending'] > 0): ?>
                        <div class="mt-2"><span class="pending-badge">Pending: <?php echo formatCurrency($weekStats['pending']); ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo (int)$monthStats['count']; ?></div>
                            <div class="stat-label">This Month</div>
                            <small class="text-muted"><?php echo formatCurrency($monthStats['total']); ?></small>
                            <?php if ($filterSearch || $filterCustomer || $filterPaymentMethod || $filterGstType): ?>
                                <div class="stat-filter-hint"><i class="bi bi-funnel"></i> Filtered</div>
                            <?php endif; ?>
                        </div>
                        <div class="stat-icon purple" style="width: 48px; height: 48px;">
                            <i class="bi bi-calendar-month"></i>
                        </div>
                    </div>
                    <?php if ((float)$monthStats['pending'] > 0): ?>
                        <div class="mt-2"><span class="pending-badge">Pending: <?php echo formatCurrency($monthStats['pending']); ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo (int)$totalStats['count']; ?></div>
                            <div class="stat-label">Filtered Total</div>
                            <small class="text-muted"><?php echo formatCurrency($totalStats['total']); ?></small>
                            <?php if ($filterSearch || $filterCustomer || $filterDateFrom || $filterDateTo || $filterPaymentMethod || $filterStatus || $filterGstType): ?>
                                <div class="stat-filter-hint"><i class="bi bi-funnel"></i> Currently filtered</div>
                            <?php else: ?>
                                <div class="stat-filter-hint"><i class="bi bi-eye"></i> All invoices</div>
                            <?php endif; ?>
                        </div>
                        <div class="stat-icon orange" style="width: 48px; height: 48px;">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                    <?php if ((float)$totalStats['pending'] > 0): ?>
                        <div class="mt-2"><span class="pending-badge">Pending: <?php echo formatCurrency($totalStats['pending']); ?></span></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats Row - Dynamically updated based on filters -->
            <div class="quick-stats">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon blue" style="width: 40px; height: 40px;">
                                <i class="bi bi-credit-card"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">Payment Methods 
                                    <?php if ($filterSearch || $filterCustomer || $filterDateFrom || $filterDateTo || $filterGstType): ?>
                                        <span class="badge bg-info" style="font-size: 8px;">Filtered</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-1">
                                    <?php if ($paymentMethods && $paymentMethods->num_rows > 0): 
                                        $paymentMethods->data_seek(0); ?>
                                        <?php while ($pm = $paymentMethods->fetch_assoc()): ?>
                                            <span class="payment-method-badge">
                                                <i class="bi bi-<?php echo getPaymentMethodIcon($pm['payment_method']); ?>"></i>
                                                <?php echo ucfirst($pm['payment_method']); ?>: <?php echo (int)$pm['count']; ?>
                                            </span>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No data for current filters</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon orange" style="width: 40px; height: 40px;">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">GST / Non-GST 
                                    <?php if ($filterSearch || $filterCustomer || $filterDateFrom || $filterDateTo || $filterPaymentMethod): ?>
                                        <span class="badge bg-info" style="font-size: 8px;">Filtered</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2 mt-1">
                                    <span class="gst-badge gst"><i class="bi bi-receipt-cutoff"></i> GST: <?php echo (int)($gstStats['gst_count'] ?? 0); ?></span>
                                    <span class="gst-badge non-gst"><i class="bi bi-file-earmark"></i> Non-GST: <?php echo (int)($gstStats['non_gst_count'] ?? 0); ?></span>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    <?php if ($filterGstType === 'gst'): ?>
                                        Showing only GST invoices
                                    <?php elseif ($filterGstType === 'non_gst'): ?>
                                        Showing only Non-GST invoices
                                    <?php else: ?>
                                        All invoice types
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon green" style="width: 40px; height: 40px;">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">Top Products 
                                    <?php if ($filterSearch || $filterCustomer || $filterDateFrom || $filterDateTo || $filterPaymentMethod || $filterGstType): ?>
                                        <span class="badge bg-info" style="font-size: 8px;">Filtered</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php
                                    if ($topProducts && $topProducts->num_rows > 0) {
                                        $topProducts->data_seek(0);
                                        $count = 0;
                                        while ($prod = $topProducts->fetch_assoc()):
                                            if ($count >= 3) break;
                                            $count++;
                                    ?>
                                        <small><?php echo htmlspecialchars($prod['item_name'] ?: 'Unknown'); ?> (<?php echo number_format((float)$prod['total_qty'], 0); ?> pcs)</small><br>
                                    <?php
                                        endwhile;
                                    } else {
                                        echo '<small class="text-muted">No sales data for current filters</small>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-section">
                <div class="row g-3">
                    <div class="col-md-12">
                        <div class="filter-tabs">
                            <a href="invoices.php" class="filter-tab <?php echo !$filterStatus ? 'active' : ''; ?>">
                                <i class="bi bi-receipt"></i> All Invoices
                                <span class="badge bg-white text-dark"><?php echo (int)$totalStats['count']; ?></span>
                            </a>
                            <a href="invoices.php?filter_status=today<?php echo buildQueryString(['filter_status']); ?>" class="filter-tab <?php echo $filterStatus === 'today' ? 'active' : ''; ?>">
                                <i class="bi bi-calendar-day"></i> Today
                                <span class="badge bg-white text-dark"><?php echo (int)$todayStats['count']; ?></span>
                            </a>
                            <a href="invoices.php?filter_status=week<?php echo buildQueryString(['filter_status']); ?>" class="filter-tab <?php echo $filterStatus === 'week' ? 'active' : ''; ?>">
                                <i class="bi bi-calendar-week"></i> This Week
                                <span class="badge bg-white text-dark"><?php echo (int)$weekStats['count']; ?></span>
                            </a>
                            <a href="invoices.php?filter_status=month<?php echo buildQueryString(['filter_status']); ?>" class="filter-tab <?php echo $filterStatus === 'month' ? 'active' : ''; ?>">
                                <i class="bi bi-calendar-month"></i> This Month
                                <span class="badge bg-white text-dark"><?php echo (int)$monthStats['count']; ?></span>
                            </a>
                            <a href="invoices.php?filter_status=pending<?php echo buildQueryString(['filter_status']); ?>" class="filter-tab <?php echo $filterStatus === 'pending' ? 'active' : ''; ?>">
                                <i class="bi bi-exclamation-circle"></i> Pending
                                <span class="badge bg-white text-dark"><?php echo (float)$totalStats['pending'] > 0 ? '₹' . number_format((float)$totalStats['pending'], 0) : '0'; ?></span>
                            </a>
                            <a href="invoices.php?filter_status=paid<?php echo buildQueryString(['filter_status']); ?>" class="filter-tab <?php echo $filterStatus === 'paid' ? 'active' : ''; ?>">
                                <i class="bi bi-check-circle"></i> Paid
                            </a>
                        </div>
                    </div>
                </div>

                <form method="GET" action="invoices.php" class="row g-3 mt-2" id="filterForm">
                    <!-- Preserve existing filters -->
                    <?php if ($filterStatus): ?>
                        <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                    <?php endif; ?>
                    
                    <div class="col-md-2">
                        <input type="text" name="search" class="form-control" placeholder="Search invoice/customer..." value="<?php echo htmlspecialchars($filterSearch); ?>">
                    </div>

                    <div class="col-md-2">
                        <select name="customer_id" class="form-select">
                            <option value="">All Customers</option>
                            <?php if ($customers): $customers->data_seek(0); ?>
                                <?php while ($cust = $customers->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$cust['id']; ?>" <?php echo ((string)$filterCustomer === (string)$cust['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cust['customer_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <select name="payment_method" class="form-select">
                            <option value="">All Payments</option>
                            <option value="cash"   <?php echo $filterPaymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="upi"    <?php echo $filterPaymentMethod === 'upi' ? 'selected' : ''; ?>>UPI</option>
                            <option value="card"   <?php echo $filterPaymentMethod === 'card' ? 'selected' : ''; ?>>Card</option>
                            <option value="bank"   <?php echo $filterPaymentMethod === 'bank' ? 'selected' : ''; ?>>Bank</option>
                            <option value="cheque" <?php echo $filterPaymentMethod === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                            <option value="credit" <?php echo $filterPaymentMethod === 'credit' ? 'selected' : ''; ?>>Credit</option>
                            <option value="mixed"  <?php echo $filterPaymentMethod === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                        </select>
                    </div>

                    <!-- GST TYPE FILTER -->
                    <div class="col-md-2">
                        <select name="gst_type" class="form-select">
                            <option value="">All Invoice Types</option>
                            <option value="gst" <?php echo $filterGstType === 'gst' ? 'selected' : ''; ?>>GST Invoice</option>
                            <option value="non_gst" <?php echo $filterGstType === 'non_gst' ? 'selected' : ''; ?>>Non-GST Invoice</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filterDateFrom); ?>" id="dateFrom">
                    </div>

                    <div class="col-md-1">
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filterDateTo); ?>" id="dateTo">
                    </div>

                    <div class="col-md-1 d-flex gap-1">
                        <button type="submit" class="btn btn-primary w-100" title="Apply Filter">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>

                    <?php if ($filterSearch || $filterCustomer || $filterDateFrom || $filterDateTo || $filterPaymentMethod || $filterStatus || $filterGstType): ?>
                        <div class="col-12">
                            <a href="invoices.php" class="clear-filter">
                                <i class="bi bi-x-circle"></i> Clear all filters
                            </a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Invoices Table -->
            <div class="dashboard-card">
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="invoicesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Invoice Details</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Date</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($invoices && $invoices->num_rows > 0): ?>
                                <?php while ($invoice = $invoices->fetch_assoc()):
                                    $displayCustomer = $invoice['customer_master_name'] ?: ($invoice['customer_name'] ?: 'Walk-in Customer');
                                    $customerInitials = '';
                                    if (!empty($displayCustomer)) {
                                        $name_parts = explode(' ', $displayCustomer);
                                        foreach ($name_parts as $part) {
                                            if (!empty($part)) $customerInitials .= strtoupper(substr($part, 0, 1));
                                        }
                                        if (strlen($customerInitials) > 2) $customerInitials = substr($customerInitials, 0, 2);
                                    }
                                    $isGst = (int)($invoice['is_gst'] ?? 1) === 1;
                                ?>
                                    <tr>
                                        <td><span class="order-id">#<?php echo (int)$invoice['id']; ?></span></td>
                                        <td>
                                            <div class="invoice-info-cell">
                                                <div class="invoice-avatar small"><?php echo $isGst ? 'SP' : 'E'; ?></div>
                                                <div>
                                                    <div class="invoice-number-text"><?php echo htmlspecialchars($invoice['inv_num']); ?></div>
                                                    <div class="invoice-meta-text">
                                                        <i class="bi bi-box-seam"></i> <?php echo (int)$invoice['item_count']; ?> items
                                                    </div>
                                                    <div class="gst-badge <?php echo $isGst ? 'gst' : 'non-gst'; ?>">
                                                        <i class="bi <?php echo $isGst ? 'bi-receipt-cutoff' : 'bi-file-earmark'; ?>"></i>
                                                        <?php echo $isGst ? 'GST' : 'NON-GST'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="customer-info">
                                                <div class="customer-avatar-small"><?php echo $customerInitials ?: '?'; ?></div>
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($displayCustomer); ?></div>
                                                    <?php if (!empty($invoice['phone'])): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($invoice['phone']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="text-center"><?php echo (int)$invoice['item_count']; ?></td>
                                        <td class="fw-semibold"><?php echo formatCurrency($invoice['total']); ?></td>

                                        <td>
                                            <span class="payment-method-badge">
                                                <i class="bi bi-<?php echo getPaymentMethodIcon($invoice['payment_method']); ?>"></i>
                                                <?php echo ucfirst($invoice['payment_method']); ?>
                                            </span>

                                            <?php if ((float)$invoice['cash_received'] > 0): ?>
                                                <div><small class="text-muted">Paid: <?php echo formatCurrency($invoice['cash_received']); ?></small></div>
                                            <?php endif; ?>

                                            <?php if (($invoice['payment_method'] ?? '') === 'mixed'): ?>
                                                <div class="mt-1" style="font-size:10px;color:#64748b;line-height:1.3;">
                                                    <?php
                                                    $splits = [];
                                                    foreach (['cash_amount'=>'Cash','upi_amount'=>'UPI','card_amount'=>'Card','bank_amount'=>'Bank','cheque_amount'=>'Cheque','credit_amount'=>'Credit'] as $col => $label) {
                                                        if (isset($invoice[$col]) && (float)$invoice[$col] > 0) {
                                                            $splits[] = $label . ': ' . formatCurrency($invoice[$col]);
                                                        }
                                                    }
                                                    echo $splits ? htmlspecialchars(implode(' | ', $splits)) : 'Mixed split';
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td><?php echo getStatusBadge($invoice['pending_amount']); ?></td>

                                        <td style="color: var(--text-muted); white-space: nowrap;">
                                            <?php echo date('d M Y', strtotime($invoice['created_at'])); ?>
                                            <div class="text-muted" style="font-size: 10px;"><?php echo date('h:i A', strtotime($invoice['created_at'])); ?></div>
                                        </td>

                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="action-group">
                                                    <a href="view_invoice.php?id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-info" style="font-size: 12px; padding: 3px 8px;" title="View Invoice">
                                                        <i class="bi bi-eye"></i>
                                                    </a>

                                                    <a href="edit_invoice.php?id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-edit" style="font-size: 12px; padding: 3px 8px;" title="Edit Invoice">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>

                                                    <a href="print_invoice.php?id=<?php echo (int)$invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size: 12px; padding: 3px 8px;" title="Print Invoice">
                                                        <i class="bi bi-printer"></i>
                                                    </a>

                                                    <?php if ((float)$invoice['pending_amount'] > 0): ?>
                                                        <button class="btn btn-sm btn-outline-warning" style="font-size: 12px; padding: 3px 8px;"
                                                                data-bs-toggle="modal" data-bs-target="#paymentModal<?php echo (int)$invoice['id']; ?>"
                                                                title="Update Payment">
                                                            <i class="bi bi-cash"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <form method="POST" action="invoices.php<?php echo buildQueryString(['filter_status', 'customer_id', 'date_from', 'date_to', 'search', 'payment_method', 'gst_type']); ?>"
                                                          style="display: inline;"
                                                          onsubmit="return confirmDelete(this, 'Are you sure you want to delete this invoice? This will reverse the stock and cannot be undone.')">
                                                        <input type="hidden" name="action" value="delete_invoice">
                                                        <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" title="Delete Invoice">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Payment Update Modal -->
                                    <?php if ((float)$invoice['pending_amount'] > 0): ?>
                                        <div class="modal fade" id="paymentModal<?php echo (int)$invoice['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST" action="invoices.php<?php echo buildQueryString(['filter_status', 'customer_id', 'date_from', 'date_to', 'search', 'payment_method', 'gst_type']); ?>" id="paymentForm<?php echo (int)$invoice['id']; ?>">
                                                        <input type="hidden" name="action" value="update_payment">
                                                        <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>">

                                                        <div class="modal-header">
                                                            <h5 class="modal-title">
                                                                <i class="bi bi-cash me-2"></i>
                                                                Update Payment - <?php echo htmlspecialchars($invoice['inv_num']); ?>
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>

                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Invoice Total</label>
                                                                <input type="text" class="form-control" value="<?php echo formatCurrency($invoice['total']); ?>" readonly disabled>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label">Current Paid Amount</label>
                                                                <input type="text" class="form-control" value="<?php echo formatCurrency($invoice['cash_received']); ?>" readonly disabled>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label">Current Pending Amount</label>
                                                                <input type="text" class="form-control bg-light text-danger fw-bold" value="<?php echo formatCurrency($invoice['pending_amount']); ?>" readonly disabled>
                                                            </div>

                                                            <hr>

                                                            <div class="mb-3">
                                                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                                                <div class="payment-method-selector">
                                                                    <?php foreach (['cash'=>'cash','card'=>'credit-card','upi'=>'phone','bank'=>'bank','cheque'=>'journal-check','credit'=>'journal-bookmark-fill','mixed'=>'shuffle'] as $method => $icon): ?>
                                                                        <div class="payment-method-option">
                                                                            <input type="radio" name="payment_method" id="<?php echo $method . (int)$invoice['id']; ?>" value="<?php echo $method; ?>" <?php echo ($invoice['payment_method'] === $method ? 'checked' : ''); ?>>
                                                                            <label for="<?php echo $method . (int)$invoice['id']; ?>">
                                                                                <i class="bi bi-<?php echo $icon; ?>"></i>
                                                                                <span><?php echo ucfirst($method); ?></span>
                                                                            </label>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label">New Paid Amount <span class="text-danger">*</span></label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">₹</span>
                                                                    <input type="number" name="paid_amount" class="form-control"
                                                                           step="0.01" min="0" max="<?php echo (float)$invoice['total']; ?>"
                                                                           value="<?php echo (float)$invoice['cash_received']; ?>"
                                                                           onchange="updatePending(this, <?php echo (float)$invoice['total']; ?>, 'pending<?php echo (int)$invoice['id']; ?>')" required>
                                                                </div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label">New Pending Amount</label>
                                                                <input type="number" name="pending_amount" id="pending<?php echo (int)$invoice['id']; ?>"
                                                                       class="form-control bg-light" step="0.01" readonly
                                                                       value="<?php echo (float)$invoice['pending_amount']; ?>">
                                                                <small class="text-muted">Auto-calculated based on paid amount</small>
                                                            </div>
                                                        </div>

                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Update Payment</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if ($invoices && $invoices->num_rows > 0): ?>
                        <?php
                        $invoices->data_seek(0);
                        while ($invoice = $invoices->fetch_assoc()):
                            $displayCustomer = $invoice['customer_master_name'] ?: ($invoice['customer_name'] ?: 'Walk-in Customer');
                            $isGst = (int)($invoice['is_gst'] ?? 1) === 1;
                        ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="invoice-avatar small"><?php echo $isGst ? 'SP' : 'E'; ?></div>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($invoice['inv_num']); ?></div>
                                            <div style="font-size: 11px; color: var(--text-muted);">
                                                <?php echo date('d M Y', strtotime($invoice['created_at'])); ?> • <?php echo (int)$invoice['item_count']; ?> items
                                            </div>
                                            <div class="gst-badge <?php echo $isGst ? 'gst' : 'non-gst'; ?>">
                                                <?php echo $isGst ? 'GST' : 'NON-GST'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php echo getStatusBadge($invoice['pending_amount']); ?>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Customer</span>
                                    <span class="mobile-card-value">
                                        <?php echo htmlspecialchars($displayCustomer); ?>
                                        <?php if (!empty($invoice['phone'])): ?>
                                            <br><small><?php echo htmlspecialchars($invoice['phone']); ?></small>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Total Amount</span>
                                    <span class="mobile-card-value fw-bold"><?php echo formatCurrency($invoice['total']); ?></span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Payment</span>
                                    <span class="mobile-card-value">
                                        <span class="payment-method-badge">
                                            <i class="bi bi-<?php echo getPaymentMethodIcon($invoice['payment_method']); ?>"></i>
                                            <?php echo ucfirst($invoice['payment_method']); ?>
                                        </span>
                                        <?php if ((float)$invoice['cash_received'] > 0): ?>
                                            <div><small>Paid: <?php echo formatCurrency($invoice['cash_received']); ?></small></div>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <div class="mobile-card-actions">
                                    <a href="view_invoice.php?id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-info flex-fill">
                                        <i class="bi bi-eye me-1"></i>View
                                    </a>

                                    <?php if ($is_admin): ?>
                                        <a href="new-sale.php?edit_id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-edit flex-fill">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </a>
                                    <?php endif; ?>

                                    <a href="print_invoice.php?id=<?php echo (int)$invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary flex-fill">
                                        <i class="bi bi-printer me-1"></i>Print
                                    </a>

                                    <?php if ($is_admin && (float)$invoice['pending_amount'] > 0): ?>
                                        <button class="btn btn-sm btn-outline-warning flex-fill" data-bs-toggle="modal" data-bs-target="#paymentModal<?php echo (int)$invoice['id']; ?>">
                                            <i class="bi bi-cash me-1"></i>Payment
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($is_admin): ?>
                                        <form method="POST" action="invoices.php<?php echo buildQueryString(['filter_status', 'customer_id', 'date_from', 'date_to', 'search', 'payment_method', 'gst_type']); ?>"
                                              style="flex: 1;" onsubmit="return confirmDelete(this, 'Delete this invoice? This will reverse stock.')">
                                            <input type="hidden" name="action" value="delete_invoice">
                                            <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                <i class="bi bi-trash me-1"></i>Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-receipt d-block mb-2" style="font-size: 48px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No invoices found</div>
                            <div style="font-size: 13px;">
                                <?php if ($filterSearch || $filterCustomer || $filterDateFrom || $filterDateTo || $filterPaymentMethod || $filterStatus || $filterGstType): ?>
                                    Try changing your filters or <a href="invoices.php">view all invoices</a>
                                <?php else: ?>
                                    <a href="new-sale.php">Create your first invoice</a> to get started
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Custom Bootstrap Confirmation Modal -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmDeleteModalLabel">Confirm Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmDeleteMessage">
                Are you sure you want to delete this invoice? This will reverse the stock and cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>

<script>
// Custom confirmation function using Bootstrap modal
let deleteFormToSubmit = null;

window.confirmDelete = function(form, message) {
    deleteFormToSubmit = form;
    document.getElementById('confirmDeleteMessage').textContent = message;
    
    var confirmModal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
    confirmModal.show();
    
    return false; // Prevent default form submission
}

document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (deleteFormToSubmit) {
        deleteFormToSubmit.submit();
    }
    
    // Close the modal
    var confirmModal = bootstrap.Modal.getInstance(document.getElementById('confirmDeleteModal'));
    confirmModal.hide();
});

function updatePending(input, total, pendingFieldId) {
    const paidAmount = parseFloat(input.value) || 0;
    let pendingAmount = total - paidAmount;
    if (pendingAmount < 0) pendingAmount = 0;
    document.getElementById(pendingFieldId).value = pendingAmount.toFixed(2);
}

$(document).ready(function() {
    // Initialize DataTable
    $('#invoicesTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search invoices:",
            lengthMenu: "Show _MENU_ invoices",
            info: "Showing _START_ to _END_ of _TOTAL_ invoices",
            emptyTable: "No invoices available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: -1 }
            <?php endif; ?>
        ],
        dom: 'Bfrtip',
        buttons: [
            {
                text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                action: function() {
                    const qs = window.location.search || '';
                    window.location.href = 'export_invoices.php?format=excel' + (qs ? '&' + qs.substring(1) : '');
                },
                className: 'btn btn-sm btn-outline-success'
            }
        ]
    });

    // Auto-submit filters on select change
    $('select[name="customer_id"], select[name="payment_method"], select[name="gst_type"]').change(function() {
        $(this).closest('form').submit();
    });

    // Date validation with Bootstrap alert
    function validateDates() {
        const fromDate = $('#dateFrom').val();
        const toDate = $('#dateTo').val();
        
        if (fromDate && toDate && toDate < fromDate) {
            // Create Bootstrap alert if it doesn't exist
            if ($('#dateAlert').length === 0) {
                const alertHtml = '<div id="dateAlert" class="alert alert-warning alert-dismissible fade show mt-2" role="alert">' +
                                 '<i class="bi bi-exclamation-triangle-fill"></i> ' +
                                 'To Date cannot be earlier than From Date' +
                                 '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                                 '</div>';
                $('#dateTo').closest('.col-md-1').after(alertHtml);
            }
            $('#dateTo').val('');
            return false;
        }
        return true;
    }

    $('#dateTo').change(function() {
        validateDates();
    });

    $('#dateFrom').change(function() {
        if ($('#dateTo').val()) {
            validateDates();
        }
    });

    // Form submission validation
    $('#filterForm').submit(function(e) {
        if (!validateDates()) {
            e.preventDefault();
        }
    });

    // Payment form validation
    $('form[id^="paymentForm"]').submit(function(e) {
        const paidAmount = parseFloat($(this).find('input[name="paid_amount"]').val()) || 0;
        const total = parseFloat($(this).find('input[name="paid_amount"]').attr('max')) || 0;
        
        if (paidAmount < 0) {
            e.preventDefault();
            showBootstrapAlert('danger', 'Paid amount cannot be negative');
        } else if (paidAmount > total) {
            e.preventDefault();
            showBootstrapAlert('danger', 'Paid amount cannot exceed invoice total');
        }
    });
});

// Helper function to show Bootstrap alerts
function showBootstrapAlert(type, message) {
    const alertHtml = '<div class="alert alert-' + type + ' alert-dismissible fade show d-flex align-items-center gap-2 mt-2" role="alert">' +
                     '<i class="bi bi-' + (type === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') + '"></i>' +
                     '<div>' + message + '</div>' +
                     '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                     '</div>';
    
    // Remove any existing alerts of this type
    $('.alert-' + type).remove();
    
    // Insert at the top of page content
    $('.page-content').prepend(alertHtml);
    
    // Auto dismiss after 5 seconds
    setTimeout(function() {
        $('.alert-' + type).alert('close');
    }, 5000);
}

// Handle payment method change validation
$(document).on('change', 'input[name="payment_method"]', function() {
    const modal = $(this).closest('.modal');
    const paidAmount = parseFloat(modal.find('input[name="paid_amount"]').val()) || 0;
    const total = parseFloat(modal.find('input[name="paid_amount"]').attr('max')) || 0;
    
    if (paidAmount === 0 && $(this).val() !== 'credit') {
        // Show warning for zero payment with non-credit methods
        showBootstrapAlert('warning', 'Paid amount is zero. Consider using Credit payment method.');
    }
});

// Track filter changes for statistics update
$(document).ready(function() {
    // Update page title based on filters
    const filterCount = <?php echo ($filterSearch ? 1 : 0) + ($filterCustomer ? 1 : 0) + ($filterDateFrom ? 1 : 0) + ($filterDateTo ? 1 : 0) + ($filterPaymentMethod ? 1 : 0) + ($filterStatus ? 1 : 0) + ($filterGstType ? 1 : 0); ?>;
    
    if (filterCount > 0) {
        $('title').text('Filtered Invoices - ' + filterCount + ' filters applied');
    }
});
</script>
</body>
</html>