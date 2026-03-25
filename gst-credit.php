<?php
session_start();
$currentPage = 'gst-credit';
$pageTitle = 'GST Credit';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can manage GST credit
checkRoleAccess(['admin']);

$success = '';
$error = '';

// Handle add GST credit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_credit') {
    
    $purchase_id = !empty($_POST['purchase_id']) ? intval($_POST['purchase_id']) : null;
    $cgst = floatval($_POST['cgst'] ?? 0);
    $sgst = floatval($_POST['sgst'] ?? 0);
    $total_credit = $cgst + $sgst;
    $notes = trim($_POST['notes'] ?? '');
    
    if ($cgst < 0 || $sgst < 0) {
        $error = "GST credit amounts cannot be negative.";
    } elseif ($cgst == 0 && $sgst == 0) {
        $error = "At least one GST credit amount is required.";
    } else {
        $stmt = $conn->prepare("INSERT INTO gst_credit_table (purchase_id, cgst, sgst, total_credit) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iddd", $purchase_id, $cgst, $sgst, $total_credit);
        
        if ($stmt->execute()) {
            $credit_id = $stmt->insert_id;
            
            // Log activity
            $log_desc = "Added GST credit: ₹" . number_format($cgst, 2) . " CGST + ₹" . number_format($sgst, 2) . " SGST = ₹" . number_format($total_credit, 2);
            if ($purchase_id) {
                $log_desc .= " for Purchase #" . $purchase_id;
            }
            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            
            $success = "GST credit added successfully.";
        } else {
            $error = "Failed to add GST credit.";
        }
        $stmt->close();
    }
}

// Handle edit GST credit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_credit' && isset($_POST['credit_id']) && is_numeric($_POST['credit_id'])) {
    
    $credit_id = intval($_POST['credit_id']);
    $purchase_id = !empty($_POST['purchase_id']) ? intval($_POST['purchase_id']) : null;
    $cgst = floatval($_POST['cgst'] ?? 0);
    $sgst = floatval($_POST['sgst'] ?? 0);
    $total_credit = $cgst + $sgst;
    
    if ($cgst < 0 || $sgst < 0) {
        $error = "GST credit amounts cannot be negative.";
    } elseif ($cgst == 0 && $sgst == 0) {
        $error = "At least one GST credit amount is required.";
    } else {
        $stmt = $conn->prepare("UPDATE gst_credit_table SET purchase_id=?, cgst=?, sgst=?, total_credit=? WHERE id=?");
        $stmt->bind_param("idddi", $purchase_id, $cgst, $sgst, $total_credit, $credit_id);
        
        if ($stmt->execute()) {
            // Log activity
            $log_desc = "Updated GST credit ID #$credit_id: ₹" . number_format($cgst, 2) . " CGST + ₹" . number_format($sgst, 2) . " SGST = ₹" . number_format($total_credit, 2);
            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            
            $success = "GST credit updated successfully.";
        } else {
            $error = "Failed to update GST credit.";
        }
        $stmt->close();
    }
}

// Handle delete GST credit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_credit' && isset($_POST['credit_id']) && is_numeric($_POST['credit_id'])) {
    
    $credit_id = intval($_POST['credit_id']);
    
    // Get credit details for logging
    $credit_query = $conn->prepare("SELECT * FROM gst_credit_table WHERE id = ?");
    $credit_query->bind_param("i", $credit_id);
    $credit_query->execute();
    $credit_data = $credit_query->get_result()->fetch_assoc();
    
    if ($credit_data) {
        $stmt = $conn->prepare("DELETE FROM gst_credit_table WHERE id = ?");
        $stmt->bind_param("i", $credit_id);
        
        if ($stmt->execute()) {
            // Log activity
            $log_desc = "Deleted GST credit ID #$credit_id: ₹" . number_format($credit_data['cgst'], 2) . " CGST + ₹" . number_format($credit_data['sgst'], 2) . " SGST = ₹" . number_format($credit_data['total_credit'], 2);
            $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            
            $success = "GST credit deleted successfully.";
        } else {
            $error = "Failed to delete GST credit.";
        }
        $stmt->close();
    } else {
        $error = "GST credit not found.";
    }
    $credit_query->close();
}

