<?php

require_once('libs/tcpdf/tcpdf.php');

function formatNum($num,$dec=2){
return number_format($num,$dec,'.','');
}

/* -----------------------------
COMPANY
----------------------------- */

$company=[
"name"=>"SRI PLAAST",
"address"=>"No.10 Industrial Area\nDharmapuri\nTamil Nadu",
"phone"=>"7904448752",
"gst"=>"33ABCDE1234F1Z5",
"logo"=>"logo.png",
"bank"=>"UNION BANK OF INDIA",
"account"=>"759705010000003",
"branch"=>"HARUR",
"ifsc"=>"UBIN0575976"
];

/* -----------------------------
CUSTOMER
----------------------------- */

$customer=[
"name"=>"ABC TRADERS",
"address"=>"12 Market Street\nSalem\nTamil Nadu",
"shipping"=>"45 Transport Nagar\nSalem\nTamil Nadu",
"phone"=>"9876543210",
"gst"=>"33AAAPL1234C1Z9"
];

/* -----------------------------
INVOICE
----------------------------- */

$invoice=[
"no"=>"SP0001",
"date"=>"13/03/2026",
"eway"=>"",
"place"=>"TAMIL NADU (33)",
"dispatch"=>"",
"reference"=>"",
"freight"=>1200,
"subtotal"=>24856,
"cgst"=>2237.05,
"sgst"=>2237.05,
"igst"=>0
];

$invoice["total"]=$invoice["subtotal"]+$invoice["cgst"]+$invoice["sgst"]+$invoice["freight"];

/* -----------------------------
ITEMS
----------------------------- */

$items=[

["desc"=>"375 ML OIL BOTTLE (NORMAL)","hsn"=>"39233090","unit"=>"BAGS","qty"=>40,"pcs"=>8160,"rate"=>1.9492,"taxable"=>15905.47],
["desc"=>"800 ML OIL NORMAL","hsn"=>"39233090","unit"=>"BAGS","qty"=>14,"pcs"=>2268,"rate"=>2.8814,"taxable"=>6535.02],
["desc"=>"PET CAP","hsn"=>"39235010","unit"=>"PCS","qty"=>1,"pcs"=>11400,"rate"=>0.2119,"taxable"=>2415.66],
["desc"=>"500 ML PET BOTTLE","hsn"=>"39233090","unit"=>"BAGS","qty"=>20,"pcs"=>4000,"rate"=>2.1500,"taxable"=>8600],
["desc"=>"1 LTR OIL BOTTLE","hsn"=>"39233090","unit"=>"BAGS","qty"=>15,"pcs"=>3000,"rate"=>3.2500,"taxable"=>9750],
["desc"=>"2 LTR PET BOTTLE","hsn"=>"39233090","unit"=>"BAGS","qty"=>10,"pcs"=>1800,"rate"=>4.5000,"taxable"=>8100],
["desc"=>"250 ML WATER BOTTLE","hsn"=>"39233090","unit"=>"BAGS","qty"=>25,"pcs"=>5000,"rate"=>1.3500,"taxable"=>6750],
["desc"=>"5 LTR CAN CONTAINER","hsn"=>"39233090","unit"=>"PCS","qty"=>500,"pcs"=>500,"rate"=>12.75,"taxable"=>6375],
["desc"=>"PLASTIC HANDLE CAP","hsn"=>"39235010","unit"=>"PCS","qty"=>1,"pcs"=>8000,"rate"=>0.3200,"taxable"=>2560],
["desc"=>"28 MM BOTTLE CAP","hsn"=>"39235010","unit"=>"PCS","qty"=>1,"pcs"=>15000,"rate"=>0.1850,"taxable"=>2775]

];

$total_bags=0;
$total_pcs=0;
$total_taxable=0;

foreach($items as $i){
$total_bags+=$i["qty"];
$total_pcs+=$i["pcs"];
$total_taxable+=$i["taxable"];
}

/* -----------------------------
PDF
----------------------------- */

class PDF extends TCPDF{
public function Header(){}
public function Footer(){}
}

$pdf=new PDF('P','mm','A4',true,'UTF-8',false);
$pdf->SetMargins(3,3,3);
$pdf->SetAutoPageBreak(true,2);
$pdf->SetFont('freeserif','',9);
$pdf->AddPage();

/* -----------------------------
HEADER
----------------------------- */

$html='

<table border="1" cellpadding="4">

<tr>
<td colspan="2" align="center" style="font-size:16px;"><b>TAX INVOICE</b></td>
</tr>

<tr>
<td width="70%"></td>
<td width="30%" align="right"><b>[ORIGINAL FOR RECIPIENT]</b></td>
</tr>

</table>

<table border="1" cellpadding="4">

<tr>

<td width="50%">

<table border="0">

<tr>

<td width="20%">';

if(file_exists($company["logo"])){
$html.='<img src="'.$company["logo"].'" height="45">';
}

$html.='</td>

<td width="80%">

<b style="font-size:16px">'.$company["name"].'</b><br>

'.nl2br($company["address"]).'<br>

<b>Cell :</b> '.$company["phone"].'<br>

<b>GSTIN :</b> '.$company["gst"].'

</td>

</tr>

</table>

</td>

<td width="50%">

<table border="1" cellpadding="3">

<tr>
<td width="50%"><b>Invoice No :</b><br>'.$invoice["no"].'</td>
<td width="50%"><b>Date :</b><br>'.$invoice["date"].'</td>
</tr>

