<?php
session_start();
$currentPage = 'bank-acc-transactions';
$pageTitle = 'Bank Accounts & Transactions';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view, but only admin can modify
checkRoleAccess(['admin', 'sale']);

// Check if user is admin for modification permissions
$is_admin = ($_SESSION['user_role'] === 'admin');

// Initialize variables to prevent undefined warnings
$success = '';
$error = '';

// Helper functions
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function getTransactionTypeBadge($type) {
    $badges = [
        'in' => '<span class="badge bg-success"><i class="bi bi-arrow-down-circle"></i> In</span>',
        'out' => '<span class="badge bg-danger"><i class="bi bi-arrow-up-circle"></i> Out</span>',
        'sale' => '<span class="badge bg-info"><i class="bi bi-cash"></i> Sale</span>',
        'sale_credit' => '<span class="badge bg-warning"><i class="bi bi-credit-card"></i> Sale Credit</span>',
        'purchase' => '<span class="badge bg-secondary"><i class="bi bi-cart"></i> Purchase</span>',
        'purchase_payment' => '<span class="badge bg-primary"><i class="bi bi-credit-card-2-back"></i> Purchase Payment</span>',
        'expense' => '<span class="badge bg-danger"><i class="bi bi-wallet2"></i> Expense</span>',
        'transfer' => '<span class="badge bg-purple"><i class="bi bi-arrow-left-right"></i> Transfer</span>',
        'other' => '<span class="badge bg-light text-dark"><i class="bi bi-question-circle"></i> Other</span>'
    ];
    return $badges[$type] ?? $badges['other'];
}

function getPaymentMethodBadge($method) {
    $badges = [
        'cash' => '<span class="badge bg-success">Cash</span>',
        'card' => '<span class="badge bg-info">Card</span>',
        'upi' => '<span class="badge bg-primary">UPI</span>',
        'bank' => '<span class="badge bg-secondary">Bank Transfer</span>',
        'cheque' => '<span class="badge bg-warning">Cheque</span>',
        'credit' => '<span class="badge bg-danger">Credit</span>',
        'mixed' => '<span class="badge bg-dark">Mixed</span>'
    ];
    return $badges[$method] ?? '<span class="badge bg-light text-dark">' . ucfirst($method) . '</span>';
}

// Handle AJAX requests for fetching filtered transactions
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_filtered_transactions') {
    header('Content-Type: application/json');
    
    $bank_account_id = isset($_GET['bank_account_id']) && $_GET['bank_account_id'] !== '' ? intval($_GET['bank_account_id']) : null;
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $transaction_type = $_GET['transaction_type'] ?? '';
    $payment_method = $_GET['payment_method'] ?? '';
    $search_term = '%' . ($_GET['search_term'] ?? '') . '%';
    
    // Build the query
    $where_conditions = [];
    $params = [];
    $types = '';
    
    if ($bank_account_id) {
        $where_conditions[] = "t.bank_account_id = ?";
        $params[] = $bank_account_id;
        $types .= 'i';
    }
    
    if (!empty($date_from)) {
        $where_conditions[] = "t.transaction_date >= ?";
        $params[] = $date_from;
        $types .= 's';
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "t.transaction_date <= ?";
        $params[] = $date_to;
        $types .= 's';
    }
    
    if (!empty($transaction_type)) {
        if ($transaction_type === 'inflow') {
            $where_conditions[] = "t.transaction_type IN ('in', 'sale', 'sale_credit')";
        } elseif ($transaction_type === 'outflow') {
            $where_conditions[] = "t.transaction_type IN ('out', 'purchase', 'purchase_payment', 'expense', 'transfer')";
        } else {
            $where_conditions[] = "t.transaction_type = ?";
            $params[] = $transaction_type;
            $types .= 's';
        }
    }
    
    if (!empty($payment_method)) {
        $where_conditions[] = "t.payment_method = ?";
        $params[] = $payment_method;
        $types .= 's';
    }
    
    if (!empty($_GET['search_term'])) {
        $where_conditions[] = "(t.reference_number LIKE ? OR t.party_name LIKE ? OR t.description LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'sss';
    }
    
    $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    $query = "
        SELECT t.*, a.account_name, a.bank_name 
        FROM bank_transactions t
        LEFT JOIN bank_accounts a ON t.bank_account_id = a.id
        $where_sql
        ORDER BY t.transaction_date DESC, t.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    $total_inflow = 0;
    $total_outflow = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Calculate totals
        if (in_array($row['transaction_type'], ['in', 'sale', 'sale_credit'])) {
            $total_inflow += $row['amount'];
        } elseif (in_array($row['transaction_type'], ['out', 'purchase', 'purchase_payment', 'expense', 'transfer'])) {
            $total_outflow += $row['amount'];
        }
        
        $transactions[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'total_inflow' => $total_inflow,
        'total_outflow' => $total_outflow
    ]);
    exit;
}

// Handle AJAX for getting account summary
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_account_summary') {
    header('Content-Type: application/json');
    
    $bank_account_id = isset($_GET['bank_account_id']) && $_GET['bank_account_id'] !== '' ? intval($_GET['bank_account_id']) : null;
    
    $query = "
        SELECT 
            a.id,
            a.account_name,
            a.bank_name,
            a.current_balance,
            a.is_default,
            a.status,
            (SELECT COALESCE(SUM(amount), 0) FROM bank_transactions 
             WHERE bank_account_id = a.id 
             AND DATE(transaction_date) = CURDATE() 
             AND transaction_type IN ('in', 'sale', 'sale_credit')) as today_inflow,
            (SELECT COALESCE(SUM(amount), 0) FROM bank_transactions 
             WHERE bank_account_id = a.id 
             AND DATE(transaction_date) = CURDATE() 
             AND transaction_type IN ('out', 'purchase', 'purchase_payment', 'expense', 'transfer')) as today_outflow
        FROM bank_accounts a
    ";
    
    if ($bank_account_id) {
        $query .= " WHERE a.id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $bank_account_id);
    } else {
        $stmt = $conn->prepare($query);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $accounts = [];
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    
    echo json_encode(['success' => true, 'accounts' => $accounts]);
    exit;
}

// Handle AJAX for getting account transactions (for modal)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_account_transactions') {
    header('Content-Type: application/json');
    
    $account_id = intval($_GET['account_id'] ?? 0);
    
    if ($account_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid account ID']);
        exit;
    }
    
    $query = "
        SELECT t.*, a.account_name, a.bank_name 
        FROM bank_transactions t
        LEFT JOIN bank_accounts a ON t.bank_account_id = a.id
        WHERE t.bank_account_id = ?
        ORDER BY t.transaction_date DESC, t.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions
    ]);
    exit;
}

// Handle AJAX for exporting transactions
if (isset($_POST['export']) && $_POST['export'] === 'transactions') {
    $format = $_POST['format'] ?? 'csv';
    $bank_account_id = isset($_POST['bank_account_id']) && $_POST['bank_account_id'] !== '' ? intval($_POST['bank_account_id']) : null;
    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    $transaction_type = $_POST['transaction_type'] ?? '';
    $payment_method = $_POST['payment_method'] ?? '';
    $search_term = '%' . ($_POST['search_term'] ?? '') . '%';
    
    // Build the query
    $where_conditions = [];
    $params = [];
    $types = '';
    
    if ($bank_account_id) {
        $where_conditions[] = "t.bank_account_id = ?";
        $params[] = $bank_account_id;
        $types .= 'i';
    }
    
    if (!empty($date_from)) {
        $where_conditions[] = "t.transaction_date >= ?";
        $params[] = $date_from;
        $types .= 's';
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "t.transaction_date <= ?";
        $params[] = $date_to;
        $types .= 's';
    }
    
    if (!empty($transaction_type)) {
        if ($transaction_type === 'inflow') {
            $where_conditions[] = "t.transaction_type IN ('in', 'sale', 'sale_credit')";
        } elseif ($transaction_type === 'outflow') {
            $where_conditions[] = "t.transaction_type IN ('out', 'purchase', 'purchase_payment', 'expense', 'transfer')";
        } else {
            $where_conditions[] = "t.transaction_type = ?";
            $params[] = $transaction_type;
            $types .= 's';
        }
    }
    
    if (!empty($payment_method)) {
        $where_conditions[] = "t.payment_method = ?";
        $params[] = $payment_method;
        $types .= 's';
    }
    
    if (!empty($_POST['search_term'])) {
        $where_conditions[] = "(t.reference_number LIKE ? OR t.party_name LIKE ? OR t.description LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'sss';
    }
    
    $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    $query = "
        SELECT 
            t.transaction_date as 'Date',
            a.account_name as 'Account',
            a.bank_name as 'Bank',
            t.transaction_type as 'Type',
            t.reference_number as 'Reference No',
            t.party_name as 'Party',
            t.description as 'Description',
            t.amount as 'Amount',
            t.payment_method as 'Payment Method',
            t.status as 'Status',
            t.cheque_number as 'Cheque No',
            t.upi_ref_no as 'UPI Ref No',
            t.transaction_ref_no as 'Transaction Ref No',
            t.notes as 'Notes'
        FROM bank_transactions t
        LEFT JOIN bank_accounts a ON t.bank_account_id = a.id
        $where_sql
        ORDER BY t.transaction_date DESC, t.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $filename = 'bank_transactions_' . date('Y-m-d_His');
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add headers
        fputcsv($output, ['Date', 'Account', 'Bank', 'Type', 'Reference No', 'Party', 'Description', 'Amount', 'Payment Method', 'Status', 'Cheque No', 'UPI Ref No', 'Transaction Ref No', 'Notes']);
        
        // Add data
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    } elseif ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo "<table border='1'>";
        echo "<tr>";
        echo "<th>Date</th><th>Account</th><th>Bank</th><th>Type</th><th>Reference No</th><th>Party</th>";
        echo "<th>Description</th><th>Amount</th><th>Payment Method</th><th>Status</th>";
        echo "<th>Cheque No</th><th>UPI Ref No</th><th>Transaction Ref No</th><th>Notes</th>";
        echo "</tr>";
        
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Date'] . "</td>";
            echo "<td>" . $row['Account'] . "</td>";
            echo "<td>" . $row['Bank'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . $row['Reference No'] . "</td>";
            echo "<td>" . $row['Party'] . "</td>";
            echo "<td>" . $row['Description'] . "</td>";
            echo "<td>" . $row['Amount'] . "</td>";
            echo "<td>" . $row['Payment Method'] . "</td>";
            echo "<td>" . $row['Status'] . "</td>";
            echo "<td>" . $row['Cheque No'] . "</td>";
            echo "<td>" . $row['UPI Ref No'] . "</td>";
            echo "<td>" . $row['Transaction Ref No'] . "</td>";
            echo "<td>" . $row['Notes'] . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        exit;
    } elseif ($format === 'pdf') {
        require_once('libs/tcpdf/tcpdf.php');
        
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        $pdf->SetCreator('Sri Plaast');
        $pdf->SetAuthor('Sri Plaast');
        $pdf->SetTitle('Bank Transactions Report');
        
        $pdf->AddPage();
        
        $html = '<h1>Bank Transactions Report</h1>';
        $html .= '<p>Generated on: ' . date('d-m-Y H:i:s') . '</p>';
        $html .= '<table border="1" cellpadding="4">';
        $html .= '<tr>';
        $html .= '<th>Date</th><th>Account</th><th>Type</th><th>Reference</th><th>Party</th>';
        $html .= '<th>Description</th><th>Amount</th><th>Method</th><th>Status</th>';
        $html .= '</tr>';
        
        while ($row = $result->fetch_assoc()) {
            $html .= '<tr>';
            $html .= '<td>' . $row['Date'] . '</td>';
            $html .= '<td>' . $row['Account'] . '</td>';
            $html .= '<td>' . $row['Type'] . '</td>';
            $html .= '<td>' . $row['Reference No'] . '</td>';
            $html .= '<td>' . $row['Party'] . '</td>';
            $html .= '<td>' . $row['Description'] . '</td>';
            $html .= '<td>' . $row['Amount'] . '</td>';
            $html .= '<td>' . $row['Payment Method'] . '</td>';
            $html .= '<td>' . $row['Status'] . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
        
        $pdf->Output($filename . '.pdf', 'D');
        exit;
    }
}

