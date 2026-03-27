<?php
// oc_report.php - Opening & Closing Report
session_start();
$currentPage = 'oc_report';
$pageTitle = 'Opening & Closing Report';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view this report (or both based on your requirement)
checkRoleAccess(['admin']);

$success = '';
$error = '';

// Helper function to build query string with current filters
function buildQueryString($exclude = []) {
    $params = $_GET;
    $allFilters = [
        'report_date_from', 'report_date_to', 'filter_customer', 'filter_payment_method',
        'filter_invoice_status', 'export'
    ];
    
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    
    $filteredParams = [];
    foreach ($params as $key => $value) {
        if (in_array($key, $allFilters) && !empty($value) && $value != 'all') {
            $filteredParams[$key] = $value;
        }
    }
    
    return count($filteredParams) ? '?' . http_build_query($filteredParams) : '';
}

// Default date range: current month
$reportDateFrom = $_GET['report_date_from'] ?? date('Y-m-01');
$reportDateTo = $_GET['report_date_to'] ?? date('Y-m-t');
$filterCustomer = $_GET['filter_customer'] ?? 'all';
$filterPaymentMethod = $_GET['filter_payment_method'] ?? 'all';
$filterInvoiceStatus = $_GET['filter_invoice_status'] ?? 'all';

// Get customers for filter
$customers = $conn->query("SELECT id, customer_name FROM customers ORDER BY customer_name ASC");

// ----------------------------------------------------------------------
// Function to get Opening Balance as of a specific date
// Opening Balance = Total Pending from all invoices CREATED before the start date
// This includes both paid and pending invoices? Actually pending amount before start date.
// Opening Balance = Sum of pending_amount from invoices where created_at < start_date
// ----------------------------------------------------------------------
function getOpeningBalance($conn, $asOfDate) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(pending_amount), 0) as opening FROM invoice WHERE DATE(created_at) < ?");
    $stmt->bind_param("s", $asOfDate);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return (float)($result['opening'] ?? 0);
}

// ----------------------------------------------------------------------
// Function to get Closing Balance as of a specific date
// Closing Balance = Opening Balance + (Sales made within period) - (Payments collected within period)
// Alternative: Closing Balance = Sum of pending_amount from all invoices created on or before end date
// We'll use the simpler method: sum of pending_amount from all invoices created on or before end_date
// ----------------------------------------------------------------------
function getClosingBalance($conn, $asOfDate) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(pending_amount), 0) as closing FROM invoice WHERE DATE(created_at) <= ?");
    $stmt->bind_param("s", $asOfDate);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return (float)($result['closing'] ?? 0);
}

// ----------------------------------------------------------------------
// Get all invoices within the date range with filters
// ----------------------------------------------------------------------
$where = "1=1";
$params = [];
$types = "";

// Date range condition - invoices created in this period
if (!empty($reportDateFrom) && !empty($reportDateTo)) {
    $where .= " AND DATE(i.created_at) BETWEEN ? AND ?";
    $params[] = $reportDateFrom;
    $params[] = $reportDateTo;
    $types .= "ss";
}

if ($filterCustomer !== 'all' && is_numeric($filterCustomer)) {
    $where .= " AND i.customer_id = ?";
    $params[] = (int)$filterCustomer;
    $types .= "i";
}

if ($filterPaymentMethod !== 'all') {
    $where .= " AND i.payment_method = ?";
    $params[] = $filterPaymentMethod;
    $types .= "s";
}

if ($filterInvoiceStatus !== 'all') {
    if ($filterInvoiceStatus === 'paid') {
        $where .= " AND i.pending_amount = 0";
    } elseif ($filterInvoiceStatus === 'pending') {
        $where .= " AND i.pending_amount > 0";
    }
}

