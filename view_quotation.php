<?php
// view_quotation.php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

checkRoleAccess(['admin', 'sale']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: quotations.php');
    exit;
}

// Get quotation details
$quote_res = mysqli_query($conn, "
    SELECT q.*, c.phone, c.email, c.address, c.gst_number, u.name as created_by_name
    FROM quotation q
    LEFT JOIN customers c ON q.customer_id = c.id
    LEFT JOIN users u ON q.created_by = u.id
    WHERE q.id = $id
");
$quote = mysqli_fetch_assoc($quote_res);

if (!$quote) {
    header('Location: quotations.php');
    exit;
}

// Get quotation items
$items_res = mysqli_query($conn, "
    SELECT qi.*, p.hsn_code 
    FROM quotation_item qi
    LEFT JOIN product p ON qi.product_id = p.id
    WHERE qi.quotation_id = $id
    ORDER BY qi.id ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Quotation <?php echo $quote['quote_num']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f4f8;
            padding: 20px;
        }
        .view-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            padding: 30px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-draft { background: #f1f5f9; color: #475569; }
        .status-sent { background: #dbeafe; color: #2563eb; }
        .status-accepted { background: #d1fae5; color: #065f46; }
        .status-expired { background: #fee2e2; color: #991b1b; }
        .status-converted { background: #f3e8ff; color: #8b5cf6; }
        .action-bar {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn-action {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-back { background: #f1f5f9; color: #475569; }
        .btn-print { background: #2563eb; color: white; }
        .btn-convert { background: #8b5cf6; color: white; }
        .btn-delete { background: #fee2e2; color: #991b1b; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 12px;
        }
        .info-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }
    </style>
</head>
<body>
    <div class="view-container">
        <div class="action-bar">
            <a href="quotations.php" class="btn-action btn-back">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="print_quotation.php?id=<?php echo $id; ?>" class="btn-action btn-print" target="_blank">
                <i class="bi bi-printer"></i> Print
            </a>
            <?php if ($quote['status'] != 'converted'): ?>
            <a href="quotations.php?action=convert&id=<?php echo $id; ?>" class="btn-action btn-convert" onclick="return confirm('Convert to invoice?')">
                <i class="bi bi-arrow-right-circle"></i> Convert to Invoice
            </a>
            <?php endif; ?>
            <a href="quotations.php?action=delete&id=<?php echo $id; ?>" class="btn-action btn-delete" onclick="return confirm('Delete this quotation?')">
                <i class="bi bi-trash"></i> Delete
            </a>
        </div>

        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="fw-bold">Quotation #<?php echo $quote['quote_num']; ?></h2>
                <p class="text-muted">Created: <?php echo date('d-m-Y h:i A', strtotime($quote['created_at'])); ?></p>
            </div>
            <div>
                <span class="status-badge status-<?php echo $quote['status']; ?>">
                    <?php echo strtoupper($quote['status']); ?>
                </span>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-card">
                <div class="info-label">Customer Name</div>
                <div class="info-value"><?php echo htmlspecialchars($quote['customer_name']); ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Phone</div>
                <div class="info-value"><?php echo htmlspecialchars($quote['phone'] ?? '-'); ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Email</div>
                <div class="info-value"><?php echo htmlspecialchars($quote['email'] ?? '-'); ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">GST Number</div>
                <div class="info-value"><?php echo htmlspecialchars($quote['gst_number'] ?? '-'); ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Valid Until</div>
                <div class="info-value"><?php echo $quote['valid_until'] ? date('d-m-Y', strtotime($quote['valid_until'])) : '-'; ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Created By</div>
                <div class="info-value"><?php echo htmlspecialchars($quote['created_by_name'] ?? 'System'); ?></div>
            </div>
        </div>

        <?php if ($quote['address']): ?>
        <div class="info-card mb-4">
            <div class="info-label">Address</div>
            <div class="info-value"><?php echo nl2br(htmlspecialchars($quote['address'])); ?></div>
        </div>
        <?php endif; ?>

        <h5 class="fw-bold mb-3">Items</h5>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>HSN</th>
                        <th class="text-end">Qty</th>
                        <th>Unit</th>
                        <th class="text-end">Pcs/Bag</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Taxable</th>
                        <th class="text-end">CGST</th>
                        <th class="text-end">SGST</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sno = 1;
                    while ($item = mysqli_fetch_assoc($items_res)): 
                    ?>
                    <tr>
                        <td><?php echo $sno++; ?></td>
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['cat_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['hsn']); ?></td>
                        <td class="text-end"><?php echo number_format($item['quantity'], 3); ?></td>
                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                        <td class="text-end"><?php echo number_format($item['pcs_per_bag'], 3); ?></td>
                        <td class="text-end">₹<?php echo number_format($item['selling_price'], 2); ?></td>
                        <td class="text-end">₹<?php echo number_format($item['taxable'], 2); ?></td>
                        <td class="text-end"><?php echo $item['cgst']; ?>% (₹<?php echo number_format($item['cgst_amount'], 2); ?>)</td>
                        <td class="text-end"><?php echo $item['sgst']; ?>% (₹<?php echo number_format($item['sgst_amount'], 2); ?>)</td>
                        <td class="text-end fw-bold">₹<?php echo number_format($item['total'], 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <th colspan="8" class="text-end">Subtotal:</th>
                        <th class="text-end">₹<?php echo number_format($quote['subtotal'], 2); ?></th>
                        <th class="text-end">₹<?php echo number_format($quote['cgst_amount'], 2); ?></th>
                        <th class="text-end">₹<?php echo number_format($quote['sgst_amount'], 2); ?></th>
                        <th class="text-end">₹<?php echo number_format($quote['total'], 2); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($quote['notes']): ?>
        <div class="mt-4 p-3 bg-light rounded">
            <strong>Notes:</strong><br>
            <?php echo nl2br(htmlspecialchars($quote['notes'])); ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>