// Handle AJAX requests for fetching reference details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_reference_details') {
    header('Content-Type: application/json');
    
    $ref_type = $_GET['ref_type'] ?? '';
    $ref_id = intval($_GET['ref_id'] ?? 0);
    
    $response = ['success' => false, 'data' => null];
    
    if ($ref_type === 'invoice' && $ref_id > 0) {
        $query = $conn->prepare("
            SELECT i.id, i.inv_num, i.total, i.cash_received, i.pending_amount, 
                   COALESCE(c.customer_name, i.customer_name) as party_name,
                   i.payment_method
            FROM invoice i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.id = ?
        ");
        $query->bind_param("i", $ref_id);
        $query->execute();
        $result = $query->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $response['success'] = true;
            $response['data'] = [
                'reference_number' => $row['inv_num'],
                'party_name' => $row['party_name'],
                'amount' => $row['total'],
                'paid_amount' => $row['cash_received'],
                'pending_amount' => $row['pending_amount'],
                'payment_method' => $row['payment_method']
            ];
        }
    } elseif ($ref_type === 'purchase' && $ref_id > 0) {
        $query = $conn->prepare("
            SELECT p.id, p.purchase_no, p.total, p.paid_amount, s.supplier_name as party_name
            FROM purchase p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            WHERE p.id = ?
        ");
        $query->bind_param("i", $ref_id);
        $query->execute();
        $result = $query->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $response['success'] = true;
            $response['data'] = [
                'reference_number' => $row['purchase_no'],
                'party_name' => $row['party_name'],
                'amount' => $row['total'],
                'paid_amount' => $row['paid_amount'],
                'pending_amount' => $row['total'] - $row['paid_amount']
            ];
        }
    } elseif ($ref_type === 'expense' && $ref_id > 0) {
        $query = $conn->prepare("
            SELECT e.id, e.expense_date, e.title, e.amount, e.paid_amount, e.pending_amount,
                   s.supplier_name as party_name
            FROM expense e
            LEFT JOIN suppliers s ON e.supplier_id = s.id
            WHERE e.id = ?
        ");
        $query->bind_param("i", $ref_id);
        $query->execute();
        $result = $query->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $response['success'] = true;
            $response['data'] = [
                'reference_number' => 'EXP-' . $row['id'],
                'party_name' => $row['party_name'] ?: $row['title'],
                'amount' => $row['amount'],
                'paid_amount' => $row['paid_amount'],
                'pending_amount' => $row['pending_amount']
            ];
        }
    }
    
    echo json_encode($response);
    exit;
}

// Handle AJAX for searching references
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_references') {
    header('Content-Type: application/json');
    
    $type = $_GET['type'] ?? '';
    $term = '%' . ($_GET['term'] ?? '') . '%';
    
    $results = [];
    
    if ($type === 'invoice') {
        $query = $conn->prepare("
            SELECT i.id, i.inv_num, COALESCE(c.customer_name, i.customer_name) as customer_name, 
                   i.total, i.cash_received, i.pending_amount
            FROM invoice i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.inv_num LIKE ? OR COALESCE(c.customer_name, i.customer_name) LIKE ?
            ORDER BY i.created_at DESC
            LIMIT 50
        ");
        $query->bind_param("ss", $term, $term);
        $query->execute();
        $result = $query->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $results[] = [
                'id' => $row['id'],
                'text' => $row['inv_num'] . ' - ' . $row['customer_name'] . ' (₹' . number_format($row['total'], 2) . ')',
                'reference_number' => $row['inv_num'],
                'party_name' => $row['customer_name'],
                'amount' => $row['total'],
                'paid_amount' => $row['cash_received'],
                'pending_amount' => $row['pending_amount']
            ];
        }
    } elseif ($type === 'purchase') {
        $query = $conn->prepare("
            SELECT p.id, p.purchase_no, s.supplier_name, p.total, p.paid_amount
            FROM purchase p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            WHERE p.purchase_no LIKE ? OR s.supplier_name LIKE ?
            ORDER BY p.created_at DESC
            LIMIT 50
        ");
        $query->bind_param("ss", $term, $term);
        $query->execute();
        $result = $query->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $results[] = [
                'id' => $row['id'],
                'text' => $row['purchase_no'] . ' - ' . $row['supplier_name'] . ' (₹' . number_format($row['total'], 2) . ')',
                'reference_number' => $row['purchase_no'],
                'party_name' => $row['supplier_name'],
                'amount' => $row['total'],
                'paid_amount' => $row['paid_amount'],
                'pending_amount' => $row['total'] - $row['paid_amount']
            ];
        }
    } elseif ($type === 'expense') {
        $query = $conn->prepare("
            SELECT e.id, e.expense_date, e.title, e.amount, e.paid_amount, e.pending_amount,
                   s.supplier_name
            FROM expense e
            LEFT JOIN suppliers s ON e.supplier_id = s.id
            WHERE e.title LIKE ? OR s.supplier_name LIKE ?
            ORDER BY e.expense_date DESC
            LIMIT 50
        ");
        $query->bind_param("ss", $term, $term);
        $query->execute();
        $result = $query->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $results[] = [
                'id' => $row['id'],
                'text' => 'EXP-' . $row['id'] . ' - ' . ($row['supplier_name'] ?: $row['title']) . ' (₹' . number_format($row['amount'], 2) . ')',
                'reference_number' => 'EXP-' . $row['id'],
                'party_name' => $row['supplier_name'] ?: $row['title'],
                'amount' => $row['amount'],
                'paid_amount' => $row['paid_amount'],
                'pending_amount' => $row['pending_amount']
            ];
        }
    }
    
    echo json_encode(['results' => $results]);
    exit;
}

// Handle CRUD operations
// ==================== BANK ACCOUNT OPERATIONS ====================

