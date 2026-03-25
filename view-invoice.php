<?php
// view-invoice.php
session_start();
$currentPage = 'view-invoice';
$pageTitle = 'View Invoice';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view invoices
checkRoleAccess(['admin', 'sale']);

header_remove("X-Powered-By");

// Check if invoice ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: invoices.php');
    exit;
}

$invoice_id = intval($_GET['id']);

// --------------------------
// Helper Functions
// --------------------------
function money2($n) {
    return number_format((float)$n, 2, '.', '');
}

function numberToWords($number) {
    $no = floor($number);
    $point = round($number - $no, 2) * 100;
    $hundred = null;
    $digits_1 = strlen($no);
    $i = 0;
    $str = array();
    $words = array('0' => '', '1' => 'One', '2' => 'Two',
        '3' => 'Three', '4' => 'Four', '5' => 'Five', '6' => 'Six',
        '7' => 'Seven', '8' => 'Eight', '9' => 'Nine',
        '10' => 'Ten', '11' => 'Eleven', '12' => 'Twelve',
        '13' => 'Thirteen', '14' => 'Fourteen', '15' => 'Fifteen',
        '16' => 'Sixteen', '17' => 'Seventeen', '18' => 'Eighteen',
        '19' => 'Nineteen', '20' => 'Twenty', '30' => 'Thirty',
        '40' => 'Forty', '50' => 'Fifty', '60' => 'Sixty',
        '70' => 'Seventy', '80' => 'Eighty', '90' => 'Ninety');
    $digits = array('', 'Hundred', 'Thousand', 'Lakh', 'Crore');
    while ($i < $digits_1) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($number) {
            $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
            $str [] = ($number < 21) ? $words[$number] .
                " " . $digits[$counter] . $plural . " " . $hundred
                :
                $words[floor($number / 10) * 10]
                . " " . $words[$number % 10] . " "
                . $digits[$counter] . $plural . " " . $hundred;
        } else $str[] = null;
    }
    $str = array_reverse($str);
    $result = implode('', $str);
    $points = ($point) ?
        "." . $words[$point / 10] . " " . $words[$point = $point % 10] : '';
    $result = $result . "Rupees " . $points . " Only";
    return $result;
}

// --------------------------
// Get invoice data
// --------------------------

// Get invoice header with customer details
$stmt = $conn->prepare("
    SELECT i.*, 
           c.phone as customer_phone, c.email as customer_email, 
           c.address as customer_address, c.gst_number as customer_gst
    FROM invoice i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.id = ?
");
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    header('Location: invoices.php');
    exit;
}

