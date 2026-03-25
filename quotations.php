<?php
// quotations.php
session_start();
$currentPage = 'quotations';
$pageTitle   = 'Quotations';
require_once 'includes/db.php';
require_once 'auth_check.php';

checkRoleAccess(['admin', 'sale']);
header_remove("X-Powered-By");

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Handle status update
if (isset($_GET['action']) && $_GET['action'] === 'update_status' && isset($_GET['id']) && isset($_GET['status'])) {
    $id = (int)$_GET['id'];
    $status = mysqli_real_escape_string($conn, $_GET['status']);
    
    $update = mysqli_query($conn, "UPDATE quotation SET status = '$status' WHERE id = $id");
    
    if ($update) {
        $success = "Quotation status updated successfully.";
    } else {
        $error = "Failed to update status: " . mysqli_error($conn);
    }
}

// Handle delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $quote_res = mysqli_query($conn, "SELECT quote_num FROM quotation WHERE id = $id");
    $quote = mysqli_fetch_assoc($quote_res);
    
    $delete = mysqli_query($conn, "DELETE FROM quotation WHERE id = $id");
    
    if ($delete) {
        mysqli_query($conn, "INSERT INTO activity_log (user_id, action, description) VALUES ({$_SESSION['user_id']}, 'delete', 'Deleted quotation: {$quote['quote_num']}')");
        $success = "Quotation deleted successfully.";
    } else {
        $error = "Failed to delete quotation: " . mysqli_error($conn);
    }
}

