<?php
session_start();
$currentPage = 'profile';
$pageTitle = 'My Profile';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can access profile
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Get current user details
$user_id = $_SESSION['user_id'];
$user_query = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($name)) {
        $error = 'Name is required.';
    } else {
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Update basic info
            $update_query = "UPDATE users SET name = ?";
            $params = [$name];
            $types = "s";
            
            // Add email if column exists (you may need to add this column)
            if (isset($user['email'])) {
                $update_query .= ", email = ?";
                $params[] = $email;
                $types .= "s";
            }
            
            // Add phone if column exists (you may need to add this column)
            if (isset($user['phone'])) {
                $update_query .= ", phone = ?";
                $params[] = $phone;
                $types .= "s";
            }
            
            $update_query .= " WHERE id = ?";
            $params[] = $user_id;
            $types .= "i";
            
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update profile.");
            }
            
            // Handle password change if requested
            if (!empty($new_password)) {
                // Verify current password
                if (empty($current_password)) {
                    throw new Exception("Current password is required to change password.");
                }
                
                if (!password_verify($current_password, $user['password'])) {
                    throw new Exception("Current password is incorrect.");
                }
                
                if (strlen($new_password) < 6) {
                    throw new Exception("New password must be at least 6 characters long.");
                }
                
                if ($new_password !== $confirm_password) {
                    throw new Exception("New passwords do not match.");
                }
                
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $pass_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $pass_stmt->bind_param("si", $hashed_password, $user_id);
                
                if (!$pass_stmt->execute()) {
                    throw new Exception("Failed to update password.");
                }
                $pass_stmt->close();
            }
            
            // Log activity
            $log_desc = "Updated profile information";
            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            
            $conn->commit();
            
            // Update session name
            $_SESSION['user_name'] = $name;
            
            $success = "Profile updated successfully.";
            
            // Refresh user data
            $stmt = $conn->prepare($user_query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
        $stmt->close();
    }
}

