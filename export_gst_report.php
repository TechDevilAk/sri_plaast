<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin and sale can export
checkRoleAccess(['admin', 'sale']);

// Get parameters from URL
$reportType = $_GET['report_type'] ?? 'sales';
$filterDateFrom = $_GET['date_from'] ?? date('Y-m-01');
$filterDateTo = $_GET['date_to'] ?? date('Y-m-d');
$filterGstOnly = isset($_GET['gst_only']) ? (int)$_GET['gst_only'] : 1;
$filterCustomer = $_GET['customer_id'] ?? '';
$filterSupplier = $_GET['supplier_id'] ?? '';

// Date validation
if (strtotime($filterDateFrom) > strtotime($filterDateTo)) {
    $filterDateTo = $filterDateFrom;
}

// Set headers for Excel download
$filename = "gst_report_{$reportType}_" . date('Y-m-d') . ".xls";
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Start output buffer
ob_start();

// Helper functions
function formatCurrency($amount) {
    return '₹' . number_format((float)$amount, 2);
}

function formatNumber($number, $decimals = 2) {
    return number_format((float)$number, $decimals);
}

// Build date condition for queries
$dateCondition = "DATE(i.created_at) BETWEEN ? AND ?";
$dateParams = [$filterDateFrom, $filterDateTo];
$dateTypes = "ss";

// Get data based on report type
$data = [];
$headers = [];
$title = '';