// Get invoice items with product/category details
$item_stmt = $conn->prepare("
    SELECT ii.*, 
           p.hsn_code, p.primary_unit, p.sec_unit
    FROM invoice_item ii
    LEFT JOIN product p ON ii.product_id = p.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id ASC
");
$item_stmt->bind_param("i", $invoice_id);
$item_stmt->execute();
$items = $item_stmt->get_result();

// Calculate totals
$total_taxable = 0;
$total_cgst = 0;
$total_sgst = 0;
$total_amount = 0;

$item_list = [];
while ($item = $items->fetch_assoc()) {
    $total_taxable += floatval($item['taxable']);
    $total_cgst += floatval($item['cgst_amount']);
    $total_sgst += floatval($item['sgst_amount']);
    $total_amount += floatval($item['total']);
    $item_list[] = $item;
}

// Reset pointer
$items->data_seek(0);

// Get invoice settings for company details
$settings = $conn->query("SELECT * FROM invoice_setting ORDER BY id ASC LIMIT 1")->fetch_assoc();
if (!$settings) {
    // Default settings if not found
    $settings = [
        'company_name' => 'SRI PLAAST',
        'company_address' => 'No: 5/268-6, PERIYAR NAGAR, H.DHOTTAMPATTI ROAD, HARUR, DHARMAPURI DT-636903.',
        'phone' => '9688011887, 9865133431',
        'email' => 'sriplaats@gmail.com',
        'gst_number' => '33BWZPA4843D1Z',
        'bank_name' => 'UNION BANK OF INDIA',
        'branch' => 'HARUR',
        'account_number' => '75970501000003',
        'ifsc' => 'UBIN0575976'
    ];
}

// Format date
$invoice_date = date('d/m/Y', strtotime($invoice['created_at']));

// Calculate CGST and SGST rates
$cgst_rate = $total_taxable > 0 ? round(($total_cgst / $total_taxable) * 100, 2) : 0;
$sgst_rate = $total_taxable > 0 ? round(($total_sgst / $total_taxable) * 100, 2) : 0;

// Amount in words
$amount_in_words = numberToWords($invoice['total']);

// Get invoice number with prefix
$invoice_number = $invoice['inv_num'] ?? 'INV' . str_pad($invoice_id, 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        /* Invoice specific styles */
        body {
            background: #f0f4f8;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .invoice-wrapper {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .invoice-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .invoice-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .invoice-title {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }
        
        .invoice-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        
        .btn-action.print {
            background: var(--primary);
            color: white;
        }
        
        .btn-action.back {
            background: white;
            color: var(--dark);
            border: 1px solid #e2e8f0;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        /* Invoice Content */
        .invoice-content {
            padding: 30px;
        }
        
        /* Company and Customer Info */
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
            border-bottom: 2px dashed #e2e8f0;
            padding-bottom: 20px;
        }
        
        .company-info h2 {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 10px 0;
        }
        
        .company-details, .customer-details {
            font-size: 14px;
            color: #475569;
            line-height: 1.6;
        }
        
        .gst-badge {
            display: inline-block;
            background: #e8f2ff;
            color: #2563eb;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 8px;
        }
        
        /* Invoice Meta */
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #e2e8f0;
        }
        
        .meta-item {
            display: flex;
            flex-direction: column;
        }
        
        .meta-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .meta-value {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
        }
        
        /* Items Table */
        .table-container {
            overflow-x: auto;
            margin-bottom: 30px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
        }
        
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .invoice-table th {
            background: #f8fafc;
            padding: 15px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
        }
        
        .invoice-table td {
            padding: 15px;
            border-bottom: 1px solid #edf2f9;
            color: #334155;
        }
        
        .invoice-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .invoice-table tfoot {
            background: #f8fafc;
            font-weight: 600;
        }
        
        .invoice-table tfoot td {
            padding: 15px;
            border-top: 2px solid #e2e8f0;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        /* Taxation Section */
        .taxation-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .tax-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
        }
        
        .tax-title {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .tax-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        .tax-row:last-child {
            border-bottom: none;
        }
        
        .tax-label {
            color: #64748b;
        }
        
        .tax-value {
            font-weight: 600;
            color: #0f172a;
        }
        
        .grand-total {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }
        
        /* Amount in words */
        .amount-words {
            background: #f0f9ff;
            border-left: 4px solid #2563eb;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            font-size: 14px;
            font-weight: 500;
            color: #1e40af;
        }
        
        /* Declaration */
        .declaration {
            font-size: 13px;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 30px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        
        /* Bank Details */
        .bank-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .bank-item {
            display: flex;
            flex-direction: column;
        }
        
        .bank-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .bank-value {
            font-size: 14px;
            font-weight: 500;
            color: #0f172a;
            margin-top: 4px;
        }
        
        /* Signature */
        .signature {
            display: flex;
            justify-content: flex-end;
            margin-top: 30px;
        }
        
        .signature-box {
            text-align: center;
            width: 200px;
        }
        
        .signature-line {
            border-top: 1px dashed #94a3b8;
            margin: 30px 0 5px 0;
        }
        
        /* Footer */
        .invoice-footer {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .topbar, .action-buttons, .footer {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            
            .invoice-wrapper {
                margin: 0 auto;
                padding: 0;
            }
            
            .invoice-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .invoice-header {
                background: #f4f4f4 !important;
                color: #333 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .meta-grid, .tax-box, .bank-details {
                break-inside: avoid;
            }
        }
        
        @media (max-width: 768px) {
            .info-section {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .meta-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .taxation-section {
                grid-template-columns: 1fr;
            }
            
            .bank-details {
                grid-template-columns: 1fr;
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
            <div class="invoice-wrapper">

                <!-- Header with Actions -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                    <div>
                        <h4 class="fw-bold mb-1">View Invoice</h4>
                        <p class="text-muted">Invoice #<?php echo htmlspecialchars($invoice_number); ?></p>
                    </div>
                    <div class="action-buttons">
                        <a href="invoices.php" class="btn-action back">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                        <a href="#" onclick="window.print(); return false;" class="btn-action print">
                            <i class="bi bi-printer"></i> Print
                        </a>
                    </div>
                </div>

                <!-- Invoice Card -->
                <div class="invoice-card">
                    <div class="invoice-header">
                        <div>
                            <h1 class="invoice-title">TAX INVOICE</h1>
                            <p style="margin: 5px 0 0; opacity: 0.9;">[ORIGINAL FOR RECEIPT]</p>
                        </div>
                        <div class="invoice-badge">
                            Invoice No: <?php echo htmlspecialchars($invoice_number); ?>
                        </div>
                    </div>

                    <div class="invoice-content">
                        <!-- Company and Customer Info -->
                        <div class="info-section">
                            <div class="company-info">
                                <h2><?php echo htmlspecialchars($settings['company_name'] ?? 'SRI PLAAST'); ?></h2>
                                <div class="company-details">
                                    <p><?php echo nl2br(htmlspecialchars($settings['company_address'] ?? '')); ?></p>
                                    <p>Email: <?php echo htmlspecialchars($settings['email'] ?? 'sriplaats@gmail.com'); ?></p>
                                    <p>Cell: <?php echo htmlspecialchars($settings['phone'] ?? '9688011887, 9865133431'); ?></p>
                                    <span class="gst-badge">GSTIN: <?php echo htmlspecialchars($settings['gst_number'] ?? '33BWZPA4843D1Z'); ?></span>
                                </div>
                            </div>
                            
                            <div class="customer-info">
                                <h3 style="font-size: 18px; margin: 0 0 10px; color: #0f172a;">Buyer (Billing To)</h3>
                                <div class="customer-details">
                                    <p><strong><?php echo htmlspecialchars($invoice['customer_name'] ?? ''); ?></strong></p>
                                    <p><?php echo nl2br(htmlspecialchars($invoice['customer_address'] ?? '')); ?></p>
                                    <p>Cell No: <?php echo htmlspecialchars($invoice['customer_phone'] ?? '-'); ?></p>
                                    <?php if (!empty($invoice['customer_gst'])): ?>
                                        <span class="gst-badge">GSTIN: <?php echo htmlspecialchars($invoice['customer_gst']); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($invoice['customer_address'])): ?>
                                <div style="margin-top: 15px;">
                                    <h4 style="font-size: 14px; margin: 0 0 5px; color: #475569;">Consignee (Shipping To)</h4>
                                    <p style="font-size: 13px;"><?php echo nl2br(htmlspecialchars($invoice['customer_address'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Invoice Meta Information -->
                        <div class="meta-grid">
                            <div class="meta-item">
                                <span class="meta-label">Invoice Date</span>
                                <span class="meta-value"><?php echo date('d/m/Y', strtotime($invoice['created_at'])); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Mode of Payment</span>
                                <span class="meta-value"><?php echo ucfirst($invoice['payment_method'] ?? 'Credit'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Supplier's Ref</span>
                                <span class="meta-value">-</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Other References</span>
                                <span class="meta-value">-</span>
                            </div>
                        </div>

                        <!-- Items Table -->
                        <div class="table-container">
                            <table class="invoice-table">
                                <thead>
                                    <tr>
                                        <th>S.No</th>
                                        <th>Description of Goods</th>
                                        <th>HSN/SAC</th>
                                        <th class="text-right">Qty (Pcs)</th>
                                        <th class="text-right">Rate</th>
                                        <th class="text-right">Taxable Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sno = 1;
                                    foreach ($item_list as $item): 
                                        $qty = floatval($item['quantity']);
                                        $rate = floatval($item['selling_price']);
                                        $taxable = floatval($item['taxable']);
                                    ?>
                                    <tr>
                                        <td><?php echo $sno++; ?></td>
                                        <td><?php echo htmlspecialchars($item['product_name'] . ' (' . $item['cat_name'] . ')'); ?></td>
                                        <td><?php echo htmlspecialchars($item['hsn'] ?? '39233090'); ?></td>
                                        <td class="text-right"><?php echo number_format($qty, 0); ?></td>
                                        <td class="text-right"><?php echo money2($rate); ?></td>
                                        <td class="text-right"><?php echo money2($taxable); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="5" class="text-right"><strong>Total</strong></td>
                                        <td class="text-right"><strong><?php echo money2($total_taxable); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Taxation Section -->
                        <div class="taxation-section">
                            <div class="tax-box">
                                <div class="tax-title">Taxation Summary</div>
                                <div class="tax-row">
                                    <span class="tax-label">Total Taxable Value:</span>
                                    <span class="tax-value">₹<?php echo money2($total_taxable); ?></span>
                                </div>
                                <div class="tax-row">
                                    <span class="tax-label">CGST @<?php echo $cgst_rate; ?>%:</span>
                                    <span class="tax-value">₹<?php echo money2($total_cgst); ?></span>
                                </div>
                                <div class="tax-row">
                                    <span class="tax-label">SGST @<?php echo $sgst_rate; ?>%:</span>
                                    <span class="tax-value">₹<?php echo money2($total_sgst); ?></span>
                                </div>
                                <div class="tax-row">
                                    <span class="tax-label">IGST:</span>
                                    <span class="tax-value">₹0.00</span>
                                </div>
                                <div class="tax-row grand-total" style="margin-top: 10px; padding-top: 10px; border-top: 2px solid #e2e8f0;">
                                    <span class="tax-label">Net Total:</span>
                                    <span class="tax-value">₹<?php echo money2($invoice['total']); ?></span>
                                </div>
                            </div>
                            
                            <div class="tax-box">
                                <div class="tax-title">Payment Summary</div>
                                <div class="tax-row">
                                    <span class="tax-label">Cash Received:</span>
                                    <span class="tax-value">₹<?php echo money2($invoice['cash_received'] ?? 0); ?></span>
                                </div>
                                <div class="tax-row">
                                    <span class="tax-label">Change Given:</span>
                                    <span class="tax-value">₹<?php echo money2($invoice['change_give'] ?? 0); ?></span>
                                </div>
                                <div class="tax-row">
                                    <span class="tax-label">Pending Amount:</span>
                                    <span class="tax-value text-danger">₹<?php echo money2($invoice['pending_amount'] ?? 0); ?></span>
                                </div>
                                <div class="tax-row" style="margin-top: 10px; padding-top: 10px; border-top: 2px solid #e2e8f0;">
                                    <span class="tax-label">Overall Discount:</span>
                                    <span class="tax-value">₹<?php echo money2($invoice['overall_discount'] ?? 0); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Amount in Words -->
                        <div class="amount-words">
                            <strong>Amount Chargeable in words:</strong><br>
                            <?php echo $amount_in_words; ?>
                        </div>

                        <!-- Declaration -->
                        <div class="declaration">
                            <strong>Declaration:</strong><br>
                            1. We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.
                        </div>

                        <!-- Bank Details -->
                        <div class="bank-details">
                            <div class="bank-item">
                                <span class="bank-label">Bank Name</span>
                                <span class="bank-value"><?php echo htmlspecialchars($settings['bank_name'] ?? 'UNION BANK OF INDIA'); ?></span>
                            </div>
                            <div class="bank-item">
                                <span class="bank-label">Branch</span>
                                <span class="bank-value"><?php echo htmlspecialchars($settings['branch'] ?? 'HARUR'); ?></span>
                            </div>
                            <div class="bank-item">
                                <span class="bank-label">Account No</span>
                                <span class="bank-value"><?php echo htmlspecialchars($settings['account_number'] ?? '75970501000003'); ?></span>
                            </div>
                            <div class="bank-item">
                                <span class="bank-label">IFSC Code</span>
                                <span class="bank-value"><?php echo htmlspecialchars($settings['ifsc'] ?? 'UBIN0575976'); ?></span>
                            </div>
                        </div>

                        <!-- Signature -->
                        <div class="signature">
                            <div class="signature-box">
                                <div class="signature-line"></div>
                                <p style="margin: 5px 0 0; font-size: 13px; color: #475569;">Authorised Signatory</p>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="invoice-footer">
                            <p>This is a computer generated invoice - no signature required</p>
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
// Print functionality
document.addEventListener('keydown', function(e) {
    // Ctrl+P or Cmd+P
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
});
</script>

</body>
</html>