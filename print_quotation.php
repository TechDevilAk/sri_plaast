<?php
// print_quotation.php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

checkRoleAccess(['admin', 'sale']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Invalid quotation ID");
}

// Get quotation details
$stmt = $conn->prepare("
    SELECT q.*, 
           u.name as created_by_name
    FROM quotation q
    LEFT JOIN users u ON q.created_by = u.id
    WHERE q.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$quotation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quotation) {
    die("Quotation not found");
}

// Get quotation items
$stmt = $conn->prepare("
    SELECT * FROM quotation_item 
    WHERE quotation_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$items = $stmt->get_result();
$stmt->close();

// Get company settings
$stmt = $conn->query("SELECT * FROM invoice_setting LIMIT 1");
$company = $stmt->fetch_assoc();

$is_gst = isset($quotation['is_gst']) ? $quotation['is_gst'] : false;

// Format numbers function
function formatMoney($amount) {
    return number_format((float)$amount, 2, '.', '');
}

// Helper function to safely get array values
function safeValue($array, $key, $default = '') {
    return isset($array[$key]) && $array[$key] !== null ? $array[$key] : $default;
}

// Calculate total CGST and SGST from items
function calculateGSTTotals($items) {
    $total_cgst = 0;
    $total_sgst = 0;
    $cgst_rate = 0;
    $sgst_rate = 0;
    
    if ($items && $items->num_rows > 0) {
        $items->data_seek(0);
        while($item = $items->fetch_assoc()) {
            $total_cgst += (float)safeValue($item, 'cgst_amount', 0);
            $total_sgst += (float)safeValue($item, 'sgst_amount', 0);
            
            // Get rates from first item (assuming same rates across items)
            if ($cgst_rate == 0) {
                $cgst_rate = (float)safeValue($item, 'cgst', 0);
                $sgst_rate = (float)safeValue($item, 'sgst', 0);
            }
        }
        $items->data_seek(0); // Reset pointer
    }
    
    return [
        'cgst' => $total_cgst,
        'sgst' => $total_sgst,
        'cgst_rate' => $cgst_rate,
        'sgst_rate' => $sgst_rate
    ];
}

$gst_totals = calculateGSTTotals($items);

// Get current time
$current_time = date('d-m-Y h:i:s A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Quotation #<?php echo htmlspecialchars(safeValue($quotation, 'quote_num', 'N/A')); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
/* ======= 80mm THERMAL RECEIPT STYLE WITH HELVETICA ======= */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    max-width: 100%;
}

body {
    background: #f0f0f0;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    font-family: 'Helvetica', 'Helvetica Neue', Arial, sans-serif;
    padding: 0;
    margin: 0;
}

#receipt {
    width: 74mm;
    max-width: 74mm;
    font-family: 'Helvetica', 'Helvetica Neue', Arial, sans-serif;
    font-size: 13px;
    line-height: 1.2;
    padding: 1mm 3mm 1mm 2mm; /* Reduced top padding */
    color: #000;
    font-weight: 500;
    margin: 0 auto;
    background: white;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border-radius: 2mm;
}

/* Remove top margin spacer completely */
.receipt-top-margin {
    display: none;
    height: 0;
}

/* Typography */
.center { text-align: center; }
.right { text-align: right; }
.bold { font-weight: 700 !important; }
.extra-bold { font-weight: 800 !important; }

.h1 { 
    font-size: 22px !important; 
    font-weight: 800 !important; 
    text-transform: uppercase; 
    letter-spacing: 0.5px; 
    margin: 2px 0; /* Reduced margin */
    font-family: 'Helvetica', 'Helvetica Neue', Arial, sans-serif;
}

.h2 { 
    font-size: 16px !important; 
    font-weight: 700 !important; 
    margin: 3px 0 2px 0; /* Reduced margin */
    font-family: 'Helvetica', 'Helvetica Neue', Arial, sans-serif;
}