<tr>
<td><b>E-way Bill No :</b><br>'.$invoice["eway"].'</td>
<td><b>Place of Supply :</b><br>'.$invoice["place"].'</td>
</tr>

<tr>
<td><b>Dispatched Through :</b><br>'.$invoice["dispatch"].'</td>
<td><b>Other Reference :</b><br>'.$invoice["reference"].'</td>
</tr>

</table>

</td>

</tr>

</table>

';

/* -----------------------------
BILLING + SHIPPING
----------------------------- */

$html.='

<table border="1" cellpadding="4">

<tr>

<td width="50%">

<b>Buyer (Billing To)</b><br>

'.$customer["name"].'<br>

'.nl2br($customer["address"]).'<br>

<b>Cell :</b> '.$customer["phone"].'<br>

<b>GSTIN :</b> '.$customer["gst"].'<br>

<b>State Name :</b> TAMIL NADU &nbsp; <b>State Code :</b> 33

</td>

<td width="50%">

<b>Consignee (Shipping To)</b><br>

'.$customer["name"].'<br>

'.nl2br($customer["shipping"]).'<br>

<b>State Name :</b> TAMIL NADU &nbsp; <b>State Code :</b> 33

</td>

</tr>

</table>

';

/* -----------------------------
ITEM TABLE (with no top/bottom borders)
----------------------------- */

$html.='

<style>
.no-top-border {
    border-top: none !important;
}
.no-bottom-border {
    border-bottom: none !important;
}
</style>

<table border="1" cellpadding="4" style="border-collapse:collapse;">

<tr style="background:#e0e0e0; font-weight:bold;">
<th width="5%">S.No</th>
<th width="35%">DESCRIPTION</th>
<th width="10%">HSN</th>
<th width="10%">UNIT</th>
<th width="8%">QTY</th>
<th width="8%">PCS</th>
<th width="10%">RATE</th>
<th width="14%">TAXABLE</th>
</tr>

';

$sn=1;
$row_count = count($items);
$current_row = 0;

foreach($items as $i){
$current_row++;
$row_class = '';
if($current_row == 1) {
    $row_class = ' class="no-top-border"';
} elseif($current_row == $row_count) {
    $row_class = ' class="no-bottom-border"';
}

$html.='

<tr'.$row_class.'>

<td align="center">'.$sn++.'</td>
<td>'.$i["desc"].'</td>
<td align="center">'.$i["hsn"].'</td>
<td align="center">'.$i["unit"].'</td>
<td align="center">'.formatNum($i["qty"]).'</td>
<td align="center">'.formatNum($i["pcs"]).'</td>
<td align="right">&#8377; '.formatNum($i["rate"],4).'</td>
<td align="right">&#8377; '.formatNum($i["taxable"]).'</td>

</tr>

';

}

$html.='

<tr style="background:#f0f0f0;">

<td colspan="4" align="right"><b>Total</b></td>

<td align="center">'.formatNum($total_bags).'</td>

<td align="center">'.formatNum($total_pcs).'</td>

<td></td>

<td align="right"><b>&#8377; '.formatNum($total_taxable).'</b></td>

</tr>

</table>

';

/* -----------------------------
BOTTOM SECTION
----------------------------- */

$html.='

<table border="1" cellpadding="4">

<tr>

<td width="55%">

<b>TAXATION</b>

<table border="1" cellpadding="4">

<tr style="background:#e0e0e0; font-weight:bold;">
<th>CGST %</th>
<th>Amount</th>
<th>SGST %</th>
<th>Amount</th>
<th>IGST %</th>
<th>Amount</th>
</tr>

<tr>
<td align="center">9%</td>
<td align="right">&#8377; '.formatNum($invoice["cgst"]).'</td>
<td align="center">9%</td>
<td align="right">&#8377; '.formatNum($invoice["sgst"]).'</td>
<td align="center">0%</td>
<td align="right">&#8377; '.formatNum($invoice["igst"]).'</td>
</tr>

</table>

<br>

<b>Bank Details :</b>

<table>

<tr>
<td width="25%"><b>Bank Name</b></td>
<td width="25%">: '.$company["bank"].'</td>
<td width="25%"><b>Account No</b></td>
<td width="25%">: '.$company["account"].'</td>
</tr>

<tr>
<td><b>Branch</b></td>
<td>: '.$company["branch"].'</td>
<td><b>IFSC</b></td>
<td>: '.$company["ifsc"].'</td>
</tr>

</table>

</td>

<td width="45%">

<div><b>Freight Charges :</b> <span style="float:right">&#8377; '.formatNum($invoice["freight"]).'</span></div>

<div><b>Subtotal :</b> <span style="float:right">&#8377; '.formatNum($invoice["subtotal"]).'</span></div>

<div><b>Tax :</b> <span style="float:right">&#8377; '.formatNum($invoice["cgst"]+$invoice["sgst"]).'</span></div>

<hr>

<div style="font-size:12px"><b>Net Total :</b> <span style="float:right">&#8377; '.formatNum($invoice["total"]).'</span></div>

<br><br>

<center>

<b>For '.$company["name"].'</b>

<br><br><br>

<b>Authorised Signatory</b>

</center>

</td>

</tr>

</table>

<div style="text-align:center;border:1px solid #000;font-size:8px;padding:5px;">

This is a Computer Generated Invoice

</div>

';

$pdf->writeHTML($html,true,false,true,false,'');

$pdf->Output("demo_invoice.pdf","I");

?>