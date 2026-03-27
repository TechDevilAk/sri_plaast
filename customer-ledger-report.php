<?php
session_start();
$currentPage = 'customer-ledger-report';
$pageTitle = 'Customer Ledger Report';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Get filter parameters
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Handle manual export requests
if ($export && $customer_id > 0) {
    exportLedgerData($conn, $customer_id, $from_date, $to_date, $export);
}

function exportLedgerData($conn, $customer_id, $from_date, $to_date, $format) {
    // Get customer details
    $customer_query = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $customer_query->bind_param("i", $customer_id);
    $customer_query->execute();
    $customer = $customer_query->get_result()->fetch_assoc();
    
    if (!$customer) {
        return;
    }
    
    // Calculate opening balance
    $opening_query = "
        SELECT 
            COALESCE(SUM(total), 0) as total_invoiced_before,
            COALESCE(SUM(cash_received), 0) as total_paid_before
        FROM invoice 
        WHERE customer_id = ? AND DATE(created_at) < ?
    ";
    $opening_stmt = $conn->prepare($opening_query);
    $opening_stmt->bind_param("is", $customer_id, $from_date);
    $opening_stmt->execute();
    $opening_data = $opening_stmt->get_result()->fetch_assoc();
    $opening_balance = $customer['opening_balance'] + ($opening_data['total_invoiced_before'] - $opening_data['total_paid_before']);
    
    // Get all invoices for the period
    $invoices_query = $conn->prepare("
        SELECT * FROM invoice 
        WHERE customer_id = ? AND DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at ASC
    ");
    $invoices_query->bind_param("iss", $customer_id, $from_date, $to_date);
    $invoices_query->execute();
    $invoices = $invoices_query->get_result();
    
    // Build transactions
    $transactions = [];
    while ($invoice = $invoices->fetch_assoc()) {
        // Add invoice as debit
        $transactions[] = [
            'date' => $invoice['created_at'],
            'ref' => $invoice['inv_num'],
            'description' => 'Invoice #' . $invoice['inv_num'],
            'debit' => $invoice['total'],
            'credit' => 0,
            'type' => 'invoice',
            'payment_method' => $invoice['payment_method']
        ];
        
        // Add payment as credit if any
        if ($invoice['cash_received'] > 0) {
            $payment_desc = 'Payment received - ' . ucfirst($invoice['payment_method']);
            if ($invoice['cash_received'] < $invoice['total']) {
                $payment_desc .= ' (Partial)';
            }
            
            $transactions[] = [
                'date' => $invoice['created_at'],
                'ref' => $invoice['inv_num'],
                'description' => $payment_desc,
                'debit' => 0,
                'credit' => $invoice['cash_received'],
                'type' => 'payment',
                'payment_method' => $invoice['payment_method']
            ];
        }
    }
    
    // Sort transactions by date
    usort($transactions, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
    
    // Calculate period totals
    $period_invoiced = 0;
    $period_paid = 0;
    foreach ($transactions as $t) {
        $period_invoiced += $t['debit'];
        $period_paid += $t['credit'];
    }
    $closing_balance = $opening_balance + $period_invoiced - $period_paid;
    
    // Prepare data for export
    $export_data = [];
    $running_balance = $opening_balance;
    
    // Opening balance row
    $export_data[] = [
        'Date' => date('d-m-Y', strtotime($from_date)),
        'Invoice No.' => '---',
        'Description' => 'Opening Balance',
        'Debit' => 0,
        'Credit' => 0,
        'Balance' => $opening_balance
    ];
    
    // Transaction rows
    foreach ($transactions as $t) {
        if ($t['debit'] > 0) {
            $running_balance += $t['debit'];
        } else {
            $running_balance -= $t['credit'];
        }
        
        $export_data[] = [
            'Date' => date('d-m-Y', strtotime($t['date'])),
            'Invoice No.' => $t['ref'],
            'Description' => $t['description'] . (isset($t['payment_method']) ? ' (' . ucfirst($t['payment_method']) . ')' : ''),
            'Debit' => $t['debit'],
            'Credit' => $t['credit'],
            'Balance' => $running_balance
        ];
    }
    
    // Closing balance row
    $export_data[] = [
        'Date' => date('d-m-Y', strtotime($to_date)),
        'Invoice No.' => '---',
        'Description' => 'Closing Balance',
        'Debit' => $period_invoiced,
        'Credit' => $period_paid,
        'Balance' => $closing_balance
    ];
    
    // Export based on format
    $filename = "ledger_" . preg_replace('/[^a-zA-Z0-9]/', '_', $customer['customer_name']) . "_" . date('Ymd');
    
    switch ($format) {
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
            
            // Header
            fputcsv($output, ['CUSTOMER LEDGER REPORT']);
            fputcsv($output, ['Customer:', $customer['customer_name']]);
            fputcsv($output, ['Phone:', $customer['phone'] ?? 'N/A']);
            fputcsv($output, ['Period:', date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date))]);
            fputcsv($output, ['Generated:', date('d-m-Y H:i:s')]);
            fputcsv($output, []);
            
            // Column headers
            fputcsv($output, ['Date', 'Invoice No.', 'Description', 'Debit (₹)', 'Credit (₹)', 'Balance (₹)']);
            
            // Data rows
            foreach ($export_data as $row) {
                fputcsv($output, [
                    $row['Date'],
                    $row['Invoice No.'],
                    $row['Description'],
                    $row['Debit'] > 0 ? number_format($row['Debit'], 2) : '-',
                    $row['Credit'] > 0 ? number_format($row['Credit'], 2) : '-',
                    number_format($row['Balance'], 2)
                ]);
            }
            
            // Summary
            fputcsv($output, []);
            fputcsv($output, ['SUMMARY']);
            fputcsv($output, ['Total Invoiced (Period):', '₹' . number_format($period_invoiced, 2)]);
            fputcsv($output, ['Total Paid (Period):', '₹' . number_format($period_paid, 2)]);
            fputcsv($output, ['Closing Balance:', '₹' . number_format($closing_balance, 2)]);
            
            fclose($output);
            exit;
            
        case 'excel':
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
            
            echo "<html>";
            echo "<head>";
            echo "<meta charset='UTF-8'>";
            echo "<style>";
            echo "td { border: 1px solid #000; padding: 5px; }";
            echo "th { background: #f0f0f0; font-weight: bold; border: 1px solid #000; padding: 5px; }";
            echo ".header { background: #1e293b; color: white; font-size: 16px; }";
            echo ".opening { background: #f8fafc; }";
            echo ".closing { background: #f1f5f9; font-weight: bold; }";
            echo "</style>";
            echo "</head>";
            echo "<body>";
            
            echo "<table border='1'>";
            
            // Header
            echo "<tr><th colspan='6' class='header'>CUSTOMER LEDGER REPORT</th></tr>";
            echo "<tr><td colspan='2'><strong>Customer:</strong> " . htmlspecialchars($customer['customer_name']) . "</td>";
            echo "<td colspan='4'><strong>Phone:</strong> " . htmlspecialchars($customer['phone'] ?? 'N/A') . "</td></tr>";
            echo "<tr><td colspan='3'><strong>Period:</strong> " . date('d-m-Y', strtotime($from_date)) . " to " . date('d-m-Y', strtotime($to_date)) . "</td>";
            echo "<td colspan='3'><strong>Generated:</strong> " . date('d-m-Y H:i:s') . "</td></tr>";
            echo "<tr><th colspan='6'></th></tr>";
            
            // Column headers
            echo "<tr>";
            echo "<th>Date</th>";
            echo "<th>Invoice No.</th>";
            echo "<th>Description</th>";
            echo "<th>Debit (₹)</th>";
            echo "<th>Credit (₹)</th>";
            echo "<th>Balance (₹)</th>";
            echo "</tr>";
            
            // Data rows
            foreach ($export_data as $index => $row) {
                $class = '';
                if ($index == 0) $class = 'opening';
                if ($index == count($export_data) - 1) $class = 'closing';
                
                echo "<tr class='$class'>";
                echo "<td>" . $row['Date'] . "</td>";
                echo "<td>" . $row['Invoice No.'] . "</td>";
                echo "<td>" . $row['Description'] . "</td>";
                echo "<td align='right'>" . ($row['Debit'] > 0 ? '₹' . number_format($row['Debit'], 2) : '-') . "</td>";
                echo "<td align='right'>" . ($row['Credit'] > 0 ? '₹' . number_format($row['Credit'], 2) : '-') . "</td>";
                echo "<td align='right'>₹" . number_format($row['Balance'], 2) . "</td>";
                echo "</tr>";
            }
            
            // Summary
            echo "<tr><td colspan='6' style='background: #e2e8f0;'>&nbsp;</td></tr>";
            echo "<tr><td colspan='3'><strong>SUMMARY</strong></td><td colspan='3'></td></tr>";
            echo "<tr><td colspan='3'>Total Invoiced (Period):</td><td colspan='3' align='right'>₹" . number_format($period_invoiced, 2) . "</td></tr>";
            echo "<tr><td colspan='3'>Total Paid (Period):</td><td colspan='3' align='right'>₹" . number_format($period_paid, 2) . "</td></tr>";
            echo "<tr><td colspan='3'><strong>Closing Balance:</strong></td><td colspan='3' align='right'><strong>₹" . number_format($closing_balance, 2) . "</strong></td></tr>";
            
            echo "</table>";
            echo "</body>";
            echo "</html>";
            exit;
            
        case 'pdf':
            ob_start();
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Customer Ledger Report</title>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
                <style>
                    body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; padding: 0; background: #f5f5f5; }
                    .action-toolbar { 
                        background: white; 
                        padding: 15px 20px; 
                        margin: 0; 
                        position: sticky; 
                        top: 0; 
                        z-index: 1000; 
                        border-bottom: 1px solid #e2e8f0;
                        display: flex;
                        gap: 10px;
                        align-items: center;
                    }
                    .action-toolbar h4 { margin: 0; color: #1e293b; font-size: 14px; font-weight: 600; margin-right: auto; }
                    .btn { 
                        padding: 8px 16px; 
                        border-radius: 6px; 
                        font-size: 13px; 
                        font-weight: 500; 
                        display: inline-flex; 
                        align-items: center; 
                        gap: 6px; 
                        cursor: pointer; 
                        border: none; 
                        text-decoration: none;
                        transition: all 0.2s;
                    }
                    .btn-print { background: #3b82f6; color: white; }
                    .btn-print:hover { background: #2563eb; }
                    .btn-download { background: #10b981; color: white; }
                    .btn-download:hover { background: #059669; }
                    .btn:active { transform: scale(0.98); }
                    .content-wrapper { padding: 20px; background: white; margin: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                    h1 { color: #2463eb; text-align: center; margin-bottom: 5px; margin-top: 0; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .customer-info { background: #f8fafc; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                    .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                    th { background: #2463eb; color: white; padding: 8px; text-align: left; }
                    td { border: 1px solid #ddd; padding: 6px; }
                    .opening-row { background: #f8fafc; }
                    .closing-row { background: #f1f5f9; font-weight: bold; }
                    .text-right { text-align: right; }
                    .debit { color: #ef4444; }
                    .credit { color: #10b981; }
                    .balance-positive { color: #10b981; }
                    .balance-negative { color: #ef4444; }
                    .summary { background: #e8f2ff; padding: 15px; border-radius: 8px; margin: 20px 0; }
                    .footer { text-align: center; margin-top: 30px; font-size: 9px; color: #64748b; }
                    @media print {
                        body { margin: 0; background: white; padding: 0; }
                        .action-toolbar { display: none !important; }
                        .content-wrapper { margin: 0; padding: 0; box-shadow: none; }
                        .no-print { display: none !important; }
                    }
                </style>
            </head>
            <body>
                <div class="action-toolbar">
                    <h4>Customer Ledger Report - Preview</h4>
                    <button class="btn btn-print" onclick="window.print();"><i class="bi bi-printer"></i> Print</button>
                    <button class="btn btn-download" onclick="downloadPDF();"><i class="bi bi-download"></i> Download</button>
                </div>
                
                <div class="content-wrapper" id="report-content">
                <div class="header">
                    <h1>CUSTOMER LEDGER REPORT</h1>
                    <p>Generated on: <?php echo date('d-m-Y H:i:s'); ?></p>
                </div>
                
                <div class="customer-info">
                    <div class="info-row">
                        <span><strong>Customer:</strong> <?php echo htmlspecialchars($customer['customer_name']); ?></span>
                        <span><strong>Phone:</strong> <?php echo htmlspecialchars($customer['phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span><strong>Period:</strong> <?php echo date('d-m-Y', strtotime($from_date)); ?> to <?php echo date('d-m-Y', strtotime($to_date)); ?></span>
                        <span><strong>Email:</strong> <?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?></span>
                    </div>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Invoice No.</th>
                            <th>Description</th>
                            <th class="text-right">Debit (₹)</th>
                            <th class="text-right">Credit (₹)</th>
                            <th class="text-right">Balance (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($export_data as $index => $row): 
                            $row_class = $index == 0 ? 'opening-row' : ($index == count($export_data) - 1 ? 'closing-row' : '');
                            $balance_class = $row['Balance'] >= 0 ? 'balance-positive' : 'balance-negative';
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><?php echo $row['Date']; ?></td>
                            <td><?php echo $row['Invoice No.']; ?></td>
                            <td><?php echo $row['Description']; ?></td>
                            <td class="text-right <?php echo $row['Debit'] > 0 ? 'debit' : ''; ?>">
                                <?php echo $row['Debit'] > 0 ? '₹' . number_format($row['Debit'], 2) : '-'; ?>
                            </td>
                            <td class="text-right <?php echo $row['Credit'] > 0 ? 'credit' : ''; ?>">
                                <?php echo $row['Credit'] > 0 ? '₹' . number_format($row['Credit'], 2) : '-'; ?>
                            </td>
                            <td class="text-right <?php echo $balance_class; ?>">
                                ₹<?php echo number_format($row['Balance'], 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="summary">
                    <h4>Summary</h4>
                    <table style="width: 50%; margin-top: 10px;">
                        <tr>
                            <td>Total Invoiced (Period):</td>
                            <td class="text-right">₹<?php echo number_format($period_invoiced, 2); ?></td>
                        </tr>
                        <tr>
                            <td>Total Paid (Period):</td>
                            <td class="text-right">₹<?php echo number_format($period_paid, 2); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Closing Balance:</strong></td>
                            <td class="text-right <?php echo $closing_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                <strong>₹<?php echo number_format($closing_balance, 2); ?></strong>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="footer">
                    <p>This is a computer-generated report. Valid without signature.</p>
                    <p>Generated by Sri Plaast ERP System</p>
                </div>
                </div>
            </body>
            <script>
                function downloadPDF() {
                    const element = document.getElementById('report-content');
                    const filename = '<?php echo $filename; ?>.pdf';
                    
                    const options = {
                        margin: 10,
                        filename: filename,
                        image: { type: 'jpeg', quality: 0.98 },
                        html2canvas: { scale: 2 },
                        jsPDF: { orientation: 'portrait', unit: 'mm', format: 'a4' }
                    };
                    
                    html2pdf().set(options).from(element).save();
                }
                
                // Focus on content area for better print experience
                window.onload = function() {
                    document.querySelector('.content-wrapper').focus();
                };
            </script>
            </html>
            <?php
            $html = ob_get_clean();
            
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: inline; filename="' . $filename . '.html"');
            echo $html;
            exit;
    }
}

// Get all customers for dropdown
$customers_query = "SELECT id, customer_name, phone, opening_balance FROM customers ORDER BY customer_name ASC";
$customers_result = $conn->query($customers_query);
$all_customers = [];
while ($row = $customers_result->fetch_assoc()) {
    $all_customers[] = $row;
}

// Initialize variables
$customer = null;
$opening_balance = 0;
$period_data = ['period_invoiced' => 0, 'period_paid' => 0, 'invoice_count' => 0, 'payment_count' => 0];
$closing_balance = 0;
$transactions = [];
$all_invoices = null;

if ($customer_id > 0) {
    $customer_query = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $customer_query->bind_param("i", $customer_id);
    $customer_query->execute();
    $customer_result = $customer_query->get_result();
    
    if ($customer_result->num_rows > 0) {
        $customer = $customer_result->fetch_assoc();
        
        $opening_balance_query = "
            SELECT 
                COALESCE(SUM(total), 0) as total_invoiced_before,
                COALESCE(SUM(cash_received), 0) as total_paid_before
            FROM invoice 
            WHERE customer_id = ? AND DATE(created_at) < ?
        ";
        
        $opening_stmt = $conn->prepare($opening_balance_query);
        $opening_stmt->bind_param("is", $customer_id, $from_date);
        $opening_stmt->execute();
        $opening_result = $opening_stmt->get_result();
        $opening_data = $opening_result->fetch_assoc();
        
        $opening_balance = $customer['opening_balance'] + ($opening_data['total_invoiced_before'] - $opening_data['total_paid_before']);
        
        $period_summary_query = "
            SELECT 
                COALESCE(SUM(total), 0) as period_invoiced,
                COALESCE(SUM(cash_received), 0) as period_paid,
                COUNT(*) as invoice_count,
                SUM(CASE WHEN cash_received > 0 THEN 1 ELSE 0 END) as payment_count
            FROM invoice 
            WHERE customer_id = ? AND DATE(created_at) BETWEEN ? AND ?
        ";
        
        $period_stmt = $conn->prepare($period_summary_query);
        $period_stmt->bind_param("iss", $customer_id, $from_date, $to_date);
        $period_stmt->execute();
        $period_result = $period_stmt->get_result();
        $period_data = $period_result->fetch_assoc();
        
        $closing_balance = $opening_balance + $period_data['period_invoiced'] - $period_data['period_paid'];
        
        $invoices_query = $conn->prepare("
            SELECT * FROM invoice 
            WHERE customer_id = ? AND DATE(created_at) BETWEEN ? AND ?
            ORDER BY created_at ASC
        ");
        $invoices_query->bind_param("iss", $customer_id, $from_date, $to_date);
        $invoices_query->execute();
        $invoices = $invoices_query->get_result();
        
        if ($invoices && $invoices->num_rows > 0) {
            $all_invoice_rows = [];
            while ($row = $invoices->fetch_assoc()) {
                $all_invoice_rows[] = $row;
            }
            
            foreach ($all_invoice_rows as $invoice) {
                $transactions[] = [
                    'date' => $invoice['created_at'],
                    'ref' => $invoice['inv_num'],
                    'description' => 'Invoice #' . $invoice['inv_num'],
                    'debit' => $invoice['total'],
                    'credit' => 0,
                    'type' => 'invoice',
                    'payment_method' => $invoice['payment_method']
                ];
                
                if ($invoice['cash_received'] > 0) {
                    $payment_desc = 'Payment received - ' . ucfirst($invoice['payment_method']);
                    if ($invoice['cash_received'] < $invoice['total']) {
                        $payment_desc .= ' (Partial)';
                    }
                    
                    $transactions[] = [
                        'date' => $invoice['created_at'],
                        'ref' => $invoice['inv_num'],
                        'description' => $payment_desc,
                        'debit' => 0,
                        'credit' => $invoice['cash_received'],
                        'type' => 'payment',
                        'payment_method' => $invoice['payment_method']
                    ];
                }
            }
            
            usort($transactions, function($a, $b) {
                if ($a['date'] == $b['date']) {
                    if ($a['debit'] > 0 && $b['credit'] > 0) return -1;
                    if ($a['credit'] > 0 && $b['debit'] > 0) return 1;
                    return 0;
                }
                return strtotime($a['date']) - strtotime($b['date']);
            });
        }
        
        $all_invoices_query = $conn->prepare("
            SELECT i.*, 
                   (SELECT COUNT(*) FROM invoice_item WHERE invoice_id = i.id) as item_count
            FROM invoice i 
            WHERE i.customer_id = ? 
            ORDER BY i.created_at DESC
        ");
        $all_invoices_query->bind_param("i", $customer_id);
        $all_invoices_query->execute();
        $all_invoices = $all_invoices_query->get_result();
    }
} else if (!empty($search_term)) {
    $search_query = "SELECT id, customer_name, phone, opening_balance FROM customers 
                     WHERE customer_name LIKE ? OR phone LIKE ? OR email LIKE ? OR gst_number LIKE ?
                     ORDER BY customer_name ASC LIMIT 20";
    $search_stmt = $conn->prepare($search_query);
    $search_param = "%$search_term%";
    $search_stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
    $search_stmt->execute();
    $search_result = $search_stmt->get_result();
    $search_customers = [];
    while ($row = $search_result->fetch_assoc()) {
        $search_customers[] = $row;
    }
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function formatDate($date) {
    return date('d M Y', strtotime($date));
}

function getStatusBadge($pending_amount) {
    if ($pending_amount == 0) {
        return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Paid</span>';
    } else {
        return '<span class="badge bg-warning text-dark"><i class="bi bi-clock-history"></i> Partial</span>';
    }
}

$is_admin = ($_SESSION['user_role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <style>
        .statement-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            color: white;
        }
        
        .customer-info-statement {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .balance-card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 16px 24px;
            text-align: center;
            min-width: 200px;
        }
        
        .balance-label {
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 5px;
        }
        
        .balance-amount {
            font-size: 24px;
            font-weight: 700;
        }
        
        .balance-positive {
            color: #10b981;
        }
        
        .balance-negative {
            color: #f87171;
        }
        
        .date-filter-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
        }
        
        .summary-card.inflow {
            border-left: 4px solid #10b981;
        }
        
        .summary-card.outflow {
            border-left: 4px solid #ef4444;
        }
        
        .summary-card.balance {
            border-left: 4px solid #3b82f6;
        }
        
        .summary-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 5px;
        }
        
        .summary-value {
            font-size: 20px;
            font-weight: 600;
        }
        
        .statement-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            width: 100%;
        }
        
        .statement-table th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 13px;
            color: #475569;
            padding: 15px 12px;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
        }
        
        .statement-table td {
            padding: 12px;
            border-bottom: 1px solid #eef2f6;
            font-size: 13px;
        }
        
        .transaction-debit {
            color: #ef4444;
            font-weight: 600;
            text-align: right;
        }
        
        .transaction-credit {
            color: #10b981;
            font-weight: 600;
            text-align: right;
        }
        
        .transaction-ref {
            font-family: monospace;
            font-weight: 600;
            color: #3b82f6;
        }
        
        .balance-column {
            font-weight: 600;
            text-align: right;
        }
        
        .closing-row {
            background: #f1f5f9;
            font-weight: 700;
        }
        
        .customer-search-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }
        
        .customer-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .customer-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eef2f6;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .customer-item:hover {
            background: #f8fafc;
        }
        
        .customer-name {
            font-weight: 600;
            color: #1e293b;
        }
        
        .customer-phone {
            font-size: 12px;
            color: #64748b;
        }
        
        .badge-payment {
            background: #f1f5f9;
            color: #475569;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
        }
        
        .export-buttons-container {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .export-buttons-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .export-buttons-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-export {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-export-excel {
            background: #10b981;
            color: white;
        }
        
        .btn-export-excel:hover {
            background: #059669;
        }
        
        .btn-export-csv {
            background: #3b82f6;
            color: white;
        }
        
        .btn-export-csv:hover {
            background: #2563eb;
        }
        
        .btn-export-pdf {
            background: #ef4444;
            color: white;
        }
        
        .btn-export-pdf:hover {
            background: #dc2626;
        }
        
        .opening-balance-row {
            background: #f8fafc;
            font-weight: 600;
        }
        
        .text-right {
            text-align: right;
        }
        
        @media print {
            .no-print {
                display: none !important;
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
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Customer Ledger Report</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">View detailed customer transactions with bank-style statement</p>
                </div>
                <?php if ($customer_id > 0): ?>
                    <a href="customer-ledger-report.php" class="btn btn-outline-secondary no-print">
                        <i class="bi bi-arrow-left"></i> Back to Search
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($customer_id == 0): ?>
                <div class="customer-search-card no-print">
                    <h5 class="mb-3 fw-semibold" style="font-size: 16px;">
                        <i class="bi bi-search me-2" style="color: #3b82f6;"></i>
                        Search Customer
                    </h5>
                    
                    <form method="GET" action="customer-ledger-report.php" class="mb-3">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" name="search" class="form-control border-start-0" 
                                           placeholder="Search by customer name, phone, email or GST number..." 
                                           value="<?php echo htmlspecialchars($search_term); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search me-2"></i>Search
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <?php if (!empty($search_term) && !empty($search_customers)): ?>
                        <div class="customer-list">
                            <?php foreach ($search_customers as $cust): ?>
                                <div class="customer-item" onclick="selectCustomer(<?php echo $cust['id']; ?>)">
                                    <div>
                                        <div class="customer-name"><?php echo htmlspecialchars($cust['customer_name']); ?></div>
                                        <div class="customer-phone">
                                            <i class="bi bi-telephone me-1"></i>
                                            <?php echo !empty($cust['phone']) ? htmlspecialchars($cust['phone']) : 'No phone'; ?>
                                        </div>
                                    </div>
                                    <div class="customer-balance <?php echo $cust['opening_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                        Bal: <?php echo formatCurrency($cust['opening_balance']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif (!empty($search_term)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            No customers found matching your search.
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-4">
                        <label class="form-label fw-semibold">Or select from all customers:</label>
                        <select class="form-select" onchange="if(this.value) selectCustomer(this.value)">
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($all_customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>">
                                    <?php echo htmlspecialchars($cust['customer_name']); ?> 
                                    (<?php echo !empty($cust['phone']) ? htmlspecialchars($cust['phone']) : 'No phone'; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($customer_id > 0 && $customer): ?>
                <!-- Customer Header -->
                <div class="statement-header no-print">
                    <div class="customer-info-statement">
                        <div>
                            <h4 style="margin: 0 0 8px 0;"><?php echo htmlspecialchars($customer['customer_name']); ?></h4>
                            <p style="margin: 0; opacity: 0.8;">Customer ID: #<?php echo $customer['id']; ?></p>
                            
                            <div style="display: flex; gap: 20px; margin-top: 15px; flex-wrap: wrap;">
                                <?php if (!empty($customer['phone'])): ?>
                                    <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($customer['phone']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($customer['email'])): ?>
                                    <span><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($customer['email']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($customer['gst_number'])): ?>
                                    <span><i class="bi bi-file-text"></i> <?php echo htmlspecialchars($customer['gst_number']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="balance-card">
                            <div class="balance-label">Opening Balance</div>
                            <div class="balance-amount <?php echo $opening_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                <?php echo formatCurrency($opening_balance); ?>
                            </div>
                            <div style="font-size: 11px; opacity: 0.7;">as on <?php echo formatDate($from_date); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Date Filter -->
                <div class="date-filter-card no-print">
                    <form method="GET" action="customer-ledger-report.php" class="row g-3">
                        <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                        
                        <div class="col-md-4">
                            <label class="form-label">From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>" required>
                        </div>
                        
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-filter"></i> Apply Filter
                            </button>
                            <a href="customer-ledger-report.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Summary Cards -->
                <div class="summary-cards no-print">
                    <div class="summary-card inflow">
                        <div class="summary-label">Total Invoiced (Period)</div>
                        <div class="summary-value"><?php echo formatCurrency($period_data['period_invoiced']); ?></div>
                        <small class="text-muted"><?php echo $period_data['invoice_count']; ?> invoices</small>
                    </div>
                    
                    <div class="summary-card outflow">
                        <div class="summary-label">Total Paid (Period)</div>
                        <div class="summary-value"><?php echo formatCurrency($period_data['period_paid']); ?></div>
                        <small class="text-muted"><?php echo $period_data['payment_count']; ?> payments</small>
                    </div>
                    
                    <div class="summary-card balance">
                        <div class="summary-label">Closing Balance</div>
                        <div class="summary-value <?php echo $closing_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                            <?php echo formatCurrency($closing_balance); ?>
                        </div>
                        <small class="text-muted">as on <?php echo formatDate($to_date); ?></small>
                    </div>
                </div>

                <!-- Manual Export Buttons -->
                <div class="export-buttons-container no-print">
                    <div class="export-buttons-title">
                        <i class="bi bi-download"></i> Export Ledger
                    </div>
                    <div class="export-buttons-group">
                        <a href="?customer_id=<?php echo $customer_id; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&export=csv" class="btn-export btn-export-csv">
                            <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                        </a>
                        <a href="?customer_id=<?php echo $customer_id; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&export=excel" class="btn-export btn-export-excel">
                            <i class="bi bi-file-earmark-excel"></i> Excel
                        </a>
                        <a href="?customer_id=<?php echo $customer_id; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&export=pdf" class="btn-export btn-export-pdf">
                            <i class="bi bi-file-earmark-pdf"></i> PDF
                        </a>
                    </div>
                </div>

                <!-- Statement Table -->
                <div class="dashboard-card">
                    <div class="table-responsive">
                        <table class="statement-table" id="statementTable" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Invoice No.</th>
                                    <th>Description</th>
                                    <th class="text-right">Debit (₹)</th>
                                    <th class="text-right">Credit (₹)</th>
                                    <th class="text-right">Balance (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="opening-balance-row">
                                    <td><?php echo formatDate($from_date); ?></td>
                                    <td>---</td>
                                    <td><strong>Opening Balance</strong></td>
                                    <td class="text-right">---</td>
                                    <td class="text-right">---</td>
                                    <td class="text-right <?php echo $opening_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                        <?php echo formatCurrency($opening_balance); ?>
                                    </td>
                                </tr>
                                
                                <?php if (!empty($transactions)): ?>
                                    <?php 
                                    $running_balance = $opening_balance;
                                    foreach ($transactions as $transaction):
                                        if ($transaction['debit'] > 0) {
                                            $running_balance += $transaction['debit'];
                                        } else {
                                            $running_balance -= $transaction['credit'];
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo formatDate($transaction['date']); ?></td>
                                            <td class="transaction-ref"><?php echo htmlspecialchars($transaction['ref']); ?></td>
                                            <td>
                                                <?php echo $transaction['description']; ?>
                                                <?php if (isset($transaction['payment_method'])): ?>
                                                    <span class="badge-payment ms-2">
                                                        <i class="bi bi-<?php 
                                                            echo $transaction['payment_method'] == 'cash' ? 'cash' : 
                                                                ($transaction['payment_method'] == 'card' ? 'credit-card' : 
                                                                ($transaction['payment_method'] == 'upi' ? 'phone' : 'bank')); 
                                                        ?>"></i>
                                                        <?php echo ucfirst($transaction['payment_method']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right transaction-debit">
                                                <?php echo $transaction['debit'] > 0 ? formatCurrency($transaction['debit']) : '-'; ?>
                                            </td>
                                            <td class="text-right transaction-credit">
                                                <?php echo $transaction['credit'] > 0 ? formatCurrency($transaction['credit']) : '-'; ?>
                                            </td>
                                            <td class="text-right <?php echo $running_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                <?php echo formatCurrency($running_balance); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 40px;">
                                            No transactions found for the selected period
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                
                                <tr class="closing-row">
                                    <td><?php echo formatDate($to_date); ?></td>
                                    <td>---</td>
                                    <td><strong>Closing Balance</strong></td>
                                    <td class="text-right"><strong><?php echo formatCurrency($period_data['period_invoiced']); ?></strong></td>
                                    <td class="text-right"><strong><?php echo formatCurrency($period_data['period_paid']); ?></strong></td>
                                    <td class="text-right <?php echo $closing_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                        <strong><?php echo formatCurrency($closing_balance); ?></strong>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Invoice Details Table -->
                <div class="dashboard-card mt-4">
                    <div class="card-header py-3" style="background: white; border-bottom: 1px solid #eef2f6;">
                        <h5 class="mb-0 fw-semibold" style="font-size: 16px;">
                            <i class="bi bi-receipt me-2" style="color: #3b82f6;"></i>
                            Detailed Invoice History
                        </h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table-custom" id="invoicesTable" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th class="text-right">Total</th>
                                    <th class="text-right">Paid</th>
                                    <th class="text-right">Pending</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($all_invoices && $all_invoices->num_rows > 0): ?>
                                    <?php while ($invoice = $all_invoices->fetch_assoc()): ?>
                                        <tr>
                                            <td><span style="color: #3b82f6;"><?php echo htmlspecialchars($invoice['inv_num']); ?></span></td>
                                            <td><?php echo formatDate($invoice['created_at']); ?></td>
                                            <td class="text-center"><?php echo $invoice['item_count']; ?></td>
                                            <td class="text-right"><?php echo formatCurrency($invoice['total']); ?></td>
                                            <td class="text-right" style="color: #10b981;"><?php echo formatCurrency($invoice['cash_received']); ?></td>
                                            <td class="text-right" style="color: <?php echo $invoice['pending_amount'] > 0 ? '#dc2626' : '#64748b'; ?>;">
                                                <?php echo formatCurrency($invoice['pending_amount']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <i class="bi bi-<?php 
                                                        echo $invoice['payment_method'] == 'cash' ? 'cash' : 
                                                            ($invoice['payment_method'] == 'card' ? 'credit-card' : 
                                                            ($invoice['payment_method'] == 'upi' ? 'phone' : 'bank')); 
                                                    ?> me-1"></i>
                                                    <?php echo ucfirst($invoice['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo getStatusBadge($invoice['pending_amount']); ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 30px;">
                                            No invoices found for this customer
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

<script>
$(document).ready(function() {
    <?php if ($customer_id > 0): ?>
    // Initialize Statement Table without buttons (using manual export instead)
    $('#statementTable').DataTable({
        pageLength: 100,
        order: [],
        language: {
            search: "Search transactions:",
            lengthMenu: "Show _MENU_ transactions",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions",
            emptyTable: "No transactions available"
        }
    });

    // Initialize Invoice Table
    $('#invoicesTable').DataTable({
        pageLength: 10,
        order: [[1, 'desc']],
        language: {
            search: "Search invoices:",
            lengthMenu: "Show _MENU_ invoices",
            info: "Showing _START_ to _END_ of _TOTAL_ invoices",
            emptyTable: "No invoices available"
        }
    });
    <?php endif; ?>
});

function selectCustomer(customerId) {
    if (customerId) {
        window.location.href = 'customer-ledger-report.php?customer_id=' + customerId;
    }
}
</script>

<style>
/* DataTable styling */
.dataTables_wrapper {
    padding: 15px;
}

.dataTables_filter input {
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 12px;
    margin-left: 8px;
}

.dataTables_length select {
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 4px 8px;
    margin: 0 4px;
}

.dataTables_info {
    font-size: 13px;
    color: #64748b;
    padding-top: 10px;
}

.dataTables_paginate {
    padding-top: 10px;
}

.dataTables_paginate .paginate_button {
    padding: 5px 10px;
    margin: 0 2px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    cursor: pointer;
}

.dataTables_paginate .paginate_button.current {
    background: #3b82f6;
    color: white !important;
    border-color: #3b82f6;
}

.dataTables_paginate .paginate_button:hover {
    background: #f1f5f9;
}
</style>

</body>
</html>