/* Lines and separators */
.line { 
    border-top: 1px dashed #000; 
    margin: 3px 0; /* Reduced margin */
}

.line2 { 
    border-top: 2px solid #000; 
    margin: 3px 0; /* Reduced margin */
}

.dotted-line {
    border-top: 1px dotted #000;
    margin: 2px 0; /* Reduced margin */
}

/* Company Header */
.company-name {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 0.5px;
    margin: 2px 0; /* Reduced margin */
}

.company-address {
    font-size: 11px;
    line-height: 1.2;
    color: #333;
    margin: 1px 0;
}

.company-details {
    font-size: 10px;
    margin: 1px 0;
}

/* Document Info */
.doc-info {
    background: #f5f5f5;
    padding: 4px 4px; /* Reduced padding */
    border-radius: 3px;
    margin: 3px 0; /* Reduced margin */
}

.doc-info-row {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    margin: 1px 0; /* Reduced margin */
}

.doc-label {
    font-weight: 600;
    color: #555;
}

.doc-value {
    font-weight: 700;
}

/* Valid Badge */
.valid-badge {
    background: #000;
    color: white;
    padding: 2px 6px; /* Reduced padding */
    border-radius: 3px;
    font-size: 10px; /* Smaller font */
    font-weight: 700;
    display: inline-block;
    letter-spacing: 0.3px;
    margin-top: 2px;
}

/* Customer Section */
.customer-section {
    margin: 3px 0 2px 0; /* Reduced margin */
}

.customer-row {
    display: flex;
    margin: 2px 0; /* Reduced margin */
    font-size: 11px;
}

.customer-label {
    font-weight: 600;
    min-width: 50px;
    color: #555;
}

.customer-value {
    font-weight: 600;
}

/* Items Table */
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10px;
    margin: 3px 0; /* Reduced margin */
}

.table th {
    font-weight: 700;
    text-align: center;
    padding: 2px 1px; /* Reduced padding */
    border-bottom: 1px solid #000;
    border-top: 1px solid #000;
    font-size: 10px;
    background: #f0f0f0;
}

.table td {
    padding: 2px 1px; /* Reduced padding */
    border-bottom: 1px dotted #999;
    vertical-align: top;
}

.table tfoot td {
    border-bottom: none;
    border-top: 1px solid #000;
    padding: 2px 1px; /* Reduced padding */
    font-weight: 700;
}

/* Column widths */
.col-sno { width: 8%; text-align: center; }
.col-item { width: 32%; text-align: left; }
.col-gst { width: 12%; text-align: center; }
.col-qty { width: 10%; text-align: center; }
.col-unit { width: 8%; text-align: center; }
.col-rate { width: 12%; text-align: right; }
.col-amount { width: 18%; text-align: right; }

.product-name {
    font-weight: 600;
    font-size: 10px;
}

.product-category {
    font-size: 8px;
    color: #555;
    margin-top: 1px;
}

.gst-badge {
    background: #f0f0f0;
    padding: 1px 2px; /* Reduced padding */
    border-radius: 2px;
    font-size: 8px;
    font-weight: 600;
    display: inline-block;
}

/* Summary Section */
.summary-box {
    margin: 4px 0; /* Reduced margin */
    padding: 4px; /* Reduced padding */
    background: #f9f9f9;
    border-radius: 3px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    padding: 2px 0; /* Reduced padding */
    border-bottom: 1px dotted #ccc;
}

.summary-row:last-child {
    border-bottom: none;
}

.summary-row.total {
    font-size: 14px;
    font-weight: 800;
    border-top: 2px solid #000;
    margin-top: 3px; /* Reduced margin */
    padding-top: 3px; /* Reduced padding */
}

.gst-summary {
    background: #f0f0f0;
    padding: 3px; /* Reduced padding */
    margin: 3px 0; /* Reduced margin */
    border-left: 3px solid #000;
}

.gst-row {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    padding: 1px 0; /* Reduced padding */
}

