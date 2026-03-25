<?php
// export-purchases.php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can export purchases
checkRoleAccess(['admin']);

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="purchases_export_' . date('Y-m-d_His') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$supplier_filter = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$gst_type_filter = isset($_GET['gst_type']) ? $_GET['gst_type'] : '';

// Build WHERE clause (same as in purchases.php)
$where = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(p.purchase_no LIKE ? OR s.supplier_name LIKE ? OR p.invoice_num LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

if ($supplier_filter > 0) {
    $where[] = "p.supplier_id = ?";
    $params[] = $supplier_filter;
    $types .= "i";
}

if (!empty($status_filter)) {
    if ($status_filter === 'paid') {
        $where[] = "p.paid_amount >= p.total";
    } elseif ($status_filter === 'partial') {
        $where[] = "p.paid_amount > 0 AND p.paid_amount < p.total";
    } elseif ($status_filter === 'unpaid') {
        $where[] = "(p.paid_amount IS NULL OR p.paid_amount = 0)";
    }
}

if (!empty($gst_type_filter)) {
    $where[] = "p.gst_type = ?";
    $params[] = $gst_type_filter;
    $types .= "s";
}

if (!empty($from_date)) {
    $where[] = "DATE(p.purchase_date) >= ?";
    $params[] = $from_date;
    $types .= "s";
}

if (!empty($to_date)) {
    $where[] = "DATE(p.purchase_date) <= ?";
    $params[] = $to_date;
    $types .= "s";
}

$where_clause = implode(" AND ", $where);

// Get all purchases for export (no pagination limit)
$sql = "SELECT 
            p.purchase_no,
            p.purchase_date,
            s.supplier_name,
            s.phone as supplier_phone,
            s.gst_number as supplier_gst,
            p.invoice_num as supplier_invoice,
            p.gst_type,
            p.cgst,
            p.sgst,
            p.cgst_amount,
            p.sgst_amount,
            p.total,
            (SELECT SUM(paid_amount) FROM purchase_payment_history WHERE purchase_id = p.id) as total_paid,
            (SELECT COUNT(*) FROM purchase_item WHERE purchase_id = p.id) as item_count,
            p.created_at
        FROM purchase p 
        LEFT JOIN suppliers s ON p.supplier_id = s.id 
        WHERE $where_clause 
        ORDER BY p.purchase_date DESC, p.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$purchases = $stmt->get_result();

// Helper function for payment status
function getPaymentStatus($total, $paid) {
    if ($paid >= $total) {
        return 'Paid';
    } elseif ($paid > 0) {
        return 'Partial';
    } else {
        return 'Unpaid';
    }
}

// Calculate summary statistics
$total_amount = 0;
$total_gst = 0;
$total_paid = 0;
$total_pending = 0;

// Start output - using HTML table format for Excel
echo '<html>';
echo '<head>';
echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
echo '<style>';
echo 'th { background-color: #4CAF50; color: white; font-weight: bold; }';
echo '.paid { background-color: #d4edda; }';
echo '.partial { background-color: #fff3cd; }';
echo '.unpaid { background-color: #f8d7da; }';
echo '</style>';
echo '</head>';
echo '<body>';

// Company Info Header
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
echo '<tr><td colspan="13" style="background-color: #333; color: white; font-size: 18px; text-align: center;">';
echo 'PURCHASES EXPORT REPORT</td></tr>';

// Filter Info
echo '<tr><td colspan="13" style="background-color: #f0f0f0;">';
echo '<strong>Filters Applied:</strong><br>';
if (!empty($search)) echo 'Search: ' . htmlspecialchars($search) . '<br>';
if (!empty($supplier_filter)) {
    $sup_sql = "SELECT supplier_name FROM suppliers WHERE id = ?";
    $sup_stmt = $conn->prepare($sup_sql);
    $sup_stmt->bind_param("i", $supplier_filter);
    $sup_stmt->execute();
    $sup_result = $sup_stmt->get_result();
    $supplier = $sup_result->fetch_assoc();
    echo 'Supplier: ' . htmlspecialchars($supplier['supplier_name']) . '<br>';
}
if (!empty($status_filter)) echo 'Status: ' . ucfirst($status_filter) . '<br>';
if (!empty($gst_type_filter)) echo 'GST Type: ' . ucfirst($gst_type_filter) . '<br>';
if (!empty($from_date) && !empty($to_date)) echo 'Date Range: ' . $from_date . ' to ' . $to_date . '<br>';
echo 'Export Date: ' . date('d-m-Y H:i:s');
echo '</td></tr>';

// Summary Row
echo '<tr><td colspan="13" style="background-color: #e8f4f8;">';
echo '<strong>Summary:</strong> ';
echo 'Total Records: ' . $purchases->num_rows . ' | ';
// Reset data to calculate summary
if ($purchases->num_rows > 0) {
    $purchases->data_seek(0);
    while ($row = $purchases->fetch_assoc()) {
        $paid = floatval($row['total_paid'] ?? 0);
        $total_amount += $row['total'];
        $total_gst += ($row['cgst_amount'] + $row['sgst_amount']);
        $total_paid += $paid;
    }
    $total_pending = $total_amount - $total_paid;
    $purchases->data_seek(0);
}
echo 'Total Amount: ₹' . number_format($total_amount, 2) . ' | ';
echo 'Total GST: ₹' . number_format($total_gst, 2) . ' | ';
echo 'Total Paid: ₹' . number_format($total_paid, 2) . ' | ';
echo 'Total Pending: ₹' . number_format($total_pending, 2);
echo '</td></tr>';
echo '</table>';

echo '<br>';

// Main Data Table
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';

// Table Headers
echo '<thead>';
echo '<tr style="background-color: #4CAF50; color: white;">';
echo '<th>S.No</th>';
echo '<th>Purchase Date</th>';
echo '<th>Purchase No</th>';
echo '<th>Supplier Name</th>';
echo '<th>Supplier Phone</th>';
echo '<th>Supplier GST</th>';
echo '<th>Supplier Invoice</th>';
echo '<th>GST Type</th>';
echo '<th>Items</th>';
echo '<th>CGST (%)</th>';
echo '<th>SGST (%)</th>';
echo '<th>CGST Amt</th>';
echo '<th>SGST Amt</th>';
echo '<th>Taxable Value</th>';
echo '<th>Total Amount</th>';
echo '<th>Paid Amount</th>';
echo '<th>Pending Amount</th>';
echo '<th>Payment Status</th>';
echo '<th>Created At</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

if ($purchases && $purchases->num_rows > 0) {
    $sno = 1;
    while ($row = $purchases->fetch_assoc()) {
        $paid = floatval($row['total_paid'] ?? 0);
        $pending = $row['total'] - $paid;
        $gst_total = $row['cgst_amount'] + $row['sgst_amount'];
        $taxable = $row['total'] - $gst_total;
        $status = getPaymentStatus($row['total'], $paid);
        
        // Add row class based on status
        $row_class = '';
        if ($status == 'Paid') $row_class = 'paid';
        elseif ($status == 'Partial') $row_class = 'partial';
        elseif ($status == 'Unpaid') $row_class = 'unpaid';
        
        echo '<tr class="' . $row_class . '">';
        echo '<td>' . $sno++ . '</td>';
        echo '<td>' . date('d-m-Y', strtotime($row['purchase_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($row['purchase_no']) . '</td>';
        echo '<td>' . htmlspecialchars($row['supplier_name'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($row['supplier_phone'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($row['supplier_gst'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($row['supplier_invoice'] ?? 'N/A') . '</td>';
        echo '<td>' . ucfirst(htmlspecialchars($row['gst_type'] ?? 'N/A')) . '</td>';
        echo '<td style="text-align: center;">' . $row['item_count'] . '</td>';
        echo '<td style="text-align: right;">' . ($row['cgst'] ? number_format($row['cgst'], 2) . '%' : '0.00%') . '</td>';
        echo '<td style="text-align: right;">' . ($row['sgst'] ? number_format($row['sgst'], 2) . '%' : '0.00%') . '</td>';
        echo '<td style="text-align: right;">₹' . number_format($row['cgst_amount'] ?? 0, 2) . '</td>';
        echo '<td style="text-align: right;">₹' . number_format($row['sgst_amount'] ?? 0, 2) . '</td>';
        echo '<td style="text-align: right;">₹' . number_format($taxable, 2) . '</td>';
        echo '<td style="text-align: right; font-weight: bold;">₹' . number_format($row['total'], 2) . '</td>';
        echo '<td style="text-align: right;">₹' . number_format($paid, 2) . '</td>';
        echo '<td style="text-align: right;">₹' . number_format($pending, 2) . '</td>';
        echo '<td style="text-align: center;">' . $status . '</td>';
        echo '<td>' . date('d-m-Y H:i:s', strtotime($row['created_at'])) . '</td>';
        echo '</tr>';
    }
    
    // Summary Row at bottom
    echo '<tr style="background-color: #e8f4f8; font-weight: bold;">';
    echo '<td colspan="12" style="text-align: right;">Totals:</td>';
    echo '<td style="text-align: right;">₹' . number_format($total_gst, 2) . '</td>';
    echo '<td style="text-align: right;">₹' . number_format($total_amount - $total_gst, 2) . '</td>';
    echo '<td style="text-align: right;">₹' . number_format($total_amount, 2) . '</td>';
    echo '<td style="text-align: right;">₹' . number_format($total_paid, 2) . '</td>';
    echo '<td style="text-align: right;">₹' . number_format($total_pending, 2) . '</td>';
    echo '<td colspan="2"></td>';
    echo '</tr>';
    
} else {
    echo '<tr><td colspan="19" style="text-align: center; padding: 20px;">No records found matching the criteria</td></tr>';
}

echo '</tbody>';
echo '</table>';

// Additional Summary Statistics
echo '<br>';
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
echo '<tr><th colspan="2" style="background-color: #2196F3; color: white;">Payment Summary</th></tr>';

// Count by status
$status_counts = [
    'paid' => 0,
    'partial' => 0,
    'unpaid' => 0
];

if ($purchases->num_rows > 0) {
    $purchases->data_seek(0);
    while ($row = $purchases->fetch_assoc()) {
        $paid = floatval($row['total_paid'] ?? 0);
        if ($paid >= $row['total']) {
            $status_counts['paid']++;
        } elseif ($paid > 0) {
            $status_counts['partial']++;
        } else {
            $status_counts['unpaid']++;
        }
    }
}

echo '<tr><td>Paid Purchases</td><td>' . $status_counts['paid'] . '</td></tr>';
echo '<tr><td>Partial Paid</td><td>' . $status_counts['partial'] . '</td></tr>';
echo '<tr><td>Unpaid Purchases</td><td>' . $status_counts['unpaid'] . '</td></tr>';
echo '<tr><td><strong>Total Purchases</strong></td><td><strong>' . $purchases->num_rows . '</strong></td></tr>';
echo '</table>';

echo '</body>';
echo '</html>';
?>