// Get user activity statistics
$login_count_query = "SELECT COUNT(*) as cnt FROM activity_log WHERE user_id = ? AND action = 'login'";
$stmt = $conn->prepare($login_count_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$login_count = $stmt->get_result()->fetch_assoc()['cnt'];

// Get last login time
$last_login_query = "SELECT created_at FROM activity_log WHERE user_id = ? AND action = 'login' ORDER BY created_at DESC LIMIT 1 OFFSET 1";
$stmt = $conn->prepare($last_login_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$last_login = $stmt->get_result()->fetch_assoc();
$previous_login = $last_login ? date('d M Y, h:i A', strtotime($last_login['created_at'])) : 'First login';

// Get total activities
$total_activities_query = "SELECT COUNT(*) as cnt FROM activity_log WHERE user_id = ?";
$stmt = $conn->prepare($total_activities_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_activities = $stmt->get_result()->fetch_assoc()['cnt'];

// Get recent activities
$recent_activities_query = "SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
$stmt = $conn->prepare($recent_activities_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_activities = $stmt->get_result();

// Get account creation date
$created_at = date('d M Y', strtotime($user['created_at']));
$member_since = date('F Y', strtotime($user['created_at']));

// Get user initials for avatar
$name_parts = explode(' ', $user['name']);
$initials = '';
foreach ($name_parts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
if (strlen($initials) > 2) {
    $initials = substr($initials, 0, 2);
}

// Check if user is admin
$is_admin = ($_SESSION['user_role'] === 'admin');

// Helper function for activity icon
function getActivityIcon($action) {
    switch($action) {
        case 'login': return 'bi-box-arrow-in-right';
        case 'logout': return 'bi-box-arrow-right';
        case 'create': return 'bi-plus-circle';
        case 'update': return 'bi-pencil';
        case 'delete': return 'bi-trash';
        case 'payment': return 'bi-cash-stack';
        case 'stock_update': return 'bi-box-seam';
        case 'cancel': return 'bi-x-circle';
        default: return 'bi-info-circle';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
      .profile-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    padding: 20px;
    color: white;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}


.profile-header::after {
    content: '';
    position: absolute;
    top: -30%;
    right: -5%;
    width: 200px;
    height: 200px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
}

.profile-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    border: 2px solid white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 600;
    color: white;
    position: relative;
    z-index: 1;
}
        
        .profile-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .profile-stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        
        .profile-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.08);
        }
        
        .profile-stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #2463eb;
            line-height: 1.2;
            margin-bottom: 4px;
        }
        
        .profile-stat-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
        }
        
        .activity-timeline {
            position: relative;
            padding-left: 30px;
            margin-left:20px ;
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
        }
        
        .info-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            height: 100%;
        }
        
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #eef2f6;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            width: 120px;
            font-size: 13px;
            color: #64748b;
        }
        
        .info-value {
            flex: 1;
            font-weight: 500;
            color: #1e293b;
        }
        
        .role-badge {
            background: #e8f2ff;
            color: #2463eb;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .role-badge.admin {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .role-badge.sale {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .password-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .btn-save {
            background: #2463eb;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .btn-save:hover {
            background: #1e4fba;
            transform: translateY(-1px);
        }
        
        .btn-cancel {
            background: white;
            color: #64748b;
            border: 1px solid #e2e8f0;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .card-body-custom{
        padding: 20px;
        }
        .btn-cancel:hover {
            background: #f8fafc;
            border-color: #94a3b8;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">My Profile</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage your account information and security</p>
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

            <!-- Profile Header -->
            <div class="profile-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="profile-avatar-lg"><?php echo $initials; ?></div>
                        <h2 style="font-size: 32px; font-weight: 700; margin-bottom: 8px;"><?php echo htmlspecialchars($user['name']); ?></h2>
                        <p style="font-size: 16px; opacity: 0.9; margin-bottom: 4px;">
                            <i class="bi bi-person-badge me-2"></i>
                            <span class="role-badge <?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
                            <span class="mx-3">•</span>
                            <i class="bi bi-at me-1"></i><?php echo htmlspecialchars($user['username']); ?>
                        </p>
                        <p style="font-size: 14px; opacity: 0.8;">
                            <i class="bi bi-calendar me-1"></i>Member since <?php echo $member_since; ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge bg-white text-dark px-3 py-2" style="font-size: 14px;">
                            <i class="bi bi-shield-check me-1"></i>
                            <?php echo $user['status'] ? 'Active Account' : 'Inactive Account'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Profile Stats -->
            <div class="profile-stats-grid">
                <div class="profile-stat-card">
                    <div class="profile-stat-value"><?php echo $login_count; ?></div>
                    <div class="profile-stat-label">Total Logins</div>
                </div>
                <div class="profile-stat-card">
                    <div class="profile-stat-value"><?php echo $total_activities; ?></div>
                    <div class="profile-stat-label">Activities</div>
                </div>
                <div class="profile-stat-card">
                    <div class="profile-stat-value" style="font-size: 16px;"><?php echo $previous_login; ?></div>
                    <div class="profile-stat-label">Previous Login</div>
                </div>
                <div class="profile-stat-card">
                    <div class="profile-stat-value"><?php echo date('d M Y', strtotime($user['created_at'])); ?></div>
                    <div class="profile-stat-label">Joined</div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Profile Information -->
                <div class="col-lg-6 p-4">
                    <div class="dashboard-card">
                        <div class="card-header-custom p-4">
                            <h5><i class="bi bi-person me-2"></i>Profile Information</h5>
                            <p>Your personal details</p>
                        </div>
                        <div class="card-body-custom">
                            <form method="POST" action="profile.php" id="profileForm">
                                <input type="hidden" name="action" value="update_profile">
                                
                                <div class="info-card">
                                    <div class="info-row">
                                        <span class="info-label">Full Name</span>
                                        <span class="info-value">
                                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                        </span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Username</span>
                                        <span class="info-value">
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly disabled>
                                            <small class="text-muted">Username cannot be changed</small>
                                        </span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Email</span>
                                        <span class="info-value">
                                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" placeholder="Enter your email">
                                        </span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Phone</span>
                                        <span class="info-value">
                                            <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="Enter your phone number">
                                        </span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Role</span>
                                        <span class="info-value">
                                            <span class="role-badge <?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
                                        </span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Status</span>
                                        <span class="info-value">
                                            <span class="status-badge <?php echo $user['status'] ? 'completed' : 'cancelled'; ?>">
                                                <span class="dot"></span>
                                                <?php echo $user['status'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Password Change Section -->
                                <div class="password-section p-4">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-shield-lock me-2"></i>
                                        Change Password
                                    </h6>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Current Password</label>
                                        <input type="password" name="current_password" class="form-control" placeholder="Enter current password">
                                    </div>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">New Password</label>
                                            <input type="password" name="new_password" class="form-control" placeholder="Enter new password">
                                            <small class="text-muted">Minimum 6 characters</small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Confirm New Password</label>
                                            <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex gap-3 mt-4">
                                    <button type="submit" class="btn-save">
                                        <i class="bi bi-check-circle me-2"></i>Save Changes
                                    </button>
                                    <button type="button" class="btn-cancel" onclick="resetForm()">
                                        <i class="bi bi-arrow-counterclockwise me-2"></i>Reset
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity & Security -->
                <div class="col-lg-6 p-4">
                    <!-- Recent Activity -->
                    <div class="dashboard-card mb-4">
                        <div class="card-header-custom p-4">
                            <h5><i class="bi bi-clock-history me-2"></i>Recent Activity</h5>
                            <p>Your latest actions</p>
                        </div>
                        <div class="card-body-custom">
                            <div class="activity-timeline">
                                <?php if ($recent_activities && $recent_activities->num_rows > 0): ?>
                                    <?php while ($activity = $recent_activities->fetch_assoc()): 
                                        $timeline_class = 'timeline-item';
                                        if (in_array($activity['action'], ['login', 'create', 'update', 'delete', 'payment'])) {
                                            $timeline_class .= ' ' . $activity['action'];
                                        }
                                    ?>
                                        <div class="<?php echo $timeline_class; ?>">
                                            <div class="timeline-content">
                                                <div class="timeline-title">
                                                    <i class="bi <?php echo getActivityIcon($activity['action']); ?> me-2"></i>
                                                    <?php echo ucfirst($activity['action']); ?>
                                                </div>
                                                <div class="timeline-meta">
                                                    <span><?php echo htmlspecialchars($activity['description']); ?></span>
                                                    <span><?php echo date('d M Y, h:i A', strtotime($activity['created_at'])); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="bi bi-activity" style="font-size: 40px;"></i>
                                        <p class="mt-2">No recent activity</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="text-center mt-3">
                                <a href="activity-log.php" class="btn-outline-custom btn-sm">
                                    <i class="bi bi-eye me-1"></i>View All Activity
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Security Tips -->
                    <div class="dashboard-card">
                        <div class="card-header-custom p-4">
                            <h5><i class="bi bi-shield-check me-2"></i>Security Tips</h5>
                            <p>Keep your account secure</p>
                        </div>
                        <div class="card-body-custom">
                            <div class="d-flex gap-3 mb-3">
                                <div class="stat-icon green" style="width: 40px; height: 40px; font-size: 20px;">
                                    <i class="bi bi-check-lg"></i>
                                </div>
                                <div>
                                    <strong>Strong Password</strong>
                                    <p class="text-muted mb-0" style="font-size: 13px;">Use a mix of letters, numbers, and special characters. Avoid common words.</p>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-3 mb-3">
                                <div class="stat-icon green" style="width: 40px; height: 40px; font-size: 20px;">
                                    <i class="bi bi-check-lg"></i>
                                </div>
                                <div>
                                    <strong>Regular Updates</strong>
                                    <p class="text-muted mb-0" style="font-size: 13px;">Change your password every 3 months for better security.</p>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-3 mb-3">
                                <div class="stat-icon green" style="width: 40px; height: 40px; font-size: 20px;">
                                    <i class="bi bi-check-lg"></i>
                                </div>
                                <div>
                                    <strong>Monitor Activity</strong>
                                    <p class="text-muted mb-0" style="font-size: 13px;">Review your activity log regularly for any unusual actions.</p>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-3">
                                <div class="stat-icon green" style="width: 40px; height: 40px; font-size: 20px;">
                                    <i class="bi bi-check-lg"></i>
                                </div>
                                <div>
                                    <strong>Never Share Credentials</strong>
                                    <p class="text-muted mb-0" style="font-size: 13px;">Your password is confidential. Never share it with anyone.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Account Information -->
            <div class="row g-4 mt-2">
                <div class="col-12">
                    <div class="dashboard-card">
                        <div class="card-header-custom p-4">
                            <h5><i class="bi bi-info-circle me-2"></i>Account Information</h5>
                            <p>System details</p>
                        </div>
                        <div class="card-body-custom">
                            <div class="row g-4">
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <div class="text-muted small">Account Created</div>
                                        <div class="fw-semibold"><?php echo date('d M Y', strtotime($user['created_at'])); ?></div>
                                        <div class="text-muted small"><?php echo date('h:i A', strtotime($user['created_at'])); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <div class="text-muted small">Last Updated</div>
                                        <div class="fw-semibold"><?php echo date('d M Y', strtotime($user['updated_at'])); ?></div>
                                        <div class="text-muted small"><?php echo date('h:i A', strtotime($user['updated_at'])); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <div class="text-muted small">Last Login</div>
                                        <div class="fw-semibold"><?php echo $previous_login; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <div class="text-muted small">User ID</div>
                                        <div class="fw-semibold">#<?php echo $user['id']; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
// Reset form to original values
function resetForm() {
    document.getElementById('profileForm').reset();
}

// Password match validation
document.getElementById('profileForm')?.addEventListener('submit', function(e) {
    const newPass = document.querySelector('[name="new_password"]').value;
    const confirmPass = document.querySelector('[name="confirm_password"]').value;
    const currentPass = document.querySelector('[name="current_password"]').value;
    
    if (newPass || confirmPass || currentPass) {
        if (!currentPass) {
            e.preventDefault();
            alert('Please enter your current password to change password.');
            return;
        }
        
        if (newPass.length > 0 && newPass.length < 6) {
            e.preventDefault();
            alert('New password must be at least 6 characters long.');
            return;
        }
        
        if (newPass !== confirmPass) {
            e.preventDefault();
            alert('New passwords do not match.');
            return;
        }
    }
});

// Phone number formatting
document.querySelector('[name="phone"]')?.addEventListener('input', function(e) {
    this.value = this.value.replace(/[^0-9+]/g, '');
});

// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
});
</script>

</body>
</html>