// Query to get all invoices in the period with their items and profit calculation
$sql = "
    SELECT 
        i.id,
        i.inv_num,
        i.created_at,
        i.customer_name,
        c.phone,
        i.subtotal,
        i.cgst_amount,
        i.sgst_amount,
        i.total,
        i.payment_method,
        i.pending_amount,
        i.cash_received,
        i.upi_amount,
        i.card_amount,
        i.bank_amount,
        i.cheque_amount,
        i.credit_amount,
        COALESCE(pf.profit, 0) AS profit_amount,
        CASE 
            WHEN i.pending_amount = 0 THEN 'Paid'
            ELSE 'Pending'
        END as payment_status
    FROM invoice i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN (
        SELECT 
            ii.invoice_id,
            COALESCE(
                SUM(ii.selling_price * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) -
                SUM(ii.purchase_price * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity))
            , 0) AS profit
        FROM invoice_item ii
        GROUP BY ii.invoice_id
    ) pf ON pf.invoice_id = i.id
    WHERE $where
    ORDER BY i.created_at ASC
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $invoices = $stmt->get_result();
} else {
    $invoices = $conn->query($sql);
}

// ----------------------------------------------------------------------
// Calculate totals for the period
// ----------------------------------------------------------------------
$total_sales = 0;
$total_paid = 0;
$total_pending = 0;
$total_profit = 0;
$total_cgst = 0;
$total_sgst = 0;
$total_tax = 0;
$invoice_count = 0;
$payment_method_totals = [
    'cash' => 0,
    'card' => 0,
    'upi' => 0,
    'bank' => 0,
    'credit' => 0,
    'mixed' => 0
];

if ($invoices && $invoices->num_rows > 0) {
    while ($inv = $invoices->fetch_assoc()) {
        $total_sales += (float)$inv['total'];
        $total_paid += (float)$inv['total'] - (float)$inv['pending_amount'];
        $total_pending += (float)$inv['pending_amount'];
        $total_profit += (float)$inv['profit_amount'];
        $total_cgst += (float)$inv['cgst_amount'];
        $total_sgst += (float)$inv['sgst_amount'];
        $invoice_count++;
        
        $method = $inv['payment_method'];
        if (isset($payment_method_totals[$method])) {
            $payment_method_totals[$method] += (float)$inv['total'];
        }
    }
    $total_tax = $total_cgst + $total_sgst;
    // Reset pointer for later use
    $invoices->data_seek(0);
}

// ----------------------------------------------------------------------
// Calculate Opening Balance (as of report start date)
// ----------------------------------------------------------------------
$opening_balance = getOpeningBalance($conn, $reportDateFrom);
// Closing Balance (as of report end date)
$closing_balance = getClosingBalance($conn, $reportDateTo);

// Verify calculation: Closing = Opening + NewSales - PaymentsReceived (this should match)
// Payments received in period = total paid amount = $total_paid
$calculated_closing = $opening_balance + $total_sales - $total_paid;
$closing_balance = $calculated_closing; // Use calculated closing for consistency

