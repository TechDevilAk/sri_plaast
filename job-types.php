<?php
$currentPage = 'job-types';
$pageTitle = 'Job Types';
require_once 'includes/db.php';

$success = '';
$error = '';

// Handle add job type (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_job_type') {
    $name = trim($_POST['name'] ?? '');
    $single_side_price = floatval($_POST['single_side_price'] ?? 0);
    $double_side_price = floatval($_POST['double_side_price'] ?? 0);
    $status = $_POST['status'] ?? 'active';

    if ($name === '') {
        $error = 'Job type name is required.';
    } else {
        $stmt = $conn->prepare("INSERT INTO job_types (name, single_side_price, double_side_price, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sddd", $name, $single_side_price, $double_side_price, $status);
        if ($stmt->execute()) {
            $success = "Job type added successfully.";
        } else {
            $error = "Failed to add job type.";
        }
        $stmt->close();
    }
}

// Handle edit job type (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_job_type' && isset($_POST['job_type_id']) && is_numeric($_POST['job_type_id'])) {
    $editId = intval($_POST['job_type_id']);
    $name = trim($_POST['name'] ?? '');
    $single_side_price = floatval($_POST['single_side_price'] ?? 0);
    $double_side_price = floatval($_POST['double_side_price'] ?? 0);
    $status = $_POST['status'] ?? 'active';

    if ($name === '') {
        $error = 'Job type name is required.';
    } else {
        $stmt = $conn->prepare("UPDATE job_types SET name=?, single_side_price=?, double_side_price=?, status=? WHERE id=?");
        $stmt->bind_param("sdddi", $name, $single_side_price, $double_side_price, $status, $editId);
        if ($stmt->execute()) {
            $success = "Job type updated successfully.";
        } else {
            $error = "Failed to update job type.";
        }
        $stmt->close();
    }
}

// Handle delete job type (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_job_type' && isset($_POST['job_type_id']) && is_numeric($_POST['job_type_id'])) {
    $deleteId = intval($_POST['job_type_id']);
    $stmt = $conn->prepare("DELETE FROM job_types WHERE id = ?");
    $stmt->bind_param("i", $deleteId);
    if ($stmt->execute()) {
        $success = "Job type deleted successfully.";
    } else {
        $error = "Failed to delete job type.";
    }
    $stmt->close();
}

// Handle status update (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status' && isset($_POST['job_type_id']) && is_numeric($_POST['job_type_id'])) {
    $statusId = intval($_POST['job_type_id']);
    $newStatus = $_POST['new_status'] ?? '';
    $allowedStatuses = ['active', 'inactive'];
    if (in_array($newStatus, $allowedStatuses)) {
        $stmt = $conn->prepare("UPDATE job_types SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $statusId);
        if ($stmt->execute()) {
            $success = "Job type status updated to " . ucfirst($newStatus) . ".";
        }
        $stmt->close();
    }
}

// Filters
$filterStatus = $_GET['filter_status'] ?? '';

$where = "1=1";
$params = [];
$types = "";

if ($filterStatus && $filterStatus !== 'all') {
    $where .= " AND status = ?";
    $params[] = $filterStatus;
    $types .= "s";
}

$sql = "SELECT * FROM job_types WHERE $where ORDER BY created_at DESC";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $job_types = $stmt->get_result();
} else {
    $job_types = $conn->query($sql);
}

// Stats
$totalCount = $conn->query("SELECT COUNT(*) as cnt FROM job_types")->fetch_assoc()['cnt'];
$activeCount = $conn->query("SELECT COUNT(*) as cnt FROM job_types WHERE status='active'")->fetch_assoc()['cnt'];
$inactiveCount = $conn->query("SELECT COUNT(*) as cnt FROM job_types WHERE status='inactive'")->fetch_assoc()['cnt'];

// Additional stats
$avgSinglePrice = $conn->query("SELECT AVG(single_side_price) as avg FROM job_types WHERE status='active'")->fetch_assoc()['avg'];
$avgDoublePrice = $conn->query("SELECT AVG(double_side_price) as avg FROM job_types WHERE status='active'")->fetch_assoc()['avg'];
$totalJobTypesValue = $conn->query("SELECT SUM(single_side_price + double_side_price) as total FROM job_types WHERE status='active'")->fetch_assoc()['total'];

// Status badge helper
function jobTypeStatusClass($status) {
    switch ($status) {
        case 'active': return 'completed';
        case 'inactive': return 'cancelled';
        default: return 'pending';
    }
}