if ($reportType === 'sales') {
    $title = "Sales GST Report";
    $headers = ['Date', 'Invoice #', 'Customer', 'GSTIN', 'Taxable Value', 'CGST', 'SGST', 'Total GST', 'Invoice Total'];
    
    $salesWhere = "1=1 AND COALESCE(i.is_gst,1) = 1";
    if (!$filterGstOnly) {
        $salesWhere = "1=1";
    }
    
    $salesParams = $dateParams;
    $salesTypes = $dateTypes;
    
    if (!empty($filterCustomer)) {
        $salesWhere .= " AND i.customer_id = ?";
        $salesParams[] = (int)$filterCustomer;
        $salesTypes .= "i";
    }
    
    $sql = "SELECT 
                i.created_at,
                i.inv_num,
                COALESCE(c.customer_name, i.customer_name, 'Walk-in Customer') as customer_name,
                c.gst_number,
                i.taxable,
                i.cgst_amount,
                i.sgst_amount,
                (i.cgst_amount + i.sgst_amount) as total_gst,
                i.total,
                i.is_gst
            FROM invoice i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE $salesWhere AND $dateCondition
            ORDER BY i.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($salesTypes, ...$salesParams);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if ($filterGstOnly && (int)$row['is_gst'] !== 1) continue;
        $data[] = [
            date('d-m-Y', strtotime($row['created_at'])),
            $row['inv_num'],
            $row['customer_name'],
            $row['gst_number'] ?? '-',
            $row['taxable'],
            $row['cgst_amount'],
            $row['sgst_amount'],
            $row['total_gst'],
            $row['total']
        ];
    }
    $stmt->close();
    
} elseif ($reportType === 'purchase') {
    $title = "Purchase GST Report";
    $headers = ['Date', 'Purchase #', 'Supplier', 'Supplier GST', 'Taxable Value', 'CGST', 'SGST', 'Total GST', 'Purchase Total'];
    
    $purchaseWhere = "1=1";
    $purchaseParams = $dateParams;
    $purchaseTypes = $dateTypes;
    
    if (!empty($filterSupplier)) {
        $purchaseWhere .= " AND p.supplier_id = ?";
        $purchaseParams[] = (int)$filterSupplier;
        $purchaseTypes .= "i";
    }
    
    $sql = "SELECT 
                p.purchase_date,
                p.purchase_no,
                s.supplier_name,
                s.gst_number as supplier_gst,
                p.total,
                p.cgst_amount,
                p.sgst_amount,
                (p.cgst_amount + p.sgst_amount) as total_gst
            FROM purchase p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            WHERE $purchaseWhere AND DATE(p.purchase_date) BETWEEN ? AND ?
            ORDER BY p.purchase_date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($purchaseTypes, ...$purchaseParams);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $taxable = (float)$row['total'] - ((float)$row['cgst_amount'] + (float)$row['sgst_amount']);
        $data[] = [
            date('d-m-Y', strtotime($row['purchase_date'])),
            $row['purchase_no'],
            $row['supplier_name'] ?? 'N/A',
            $row['supplier_gst'] ?? '-',
            $taxable,
            $row['cgst_amount'],
            $row['sgst_amount'],
            $row['total_gst'],
            $row['total']
        ];
    }
    $stmt->close();
    
} elseif ($reportType === 'gst_credit') {
    $title = "GST Credit Report (Input Tax Credit)";
    $headers = ['Date', 'Purchase #', 'Supplier', 'CGST Credit', 'SGST Credit', 'Total Credit'];
    
    $sql = "SELECT 
                p.purchase_date,
                p.purchase_no,
                s.supplier_name,
                gct.cgst,
                gct.sgst,
                gct.total_credit
            FROM gst_credit_table gct
            INNER JOIN purchase p ON gct.purchase_id = p.id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            WHERE DATE(p.purchase_date) BETWEEN ? AND ?
            ORDER BY p.purchase_date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            date('d-m-Y', strtotime($row['purchase_date'])),
            $row['purchase_no'],
            $row['supplier_name'] ?? 'N/A',
            $row['cgst'],
            $row['sgst'],
            $row['total_credit']
        ];
    }
    $stmt->close();
    
} elseif ($reportType === 'hsn_summary') {
    $title = "HSN-wise Summary Report";
    $headers = [
        'HSN Code',
        'Sales Invoices', 'Sales Qty', 'Sales Taxable', 'Sales CGST', 'Sales SGST',
        'Purchase Invoices', 'Purchase Qty', 'Purchase Taxable', 'Purchase CGST', 'Purchase SGST',
        'Net Taxable', 'Net GST'
    ];
    
    // Get sales by HSN
    $hsnSalesSql = "SELECT 
                        ii.hsn,
                        COUNT(DISTINCT i.id) as invoice_count,
                        SUM(ii.quantity) as total_qty,
                        SUM(ii.taxable) as total_taxable,
                        SUM(ii.cgst_amount) as total_cgst,
                        SUM(ii.sgst_amount) as total_sgst,
                        SUM(ii.total) as total_amount
                    FROM invoice_item ii
                    INNER JOIN invoice i ON ii.invoice_id = i.id
                    WHERE i.is_gst = 1 AND DATE(i.created_at) BETWEEN ? AND ?
                    GROUP BY ii.hsn
                    ORDER BY ii.hsn";
    
    $stmt = $conn->prepare($hsnSalesSql);
    $stmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $stmt->execute();
    $hsnSalesResult = $stmt->get_result();
    
    // Get purchases by HSN
    $hsnPurchaseSql = "SELECT 
                            pi.hsn,
                            COUNT(DISTINCT p.id) as purchase_count,
                            SUM(pi.qty) as total_qty,
                            SUM(pi.taxable) as total_taxable,
                            SUM(pi.cgst_amount) as total_cgst,
                            SUM(pi.sgst_amount) as total_sgst,
                            SUM(pi.total) as total_amount
                        FROM purchase_item pi
                        INNER JOIN purchase p ON pi.purchase_id = p.id
                        WHERE DATE(p.purchase_date) BETWEEN ? AND ?
                        GROUP BY pi.hsn
                        ORDER BY pi.hsn";
    
    $stmt = $conn->prepare($hsnPurchaseSql);
    $stmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $stmt->execute();
    $hsnPurchaseResult = $stmt->get_result();
    $stmt->close();
    
    // Combine HSN data
    $hsnMap = [];
    
    while ($row = $hsnSalesResult->fetch_assoc()) {
        $hsnMap[$row['hsn']] = [
            'hsn' => $row['hsn'],
            'sales_invoices' => $row['invoice_count'],
            'sales_qty' => $row['total_qty'],
            'sales_taxable' => $row['total_taxable'],
            'sales_cgst' => $row['total_cgst'],
            'sales_sgst' => $row['total_sgst'],
            'sales_total' => $row['total_amount'],
            'purchase_invoices' => 0,
            'purchase_qty' => 0,
            'purchase_taxable' => 0,
            'purchase_cgst' => 0,
            'purchase_sgst' => 0,
            'purchase_total' => 0
        ];
    }
    
    while ($row = $hsnPurchaseResult->fetch_assoc()) {
        if (isset($hsnMap[$row['hsn']])) {
            $hsnMap[$row['hsn']]['purchase_invoices'] = $row['purchase_count'];
            $hsnMap[$row['hsn']]['purchase_qty'] = $row['total_qty'];
            $hsnMap[$row['hsn']]['purchase_taxable'] = $row['total_taxable'];
            $hsnMap[$row['hsn']]['purchase_cgst'] = $row['total_cgst'];
            $hsnMap[$row['hsn']]['purchase_sgst'] = $row['total_sgst'];
            $hsnMap[$row['hsn']]['purchase_total'] = $row['total_amount'];
        } else {
            $hsnMap[$row['hsn']] = [
                'hsn' => $row['hsn'],
                'sales_invoices' => 0,
                'sales_qty' => 0,
                'sales_taxable' => 0,
                'sales_cgst' => 0,
                'sales_sgst' => 0,
                'sales_total' => 0,
                'purchase_invoices' => $row['purchase_count'],
                'purchase_qty' => $row['total_qty'],
                'purchase_taxable' => $row['total_taxable'],
                'purchase_cgst' => $row['total_cgst'],
                'purchase_sgst' => $row['total_sgst'],
                'purchase_total' => $row['total_amount']
            ];
        }
    }
    
    foreach ($hsnMap as $hsn => $row) {
        $netTaxable = (float)$row['sales_taxable'] - (float)$row['purchase_taxable'];
        $netGST = ((float)$row['sales_cgst'] + (float)$row['sales_sgst']) - ((float)$row['purchase_cgst'] + (float)$row['purchase_sgst']);
        
        $data[] = [
            $hsn ?: 'N/A',
            $row['sales_invoices'],
            formatNumber($row['sales_qty'], 0),
            $row['sales_taxable'],
            $row['sales_cgst'],
            $row['sales_sgst'],
            $row['purchase_invoices'],
            formatNumber($row['purchase_qty'], 0),
            $row['purchase_taxable'],
            $row['purchase_cgst'],
            $row['purchase_sgst'],
            $netTaxable,
            $netGST
        ];
    }
    
} elseif ($reportType === 'summary') {
    $title = "GST Complete Summary Report";
    $headers = ['Section', 'Metric', 'Value'];
    
    // Calculate totals
    $totalSales = 0;
    $totalTaxable = 0;
    $totalCGST = 0;
    $totalSGST = 0;
    $totalPurchases = 0;
    $purchaseCGST = 0;
    $purchaseSGST = 0;
    
    // Get sales totals
    $salesSql = "SELECT 
                    SUM(i.total) as total_sales,
                    SUM(i.taxable) as total_taxable,
                    SUM(i.cgst_amount) as total_cgst,
                    SUM(i.sgst_amount) as total_sgst
                FROM invoice i
                WHERE i.is_gst = 1 AND DATE(i.created_at) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($salesSql);
    $stmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $stmt->execute();
    $salesTotals = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Get purchase totals
    $purchaseSql = "SELECT 
                        SUM(p.total) as total_purchases,
                        SUM(p.cgst_amount) as total_cgst,
                        SUM(p.sgst_amount) as total_sgst
                    FROM purchase p
                    WHERE DATE(p.purchase_date) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($purchaseSql);
    $stmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $stmt->execute();
    $purchaseTotals = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Get invoice counts
    $countSql = "SELECT 
                    COUNT(*) as total_invoices,
                    SUM(CASE WHEN is_gst = 1 THEN 1 ELSE 0 END) as gst_invoices,
                    SUM(CASE WHEN is_gst = 0 THEN 1 ELSE 0 END) as non_gst_invoices
                FROM invoice
                WHERE DATE(created_at) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($countSql);
    $stmt->bind_param("ss", $filterDateFrom, $filterDateTo);
    $stmt->execute();
    $counts = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Build summary data
    $data = [
        ['Invoice Summary', 'Total Invoices', $counts['total_invoices'] ?? 0],
        ['Invoice Summary', 'GST Invoices', $counts['gst_invoices'] ?? 0],
        ['Invoice Summary', 'Non-GST Invoices', $counts['non_gst_invoices'] ?? 0],
        ['', '', ''],
        ['Sales GST', 'Total Sales', $salesTotals['total_sales'] ?? 0],
        ['Sales GST', 'Taxable Value', $salesTotals['total_taxable'] ?? 0],
        ['Sales GST', 'CGST', $salesTotals['total_cgst'] ?? 0],
        ['Sales GST', 'SGST', $salesTotals['total_sgst'] ?? 0],
        ['Sales GST', 'Total Output GST', ($salesTotals['total_cgst'] ?? 0) + ($salesTotals['total_sgst'] ?? 0)],
        ['', '', ''],
        ['Purchase GST', 'Total Purchases', $purchaseTotals['total_purchases'] ?? 0],
        ['Purchase GST', 'CGST Credit', $purchaseTotals['total_cgst'] ?? 0],
        ['Purchase GST', 'SGST Credit', $purchaseTotals['total_sgst'] ?? 0],
        ['Purchase GST', 'Total Input Credit', ($purchaseTotals['total_cgst'] ?? 0) + ($purchaseTotals['total_sgst'] ?? 0)],
        ['', '', ''],
        ['Net Liability', 'Net GST Payable/Refundable', 
            (($salesTotals['total_cgst'] ?? 0) + ($salesTotals['total_sgst'] ?? 0)) - 
            (($purchaseTotals['total_cgst'] ?? 0) + ($purchaseTotals['total_sgst'] ?? 0))
        ]
    ];
}

