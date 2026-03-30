<?php
// print_invoice_pdf.php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

checkRoleAccess(['admin', 'sale']);

$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoice_id <= 0) die("Invalid invoice ID");

// Fetch invoice + customer
$stmt = $conn->prepare("
    SELECT i.*, c.phone as customer_phone, c.email as customer_email,
           c.address as customer_address, c.gst_number as customer_gst,
           c.opening_balance
    FROM invoice i LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.id = ?
");
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) die("Invoice not found");

// Fetch items - USING no_of_pcs FROM invoice_item TABLE
$stmt = $conn->prepare("
    SELECT ii.*, p.hsn_code, p.primary_unit, p.sec_unit, p.primary_qty, p.sec_qty
    FROM invoice_item ii LEFT JOIN product p ON ii.product_id = p.id
    WHERE ii.invoice_id = ?
");
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Company settings
$setting = $conn->query("SELECT * FROM invoice_setting ORDER BY id ASC LIMIT 1")->fetch_assoc();

// --------------------------------------------------
// CALCULATIONS
// --------------------------------------------------
$total_bags = 0;
$total_pieces = 0;
$total_taxable = 0;
$total_cgst = 0;
$total_sgst = 0;
$total_gst = 0;

foreach ($items as $item) {
    $qty  = (float)($item['quantity'] ?? 0);
    $unit = strtolower(trim((string)($item['unit'] ?? '')));

    // Get pieces directly from invoice_item.no_of_pcs column
    $pieces = (float)($item['no_of_pcs'] ?? 0);

    $bags = 0.0;

    // If unit is bag/bags, use quantity as bag count
    if (($unit === 'bag' || $unit === 'bags') && $qty > 0) {
        $bags = $qty;
    }

    $total_bags += $bags;
    $total_pieces += $pieces;
    $total_taxable += (float)($item['taxable'] ?? 0);
    $total_cgst += (float)($item['cgst_amount'] ?? 0);
    $total_sgst += (float)($item['sgst_amount'] ?? 0);
}
$total_gst = $total_cgst + $total_sgst;

// Get invoice totals
$subtotal = (float)($invoice['subtotal'] ?? $total_taxable);
$overall_discount = (float)($invoice['overall_discount'] ?? 0);
$freight_charge = (float)($invoice['shipping_charges'] ?? 0);
$total_invoice = (float)($invoice['total'] ?? 0);
$cash_received = (float)($invoice['cash_received'] ?? 0);
$pending_amount = (float)($invoice['pending_amount'] ?? 0);
$is_gst = (int)($invoice['is_gst'] ?? 1);
$customer_opening_balance = (float)($invoice['opening_balance'] ?? 0);

require_once 'libs/tcpdf/tcpdf.php';

function formatNum($num, $dec = 2) {
    return number_format((float)$num, $dec, '.', '');
}

// Helper function to add rupee symbol properly
function formatMoney($amount) {
    return '&#8377; ' . number_format((float)$amount, 2, '.', '');
}

function numberToWords($number) {
    $no = floor($number);
    $decimal = round(($number - $no) * 100);
    $digits_length = strlen((string)$no);
    $i = 0;
    $str = [];
    $words = [
        0=>'',1=>'One',2=>'Two',3=>'Three',4=>'Four',5=>'Five',6=>'Six',
        7=>'Seven',8=>'Eight',9=>'Nine',10=>'Ten',11=>'Eleven',12=>'Twelve',
        13=>'Thirteen',14=>'Fourteen',15=>'Fifteen',16=>'Sixteen',17=>'Seventeen',
        18=>'Eighteen',19=>'Nineteen',20=>'Twenty',30=>'Thirty',40=>'Forty',
        50=>'Fifty',60=>'Sixty',70=>'Seventy',80=>'Eighty',90=>'Ninety'
    ];
    $digits = ['','Hundred','Thousand','Lakh','Crore'];

    while ($i < $digits_length) {
        $divider = ($i == 2) ? 10 : 100;
        $num_part = floor($no % $divider);
        $no = floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;

        if ($num_part) {
            $plural = (count($str) && $num_part > 9) ? 's' : '';
            $str[] = ($num_part < 21)
                ? $words[$num_part] . ' ' . $digits[count($str)] . $plural
                : $words[floor($num_part/10)*10] . ' ' . $words[$num_part%10] . ' ' . $digits[count($str)] . $plural;
        } else {
            $str[] = null;
        }
    }

    $rupees = implode(' ', array_filter(array_reverse($str)));

    $paise = '';
    if ($decimal > 0) {
        $tens = floor($decimal / 10) * 10;
        $ones = $decimal % 10;
        $paiseWords = trim(($words[$tens] ?? '') . ' ' . ($words[$ones] ?? ''));
        $paise = " and " . $paiseWords . " Paise";
    }

    return ($rupees ?: 'Zero') . $paise . ' Only';
}

// Function to get payment method display text
function getPaymentMethodDisplay($invoice) {
    $payment_method = $invoice['payment_method'] ?? 'cash';
    $cash_amount = (float)($invoice['cash_amount'] ?? 0);
    $upi_amount = (float)($invoice['upi_amount'] ?? 0);
    $card_amount = (float)($invoice['card_amount'] ?? 0);
    $bank_amount = (float)($invoice['bank_amount'] ?? 0);
    $cheque_amount = (float)($invoice['cheque_amount'] ?? 0);
    $credit_amount = (float)($invoice['credit_amount'] ?? 0);

    if ($payment_method === 'mixed' ||
        ($cash_amount > 0 && $upi_amount > 0) ||
        ($cash_amount > 0 && $card_amount > 0) ||
        ($cash_amount > 0 && $bank_amount > 0) ||
        ($cash_amount > 0 && $cheque_amount > 0) ||
        ($upi_amount > 0 && $card_amount > 0) ||
        ($upi_amount > 0 && $bank_amount > 0) ||
        ($upi_amount > 0 && $cheque_amount > 0) ||
        ($card_amount > 0 && $bank_amount > 0) ||
        ($card_amount > 0 && $cheque_amount > 0) ||
        ($bank_amount > 0 && $cheque_amount > 0)) {
        return 'Mixed Payment';
    }

    switch ($payment_method) {
        case 'cash':
            return 'Cash';
        case 'upi':
            return 'UPI';
        case 'card':
            return 'Card';
        case 'bank':
            return 'Bank Transfer';
        case 'cheque':
            return 'Cheque';
        case 'credit':
            return 'Credit';
        default:
            return 'Cash';
    }
}

// Function to get payment breakdown text
function getPaymentBreakdown($invoice) {
    $cash_amount = (float)($invoice['cash_amount'] ?? 0);
    $upi_amount = (float)($invoice['upi_amount'] ?? 0);
    $card_amount = (float)($invoice['card_amount'] ?? 0);
    $bank_amount = (float)($invoice['bank_amount'] ?? 0);
    $cheque_amount = (float)($invoice['cheque_amount'] ?? 0);
    $credit_amount = (float)($invoice['credit_amount'] ?? 0);

    $breakdown = [];
    if ($cash_amount > 0) $breakdown[] = 'Cash: ₹' . formatNum($cash_amount);
    if ($upi_amount > 0) $breakdown[] = 'UPI: ₹' . formatNum($upi_amount);
    if ($card_amount > 0) $breakdown[] = 'Card: ₹' . formatNum($card_amount);
    if ($bank_amount > 0) $breakdown[] = 'Bank: ₹' . formatNum($bank_amount);
    if ($cheque_amount > 0) $breakdown[] = 'Cheque: ₹' . formatNum($cheque_amount);
    if ($credit_amount > 0) $breakdown[] = 'Credit: ₹' . formatNum($credit_amount);

    return implode(' + ', $breakdown);
}

// Function to get place of supply
function getPlaceOfSupply($invoice) {
    // Default to Tamil Nadu
    return 'TAMIL NADU (33)';
}

class GSTPDF extends TCPDF {
    public $company_name = 'SRI PLAAST';
    public $bank_name = 'UNION BANK OF INDIA';
    public $account_number = '759705010000003';
    public $branch = 'HARUR';
    public $ifsc = 'UBIN0575976';

    public function Header() {
        // Clean outer border (no overlap)
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.8);

        $margin = 4;
        $this->Rect(
            $margin,
            $margin,
            $this->getPageWidth() - (2 * $margin),
            $this->getPageHeight() - (2 * $margin)
        );
    }

    public function Footer() {
        // Move footer properly above bottom border
        $this->SetY(-50);
        $this->SetFont('freeserif', '', 8);

        $footerHtml = '
        <table border="1" cellpadding="4" cellspacing="0" style="width:100%;">
            <tr>
                <td width="70%">
                    <b>Declaration :</b><br>
                    We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.
                    <br><br>

                    <b>Bank Details :</b><br>
                    <table cellpadding="2">
                        <tr>
                            <td width="30%"><b>Bank</b></td>
                            <td>: ' . htmlspecialchars($this->bank_name) . '</td>
                        </tr>
                        <tr>
                            <td><b>Account No</b></td>
                            <td>: ' . htmlspecialchars($this->account_number) . '</td>
                        </tr>
                        <tr>
                            <td><b>Branch</b></td>
                            <td>: ' . htmlspecialchars($this->branch) . '</td>
                        </tr>
                        <tr>
                            <td><b>IFSC</b></td>
                            <td>: ' . htmlspecialchars($this->ifsc) . '</td>
                        </tr>
                    </table>
                </td>

                <td width="30%" align="center">
                    <b>For ' . htmlspecialchars($this->company_name) . '</b>
                    <br><br><br><br><br><br><br><br><br>
                    <b>Authorised Signatory</b>
                </td>
             </tr>

             <tr>
                <td colspan="2" align="center">
                    This is a Computer Generated Invoice
                </td>
             </tr>
        </table>';

        $this->writeHTMLCell(0, 0, 6, '', $footerHtml, 0, 0, false, true);
    }
}