// Add Bank Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_account') {
    if (!$is_admin) {
        $error = 'You do not have permission to add bank accounts.';
    } else {
        $account_name = trim($_POST['account_name'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $ifsc_code = trim($_POST['ifsc_code'] ?? '');
        $upi_id = trim($_POST['upi_id'] ?? '');
        $account_type = trim($_POST['account_type'] ?? '');
        $opening_balance = floatval($_POST['opening_balance'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        
        if (empty($account_name) || empty($bank_name)) {
            $error = 'Account Name and Bank Name are required.';
        } else {
            $current_balance = $opening_balance;
            
            $stmt = $conn->prepare("
                INSERT INTO bank_accounts 
                (account_name, bank_name, branch, account_number, ifsc_code, upi_id, account_type, 
                 opening_balance, current_balance, is_default, notes, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->bind_param("sssssssddds", $account_name, $bank_name, $branch, $account_number, 
                             $ifsc_code, $upi_id, $account_type, $opening_balance, $current_balance, 
                             $is_default, $notes);
            
            if ($stmt->execute()) {
                $account_id = $stmt->insert_id;
                
                // Log activity
                $log_desc = "Added new bank account: $account_name - $bank_name";
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $success = "Bank account added successfully.";
            } else {
                $error = "Failed to add bank account: " . $conn->error;
            }
        }
    }
}

// Update Bank Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_account') {
    if (!$is_admin) {
        $error = 'You do not have permission to update bank accounts.';
    } else {
        $account_id = intval($_POST['account_id'] ?? 0);
        $account_name = trim($_POST['account_name'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $ifsc_code = trim($_POST['ifsc_code'] ?? '');
        $upi_id = trim($_POST['upi_id'] ?? '');
        $account_type = trim($_POST['account_type'] ?? '');
        $opening_balance = floatval($_POST['opening_balance'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        
        if ($account_id <= 0 || empty($account_name) || empty($bank_name)) {
            $error = 'Invalid data. Account Name and Bank Name are required.';
        } else {
            // Get current account data to adjust balance if opening balance changed
            $old_query = $conn->prepare("SELECT opening_balance, current_balance FROM bank_accounts WHERE id = ?");
            $old_query->bind_param("i", $account_id);
            $old_query->execute();
            $old_data = $old_query->get_result()->fetch_assoc();
            
            if ($old_data) {
                $balance_diff = $opening_balance - $old_data['opening_balance'];
                $new_current_balance = $old_data['current_balance'] + $balance_diff;
                
                $stmt = $conn->prepare("
                    UPDATE bank_accounts SET 
                        account_name = ?, bank_name = ?, branch = ?, account_number = ?, 
                        ifsc_code = ?, upi_id = ?, account_type = ?, opening_balance = ?, 
                        current_balance = ?, is_default = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("sssssssdddsi", $account_name, $bank_name, $branch, $account_number, 
                                 $ifsc_code, $upi_id, $account_type, $opening_balance, $new_current_balance, 
                                 $is_default, $notes, $account_id);
                
                if ($stmt->execute()) {
                    // Log activity
                    $log_desc = "Updated bank account: $account_name";
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $success = "Bank account updated successfully.";
                } else {
                    $error = "Failed to update bank account: " . $conn->error;
                }
            } else {
                $error = "Bank account not found.";
            }
        }
    }
}

// Delete Bank Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    if (!$is_admin) {
        $error = 'You do not have permission to delete bank accounts.';
    } else {
        $account_id = intval($_POST['account_id'] ?? 0);
        
        if ($account_id <= 0) {
            $error = 'Invalid account ID.';
        } else {
            // Check if account has transactions
            $check_query = $conn->prepare("SELECT COUNT(*) as count FROM bank_transactions WHERE bank_account_id = ?");
            $check_query->bind_param("i", $account_id);
            $check_query->execute();
            $check_result = $check_query->get_result();
            $tx_count = $check_result->fetch_assoc()['count'];
            
            if ($tx_count > 0) {
                $error = "Cannot delete account with $tx_count transaction(s). Please deactivate it instead.";
            } else {
                $stmt = $conn->prepare("DELETE FROM bank_accounts WHERE id = ?");
                $stmt->bind_param("i", $account_id);
                
                if ($stmt->execute()) {
                    $success = "Bank account deleted successfully.";
                } else {
                    $error = "Failed to delete bank account: " . $conn->error;
                }
            }
        }
    }
}

// Toggle Account Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    if (!$is_admin) {
        $error = 'You do not have permission to change account status.';
    } else {
        $account_id = intval($_POST['account_id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        
        $stmt = $conn->prepare("UPDATE bank_accounts SET status = ? WHERE id = ?");
        $stmt->bind_param("ii", $status, $account_id);
        
        if ($stmt->execute()) {
            $success = "Account status updated successfully.";
        } else {
            $error = "Failed to update status: " . $conn->error;
        }
    }
}

// ==================== TRANSACTION OPERATIONS ====================

// Add Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_transaction') {
    if (!$is_admin) {
        $error = 'You do not have permission to add transactions.';
    } else {
        $bank_account_id = intval($_POST['bank_account_id'] ?? 0);
        $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
        $transaction_type = $_POST['transaction_type'] ?? 'other';
        $reference_type = $_POST['reference_type'] ?? null;
        $reference_id = !empty($_POST['reference_id']) ? intval($_POST['reference_id']) : null;
        $reference_number = trim($_POST['reference_number'] ?? '');
        $party_name = trim($_POST['party_name'] ?? '');
        $party_type = $_POST['party_type'] ?? 'other';
        $description = trim($_POST['description'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'bank';
        $status = $_POST['status'] ?? 'completed';
        $cheque_number = trim($_POST['cheque_number'] ?? '');
        $cheque_date = !empty($_POST['cheque_date']) ? $_POST['cheque_date'] : null;
        $cheque_bank = trim($_POST['cheque_bank'] ?? '');
        $upi_ref_no = trim($_POST['upi_ref_no'] ?? '');
        $transaction_ref_no = trim($_POST['transaction_ref_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($bank_account_id <= 0 || $amount <= 0) {
            $error = 'Bank account and valid amount are required.';
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert transaction
                $stmt = $conn->prepare("
                    INSERT INTO bank_transactions 
                    (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, 
                     reference_number, party_name, party_type, description, amount, payment_method, 
                     status, cheque_number, cheque_date, cheque_bank, upi_ref_no, transaction_ref_no, 
                     notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("isssissssdssssssssi", 
                    $bank_account_id, $transaction_date, $transaction_type, $reference_type, $reference_id,
                    $reference_number, $party_name, $party_type, $description, $amount, $payment_method,
                    $status, $cheque_number, $cheque_date, $cheque_bank, $upi_ref_no, $transaction_ref_no,
                    $notes, $_SESSION['user_id']
                );
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to add transaction: " . $conn->error);
                }
                
                $transaction_id = $stmt->insert_id;
                
                // Update bank account balance
                $balance_query = $conn->prepare("SELECT current_balance FROM bank_accounts WHERE id = ?");
                $balance_query->bind_param("i", $bank_account_id);
                $balance_query->execute();
                $balance_result = $balance_query->get_result();
                $current_balance = $balance_result->fetch_assoc()['current_balance'];
                
                $new_balance = $current_balance;
                if (in_array($transaction_type, ['in', 'sale', 'sale_credit'])) {
                    $new_balance += $amount;
                } elseif (in_array($transaction_type, ['out', 'purchase', 'purchase_payment', 'expense', 'transfer'])) {
                    $new_balance -= $amount;
                }
                
                $update_balance = $conn->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
                $update_balance->bind_param("di", $new_balance, $bank_account_id);
                
                if (!$update_balance->execute()) {
                    throw new Exception("Failed to update balance: " . $conn->error);
                }
                
                // If this transaction is for an invoice, update the invoice's bank transaction link
                if ($reference_type === 'invoice' && $reference_id > 0) {
                    $update_invoice = $conn->prepare("
                        UPDATE invoice SET bank_account_id = ?, bank_transaction_id = ? 
                        WHERE id = ?
                    ");
                    $update_invoice->bind_param("iii", $bank_account_id, $transaction_id, $reference_id);
                    $update_invoice->execute();
                }
                
                // If this transaction is for a purchase, update the purchase's bank transaction link
                if ($reference_type === 'purchase' && $reference_id > 0) {
                    $update_purchase = $conn->prepare("
                        UPDATE purchase SET bank_account_id = ?, bank_transaction_id = ? 
                        WHERE id = ?
                    ");
                    $update_purchase->bind_param("iii", $bank_account_id, $transaction_id, $reference_id);
                    $update_purchase->execute();
                }
                
                // If this transaction is for an expense, update the expense's bank transaction link
                if ($reference_type === 'expense' && $reference_id > 0) {
                    $update_expense = $conn->prepare("
                        UPDATE expense SET bank_account_id = ?, bank_transaction_id = ? 
                        WHERE id = ?
                    ");
                    $update_expense->bind_param("iii", $bank_account_id, $transaction_id, $reference_id);
                    $update_expense->execute();
                }
                
                // Log activity
                $log_desc = "Added bank transaction: ₹" . number_format($amount, 2) . " ($transaction_type)";
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'create', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $conn->commit();
                $success = "Transaction added successfully.";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

// Update Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_transaction') {
    if (!$is_admin) {
        $error = 'You do not have permission to update transactions.';
    } else {
        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        $bank_account_id = intval($_POST['bank_account_id'] ?? 0);
        $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
        $transaction_type = $_POST['transaction_type'] ?? 'other';
        $reference_type = $_POST['reference_type'] ?? null;
        $reference_id = !empty($_POST['reference_id']) ? intval($_POST['reference_id']) : null;
        $reference_number = trim($_POST['reference_number'] ?? '');
        $party_name = trim($_POST['party_name'] ?? '');
        $party_type = $_POST['party_type'] ?? 'other';
        $description = trim($_POST['description'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'bank';
        $status = $_POST['status'] ?? 'completed';
        $cheque_number = trim($_POST['cheque_number'] ?? '');
        $cheque_date = !empty($_POST['cheque_date']) ? $_POST['cheque_date'] : null;
        $cheque_bank = trim($_POST['cheque_bank'] ?? '');
        $upi_ref_no = trim($_POST['upi_ref_no'] ?? '');
        $transaction_ref_no = trim($_POST['transaction_ref_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($transaction_id <= 0 || $bank_account_id <= 0 || $amount <= 0) {
            $error = 'Invalid data.';
        } else {
            $conn->begin_transaction();
            
            try {
                // Get old transaction to reverse balance
                $old_query = $conn->prepare("SELECT * FROM bank_transactions WHERE id = ?");
                $old_query->bind_param("i", $transaction_id);
                $old_query->execute();
                $old_data = $old_query->get_result()->fetch_assoc();
                
                if (!$old_data) {
                    throw new Exception("Transaction not found.");
                }
                
                // Reverse old transaction effect on balance
                $balance_query = $conn->prepare("SELECT current_balance FROM bank_accounts WHERE id = ?");
                $balance_query->bind_param("i", $old_data['bank_account_id']);
                $balance_query->execute();
                $balance_result = $balance_query->get_result();
                $current_balance = $balance_result->fetch_assoc()['current_balance'];
                
                if (in_array($old_data['transaction_type'], ['in', 'sale', 'sale_credit'])) {
                    $current_balance -= $old_data['amount'];
                } elseif (in_array($old_data['transaction_type'], ['out', 'purchase', 'purchase_payment', 'expense', 'transfer'])) {
                    $current_balance += $old_data['amount'];
                }
                
                // Apply new transaction effect
                if (in_array($transaction_type, ['in', 'sale', 'sale_credit'])) {
                    $current_balance += $amount;
                } elseif (in_array($transaction_type, ['out', 'purchase', 'purchase_payment', 'expense', 'transfer'])) {
                    $current_balance -= $amount;
                }
                
                // Update balance for the account
                $update_balance = $conn->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
                $update_balance->bind_param("di", $current_balance, $bank_account_id);
                
                if (!$update_balance->execute()) {
                    throw new Exception("Failed to update balance.");
                }
                
                // Update transaction
                $stmt = $conn->prepare("
                    UPDATE bank_transactions SET 
                        bank_account_id = ?, transaction_date = ?, transaction_type = ?, 
                        reference_type = ?, reference_id = ?, reference_number = ?, party_name = ?, 
                        party_type = ?, description = ?, amount = ?, payment_method = ?, status = ?, 
                        cheque_number = ?, cheque_date = ?, cheque_bank = ?, upi_ref_no = ?, 
                        transaction_ref_no = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("isssissssdsssssssssi", 
                    $bank_account_id, $transaction_date, $transaction_type,
                    $reference_type, $reference_id, $reference_number, $party_name,
                    $party_type, $description, $amount, $payment_method, $status,
                    $cheque_number, $cheque_date, $cheque_bank, $upi_ref_no,
                    $transaction_ref_no, $notes, $transaction_id
                );
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update transaction: " . $conn->error);
                }
                
                $conn->commit();
                $success = "Transaction updated successfully.";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

// Delete Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_transaction') {
    if (!$is_admin) {
        $error = 'You do not have permission to delete transactions.';
    } else {
        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        
        if ($transaction_id <= 0) {
            $error = 'Invalid transaction ID.';
        } else {
            $conn->begin_transaction();
            
            try {
                // Get transaction details
                $tx_query = $conn->prepare("SELECT * FROM bank_transactions WHERE id = ?");
                $tx_query->bind_param("i", $transaction_id);
                $tx_query->execute();
                $tx_data = $tx_query->get_result()->fetch_assoc();
                
                if (!$tx_data) {
                    throw new Exception("Transaction not found.");
                }
                
                // Reverse transaction effect on balance
                $balance_query = $conn->prepare("SELECT current_balance FROM bank_accounts WHERE id = ?");
                $balance_query->bind_param("i", $tx_data['bank_account_id']);
                $balance_query->execute();
                $balance_result = $balance_query->get_result();
                $current_balance = $balance_result->fetch_assoc()['current_balance'];
                
                if (in_array($tx_data['transaction_type'], ['in', 'sale', 'sale_credit'])) {
                    $current_balance -= $tx_data['amount'];
                } elseif (in_array($tx_data['transaction_type'], ['out', 'purchase', 'purchase_payment', 'expense', 'transfer'])) {
                    $current_balance += $tx_data['amount'];
                }
                
                $update_balance = $conn->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
                $update_balance->bind_param("di", $current_balance, $tx_data['bank_account_id']);
                
                if (!$update_balance->execute()) {
                    throw new Exception("Failed to update balance.");
                }
                
                // Delete transaction
                $stmt = $conn->prepare("DELETE FROM bank_transactions WHERE id = ?");
                $stmt->bind_param("i", $transaction_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete transaction.");
                }
                
                $conn->commit();
                $success = "Transaction deleted successfully.";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

// ==================== GET DATA ====================

// Get all bank accounts for dropdown
$accounts_query = "SELECT * FROM bank_accounts ORDER BY is_default DESC, account_name ASC";
$accounts = $conn->query($accounts_query);

// Get default account for credit transactions
$default_account_query = "SELECT * FROM bank_accounts WHERE is_default = 1 AND status = 1 LIMIT 1";
$default_account = $conn->query($default_account_query)->fetch_assoc();

// Get initial summary statistics
$summary_query = "
    SELECT 
        COUNT(*) as total_accounts,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_accounts,
        SUM(current_balance) as total_balance,
        (SELECT COUNT(*) FROM bank_transactions WHERE DATE(transaction_date) = CURDATE()) as today_transactions,
        (SELECT SUM(amount) FROM bank_transactions WHERE DATE(transaction_date) = CURDATE() AND transaction_type IN ('in','sale','sale_credit')) as today_in,
        (SELECT SUM(amount) FROM bank_transactions WHERE DATE(transaction_date) = CURDATE() AND transaction_type IN ('out','purchase','purchase_payment','expense')) as today_out
    FROM bank_accounts
";
$summary = $conn->query($summary_query)->fetch_assoc();

// Get initial transactions
$transactions_query = "
    SELECT t.*, a.account_name, a.bank_name 
    FROM bank_transactions t
    LEFT JOIN bank_accounts a ON t.bank_account_id = a.id
    ORDER BY t.transaction_date DESC, t.created_at DESC
    LIMIT 100
";
$transactions = $conn->query($transactions_query);

// Get all transaction types for filter
$tx_types_query = "SELECT DISTINCT transaction_type FROM bank_transactions ORDER BY transaction_type";
$tx_types = $conn->query($tx_types_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/buttons/2.2.3/css/buttons.dataTables.min.css" rel="stylesheet" />
    <style>
        .bank-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
            height: 100%;
            position: relative;
            cursor: pointer;
        }
        .bank-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }
        .bank-card.selected {
            border: 2px solid #3b82f6;
            background: #f0f7ff;
        }
        .bank-card.default {
            border: 2px solid #3b82f6;
        }
        .bank-card.inactive {
            opacity: 0.7;
            background: #f8fafc;
        }
        .bank-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .bank-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .bank-icon.default-icon {
            background: #10b981;
        }
        .bank-name {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }
        .bank-account-name {
            font-size: 14px;
            color: #64748b;
        }
        .bank-balance {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin: 15px 0 5px;
        }
        .bank-today-stats {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #e2e8f0;
        }
        .today-stat {
            flex: 1;
            text-align: center;
        }
        .today-stat-label {
            font-size: 11px;
            color: #64748b;
        }
        .today-stat-value {
            font-size: 14px;
            font-weight: 600;
        }
        .today-stat-value.inflow {
            color: #10b981;
        }
        .today-stat-value.outflow {
            color: #ef4444;
        }
        .bank-detail {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .bank-detail i {
            width: 16px;
            color: #94a3b8;
        }
        .default-badge {
            background: #3b82f6;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }
        .status-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .status-badge.active {
            background: #dcfce7;
            color: #166534;
        }
        .status-badge.inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
            margin-bottom: 24px;
        }
        .filter-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 15px;
        }
        .filter-badge {
            background: #e2e8f0;
            color: #1e293b;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card-custom {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
        }
        .stat-value-large {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }
        .stat-label {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }
        .transaction-row {
            cursor: pointer;
            transition: background 0.2s;
        }
        .transaction-row:hover {
            background: #f8fafc;
        }
        .amount-in {
            color: #10b981;
            font-weight: 600;
        }
        .amount-out {
            color: #ef4444;
            font-weight: 600;
        }
        .nav-tabs-custom {
            display: flex;
            gap: 10px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .nav-tab-custom {
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            color: #64748b;
            transition: all 0.2s;
            border: none;
            background: transparent;
        }
        .nav-tab-custom:hover {
            background: #f1f5f9;
            color: #1e293b;
        }
        .nav-tab-custom.active {
            background: #3b82f6;
            color: white;
        }
        .nav-tab-custom i {
            margin-right: 8px;
        }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin: 20px 0 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .btn-add {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-add:hover {
            background: #2563eb;
            color: white;
        }
        .btn-filter {
            background: #f1f5f9;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-filter:hover {
            background: #e2e8f0;
        }
        .btn-clear {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-clear:hover {
            background: #fecaca;
        }
        .reference-info {
            font-size: 11px;
            color: #64748b;
            background: #f8fafc;
            padding: 4px 8px;
            border-radius: 4px;
            margin-top: 4px;
        }
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        .summary-cards-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 15px;
        }
        .summary-card.inflow {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .summary-card.outflow {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        .summary-label {
            font-size: 12px;
            opacity: 0.9;
        }
        .summary-value {
            font-size: 24px;
            font-weight: 700;
        }
        .active-filter {
            background: #dbeafe;
            border: 1px solid #3b82f6;
            color: #1e3a8a;
        }
        .export-buttons {
            display: flex;
            gap: 8px;
        }
        .btn-export {
            background: #10b981;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-export:hover {
            background: #059669;
            color: white;
        }
        .view-transactions-btn {
            background: #8b5cf6;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .view-transactions-btn:hover {
            background: #7c3aed;
            color: white;
        }
        .modal-xl {
            max-width: 90%;
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Bank Accounts & Transactions</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage bank accounts and track all financial transactions</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if (!$is_admin): ?>
                        <span class="permission-badge"><i class="bi bi-eye"></i> View Only Mode</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bootstrap Alerts -->
            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large" id="totalBalance"><?php echo formatCurrency($summary['total_balance'] ?? 0); ?></div>
                            <div class="stat-label">Total Balance (All Accounts)</div>
                        </div>
                        <div class="stat-icon green" style="width: 48px; height: 48px; background: #d1fae5; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-cash-stack" style="color: #059669; font-size: 24px;"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large" id="todayInflow"><?php echo formatCurrency($summary['today_in'] ?? 0); ?></div>
                            <div class="stat-label">Today's Total Inflow</div>
                        </div>
                        <div class="stat-icon success" style="width: 48px; height: 48px; background: #d1fae5; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-arrow-down-circle" style="color: #059669; font-size: 24px;"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large" id="todayOutflow"><?php echo formatCurrency($summary['today_out'] ?? 0); ?></div>
                            <div class="stat-label">Today's Total Outflow</div>
                        </div>
                        <div class="stat-icon danger" style="width: 48px; height: 48px; background: #fee2e2; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-arrow-up-circle" style="color: #dc2626; font-size: 24px;"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-title">
                    <i class="bi bi-funnel me-2"></i> Filter Transactions
                    <span class="filter-badge ms-2" id="activeFilterCount" style="display: none;">0 filters active</span>
                </div>
                
                <div class="row g-3">
                    <!-- Bank Account Filter -->
                    <div class="col-md-3">
                        <label class="form-label">Bank Account</label>
                        <select class="form-select" id="filterBankAccount">
                            <option value="">All Accounts</option>
                            <?php
                            $accounts->data_seek(0);
                            while ($acc = $accounts->fetch_assoc()):
                            ?>
                                <option value="<?php echo $acc['id']; ?>">
                                    <?php echo htmlspecialchars($acc['account_name'] . ' - ' . $acc['bank_name']); ?>
                                    <?php echo $acc['is_default'] ? ' (Default)' : ''; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <!-- Date Range Filter -->
                    <div class="col-md-3">
                        <label class="form-label">Date Range</label>
                        <input type="text" class="form-control" id="dateRange" placeholder="Select date range">
                        <input type="hidden" id="dateFrom" name="date_from">
                        <input type="hidden" id="dateTo" name="date_to">
                    </div>
                    
                    <!-- Transaction Type Filter -->
                    <div class="col-md-2">
                        <label class="form-label">Transaction Type</label>
                        <select class="form-select" id="filterTransactionType">
                            <option value="">All Types</option>
                            <option value="inflow">All Inflow (In/Sale/Sale Credit)</option>
                            <option value="outflow">All Outflow (Out/Purchase/Expense)</option>
                            <option value="in">Money In</option>
                            <option value="out">Money Out</option>
                            <option value="sale">Sale</option>
                            <option value="sale_credit">Sale Credit</option>
                            <option value="purchase">Purchase</option>
                            <option value="purchase_payment">Purchase Payment</option>
                            <option value="expense">Expense</option>
                            <option value="transfer">Transfer</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <!-- Payment Method Filter -->
                    <div class="col-md-2">
                        <label class="form-label">Payment Method</label>
                        <select class="form-select" id="filterPaymentMethod">
                            <option value="">All Methods</option>
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="upi">UPI</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="credit">Credit</option>
                            <option value="mixed">Mixed</option>
                        </select>
                    </div>
                    
                    <!-- Search Term -->
                    <div class="col-md-2">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" id="filterSearch" placeholder="Ref/Party/Desc">
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12 d-flex gap-2 justify-content-end">
                        <button class="btn-filter" id="applyFilters">
                            <i class="bi bi-funnel"></i> Apply Filters
                        </button>
                        <button class="btn-clear" id="clearFilters">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </button>
                        <button class="btn-add" id="refreshData">
                            <i class="bi bi-arrow-repeat"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filtered Summary Cards -->
            <div class="summary-cards-row" id="filteredSummary" style="display: none;">
                <div class="summary-card inflow">
                    <div class="summary-label">Filtered Period Inflow</div>
                    <div class="summary-value" id="filteredInflow">₹0.00</div>
                </div>
                <div class="summary-card outflow">
                    <div class="summary-label">Filtered Period Outflow</div>
                    <div class="summary-value" id="filteredOutflow">₹0.00</div>
                </div>
                <div class="summary-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="summary-label">Net Flow</div>
                    <div class="summary-value" id="filteredNet">₹0.00</div>
                </div>
            </div>

            <!-- Export Options -->
            <div class="export-buttons mb-3 justify-content-end">
                <form method="POST" action="" id="exportForm" style="display: inline;">
                    <input type="hidden" name="export" value="transactions">
                    <input type="hidden" name="format" id="exportFormat">
                    <input type="hidden" name="bank_account_id" id="exportBankAccountId">
                    <input type="hidden" name="date_from" id="exportDateFrom">
                    <input type="hidden" name="date_to" id="exportDateTo">
                    <input type="hidden" name="transaction_type" id="exportTransactionType">
                    <input type="hidden" name="payment_method" id="exportPaymentMethod">
                    <input type="hidden" name="search_term" id="exportSearchTerm">
                    
                    <button type="button" class="btn-export" onclick="exportData('csv')">
                        <i class="bi bi-filetype-csv"></i> CSV
                    </button>
                    <button type="button" class="btn-export" onclick="exportData('excel')">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                    <button type="button" class="btn-export" onclick="exportData('pdf')">
                        <i class="bi bi-file-earmark-pdf"></i> PDF
                    </button>
                </form>
            </div>

            <!-- Navigation Tabs -->
            <div class="nav-tabs-custom">
                <button class="nav-tab-custom active" id="tabAccounts">
                    <i class="bi bi-bank"></i> Bank Accounts
                </button>
                <button class="nav-tab-custom" id="tabTransactions">
                    <i class="bi bi-arrow-left-right"></i> Transactions
                </button>
                <?php if ($is_admin): ?>
                <button class="nav-tab-custom" id="tabAddTransaction">
                    <i class="bi bi-plus-circle"></i> Add Transaction
                </button>
                <?php endif; ?>
            </div>

            <!-- Bank Accounts Section -->
            <div id="accountsSection">
                <div class="section-title">
                    <span><i class="bi bi-bank me-2"></i> Bank Accounts</span>
                    <?php if ($is_admin): ?>
                        <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                            <i class="bi bi-plus-lg"></i> Add Bank Account
                        </button>
                    <?php endif; ?>
                </div>

                <div class="row g-4" id="bankAccountsContainer">
                    <?php
                    $accounts->data_seek(0);
                    while ($account = $accounts->fetch_assoc()):
                        // Get today's inflow/outflow for this account
                        $today_in_query = $conn->prepare("
                            SELECT COALESCE(SUM(amount), 0) as total 
                            FROM bank_transactions 
                            WHERE bank_account_id = ? 
                            AND DATE(transaction_date) = CURDATE() 
                            AND transaction_type IN ('in', 'sale', 'sale_credit')
                        ");
                        $today_in_query->bind_param("i", $account['id']);
                        $today_in_query->execute();
                        $today_in = $today_in_query->get_result()->fetch_assoc()['total'];
                        
                        $today_out_query = $conn->prepare("
                            SELECT COALESCE(SUM(amount), 0) as total 
                            FROM bank_transactions 
                            WHERE bank_account_id = ? 
                            AND DATE(transaction_date) = CURDATE() 
                            AND transaction_type IN ('out', 'purchase', 'purchase_payment', 'expense', 'transfer')
                        ");
                        $today_out_query->bind_param("i", $account['id']);
                        $today_out_query->execute();
                        $today_out = $today_out_query->get_result()->fetch_assoc()['total'];
                    ?>
                        <div class="col-md-6 col-xl-4">
                            <div class="bank-card <?php echo $account['is_default'] ? 'default' : ''; ?> <?php echo $account['status'] ? '' : 'inactive'; ?>" 
                                 onclick="selectBankAccount(<?php echo $account['id']; ?>)">
                                <div class="bank-header">
                                    <div class="bank-icon <?php echo $account['is_default'] ? 'default-icon' : ''; ?>">
                                        <i class="bi bi-bank2"></i>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <?php if ($account['is_default']): ?>
                                            <span class="default-badge me-1">Default</span>
                                        <?php endif; ?>
                                        <span class="status-badge <?php echo $account['status'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $account['status'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="bank-name"><?php echo htmlspecialchars($account['account_name']); ?></div>
                                <div class="bank-account-name"><?php echo htmlspecialchars($account['bank_name']); ?></div>

                                <div class="bank-balance"><?php echo formatCurrency($account['current_balance']); ?></div>

                                <div class="bank-today-stats">
                                    <div class="today-stat">
                                        <div class="today-stat-label">Today In</div>
                                        <div class="today-stat-value inflow"><?php echo formatCurrency($today_in); ?></div>
                                    </div>
                                    <div class="today-stat">
                                        <div class="today-stat-label">Today Out</div>
                                        <div class="today-stat-value outflow"><?php echo formatCurrency($today_out); ?></div>
                                    </div>
                                    <div class="today-stat">
                                        <div class="today-stat-label">Net</div>
                                        <div class="today-stat-value"><?php echo formatCurrency($today_in - $today_out); ?></div>
                                    </div>
                                </div>

                                <div class="bank-detail">
                                    <i class="bi bi-person-badge"></i>
                                    <span><strong>A/C Type:</strong> <?php echo htmlspecialchars($account['account_type'] ?: 'N/A'); ?></span>
                                </div>

                                <?php if (!empty($account['account_number'])): ?>
                                    <div class="bank-detail">
                                        <i class="bi bi-credit-card"></i>
                                        <span><strong>A/C No:</strong> <?php echo htmlspecialchars($account['account_number']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($account['branch'])): ?>
                                    <div class="bank-detail">
                                        <i class="bi bi-geo-alt"></i>
                                        <span><strong>Branch:</strong> <?php echo htmlspecialchars($account['branch']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($account['ifsc_code'])): ?>
                                    <div class="bank-detail">
                                        <i class="bi bi-upc-scan"></i>
                                        <span><strong>IFSC:</strong> <?php echo htmlspecialchars($account['ifsc_code']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($account['upi_id'])): ?>
                                    <div class="bank-detail">
                                        <i class="bi bi-phone"></i>
                                        <span><strong>UPI:</strong> <?php echo htmlspecialchars($account['upi_id']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="mt-3 d-flex gap-2" onclick="event.stopPropagation()">
                                    <button class="btn btn-sm view-transactions-btn flex-fill" 
                                            onclick="viewAccountTransactions(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['account_name']); ?>')">
                                        <i class="bi bi-eye"></i> View Transactions
                                    </button>
                                    
                                    <?php if ($is_admin): ?>
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="editAccount(<?php echo htmlspecialchars(json_encode($account)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        
                                        <?php if ($account['status']): ?>
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Deactivate this account?')">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                                <input type="hidden" name="status" value="0">
                                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                                    <i class="bi bi-pause-circle"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Activate this account?')">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                                <input type="hidden" name="status" value="1">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="bi bi-play-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete this account? This action cannot be undone if there are no transactions.')">
                                            <input type="hidden" name="action" value="delete_account">
                                            <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>

            <!-- Transactions Section (initially hidden) -->
            <div id="transactionsSection" style="display: none;">
                <div class="section-title">
                    <span><i class="bi bi-arrow-left-right me-2"></i> Transactions</span>
                    <span class="text-muted" style="font-size: 13px; font-weight: normal;" id="transactionCount"></span>
                </div>

                <div class="dashboard-card">
                    <div class="table-responsive">
                        <table class="table-custom" id="transactionsTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Account</th>
                                    <th>Type</th>
                                    <th>Reference</th>
                                    <th>Party</th>
                                    <th>Description</th>
                                    <th class="text-end">Amount</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <?php if ($is_admin): ?>
                                        <th style="text-align: center;">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="transactionsTableBody">
                                <?php if ($transactions && $transactions->num_rows > 0): ?>
                                    <?php while ($tx = $transactions->fetch_assoc()): ?>
                                        <tr class="transaction-row" onclick="viewTransaction(<?php echo htmlspecialchars(json_encode($tx)); ?>)">
                                            <td style="white-space: nowrap;">
                                                <?php echo date('d M Y', strtotime($tx['transaction_date'])); ?>
                                                <div style="font-size: 10px; color: #94a3b8;">
                                                    <?php echo date('h:i A', strtotime($tx['created_at'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($tx['account_name'] ?: 'Unknown'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($tx['bank_name'] ?: ''); ?></small>
                                            </td>
                                            <td><?php echo getTransactionTypeBadge($tx['transaction_type']); ?></td>
                                            <td>
                                                <?php if (!empty($tx['reference_number'])): ?>
                                                    <span class="fw-semibold"><?php echo htmlspecialchars($tx['reference_number']); ?></span>
                                                    <div class="reference-info"><?php echo ucfirst($tx['reference_type'] ?: 'N/A'); ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($tx['party_name'])): ?>
                                                    <span><?php echo htmlspecialchars($tx['party_name']); ?></span>
                                                    <?php if ($tx['party_type'] !== 'other'): ?>
                                                        <div><small class="text-muted"><?php echo ucfirst($tx['party_type']); ?></small></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="text-truncate" style="max-width: 150px; display: inline-block;" 
                                                      title="<?php echo htmlspecialchars($tx['description'] ?: ''); ?>">
                                                    <?php echo htmlspecialchars($tx['description'] ?: '-'); ?>
                                                </span>
                                            </td>
                                            <td class="text-end <?php echo in_array($tx['transaction_type'], ['in','sale','sale_credit']) ? 'amount-in' : 'amount-out'; ?>">
                                                <?php echo in_array($tx['transaction_type'], ['in','sale','sale_credit']) ? '+' : '-'; ?>
                                                <?php echo formatCurrency($tx['amount']); ?>
                                            </td>
                                            <td><?php echo getPaymentMethodBadge($tx['payment_method']); ?></td>
                                            <td>
                                                <?php if ($tx['status'] === 'completed'): ?>
                                                    <span class="badge bg-success">Completed</span>
                                                <?php elseif ($tx['status'] === 'pending'): ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Cancelled</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($is_admin): ?>
                                                <td>
                                                    <div class="d-flex gap-1 justify-content-center" onclick="event.stopPropagation()">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="editTransaction(<?php echo htmlspecialchars(json_encode($tx)); ?>)"
                                                                title="Edit Transaction">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <form method="POST" style="display: inline;" 
                                                              onsubmit="event.stopPropagation(); return confirm('Delete this transaction? This will update account balance.');">
                                                            <input type="hidden" name="action" value="delete_transaction">
                                                            <input type="hidden" name="transaction_id" value="<?php echo $tx['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                                    onclick="event.stopPropagation();" title="Delete Transaction">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?php echo $is_admin ? 10 : 9; ?>" class="text-center py-4">
                                            <i class="bi bi-inbox" style="font-size: 24px; color: #94a3b8;"></i>
                                            <p class="mt-2 text-muted">No transactions found</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add Transaction Section (initially hidden) -->
            <?php if ($is_admin): ?>
            <div id="addTransactionSection" style="display: none;">
                <div class="section-title">
                    <span><i class="bi bi-plus-circle me-2"></i> Add New Transaction</span>
                </div>

                <div class="dashboard-card p-4">
                    <form method="POST" id="addTransactionForm" onsubmit="return validateTransactionForm()">
                        <input type="hidden" name="action" value="add_transaction">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Bank Account <span class="text-danger">*</span></label>
                                <select name="bank_account_id" class="form-select" required>
                                    <option value="">Select Account</option>
                                    <?php
                                    $accounts->data_seek(0);
                                    while ($acc = $accounts->fetch_assoc()):
                                    ?>
                                        <option value="<?php echo $acc['id']; ?>" <?php echo ($acc['is_default'] ?? 0) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($acc['account_name'] . ' - ' . $acc['bank_name']); ?>
                                            <?php echo $acc['is_default'] ? ' (Default)' : ''; ?>
                                            <?php echo $acc['status'] ? '' : ' (Inactive)'; ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Transaction Date <span class="text-danger">*</span></label>
                                <input type="date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Transaction Type <span class="text-danger">*</span></label>
                                <select name="transaction_type" class="form-select" required id="transactionType" onchange="toggleTransactionTypeFields()">
                                    <option value="in">Money In (Deposit/Receipt)</option>
                                    <option value="out">Money Out (Withdrawal/Payment)</option>
                                    <option value="sale">Sale Payment Received</option>
                                    <option value="sale_credit">Sale Credit Payment</option>
                                    <option value="purchase">Purchase Payment Made</option>
                                    <option value="purchase_payment">Purchase Payment</option>
                                    <option value="expense">Expense Payment</option>
                                    <option value="transfer">Transfer Between Accounts</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div class="col-md-12" id="referenceFields" style="display: none;">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Reference Type</label>
                                        <select name="reference_type" class="form-select" id="referenceType" onchange="toggleReferenceSearch()">
                                            <option value="">None</option>
                                            <option value="invoice">Invoice</option>
                                            <option value="purchase">Purchase</option>
                                            <option value="expense">Expense</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4" id="referenceSearchField" style="display: none;">
                                        <label class="form-label">Search Reference</label>
                                        <select class="form-select" id="referenceSelect" style="width: 100%;">
                                            <option value="">Search and select...</option>
                                        </select>
                                        <input type="hidden" name="reference_id" id="referenceId">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Reference Number</label>
                                        <input type="text" name="reference_number" class="form-control" id="referenceNumber" readonly>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Party Name</label>
                                        <input type="text" name="party_name" class="form-control" id="partyName" readonly>
                                        <input type="hidden" name="party_type" id="partyType">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Amount from Reference</label>
                                        <input type="text" class="form-control" id="refAmount" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Description</label>
                                <input type="text" name="description" class="form-control" placeholder="Transaction description">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required id="transactionAmount">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method" class="form-select">
                                    <option value="bank">Bank Transfer</option>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="upi">UPI</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="credit">Credit</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="completed">Completed</option>
                                    <option value="pending">Pending</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>

                            <div class="col-md-12" id="paymentDetailsFields">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Cheque Number</label>
                                        <input type="text" name="cheque_number" class="form-control">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Cheque Date</label>
                                        <input type="date" name="cheque_date" class="form-control">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Cheque Bank</label>
                                        <input type="text" name="cheque_bank" class="form-control">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">UPI Ref No</label>
                                        <input type="text" name="upi_ref_no" class="form-control">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Transaction Ref No</label>
                                        <input type="text" name="transaction_ref_no" class="form-control">
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2"></textarea>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle me-2"></i> Add Transaction
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="bi bi-x-circle me-2"></i> Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Account Transactions Modal -->
<div class="modal fade" id="accountTransactionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-bank me-2"></i> Account Transactions: <span id="accountNameSpan"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="export-buttons mb-3">
                    <button class="btn-export" onclick="exportAccountTransactions('csv')">
                        <i class="bi bi-filetype-csv"></i> CSV
                    </button>
                    <button class="btn-export" onclick="exportAccountTransactions('excel')">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                    <button class="btn-export" onclick="exportAccountTransactions('pdf')">
                        <i class="bi bi-file-earmark-pdf"></i> PDF
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered" id="accountTransactionsTable" style="width:100%">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th>Party</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="accountTransactionsBody">
                            <tr>
                                <td colspan="8" class="text-center">Loading transactions...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_account">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-bank me-2"></i> Add Bank Account
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Account Name <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" class="form-control" placeholder="e.g., Main Business Account" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" class="form-control" placeholder="e.g., State Bank of India" required>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Branch</label>
                                <input type="text" name="branch" class="form-control" placeholder="Branch name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Account Number</label>
                                <input type="text" name="account_number" class="form-control" placeholder="Account number">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">IFSC Code</label>
                                <input type="text" name="ifsc_code" class="form-control" placeholder="IFSC code">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">UPI ID (Optional)</label>
                                <input type="text" name="upi_id" class="form-control" placeholder="UPI ID">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Account Type</label>
                                <input type="text" name="account_type" class="form-control" placeholder="e.g., Savings, Current">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Opening Balance</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" name="opening_balance" class="form-control" step="0.01" value="0.00">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_default" id="isDefaultAccount">
                            <label class="form-check-label" for="isDefaultAccount">
                                Set as default account for credit transactions
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1">
                            Default account will be automatically selected for credit payments
                        </small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Account Modal -->
<div class="modal fade" id="editAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_account">
                <input type="hidden" name="account_id" id="edit_account_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i> Edit Bank Account
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Account Name <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" id="edit_account_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" id="edit_bank_name" class="form-control" required>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Branch</label>
                                <input type="text" name="branch" id="edit_branch" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Account Number</label>
                                <input type="text" name="account_number" id="edit_account_number" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">IFSC Code</label>
                                <input type="text" name="ifsc_code" id="edit_ifsc_code" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">UPI ID</label>
                                <input type="text" name="upi_id" id="edit_upi_id" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Account Type</label>
                                <input type="text" name="account_type" id="edit_account_type" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Opening Balance</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" name="opening_balance" id="edit_opening_balance" class="form-control" step="0.01">
                                </div>
                                <small class="text-muted">Changing opening balance will adjust current balance</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_default" id="edit_is_default">
                            <label class="form-check-label" for="edit_is_default">
                                Set as default account for credit transactions
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Transaction Modal -->
<div class="modal fade" id="editTransactionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_transaction">
                <input type="hidden" name="transaction_id" id="edit_transaction_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i> Edit Transaction
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Bank Account</label>
                            <select name="bank_account_id" id="edit_bank_account_id" class="form-select" required>
                                <option value="">Select Account</option>
                                <?php
                                $accounts->data_seek(0);
                                while ($acc = $accounts->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo htmlspecialchars($acc['account_name'] . ' - ' . $acc['bank_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Transaction Date</label>
                            <input type="date" name="transaction_date" id="edit_transaction_date" class="form-control" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Type</label>
                            <select name="transaction_type" id="edit_transaction_type" class="form-select" required>
                                <option value="in">Money In</option>
                                <option value="out">Money Out</option>
                                <option value="sale">Sale Payment</option>
                                <option value="sale_credit">Sale Credit</option>
                                <option value="purchase">Purchase</option>
                                <option value="purchase_payment">Purchase Payment</option>
                                <option value="expense">Expense</option>
                                <option value="transfer">Transfer</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Reference Type</label>
                            <select name="reference_type" id="edit_reference_type" class="form-select">
                                <option value="">None</option>
                                <option value="invoice">Invoice</option>
                                <option value="purchase">Purchase</option>
                                <option value="expense">Expense</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" id="edit_reference_number" class="form-control" readonly>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Party Name</label>
                            <input type="text" name="party_name" id="edit_party_name" class="form-control">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" id="edit_description" class="form-control">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="amount" id="edit_amount" class="form-control" step="0.01" required>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" id="edit_payment_method" class="form-select">
                                <option value="bank">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="upi">UPI</option>
                                <option value="cheque">Cheque</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="completed">Completed</option>
                                <option value="pending">Pending</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Cheque Number</label>
                            <input type="text" name="cheque_number" id="edit_cheque_number" class="form-control">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Cheque Date</label>
                            <input type="date" name="cheque_date" id="edit_cheque_date" class="form-control">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Cheque Bank</label>
                            <input type="text" name="cheque_bank" id="edit_cheque_bank" class="form-control">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">UPI Ref No</label>
                            <input type="text" name="upi_ref_no" id="edit_upi_ref_no" class="form-control">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Transaction Ref No</label>
                            <input type="text" name="transaction_ref_no" id="edit_transaction_ref_no" class="form-control">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="edit_notes_tx" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Transaction Modal -->
<div class="modal fade" id="viewTransactionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-info-circle me-2"></i> Transaction Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="transactionDetails">
                <!-- Filled dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/moment/min/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.3/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.3/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.3/js/buttons.print.min.js"></script>
<script>
let currentTransactionData = null;
let selectedBankAccountId = '';
let currentAccountTransactions = [];

// Tab switching
document.getElementById('tabAccounts').addEventListener('click', function() {
    document.getElementById('tabAccounts').classList.add('active');
    document.getElementById('tabTransactions').classList.remove('active');
    <?php if ($is_admin): ?>
    document.getElementById('tabAddTransaction').classList.remove('active');
    <?php endif; ?>
    
    document.getElementById('accountsSection').style.display = 'block';
    document.getElementById('transactionsSection').style.display = 'none';
    <?php if ($is_admin): ?>
    document.getElementById('addTransactionSection').style.display = 'none';
    <?php endif; ?>
});

document.getElementById('tabTransactions').addEventListener('click', function() {
    document.getElementById('tabTransactions').classList.add('active');
    document.getElementById('tabAccounts').classList.remove('active');
    <?php if ($is_admin): ?>
    document.getElementById('tabAddTransaction').classList.remove('active');
    <?php endif; ?>
    
    document.getElementById('transactionsSection').style.display = 'block';
    document.getElementById('accountsSection').style.display = 'none';
    <?php if ($is_admin): ?>
    document.getElementById('addTransactionSection').style.display = 'none';
    <?php endif; ?>
});

<?php if ($is_admin): ?>
document.getElementById('tabAddTransaction').addEventListener('click', function() {
    document.getElementById('tabAddTransaction').classList.add('active');
    document.getElementById('tabAccounts').classList.remove('active');
    document.getElementById('tabTransactions').classList.remove('active');
    
    document.getElementById('addTransactionSection').style.display = 'block';
    document.getElementById('accountsSection').style.display = 'none';
    document.getElementById('transactionsSection').style.display = 'none';
});
<?php endif; ?>

// Initialize Date Range Picker
$(function() {
    $('#dateRange').daterangepicker({
        opens: 'left',
        autoUpdateInput: false,
        locale: {
            format: 'YYYY-MM-DD',
            cancelLabel: 'Clear'
        },
        ranges: {
            'Today': [moment(), moment()],
            'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            'Last 7 Days': [moment().subtract(6, 'days'), moment()],
            'Last 30 Days': [moment().subtract(29, 'days'), moment()],
            'This Month': [moment().startOf('month'), moment().endOf('month')],
            'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        }
    });

    $('#dateRange').on('apply.daterangepicker', function(ev, picker) {
        $(this).val(picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
        $('#dateFrom').val(picker.startDate.format('YYYY-MM-DD'));
        $('#dateTo').val(picker.endDate.format('YYYY-MM-DD'));
        updateFilterCount();
    });

    $('#dateRange').on('cancel.daterangepicker', function(ev, picker) {
        $(this).val('');
        $('#dateFrom').val('');
        $('#dateTo').val('');
        updateFilterCount();
    });
});

// Filter functions
function updateFilterCount() {
    let count = 0;
    if ($('#filterBankAccount').val()) count++;
    if ($('#dateFrom').val()) count++;
    if ($('#filterTransactionType').val()) count++;
    if ($('#filterPaymentMethod').val()) count++;
    if ($('#filterSearch').val()) count++;
    
    const badge = $('#activeFilterCount');
    if (count > 0) {
        badge.show().text(count + ' filter' + (count > 1 ? 's' : '') + ' active');
    } else {
        badge.hide();
    }
}

function selectBankAccount(accountId) {
    selectedBankAccountId = accountId;
    $('#filterBankAccount').val(accountId).trigger('change');
    
    // Highlight selected card
    $('.bank-card').removeClass('selected');
    $(event.currentTarget).addClass('selected');
    
    // Switch to transactions tab and apply filters
    document.getElementById('tabTransactions').click();
    applyFilters();
}

function applyFilters() {
    const filters = {
        ajax: 'get_filtered_transactions',
        bank_account_id: $('#filterBankAccount').val(),
        date_from: $('#dateFrom').val(),
        date_to: $('#dateTo').val(),
        transaction_type: $('#filterTransactionType').val(),
        payment_method: $('#filterPaymentMethod').val(),
        search_term: $('#filterSearch').val()
    };
    
    // Show loading state
    $('#transactionsTableBody').html('<tr><td colspan="<?php echo $is_admin ? 10 : 9; ?>" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2 text-muted">Loading transactions...</p></td></tr>');
    
    $.get('bank-acc-transactions.php', filters, function(data) {
        if (data.success) {
            renderTransactions(data.transactions);
            
            // Update filtered summary
            if (data.total_inflow > 0 || data.total_outflow > 0) {
                $('#filteredSummary').show();
                $('#filteredInflow').text('₹' + data.total_inflow.toFixed(2));
                $('#filteredOutflow').text('₹' + data.total_outflow.toFixed(2));
                $('#filteredNet').text('₹' + (data.total_inflow - data.total_outflow).toFixed(2));
            } else {
                $('#filteredSummary').hide();
            }
            
            // Update transaction count
            $('#transactionCount').text('(' + data.transactions.length + ' transactions)');
        }
    });
    
    updateFilterCount();
}

function renderTransactions(transactions) {
    let html = '';
    
    if (transactions.length === 0) {
        html = '<tr><td colspan="<?php echo $is_admin ? 10 : 9; ?>" class="text-center py-4">' +
               '<i class="bi bi-inbox" style="font-size: 24px; color: #94a3b8;"></i>' +
               '<p class="mt-2 text-muted">No transactions found</p></td></tr>';
    } else {
        transactions.forEach(function(tx) {
            const isInflow = ['in', 'sale', 'sale_credit'].includes(tx.transaction_type);
            const amountClass = isInflow ? 'amount-in' : 'amount-out';
            const amountSign = isInflow ? '+' : '-';
            
            html += '<tr class="transaction-row" onclick="viewTransaction(' + JSON.stringify(tx).replace(/"/g, '&quot;') + ')">' +
                    '<td style="white-space: nowrap;">' + new Date(tx.transaction_date).toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) +
                    '<div style="font-size: 10px; color: #94a3b8;">' + new Date(tx.created_at).toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'}) + '</div></td>' +
                    '<td><div class="fw-semibold">' + (tx.account_name || 'Unknown') + '</div>' +
                    '<small class="text-muted">' + (tx.bank_name || '') + '</small></td>' +
                    '<td>' + getTransactionTypeBadge(tx.transaction_type) + '</td>' +
                    '<td>' + (tx.reference_number ? '<span class="fw-semibold">' + tx.reference_number + '</span><div class="reference-info">' + (tx.reference_type || 'N/A') + '</div>' : '<span class="text-muted">-</span>') + '</td>' +
                    '<td>' + (tx.party_name ? '<span>' + tx.party_name + '</span>' + (tx.party_type !== 'other' ? '<div><small class="text-muted">' + tx.party_type + '</small></div>' : '') : '<span class="text-muted">-</span>') + '</td>' +
                    '<td><span class="text-truncate" style="max-width: 150px; display: inline-block;" title="' + (tx.description || '') + '">' + (tx.description || '-') + '</span></td>' +
                    '<td class="text-end ' + amountClass + '">' + amountSign + ' ₹' + parseFloat(tx.amount).toFixed(2) + '</td>' +
                    '<td>' + getPaymentMethodBadge(tx.payment_method) + '</td>' +
                    '<td>' + getStatusBadge(tx.status) + '</td>' +
                    <?php if ($is_admin): ?>
                    '<td><div class="d-flex gap-1 justify-content-center" onclick="event.stopPropagation()">' +
                    '<button class="btn btn-sm btn-outline-primary" onclick="editTransaction(' + JSON.stringify(tx).replace(/"/g, '&quot;') + ')" title="Edit Transaction"><i class="bi bi-pencil"></i></button>' +
                    '<form method="POST" style="display: inline;" onsubmit="event.stopPropagation(); return confirm(\'Delete this transaction? This will update account balance.\');">' +
                    '<input type="hidden" name="action" value="delete_transaction">' +
                    '<input type="hidden" name="transaction_id" value="' + tx.id + '">' +
                    '<button type="submit" class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation();" title="Delete Transaction"><i class="bi bi-trash"></i></button>' +
                    '</form></div></td>' +
                    <?php endif; ?>
                    '</tr>';
        });
    }
    
    $('#transactionsTableBody').html(html);
}

function getTransactionTypeBadge(type) {
    const badges = {
        'in': '<span class="badge bg-success"><i class="bi bi-arrow-down-circle"></i> In</span>',
        'out': '<span class="badge bg-danger"><i class="bi bi-arrow-up-circle"></i> Out</span>',
        'sale': '<span class="badge bg-info"><i class="bi bi-cash"></i> Sale</span>',
        'sale_credit': '<span class="badge bg-warning"><i class="bi bi-credit-card"></i> Sale Credit</span>',
        'purchase': '<span class="badge bg-secondary"><i class="bi bi-cart"></i> Purchase</span>',
        'purchase_payment': '<span class="badge bg-primary"><i class="bi bi-credit-card-2-back"></i> Purchase Payment</span>',
        'expense': '<span class="badge bg-danger"><i class="bi bi-wallet2"></i> Expense</span>',
        'transfer': '<span class="badge bg-purple"><i class="bi bi-arrow-left-right"></i> Transfer</span>',
        'other': '<span class="badge bg-light text-dark"><i class="bi bi-question-circle"></i> Other</span>'
    };
    return badges[type] || badges['other'];
}

function getPaymentMethodBadge(method) {
    const badges = {
        'cash': '<span class="badge bg-success">Cash</span>',
        'card': '<span class="badge bg-info">Card</span>',
        'upi': '<span class="badge bg-primary">UPI</span>',
        'bank': '<span class="badge bg-secondary">Bank Transfer</span>',
        'cheque': '<span class="badge bg-warning">Cheque</span>',
        'credit': '<span class="badge bg-danger">Credit</span>',
        'mixed': '<span class="badge bg-dark">Mixed</span>'
    };
    return badges[method] || '<span class="badge bg-light text-dark">' + method + '</span>';
}

function getStatusBadge(status) {
    if (status === 'completed') {
        return '<span class="badge bg-success">Completed</span>';
    } else if (status === 'pending') {
        return '<span class="badge bg-warning">Pending</span>';
    } else {
        return '<span class="badge bg-danger">Cancelled</span>';
    }
}

function clearFilters() {
    $('#filterBankAccount').val('').trigger('change');
    $('#dateRange').val('');
    $('#dateFrom').val('');
    $('#dateTo').val('');
    $('#filterTransactionType').val('');
    $('#filterPaymentMethod').val('');
    $('#filterSearch').val('');
    
    selectedBankAccountId = '';
    $('.bank-card').removeClass('selected');
    
    applyFilters();
}

// Export functions
function exportData(format) {
    // Set export format
    $('#exportFormat').val(format);
    
    // Set current filter values
    $('#exportBankAccountId').val($('#filterBankAccount').val());
    $('#exportDateFrom').val($('#dateFrom').val());
    $('#exportDateTo').val($('#dateTo').val());
    $('#exportTransactionType').val($('#filterTransactionType').val());
    $('#exportPaymentMethod').val($('#filterPaymentMethod').val());
    $('#exportSearchTerm').val($('#filterSearch').val());
    
    // Submit form
    $('#exportForm').submit();
}

// Account Transactions Modal
function viewAccountTransactions(accountId, accountName) {
    $('#accountNameSpan').text(accountName);
    $('#accountTransactionsBody').html('<tr><td colspan="8" class="text-center">Loading transactions...</td></tr>');
    
    $.get('bank-acc-transactions.php', {
        ajax: 'get_account_transactions',
        account_id: accountId
    }, function(data) {
        if (data.success) {
            currentAccountTransactions = data.transactions;
            renderAccountTransactions(data.transactions);
        } else {
            $('#accountTransactionsBody').html('<tr><td colspan="8" class="text-center text-danger">Failed to load transactions</td></tr>');
        }
    });
    
    $('#accountTransactionsModal').modal('show');
}

function renderAccountTransactions(transactions) {
    let html = '';
    
    if (transactions.length === 0) {
        html = '<tr><td colspan="8" class="text-center">No transactions found</td></tr>';
    } else {
        transactions.forEach(function(tx) {
            const isInflow = ['in', 'sale', 'sale_credit'].includes(tx.transaction_type);
            const amountClass = isInflow ? 'text-success' : 'text-danger';
            const amountSign = isInflow ? '+' : '-';
            
            html += '<tr>' +
                    '<td>' + new Date(tx.transaction_date).toLocaleDateString('en-GB') + '</td>' +
                    '<td>' + tx.transaction_type + '</td>' +
                    '<td>' + (tx.reference_number || '-') + '</td>' +
                    '<td>' + (tx.party_name || '-') + '</td>' +
                    '<td>' + (tx.description || '-') + '</td>' +
                    '<td class="' + amountClass + ' fw-bold">' + amountSign + ' ₹' + parseFloat(tx.amount).toFixed(2) + '</td>' +
                    '<td>' + tx.payment_method + '</td>' +
                    '<td>' + tx.status + '</td>' +
                    '</tr>';
        });
    }
    
    $('#accountTransactionsBody').html(html);
    
    // Initialize DataTable for account transactions
    if ($.fn.DataTable.isDataTable('#accountTransactionsTable')) {
        $('#accountTransactionsTable').DataTable().destroy();
    }
    
    $('#accountTransactionsTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ transactions",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions"
        }
    });
}

function exportAccountTransactions(format) {
    if (currentAccountTransactions.length === 0) {
        alert('No transactions to export');
        return;
    }
    
    const accountId = $('#filterBankAccount').val();
    const accountName = $('#accountNameSpan').text();
    
    // Create CSV content
    let csv = 'Date,Type,Reference,Party,Description,Amount,Payment Method,Status\n';
    
    currentAccountTransactions.forEach(tx => {
        const amount = (['in', 'sale', 'sale_credit'].includes(tx.transaction_type) ? '+' : '-') + 
                      parseFloat(tx.amount).toFixed(2);
        
        csv += [
            tx.transaction_date,
            tx.transaction_type,
            tx.reference_number || '',
            tx.party_name || '',
            (tx.description || '').replace(/,/g, ';'),
            amount,
            tx.payment_method,
            tx.status
        ].join(',') + '\n';
    });
    
    if (format === 'csv') {
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = accountName + '_transactions.csv';
        a.click();
        window.URL.revokeObjectURL(url);
    } else if (format === 'excel') {
        // For Excel, we'll create an HTML table format
        let html = '<table>';
        html += '<tr><th>Date</th><th>Type</th><th>Reference</th><th>Party</th><th>Description</th><th>Amount</th><th>Payment Method</th><th>Status</th></tr>';
        
        currentAccountTransactions.forEach(tx => {
            const amount = (['in', 'sale', 'sale_credit'].includes(tx.transaction_type) ? '+' : '-') + 
                          parseFloat(tx.amount).toFixed(2);
            
            html += '<tr>';
            html += '<td>' + tx.transaction_date + '</td>';
            html += '<td>' + tx.transaction_type + '</td>';
            html += '<td>' + (tx.reference_number || '') + '</td>';
            html += '<td>' + (tx.party_name || '') + '</td>';
            html += '<td>' + (tx.description || '') + '</td>';
            html += '<td>' + amount + '</td>';
            html += '<td>' + tx.payment_method + '</td>';
            html += '<td>' + tx.status + '</td>';
            html += '</tr>';
        });
        
        html += '</table>';
        
        const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = accountName + '_transactions.xls';
        a.click();
        window.URL.revokeObjectURL(url);
    } else if (format === 'pdf') {
        // For PDF, we'll use window print as simple solution
        let printWindow = window.open('', '_blank');
        printWindow.document.write('<html><head><title>' + accountName + ' Transactions</title>');
        printWindow.document.write('<style>table { border-collapse: collapse; width: 100%; } th, td { border: 1px solid #ddd; padding: 8px; text-align: left; } th { background-color: #f2f2f2; }</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write('<h2>' + accountName + ' Transactions</h2>');
        printWindow.document.write('<table>');
        printWindow.document.write('<tr><th>Date</th><th>Type</th><th>Reference</th><th>Party</th><th>Description</th><th>Amount</th><th>Payment Method</th><th>Status</th></tr>');
        
        currentAccountTransactions.forEach(tx => {
            const amount = (['in', 'sale', 'sale_credit'].includes(tx.transaction_type) ? '+' : '-') + 
                          parseFloat(tx.amount).toFixed(2);
            
            printWindow.document.write('<tr>');
            printWindow.document.write('<td>' + tx.transaction_date + '</td>');
            printWindow.document.write('<td>' + tx.transaction_type + '</td>');
            printWindow.document.write('<td>' + (tx.reference_number || '') + '</td>');
            printWindow.document.write('<td>' + (tx.party_name || '') + '</td>');
            printWindow.document.write('<td>' + (tx.description || '') + '</td>');
            printWindow.document.write('<td>' + amount + '</td>');
            printWindow.document.write('<td>' + tx.payment_method + '</td>');
            printWindow.document.write('<td>' + tx.status + '</td>');
            printWindow.document.write('</tr>');
        });
        
        printWindow.document.write('</table>');
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.print();
    }
}

// Edit Account
function editAccount(account) {
    document.getElementById('edit_account_id').value = account.id;
    document.getElementById('edit_account_name').value = account.account_name || '';
    document.getElementById('edit_bank_name').value = account.bank_name || '';
    document.getElementById('edit_branch').value = account.branch || '';
    document.getElementById('edit_account_number').value = account.account_number || '';
    document.getElementById('edit_ifsc_code').value = account.ifsc_code || '';
    document.getElementById('edit_upi_id').value = account.upi_id || '';
    document.getElementById('edit_account_type').value = account.account_type || '';
    document.getElementById('edit_opening_balance').value = account.opening_balance || 0;
    document.getElementById('edit_is_default').checked = account.is_default == 1;
    document.getElementById('edit_notes').value = account.notes || '';
    
    new bootstrap.Modal(document.getElementById('editAccountModal')).show();
}

// Edit Transaction
function editTransaction(tx) {
    document.getElementById('edit_transaction_id').value = tx.id;
    document.getElementById('edit_bank_account_id').value = tx.bank_account_id || '';
    document.getElementById('edit_transaction_date').value = tx.transaction_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('edit_transaction_type').value = tx.transaction_type || 'other';
    document.getElementById('edit_reference_type').value = tx.reference_type || '';
    document.getElementById('edit_reference_number').value = tx.reference_number || '';
    document.getElementById('edit_party_name').value = tx.party_name || '';
    document.getElementById('edit_description').value = tx.description || '';
    document.getElementById('edit_amount').value = tx.amount || 0;
    document.getElementById('edit_payment_method').value = tx.payment_method || 'bank';
    document.getElementById('edit_status').value = tx.status || 'completed';
    document.getElementById('edit_cheque_number').value = tx.cheque_number || '';
    document.getElementById('edit_cheque_date').value = tx.cheque_date || '';
    document.getElementById('edit_cheque_bank').value = tx.cheque_bank || '';
    document.getElementById('edit_upi_ref_no').value = tx.upi_ref_no || '';
    document.getElementById('edit_transaction_ref_no').value = tx.transaction_ref_no || '';
    document.getElementById('edit_notes_tx').value = tx.notes || '';
    
    new bootstrap.Modal(document.getElementById('editTransactionModal')).show();
}

// View Transaction
function viewTransaction(tx) {
    currentTransactionData = tx;
    
    let html = `
        <div class="mb-3">
            <div class="d-flex justify-content-between">
                <span class="text-muted">Transaction ID:</span>
                <span class="fw-semibold">#${tx.id}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-muted">Date:</span>
                <span class="fw-semibold">${tx.transaction_date}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-muted">Account:</span>
                <span class="fw-semibold">${tx.account_name || 'Unknown'} (${tx.bank_name || ''})</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-muted">Type:</span>
                <span>${getTransactionTypeBadge(tx.transaction_type)}</span>
            </div>
            <hr>
    `;
    
    if (tx.reference_number) {
        html += `
            <div class="d-flex justify-content-between">
                <span class="text-muted">Reference:</span>
                <span class="fw-semibold">${tx.reference_number}</span>
            </div>
        `;
    }
    
    if (tx.party_name) {
        html += `
            <div class="d-flex justify-content-between">
                <span class="text-muted">Party:</span>
                <span class="fw-semibold">${tx.party_name}</span>
            </div>
        `;
    }
    
    const isInflow = ['in', 'sale', 'sale_credit'].includes(tx.transaction_type);
    const amountClass = isInflow ? 'amount-in' : 'amount-out';
    const amountSign = isInflow ? '+' : '-';
    
    html += `
        <div class="d-flex justify-content-between">
            <span class="text-muted">Amount:</span>
            <span class="fw-semibold ${amountClass}">
                ${amountSign} ₹${parseFloat(tx.amount).toFixed(2)}
            </span>
        </div>
    `;
    
    html += `
        <div class="d-flex justify-content-between">
            <span class="text-muted">Payment Method:</span>
            <span>${getPaymentMethodBadge(tx.payment_method)}</span>
        </div>
        
        <div class="d-flex justify-content-between">
            <span class="text-muted">Status:</span>
            <span>${getStatusBadge(tx.status)}</span>
        </div>
    `;
    
    if (tx.description) {
        html += `
            <div class="mt-2">
                <span class="text-muted">Description:</span><br>
                <span>${tx.description}</span>
            </div>
        `;
    }
    
    if (tx.notes) {
        html += `
            <div class="mt-2">
                <span class="text-muted">Notes:</span><br>
                <span>${tx.notes}</span>
            </div>
        `;
    }
    
    if (tx.cheque_number) {
        html += `
            <div class="mt-2">
                <span class="text-muted">Cheque Details:</span><br>
                <span>Number: ${tx.cheque_number}</span><br>
                <span>Date: ${tx.cheque_date || 'N/A'}</span><br>
                <span>Bank: ${tx.cheque_bank || 'N/A'}</span>
            </div>
        `;
    }
    
    if (tx.upi_ref_no) {
        html += `
            <div class="mt-2">
                <span class="text-muted">UPI Ref No:</span><br>
                <span>${tx.upi_ref_no}</span>
            </div>
        `;
    }
    
    if (tx.transaction_ref_no) {
        html += `
            <div class="mt-2">
                <span class="text-muted">Transaction Ref No:</span><br>
                <span>${tx.transaction_ref_no}</span>
            </div>
        `;
    }
    
    html += '</div>';
    
    document.getElementById('transactionDetails').innerHTML = html;
    new bootstrap.Modal(document.getElementById('viewTransactionModal')).show();
}

// Transaction form handling
function toggleTransactionTypeFields() {
    const type = document.getElementById('transactionType').value;
    const referenceFields = document.getElementById('referenceFields');
    
    // Show reference fields for transaction types that typically have references
    if (['sale', 'sale_credit', 'purchase', 'purchase_payment', 'expense'].includes(type)) {
        referenceFields.style.display = 'block';
    } else {
        referenceFields.style.display = 'none';
    }
}

function toggleReferenceSearch() {
    const refType = document.getElementById('referenceType').value;
    const searchField = document.getElementById('referenceSearchField');
    
    if (refType) {
        searchField.style.display = 'block';
        initializeReferenceSelect(refType);
    } else {
        searchField.style.display = 'none';
        document.getElementById('referenceNumber').value = '';
        document.getElementById('partyName').value = '';
        document.getElementById('refAmount').value = '';
    }
}

function initializeReferenceSelect(type) {
    const $select = $('#referenceSelect');
    
    $select.select2({
        placeholder: 'Search for ' + type + '...',
        allowClear: true,
        dropdownParent: $('#addTransactionSection'),
        ajax: {
            url: 'bank-acc-transactions.php?ajax=search_references&type=' + type,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    term: params.term || ''
                };
            },
            processResults: function(data) {
                return {
                    results: data.results
                };
            }
        }
    });
    
    $select.on('select2:select', function(e) {
        const data = e.params.data;
        document.getElementById('referenceId').value = data.id;
        document.getElementById('referenceNumber').value = data.reference_number;
        document.getElementById('partyName').value = data.party_name;
        document.getElementById('refAmount').value = '₹' + data.amount.toFixed(2);
        
        // Auto-fill amount if it's a credit payment
        if (document.getElementById('transactionType').value === 'sale_credit' && data.pending_amount > 0) {
            document.getElementById('transactionAmount').value = data.pending_amount;
        }
    });
    
    $select.on('select2:clear', function() {
        document.getElementById('referenceId').value = '';
        document.getElementById('referenceNumber').value = '';
        document.getElementById('partyName').value = '';
        document.getElementById('refAmount').value = '';
    });
}

function validateTransactionForm() {
    const amount = parseFloat(document.getElementById('transactionAmount').value);
    if (amount <= 0) {
        alert('Please enter a valid amount greater than 0');
        return false;
    }
    
    const bankAccount = document.querySelector('select[name="bank_account_id"]').value;
    if (!bankAccount) {
        alert('Please select a bank account');
        return false;
    }
    
    return true;
}

// Initialize on load
$(document).ready(function() {
    // Initialize Select2 for bank account filter
    $('#filterBankAccount').select2({
        placeholder: 'All Accounts',
        allowClear: true,
        width: '100%'
    });
    
    // Initialize filter change listeners
    $('#filterBankAccount, #filterTransactionType, #filterPaymentMethod').on('change', function() {
        updateFilterCount();
    });
    
    $('#filterSearch').on('input', function() {
        updateFilterCount();
    });
    
    // Apply filters button
    $('#applyFilters').click(function() {
        applyFilters();
    });
    
    // Clear filters button
    $('#clearFilters').click(function() {
        clearFilters();
    });
    
    // Refresh data button
    $('#refreshData').click(function() {
        applyFilters();
    });
    
    // Initialize DataTable for transactions
    $('#transactionsTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search transactions:",
            lengthMenu: "Show _MENU_ transactions",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: -1 }
            <?php endif; ?>
        ]
    });
    
    // Initialize transaction type fields
    toggleTransactionTypeFields();
    
    // Check URL parameters for initial filter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('account_id')) {
        $('#filterBankAccount').val(urlParams.get('account_id')).trigger('change');
        applyFilters();
    }
});
</script>
</body>
</html>