// Output Excel file
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>' . $title . '</title>';
echo '<style>';
echo 'th { background-color: #f2f2f2; font-weight: bold; text-align: center; }';
echo 'td { text-align: left; }';
echo '.amount { text-align: right; }';
echo '.header { font-size: 16px; font-weight: bold; text-align: center; }';
echo '.subheader { font-size: 12px; text-align: center; color: #666; }';
echo '</style>';
echo '</head>';
echo '<body>';

// Report Header
echo '<table border="1" cellpadding="5" cellspacing="0" width="100%">';
echo '<tr><td colspan="' . count($headers) . '" class="header">' . $title . '</td></tr>';
echo '<tr><td colspan="' . count($headers) . '" class="subheader">';
echo 'Period: ' . date('d-m-Y', strtotime($filterDateFrom)) . ' to ' . date('d-m-Y', strtotime($filterDateTo));
echo '</td></tr>';
echo '<tr><td colspan="' . count($headers) . '" class="subheader">';
echo 'Generated on: ' . date('d-m-Y H:i:s');
echo '</td></tr>';
echo '<tr><td colspan="' . count($headers) . '"></td></tr>';

// Headers
echo '<tr>';
foreach ($headers as $header) {
    echo '<th>' . $header . '</th>';
}
echo '</tr>';

