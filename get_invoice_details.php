<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view invoice details
checkRoleAccess(['admin', 'sale']);

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoice_id) {
    echo '<div class="alert alert-danger">Invalid invoice ID</div>';
    exit;
}

// Get invoice details
$sql = "SELECT i.*, c.customer_name, c.phone, c.email, c.address, c.gst_number
        FROM invoice i 
        LEFT JOIN customers c ON i.customer_id = c.id 
        WHERE i.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div class="alert alert-warning">Invoice not found</div>';
    exit;
}

$invoice = $result->fetch_assoc();

// Get invoice items
$items_sql = "SELECT ii.*, p.product_name, c.category_name
              FROM invoice_item ii
              LEFT JOIN product p ON ii.product_id = p.id
              LEFT JOIN category c ON ii.cat_id = c.id
              WHERE ii.invoice_id = ?
              ORDER BY ii.id ASC";

$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("i", $invoice_id);
$items_stmt->execute();
$items = $items_stmt->get_result();

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}
?>

<div class="container-fluid">
    <!-- Invoice Header -->
    <div class="row mb-3">
        <div class="col-6">
            <h5>Invoice #<?php echo htmlspecialchars($invoice['inv_num']); ?></h5>
            <p class="text-muted mb-1">Date: <?php echo date('d M Y, h:i A', strtotime($invoice['created_at'])); ?></p>
            <p class="text-muted">Payment: <?php echo ucfirst($invoice['payment_method']); ?></p>
        </div>
        <div class="col-6 text-end">
            <h3 class="<?php echo $invoice['pending_amount'] > 0 ? 'text-danger' : 'text-success'; ?>">
                <?php echo formatCurrency($invoice['total']); ?>
            </h3>
            <?php if ($invoice['pending_amount'] > 0): ?>
                <span class="badge bg-warning">Pending: <?php echo formatCurrency($invoice['pending_amount']); ?></span>
            <?php else: ?>
                <span class="badge bg-success">Paid</span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Customer Info -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="card bg-light">
                <div class="card-body">
                    <h6 class="card-title">Customer Details</h6>
                    <p class="mb-1"><strong><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></strong></p>
                    <?php if (!empty($invoice['phone'])): ?>
                        <p class="mb-1"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($invoice['phone']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['email'])): ?>
                        <p class="mb-1"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($invoice['email']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['address'])): ?>
                        <p class="mb-1"><i class="bi bi-geo-alt"></i> <?php echo nl2br(htmlspecialchars($invoice['address'])); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['gst_number'])): ?>
                        <p class="mb-1"><i class="bi bi-file-text"></i> GST: <?php echo htmlspecialchars($invoice['gst_number']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Items Table -->
    <div class="row">
        <div class="col-12">
            <h6>Items</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Item</th>
                            <th>Category</th>
                            <th>Qty</th>
                            <th>Price</th>
                            <th>Taxable</th>
                            <th>CGST</th>
                            <th>SGST</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sn = 1;
                        while ($item = $items->fetch_assoc()): 
                        ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><?php echo htmlspecialchars($item['product_name'] ?: $item['cat_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['cat_name'] ?: '-'); ?></td>
                                <td><?php echo number_format($item['quantity'], 3); ?></td>
                                <td><?php echo formatCurrency($item['selling_price']); ?></td>
                                <td><?php echo formatCurrency($item['taxable']); ?></td>
                                <td><?php echo formatCurrency($item['cgst_amount']); ?></td>
                                <td><?php echo formatCurrency($item['sgst_amount']); ?></td>
                                <td><?php echo formatCurrency($item['total']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="8" class="text-end">Subtotal:</th>
                            <th><?php echo formatCurrency($invoice['subtotal']); ?></th>
                        </tr>
                        <?php if ($invoice['overall_discount'] > 0): ?>
                            <tr>
                                <th colspan="8" class="text-end">Discount:</th>
                                <th>- <?php echo formatCurrency($invoice['overall_discount']); ?></th>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <th colspan="8" class="text-end">CGST:</th>
                            <th><?php echo formatCurrency($invoice['cgst_amount']); ?></th>
                        </tr>
                        <tr>
                            <th colspan="8" class="text-end">SGST:</th>
                            <th><?php echo formatCurrency($invoice['sgst_amount']); ?></th>
                        </tr>
                        <tr>
                            <th colspan="8" class="text-end">Grand Total:</th>
                            <th><?php echo formatCurrency($invoice['total']); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Payment Details -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h6>Payment Summary</h6>
                    <div class="row">
                        <div class="col-4">
                            <small class="text-muted">Total Amount</small>
                            <p class="fw-bold"><?php echo formatCurrency($invoice['total']); ?></p>
                        </div>
                        <div class="col-4">
                            <small class="text-muted">Cash Received</small>
                            <p class="fw-bold"><?php echo formatCurrency($invoice['cash_received']); ?></p>
                        </div>
                        <div class="col-4">
                            <small class="text-muted">Pending Amount</small>
                            <p class="fw-bold <?php echo $invoice['pending_amount'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo formatCurrency($invoice['pending_amount']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>