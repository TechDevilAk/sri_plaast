<?php
// payment-methods-report.php - Payment Methods Transaction Report
session_start();
$currentPage = 'payment-methods-report';
$pageTitle = 'Payment Methods Report';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view this report
checkRoleAccess(['admin']);

$success = '';
$error = '';

// Helper function to build query string with current filters
function buildQueryString($exclude = []) {
    $params = $_GET;
    $allFilters = [
        'report_date_from', 'report_date_to', 'filter_transaction_type', 
        'filter_payment_method', 'filter_party_type', 'filter_party_id',
        'filter_bank_account', 'export'
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
$filterTransactionType = $_GET['filter_transaction_type'] ?? 'all';
$filterPaymentMethod = $_GET['filter_payment_method'] ?? 'all';
$filterPartyType = $_GET['filter_party_type'] ?? 'all';
$filterPartyId = $_GET['filter_party_id'] ?? 'all';
$filterBankAccount = $_GET['filter_bank_account'] ?? 'all';

// Get bank accounts for filter
$bankAccounts = $conn->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY account_name ASC");

// Get customers for filter
$customers = $conn->query("SELECT id, customer_name FROM customers ORDER BY customer_name ASC");

// Get suppliers for filter
$suppliers = $conn->query("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC");

// ----------------------------------------------------------------------
// Main query to get all payment transactions from various sources
// This combines:
// 1. Invoice payments (sales)
// 2. Purchase payments
// 3. Expense payments
// 4. Opening balance payments
// ----------------------------------------------------------------------
$where = "1=1";
$params = [];
$types = "";

// Date range filter
if (!empty($reportDateFrom) && !empty($reportDateTo)) {
    $where .= " AND transaction_date BETWEEN ? AND ?";
    $params[] = $reportDateFrom;
    $params[] = $reportDateTo;
    $types .= "ss";
}

// Transaction type filter
if ($filterTransactionType !== 'all') {
    $where .= " AND transaction_type = ?";
    $params[] = $filterTransactionType;
    $types .= "s";
}

// Payment method filter
if ($filterPaymentMethod !== 'all') {
    $where .= " AND payment_method = ?";
    $params[] = $filterPaymentMethod;
    $types .= "s";
}

// Party type filter
if ($filterPartyType !== 'all') {
    $where .= " AND party_type = ?";
    $params[] = $filterPartyType;
    $types .= "s";
}

// Party filter (specific customer/supplier)
if ($filterPartyId !== 'all' && $filterPartyType !== 'all') {
    if ($filterPartyType === 'customer') {
        $where .= " AND reference_type = 'invoice' AND party_id = ?";
    } elseif ($filterPartyType === 'supplier') {
        $where .= " AND reference_type IN ('purchase', 'expense') AND party_id = ?";
    }
    $params[] = (int)$filterPartyId;
    $types .= "i";
}

// Bank account filter
if ($filterBankAccount !== 'all') {
    $where .= " AND bank_account_id = ?";
    $params[] = (int)$filterBankAccount;
    $types .= "i";
}

// Union query combining all transaction sources
$sql = "
    -- Invoice payments (sales)
    SELECT 
        bt.id,
        bt.transaction_date,
        'sale' as transaction_type,
        bt.payment_method,
        bt.amount,
        bt.reference_number as document_no,
        bt.party_name,
        bt.party_type,
        bt.bank_account_id,
        ba.account_name as bank_account_name,
        bt.description,
        bt.transaction_ref_no,
        bt.created_at,
        NULL as purchase_id,
        NULL as expense_id,
        NULL as opening_balance_id
    FROM bank_transactions bt
    LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id
    WHERE bt.transaction_type IN ('sale', 'sale_credit') AND bt.status = 'completed'
    
    UNION ALL
    
    -- Purchase payments
    SELECT 
        bt.id,
        bt.transaction_date,
        'purchase' as transaction_type,
        bt.payment_method,
        bt.amount,
        bt.reference_number as document_no,
        bt.party_name,
        bt.party_type,
        bt.bank_account_id,
        ba.account_name as bank_account_name,
        bt.description,
        bt.transaction_ref_no,
        bt.created_at,
        NULL as purchase_id,
        NULL as expense_id,
        NULL as opening_balance_id
    FROM bank_transactions bt
    LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id
    WHERE bt.transaction_type = 'purchase_payment' AND bt.status = 'completed'
    
    UNION ALL
    
    -- Expense payments
    SELECT 
        bt.id,
        bt.transaction_date,
        'expense' as transaction_type,
        bt.payment_method,
        bt.amount,
        bt.reference_number as document_no,
        bt.party_name,
        bt.party_type,
        bt.bank_account_id,
        ba.account_name as bank_account_name,
        bt.description,
        bt.transaction_ref_no,
        bt.created_at,
        NULL as purchase_id,
        NULL as expense_id,
        NULL as opening_balance_id
    FROM bank_transactions bt
    LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id
    WHERE bt.transaction_type = 'expense' AND bt.status = 'completed'
    
    UNION ALL
    
    -- Opening balance payments (customer)
    SELECT 
        obp.id,
        obp.payment_date as transaction_date,
        'opening_balance' as transaction_type,
        obp.payment_method,
        obp.amount,
        CONCAT('OB-', c.customer_name) as document_no,
        c.customer_name as party_name,
        'customer' as party_type,
        NULL as bank_account_id,
        NULL as bank_account_name,
        obp.notes as description,
        NULL as transaction_ref_no,
        obp.created_at,
        NULL as purchase_id,
        NULL as expense_id,
        obp.id as opening_balance_id
    FROM opening_balance_payments obp
    LEFT JOIN customers c ON obp.customer_id = c.id
    WHERE 1=1
    
    UNION ALL
    
    -- Cash payments from invoice table (for cash transactions not in bank_transactions)
    SELECT 
        i.id,
        i.created_at as transaction_date,
        'sale' as transaction_type,
        i.payment_method,
        CASE 
            WHEN i.payment_method = 'cash' THEN i.total - i.pending_amount
            WHEN i.payment_method = 'mixed' THEN i.cash_amount
            ELSE 0
        END as amount,
        i.inv_num as document_no,
        COALESCE(c.customer_name, i.customer_name) as party_name,
        'customer' as party_type,
        NULL as bank_account_id,
        NULL as bank_account_name,
        CONCAT('Cash payment for invoice ', i.inv_num) as description,
        NULL as transaction_ref_no,
        i.created_at,
        NULL as purchase_id,
        NULL as expense_id,
        NULL as opening_balance_id
    FROM invoice i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE (i.payment_method = 'cash' OR (i.payment_method = 'mixed' AND i.cash_amount > 0))
      AND (i.total - i.pending_amount) > 0
";

// Add WHERE clause and parameters
$fullSql = "SELECT * FROM ($sql) as combined WHERE $where ORDER BY transaction_date DESC, created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($fullSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $transactions = $stmt->get_result();
} else {
    $transactions = $conn->query($fullSql);
}

// ----------------------------------------------------------------------
// Calculate summary statistics
// ----------------------------------------------------------------------
$summary = [
    'total_cash' => 0,
    'total_card' => 0,
    'total_upi' => 0,
    'total_bank' => 0,
    'total_cheque' => 0,
    'total_credit' => 0,
    'total_mixed' => 0,
    'total_sales' => 0,
    'total_purchases' => 0,
    'total_expenses' => 0,
    'total_opening_balance' => 0,
    'total_transactions' => 0,
    'by_date' => []
];

if ($transactions && $transactions->num_rows > 0) {
    while ($row = $transactions->fetch_assoc()) {
        $amount = (float)$row['amount'];
        $method = $row['payment_method'];
        $type = $row['transaction_type'];
        $date = date('Y-m-d', strtotime($row['transaction_date']));
        
        // Method totals
        switch ($method) {
            case 'cash': $summary['total_cash'] += $amount; break;
            case 'card': $summary['total_card'] += $amount; break;
            case 'upi': $summary['total_upi'] += $amount; break;
            case 'bank': $summary['total_bank'] += $amount; break;
            case 'cheque': $summary['total_cheque'] += $amount; break;
            case 'credit': $summary['total_credit'] += $amount; break;
            case 'mixed': $summary['total_mixed'] += $amount; break;
        }
        
        // Type totals
        switch ($type) {
            case 'sale': $summary['total_sales'] += $amount; break;
            case 'purchase': $summary['total_purchases'] += $amount; break;
            case 'expense': $summary['total_expenses'] += $amount; break;
            case 'opening_balance': $summary['total_opening_balance'] += $amount; break;
        }
        
        $summary['total_transactions']++;
        
        // Daily breakdown
        if (!isset($summary['by_date'][$date])) {
            $summary['by_date'][$date] = [
                'total' => 0,
                'cash' => 0,
                'card' => 0,
                'upi' => 0,
                'bank' => 0
            ];
        }
        $summary['by_date'][$date]['total'] += $amount;
        if (in_array($method, ['cash', 'card', 'upi', 'bank'])) {
            $summary['by_date'][$date][$method] += $amount;
        }
    }
    // Reset pointer for display
    $transactions->data_seek(0);
}

$total_all_payments = $summary['total_cash'] + $summary['total_card'] + $summary['total_upi'] + 
                      $summary['total_bank'] + $summary['total_cheque'] + $summary['total_credit'] + 
                      $summary['total_mixed'];

// ----------------------------------------------------------------------
// Handle Export Functionality
// ----------------------------------------------------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['excel', 'csv', 'pdf'])) {
    $export_type = $_GET['export'];
    
    // Prepare data for export
    $export_transactions = [];
    if ($transactions && $transactions->num_rows > 0) {
        $transactions->data_seek(0);
        while ($row = $transactions->fetch_assoc()) {
            $export_transactions[] = [
                'Date' => date('d-m-Y', strtotime($row['transaction_date'])),
                'Type' => ucfirst(str_replace('_', ' ', $row['transaction_type'])),
                'Document No' => $row['document_no'],
                'Party Name' => $row['party_name'] ?: '-',
                'Payment Method' => ucfirst($row['payment_method']),
                'Amount' => $row['amount'],
                'Bank Account' => $row['bank_account_name'] ?: 'N/A',
                'Reference' => $row['transaction_ref_no'] ?: '-',
                'Description' => $row['description'] ?: '-'
            ];
        }
    }
    
    switch($export_type) {
        case 'csv':
        case 'excel':
            $filename = "payment_methods_report_" . date('Y-m-d');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Report Header
            fputcsv($output, ['PAYMENT METHODS REPORT']);
            fputcsv($output, ['Generated on: ' . date('d-m-Y H:i:s')]);
            fputcsv($output, ['Period: ' . date('d M Y', strtotime($reportDateFrom)) . ' to ' . date('d M Y', strtotime($reportDateTo))]);
            fputcsv($output, []);
            
            // Summary Section
            fputcsv($output, ['SUMMARY']);
            fputcsv($output, ['Total Transactions', $summary['total_transactions']]);
            fputcsv($output, ['Total Amount Received/Paid', '₹' . number_format($total_all_payments, 2)]);
            fputcsv($output, []);
            
            fputcsv($output, ['PAYMENT METHOD BREAKDOWN']);
            fputcsv($output, ['Cash', '₹' . number_format($summary['total_cash'], 2), $total_all_payments > 0 ? number_format(($summary['total_cash'] / $total_all_payments) * 100, 1) . '%' : '0%']);
            fputcsv($output, ['Card', '₹' . number_format($summary['total_card'], 2), $total_all_payments > 0 ? number_format(($summary['total_card'] / $total_all_payments) * 100, 1) . '%' : '0%']);
            fputcsv($output, ['UPI', '₹' . number_format($summary['total_upi'], 2), $total_all_payments > 0 ? number_format(($summary['total_upi'] / $total_all_payments) * 100, 1) . '%' : '0%']);
            fputcsv($output, ['Bank Transfer', '₹' . number_format($summary['total_bank'], 2), $total_all_payments > 0 ? number_format(($summary['total_bank'] / $total_all_payments) * 100, 1) . '%' : '0%']);
            fputcsv($output, ['Cheque', '₹' . number_format($summary['total_cheque'], 2), $total_all_payments > 0 ? number_format(($summary['total_cheque'] / $total_all_payments) * 100, 1) . '%' : '0%']);
            fputcsv($output, ['Credit', '₹' . number_format($summary['total_credit'], 2), $total_all_payments > 0 ? number_format(($summary['total_credit'] / $total_all_payments) * 100, 1) . '%' : '0%']);
            fputcsv($output, ['Mixed', '₹' . number_format($summary['total_mixed'], 2), $total_all_payments > 0 ? number_format(($summary['total_mixed'] / $total_all_payments) * 100, 1) . '%' : '0%']);
            fputcsv($output, []);
            
            fputcsv($output, ['TRANSACTION TYPE BREAKDOWN']);
            fputcsv($output, ['Sales (Payments Received)', '₹' . number_format($summary['total_sales'], 2)]);
            fputcsv($output, ['Purchases (Payments Made)', '₹' . number_format($summary['total_purchases'], 2)]);
            fputcsv($output, ['Expenses (Payments Made)', '₹' . number_format($summary['total_expenses'], 2)]);
            fputcsv($output, ['Opening Balance Collections', '₹' . number_format($summary['total_opening_balance'], 2)]);
            fputcsv($output, []);
            
            // Details Section
            fputcsv($output, ['TRANSACTION DETAILS']);
            if (!empty($export_transactions)) {
                fputcsv($output, array_keys($export_transactions[0]));
                foreach ($export_transactions as $row) {
                    $row['Amount'] = '₹' . number_format($row['Amount'], 2);
                    fputcsv($output, $row);
                }
            } else {
                fputcsv($output, ['No transactions found for the selected period.']);
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
                <title>Payment Methods Report</title>
                <style>
                    body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
                    h1 { color: #2463eb; text-align: center; margin-bottom: 5px; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .period { text-align: center; font-size: 14px; color: #475569; margin-bottom: 20px; }
                    .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
                    .summary-item { background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e2e8f0; }
                    .summary-label { font-size: 11px; color: #64748b; text-transform: uppercase; }
                    .summary-value { font-size: 20px; font-weight: bold; color: #1e293b; }
                    .method-row { display: flex; justify-content: space-between; padding: 8px; border-bottom: 1px solid #e2e8f0; }
                    .method-name { font-weight: bold; }
                    .method-amount { font-weight: bold; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 11px; }
                    th { background: #2463eb; color: white; padding: 8px; text-align: left; }
                    td { border: 1px solid #ddd; padding: 6px; }
                    tr:nth-child(even) { background: #f8fafc; }
                    .text-right { text-align: right; }
                    .badge-cash { background: #e8f2ff; color: #2463eb; padding: 2px 6px; border-radius: 12px; font-size: 10px; }
                    .badge-card { background: #f0fdf4; color: #16a34a; padding: 2px 6px; border-radius: 12px; font-size: 10px; }
                    .badge-upi { background: #fef3c7; color: #d97706; padding: 2px 6px; border-radius: 12px; font-size: 10px; }
                    .badge-bank { background: #f3e8ff; color: #9333ea; padding: 2px 6px; border-radius: 12px; font-size: 10px; }
                    .footer { text-align: center; margin-top: 30px; font-size: 10px; color: #64748b; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>PAYMENT METHODS REPORT</h1>
                    <p>Generated on: <?php echo date('d-m-Y H:i:s'); ?></p>
                    <div class="period">Period: <?php echo date('d M Y', strtotime($reportDateFrom)); ?> to <?php echo date('d M Y', strtotime($reportDateTo)); ?></div>
                </div>
                
                <!-- Summary Cards -->
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-label">Total Transactions</div>
                        <div class="summary-value"><?php echo $summary['total_transactions']; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Total Amount</div>
                        <div class="summary-value">₹<?php echo number_format($total_all_payments, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Sales Received</div>
                        <div class="summary-value">₹<?php echo number_format($summary['total_sales'], 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Payments Made</div>
                        <div class="summary-value">₹<?php echo number_format($summary['total_purchases'] + $summary['total_expenses'], 2); ?></div>
                    </div>
                </div>
                
                <!-- Payment Method Breakdown -->
                <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h4>Payment Method Breakdown</h4>
                    <?php
                    $methods = [
                        'Cash' => $summary['total_cash'],
                        'Card' => $summary['total_card'],
                        'UPI' => $summary['total_upi'],
                        'Bank Transfer' => $summary['total_bank'],
                        'Cheque' => $summary['total_cheque'],
                        'Credit' => $summary['total_credit'],
                        'Mixed' => $summary['total_mixed']
                    ];
                    foreach ($methods as $name => $amount):
                        if ($amount > 0 || $total_all_payments == 0):
                            $percentage = $total_all_payments > 0 ? ($amount / $total_all_payments) * 100 : 0;
                    ?>
                    <div class="method-row">
                        <span class="method-name"><?php echo $name; ?></span>
                        <span class="method-amount">₹<?php echo number_format($amount, 2); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                    </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
                
                <!-- Transaction Type Breakdown -->
                <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h4>Transaction Type Breakdown</h4>
                    <div class="method-row">
                        <span class="method-name">Sales (Payments Received)</span>
                        <span class="method-amount">₹<?php echo number_format($summary['total_sales'], 2); ?></span>
                    </div>
                    <div class="method-row">
                        <span class="method-name">Purchases (Payments Made)</span>
                        <span class="method-amount">₹<?php echo number_format($summary['total_purchases'], 2); ?></span>
                    </div>
                    <div class="method-row">
                        <span class="method-name">Expenses (Payments Made)</span>
                        <span class="method-amount">₹<?php echo number_format($summary['total_expenses'], 2); ?></span>
                    </div>
                    <div class="method-row">
                        <span class="method-name">Opening Balance Collections</span>
                        <span class="method-amount">₹<?php echo number_format($summary['total_opening_balance'], 2); ?></span>
                    </div>
                </div>
                
                <!-- Transaction Details Table -->
                <h4>Transaction Details (<?php echo $summary['total_transactions']; ?> transactions)</h4>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Document No</th>
                            <th>Party Name</th>
                            <th>Method</th>
                            <th class="text-right">Amount</th>
                            <th>Bank Account</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($transactions && $transactions->num_rows > 0):
                            $transactions->data_seek(0);
                            while ($row = $transactions->fetch_assoc()):
                                $method_class = '';
                                switch ($row['payment_method']) {
                                    case 'cash': $method_class = 'badge-cash'; break;
                                    case 'card': $method_class = 'badge-card'; break;
                                    case 'upi': $method_class = 'badge-upi'; break;
                                    case 'bank': $method_class = 'badge-bank'; break;
                                    default: $method_class = '';
                                }
                        ?>
                        <tr>
                            <td><?php echo date('d-m-Y', strtotime($row['transaction_date'])); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $row['transaction_type'])); ?></td>
                            <td><?php echo htmlspecialchars($row['document_no']); ?></td>
                            <td><?php echo htmlspecialchars($row['party_name'] ?: '-'); ?></td>
                            <td><span class="<?php echo $method_class; ?>"><?php echo ucfirst($row['payment_method']); ?></span></td>
                            <td class="text-right">₹<?php echo number_format((float)$row['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($row['bank_account_name'] ?: '-'); ?></td>
                        </tr>
                        <?php 
                            endwhile;
                        else: 
                        ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">No transactions found for the selected period.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot style="background: #f8fafc; font-weight: 600;">
                        <tr>
                            <td colspan="5" class="text-right"><strong>Total:</strong></td>
                            <td class="text-right"><strong>₹<?php echo number_format($total_all_payments, 2); ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                
                <div class="footer">
                    <p>This is a computer-generated report. Valid without signature.</p>
                    <p>Generated by Sri Plaast ERP System</p>
                </div>
            </body>
            </html>
            <?php
            $html = ob_get_clean();
            $filename = "payment_methods_report_" . date('Y-m-d');
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="' . $filename . '.html"');
            echo $html;
            exit;
    }
}

// Get unique transaction types for filter dropdown
$transaction_types = [
    'sale' => 'Sales Payments',
    'purchase' => 'Purchase Payments',
    'expense' => 'Expense Payments',
    'opening_balance' => 'Opening Balance Collections'
];
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
        .method-badge.cheque { background: #f1f5f9; color: #475569; }
        .method-badge.mixed { background: #f1f5f9; color: #475569; }
        .party-select-group {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
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
            .party-select-group {
                flex-direction: column;
                gap: 5px;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Payment Methods Report</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">
                        Track all payments by method - Sales, Purchases, Expenses & Opening Balance
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
                <form method="GET" action="payment-methods-report.php" id="reportForm">
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
                            <label class="form-label">Transaction Type</label>
                            <select name="filter_transaction_type" class="form-select">
                                <option value="all">All Transactions</option>
                                <?php foreach ($transaction_types as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $filterTransactionType === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Method</label>
                            <select name="filter_payment_method" class="form-select">
                                <option value="all">All Methods</option>
                                <option value="cash" <?php echo $filterPaymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="card" <?php echo $filterPaymentMethod === 'card' ? 'selected' : ''; ?>>Card</option>
                                <option value="upi" <?php echo $filterPaymentMethod === 'upi' ? 'selected' : ''; ?>>UPI</option>
                                <option value="bank" <?php echo $filterPaymentMethod === 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="cheque" <?php echo $filterPaymentMethod === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                <option value="credit" <?php echo $filterPaymentMethod === 'credit' ? 'selected' : ''; ?>>Credit</option>
                                <option value="mixed" <?php echo $filterPaymentMethod === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row g-3 mt-2">
                        <div class="col-md-3">
                            <label class="form-label">Party Type</label>
                            <select name="filter_party_type" class="form-select" id="partyTypeSelect">
                                <option value="all">All Parties</option>
                                <option value="customer" <?php echo $filterPartyType === 'customer' ? 'selected' : ''; ?>>Customer</option>
                                <option value="supplier" <?php echo $filterPartyType === 'supplier' ? 'selected' : ''; ?>>Supplier</option>
                            </select>
                        </div>
                        <div class="col-md-3" id="partySelectContainer">
                            <label class="form-label">Select Party</label>
                            <select name="filter_party_id" class="form-select" id="partySelect">
                                <option value="all">All</option>
                                <?php if ($filterPartyType === 'customer' && $customers): ?>
                                    <?php while ($c = $customers->fetch_assoc()): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $filterPartyId == $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['customer_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php elseif ($filterPartyType === 'supplier' && $suppliers): ?>
                                    <?php while ($s = $suppliers->fetch_assoc()): ?>
                                        <option value="<?php echo $s['id']; ?>" <?php echo $filterPartyId == $s['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($s['supplier_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Bank Account</label>
                            <select name="filter_bank_account" class="form-select">
                                <option value="all">All Accounts</option>
                                <?php 
                                $bankAccounts->data_seek(0);
                                while ($bank = $bankAccounts->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $bank['id']; ?>" <?php echo $filterBankAccount == $bank['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($bank['account_name'] . ' - ' . $bank['bank_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn-primary-custom w-100">
                                <i class="bi bi-search"></i> Generate Report
                            </button>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <a href="payment-methods-report.php" class="btn btn-outline-secondary w-100">
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
                            <div class="stat-value"><?php echo $summary['total_transactions']; ?></div>
                            <div class="stat-label">Total Transactions</div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="bi bi-arrow-left-right"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($total_all_payments, 2); ?></div>
                            <div class="stat-label">Total Amount</div>
                        </div>
                        <div class="stat-icon green">
                            <i class="bi bi-calculator"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($summary['total_sales'], 2); ?></div>
                            <div class="stat-label">Sales Received</div>
                        </div>
                        <div class="stat-icon purple">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($summary['total_purchases'] + $summary['total_expenses'], 2); ?></div>
                            <div class="stat-label">Payments Made</div>
                        </div>
                        <div class="stat-icon orange">
                            <i class="bi bi-wallet2"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Method Breakdown Cards -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="dashboard-card">
                        <div class="card-body p-4">
                            <h6 class="fw-semibold mb-3"><i class="bi bi-credit-card me-2"></i>Payment Method Breakdown</h6>
                            <div class="d-flex flex-wrap gap-3">
                                <?php
                                $method_data = [
                                    ['name' => 'Cash', 'amount' => $summary['total_cash'], 'icon' => 'bi-cash-stack', 'class' => 'cash'],
                                    ['name' => 'Card', 'amount' => $summary['total_card'], 'icon' => 'bi-credit-card', 'class' => 'card'],
                                    ['name' => 'UPI', 'amount' => $summary['total_upi'], 'icon' => 'bi-phone', 'class' => 'upi'],
                                    ['name' => 'Bank Transfer', 'amount' => $summary['total_bank'], 'icon' => 'bi-bank', 'class' => 'bank'],
                                    ['name' => 'Cheque', 'amount' => $summary['total_cheque'], 'icon' => 'bi-file-text', 'class' => 'cheque'],
                                    ['name' => 'Credit', 'amount' => $summary['total_credit'], 'icon' => 'bi-clock-history', 'class' => 'credit'],
                                    ['name' => 'Mixed', 'amount' => $summary['total_mixed'], 'icon' => 'bi-shuffle', 'class' => 'mixed']
                                ];
                                foreach ($method_data as $method):
                                    $percentage = $total_all_payments > 0 ? ($method['amount'] / $total_all_payments) * 100 : 0;
                                ?>
                                    <div class="flex-grow-1 text-center p-3 rounded-3" style="background: #f8fafc; min-width: 120px;">
                                        <i class="bi <?php echo $method['icon']; ?> fs-4 mb-2 d-block text-<?php echo $method['class'] == 'cash' ? 'primary' : ($method['class'] == 'card' ? 'success' : ($method['class'] == 'upi' ? 'warning' : 'secondary')); ?>"></i>
                                        <div class="fw-semibold"><?php echo $method['name']; ?></div>
                                        <div class="fs-5 fw-bold">₹<?php echo number_format($method['amount'], 2); ?></div>
                                        <div class="small text-muted"><?php echo number_format($percentage, 1); ?>%</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transaction Type Summary -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="dashboard-card text-center p-3">
                        <div class="text-muted mb-1">Sales Payments</div>
                        <div class="fw-bold fs-4 text-success">₹<?php echo number_format($summary['total_sales'], 2); ?></div>
                        <div class="small text-muted">Payments received from customers</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card text-center p-3">
                        <div class="text-muted mb-1">Purchase Payments</div>
                        <div class="fw-bold fs-4 text-danger">₹<?php echo number_format($summary['total_purchases'], 2); ?></div>
                        <div class="small text-muted">Payments made to suppliers</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card text-center p-3">
                        <div class="text-muted mb-1">Expense Payments</div>
                        <div class="fw-bold fs-4 text-warning">₹<?php echo number_format($summary['total_expenses'], 2); ?></div>
                        <div class="small text-muted">Payments for expenses</div>
                    </div>
                </div>
            </div>

            <!-- Transaction Details Table -->
            <div class="dashboard-card">
                <div class="card-header-custom p-4">
                    <h5><i class="bi bi-list-ul me-2"></i> Transaction Details</h5>
                    <p>Showing <?php echo $transactions ? (int)$transactions->num_rows : 0; ?> transactions</p>
                </div>

                <div style="overflow-x: auto;">
                    <table class="table-custom" id="paymentMethodsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Document No</th>
                                <th>Party Name</th>
                                <th>Payment Method</th>
                                <th class="text-right">Amount</th>
                                <th>Bank Account</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions && $transactions->num_rows > 0): 
                                $transactions->data_seek(0);
                                while ($row = $transactions->fetch_assoc()):
                                    $methodClass = $row['payment_method'];
                                    $typeLabel = '';
                                    switch ($row['transaction_type']) {
                                        case 'sale': $typeLabel = 'Sale Payment'; $typeIcon = 'bi-cash-coin'; break;
                                        case 'purchase': $typeLabel = 'Purchase Payment'; $typeIcon = 'bi-cart-check'; break;
                                        case 'expense': $typeLabel = 'Expense'; $typeIcon = 'bi-receipt'; break;
                                        case 'opening_balance': $typeLabel = 'Opening Balance'; $typeIcon = 'bi-calendar-check'; break;
                                        default: $typeLabel = ucfirst($row['transaction_type']); $typeIcon = 'bi-tag';
                                    }
                            ?>
                                <tr>
                                    <td style="white-space: nowrap;">
                                        <?php echo date('d M Y', strtotime($row['transaction_date'])); ?>
                                        <div class="text-muted" style="font-size: 10px;"><?php echo date('h:i A', strtotime($row['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <i class="bi <?php echo $typeIcon; ?> me-1"></i>
                                            <?php echo $typeLabel; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?php echo htmlspecialchars($row['document_no']); ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['party_name'] ?: '-'); ?></div>
                                        <div class="text-muted" style="font-size: 10px;"><?php echo ucfirst($row['party_type'] ?? '-'); ?></div>
                                    </td>
                                    <td>
                                        <span class="method-badge <?php echo $methodClass; ?>">
                                            <i class="bi <?php echo $methodClass === 'cash' ? 'bi-cash-stack' : ($methodClass === 'card' ? 'bi-credit-card' : ($methodClass === 'upi' ? 'bi-phone' : ($methodClass === 'bank' ? 'bi-bank' : ($methodClass === 'cheque' ? 'bi-file-text' : 'bi-clock-history')))); ?>"></i>
                                            <?php echo ucfirst($row['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right fw-semibold <?php echo in_array($row['transaction_type'], ['sale', 'opening_balance']) ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo in_array($row['transaction_type'], ['sale', 'opening_balance']) ? '+' : '-'; ?>
                                        ₹<?php echo number_format((float)$row['amount'], 2); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['bank_account_name'] ?: '-'); ?></td>
                                    <td>
                                        <?php if (!empty($row['transaction_ref_no'])): ?>
                                            <span class="text-muted small"><?php echo htmlspecialchars($row['transaction_ref_no']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px;">
                                        <i class="bi bi-credit-card" style="font-size: 48px; color: #cbd5e1;"></i>
                                        <div class="mt-2">No payment transactions found for the selected period and filters.</div>
                                        <div class="text-muted small">Try changing your date range or resetting filters.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if ($transactions && $transactions->num_rows > 0): ?>
                        <tfoot style="background: #f8fafc; font-weight: 600;">
                            <tr>
                                <td colspan="5" class="text-end"><strong>Total:</strong></td>
                                <td class="text-right"><strong>₹<?php echo number_format($total_all_payments, 2); ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Calculation Note -->
            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle-fill me-2"></i>
                <strong>Report Information:</strong> 
                This report tracks all payments including:
                <ul class="mb-0 mt-2">
                    <li><strong>Sales Payments</strong> - Payments received from invoices (including cash, card, UPI, bank transfers)</li>
                    <li><strong>Purchase Payments</strong> - Payments made to suppliers for purchases</li>
                    <li><strong>Expense Payments</strong> - Payments made for business expenses</li>
                    <li><strong>Opening Balance Collections</strong> - Payments collected for customer opening balances</li>
                </ul>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    $('#paymentMethodsTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search transactions:",
            lengthMenu: "Show _MENU_ transactions",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions",
            emptyTable: "No transactions available"
        },
        columnDefs: [
            { orderable: false, targets: [-1] }
        ]
    });
    
    // Dynamic party select based on party type
    function updatePartySelect() {
        const partyType = $('#partyTypeSelect').val();
        const currentPartyId = '<?php echo $filterPartyId; ?>';
        
        if (partyType === 'customer') {
            $.ajax({
                url: 'ajax/get_customers.php',
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    let options = '<option value="all">All Customers</option>';
                    $.each(data, function(i, customer) {
                        const selected = (currentPartyId == customer.id) ? 'selected' : '';
                        options += `<option value="${customer.id}" ${selected}>${customer.customer_name}</option>`;
                    });
                    $('#partySelect').html(options);
                }
            });
        } else if (partyType === 'supplier') {
            $.ajax({
                url: 'ajax/get_suppliers.php',
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    let options = '<option value="all">All Suppliers</option>';
                    $.each(data, function(i, supplier) {
                        const selected = (currentPartyId == supplier.id) ? 'selected' : '';
                        options += `<option value="${supplier.id}" ${selected}>${supplier.supplier_name}</option>`;
                    });
                    $('#partySelect').html(options);
                }
            });
        } else {
            $('#partySelect').html('<option value="all">All</option>');
        }
    }
    
    // Initial load
    updatePartySelect();
    
    // On party type change, update the select
    $('#partyTypeSelect').change(function() {
        updatePartySelect();
    });
});
</script>
</body>
</html>