// Data rows
$totals = array_fill(0, count($headers), 0);
$numericColumns = [];

if ($reportType === 'sales') {
    $numericColumns = [4, 5, 6, 7, 8]; // Taxable, CGST, SGST, Total GST, Invoice Total
} elseif ($reportType === 'purchase') {
    $numericColumns = [4, 5, 6, 7, 8]; // Taxable, CGST, SGST, Total GST, Purchase Total
} elseif ($reportType === 'gst_credit') {
    $numericColumns = [3, 4, 5]; // CGST Credit, SGST Credit, Total Credit
} elseif ($reportType === 'hsn_summary') {
    $numericColumns = [3, 4, 5, 8, 9, 10, 11, 12]; // All amount columns
}

foreach ($data as $row) {
    echo '<tr>';
    foreach ($row as $index => $cell) {
        $isNumeric = is_numeric($cell) && !is_string($cell) && !str_starts_with((string)$cell, '0');
        $class = $isNumeric ? 'amount' : '';
        
        // Add to totals if numeric column
        if ($isNumeric && in_array($index, $numericColumns)) {
            $totals[$index] += (float)$cell;
        }
        
        // Format currency if it's a numeric amount
        if ($isNumeric && in_array($index, $numericColumns)) {
            echo '<td class="' . $class . '">' . formatCurrency($cell) . '</td>';
        } elseif ($isNumeric) {
            echo '<td class="' . $class . '">' . formatNumber($cell) . '</td>';
        } else {
            echo '<td>' . htmlspecialchars($cell) . '</td>';
        }
    }
    echo '</tr>';
}

// Totals row for relevant reports
if (!empty($data) && $reportType !== 'summary') {
    echo '<tr style="background-color: #e6e6e6; font-weight: bold;">';
    foreach ($headers as $index => $header) {
        if ($index === 0) {
            echo '<td><strong>TOTAL</strong></td>';
        } elseif (in_array($index, $numericColumns)) {
            echo '<td class="amount"><strong>' . formatCurrency($totals[$index]) . '</strong></td>';
        } else {
            echo '<td></td>';
        }
    }
    echo '</tr>';
}

echo '</table>';
echo '</body>';
echo '</html>';

// Clear output buffer
ob_end_flush();
?>