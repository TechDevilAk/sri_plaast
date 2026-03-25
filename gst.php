<?php
session_start();
$currentPage = 'gst';
$pageTitle = 'GST Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can manage GST
checkRoleAccess(['admin']);

$success = '';
$error = '';

// Handle add GST rate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_gst') {
    
    $hsn = trim($_POST['hsn'] ?? '');
    $cgst = floatval($_POST['cgst'] ?? 0);
    $sgst = floatval($_POST['sgst'] ?? 0);
    $igst = floatval($_POST['igst'] ?? 0);
    $status = isset($_POST['status']) ? 1 : 0;
    
    if (empty($hsn)) {
        $error = "HSN code is required.";
    } elseif ($cgst < 0 || $sgst < 0 || $igst < 0) {
        $error = "GST rates cannot be negative.";
    } else {
        // Check if HSN already exists
        $check = $conn->prepare("SELECT id FROM gst WHERE hsn = ?");
        $check->bind_param("s", $hsn);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            $error = "HSN code already exists.";
        } else {
            $stmt = $conn->prepare("INSERT INTO gst (hsn, cgst, sgst, igst, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sdddi", $hsn, $cgst, $sgst, $igst, $status);
            
            if ($stmt->execute()) {
                // Log activity
                $log_desc = "Added new GST rate: HSN $hsn (CGST: $cgst%, SGST: $sgst%, IGST: $igst%)";
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $success = "GST rate added successfully.";
            } else {
                $error = "Failed to add GST rate.";
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Handle edit GST rate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_gst' && isset($_POST['gst_id']) && is_numeric($_POST['gst_id'])) {
    
    $gst_id = intval($_POST['gst_id']);
    $hsn = trim($_POST['hsn'] ?? '');
    $cgst = floatval($_POST['cgst'] ?? 0);
    $sgst = floatval($_POST['sgst'] ?? 0);
    $igst = floatval($_POST['igst'] ?? 0);
    $status = isset($_POST['status']) ? 1 : 0;
    
    if (empty($hsn)) {
        $error = "HSN code is required.";
    } elseif ($cgst < 0 || $sgst < 0 || $igst < 0) {
        $error = "GST rates cannot be negative.";
    } else {
        // Check if HSN exists for other records
        $check = $conn->prepare("SELECT id FROM gst WHERE hsn = ? AND id != ?");
        $check->bind_param("si", $hsn, $gst_id);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            $error = "HSN code already exists.";
        } else {
            $stmt = $conn->prepare("UPDATE gst SET hsn=?, cgst=?, sgst=?, igst=?, status=? WHERE id=?");
            $stmt->bind_param("sdddi", $hsn, $cgst, $sgst, $igst, $status, $gst_id);
            
            if ($stmt->execute()) {
                // Log activity
                $log_desc = "Updated GST rate: HSN $hsn (CGST: $cgst%, SGST: $sgst%, IGST: $igst%)";
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $success = "GST rate updated successfully.";
            } else {
                $error = "Failed to update GST rate.";
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Handle delete GST rate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_gst' && isset($_POST['gst_id']) && is_numeric($_POST['gst_id'])) {
    
    $gst_id = intval($_POST['gst_id']);
    
    // Check if GST is used in categories (if you have hsn_code in category table)
    $check_categories = $conn->prepare("SELECT id FROM category WHERE hsn_code = (SELECT hsn FROM gst WHERE id = ?) LIMIT 1");
    $check_categories->bind_param("i", $gst_id);
    $check_categories->execute();
    $check_categories->store_result();
    
    if ($check_categories->num_rows > 0) {
        $error = "Cannot delete GST rate. It is being used by categories.";
    } else {
        // Get GST details for logging
        $gst_query = $conn->prepare("SELECT hsn FROM gst WHERE id = ?");
        $gst_query->bind_param("i", $gst_id);
        $gst_query->execute();
        $gst_data = $gst_query->get_result()->fetch_assoc();
        $hsn = $gst_data['hsn'] ?? 'Unknown';
        
        $stmt = $conn->prepare("DELETE FROM gst WHERE id = ?");
        $stmt->bind_param("i", $gst_id);
        
        if ($stmt->execute()) {
            // Log activity
            $log_desc = "Deleted GST rate: HSN $hsn";
            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            
            $success = "GST rate deleted successfully.";
        } else {
            $error = "Failed to delete GST rate.";
        }
        $stmt->close();
    }
    $check_categories->close();
}

// Handle toggle status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status' && isset($_POST['gst_id']) && is_numeric($_POST['gst_id'])) {
    
    $gst_id = intval($_POST['gst_id']);
    $new_status = intval($_POST['status']);
    
    $stmt = $conn->prepare("UPDATE gst SET status = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $gst_id);
    
    if ($stmt->execute()) {
        $status_text = $new_status ? 'activated' : 'deactivated';
        
        // Get GST details for logging
        $gst_query = $conn->prepare("SELECT hsn FROM gst WHERE id = ?");
        $gst_query->bind_param("i", $gst_id);
        $gst_query->execute();
        $gst_data = $gst_query->get_result()->fetch_assoc();
        $hsn = $gst_data['hsn'] ?? 'Unknown';
        
        $log_desc = "$status_text GST rate: HSN $hsn";
        $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
        $log_stmt->execute();
        
        $success = "GST rate $status_text successfully.";
    }
    $stmt->close();
}

// Filters
$filter_status = $_GET['filter_status'] ?? '';
$filter_search = $_GET['search'] ?? '';

$where = "1=1";
$params = [];
$types = "";

if ($filter_status && $filter_status !== 'all') {
    $where .= " AND status = ?";
    $params[] = $filter_status;
    $types .= "i";
}

if (!empty($filter_search)) {
    $where .= " AND hsn LIKE ?";
    $params[] = "%$filter_search%";
    $types .= "s";
}

$sql = "SELECT * FROM gst WHERE $where ORDER BY hsn ASC";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $gst_rates = $stmt->get_result();
} else {
    $gst_rates = $conn->query($sql);
}

// Statistics
$total_rates = $conn->query("SELECT COUNT(*) as cnt FROM gst")->fetch_assoc()['cnt'];
$active_rates = $conn->query("SELECT COUNT(*) as cnt FROM gst WHERE status = 1")->fetch_assoc()['cnt'];
$inactive_rates = $total_rates - $active_rates;

// Average rates
$avg_cgst = $conn->query("SELECT COALESCE(AVG(cgst), 0) as avg FROM gst WHERE status = 1")->fetch_assoc()['avg'];
$avg_sgst = $conn->query("SELECT COALESCE(AVG(sgst), 0) as avg FROM gst WHERE status = 1")->fetch_assoc()['avg'];
$avg_igst = $conn->query("SELECT COALESCE(AVG(igst), 0) as avg FROM gst WHERE status = 1")->fetch_assoc()['avg'];

// Most common rate
$common_rate = $conn->query("SELECT CONCAT(cgst, '%+', sgst, '%') as rate, COUNT(*) as cnt 
                              FROM gst WHERE status = 1 GROUP BY cgst, sgst ORDER BY cnt DESC LIMIT 1")->fetch_assoc();

// Check if user is admin
$is_admin = ($_SESSION['user_role'] === 'admin');

// Helper function for status badge
function getStatusBadge($status) {
    return $status == 1 ? 'completed' : 'cancelled';
}

// Helper function to format rate
function formatRate($rate) {
    return number_format($rate, 2) . '%';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
 .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 8px;
    margin-bottom: 12px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 12px;
    border: 1px solid #eef2f6;
}

.stat-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.stat-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    margin-bottom: 0;
}

.stat-value {
    font-size: 18px;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.2;
}

.stat-label {
    font-size: 11px;
    color: #64748b;
}
        
        .stat-icon.blue { background: #e8f2ff; color: #2463eb; }
        .stat-icon.green { background: #e2f7e9; color: #16a34a; }
        .stat-icon.orange { background: #fff4e5; color: #f59e0b; }
        .stat-icon.purple { background: #f2e8ff; color: #8b5cf6; }
        .stat-icon.red { background: #fee2e2; color: #dc2626; }
        
        /*.stat-value {*/
        /*    font-size: 28px;*/
        /*    font-weight: 700;*/
        /*    color: #1e293b;*/
        /*    line-height: 1.2;*/
        /*    margin-bottom: 4px;*/
        /*}*/
        
        /*.stat-label {*/
        /*    font-size: 13px;*/
        /*    color: #64748b;*/
        /*}*/
        
        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 24px;
        }
        
        .gst-rate-badge {
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .gst-rate-badge.cgst { background: #e8f2ff; color: #2463eb; }
        .gst-rate-badge.sgst { background: #e0f2e7; color: #16a34a; }
        .gst-rate-badge.igst { background: #f2e8ff; color: #8b5cf6; }
        
        .hsn-code {
            font-family: monospace;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        .stats-mini-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            border: 1px solid #eef2f6;
            height: 100%;
        }
        
        .stats-mini-value {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .stats-mini-label {
            font-size: 12px;
            color: #64748b;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">GST Management</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage GST rates and HSN codes</p>
                </div>
                <?php if ($is_admin): ?>
                    <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addGSTModal">
                        <i class="bi bi-plus-circle"></i> Add GST Rate
                    </button>
                <?php else: ?>
                    <span class="permission-badge"><i class="bi bi-eye"></i> View Only Mode</span>
                <?php endif; ?>
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

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i class="bi bi-tags"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_rates; ?></div>
                    <div class="stat-label">Total GST Rates</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $active_rates; ?></div>
                    <div class="stat-label">Active Rates</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $inactive_rates; ?></div>
                    <div class="stat-label">Inactive Rates</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i class="bi bi-percent"></i>
                    </div>
                    <div class="stat-value"><?php echo formatRate($avg_cgst + $avg_sgst); ?></div>
                    <div class="stat-label">Avg Total GST</div>
                </div>
            </div>

            <!-- Stats Row 2 - Mini Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stats-mini-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stats-mini-value"><?php echo formatRate($avg_cgst); ?></div>
                                <div class="stats-mini-label">Avg CGST</div>
                            </div>
                            <span class="gst-rate-badge cgst">CGST</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-mini-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stats-mini-value"><?php echo formatRate($avg_sgst); ?></div>
                                <div class="stats-mini-label">Avg SGST</div>
                            </div>
                            <span class="gst-rate-badge sgst">SGST</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-mini-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stats-mini-value"><?php echo formatRate($avg_igst); ?></div>
                                <div class="stats-mini-label">Avg IGST</div>
                            </div>
                            <span class="gst-rate-badge igst">IGST</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Most Common Rate Card -->
            <?php if ($common_rate && $common_rate['rate']): ?>
            <div class="alert alert-info mb-4" style="background: #e8f2ff; border: none;">
                <i class="bi bi-info-circle"></i> 
                <strong>Most Common Rate:</strong> <?php echo $common_rate['rate']; ?> (used in <?php echo $common_rate['cnt']; ?> rates)
            </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section no-print">
                <form method="GET" action="gst.php" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="filter_status" class="form-select">
                            <option value="all">All Status</option>
                            <option value="1" <?php echo $filter_status === '1' ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo $filter_status === '0' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Search HSN</label>
                        <input type="text" name="search" class="form-control" placeholder="Enter HSN code..." value="<?php echo htmlspecialchars($filter_search); ?>">
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="d-flex gap-2 w-100">
                            <button type="submit" class="btn-primary-custom flex-fill">
                                <i class="bi bi-funnel"></i> Apply
                            </button>
                            <a href="gst.php" class="btn-outline-custom flex-fill">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- GST Rates Table -->
            <div class="dashboard-card">
                <div class="card-header-custom p-4">
                    <h5><i class="bi bi-table me-2"></i>GST Rates</h5>
                    <p>Showing <?php echo $gst_rates ? $gst_rates->num_rows : 0; ?> rates</p>
                </div>

                <!-- Desktop Table View -->
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="gstTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>HSN Code</th>
                                <th>CGST (%)</th>
                                <th>SGST (%)</th>
                                <th>IGST (%)</th>
                                <th>Total GST</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($gst_rates && $gst_rates->num_rows > 0): ?>
                                <?php while ($gst = $gst_rates->fetch_assoc()): 
                                    $total_gst = $gst['cgst'] + $gst['sgst'];
                                ?>
                                    <tr>
                                        <td><span class="order-id">#<?php echo $gst['id']; ?></span></td>
                                        <td>
                                            <span class="hsn-code"><?php echo htmlspecialchars($gst['hsn']); ?></span>
                                        </td>
                                        <td>
                                            <span class="gst-rate-badge cgst">
                                                <?php echo formatRate($gst['cgst']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="gst-rate-badge sgst">
                                                <?php echo formatRate($gst['sgst']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="gst-rate-badge igst">
                                                <?php echo formatRate($gst['igst']); ?>
                                            </span>
                                        </td>
                                        <td class="fw-semibold"><?php echo formatRate($total_gst); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo getStatusBadge($gst['status']); ?>">
                                                <span class="dot"></span>
                                                <?php echo $gst['status'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td style="color: var(--text-muted); white-space: nowrap;">
                                            <?php echo date('d M Y', strtotime($gst['updated_at'])); ?>
                                            <div class="text-muted" style="font-size: 10px;"><?php echo date('h:i A', strtotime($gst['updated_at'])); ?></div>
                                        </td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <!-- Status Toggle -->
                                                    <form method="POST" action="gst.php<?php echo buildQueryString(['filter_status', 'search']); ?>" style="display: inline;">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="gst_id" value="<?php echo $gst['id']; ?>">
                                                        <input type="hidden" name="status" value="<?php echo $gst['status'] ? 0 : 1; ?>">
                                                        <button type="submit" class="btn btn-sm <?php echo $gst['status'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>" 
                                                                style="font-size: 12px; padding: 3px 8px;"
                                                                title="<?php echo $gst['status'] ? 'Deactivate' : 'Activate'; ?>">
                                                            <i class="bi <?php echo $gst['status'] ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                                                        </button>
                                                    </form>
                                                    
                                                    <!-- Edit -->
                                                    <button class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 3px 8px;" 
                                                            data-bs-toggle="modal" data-bs-target="#editGSTModal<?php echo $gst['id']; ?>"
                                                            title="Edit GST Rate">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    
                                                    <!-- Delete -->
                                                    <form method="POST" action="gst.php<?php echo buildQueryString(['filter_status', 'search']); ?>" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this GST rate?')">
                                                        <input type="hidden" name="action" value="delete_gst">
                                                        <input type="hidden" name="gst_id" value="<?php echo $gst['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;"
                                                                title="Delete GST Rate">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Edit GST Modal -->
                                    <div class="modal fade" id="editGSTModal<?php echo $gst['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="gst.php<?php echo buildQueryString(['filter_status', 'search']); ?>">
                                                    <input type="hidden" name="action" value="edit_gst">
                                                    <input type="hidden" name="gst_id" value="<?php echo $gst['id']; ?>">
                                                    
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit GST Rate</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">HSN Code <span class="text-danger">*</span></label>
                                                            <input type="text" name="hsn" class="form-control" value="<?php echo htmlspecialchars($gst['hsn']); ?>" required>
                                                        </div>
                                                        
                                                        <div class="row g-3">
                                                            <div class="col-md-4">
                                                                <label class="form-label">CGST (%)</label>
                                                                <input type="number" name="cgst" class="form-control" step="0.01" min="0" max="100" value="<?php echo $gst['cgst']; ?>" required>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label">SGST (%)</label>
                                                                <input type="number" name="sgst" class="form-control" step="0.01" min="0" max="100" value="<?php echo $gst['sgst']; ?>" required>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label">IGST (%)</label>
                                                                <input type="number" name="igst" class="form-control" step="0.01" min="0" max="100" value="<?php echo $gst['igst']; ?>" required>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3 mt-3">
                                                            <div class="form-check form-switch">
                                                                <input class="form-check-input" type="checkbox" name="status" id="editStatus<?php echo $gst['id']; ?>" value="1" <?php echo $gst['status'] ? 'checked' : ''; ?>>
                                                                <label class="form-check-label" for="editStatus<?php echo $gst['id']; ?>">Active Status</label>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="alert alert-info py-2" style="font-size: 12px;">
                                                            <i class="bi bi-info-circle"></i> Common rates: 5% (2.5+2.5), 12% (6+6), 18% (9+9), 28% (14+14)
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if ($gst_rates && $gst_rates->num_rows > 0): ?>
                        <?php 
                        $gst_rates->data_seek(0);
                        while ($gst = $gst_rates->fetch_assoc()): 
                            $total_gst = $gst['cgst'] + $gst['sgst'];
                        ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header">
                                    <div>
                                        <span class="order-id">#<?php echo $gst['id']; ?></span>
                                        <span class="hsn-code ms-2"><?php echo htmlspecialchars($gst['hsn']); ?></span>
                                    </div>
                                    <span class="status-badge <?php echo getStatusBadge($gst['status']); ?>">
                                        <span class="dot"></span>
                                        <?php echo $gst['status'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">CGST</span>
                                    <span class="mobile-card-value">
                                        <span class="gst-rate-badge cgst"><?php echo formatRate($gst['cgst']); ?></span>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">SGST</span>
                                    <span class="mobile-card-value">
                                        <span class="gst-rate-badge sgst"><?php echo formatRate($gst['sgst']); ?></span>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">IGST</span>
                                    <span class="mobile-card-value">
                                        <span class="gst-rate-badge igst"><?php echo formatRate($gst['igst']); ?></span>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Total GST</span>
                                    <span class="mobile-card-value fw-semibold text-primary"><?php echo formatRate($total_gst); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Last Updated</span>
                                    <span class="mobile-card-value"><?php echo date('d M Y, h:i A', strtotime($gst['updated_at'])); ?></span>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                    <div class="mobile-card-actions">
                                        <form method="POST" action="gst.php<?php echo buildQueryString(['filter_status', 'search']); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="gst_id" value="<?php echo $gst['id']; ?>">
                                            <input type="hidden" name="status" value="<?php echo $gst['status'] ? 0 : 1; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $gst['status'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>">
                                                <i class="bi <?php echo $gst['status'] ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                                                <?php echo $gst['status'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                        
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editGSTModal<?php echo $gst['id']; ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        
                                        <form method="POST" action="gst.php<?php echo buildQueryString(['filter_status', 'search']); ?>" style="display: inline;" 
                                              onsubmit="return confirm('Delete this GST rate?')">
                                            <input type="hidden" name="action" value="delete_gst">
                                            <input type="hidden" name="gst_id" value="<?php echo $gst['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-percent" style="font-size: 48px;"></i>
                            <p class="mt-2">No GST rates found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add GST Modal -->
<div class="modal fade" id="addGSTModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="gst.php<?php echo buildQueryString(['filter_status', 'search']); ?>">
                <input type="hidden" name="action" value="add_gst">
                
                <div class="modal-header">
                    <h5 class="modal-title">Add New GST Rate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">HSN Code <span class="text-danger">*</span></label>
                        <input type="text" name="hsn" class="form-control" required placeholder="e.g., 4802, 4819, 7323">
                        <small class="text-muted">Enter HSN code without spaces</small>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">CGST (%)</label>
                            <input type="number" name="cgst" class="form-control" step="0.01" min="0" max="100" value="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">SGST (%)</label>
                            <input type="number" name="sgst" class="form-control" step="0.01" min="0" max="100" value="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IGST (%)</label>
                            <input type="number" name="igst" class="form-control" step="0.01" min="0" max="100" value="0" required>
                        </div>
                    </div>
                    
                    <div class="mb-3 mt-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="status" id="addStatus" value="1" checked>
                            <label class="form-check-label" for="addStatus">Active Status</label>
                        </div>
                    </div>
                    
                    <div class="alert alert-info py-2" style="font-size: 12px;">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Note:</strong> Common rates: 5% (2.5+2.5), 12% (6+6), 18% (9+9), 28% (14+14)
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add GST Rate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Helper function to build query string
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return count($params) ? '?' . http_build_query($params) : '';
}
?>

<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    $('#gstTable').DataTable({
        pageLength: 25,
        order: [[1, 'asc']],
        language: {
            search: "Search GST:",
            lengthMenu: "Show _MENU_ rates",
            info: "Showing _START_ to _END_ of _TOTAL_ rates",
            emptyTable: "No GST rates available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: -1 }
            <?php endif; ?>
        ]
    });
});

// Auto-calculate IGST
document.addEventListener('DOMContentLoaded', function() {
    function updateIGST(cgstInput, sgstInput, igstInput) {
        let cgst = parseFloat(cgstInput.value) || 0;
        let sgst = parseFloat(sgstInput.value) || 0;
        igstInput.value = (cgst + sgst).toFixed(2);
    }

    // For add modal
    const addCgst = document.querySelector('#addGSTModal input[name="cgst"]');
    const addSgst = document.querySelector('#addGSTModal input[name="sgst"]');
    const addIgst = document.querySelector('#addGSTModal input[name="igst"]');
    
    if (addCgst && addSgst && addIgst) {
        addCgst.addEventListener('input', () => updateIGST(addCgst, addSgst, addIgst));
        addSgst.addEventListener('input', () => updateIGST(addCgst, addSgst, addIgst));
    }

    // For edit modals
    document.querySelectorAll('[id^="editGSTModal"]').forEach(modal => {
        const cgst = modal.querySelector('input[name="cgst"]');
        const sgst = modal.querySelector('input[name="sgst"]');
        const igst = modal.querySelector('input[name="igst"]');
        
        if (cgst && sgst && igst) {
            cgst.addEventListener('input', () => updateIGST(cgst, sgst, igst));
            sgst.addEventListener('input', () => updateIGST(cgst, sgst, igst));
        }
    });
});
</script>

</body>
</html>