// Format price helper
function formatPrice($price) {
    if ($price === null || $price == 0) {
        return '₹0.00';
    }
    return '₹' . number_format($price, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Surya Press - Job Types</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <style>
    .price-badge {
      background: var(--bg-light);
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 13px;
      font-weight: 500;
      color: var(--text-primary);
      white-space: nowrap;
    }
    .price-badge i {
      color: var(--primary-color);
      margin-right: 4px;
      font-size: 12px;
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
          <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Job Types</h4>
          <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage printing job types and pricing</p>
        </div>
        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addJobTypeModal" data-testid="button-add-job-type">
          <i class="bi bi-plus-circle"></i> Add Job Type
        </button>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success d-flex align-items-center gap-2" role="alert" data-testid="alert-success">
          <i class="bi bi-check-circle-fill"></i>
          <?php echo htmlspecialchars($success); ?>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2" role="alert" data-testid="alert-error">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <!-- Stat Cards - Now 4 Cards -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
          <div class="stat-card" data-testid="stat-total">
            <div class="d-flex align-items-center gap-3">
              <div class="stat-icon blue">
                <i class="bi bi-tags"></i>
              </div>
              <div class="stat-info">
                <div class="stat-label">Total Job Types</div>
                <div class="stat-value" data-testid="stat-value-total"><?php echo $totalCount; ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card" data-testid="stat-active">
            <div class="d-flex align-items-center gap-3">
              <div class="stat-icon green">
                <i class="bi bi-check-circle"></i>
              </div>
              <div class="stat-info">
                <div class="stat-label">Active</div>
                <div class="stat-value" data-testid="stat-value-active"><?php echo $activeCount; ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card" data-testid="stat-inactive">
            <div class="d-flex align-items-center gap-3">
              <div class="stat-icon orange">
                <i class="bi bi-x-circle"></i>
              </div>
              <div class="stat-info">
                <div class="stat-label">Inactive</div>
                <div class="stat-value" data-testid="stat-value-inactive"><?php echo $inactiveCount; ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card" data-testid="stat-avg-price">
            <div class="d-flex align-items-center gap-3">
              <div class="stat-icon purple">
                <i class="bi bi-currency-rupee"></i>
              </div>
              <div class="stat-info">
                <div class="stat-label">Avg. Single Price</div>
                <div class="stat-value" data-testid="stat-value-avg"><?php echo formatPrice($avgSinglePrice); ?></div>
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
              <a href="job-types.php" class="btn btn-sm <?php echo !$filterStatus || $filterStatus === 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?>" data-testid="filter-all">
                All <span class="badge bg-white text-dark ms-1"><?php echo $totalCount; ?></span>
              </a>
              <a href="job-types.php?filter_status=active" class="btn btn-sm <?php echo $filterStatus === 'active' ? 'btn-success' : 'btn-outline-secondary'; ?>" data-testid="filter-active">
                Active <span class="badge bg-white text-dark ms-1"><?php echo $activeCount; ?></span>
              </a>
              <a href="job-types.php?filter_status=inactive" class="btn btn-sm <?php echo $filterStatus === 'inactive' ? 'btn-danger' : 'btn-outline-secondary'; ?>" data-testid="filter-inactive">
                Inactive <span class="badge bg-white text-dark ms-1"><?php echo $inactiveCount; ?></span>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Job Types Table -->
      <div class="dashboard-card" data-testid="job-types-table">
        <div class="desktop-table" style="overflow-x: auto;">
          <table class="table-custom" id="jobTypesTable">
            <thead>
              <tr>
                <th>#</th>
                <th>Job Type Name</th>
                <th>Single Side Price</th>
                <th>Double Side Price</th>
                <th>Status</th>
                <th>Created</th>
                <th style="text-align: center;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($job_types && $job_types->num_rows > 0): ?>
                <?php while ($job_type = $job_types->fetch_assoc()): ?>
                  <tr data-testid="row-job-type-<?php echo $job_type['id']; ?>">
                    <td><span class="order-id">#<?php echo $job_type['id']; ?></span></td>
                    <td class="fw-semibold"><?php echo htmlspecialchars($job_type['name']); ?></td>
                    <td>
                      <span class="price-badge">
                        <i class="bi bi-file-text"></i>
                        <?php echo formatPrice($job_type['single_side_price']); ?>
                      </span>
                    </td>
                    <td>
                      <span class="price-badge">
                        <i class="bi bi-files"></i>
                        <?php echo formatPrice($job_type['double_side_price']); ?>
                      </span>
                    </td>
                    <td>
                      <span class="status-badge <?php echo jobTypeStatusClass($job_type['status']); ?>">
                        <span class="dot"></span>
                        <?php echo ucfirst($job_type['status']); ?>
                      </span>
                    </td>
                    <td style="color: var(--text-muted); white-space: nowrap;"><?php echo date('M d, Y', strtotime($job_type['created_at'])); ?></td>
                    <td>
                      <div class="d-flex align-items-center justify-content-center gap-1">
                        <!-- Status Dropdown -->
                        <div class="dropdown">
                          <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" style="font-size: 12px; padding: 3px 8px;" data-testid="button-status-<?php echo $job_type['id']; ?>">
                            <i class="bi bi-arrow-repeat"></i>
                          </button>
                          <div class="dropdown-menu dropdown-menu-end p-1">
                            <h6 class="dropdown-header" style="font-size: 11px;">Change Status</h6>
                            <form method="POST" action="job-types.php<?php echo $filterStatus ? '?filter_status='.$filterStatus : ''; ?>">
                              <input type="hidden" name="action" value="update_status">
                              <input type="hidden" name="job_type_id" value="<?php echo $job_type['id']; ?>">
                              <button type="submit" name="new_status" value="active" class="dropdown-item" data-testid="button-status-active-<?php echo $job_type['id']; ?>">
                                <span class="status-badge completed" style="font-size: 11px;"><span class="dot"></span>Active</span>
                              </button>
                              <button type="submit" name="new_status" value="inactive" class="dropdown-item" data-testid="button-status-inactive-<?php echo $job_type['id']; ?>">
                                <span class="status-badge cancelled" style="font-size: 11px;"><span class="dot"></span>Inactive</span>
                              </button>
                            </form>
                          </div>
                        </div>
                        <!-- Edit -->
                        <button class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 3px 8px;" data-bs-toggle="modal" data-bs-target="#editJobTypeModal<?php echo $job_type['id']; ?>" data-testid="button-edit-<?php echo $job_type['id']; ?>">
                          <i class="bi bi-pencil"></i>
                        </button>
                        <!-- Delete -->
                        <form method="POST" action="job-types.php<?php echo $filterStatus ? '?filter_status='.$filterStatus : ''; ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this job type?')">
                          <input type="hidden" name="action" value="delete_job_type">
                          <input type="hidden" name="job_type_id" value="<?php echo $job_type['id']; ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" data-testid="button-delete-<?php echo $job_type['id']; ?>">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>

                  <!-- Edit Job Type Modal -->
                  <div class="modal fade" id="editJobTypeModal<?php echo $job_type['id']; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                      <div class="modal-content">
                        <form method="POST" action="job-types.php<?php echo $filterStatus ? '?filter_status='.$filterStatus : ''; ?>" data-testid="form-edit-job-type-<?php echo $job_type['id']; ?>">
                          <input type="hidden" name="action" value="edit_job_type">
                          <input type="hidden" name="job_type_id" value="<?php echo $job_type['id']; ?>">
                          <div class="modal-header">
                            <h5 class="modal-title">Edit Job Type</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <div class="mb-3">
                              <label class="form-label">Job Type Name <span class="text-danger">*</span></label>
                              <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($job_type['name']); ?>" required data-testid="input-edit-name-<?php echo $job_type['id']; ?>">
                            </div>
                            <div class="row g-3 mb-3">
                              <div class="col-md-6">
                                <label class="form-label">Single Side Price (₹)</label>
                                <div class="input-group">
                                  <span class="input-group-text">₹</span>
                                  <input type="number" name="single_side_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($job_type['single_side_price']); ?>" data-testid="input-edit-single-<?php echo $job_type['id']; ?>">
                                </div>
                              </div>
                              <div class="col-md-6">
                                <label class="form-label">Double Side Price (₹)</label>
                                <div class="input-group">
                                  <span class="input-group-text">₹</span>
                                  <input type="number" name="double_side_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($job_type['double_side_price']); ?>" data-testid="input-edit-double-<?php echo $job_type['id']; ?>">
                                </div>
                              </div>
                            </div>
                            <div class="mb-3">
                              <label class="form-label">Status</label>
                              <select name="status" class="form-select" data-testid="select-edit-status-<?php echo $job_type['id']; ?>">
                                <option value="active" <?php echo $job_type['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $job_type['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                              </select>
                            </div>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" data-testid="button-save-edit-<?php echo $job_type['id']; ?>">Save Changes</button>
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
              $mobileJobTypes = $stmt2->get_result();
              $stmt2->close();
            } else {
              $mobileJobTypes = $conn->query($sql);
            }
          ?>
          <?php if ($mobileJobTypes && $mobileJobTypes->num_rows > 0): ?>
            <?php while ($mJobType = $mobileJobTypes->fetch_assoc()): ?>
              <div class="mobile-card" data-testid="mobile-card-job-type-<?php echo $mJobType['id']; ?>">
                <div class="mobile-card-header">
                  <div>
                    <span class="order-id">#<?php echo $mJobType['id']; ?></span>
                    <span class="customer-name ms-2"><?php echo htmlspecialchars($mJobType['name']); ?></span>
                  </div>
                  <span class="status-badge <?php echo jobTypeStatusClass($mJobType['status']); ?>">
                    <span class="dot"></span>
                    <?php echo ucfirst($mJobType['status']); ?>
                  </span>
                </div>
                <div class="mobile-card-row">
                  <span class="mobile-card-label">Single Side</span>
                  <span class="mobile-card-value"><?php echo formatPrice($mJobType['single_side_price']); ?></span>
                </div>
                <div class="mobile-card-row">
                  <span class="mobile-card-label">Double Side</span>
                  <span class="mobile-card-value"><?php echo formatPrice($mJobType['double_side_price']); ?></span>
                </div>
                <div class="mobile-card-row">
                  <span class="mobile-card-label">Created</span>
                  <span class="mobile-card-value"><?php echo date('M d, Y', strtotime($mJobType['created_at'])); ?></span>
                </div>
                <div class="mobile-card-actions">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" data-testid="mobile-button-status-<?php echo $mJobType['id']; ?>">
                      <i class="bi bi-arrow-repeat me-1"></i>Status
                    </button>
                    <div class="dropdown-menu p-1">
                      <form method="POST" action="job-types.php<?php echo $filterStatus ? '?filter_status='.$filterStatus : ''; ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="job_type_id" value="<?php echo $mJobType['id']; ?>">
                        <button type="submit" name="new_status" value="active" class="dropdown-item"><span class="status-badge completed" style="font-size: 11px;"><span class="dot"></span>Active</span></button>
                        <button type="submit" name="new_status" value="inactive" class="dropdown-item"><span class="status-badge cancelled" style="font-size: 11px;"><span class="dot"></span>Inactive</span></button>
                      </form>
                    </div>
                  </div>
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editJobTypeModal<?php echo $mJobType['id']; ?>" data-testid="mobile-button-edit-<?php echo $mJobType['id']; ?>">
                    <i class="bi bi-pencil me-1"></i>Edit
                  </button>
                  <form method="POST" action="job-types.php<?php echo $filterStatus ? '?filter_status='.$filterStatus : ''; ?>" style="display: inline;" onsubmit="return confirm('Delete this job type?')">
                    <input type="hidden" name="action" value="delete_job_type">
                    <input type="hidden" name="job_type_id" value="<?php echo $mJobType['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" data-testid="mobile-button-delete-<?php echo $mJobType['id']; ?>"><i class="bi bi-trash me-1"></i>Delete</button>
                  </form>
                </div>
              </div>
            <?php endwhile; ?>
          <?php else: ?>
            <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
              <i class="bi bi-tags d-block mb-2" style="font-size: 36px;"></i>
              <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No job types found</div>
              <div style="font-size: 13px;">
                <?php if ($filterStatus): ?>
                  Try changing your filters or <a href="job-types.php">view all job types</a>
                <?php else: ?>
                  <a href="#" data-bs-toggle="modal" data-bs-target="#addJobTypeModal">Add your first job type</a> to get started
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

<!-- Add Job Type Modal -->
<div class="modal fade" id="addJobTypeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="job-types.php<?php echo $filterStatus ? '?filter_status='.$filterStatus : ''; ?>" data-testid="form-add-job-type">
        <input type="hidden" name="action" value="add_job_type">
        <div class="modal-header">
          <h5 class="modal-title">Add Job Type</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Job Type Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required placeholder="e.g., Black & White Print, Color Print, etc." data-testid="input-add-name">
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Single Side Price (₹)</label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" name="single_side_price" class="form-control" step="0.01" min="0" value="0.00" data-testid="input-add-single">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Double Side Price (₹)</label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" name="double_side_price" class="form-control" step="0.01" min="0" value="0.00" data-testid="input-add-double">
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" data-testid="select-add-status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" data-testid="button-submit-add-job-type">Add Job Type</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#jobTypesTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            emptyTable: "No data available"
        },
        columnDefs: [
            { orderable: false, targets: -1 }
        ]
    });
});
</script>
</body>
</html>