// Get all purchases for dropdown
$purchases = $conn->query("SELECT p.id, p.purchase_no, p.invoice_num, p.total, p.cgst_amount, p.sgst_amount 
                           FROM purchase p 
                           ORDER BY p.created_at DESC 
                           LIMIT 100");

// Get all GST credits with purchase details
$sql = "SELECT gc.*, p.purchase_no, p.invoice_num, p.total as purchase_total 
        FROM gst_credit_table gc
        LEFT JOIN purchase p ON gc.purchase_id = p.id
        ORDER BY gc.created_at DESC";

$credits = $conn->query($sql);

// Statistics
$total_credits = $conn->query("SELECT COUNT(*) as cnt FROM gst_credit_table")->fetch_assoc()['cnt'];
$total_cgst = $conn->query("SELECT COALESCE(SUM(cgst), 0) as total FROM gst_credit_table")->fetch_assoc()['total'];
$total_sgst = $conn->query("SELECT COALESCE(SUM(sgst), 0) as total FROM gst_credit_table")->fetch_assoc()['total'];
$total_credit_amount = $conn->query("SELECT COALESCE(SUM(total_credit), 0) as total FROM gst_credit_table")->fetch_assoc()['total'];

// Monthly credit summary
$monthly_summary = $conn->query("SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as count,
    SUM(cgst) as monthly_cgst,
    SUM(sgst) as monthly_sgst,
    SUM(total_credit) as monthly_total
    FROM gst_credit_table
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC");

// Credits with purchases vs without
$with_purchase = $conn->query("SELECT COUNT(*) as cnt FROM gst_credit_table WHERE purchase_id IS NOT NULL")->fetch_assoc()['cnt'];
$without_purchase = $total_credits - $with_purchase;

// Check if user is admin
$is_admin = ($_SESSION['user_role'] === 'admin');

// Helper function to format currency
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}
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
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
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
        
        .credit-badge {
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .credit-badge.cgst { background: #e8f2ff; color: #2463eb; }
        .credit-badge.sgst { background: #e0f2e7; color: #16a34a; }
        .credit-badge.total { background: #f2e8ff; color: #8b5cf6; }
        
        .purchase-ref {
            font-family: monospace;
            font-size: 13px;
            font-weight: 500;
            color: #2463eb;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .progress-sm {
            height: 8px;
            border-radius: 4px;
            background: #e2e8f0;
        }
        
        .progress-bar-credit {
            background: linear-gradient(90deg, #2463eb, #8b5cf6);
            border-radius: 4px;
        }
        
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 30px;
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
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">GST Credit Management</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Track and manage input GST credits from purchases</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($is_admin): ?>
                   
                    <?php endif; ?>
                    <button class="btn-export" onclick="exportToCSV()">
                        <i class="bi bi-download"></i> Export
                    </button>
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
                        <i class="bi bi-receipt"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_credits; ?></div>
                    <div class="stat-label">Total Credits</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="bi bi-currency-rupee"></i>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($total_cgst); ?></div>
                    <div class="stat-label">Total CGST Credit</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="bi bi-currency-rupee"></i>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($total_sgst); ?></div>
                    <div class="stat-label">Total SGST Credit</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i class="bi bi-piggy-bank"></i>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($total_credit_amount); ?></div>
                    <div class="stat-label">Total Credit</div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="summary-card">
                        <h6 class="fw-semibold mb-3"><i class="bi bi-pie-chart me-2"></i>Credit Distribution</h6>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted">With Purchase</span>
                                    <span class="fw-semibold"><?php echo $with_purchase; ?></span>
                                </div>
                                <div class="progress-sm">
                                    <div class="progress-bar-credit" style="width: <?php echo $total_credits > 0 ? ($with_purchase/$total_credits*100) : 0; ?>%;"></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted">Without Purchase</span>
                                    <span class="fw-semibold"><?php echo $without_purchase; ?></span>
                                </div>
                                <div class="progress-sm">
                                    <div class="progress-bar-credit" style="width: <?php echo $total_credits > 0 ? ($without_purchase/$total_credits*100) : 0; ?>%; background: #94a3b8;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-2">
                            <div class="col-6">
                                <span class="credit-badge cgst">CGST: <?php echo formatCurrency($total_cgst); ?></span>
                            </div>
                            <div class="col-6">
                                <span class="credit-badge sgst">SGST: <?php echo formatCurrency($total_sgst); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="summary-card">
                        <h6 class="fw-semibold mb-3"><i class="bi bi-calendar me-2"></i>Monthly Summary</h6>
                        <div style="height: 150px;">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- GST Credits Table -->
            <div class="dashboard-card">
                <div class="card-header-custom p-4">
                    <h5><i class="bi bi-table me-2"></i>GST Credit Records</h5>
                    <p>Showing <?php echo $credits ? $credits->num_rows : 0; ?> credits</p>
                </div>

                <!-- Desktop Table View -->
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="creditTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Purchase Reference</th>
                                <th>CGST Credit</th>
                                <th>SGST Credit</th>
                                <th>Total Credit</th>
                                <th>Purchase Total</th>
                                <th>Added On</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($credits && $credits->num_rows > 0): ?>
                                <?php while ($credit = $credits->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="order-id">#<?php echo $credit['id']; ?></span></td>
                                        <td style="white-space: nowrap;">
                                            <?php echo date('d M Y', strtotime($credit['created_at'])); ?>
                                            <div class="text-muted" style="font-size: 10px;"><?php echo date('h:i A', strtotime($credit['created_at'])); ?></div>
                                        </td>
                                        <td>
                                            <?php if ($credit['purchase_id']): ?>
                                                <span class="purchase-ref">
                                                    <i class="bi bi-cart-check"></i>
                                                    <?php echo htmlspecialchars($credit['purchase_no'] ?: 'PO#' . $credit['purchase_id']); ?>
                                                </span>
                                                <?php if ($credit['invoice_num']): ?>
                                                    <div class="text-muted" style="font-size: 10px;">Inv: <?php echo $credit['invoice_num']; ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="credit-badge cgst">
                                                <i class="bi bi-percent"></i>
                                                <?php echo formatCurrency($credit['cgst']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="credit-badge sgst">
                                                <i class="bi bi-percent"></i>
                                                <?php echo formatCurrency($credit['sgst']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="credit-badge total">
                                                <i class="bi bi-piggy-bank"></i>
                                                <?php echo formatCurrency($credit['total_credit']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($credit['purchase_total']): ?>
                                                <?php echo formatCurrency($credit['purchase_total']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color: var(--text-muted); white-space: nowrap;">
                                            <?php echo date('d M Y', strtotime($credit['created_at'])); ?>
                                        </td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <!-- Edit -->
                                                    <button class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 3px 8px;" 
                                                            data-bs-toggle="modal" data-bs-target="#editCreditModal<?php echo $credit['id']; ?>"
                                                            title="Edit GST Credit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    
                                                    <!-- Delete -->
                                                    <form method="POST" action="gst-credit.php" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this GST credit record?')">
                                                        <input type="hidden" name="action" value="delete_credit">
                                                        <input type="hidden" name="credit_id" value="<?php echo $credit['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;"
                                                                title="Delete GST Credit">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Edit Credit Modal -->
                                    <div class="modal fade" id="editCreditModal<?php echo $credit['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="gst-credit.php">
                                                    <input type="hidden" name="action" value="edit_credit">
                                                    <input type="hidden" name="credit_id" value="<?php echo $credit['id']; ?>">
                                                    
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit GST Credit</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Purchase Reference (Optional)</label>
                                                            <select name="purchase_id" class="form-select">
                                                                <option value="">-- No Purchase Reference --</option>
                                                                <?php 
                                                                if ($purchases && $purchases->num_rows > 0) {
                                                                    $purchases->data_seek(0);
                                                                    while ($purchase = $purchases->fetch_assoc()): 
                                                                        $selected = ($credit['purchase_id'] == $purchase['id']) ? 'selected' : '';
                                                                ?>
                                                                    <option value="<?php echo $purchase['id']; ?>" <?php echo $selected; ?>>
                                                                        <?php echo $purchase['purchase_no'] ?: 'PO#' . $purchase['id']; ?> 
                                                                        - ₹<?php echo number_format($purchase['total'], 2); ?>
                                                                    </option>
                                                                <?php 
                                                                    endwhile; 
                                                                } 
                                                                ?>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="row g-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label">CGST Credit (₹)</label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">₹</span>
                                                                    <input type="number" name="cgst" class="form-control" step="0.01" min="0" value="<?php echo $credit['cgst']; ?>" required id="editCGST<?php echo $credit['id']; ?>">
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">SGST Credit (₹)</label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">₹</span>
                                                                    <input type="number" name="sgst" class="form-control" step="0.01" min="0" value="<?php echo $credit['sgst']; ?>" required id="editSGST<?php echo $credit['id']; ?>">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="alert alert-info mt-3 py-2" style="font-size: 12px;">
                                                            <i class="bi bi-info-circle"></i> 
                                                            Total Credit: <strong>₹<span id="editTotal<?php echo $credit['id']; ?>"><?php echo number_format($credit['total_credit'], 2); ?></span></strong>
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

                                    <script>
                                    // Auto-calculate total for edit modal
                                    $(document).ready(function() {
                                        $('#editCGST<?php echo $credit['id']; ?>, #editSGST<?php echo $credit['id']; ?>').on('input', function() {
                                            let cgst = parseFloat($('#editCGST<?php echo $credit['id']; ?>').val()) || 0;
                                            let sgst = parseFloat($('#editSGST<?php echo $credit['id']; ?>').val()) || 0;
                                            $('#editTotal<?php echo $credit['id']; ?>').text((cgst + sgst).toFixed(2));
                                        });
                                    });
                                    </script>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="bi bi-piggy-bank" style="font-size: 24px;"></i>
                                        <p class="mt-2">No GST credit records found</p>
                                        <?php if ($is_admin): ?>
                                            <button class="btn-primary-custom btn-sm" data-bs-toggle="modal" data-bs-target="#addCreditModal">
                                                <i class="bi bi-plus-circle"></i> Add your first GST credit
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if ($credits && $credits->num_rows > 0): ?>
                        <?php 
                        $credits->data_seek(0);
                        while ($credit = $credits->fetch_assoc()): 
                        ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header">
                                    <div>
                                        <span class="order-id">#<?php echo $credit['id']; ?></span>
                                        <span class="customer-name ms-2"><?php echo date('d M Y', strtotime($credit['created_at'])); ?></span>
                                    </div>
                                    <span class="credit-badge total"><?php echo formatCurrency($credit['total_credit']); ?></span>
                                </div>
                                
                                <?php if ($credit['purchase_id']): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Purchase</span>
                                    <span class="mobile-card-value">
                                        <span class="purchase-ref">
                                            <?php echo htmlspecialchars($credit['purchase_no'] ?: 'PO#' . $credit['purchase_id']); ?>
                                        </span>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">CGST Credit</span>
                                    <span class="mobile-card-value">
                                        <span class="credit-badge cgst"><?php echo formatCurrency($credit['cgst']); ?></span>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">SGST Credit</span>
                                    <span class="mobile-card-value">
                                        <span class="credit-badge sgst"><?php echo formatCurrency($credit['sgst']); ?></span>
                                    </span>
                                </div>
                                
                                <?php if ($credit['purchase_total']): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Purchase Total</span>
                                    <span class="mobile-card-value"><?php echo formatCurrency($credit['purchase_total']); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Added On</span>
                                    <span class="mobile-card-value"><?php echo date('d M Y, h:i A', strtotime($credit['created_at'])); ?></span>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                    <div class="mobile-card-actions">
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCreditModal<?php echo $credit['id']; ?>">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
                                        
                                        <form method="POST" action="gst-credit.php" style="display: inline;" 
                                              onsubmit="return confirm('Delete this GST credit record?')">
                                            <input type="hidden" name="action" value="delete_credit">
                                            <input type="hidden" name="credit_id" value="<?php echo $credit['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash me-1"></i>Delete
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-piggy-bank" style="font-size: 48px;"></i>
                            <p class="mt-2">No GST credit records found</p>
                            <?php if ($is_admin): ?>
                                <button class="btn-primary-custom btn-sm" data-bs-toggle="modal" data-bs-target="#addCreditModal">
                                    <i class="bi bi-plus-circle"></i> Add your first GST credit
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Information Note -->
            <div class="alert alert-secondary mt-3" style="background: #f8fafc; border: 1px solid #eef2f6;">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-info-circle-fill text-primary"></i>
                    <div>
                        <strong>About GST Credit:</strong> Input Tax Credit (ITC) can be claimed on GST paid for purchases. 
                        CGST and SGST credits are tracked separately. Total credit is the sum of both.
                    </div>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add GST Credit Modal -->
<div class="modal fade" id="addCreditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="gst-credit.php">
                <input type="hidden" name="action" value="add_credit">
                
                <div class="modal-header">
                    <h5 class="modal-title">Add GST Credit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Purchase Reference (Optional)</label>
                        <select name="purchase_id" class="form-select">
                            <option value="">-- No Purchase Reference --</option>
                            <?php 
                            if ($purchases && $purchases->num_rows > 0) {
                                $purchases->data_seek(0);
                                while ($purchase = $purchases->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $purchase['id']; ?>">
                                    <?php echo $purchase['purchase_no'] ?: 'PO#' . $purchase['id']; ?> 
                                    - ₹<?php echo number_format($purchase['total'], 2); ?>
                                    (CGST: ₹<?php echo number_format($purchase['cgst_amount'], 2); ?>, 
                                    SGST: ₹<?php echo number_format($purchase['sgst_amount'], 2); ?>)
                                </option>
                            <?php 
                                endwhile; 
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">CGST Credit (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="cgst" class="form-control" step="0.01" min="0" value="0" required id="addCGST">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SGST Credit (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="sgst" class="form-control" step="0.01" min="0" value="0" required id="addSGST">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3 mt-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Enter any additional notes..."></textarea>
                    </div>
                    
                    <div class="alert alert-info py-2" style="font-size: 12px;">
                        <i class="bi bi-info-circle"></i> 
                        Total Credit: <strong>₹<span id="totalCredit">0.00</span></strong>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add GST Credit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
$(document).ready(function() {
    $('#creditTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc']],
        language: {
            search: "Search credits:",
            lengthMenu: "Show _MENU_ credits",
            info: "Showing _START_ to _END_ of _TOTAL_ credits",
            emptyTable: "No GST credits available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: -1 }
            <?php endif; ?>
        ]
    });

    // Auto-calculate total for add modal
    $('#addCGST, #addSGST').on('input', function() {
        let cgst = parseFloat($('#addCGST').val()) || 0;
        let sgst = parseFloat($('#addSGST').val()) || 0;
        $('#totalCredit').text((cgst + sgst).toFixed(2));
    });

    // Monthly chart
    const ctx = document.getElementById('monthlyChart')?.getContext('2d');
    if (ctx) {
        const months = [];
        const amounts = [];
        
        <?php 
        if ($monthly_summary && $monthly_summary->num_rows > 0) {
            $monthly_summary->data_seek(0);
            while ($month = $monthly_summary->fetch_assoc()): 
        ?>
        months.push('<?php echo date('M Y', strtotime($month['month'] . '-01')); ?>');
        amounts.push(<?php echo $month['monthly_total']; ?>);
        <?php 
            endwhile; 
        } 
        ?>
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: months.reverse(),
                datasets: [{
                    label: 'Monthly GST Credit',
                    data: amounts.reverse(),
                    backgroundColor: '#8b5cf6',
                    borderRadius: 4
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
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString();
                            }
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
    let rows = document.querySelectorAll('#creditTable tr');
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            // Skip actions column for export
            if (j < cols.length - 1) {
                let text = cols[j].innerText.replace(/\s+/g, ' ').trim();
                row.push('"' + text + '"');
            }
        }
        if (row.length > 0) {
            csv.push(row.join(','));
        }
    }
    
    let csvFile = new Blob([csv.join('\n')], {type: 'text/csv'});
    let downloadLink = document.createElement('a');
    downloadLink.download = 'gst_credit_' + new Date().toISOString().slice(0,10) + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

</body>
</html>