// Handle convert to invoice
if (isset($_GET['action']) && $_GET['action'] === 'convert' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Get quotation data
    $quote_res = mysqli_query($conn, "SELECT * FROM quotation WHERE id = $id");
    $quote = mysqli_fetch_assoc($quote_res);
    
    if ($quote) {
        // Generate invoice number
        $is_gst = $quote['is_gst'];
        $prefix = $is_gst ? 'SP' : 'E';
        
        $counter_res = mysqli_query($conn, "SELECT id, counter_value FROM invoice_counter WHERE prefix = '$prefix' LIMIT 1 FOR UPDATE");
        $counter_row = mysqli_fetch_assoc($counter_res);
        
        if (!$counter_row) {
            mysqli_query($conn, "INSERT INTO invoice_counter (prefix, counter_value) VALUES ('$prefix', 1)");
            $counterId = mysqli_insert_id($conn);
            $counterValue = 1;
        } else {
            $counterId = $counter_row['id'];
            $counterValue = $counter_row['counter_value'];
        }
        
        $inv_num = $prefix . str_pad($counterValue, 5, "0", STR_PAD_LEFT);
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert invoice
            $insert_invoice = mysqli_query($conn, "
                INSERT INTO invoice
                (
                    inv_num, customer_id, customer_name, subtotal, overall_discount, overall_discount_type, total,
                    taxable, cgst, cgst_amount, sgst, sgst_amount, cash_received, change_give, pending_amount, payment_method,
                    is_gst
                )
                VALUES
                (
                    '$inv_num', {$quote['customer_id']}, '{$quote['customer_name']}', {$quote['subtotal']}, {$quote['overall_discount']}, '{$quote['overall_discount_type']}', {$quote['total']},
                    {$quote['taxable']}, 0, {$quote['cgst_amount']}, 0, {$quote['sgst_amount']}, 0, 0, {$quote['total']}, 'credit',
                    {$quote['is_gst']}
                )
            ");
            
            if (!$insert_invoice) {
                throw new Exception("Failed to create invoice: " . mysqli_error($conn));
            }
            
            $invoice_id = mysqli_insert_id($conn);
            
            // Get quotation items
            $items_res = mysqli_query($conn, "SELECT * FROM quotation_item WHERE quotation_id = $id");
            
            while ($item = mysqli_fetch_assoc($items_res)) {
                $insert_item = mysqli_query($conn, "
                    INSERT INTO invoice_item
                    (
                        invoice_id, product_id, product_name, cat_id, cat_name, quantity, unit,
                        purchase_price, selling_price, discount, discount_type, total, hsn,
                        taxable, cgst, cgst_amount, sgst, sgst_amount
                    )
                    VALUES
                    (
                        $invoice_id, {$item['product_id']}, '{$item['product_name']}', {$item['cat_id']}, '{$item['cat_name']}', {$item['quantity']}, '{$item['unit']}',
                        0, {$item['selling_price']}, 0, 'amount', {$item['total']}, '{$item['hsn']}',
                        {$item['taxable']}, {$item['cgst']}, {$item['cgst_amount']}, {$item['sgst']}, {$item['sgst_amount']}
                    )
                ");
                
                if (!$insert_item) {
                    throw new Exception("Failed to create invoice item: " . mysqli_error($conn));
                }
                
                // Update stock
                $update_stock = mysqli_query($conn, "UPDATE category SET total_quantity = total_quantity - {$item['converted_qty']} WHERE id = {$item['cat_id']}");
                
                if (!$update_stock) {
                    throw new Exception("Failed to update stock: " . mysqli_error($conn));
                }
            }
            
            // Update quotation status
            mysqli_query($conn, "UPDATE quotation SET status = 'converted' WHERE id = $id");
            
            // Update invoice counter
            $next = $counterValue + 1;
            mysqli_query($conn, "UPDATE invoice_counter SET counter_value = $next WHERE prefix = '$prefix'");
            
            // Log activity
            mysqli_query($conn, "INSERT INTO activity_log (user_id, action, description) VALUES ({$_SESSION['user_id']}, 'convert', 'Converted quotation {$quote['quote_num']} to invoice $inv_num')");
            
            mysqli_commit($conn);
            
            header("Location: print_invoice.php?id=" . $invoice_id);
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// Get all quotations
$quotations = mysqli_query($conn, "
    SELECT q.*, 
           (SELECT COUNT(*) FROM quotation_item WHERE quotation_id = q.id) as item_count,
           u.name as created_by_name
    FROM quotation q
    LEFT JOIN users u ON q.created_by = u.id
    ORDER BY q.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background:#f0f4f8; color:#1e293b; line-height:1.4;
            font-size: 12px;
        }
        .full-screen { min-height:100vh; width:100%; padding:14px 18px; background:#f0f4f8; }
        .page-header { margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
        .page-header h1 { font-size:20px; font-weight:700; color:#0f172a; margin-bottom:2px; }
        .page-header p { font-size:12px; color:#475569; margin:0; }
        .nav-buttons { display:flex; gap:8px; }
        .btn-nav {
            padding:6px 12px; border-radius:20px; font-weight:600; font-size:12px;
            display:inline-flex; align-items:center; gap:6px; transition:all .2s; text-decoration:none;
        }
        .btn-nav-back { background:white; color:#475569; border:1px solid #e2e8f0; }
        .btn-nav-new { background:#8b5cf6; color:white; border:1px solid #7c3aed; }

        .card-custom {
            background:white; border-radius:12px; box-shadow:0 6px 20px rgba(0,0,0,.04);
            padding:12px; margin-bottom:12px; border:1px solid #e9eef2;
        }
        .card-header-custom {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid #eef2f6;
        }
        .card-header-custom h5 { font-size:13px; font-weight:700; margin:0; color:#0f172a; }
        
        .badge-status {
            padding:4px 8px; border-radius:30px; font-size:11px; font-weight:600;
        }
        .badge-draft { background:#f1f5f9; color:#475569; }
        .badge-sent { background:#dbeafe; color:#2563eb; }
        .badge-accepted { background:#d1fae5; color:#065f46; }
        .badge-expired { background:#fee2e2; color:#991b1b; }
        .badge-converted { background:#f3e8ff; color:#8b5cf6; }
        
        .table { border-radius:10px; overflow:hidden; border:1px solid #e2e8f0; margin-bottom:0; }
        .table thead th {
            background:#f8fafc; font-weight:700; font-size:11px; color:#475569; padding:8px 6px; border-bottom:1px solid #e2e8f0;
        }
        .table tbody td { padding:8px 6px; font-size:12px; border-bottom:1px solid #eef2f6; vertical-align:middle; }
        
        .btn-action {
            padding:4px 8px; border-radius:6px; font-size:11px; font-weight:600;
            display:inline-flex; align-items:center; gap:4px; text-decoration:none;
        }
        .btn-view { background:#f1f5f9; color:#475569; }
        .btn-print { background:#f1f5f9; color:#475569; }
        .btn-convert { background:#dbeafe; color:#2563eb; }
        .btn-delete { background:#fee2e2; color:#991b1b; }
        
        .alert { border-radius:10px; padding:10px 12px; border:none; font-size:12px; margin-bottom:12px; }
        .alert-success { background:#d1fae5; color:#065f46; }
        .alert-danger { background:#fee2e2; color:#991b1b; }
        
        .action-group { display:flex; gap:4px; flex-wrap:wrap; }
    </style>
</head>
<body>
<div class="full-screen">

    <div class="page-header">
        <div>
            <h1>Quotations</h1>
            <p>Manage all quotations • Convert to invoice</p>
        </div>
        <div class="nav-buttons">
            <a href="new-sale.php" class="btn-nav btn-nav-new"><i class="bi bi-plus-circle"></i> New Quotation</a>
            <a href="invoices.php" class="btn-nav btn-nav-back"><i class="bi bi-arrow-left"></i> Back to Invoices</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="card-custom">
        <div class="card-header-custom">
            <h5><i class="bi bi-file-text me-1"></i>All Quotations</h5>
            <span class="badge-custom">Total: <?php echo mysqli_num_rows($quotations); ?></span>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Quote #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th class="text-end">Total</th>
                        <th>Valid Until</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($quotations) > 0): ?>
                        <?php while ($quote = mysqli_fetch_assoc($quotations)): 
                            $status_class = '';
                            switch($quote['status']) {
                                case 'draft': $status_class = 'badge-draft'; break;
                                case 'sent': $status_class = 'badge-sent'; break;
                                case 'accepted': $status_class = 'badge-accepted'; break;
                                case 'expired': $status_class = 'badge-expired'; break;
                                case 'converted': $status_class = 'badge-converted'; break;
                                default: $status_class = 'badge-draft';
                            }
                            
                            $valid_until = $quote['valid_until'] ? date('d-m-Y', strtotime($quote['valid_until'])) : '-';
                            $is_expired = $quote['valid_until'] && strtotime($quote['valid_until']) < time() && $quote['status'] != 'converted';
                            if ($is_expired && $quote['status'] != 'expired') {
                                // Auto-mark as expired
                                mysqli_query($conn, "UPDATE quotation SET status = 'expired' WHERE id = {$quote['id']}");
                                $quote['status'] = 'expired';
                                $status_class = 'badge-expired';
                            }
                        ?>
                        <tr>
                            <td><span class="mono fw-bold"><?php echo htmlspecialchars($quote['quote_num']); ?></span></td>
                            <td><?php echo date('d-m-Y', strtotime($quote['created_at'])); ?></td>
                            <td>
                                <?php echo htmlspecialchars($quote['customer_name']); ?>
                                <?php if ($quote['customer_id']): ?>
                                    <br><span class="tiny text-muted">ID: <?php echo $quote['customer_id']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?php echo $quote['item_count']; ?></td>
                            <td class="text-end fw-bold">₹<?php echo number_format($quote['total'], 2); ?></td>
                            <td>
                                <?php echo $valid_until; ?>
                                <?php if ($is_expired): ?>
                                    <span class="badge-status badge-expired ms-1">Expired</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select class="form-select form-select-sm" style="width:100px;" onchange="updateStatus(<?php echo $quote['id']; ?>, this.value)">
                                    <option value="draft" <?php echo $quote['status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="sent" <?php echo $quote['status'] == 'sent' ? 'selected' : ''; ?>>Sent</option>
                                    <option value="accepted" <?php echo $quote['status'] == 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                    <option value="expired" <?php echo $quote['status'] == 'expired' ? 'selected' : ''; ?>>Expired</option>
                                    <option value="converted" <?php echo $quote['status'] == 'converted' ? 'selected' : ''; ?> disabled>Converted</option>
                                </select>
                            </td>
                            <td><?php echo htmlspecialchars($quote['created_by_name'] ?? 'System'); ?></td>
                            <td>
                                <div class="action-group">
                                    <a href="view_quotation.php?id=<?php echo $quote['id']; ?>" class="btn-action btn-view" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="print_quotation.php?id=<?php echo $quote['id']; ?>" class="btn-action btn-print" title="Print" target="_blank">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <?php if ($quote['status'] != 'converted'): ?>
                                        <a href="quotations.php?action=convert&id=<?php echo $quote['id']; ?>" 
                                           class="btn-action btn-convert" 
                                           title="Convert to Invoice"
                                           onclick="return confirm('Convert this quotation to invoice? Stock will be deducted.')">
                                            <i class="bi bi-arrow-right-circle"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="quotations.php?action=delete&id=<?php echo $quote['id']; ?>" 
                                       class="btn-action btn-delete" 
                                       title="Delete"
                                       onclick="return confirm('Are you sure you want to delete this quotation?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="bi bi-file-text" style="font-size:24px;"></i><br>
                                No quotations found. <a href="new-sale.php" class="text-decoration-none">Create your first quotation</a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
function updateStatus(id, status) {
    if (confirm('Update quotation status to ' + status + '?')) {
        window.location.href = 'quotations.php?action=update_status&id=' + id + '&status=' + status;
    }
}
</script>
</body>
</html>