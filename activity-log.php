<?php
session_start();
$currentPage = 'activity-log';
$pageTitle = 'Activity Log';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view activity log
checkRoleAccess(['admin']);

$success = '';
$error = '';

// Filters
$filter_user = $_GET['filter_user'] ?? '';
$filter_action = $_GET['filter_action'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_date_to = $_GET['filter_date_to'] ?? date('Y-m-d');
$filter_search = $_GET['search'] ?? '';

// Get users for filter dropdown
$users = $conn->query("SELECT id, name, username FROM users WHERE status = 1 ORDER BY name ASC");

// Build WHERE clause
$where = "1=1";
$params = [];
$types = "";

if ($filter_user && $filter_user !== 'all') {
    $where .= " AND al.user_id = ?";
    $params[] = $filter_user;
    $types .= "i";
}

if ($filter_action && $filter_action !== 'all') {
    $where .= " AND al.action = ?";
    $params[] = $filter_action;
    $types .= "s";
}

if ($filter_date_from) {
    $where .= " AND DATE(al.created_at) >= ?";
    $params[] = $filter_date_from;
    $types .= "s";
}

if ($filter_date_to) {
    $where .= " AND DATE(al.created_at) <= ?";
    $params[] = $filter_date_to;
    $types .= "s";
}

if ($filter_search) {
    $where .= " AND (al.description LIKE ? OR u.name LIKE ? OR u.username LIKE ?)";
    $search_term = "%$filter_search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

// Get activity logs with user details
$sql = "SELECT al.*, u.name as user_name, u.username, u.role 
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE $where
        ORDER BY al.created_at DESC
        LIMIT 5000"; // Limit to prevent overload

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $activities = $stmt->get_result();
} else {
    $activities = $conn->query($sql);
}

// Get action statistics
$stats_query = "SELECT 
    COUNT(*) as total_activities,
    COUNT(DISTINCT user_id) as active_users,
    COUNT(CASE WHEN action = 'login' THEN 1 END) as login_count,
    COUNT(CASE WHEN action = 'create' THEN 1 END) as create_count,
    COUNT(CASE WHEN action = 'update' THEN 1 END) as update_count,
    COUNT(CASE WHEN action = 'delete' THEN 1 END) as delete_count,
    COUNT(CASE WHEN action = 'payment' THEN 1 END) as payment_count,
    COUNT(CASE WHEN action = 'stock_update' THEN 1 END) as stock_count,
    COUNT(CASE WHEN action = 'cancel' THEN 1 END) as cancel_count
    FROM activity_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$stats = $conn->query($stats_query)->fetch_assoc();

// Get daily activity for chart
$daily_query = "SELECT 
    DATE(created_at) as activity_date,
    COUNT(*) as total,
    COUNT(CASE WHEN action = 'login' THEN 1 END) as logins,
    COUNT(CASE WHEN action = 'create' THEN 1 END) as creates,
    COUNT(CASE WHEN action = 'update' THEN 1 END) as updates
    FROM activity_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY activity_date DESC";
$daily_activities = $conn->query($daily_query);

// Get unique actions for filter
$actions = $conn->query("SELECT DISTINCT action FROM activity_log ORDER BY action");

// Helper function to get action badge class
function getActionBadgeClass($action) {
    switch($action) {
        case 'login':
            return 'info';
        case 'logout':
            return 'secondary';
        case 'create':
            return 'success';
        case 'update':
            return 'primary';
        case 'delete':
            return 'danger';
        case 'payment':
            return 'warning';
        case 'stock_update':
            return 'purple';
        case 'cancel':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Helper function to get action icon
function getActionIcon($action) {
    switch($action) {
        case 'login':
            return 'bi-box-arrow-in-right';
        case 'logout':
            return 'bi-box-arrow-right';
        case 'create':
            return 'bi-plus-circle';
        case 'update':
            return 'bi-pencil';
        case 'delete':
            return 'bi-trash';
        case 'payment':
            return 'bi-cash-stack';
        case 'stock_update':
            return 'bi-box-seam';
        case 'cancel':
            return 'bi-x-circle';
        default:
            return 'bi-info-circle';
    }
}

// Helper function to format time ago
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('d M Y, h:i A', $time);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 10px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }
        
        .stat-icon.blue { background: #e8f2ff; color: #2463eb; }
        .stat-icon.green { background: #e2f7e9; color: #16a34a; }
        .stat-icon.orange { background: #fff4e5; color: #f59e0b; }
        .stat-icon.purple { background: #f2e8ff; color: #8b5cf6; }
        .stat-icon.red { background: #fee2e2; color: #dc2626; }
        .stat-icon.teal { background: #e0f2f1; color: #0d9488; }
        
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
        
        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 24px;
        }
        
        .activity-timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .activity-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #eef2f6;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -30px;
            top: 4px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #2463eb;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .timeline-item.login::before { background: #16a34a; }
        .timeline-item.create::before { background: #8b5cf6; }
        .timeline-item.update::before { background: #f59e0b; }
        .timeline-item.delete::before { background: #dc2626; }
        .timeline-item.payment::before { background: #0d9488; }
        .timeline-item.stock_update::before { background: #6366f1; }
        
        .timeline-content {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
        }
        
        .timeline-title {
            font-weight: 500;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .timeline-meta {
            font-size: 12px;
            color: #64748b;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .action-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .action-badge i {
            margin-right: 4px;
            font-size: 10px;
        }
        
        .action-badge.login { background: #e0f2e7; color: #16a34a; }
        .action-badge.logout { background: #f1f5f9; color: #64748b; }
        .action-badge.create { background: #f2e8ff; color: #8b5cf6; }
        .action-badge.update { background: #fff4e5; color: #f59e0b; }
        .action-badge.delete { background: #fee2e2; color: #dc2626; }
        .action-badge.payment { background: #e0f2f1; color: #0d9488; }
        .action-badge.stock_update { background: #e0e7ff; color: #6366f1; }
        .action-badge.cancel { background: #fee2e2; color: #dc2626; }
        
        .user-avatar-xs {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
        }
        
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 24px;
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
        
        .clear-filters {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            color: #64748b;
            text-decoration: none;
        }
        
        .clear-filters:hover {
            background: #f1f5f9;
            color: #1e293b;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Activity Log</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Track all user activities and system events</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn-export" onclick="exportToCSV()">
                        <i class="bi bi-download"></i> Export CSV
                    </button>
                    <a href="activity-log.php" class="clear-filters">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i class="bi bi-activity"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_activities']); ?></div>
                    <div class="stat-label">Last 30 Days</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['active_users']; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i class="bi bi-box-arrow-in-right"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['login_count']; ?></div>
                    <div class="stat-label">Logins</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="bi bi-plus-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['create_count']; ?></div>
                    <div class="stat-label">Creations</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon teal">
                        <i class="bi bi-pencil"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['update_count']; ?></div>
                    <div class="stat-label">Updates</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon red">
                        <i class="bi bi-trash"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['delete_count']; ?></div>
                    <div class="stat-label">Deletions</div>
                </div>
            </div>

            <!-- Daily Activity Chart -->
            <div class="chart-container no-print">
                <h5 class="mb-3"><i class="bi bi-graph-up me-2"></i>Daily Activity (Last 7 Days)</h5>
                <canvas id="activityChart" style="height: 250px;"></canvas>
            </div>

            <!-- Filter Section -->
            <div class="filter-section no-print">
                <form method="GET" action="activity-log.php" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">User</label>
                        <select name="filter_user" class="form-select">
                            <option value="all">All Users</option>
                            <?php 
                            if ($users && $users->num_rows > 0) {
                                while ($user = $users->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $filter_user == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['name']); ?>
                                </option>
                            <?php 
                                endwhile; 
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Action</label>
                        <select name="filter_action" class="form-select">
                            <option value="all">All Actions</option>
                            <?php 
                            if ($actions && $actions->num_rows > 0) {
                                while ($action = $actions->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $action['action']; ?>" <?php echo $filter_action == $action['action'] ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($action['action']); ?>
                                </option>
                            <?php 
                                endwhile; 
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="filter_date_from" class="form-control" value="<?php echo $filter_date_from; ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="filter_date_to" class="form-control" value="<?php echo $filter_date_to; ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Search description..." value="<?php echo htmlspecialchars($filter_search); ?>">
                    </div>
                    
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn-primary-custom w-100">
                            <i class="bi bi-funnel"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Activity Log Table -->
            <div class="dashboard-card">
                <div class="card-header-custom p-4">
                    <h5><i class="bi bi-clock-history me-2"></i>Activity Timeline</h5>
                    <p>Showing <?php echo $activities ? $activities->num_rows : 0; ?> activities</p>
                </div>

                <!-- Desktop Table View -->
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="activityTable">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($activities && $activities->num_rows > 0): ?>
                                <?php while ($activity = $activities->fetch_assoc()): 
                                    $action_class = getActionBadgeClass($activity['action']);
                                    $action_icon = getActionIcon($activity['action']);
                                    
                                    // Get user initials for avatar
                                    $initials = '';
                                    if ($activity['user_name']) {
                                        $name_parts = explode(' ', $activity['user_name']);
                                        foreach ($name_parts as $part) {
                                            if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                        if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                                    } else {
                                        $initials = 'SY';
                                    }
                                ?>
                                    <tr>
                                        <td style="white-space: nowrap;">
                                            <?php echo date('d M Y', strtotime($activity['created_at'])); ?>
                                            <div class="text-muted" style="font-size: 10px;">
                                                <?php echo date('h:i A', strtotime($activity['created_at'])); ?>
                                                <br><small><?php echo timeAgo($activity['created_at']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="user-avatar-xs"><?php echo $initials; ?></div>
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></div>
                                                    <div class="text-muted" style="font-size: 10px;">@<?php echo htmlspecialchars($activity['username'] ?? 'system'); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="action-badge <?php echo $action_class; ?>">
                                                <i class="bi <?php echo $action_icon; ?>"></i>
                                                <?php echo ucfirst($activity['action']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                        <td><span class="text-muted" style="font-size: 11px;"><?php echo $activity['ip_address'] ?? '-'; ?></span></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="bi bi-clock-history" style="font-size: 24px;"></i>
                                        <p class="mt-2">No activities found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Timeline View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if ($activities && $activities->num_rows > 0): ?>
                        <?php 
                        $activities->data_seek(0);
                        while ($activity = $activities->fetch_assoc()): 
                            $action_class = getActionBadgeClass($activity['action']);
                            $action_icon = getActionIcon($activity['action']);
                            $timeline_class = 'timeline-item';
                            if (in_array($activity['action'], ['login', 'create', 'update', 'delete', 'payment', 'stock_update'])) {
                                $timeline_class .= ' ' . $activity['action'];
                            }
                            
                            // Get user initials
                            $initials = '';
                            if ($activity['user_name']) {
                                $name_parts = explode(' ', $activity['user_name']);
                                foreach ($name_parts as $part) {
                                    if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                                }
                                if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                            } else {
                                $initials = 'SY';
                            }
                        ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="user-avatar-xs"><?php echo $initials; ?></div>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></div>
                                            <div class="text-muted" style="font-size: 10px;"><?php echo timeAgo($activity['created_at']); ?></div>
                                        </div>
                                    </div>
                                    <span class="action-badge <?php echo $action_class; ?>">
                                        <i class="bi <?php echo $action_icon; ?>"></i>
                                        <?php echo ucfirst($activity['action']); ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Description</span>
                                    <span class="mobile-card-value"><?php echo htmlspecialchars($activity['description']); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Date & Time</span>
                                    <span class="mobile-card-value"><?php echo date('d M Y, h:i A', strtotime($activity['created_at'])); ?></span>
                                </div>
                                
                                <?php if ($activity['ip_address']): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">IP Address</span>
                                    <span class="mobile-card-value"><?php echo $activity['ip_address']; ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-clock-history" style="font-size: 48px;"></i>
                            <p class="mt-2">No activities found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pagination Info -->
            <div class="text-muted text-end mt-3 no-print" style="font-size: 12px;">
                <i class="bi bi-info-circle"></i> Showing last 5000 activities. 
                <a href="activity-log-archive.php" class="text-primary">View full archive</a>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<!-- Chart.js Initialization -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Activity Chart
    const chartCtx = document.getElementById('activityChart')?.getContext('2d');
    if (chartCtx) {
        const dates = [];
        const totals = [];
        const logins = [];
        const creates = [];
        const updates = [];
        
        <?php 
        if ($daily_activities && $daily_activities->num_rows > 0) {
            $daily_activities->data_seek(0);
            while ($day = $daily_activities->fetch_assoc()): 
        ?>
        dates.push('<?php echo date('d M', strtotime($day['activity_date'])); ?>');
        totals.push(<?php echo $day['total']; ?>);
        logins.push(<?php echo $day['logins']; ?>);
        creates.push(<?php echo $day['creates']; ?>);
        updates.push(<?php echo $day['updates']; ?>);
        <?php 
            endwhile; 
        } 
        ?>
        
        new Chart(chartCtx, {
            type: 'line',
            data: {
                labels: dates.reverse(),
                datasets: [
                    {
                        label: 'Total Activities',
                        data: totals.reverse(),
                        borderColor: '#2463eb',
                        backgroundColor: 'rgba(36, 99, 235, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Logins',
                        data: logins.reverse(),
                        borderColor: '#16a34a',
                        borderWidth: 2,
                        tension: 0.4,
                        hidden: true
                    },
                    {
                        label: 'Creations',
                        data: creates.reverse(),
                        borderColor: '#8b5cf6',
                        borderWidth: 2,
                        tension: 0.4,
                        hidden: true
                    },
                    {
                        label: 'Updates',
                        data: updates.reverse(),
                        borderColor: '#f59e0b',
                        borderWidth: 2,
                        tension: 0.4,
                        hidden: true
                    }
                ]
            },
            options: {
                responsive: false,
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
});

// Export to CSV
function exportToCSV() {
    let csv = [];
    let rows = document.querySelectorAll('#activityTable tr');
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            // Clean the text (remove extra spaces, HTML)
            let text = cols[j].innerText.replace(/\s+/g, ' ').trim();
            row.push('"' + text + '"');
        }
        csv.push(row.join(','));
    }
    
    // Download CSV
    let csvFile = new Blob([csv.join('\n')], {type: 'text/csv'});
    let downloadLink = document.createElement('a');
    downloadLink.download = 'activity_log_' + new Date().toISOString().slice(0,10) + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// Initialize DataTable
$(document).ready(function() {
    $('#activityTable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
        language: {
            search: "Search activities:",
            lengthMenu: "Show _MENU_ activities",
            info: "Showing _START_ to _END_ of _TOTAL_ activities",
            emptyTable: "No activities available"
        },
        columnDefs: [
            { orderable: false, targets: [4] }
        ]
    });
});
</script>

</body>
</html>