.gst-percent {
    font-weight: 700;
    color: #000;
}

/* Amount in Words */
.words-box {
    margin: 4px 0; /* Reduced margin */
    padding: 4px; /* Reduced padding */
    border: 1px dashed #000;
    font-size: 10px;
}

.words-label {
    font-weight: 700;
    margin-bottom: 2px; /* Reduced margin */
    text-transform: uppercase;
    font-size: 9px;
}

.words-value {
    font-weight: 600;
    font-style: italic;
    line-height: 1.2;
}

/* Notes Section */
.notes-box {
    margin: 4px 0; /* Reduced margin */
    padding: 4px; /* Reduced padding */
    background: #fff9e6;
    border: 1px solid #ffd700;
    font-size: 9px;
}

.notes-title {
    font-weight: 700;
    margin-bottom: 2px; /* Reduced margin */
    color: #b8860b;
}

/* Footer */
.footer {
    text-align: center;
    margin-top: 4px; /* Reduced margin */
    padding-top: 3px; /* Reduced padding */
    border-top: 2px solid #000;
    font-size: 9px;
}

.footer-text {
    margin: 1px 0; /* Reduced margin */
}

.footer-bold {
    font-weight: 700;
    font-size: 10px;
    margin: 2px 0; /* Reduced margin */
}

/* Time Stamp */
.time-stamp {
    font-size: 8px;
    color: #666;
    margin-top: 1px;
    font-family: 'Helvetica', 'Helvetica Neue', Arial, sans-serif;
}

.print-time {
    border-top: 1px dotted #999;
    margin-top: 3px;
    padding-top: 2px;
    font-size: 8px;
    color: #555;
    display: flex;
    justify-content: space-between;
}

/* Action Buttons */
.action-buttons {
    position: fixed;
    top: 10px;
    right: 10px;
    display: flex;
    gap: 8px;
    z-index: 1000;
}

.btn {
    padding: 10px 18px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    font-family: 'Helvetica', 'Helvetica Neue', Arial, sans-serif;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    transition: all 0.2s;
}

.btn-print {
    background: #2563eb;
    color: white;
}

.btn-print:hover {
    background: #1d4ed8;
    transform: translateY(-2px);
}

.btn-back {
    background: #64748b;
    color: white;
}

.btn-back:hover {
    background: #475569;
    transform: translateY(-2px);
}

