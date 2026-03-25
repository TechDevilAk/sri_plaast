<?php
session_start();
$currentPage = 'users';
$pageTitle = 'Users Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

checkRoleAccess(['admin']);


$success = '';
$error = '';

// Handle add user (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'sale';
    $status = isset($_POST['status']) ? 1 : 0;

    if (empty($name) || empty($username) || empty($password)) {
        $error = 'Name, username and password are required.';
    } else {
        // Check if username exists
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            $error = 'Username already exists. Please choose a different username.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, username, password, role, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $name, $username, $hashed_password, $role, $status);
            
            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', 'Created new user: " . $conn->real_escape_string($username) . "')";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("i", $_SESSION['user_id']);
                $log_stmt->execute();
                
                $success = "User added successfully.";
            } else {
                $error = "Failed to add user.";
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Handle edit user (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user' && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
    $editId = intval($_POST['user_id']);
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? 'sale';
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Check if editing self
    if ($editId == $_SESSION['user_id'] && $role !== 'admin') {
        $error = 'You cannot change your own role from admin.';
    } else {
        if (empty($name) || empty($username)) {
            $error = 'Name and username are required.';
        } else {
            // Check if username exists for other users
            $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check->bind_param("si", $username, $editId);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $error = 'Username already exists. Please choose a different username.';
            } else {
                // Check if password update is requested
                if (!empty($_POST['password'])) {
                    $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET name=?, username=?, password=?, role=?, status=? WHERE id=?");
                    $stmt->bind_param("ssssii", $name, $username, $hashed_password, $role, $status, $editId);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET name=?, username=?, role=?, status=? WHERE id=?");
                    $stmt->bind_param("sssii", $name, $username, $role, $status, $editId);
                }
                
                if ($stmt->execute()) {
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', 'Updated user: " . $conn->real_escape_string($username) . "')";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("i", $_SESSION['user_id']);
                    $log_stmt->execute();
                    
                    $success = "User updated successfully.";
                } else {
                    $error = "Failed to update user.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle delete user (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user' && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
    $deleteId = intval($_POST['user_id']);
    
    // Prevent self-deletion
    if ($deleteId == $_SESSION['user_id']) {
        $error = "You cannot delete your own account.";
    } else {
        // Get username for logging
        $user_query = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $user_query->bind_param("i", $deleteId);
        $user_query->execute();
        $user_result = $user_query->get_result();
        $user_data = $user_result->fetch_assoc();
        $username = $user_data['username'] ?? 'Unknown';
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $deleteId);
        
        if ($stmt->execute()) {
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', 'Deleted user: " . $conn->real_escape_string($username) . "')";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("i", $_SESSION['user_id']);
            $log_stmt->execute();
            
            $success = "User deleted successfully.";
        } else {
            $error = "Failed to delete user.";
        }
        $stmt->close();
    }
}

// Handle status toggle (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status' && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
    $toggleId = intval($_POST['user_id']);
    $newStatus = intval($_POST['status']);
    
    // Prevent self-deactivation
    if ($toggleId == $_SESSION['user_id'] && $newStatus == 0) {
        $error = "You cannot deactivate your own account.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param("ii", $newStatus, $toggleId);
        
        if ($stmt->execute()) {
            $status_text = $newStatus ? 'activated' : 'deactivated';
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', 'User " . $status_text . "')";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("i", $_SESSION['user_id']);
            $log_stmt->execute();
            
            $success = "User " . $status_text . " successfully.";
        }
        $stmt->close();
    }
}

// Filters
$filterRole = $_GET['filter_role'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';

$where = "1=1";
$params = [];
$types = "";

if ($filterRole && $filterRole !== 'all') {
    $where .= " AND role = ?";
    $params[] = $filterRole;
    $types .= "s";
}

if ($filterStatus && $filterStatus !== 'all') {
    $where .= " AND status = ?";
    $params[] = $filterStatus;
    $types .= "i";
}

$sql = "SELECT * FROM users WHERE $where ORDER BY created_at DESC";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $users = $stmt->get_result();
} else {
    $users = $conn->query($sql);
}

// Stats
$totalCount = $conn->query("SELECT COUNT(*) as cnt FROM users")->fetch_assoc()['cnt'];
$adminCount = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role='admin'")->fetch_assoc()['cnt'];
$saleCount = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role='sale'")->fetch_assoc()['cnt'];
$activeCount = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE status=1")->fetch_assoc()['cnt'];
$inactiveCount = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE status=0")->fetch_assoc()['cnt'];

// Last login info (from activity log)
$last_login_query = "SELECT user_id, MAX(created_at) as last_login 
                     FROM activity_log 
                     WHERE action = 'login' 
                     GROUP BY user_id";
$last_login_result = $conn->query($last_login_query);
$last_logins = [];
while ($row = $last_login_result->fetch_assoc()) {
    $last_logins[$row['user_id']] = $row['last_login'];
}

// Status badge helper
function userStatusClass($status) {
    return $status == 1 ? 'completed' : 'cancelled';
}

// Role badge helper
function userRoleBadge($role) {
    return $role === 'admin' ? 'bg-primary' : 'bg-info';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .role-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
            display: inline-block;
        }
        .role-badge.admin {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .role-badge.sale {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .user-avatar-sm {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }
        .user-info-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .user-name-text {
            font-weight: 500;
            color: var(--text-primary);
        }
        .user-username-text {
            font-size: 12px;
            color: var(--text-muted);
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Users Management</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage system users and their permissions</p>
                </div>
                <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addUserModal" data-testid="button-add-user">
                    <i class="bi bi-person-plus"></i> Add New User
                </button>
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

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-total">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon blue">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Total Users</div>
                                <div class="stat-value" data-testid="stat-value-total"><?php echo $totalCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-active">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon green">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Active Users</div>
                                <div class="stat-value" data-testid="stat-value-active"><?php echo $activeCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-admin">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon purple">
                                <i class="bi bi-shield-lock"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Admins</div>
                                <div class="stat-value" data-testid="stat-value-admin"><?php echo $adminCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-sale">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon orange">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Sales Staff</div>
                                <div class="stat-value" data-testid="stat-value-sale"><?php echo $saleCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="dashboard-card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-3 flex-wrap filter-bar-inner">
                        <div class="d-flex gap-1 flex-wrap filter-tabs">
                            <a href="users.php" class="btn btn-sm <?php echo (!$filterRole && !$filterStatus) || ($filterRole === 'all' && $filterStatus === 'all') ? 'btn-primary' : 'btn-outline-secondary'; ?>" data-testid="filter-all">
                                All <span class="badge bg-white text-dark ms-1"><?php echo $totalCount; ?></span>
                            </a>
                            <a href="users.php?filter_role=admin" class="btn btn-sm <?php echo $filterRole === 'admin' ? 'btn-primary' : 'btn-outline-secondary'; ?>" data-testid="filter-admin">
                                Admin <span class="badge bg-white text-dark ms-1"><?php echo $adminCount; ?></span>
                            </a>
                            <a href="users.php?filter_role=sale" class="btn btn-sm <?php echo $filterRole === 'sale' ? 'btn-primary' : 'btn-outline-secondary'; ?>" data-testid="filter-sale">
                                Sales <span class="badge bg-white text-dark ms-1"><?php echo $saleCount; ?></span>
                            </a>
                            <a href="users.php?filter_status=1" class="btn btn-sm <?php echo $filterStatus === '1' ? 'btn-success' : 'btn-outline-secondary'; ?>" data-testid="filter-active">
                                Active <span class="badge bg-white text-dark ms-1"><?php echo $activeCount; ?></span>
                            </a>
                            <a href="users.php?filter_status=0" class="btn btn-sm <?php echo $filterStatus === '0' ? 'btn-danger' : 'btn-outline-secondary'; ?>" data-testid="filter-inactive">
                                Inactive <span class="badge bg-white text-dark ms-1"><?php echo $inactiveCount; ?></span>
                            </a>
                        </div>
                        <div class="ms-auto">
                            <a href="users.php" class="btn btn-sm btn-outline-secondary" data-testid="clear-filters">
                                <i class="bi bi-x-circle"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Users Table -->
            <div class="dashboard-card" data-testid="users-table">
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="usersTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>User</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Created</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($users && $users->num_rows > 0): ?>
                                <?php while ($user = $users->fetch_assoc()): 
                                    $initials = '';
                                    $name_parts = explode(' ', $user['name']);
                                    foreach ($name_parts as $part) {
                                        if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                                    }
                                    if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                                    
                                    $last_login = $last_logins[$user['id']] ?? 'Never';
                                    if ($last_login !== 'Never') {
                                        $last_login = date('d M Y, h:i A', strtotime($last_login));
                                    }
                                ?>
                                    <tr data-testid="row-user-<?php echo $user['id']; ?>">
                                        <td><span class="order-id">#<?php echo $user['id']; ?></span></td>
                                        <td>
                                            <div class="user-info-cell">
                                                <div class="user-avatar-sm"><?php echo $initials; ?></div>
                                                <div>
                                                    <div class="user-name-text"><?php echo htmlspecialchars($user['name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="user-username-text">@<?php echo htmlspecialchars($user['username']); ?></span></td>
                                        <td>
                                            <span class="role-badge <?php echo $user['role']; ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo userStatusClass($user['status']); ?>">
                                                <span class="dot"></span>
                                                <?php echo $user['status'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td style="color: var(--text-muted); font-size: 13px;"><?php echo $last_login; ?></td>
                                        <td style="color: var(--text-muted); white-space: nowrap;"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <!-- Status Toggle -->
                                                    <form method="POST" action="users.php<?php echo buildQueryString(['filter_role', 'filter_status']); ?>" style="display: inline;">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="status" value="<?php echo $user['status'] ? 0 : 1; ?>">
                                                        <button type="submit" class="btn btn-sm <?php echo $user['status'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>" style="font-size: 12px; padding: 3px 8px;" 
                                                                onclick="return confirm('Are you sure you want to <?php echo $user['status'] ? 'deactivate' : 'activate'; ?> this user?')"
                                                                data-testid="button-toggle-<?php echo $user['id']; ?>">
                                                            <i class="bi <?php echo $user['status'] ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <!-- Edit -->
                                                <button class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 3px 8px;" 
                                                        data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $user['id']; ?>" 
                                                        data-testid="button-edit-<?php echo $user['id']; ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <!-- Delete -->
                                                    <form method="POST" action="users.php<?php echo buildQueryString(['filter_role', 'filter_status']); ?>" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" 
                                                                data-testid="button-delete-<?php echo $user['id']; ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Edit User Modal -->
                                    <div class="modal fade" id="editUserModal<?php echo $user['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="users.php<?php echo buildQueryString(['filter_role', 'filter_status']); ?>" data-testid="form-edit-user-<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="action" value="edit_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit User</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required data-testid="input-edit-name-<?php echo $user['id']; ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Username <span class="text-danger">*</span></label>
                                                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required data-testid="input-edit-username-<?php echo $user['id']; ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Password</label>
                                                            <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password" data-testid="input-edit-password-<?php echo $user['id']; ?>">
                                                            <small class="text-muted">Only fill if you want to change the password</small>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Role</label>
                                                            <select name="role" class="form-select" data-testid="select-edit-role-<?php echo $user['id']; ?>" 
                                                                    <?php echo ($user['id'] == $_SESSION['user_id'] && $user['role'] == 'admin') ? 'disabled' : ''; ?>>
                                                                <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                                <option value="sale" <?php echo $user['role'] === 'sale' ? 'selected' : ''; ?>>Sales</option>
                                                            </select>
                                                            <?php if ($user['id'] == $_SESSION['user_id'] && $user['role'] == 'admin'): ?>
                                                                <input type="hidden" name="role" value="admin">
                                                                <small class="text-muted">You cannot change your own role</small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mb-3">
                                                            <div class="form-check form-switch">
                                                                <input class="form-check-input" type="checkbox" name="status" id="editStatus<?php echo $user['id']; ?>" value="1" <?php echo $user['status'] ? 'checked' : ''; ?> data-testid="checkbox-edit-status-<?php echo $user['id']; ?>">
                                                                <label class="form-check-label" for="editStatus<?php echo $user['id']; ?>">Active Status</label>
                                                            </div>
                                                            <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                                                <small class="text-muted">You cannot deactivate your own account</small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary" data-testid="button-save-edit-<?php echo $user['id']; ?>">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php
                        if ($params) {
                            $stmt2 = $conn->prepare($sql);
                            $stmt2->bind_param($types, ...$params);
                            $stmt2->execute();
                            $mobileUsers = $stmt2->get_result();
                            $stmt2->close();
                        } else {
                            $mobileUsers = $conn->query($sql);
                        }
                    ?>
                    <?php if ($mobileUsers && $mobileUsers->num_rows > 0): ?>
                        <?php while ($mUser = $mobileUsers->fetch_assoc()): 
                            $initials = '';
                            $name_parts = explode(' ', $mUser['name']);
                            foreach ($name_parts as $part) {
                                if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                            }
                            if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                            
                            $last_login = $last_logins[$mUser['id']] ?? 'Never';
                            if ($last_login !== 'Never') {
                                $last_login = date('d M Y, h:i A', strtotime($last_login));
                            }
                        ?>
                            <div class="mobile-card" data-testid="mobile-card-user-<?php echo $mUser['id']; ?>">
                                <div class="mobile-card-header">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="user-avatar-sm"><?php echo $initials; ?></div>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($mUser['name']); ?></div>
                                            <div style="font-size: 11px; color: var(--text-muted);">@<?php echo htmlspecialchars($mUser['username']); ?></div>
                                        </div>
                                    </div>
                                    <span class="status-badge <?php echo userStatusClass($mUser['status']); ?>">
                                        <span class="dot"></span>
                                        <?php echo $mUser['status'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Role</span>
                                    <span class="mobile-card-value">
                                        <span class="role-badge <?php echo $mUser['role']; ?>" style="padding: 2px 8px;">
                                            <?php echo ucfirst($mUser['role']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Last Login</span>
                                    <span class="mobile-card-value"><?php echo $last_login; ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Created</span>
                                    <span class="mobile-card-value"><?php echo date('M d, Y', strtotime($mUser['created_at'])); ?></span>
                                </div>
                                <div class="mobile-card-actions">
                                    <?php if ($mUser['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" action="users.php<?php echo buildQueryString(['filter_role', 'filter_status']); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?php echo $mUser['id']; ?>">
                                            <input type="hidden" name="status" value="<?php echo $mUser['status'] ? 0 : 1; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $mUser['status'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>" 
                                                    onclick="return confirm('<?php echo $mUser['status'] ? 'Deactivate' : 'Activate'; ?> this user?')">
                                                <i class="bi <?php echo $mUser['status'] ? 'bi-pause-circle' : 'bi-play-circle'; ?> me-1"></i>
                                                <?php echo $mUser['status'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $mUser['id']; ?>">
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </button>
                                    
                                    <?php if ($mUser['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" action="users.php<?php echo buildQueryString(['filter_role', 'filter_status']); ?>" style="display: inline;" 
                                              onsubmit="return confirm('Delete this user permanently?')">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo $mUser['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash me-1"></i>Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-people d-block mb-2" style="font-size: 36px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No users found</div>
                            <div style="font-size: 13px;">
                                <?php if ($filterRole || $filterStatus): ?>
                                    Try changing your filters or <a href="users.php">view all users</a>
                                <?php else: ?>
                                    <a href="#" data-bs-toggle="modal" data-bs-target="#addUserModal">Add your first user</a> to get started
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="users.php<?php echo buildQueryString(['filter_role', 'filter_status']); ?>" data-testid="form-add-user">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-header">
                    <h5 class="modal-title">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="Enter full name" data-testid="input-add-name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required placeholder="Enter username" data-testid="input-add-username">
                        <small class="text-muted">Username must be unique</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required placeholder="Enter password" data-testid="input-add-password">
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" data-testid="select-add-role">
                            <option value="admin">Admin</option>
                            <option value="sale" selected>Sales</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="status" id="addStatus" value="1" checked data-testid="checkbox-add-status">
                            <label class="form-check-label" for="addStatus">Active Status</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-testid="button-submit-add-user">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

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
<script>
$(document).ready(function() {
    $('#usersTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search users:",
            lengthMenu: "Show _MENU_ users",
            info: "Showing _START_ to _END_ of _TOTAL_ users",
            emptyTable: "No users available"
        },
        columnDefs: [
            { orderable: false, targets: -1 }
        ]
    });
});

// Password validation
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const passwordInput = this.querySelector('input[name="password"]');
        if (passwordInput && passwordInput.value.length > 0 && passwordInput.value.length < 6) {
            e.preventDefault();
            alert('Password must be at least 6 characters long.');
        }
    });
});
</script>
</body>
</html>