// ----------------------------------------------------------------------
// Handle Export Functionality
// ----------------------------------------------------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['excel', 'csv', 'pdf'])) {
    $export_type = $_GET['export'];
    
    // Prepare data for export
    $export_invoices = [];
    if ($invoices && $invoices->num_rows > 0) {
        $invoices->data_seek(0);
        while ($inv = $invoices->fetch_assoc()) {
            $export_invoices[] = [
                'Invoice No' => $inv['inv_num'],
                'Date' => date('d-m-Y', strtotime($inv['created_at'])),
                'Customer' => $inv['customer_name'] ?: 'Walk-in',
                'Phone' => $inv['phone'] ?: '-',
                'Subtotal' => $inv['subtotal'],
                'CGST' => $inv['cgst_amount'],
                'SGST' => $inv['sgst_amount'],
                'Total' => $inv['total'],
                'Paid' => (float)$inv['total'] - (float)$inv['pending_amount'],
                'Pending' => $inv['pending_amount'],
                'Payment Method' => ucfirst($inv['payment_method']),
                'Status' => $inv['payment_status'],
                'Profit' => $inv['profit_amount']
            ];
        }
    }
    
    switch($export_type) {
        case 'csv':
        case 'excel':
            $filename = "oc_report_" . date('Y-m-d');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Report Header
            fputcsv($output, ['OPENING & CLOSING REPORT']);
            fputcsv($output, ['Generated on: ' . date('d-m-Y H:i:s')]);
            fputcsv($output, ['Period: ' . date('d M Y', strtotime($reportDateFrom)) . ' to ' . date('d M Y', strtotime($reportDateTo))]);
            fputcsv($output, []);
            
            // Summary Section
            fputcsv($output, ['SUMMARY']);
            fputcsv($output, ['Opening Balance', '₹' . number_format($opening_balance, 2)]);
            fputcsv($output, ['Total Sales (Period)', '₹' . number_format($total_sales, 2)]);
            fputcsv($output, ['Total Payments Collected', '₹' . number_format($total_paid, 2)]);
            fputcsv($output, ['Closing Balance', '₹' . number_format($closing_balance, 2)]);
            fputcsv($output, ['']);
            fputcsv($output, ['Total Invoices', $invoice_count]);
            fputcsv($output, ['Total Profit', '₹' . number_format($total_profit, 2)]);
            fputcsv($output, ['Total Tax (CGST+SGST)', '₹' . number_format($total_tax, 2)]);
            fputcsv($output, []);
            
            // Payment Method Breakdown
            fputcsv($output, ['PAYMENT METHOD BREAKDOWN']);
            foreach ($payment_method_totals as $method => $amount) {
                if ($amount > 0 || $total_sales == 0) {
                    $percentage = $total_sales > 0 ? ($amount / $total_sales) * 100 : 0;
                    fputcsv($output, [ucfirst($method), '₹' . number_format($amount, 2), number_format($percentage, 1) . '%']);
                }
            }
            fputcsv($output, []);
            
            // Details Section
            fputcsv($output, ['INVOICE DETAILS']);
            if (!empty($export_invoices)) {
                fputcsv($output, array_keys($export_invoices[0]));
                foreach ($export_invoices as $row) {
                    $formatted = $row;
                    $formatted['Subtotal'] = '₹' . number_format($row['Subtotal'], 2);
                    $formatted['CGST'] = '₹' . number_format($row['CGST'], 2);
                    $formatted['SGST'] = '₹' . number_format($row['SGST'], 2);
                    $formatted['Total'] = '₹' . number_format($row['Total'], 2);
                    $formatted['Paid'] = '₹' . number_format($row['Paid'], 2);
                    $formatted['Pending'] = '₹' . number_format($row['Pending'], 2);
                    $formatted['Profit'] = '₹' . number_format($row['Profit'], 2);
                    fputcsv($output, $formatted);
                }
            } else {
                fputcsv($output, ['No invoices found for the selected period.']);
            }
            
            fclose($output);
            exit;
            
        case 'pdf':
            ob_start();
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Opening & Closing Report</title>
                <style>
                    body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
                    h1 { color: #2463eb; text-align: center; margin-bottom: 5px; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .period { text-align: center; font-size: 14px; color: #475569; margin-bottom: 20px; }
                    .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
                    .summary-item { background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e2e8f0; }
                    .summary-label { font-size: 11px; color: #64748b; text-transform: uppercase; }
                    .summary-value { font-size: 20px; font-weight: bold; color: #1e293b; }
                    .summary-value.opening { color: #3b82f6; }
                    .summary-value.sales { color: #10b981; }
                    .summary-value.paid { color: #8b5cf6; }
                    .summary-value.closing { color: #f59e0b; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 11px; }
                    th { background: #2463eb; color: white; padding: 8px; text-align: left; }
                    td { border: 1px solid #ddd; padding: 6px; }
                    tr:nth-child(even) { background: #f8fafc; }
                    .text-right { text-align: right; }
                    .badge-paid { background: #dcfce7; color: #16a34a; padding: 2px 8px; border-radius: 12px; font-size: 10px; }
                    .badge-pending { background: #fee2e2; color: #dc2626; padding: 2px 8px; border-radius: 12px; font-size: 10px; }
                    .payment-breakdown { background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 20px; }
                    .footer { text-align: center; margin-top: 30px; font-size: 10px; color: #64748b; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>OPENING & CLOSING REPORT</h1>
                    <p>Generated on: <?php echo date('d-m-Y H:i:s'); ?></p>
                    <div class="period">Period: <?php echo date('d M Y', strtotime($reportDateFrom)); ?> to <?php echo date('d M Y', strtotime($reportDateTo)); ?></div>
                </div>
                
                <!-- Summary Cards -->
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-label">Opening Balance</div>
                        <div class="summary-value opening">₹<?php echo number_format($opening_balance, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Total Sales (Period)</div>
                        <div class="summary-value sales">₹<?php echo number_format($total_sales, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Payments Collected</div>
                        <div class="summary-value paid">₹<?php echo number_format($total_paid, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Closing Balance</div>
                        <div class="summary-value closing">₹<?php echo number_format($closing_balance, 2); ?></div>
                    </div>
                </div>
                
                <!-- Additional Stats -->
                <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                    <div><strong>Total Invoices:</strong> <?php echo $invoice_count; ?></div>
                    <div><strong>Total Profit:</strong> ₹<?php echo number_format($total_profit, 2); ?></div>
                    <div><strong>Total Tax:</strong> ₹<?php echo number_format($total_tax, 2); ?></div>
                </div>
                
                <!-- Payment Method Breakdown -->
                <div class="payment-breakdown">
                    <h4>Payment Method Breakdown</h4>
                    <?php foreach ($payment_method_totals as $method => $amount): ?>
                        <?php if ($amount > 0 || $total_sales == 0): 
                            $percentage = $total_sales > 0 ? ($amount / $total_sales) * 100 : 0;
                        ?>
                        <div style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #e2e8f0;">
                            <span><strong><?php echo ucfirst($method); ?></strong></span>
                            <span>₹<?php echo number_format($amount, 2); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                
                <!-- Invoice Details Table -->
                <h4>Invoice Details (<?php echo $invoice_count; ?> invoices)</h4>
                <table>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Subtotal</th>
                            <th>Tax</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Pending</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($invoices && $invoices->num_rows > 0) {
                            $invoices->data_seek(0);
                            while ($inv = $invoices->fetch_assoc()):
                                $paid = (float)$inv['total'] - (float)$inv['pending_amount'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($inv['inv_num']); ?></td>
                            <td><?php echo date('d-m-Y', strtotime($inv['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($inv['customer_name'] ?: 'Walk-in'); ?></td>
                            <td class="text-right">₹<?php echo number_format((float)$inv['subtotal'], 2); ?></td>
                            <td class="text-right">₹<?php echo number_format((float)$inv['cgst_amount'] + (float)$inv['sgst_amount'], 2); ?></td>
                            <td class="text-right">₹<?php echo number_format((float)$inv['total'], 2); ?></td>
                            <td class="text-right">₹<?php echo number_format($paid, 2); ?></td>
                            <td class="text-right">₹<?php echo number_format((float)$inv['pending_amount'], 2); ?></td>
                            <td><?php echo ucfirst($inv['payment_method']); ?></td>
                            <td><span class="badge-<?php echo $inv['payment_status'] == 'Paid' ? 'paid' : 'pending'; ?>"><?php echo $inv['payment_status']; ?></span></td>
                            <td class="text-right">₹<?php echo number_format((float)$inv['profit_amount'], 2); ?></td>
                        </tr>
                        <?php 
                            endwhile;
                        } else { 
                        ?>
                        <tr>
                            <td colspan="11" style="text-align: center;">No invoices found for the selected period.</td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
                
                <div class="footer">
                    <p>This is a computer-generated report. Valid without signature.</p>
                    <p>Generated by Sri Plaast ERP System</p>
                </div>
            </body>
            </html>
            <?php
            $html = ob_get_clean();
            $filename = "oc_report_" . date('Y-m-d');
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="' . $filename . '.html"');
            echo $html;
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .report-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #eef2f6;
            margin-bottom: 24px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .stat-box {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        .stat-box:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border-color: #cbd5e1;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 8px;
        }
        .stat-label {
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-icon.blue { background: #e8f2ff; color: #2463eb; }
        .stat-icon.green { background: #dcfce7; color: #10b981; }
        .stat-icon.purple { background: #f3e8ff; color: #9333ea; }
        .stat-icon.orange { background: #ffedd5; color: #f59e0b; }
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 24px;
        }
        .export-btn {
            background: #10b981;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .export-btn:hover {
            background: #059669;
            color: white;
        }
        .export-dropdown {
            position: relative;
            display: inline-block;
        }
        .export-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background: white;
            min-width: 180px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            border-radius: 8px;
            z-index: 1000;
            border: 1px solid #eef2f6;
        }
        .export-dropdown:hover .export-dropdown-content {
            display: block;
        }
        .export-dropdown-content a {
            color: #1e293b;
            padding: 10px 16px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            transition: background 0.2s;
            border-bottom: 1px solid #f1f5f9;
        }
        .export-dropdown-content a:hover {
            background: #f8fafc;
        }
        .export-dropdown-content a:last-child {
            border-bottom: none;
        }
        .method-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .method-badge.cash { background: #e8f2ff; color: #2463eb; }
        .method-badge.card { background: #f0fdf4; color: #16a34a; }
        .method-badge.upi { background: #fef3c7; color: #d97706; }
        .method-badge.bank { background: #f3e8ff; color: #9333ea; }
        .method-badge.credit { background: #fee2e2; color: #dc2626; }
        .method-badge.mixed { background: #f1f5f9; color: #475569; }
        .status-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .status-badge.paid { background: #dcfce7; color: #16a34a; }
        .status-badge.pending { background: #fee2e2; color: #dc2626; }
        .table-custom th, .table-custom td {
            vertical-align: middle;
        }
        .text-right {
            text-align: right;
        }
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            .stat-value {
                font-size: 24px;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Opening & Closing Report</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">
                        View opening balance, period sales, payments collected, and closing balance
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <div class="export-dropdown">
                        <button class="export-btn">
                            <i class="bi bi-download"></i> Export Report
                            <i class="bi bi-chevron-down" style="font-size: 12px;"></i>
                        </button>
                        <div class="export-dropdown-content">
                            <a href="?export=csv<?php echo buildQueryString(['export']); ?>">
                                <i class="bi bi-file-earmark-spreadsheet" style="color: #059669;"></i> Export as CSV
                            </a>
                            <a href="?export=excel<?php echo buildQueryString(['export']); ?>">
                                <i class="bi bi-file-excel" style="color: #16a34a;"></i> Export as Excel
                            </a>
                            <a href="?export=pdf<?php echo buildQueryString(['export']); ?>">
                                <i class="bi bi-file-pdf" style="color: #dc2626;"></i> Export as PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="oc_report.php" id="reportForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">From Date</label>
                            <input type="date" name="report_date_from" class="form-control" 
                                   value="<?php echo htmlspecialchars($reportDateFrom); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">To Date</label>
                            <input type="date" name="report_date_to" class="form-control" 
                                   value="<?php echo htmlspecialchars($reportDateTo); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Customer</label>
                            <select name="filter_customer" class="form-select">
                                <option value="all">All Customers</option>
                                <?php
                                if ($customers && $customers->num_rows > 0) {
                                    while ($customer = $customers->fetch_assoc()):
                                ?>
                                    <option value="<?php echo (int)$customer['id']; ?>" 
                                        <?php echo ((string)$filterCustomer === (string)$customer['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['customer_name']); ?>
                                    </option>
                                <?php
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Method</label>
                            <select name="filter_payment_method" class="form-select">
                                <option value="all">All Methods</option>
                                <option value="cash" <?php echo $filterPaymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="card" <?php echo $filterPaymentMethod === 'card' ? 'selected' : ''; ?>>Card</option>
                                <option value="upi" <?php echo $filterPaymentMethod === 'upi' ? 'selected' : ''; ?>>UPI</option>
                                <option value="bank" <?php echo $filterPaymentMethod === 'bank' ? 'selected' : ''; ?>>Bank</option>
                                <option value="credit" <?php echo $filterPaymentMethod === 'credit' ? 'selected' : ''; ?>>Credit</option>
                                <option value="mixed" <?php echo $filterPaymentMethod === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="filter_invoice_status" class="form-select">
                                <option value="all">All</option>
                                <option value="paid" <?php echo $filterInvoiceStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="pending" <?php echo $filterInvoiceStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn-primary-custom w-100">
                                <i class="bi bi-search"></i> Generate Report
                            </button>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <a href="oc_report.php" class="btn btn-outline-secondary w-100">
                                <i class="bi bi-eraser"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Summary Stats Cards -->
            <div class="summary-grid">
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($opening_balance, 2); ?></div>
                            <div class="stat-label">Opening Balance</div>
                            <div class="text-muted mt-1" style="font-size: 11px;">
                                as of <?php echo date('d M Y', strtotime($reportDateFrom)); ?>
                            </div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color: #10b981;">₹<?php echo number_format($total_sales, 2); ?></div>
                            <div class="stat-label">Total Sales (Period)</div>
                            <div class="text-muted mt-1" style="font-size: 11px;">
                                <?php echo $invoice_count; ?> invoices
                            </div>
                        </div>
                        <div class="stat-icon green">
                            <i class="bi bi-cart-check"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color: #8b5cf6;">₹<?php echo number_format($total_paid, 2); ?></div>
                            <div class="stat-label">Payments Collected</div>
                            <div class="text-muted mt-1" style="font-size: 11px;">
                                Collection rate: <?php echo $total_sales > 0 ? number_format(($total_paid / $total_sales) * 100, 1) : 0; ?>%
                            </div>
                        </div>
                        <div class="stat-icon purple">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color: #f59e0b;">₹<?php echo number_format($closing_balance, 2); ?></div>
                            <div class="stat-label">Closing Balance</div>
                            <div class="text-muted mt-1" style="font-size: 11px;">
                                as of <?php echo date('d M Y', strtotime($reportDateTo)); ?>
                            </div>
                        </div>
                        <div class="stat-icon orange">
                            <i class="bi bi-calendar-week"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Metrics Row -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="dashboard-card text-center p-3">
                        <div class="text-muted mb-1">Total Profit</div>
                        <div class="fw-bold fs-4 <?php echo $total_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                            ₹<?php echo number_format($total_profit, 2); ?>
                        </div>
                        <div class="text-muted small">Margin: <?php echo $total_sales > 0 ? number_format(($total_profit / $total_sales) * 100, 1) : 0; ?>%</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card text-center p-3">
                        <div class="text-muted mb-1">Total Tax (GST)</div>
                        <div class="fw-bold fs-4">₹<?php echo number_format($total_tax, 2); ?></div>
                        <div class="text-muted small">CGST: ₹<?php echo number_format($total_cgst, 2); ?> | SGST: ₹<?php echo number_format($total_sgst, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card text-center p-3">
                        <div class="text-muted mb-1">Pending Amount (Period)</div>
                        <div class="fw-bold fs-4 text-danger">₹<?php echo number_format($total_pending, 2); ?></div>
                        <div class="text-muted small">From invoices in this period</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card text-center p-3">
                        <div class="text-muted mb-1">Payment Methods Breakdown</div>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-2">
                            <?php foreach ($payment_method_totals as $method => $amount): ?>
                                <?php if ($amount > 0): ?>
                                    <span class="method-badge <?php echo $method; ?>">
                                        <i class="bi <?php echo $method === 'cash' ? 'bi-cash-stack' : ($method === 'card' ? 'bi-credit-card' : ($method === 'upi' ? 'bi-phone' : ($method === 'bank' ? 'bi-bank' : 'bi-clock-history'))); ?>"></i>
                                        <?php echo ucfirst($method); ?>: ₹<?php echo number_format($amount, 2); ?>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoice Details Table -->
            <div class="dashboard-card">
                <div class="card-header-custom p-4">
                    <h5><i class="bi bi-receipt me-2"></i> Invoice Details</h5>
                    <p>Showing <?php echo $invoice_count; ?> invoices for the selected period</p>
                </div>

                <div style="overflow-x: auto;">
                    <table class="table-custom" id="ocReportTable">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Subtotal</th>
                                <th>Tax</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Pending</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                                <th>Profit</th>
                                <th style="text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($invoices && $invoices->num_rows > 0): 
                                $invoices->data_seek(0);
                                while ($inv = $invoices->fetch_assoc()):
                                    $paid = (float)$inv['total'] - (float)$inv['pending_amount'];
                                    $methodClass = $inv['payment_method'];
                                    $statusClass = $inv['payment_status'] == 'Paid' ? 'paid' : 'pending';
                            ?>
                                <tr>
                                    <td><span class="order-id"><?php echo htmlspecialchars($inv['inv_num']); ?></span></td>
                                    <td style="white-space: nowrap;">
                                        <?php echo date('d M Y', strtotime($inv['created_at'])); ?>
                                        <div class="text-muted" style="font-size: 10px;"><?php echo date('h:i A', strtotime($inv['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($inv['customer_name'] ?: 'Walk-in Customer'); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($inv['phone'] ?: '-'); ?></td>
                                    <td class="text-right">₹<?php echo number_format((float)$inv['subtotal'], 2); ?></td>
                                    <td class="text-right">₹<?php echo number_format((float)$inv['cgst_amount'] + (float)$inv['sgst_amount'], 2); ?></td>
                                    <td class="text-right fw-semibold">₹<?php echo number_format((float)$inv['total'], 2); ?></td>
                                    <td class="text-right text-success">₹<?php echo number_format($paid, 2); ?></td>
                                    <td class="text-right <?php echo (float)$inv['pending_amount'] > 0 ? 'text-danger' : ''; ?>">
                                        ₹<?php echo number_format((float)$inv['pending_amount'], 2); ?>
                                    </td>
                                    <td>
                                        <span class="method-badge <?php echo $methodClass; ?>">
                                            <i class="bi <?php echo $methodClass === 'cash' ? 'bi-cash-stack' : ($methodClass === 'card' ? 'bi-credit-card' : ($methodClass === 'upi' ? 'bi-phone' : ($methodClass === 'bank' ? 'bi-bank' : 'bi-clock-history'))); ?>"></i>
                                            <?php echo ucfirst($inv['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <i class="bi <?php echo $inv['payment_status'] == 'Paid' ? 'bi-check-circle' : 'bi-clock-history'; ?>"></i>
                                            <?php echo $inv['payment_status']; ?>
                                        </span>
                                    </td>
                                    <td class="text-right <?php echo (float)$inv['profit_amount'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        ₹<?php echo number_format((float)$inv['profit_amount'], 2); ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="invoice-view.php?id=<?php echo (int)$inv['id']; ?>" class="btn btn-sm btn-outline-info" title="View Invoice">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="print_invoice.php?id=<?php echo (int)$inv['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print Invoice">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="13" style="text-align: center; padding: 40px;">
                                        <i class="bi bi-receipt" style="font-size: 48px; color: #cbd5e1;"></i>
                                        <div class="mt-2">No invoices found for the selected period and filters.</div>
                                        <div class="text-muted small">Try changing your date range or resetting filters.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if ($invoice_count > 0): ?>
                        <tfoot style="background: #f8fafc; font-weight: 600;">
                            <tr>
                                <td colspan="4" class="text-end"><strong>Totals:</strong></td>
                                <td class="text-right"><strong>₹<?php echo number_format($total_sales - $total_tax, 2); ?></strong></td>
                                <td class="text-right"><strong>₹<?php echo number_format($total_tax, 2); ?></strong></td>
                                <td class="text-right"><strong>₹<?php echo number_format($total_sales, 2); ?></strong></td>
                                <td class="text-right"><strong>₹<?php echo number_format($total_paid, 2); ?></strong></td>
                                <td class="text-right"><strong>₹<?php echo number_format($total_pending, 2); ?></strong></td>
                                <td colspan="3"></td>
                                <td class="text-right"><strong>₹<?php echo number_format($total_profit, 2); ?></strong></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Calculation Note -->
            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle-fill me-2"></i>
                <strong>Report Calculation:</strong> 
                <strong>Opening Balance</strong> = Total pending amount from all invoices created before <?php echo date('d M Y', strtotime($reportDateFrom)); ?>.
                <strong>Closing Balance</strong> = Opening Balance + Sales (period) - Payments Collected (period).
                <strong>Payments Collected</strong> = Total amount paid towards invoices in this period.
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    $('#ocReportTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc']],
        language: {
            search: "Search invoices:",
            lengthMenu: "Show _MENU_ invoices",
            info: "Showing _START_ to _END_ of _TOTAL_ invoices",
            emptyTable: "No invoices available"
        },
        columnDefs: [
            { orderable: false, targets: [-1] }
        ]
    });
});
</script>
</body>
</html>