/* PRINT STYLES - Optimized for no empty space */
@media print {
    @page {
        margin: 1mm 2mm 1mm 1mm !important; /* Minimal margins */
        size: 80mm auto;
    }
    
    body {
        background: white;
        padding: 0 !important;
        margin: 0 !important;
        width: 80mm !important;
        min-height: 0 !important;
        display: block;
    }
    
    #receipt {
        box-shadow: none;
        padding: 0.5mm 2mm 0.5mm 1mm !important; /* Minimal padding */
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        border-radius: 0;
    }
    
    .action-buttons {
        display: none !important;
    }
    
    .btn {
        display: none !important;
    }
    
    /* Remove all top margins and padding */
    #receipt > *:first-child {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }
    
    .receipt-top-margin {
        display: none !important;
        height: 0 !important;
    }
    
    /* Ensure no extra space at top */
    .center:first-child, 
    .company-name:first-child {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }
    
    /* Keep background colors for print */
    .summary-box, .gst-summary, .notes-box, .doc-info {
        background: none !important;
        border: 1px solid #000 !important;
    }
    
    .valid-badge {
        background: #000 !important;
        color: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .time-stamp, .print-time {
        color: #000 !important;
    }
    
    /* Ensure table headers print with background */
    .table th {
        background: #f0f0f0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}

/* Responsive */
@media (max-width: 80mm) {
    #receipt {
        width: 100%;
        max-width: 100%;
        padding: 1mm;
    }
}
</style>
</head>
<body>

<!-- Action Buttons -->
<div class="action-buttons">
    <button class="btn btn-back" onclick="goBack()">← Back</button>
    <button class="btn btn-print" onclick="window.print()">🖨️ Print</button>
</div>

<div id="receipt">
    <!-- No top margin spacer - completely removed -->
    
    <!-- Company Header -->
    <div class="center">
        <div class="company-name extra-bold">
            <?php echo htmlspecialchars(safeValue($company, 'company_name', 'Company Name')); ?>
        </div>
        <div class="company-address">
            <?php echo nl2br(htmlspecialchars(safeValue($company, 'company_address', ''))); ?>
        </div>
        <?php if (safeValue($company, 'phone')): ?>
            <div class="company-details">Ph: <?php echo htmlspecialchars(safeValue($company, 'phone')); ?></div>
        <?php endif; ?>
        <?php if (safeValue($company, 'email')): ?>
            <div class="company-details"><?php echo htmlspecialchars(safeValue($company, 'email')); ?></div>
        <?php endif; ?>
        <?php if (safeValue($company, 'gst_number')): ?>
            <div class="company-details bold">GST: <?php echo htmlspecialchars(safeValue($company, 'gst_number')); ?></div>
        <?php endif; ?>
    </div>
    
    <div class="line"></div>
    
    <!-- Quotation Info - WITH CURRENT TIME IN THE DOC INFO SECTION -->
    <div class="doc-info">
        <div class="doc-info-row">
            <span class="doc-label">Quotation No:</span>
            <span class="doc-value"><?php echo htmlspecialchars(safeValue($quotation, 'quote_num', 'N/A')); ?></span>
        </div>
        <div class="doc-info-row">
            <span class="doc-label">Date:</span>
            <span class="doc-value"><?php echo date('d-m-Y', strtotime(safeValue($quotation, 'created_at', date('Y-m-d H:i:s')))); ?></span>
        </div>
        <div class="doc-info-row">
            <span class="doc-label">Time:</span>
            <span class="doc-value time-display"><?php echo date('h:i:s A', strtotime(safeValue($quotation, 'created_at', date('Y-m-d H:i:s')))); ?></span>
        </div>
        <?php if (safeValue($quotation, 'valid_until')): ?>
        <div class="center" style="margin-top: 3px;">
            <span class="valid-badge">VALID TILL: <?php echo date('d-m-Y', strtotime(safeValue($quotation, 'valid_until'))); ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="line"></div>
    
    <!-- Customer Details -->
    <div class="customer-section">
        <div class="h2">BILL TO</div>
        <div class="customer-row">
            <span class="customer-label">Name:</span>
            <span class="customer-value"><?php echo htmlspecialchars(safeValue($quotation, 'customer_name', 'Walk-in Customer')); ?></span>
        </div>
        <?php if (safeValue($quotation, 'customer_phone')): ?>
        <div class="customer-row">
            <span class="customer-label">Phone:</span>
            <span class="customer-value"><?php echo htmlspecialchars(safeValue($quotation, 'customer_phone')); ?></span>
        </div>
        <?php endif; ?>
        <?php if (safeValue($quotation, 'customer_gst')): ?>
        <div class="customer-row">
            <span class="customer-label">GST:</span>
            <span class="customer-value"><?php echo htmlspecialchars(safeValue($quotation, 'customer_gst')); ?></span>
        </div>
        <?php endif; ?>
        <?php if (safeValue($quotation, 'customer_address')): ?>
        <div class="customer-row">
            <span class="customer-label">Address:</span>
            <span class="customer-value"><?php echo nl2br(htmlspecialchars(safeValue($quotation, 'customer_address'))); ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="line2"></div>
    
    <!-- Items Table -->
    <table class="table">
        <thead>
            <tr>
                <th class="col-sno">#</th>
                <th class="col-item">Item</th>
                <?php if ($is_gst): ?>
                <th class="col-gst">GST%</th>
                <?php endif; ?>
                <th class="col-qty">Qty</th>
                <th class="col-unit">Unit</th>
                <th class="col-rate">Rate</th>
                <th class="col-amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $i = 1; 
            $total_qty = 0;
            if ($items && $items->num_rows > 0):
                while($item = $items->fetch_assoc()): 
                    $total_qty += safeValue($item, 'quantity', 0);
                    $gst_rate = safeValue($item, 'cgst', 0) + safeValue($item, 'sgst', 0);
            ?>
            <tr>
                <td class="col-sno"><?php echo $i++; ?></td>
                <td class="col-item">
                    <div class="product-name"><?php echo htmlspecialchars(safeValue($item, 'product_name', 'Product')); ?></div>
                    <?php if (safeValue($item, 'cat_name')): ?>
                        <div class="product-category"><?php echo htmlspecialchars(safeValue($item, 'cat_name')); ?></div>
                    <?php endif; ?>
                </td>
                <?php if ($is_gst): ?>
                <td class="col-gst">
                    <?php if ($gst_rate > 0): ?>
                        <span class="gst-badge"><?php echo formatMoney($gst_rate); ?>%</span>
                    <?php else: ?>
                        <span class="gst-badge">0%</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="col-qty"><?php echo formatMoney(safeValue($item, 'quantity', 0)); ?></td>
                <td class="col-unit"><?php echo htmlspecialchars(safeValue($item, 'unit', 'pcs')); ?></td>
                <td class="col-rate"><?php echo formatMoney(safeValue($item, 'selling_price', 0)); ?></td>
                <td class="col-amount"><?php echo formatMoney(safeValue($item, 'total', 0)); ?></td>
            </tr>
            <?php if (safeValue($item, 'pcs_per_bag', 0) > 1): ?>
            <tr>
                <td colspan="<?php echo $is_gst ? '7' : '6'; ?>" style="padding: 0 0 2px 0; font-size: 8px; color: #555;">
                    <span class="bold">Pcs/Bag:</span> <?php echo formatMoney(safeValue($item, 'pcs_per_bag')); ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php 
                endwhile; 
            else:
            ?>
            <tr>
                <td colspan="<?php echo $is_gst ? '7' : '6'; ?>" class="center" style="padding: 10px;">
                    No items found
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="<?php echo $is_gst ? '3' : '2'; ?>" class="bold">Total Items: <?php echo $i-1; ?></td>
                <td class="col-qty bold"><?php echo formatMoney($total_qty); ?></td>
                <td colspan="<?php echo $is_gst ? '2' : '2'; ?>"></td>
                <td class="col-amount bold"><?php echo formatMoney(safeValue($quotation, 'subtotal', 0)); ?></td>
            </tr>
        </tfoot>
    </table>
    
    <div class="dotted-line"></div>
    
    <!-- Summary with GST -->
    <div class="summary-box">
        <div class="summary-row">
            <span>Subtotal:</span>
            <span>₹<?php echo formatMoney(safeValue($quotation, 'subtotal', 0)); ?></span>
        </div>
        
        <?php if (safeValue($quotation, 'overall_discount', 0) > 0): ?>
        <div class="summary-row">
            <span>Discount <?php echo safeValue($quotation, 'overall_discount_type') === 'percentage' ? '(' . formatMoney(safeValue($quotation, 'overall_discount')) . '%)' : '' ?>:</span>
            <span>-₹<?php echo formatMoney(safeValue($quotation, 'overall_discount')); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if (safeValue($quotation, 'shipping_charges', 0) > 0): ?>
        <div class="summary-row">
            <span>Shipping:</span>
            <span>₹<?php echo formatMoney(safeValue($quotation, 'shipping_charges')); ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- GST Section -->
    <?php if ($is_gst && ($gst_totals['cgst'] > 0 || $gst_totals['sgst'] > 0)): ?>
    <div class="gst-summary">
        <div class="gst-row">
            <span class="bold">GST BREAKDOWN</span>
        </div>
        <?php if ($gst_totals['cgst'] > 0): ?>
        <div class="gst-row">
            <span>CGST <span class="gst-percent">(<?php echo formatMoney($gst_totals['cgst_rate']); ?>%)</span>:</span>
            <span>₹<?php echo formatMoney($gst_totals['cgst']); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($gst_totals['sgst'] > 0): ?>
        <div class="gst-row">
            <span>SGST <span class="gst-percent">(<?php echo formatMoney($gst_totals['sgst_rate']); ?>%)</span>:</span>
            <span>₹<?php echo formatMoney($gst_totals['sgst']); ?></span>
        </div>
        <?php endif; ?>
        <div class="gst-row" style="border-top: 1px solid #000; margin-top: 2px; padding-top: 2px;">
            <span class="bold">Total GST:</span>
            <span class="bold">₹<?php echo formatMoney($gst_totals['cgst'] + $gst_totals['sgst']); ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Grand Total -->
    <div class="summary-row total">
        <span class="extra-bold">GRAND TOTAL:</span>
        <span class="extra-bold">₹<?php echo formatMoney(safeValue($quotation, 'total', 0)); ?></span>
    </div>
    
    <?php if ($is_gst): ?>
    <div class="right" style="font-size: 8px; margin-top: 1px;">
        * Inclusive of all taxes
    </div>
    <?php endif; ?>
    
    <div class="dotted-line"></div>
    
    <!-- Amount in Words -->
    <div class="words-box">
        <div class="words-label">Amount in Words</div>
        <div class="words-value">
            Rupees <?php 
            $total_amount = safeValue($quotation, 'total', 0);
            $whole = floor($total_amount);
            $decimal = ($total_amount - $whole) * 100;
            echo ucwords(strtolower(numberToWords($whole))) . " Rupees";
            if ($decimal > 0) {
                echo " and " . ucwords(strtolower(numberToWords($decimal))) . " Paise";
            }
            echo " Only";
            ?>
        </div>
    </div>
    
    <!-- Notes -->
    <?php if (safeValue($quotation, 'notes')): ?>
    <div class="notes-box">
        <div class="notes-title">NOTES</div>
        <div><?php echo nl2br(htmlspecialchars(safeValue($quotation, 'notes'))); ?></div>
    </div>
    <?php endif; ?>
    
    <!-- Footer with Current Time -->
    <div class="footer">
        <div class="footer-bold">This is a computer generated quotation</div>
        <div class="footer-text">Subject to management approval</div>
        <?php if (safeValue($quotation, 'created_by_name')): ?>
            <div class="footer-text">Created by: <?php echo htmlspecialchars(safeValue($quotation, 'created_by_name')); ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- Include NumberFormatter for words -->
<?php
function numberToWords($number) {
    if (!class_exists('NumberFormatter')) {
        return number_format($number, 2);
    }
    $f = new NumberFormatter("en", NumberFormatter::SPELLOUT);
    return $f->format($number);
}
?>

<script>
function goBack() {
    window.history.back();
}

// Update time dynamically
function updateCurrentTime() {
    const now = new Date();
    const day = String(now.getDate()).padStart(2, '0');
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const year = now.getFullYear();
    const hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const formattedHours = String(hours % 12 || 12).padStart(2, '0');
    
    const timeString = `${formattedHours}:${minutes}:${seconds} ${ampm}`;
    
    // Update the time display in the document info section
    const timeDisplay = document.querySelector('.time-display');
    if (timeDisplay) {
        timeDisplay.textContent = timeString;
    }
}

// Update time every second
setInterval(updateCurrentTime, 1000);

// Update time before printing
window.addEventListener('beforeprint', function() {
    updateCurrentTime();
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        goBack();
    }
    if ((e.key === 'p' || e.key === 'P') && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        updateCurrentTime(); // Update time before printing
        setTimeout(() => window.print(), 100);
    }
});

// Initial time set
updateCurrentTime();
</script>

</body>
</html>