class NonGSTPDF extends TCPDF {
    public $company_name = 'SRI PLAAST';
    
    public function Header() {
        // No outer border for non-GST
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
    }
    
    public function Footer() {
        // Simple footer for non-GST
        $this->SetY(-15);
        $this->SetFont('freeserif', '', 8);
        $this->Cell(0, 10, 'This is a Computer Generated Estimate', 0, 0, 'C');
    }
}

// Choose PDF class based on GST status
if ($is_gst == 1) {
    $pdf = new GSTPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetMargins(6, 8, 6);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true, 55);
    
    // Assign footer values from settings
    $pdf->company_name   = $setting['company_name'] ?? 'SRI PLAAST';
    $pdf->bank_name      = $setting['bank_name'] ?? 'UNION BANK OF INDIA';
    $pdf->account_number = $setting['account_number'] ?? '759705010000003';
    $pdf->branch         = $setting['branch'] ?? 'HARUR';
    $pdf->ifsc           = $setting['ifsc'] ?? 'UBIN0575976';
    
    $pdf->SetFont('freeserif', '', 9);
    $pdf->AddPage();
    
    // Set line width for inner table borders
    $pdf->SetLineWidth(0.2);
    
    /* -----------------------------
    GST INVOICE HEADER
    ----------------------------- */
    $html = '
    <table border="1" cellpadding="4" cellspacing="0">
        <tr>
            <td colspan="2" align="center" style="font-size:16px;"><b>TAX INVOICE</b></td>
        </tr>
        <tr>
            <td width="70%"></td>
            <td width="30%" align="right"><b>[ORIGINAL FOR RECIPIENT]</b></td>
        </tr>
    </table>

    <table border="1" cellpadding="4" cellspacing="0">
        <tr>
            <td width="50%">
                <table border="0">
                    <tr>
                        <td width="20%">';
    
    if (!empty($setting['logo']) && file_exists($setting['logo'])) {
        $html .= '<img src="' . $setting['logo'] . '" height="45">';
    } else {
        $html .= '<div style="border:1px dashed #888; height:45px; width:45px; text-align:center; vertical-align:middle;">LOGO</div>';
    }
    
    $html .= '</td>
                        <td width="80%">
                            <b style="font-size:16px">' . htmlspecialchars($setting['company_name'] ?? 'SRI PLAAST') . '</b><br>
                            ' . nl2br(htmlspecialchars($setting['company_address'] ?? 'No.10 Industrial Area, Dharmapuri, Tamil Nadu')) . '<br>
                            <b>Cell :</b> ' . htmlspecialchars($setting['phone'] ?? '7904448752') . '<br>
                            <b>GSTIN :</b> ' . htmlspecialchars($setting['gst_number'] ?? '33ABCDE1234F1Z5') . '
                        </td>
                    </tr>
                </table>
            </td>

            <td width="50%">
                <table border="1" cellpadding="3" cellspacing="0">
                    <tr>
                        <td width="50%"><b>Invoice No :</b><br>' . htmlspecialchars($invoice['inv_num'] ?? 'SP0001') . '</td>
                        <td width="50%"><b>Date :</b><br>' . (!empty($invoice['created_at']) ? date('d/m/Y', strtotime($invoice['created_at'])) : date('d/m/Y')) . '</td>
                    </tr>
                    <tr>
                        <td><b>Delivery Note :</b><br>-</td>
                        <td><b>Mode / Terms of Payment :</b><br>' . getPaymentMethodDisplay($invoice) . '</td>
                    </tr>
                    <tr>
                        <td><b>E-way Bill No :</b><br>' . htmlspecialchars($invoice['e_way_bill'] ?? '') . '</td>
                        <td><b>Place of Supply :</b><br>' . getPlaceOfSupply($invoice) . '</td>
                    </tr>
                    <tr>
                        <td><b>Dispatched Through :</b><br>' . htmlspecialchars($invoice['dispatch_through'] ?? '') . '</td>
                        <td><b>Other Reference :</b><br>' . htmlspecialchars($invoice['other_reference'] ?? '') . '</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    ';
    
    /* -----------------------------
    BILLING + SHIPPING
    ----------------------------- */
    $html .= '
    <table border="1" cellpadding="4" cellspacing="0">
        <tr>
            <td width="50%">
                <b>Buyer (Billing To)</b><br>
                ' . htmlspecialchars($invoice['customer_name'] ?? '') . '<br>
                ' . nl2br(htmlspecialchars($invoice['customer_address'] ?? '')) . '<br>
                <b>Cell :</b> ' . htmlspecialchars($invoice['customer_phone'] ?? '') . '<br>
                <b>GSTIN :</b> ' . htmlspecialchars($invoice['customer_gst'] ?? '') . '<br>
                <b>State Name :</b> TAMIL NADU &nbsp; <b>State Code :</b> 33
            </td>

            <td width="50%">
                <b>Consignee (Shipping To)</b><br>
                ' . htmlspecialchars($invoice['customer_name'] ?? '') . '<br>';
    
    if (!empty($invoice['shipping_address'])) {
        $html .= nl2br(htmlspecialchars($invoice['shipping_address'])) . '<br>';
    } else {
        $html .= nl2br(htmlspecialchars($invoice['customer_address'] ?? '')) . '<br>';
    }
    
    $html .= '<b>State Name :</b> TAMIL NADU &nbsp; <b>State Code :</b> 33
            </td>
        </tr>
    </table>
    ';
    
    /* -----------------------------
    ITEM TABLE
    ----------------------------- */
    $html .= '
    <table border="1" cellpadding="4" cellspacing="0">
        <tr style="background:#e0e0e0; font-weight:bold;">
            <th width="5%">S.No</th>
            <th width="35%">DESCRIPTION</th>
            <th width="10%">HSN</th>
            <th width="7%">UNIT</th>
            <th width="8%">QTY</th>
            <th width="7%">PCS</th>
            <th width="8%">RATE</th>
            <th width="10%">DISCOUNT</th>
            <th width="10%">TAXABLE</th>
        </tr>
    ';
    
    $sn = 1;
    foreach ($items as $item) {
        $qty  = (float)($item['quantity'] ?? 0);
        $pieces = (float)($item['no_of_pcs'] ?? 0);
        $taxable = (float)($item['taxable'] ?? 0);
        $rate = (float)($item['selling_price'] ?? 0);
        $disc = (float)($item['discount'] ?? 0);
        $disc_str = $disc > 0 ? (($item['discount_type'] ?? '') === 'percentage' ? formatNum($disc, 1) . '%' : '&#8377;' . formatNum($disc, 2)) : '-';
    
        $desc = htmlspecialchars($item['product_name'] ?? '-');
        $hsn  = htmlspecialchars($item['hsn'] ?? '-');
        $unit = htmlspecialchars($item['unit'] ?? 'BAGS');
    
        $html .= '
        <tr>
            <td align="center">' . $sn++ . '</td>
            <td>' . $desc . '</td>
            <td align="center">' . $hsn . '</td>
            <td align="center">' . $unit . '</td>
            <td align="center">' . ($qty > 0 ? formatNum($qty) : '-') . '</td>
            <td align="center">' . ($pieces > 0 ? formatNum($pieces) : '-') . '</td>
            <td align="right">&#8377; ' . formatNum($rate, 2) . '</td>
            <td align="center">' . $disc_str . '</td>
            <td align="right">&#8377; ' . formatNum($taxable) . '</td>
        </tr>
        ';
    }
    
    $html .= '
        <tr style="background:#f0f0f0; font-weight:bold;">
            <td colspan="4" align="right"><b>Total</b></td>
            <td align="center">' . formatNum($total_bags) . '</td>
            <td align="center">' . formatNum($total_pieces) . '</td>
            <td colspan="2"></td>
            <td align="right"><b>&#8377; ' . formatNum($total_taxable) . '</b></td>
        </tr>
    </table>
    ';
    
    /* -----------------------------
    BOTTOM SECTION
    ----------------------------- */
    // Group items by GST rate for display
    $cgst_rates = [];
    $sgst_rates = [];
    foreach ($items as $item) {
        $cgst_rate = (float)($item['cgst'] ?? 0);
        $sgst_rate = (float)($item['sgst'] ?? 0);
        
        if ($cgst_rate > 0) {
            $cgst_rates[$cgst_rate] = ($cgst_rates[$cgst_rate] ?? 0) + (float)($item['cgst_amount'] ?? 0);
        }
        if ($sgst_rate > 0) {
            $sgst_rates[$sgst_rate] = ($sgst_rates[$sgst_rate] ?? 0) + (float)($item['sgst_amount'] ?? 0);
        }
    }
    
    $html .= '
    <table border="1" cellpadding="4" cellspacing="0">
        <tr>
            <td width="55%">
                <b>Amount Chargeable in words :</b><br>
                <b style="font-size:10px;">' . strtoupper(numberToWords($total_invoice)) . '</b>
                <br><br><br>
                <b>TAXATION</b>

                <table border="1" cellpadding="4" cellspacing="0" style="margin-top:5px;">
                    <tr style="background:#e0e0e0; font-weight:bold;">
                        <th align="center">CGST %</th>
                        <th align="center">Amount</th>
                        <th align="center">SGST %</th>
                        <th align="center">Amount</th>
                    </tr>';
    
    if (empty($cgst_rates) && empty($sgst_rates)) {
        $html .= '
                    <tr>
                        <td align="center">' . ($total_cgst > 0 ? '9.0%' : '-') . '</td>
                        <td align="right">' . ($total_cgst > 0 ? formatNum($total_cgst) : '-') . '</td>
                        <td align="center">' . ($total_sgst > 0 ? '9.0%' : '-') . '</td>
                        <td align="right">' . ($total_sgst > 0 ? formatNum($total_sgst) : '-') . '</td>
                    </tr>';
    } else {
        $max_rows = max(count($cgst_rates), count($sgst_rates));
        $cgst_keys = array_keys($cgst_rates);
        $sgst_keys = array_keys($sgst_rates);
        
        for ($i = 0; $i < $max_rows; $i++) {
            $html .= '<tr>';
            
            if (isset($cgst_keys[$i])) {
                $rate = $cgst_keys[$i];
                $amount = $cgst_rates[$rate];
                $html .= '<td align="center">' . formatNum($rate, 1) . '%</td>';
                $html .= '<td align="right">' . formatNum($amount, 2) . '</td>';
            } else {
                $html .= '<td align="center">-</td><td align="right">-</td>';
            }
            
            if (isset($sgst_keys[$i])) {
                $rate = $sgst_keys[$i];
                $amount = $sgst_rates[$rate];
                $html .= '<td align="center">' . formatNum($rate, 1) . '%</td>';
                $html .= '<td align="right">' . formatNum($amount, 2) . '</td>';
            } else {
                $html .= '<td align="center">-</td><td align="right">-</td>';
            }
            
            $html .= '</tr>';
        }
    }
    
    $html .= '
                </table>
            </td>

            <td width="45%" style="vertical-align:top;">
                <table border="1" cellpadding="4" cellspacing="0" style="width:100%;">
                    <tr>
                        <td><b>Freight Charges :</b></td>
                        <td align="right">&#8377; ' . formatNum($freight_charge) . '</td>
                    </tr>';
    
    if ($overall_discount > 0) {
        $html .= '
                    <tr>
                        <td><b>Discount :</b></td>
                        <td align="right">- &#8377; ' . formatNum($overall_discount) . '</td>
                    </tr>';
    }
    
    $html .= '
                    <tr>
                        <td><b>Subtotal :</b></td>
                        <td align="right">&#8377; ' . formatNum($subtotal) . '</td>
                    </tr>
                    <tr style="background:#f0f0f0; font-weight:bold;">
                        <td><b>Net Total :</b></td>
                        <td align="right"><b>&#8377; ' . formatNum($total_invoice) . '</b></td>
                    </tr>
                    <tr style="background:#e0f7fa;">
                        <td><b>Amount Paid :</b></td>
                        <td align="right"><b>&#8377; ' . formatNum($cash_received) . '</b></td>
                    </tr>
                    <tr style="background:#ffebee;">
                        <td><b>Pending Amount :</b></td>
                        <td align="right"><b>&#8377; ' . formatNum($pending_amount) . '</b></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    
    <br>
    
    <table border="1" cellpadding="4" cellspacing="0" style="width:100%; margin-bottom:10px;">
        <tr style="background:#e0e0e0; font-weight:bold;">
            <td colspan="2">Payment Summary</td>
        </tr>
        <tr>
            <td width="50%"><b>Payment Mode:</b></td>
            <td width="50%">' . getPaymentMethodDisplay($invoice) . '</td>
        </tr>
        <tr>
            <td><b>Payment Breakdown:</b></td>
            <td>' . getPaymentBreakdown($invoice) . '</td>
        </tr>' . 
        (($pending_amount > 0 && !empty($invoice['credit_due_date'])) ? '
        <tr>
            <td><b>Credit Due Date:</b></td>
            <td>' . date('d/m/Y', strtotime($invoice['credit_due_date'])) . '</td>
        </tr>' : '') . 
        ((!empty($invoice['cheque_number'])) ? '
        <tr>
            <td><b>Cheque Details:</b></td>
            <td>No: ' . htmlspecialchars($invoice['cheque_number']) . ', Date: ' . (!empty($invoice['cheque_date']) ? date('d/m/Y', strtotime($invoice['cheque_date'])) : '') . ', Bank: ' . htmlspecialchars($invoice['cheque_bank']) . '</td>
        </tr>' : '') . '
    </table>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output("Invoice_" . ($invoice['inv_num'] ?? 'TEMP') . ".pdf", "I");
    
} else {
    // NON-GST INVOICE (ESTIMATE FORMAT)
    $pdf = new NonGSTPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->SetFont('freeserif', '', 10);
    $pdf->AddPage();
    
    // Calculate total amount including all items
    $total_amount = 0;
    foreach ($items as $item) {
        $total_amount += (float)($item['total'] ?? 0);
    }
    
    // Calculate balance amount including opening balance
    $total_due = $customer_opening_balance + $total_amount - $cash_received;
    
    // HTML for Non-GST Estimate
    $html = '
<style>
table {
    border-collapse: collapse;
    width: 100%;
    font-size: 9px;
}
td, th {
    border: 1px solid #000;
    padding: 2px;
}
.title {
    text-align:center;
    font-weight:bold;
    border:1px solid #000;
    font-size:11px;
}
.center { text-align:center; }
.right { text-align:right; }

.header-left {
    height:110px;
    vertical-align:top;
}

.inner-table td {
    height:18px;
}

/* column widths tuned EXACT */
.col-sl{ width:4%; }
.col-hsn{ width:11%; }
.col-desc{ width:32%; }
.col-pack{ width:6%; }
.col-qty{ width:6%; }
.col-total{ width:10%; }
.col-uom{ width:6%; }
.col-rate{ width:8%; }
.col-disc{ width:7%; }
.col-amt{ width:10%; }

.empty td {
    height:180px;
}

.small { font-size:8px; }
</style>

<!-- TITLE -->
<div class="title">ESTIMATE</div>

<!-- HEADER -->
<table>
<tr>
<td class="header-left" width="68%">
<b><u>Name & Address of Buyer:</u></b><br><br>
' . nl2br(htmlspecialchars($invoice['customer_name'] ?? '')) . '<br>
' . nl2br(htmlspecialchars($invoice['customer_address'] ?? '')) . '<br><br>
<b>Mobile No :</b> ' . htmlspecialchars($invoice['customer_phone'] ?? '') . '
</td>

<td width="32%">
<table class="inner-table">
<tr><td width="50%">Estimate No</td><td>: ' . htmlspecialchars($invoice['inv_num'] ?? '') . '</td></tr>
<tr><td>Date</td><td>: ' . (!empty($invoice['created_at']) ? date('d/m/Y', strtotime($invoice['created_at'])) : date('d/m/Y')) . '</td></tr>
<tr><td>Supply Through</td><td>: ' . htmlspecialchars($invoice['dispatch_through'] ?? '') . '</td></tr>
<tr><td>Pay Mode</td><td>: ' . getPaymentMethodDisplay($invoice) . '</td></tr>
<tr><td>Destination</td><td>: ' . getPlaceOfSupply($invoice) . '</td></tr>
<tr><td>Dispatch Through</td><td>: ' . htmlspecialchars($invoice['dispatch_through'] ?? '') . '</td></tr>
</table>
</td>
</tr>
</table>

<!-- ITEMS -->
<table>
<tr>
<th class="col-sl">Sl</th>
<th class="col-hsn">HSN Code</th>
<th class="col-desc">Product Name</th>
<th class="col-pack">Pack</th>
<th class="col-qty">Qty</th>
<th class="col-total">Tot Qty</th>
<th class="col-uom">UOM</th>
<th class="col-rate">Rate</th>
<th class="col-disc">Disc%</th>
<th class="col-amt">Amount</th>
</tr>';
    
    $sn = 1;
    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $pieces = (float)($item['no_of_pcs'] ?? 0);
        $rate = (float)($item['selling_price'] ?? 0);
        $total = (float)($item['total'] ?? 0);
        $disc = (float)($item['discount'] ?? 0);
        $hsn = htmlspecialchars($item['hsn'] ?? '-');
        $product_name = htmlspecialchars($item['product_name'] ?? '-');
        $unit = htmlspecialchars($item['unit'] ?? 'Nos');
        
        // Calculate pack size
        $pack = '';
        if ($pieces > 0 && $qty > 0) {
            $pack = round($pieces / $qty);
        }
        
        $html .= '
<tr>
<td class="center">' . $sn++ . '</td>
<td>' . $hsn . '</td>
<td>' . $product_name . '</td>
<td class="center">' . ($pack ?: '-') . '</td>
<td class="center">' . formatNum($qty) . '</td>
<td class="center">' . formatNum($pieces) . '</td>
<td class="center">' . $unit . '</td>
<td class="right">' . formatNum($rate, 2) . '</td>
<td class="center">' . ($disc > 0 ? formatNum($disc, 2) : '0.00') . '</td>
<td class="right">' . formatNum($total, 2) . '</td>
</tr>';
    }
    
    // Add single empty row for spacing
    $html .= '
<tr class="empty">
<td></td><td></td><td></td><td></td><td></td>
<td></td><td></td><td></td><td></td><td></td>
</tr>
</table>

<!-- TOTAL SECTION -->
<table>
<tr>
<td width="68%" class="small">
<u>Amount Chargeable in words:</u><br>
<b>' . strtoupper(numberToWords($total_amount)) . '</b>
</td>

<td width="32%">
<table>
<tr><td>Total Amount</td><td class="right">' . formatNum($total_amount, 2) . '</td></tr>
<tr><td>Freight Charge</td><td class="right">' . formatNum($freight_charge, 2) . '</td></tr>
<tr><td><b>Net Total</b></td><td class="right"><b>' . formatNum($total_invoice, 2) . '</b></td></tr>';
    
    if ($customer_opening_balance > 0) {
        $html .= '<tr><td>Old Balance</td><td class="right">' . formatNum($customer_opening_balance, 2) . '</td></tr>';
    }
    
    $html .= '<tr><td>Received Amt</td><td class="right">' . formatNum($cash_received, 2) . '</td></tr>
<tr><td><b>Balance Amount</b></td><td class="right"><b>' . formatNum($total_due, 2) . '</b></td></tr>
</table>
</td>
</tr>
</table>

<!-- FOOTER -->
<table>
<tr>
<td width="50%">Signature</td>
<td width="50%" class="center small">This is a Computer Generated Estimate</td>
</tr>
</table>
';
    
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output("Estimate_" . ($invoice['inv_num'] ?? 'TEMP') . ".pdf", "I");
}
?>