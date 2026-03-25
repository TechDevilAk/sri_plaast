<?php
// purchase-payments.php
session_start();
$currentPage = 'purchase-payments';
$pageTitle = 'Purchase Payments';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can manage payments
checkRoleAccess(['admin', 'sale']);

// --------------------------
// Helper Functions
// --------------------------
function money2($n) {
    return number_format((float)$n, 2, '.', '');
}

function getPaymentStatus($total, $paid) {
    if ($paid >= $total) {
        return ['badge' => 'success', 'text' => 'Paid', 'progress' => 100];
    } elseif ($paid > 0) {
        $progress = round(($paid / $total) * 100);
        return ['badge' => 'warning', 'text' => 'Partial', 'progress' => $progress];
    } else {
        return ['badge' => 'danger', 'text' => 'Unpaid', 'progress' => 0];
    }
}

// ---------- Get current user ID safely ----------
function getCurrentUserId($conn) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return 0;
    }
    
    $user_id = (int)$_SESSION['user_id'];
    
    $result = mysqli_query($conn, "SELECT id FROM users WHERE id = $user_id LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        return $user_id;
    }
    
    return 0;
}

// ---------- Save bank transaction for purchase payment ----------
function saveBankTransaction($conn, $data) {
    $bank_account_id = (int)$data['bank_account_id'];
    $transaction_date = $data['transaction_date'];
    $transaction_type = $data['transaction_type'];
    $reference_type = $data['reference_type'];
    $reference_id = (int)$data['reference_id'];
    $reference_number = mysqli_real_escape_string($conn, $data['reference_number']);
    $party_name = mysqli_real_escape_string($conn, $data['party_name']);
    $party_type = $data['party_type'];
    $description = mysqli_real_escape_string($conn, $data['description']);
    $amount = (float)$data['amount'];
    $payment_method = $data['payment_method'];
    $status = 'completed';
    $cheque_number = isset($data['cheque_number']) ? mysqli_real_escape_string($conn, $data['cheque_number']) : '';
    $cheque_date = isset($data['cheque_date']) && !empty($data['cheque_date']) ? "'" . mysqli_real_escape_string($conn, $data['cheque_date']) . "'" : 'NULL';
    $cheque_bank = isset($data['cheque_bank']) ? mysqli_real_escape_string($conn, $data['cheque_bank']) : '';
    $upi_ref_no = isset($data['upi_ref_no']) ? mysqli_real_escape_string($conn, $data['upi_ref_no']) : '';
    $transaction_ref_no = isset($data['transaction_ref_no']) ? mysqli_real_escape_string($conn, $data['transaction_ref_no']) : '';
    $notes = isset($data['notes']) ? mysqli_real_escape_string($conn, $data['notes']) : '';
    $balance_due = isset($data['balance_due']) ? (float)$data['balance_due'] : 0;
    $created_by = getCurrentUserId($conn);
    $created_by_sql = $created_by > 0 ? $created_by : 'NULL';

    $query = "
        INSERT INTO bank_transactions 
        (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, 
         reference_number, party_name, party_type, description, amount, payment_method, 
         status, cheque_number, cheque_date, cheque_bank, upi_ref_no, transaction_ref_no, 
         notes, created_by) 
        VALUES (
            $bank_account_id, '$transaction_date', '$transaction_type', '$reference_type', $reference_id,
            '$reference_number', '$party_name', '$party_type', '$description', $amount, '$payment_method',
            '$status', '$cheque_number', $cheque_date, '$cheque_bank', '$upi_ref_no', '$transaction_ref_no',
            '$notes', $created_by_sql
        )
    ";
    
    if (mysqli_query($conn, $query)) {
        $transaction_id = mysqli_insert_id($conn);
        
        // Update bank account balance (purchase payment is money out)
        $balance_query = "SELECT current_balance FROM bank_accounts WHERE id = $bank_account_id";
        $balance_result = mysqli_query($conn, $balance_query);
        $current_balance = mysqli_fetch_assoc($balance_result)['current_balance'];
        
        // For purchase payments (money out)
        $new_balance = $current_balance - $amount;
        
        $update_balance = "UPDATE bank_accounts SET current_balance = $new_balance WHERE id = $bank_account_id";
        mysqli_query($conn, $update_balance);
        
        return $transaction_id;
    }
    
    return false;
}

// ---------- Get last used bank account for user ----------
function getLastUsedBankAccount($conn, $user_id) {
    // First check if there's a cookie with last selection
    if (isset($_COOKIE['last_purchase_payment_bank_account'])) {
        $account_id = (int)$_COOKIE['last_purchase_payment_bank_account'];
        $result = mysqli_query($conn, "SELECT * FROM bank_accounts WHERE id = $account_id AND status = 1");
        if ($row = mysqli_fetch_assoc($result)) {
            return $row;
        }
    }
    
    // If no cookie or account not found, return default account
    $result = mysqli_query($conn, "SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, id DESC LIMIT 1");
    return mysqli_fetch_assoc($result);
}

// --------------------------
// Handle Form Submissions
// --------------------------
$success_message = '';
$error_message = '';

// Add new payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_payment') {
        $purchase_id = intval($_POST['purchase_id']);
        $paid_amount = floatval($_POST['paid_amount']);
        $payment_method = $conn->real_escape_string($_POST['payment_method']);
        $notes = $conn->real_escape_string($_POST['notes'] ?? '');
        
        // Bank account selection (for UPI/Card/Bank payments)
        $bank_account_id = isset($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : 0;
        $upi_ref_no = isset($_POST['upi_ref_no']) ? $conn->real_escape_string($_POST['upi_ref_no']) : '';
        $transaction_ref_no = isset($_POST['transaction_ref_no']) ? $conn->real_escape_string($_POST['transaction_ref_no']) : '';
        
        // Validate bank account for UPI/Card/Bank payments
        if (in_array($payment_method, ['upi', 'card', 'bank']) && $bank_account_id <= 0) {
            $error_message = "Please select a bank account for $payment_method payments.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert payment
                $stmt = $conn->prepare("
                    INSERT INTO purchase_payment_history 
                    (purchase_id, paid_amount, payment_method, notes) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param("idss", $purchase_id, $paid_amount, $payment_method, $notes);
                $stmt->execute();
                $payment_history_id = $stmt->insert_id;
                
                // Get purchase details
                $purchase_info = $conn->query("
                    SELECT p.purchase_no, p.total, p.purchase_date, s.supplier_name,
                           COALESCE(SUM(pp.paid_amount), 0) as total_paid_before
                    FROM purchase p
                    JOIN suppliers s ON p.supplier_id = s.id
                    LEFT JOIN purchase_payment_history pp ON p.id = pp.purchase_id AND pp.id != $payment_history_id
                    WHERE p.id = $purchase_id
                    GROUP BY p.id
                ")->fetch_assoc();
                
                $total_before = floatval($purchase_info['total_paid_before'] ?? 0);
                $total_after = $total_before + $paid_amount;
                $balance_due = $purchase_info['total'] - $total_after;
                
                // Create bank transaction for UPI/Card/Bank payments
                if (in_array($payment_method, ['upi', 'card', 'bank']) && $bank_account_id > 0) {
                    // Create description with balance due information
                    $balance_status = $balance_due <= 0 ? "Fully Paid" : "Balance Due: ₹" . money2($balance_due);
                    $description = "Purchase payment: {$purchase_info['purchase_no']} to {$purchase_info['supplier_name']}";
                    $description .= " | Amount: ₹" . money2($paid_amount);
                    $description .= " | Previous Paid: ₹" . money2($total_before);
                    $description .= " | Total Paid After: ₹" . money2($total_after);
                    $description .= " | {$balance_status}";
                    
                    if (!empty($notes)) {
                        $description .= " | Notes: {$notes}";
                    }
                    
                    $tx_data = [
                        'bank_account_id' => $bank_account_id,
                        'transaction_date' => date('Y-m-d'),
                        'transaction_type' => 'purchase_payment',
                        'reference_type' => 'purchase',
                        'reference_id' => $purchase_id,
                        'reference_number' => $purchase_info['purchase_no'],
                        'party_name' => $purchase_info['supplier_name'],
                        'party_type' => 'supplier',
                        'description' => $description,
                        'amount' => $paid_amount,
                        'payment_method' => $payment_method,
                        'cheque_number' => '',
                        'cheque_date' => '',
                        'cheque_bank' => '',
                        'upi_ref_no' => $upi_ref_no,
                        'transaction_ref_no' => $transaction_ref_no ?: $purchase_info['purchase_no'] . '-' . strtoupper($payment_method) . '-' . $payment_history_id,
                        'notes' => "Payment for purchase via " . strtoupper($payment_method) . " | Balance Due: ₹" . money2($balance_due),
                        'balance_due' => $balance_due
                    ];
                    saveBankTransaction($conn, $tx_data);
                    
                    // Save last used bank account in cookie
                    $current_user_id = getCurrentUserId($conn);
                    if ($current_user_id > 0) {
                        setcookie('last_purchase_payment_bank_account', $bank_account_id, time() + (86400 * 30), '/');
                    }
                }
                
                // Get total paid so far after this payment
                $paid_stmt = $conn->prepare("
                    SELECT SUM(paid_amount) as total_paid 
                    FROM purchase_payment_history 
                    WHERE purchase_id = ?
                ");
                $paid_stmt->bind_param("i", $purchase_id);
                $paid_stmt->execute();
                $paid_result = $paid_stmt->get_result()->fetch_assoc();
                $total_paid = floatval($paid_result['total_paid'] ?? 0);
                
                // Log activity with balance information
                $balance_status = $balance_due <= 0 ? "fully paid" : "balance due: ₹" . money2($balance_due);
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_log (user_id, action, description) 
                    VALUES (?, 'payment', ?)
                ");
                $desc = "Added payment of ₹" . money2($paid_amount) . " to purchase " . $purchase_info['purchase_no'] . " via " . strtoupper($payment_method) . ". " . ucfirst($balance_status);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $desc);
                $log_stmt->execute();
                
                $conn->commit();
                $success_message = "Payment of ₹" . money2($paid_amount) . " added successfully! " . ucfirst($balance_status);
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error adding payment: " . $e->getMessage();
            }
        }
    }
    
    // Delete payment
    if ($_POST['action'] === 'delete_payment' && isset($_POST['payment_id'])) {
        $payment_id = intval($_POST['payment_id']);
        
        // Get payment details for logging
        $payment_info = $conn->query("
            SELECT p.purchase_no, pp.paid_amount, pp.payment_method
            FROM purchase_payment_history pp
            JOIN purchase p ON pp.purchase_id = p.id
            WHERE pp.id = $payment_id
        ")->fetch_assoc();
        
        // Note: In a production system, you might want to handle reversing bank transactions
        // For simplicity, we'll just delete the payment record
        
        $stmt = $conn->prepare("DELETE FROM purchase_payment_history WHERE id = ?");
        $stmt->bind_param("i", $payment_id);
        
        if ($stmt->execute()) {
            // Log activity
            $log_stmt = $conn->prepare("
                INSERT INTO activity_log (user_id, action, description) 
                VALUES (?, 'delete', ?)
            ");
            $desc = "Deleted payment of ₹" . money2($payment_info['paid_amount']) . " from purchase " . $payment_info['purchase_no'];
            $log_stmt->bind_param("is", $_SESSION['user_id'], $desc);
            $log_stmt->execute();
            
            $success_message = "Payment deleted successfully!";
        } else {
            $error_message = "Error deleting payment: " . $conn->error;
        }
    }
}

// --------------------------
// Get Filter Parameters
// --------------------------
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$supplier_filter = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Build query for purchases with payment info
$query = "
    SELECT 
        p.id,
        p.purchase_no,
        p.invoice_num,
        p.total,
        p.purchase_date,
        p.created_at,
        s.supplier_name,
        s.phone as supplier_phone,
        COALESCE(SUM(pp.paid_amount), 0) as total_paid,
        COUNT(pp.id) as payment_count,
        MAX(pp.payment_date) as last_payment_date
    FROM purchase p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN purchase_payment_history pp ON p.id = pp.purchase_id
    WHERE 1=1
";

if (!empty($search)) {
    $query .= " AND (p.purchase_no LIKE '%$search%' OR s.supplier_name LIKE '%$search%' OR p.invoice_num LIKE '%$search%')";
}

if ($supplier_filter > 0) {
    $query .= " AND p.supplier_id = $supplier_filter";
}

if (!empty($date_from)) {
    $query .= " AND DATE(p.purchase_date) >= '$date_from'";
}

if (!empty($date_to)) {
    $query .= " AND DATE(p.purchase_date) <= '$date_to'";
}

$query .= " GROUP BY p.id ORDER BY p.purchase_date DESC";

// Apply status filter after grouping
$purchases = $conn->query($query);
$filtered_purchases = [];

if ($purchases && $purchases->num_rows > 0) {
    while ($row = $purchases->fetch_assoc()) {
        $status = getPaymentStatus($row['total'], $row['total_paid']);
        if (empty($status_filter) || $status['badge'] === $status_filter) {
            $filtered_purchases[] = $row;
        }
    }
}

// Get all suppliers for filter dropdown
$suppliers = $conn->query("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name");

// Get all active bank accounts
$bank_accounts = $conn->query("SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, account_name ASC");

// Get last used bank account for current user
$current_user_id = getCurrentUserId($conn);
$last_bank_account = getLastUsedBankAccount($conn, $current_user_id);

// Get recent payments with bank info if available
$recent_payments = $conn->query("
    SELECT 
        pp.*,
        p.purchase_no,
        p.total as purchase_total,
        s.supplier_name,
        ba.account_name as bank_account_name,
        ba.bank_name,
        (SELECT SUM(paid_amount) FROM purchase_payment_history WHERE purchase_id = p.id AND id <= pp.id) as running_total
    FROM purchase_payment_history pp
    JOIN purchase p ON pp.purchase_id = p.id
    JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN bank_transactions bt ON bt.reference_id = p.id AND bt.reference_type = 'purchase' AND bt.amount = pp.paid_amount
    LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id
    ORDER BY pp.payment_date DESC
    LIMIT 10
");

// Calculate summary stats
$stats_query = "
    SELECT 
        COUNT(DISTINCT p.id) as total_purchases,
        SUM(p.total) as total_amount,
        COALESCE(SUM(pp.paid_amount), 0) as total_paid,
        COUNT(pp.id) as total_payments
    FROM purchase p
    LEFT JOIN purchase_payment_history pp ON p.id = pp.purchase_id
";
$stats = $conn->query($stats_query)->fetch_assoc();
$total_purchases = $stats['total_purchases'] ?? 0;
$total_amount = floatval($stats['total_amount'] ?? 0);
$total_paid = floatval($stats['total_paid'] ?? 0);
$total_pending = $total_amount - $total_paid;
$total_payments = $stats['total_payments'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1e293b;
            --light: #f8fafc;
        }
        
        body {
            background: #f0f4f8;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            border: 1px solid #edf2f9;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.05);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }
        
        .stat-icon.primary { background: #e8f2ff; color: #4361ee; }
        .stat-icon.success { background: #e3f9f2; color: #10b981; }
        .stat-icon.warning { background: #fff4dd; color: #f59e0b; }
        .stat-icon.danger { background: #fee2e2; color: #ef4444; }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            margin: 4px 0 0 0;
        }
        
        .stat-sub {
            font-size: 12px;
            color: #94a3b8;
        }
        
        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #edf2f9;
        }
        
        .filter-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-title i {
            color: var(--primary);
        }
        
        /* Purchase Cards */
        .purchase-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .purchase-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid #edf2f9;
            transition: all 0.2s;
        }
        
        .purchase-card:hover {
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        
        .purchase-header {
            padding: 16px;
            background: #f8fafc;
            border-bottom: 1px solid #edf2f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .purchase-number {
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
            font-size: 16px;
        }
        
        .purchase-number:hover {
            text-decoration: underline;
        }
        
        .purchase-status {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-success { background: #e3f9f2; color: #0b5e42; }
        .status-warning { background: #fff4dd; color: #92400e; }
        .status-danger { background: #fee2e2; color: #991b1b; }
        
        .purchase-body {
            padding: 16px;
        }
        
        .supplier-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        
        .supplier-avatar {
            width: 40px;
            height: 40px;
            background: #e8f2ff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-weight: 600;
            font-size: 16px;
        }
        
        .supplier-details {
            flex: 1;
        }
        
        .supplier-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }
        
        .supplier-meta {
            font-size: 12px;
            color: #64748b;
        }
        
        .amount-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .amount-label {
            font-size: 13px;
            color: #64748b;
        }
        
        .amount-value {
            font-weight: 600;
            color: var(--dark);
        }
        
        .progress-bar-container {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            margin: 12px 0;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--success));
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        .payment-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: #f8fafc;
            border-radius: 20px;
            font-size: 12px;
            color: #475569;
        }
        
        .purchase-footer {
            padding: 12px 16px;
            background: #f8fafc;
            border-top: 1px solid #edf2f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .last-payment {
            font-size: 11px;
            color: #64748b;
        }
        
        .btn-payment {
            padding: 6px 12px;
            border-radius: 8px;
            background: var(--primary);
            color: white;
            border: none;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-payment:hover {
            background: #2f4ad0;
            color: white;
            transform: translateY(-1px);
        }
        
        .btn-payment i {
            font-size: 14px;
        }
        
        /* Recent Payments */
        .recent-payments-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #edf2f9;
            margin-top: 30px;
        }
        
        .payment-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #edf2f9;
        }
        
        .payment-item:last-child {
            border-bottom: none;
        }
        
        .payment-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #e8f2ff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            margin-right: 12px;
        }
        
        .payment-details {
            flex: 1;
        }
        
        .payment-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }
        
        .payment-meta {
            font-size: 12px;
            color: #64748b;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .payment-amount {
            font-weight: 700;
            color: var(--success);
            font-size: 16px;
        }
        
        .balance-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .bank-badge {
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Modal */
        .modal-content {
            border-radius: 24px;
            border: none;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 24px 24px 0 0;
            padding: 20px 24px;
        }
        
        .modal-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #edf2f9;
        }
        
        .form-control, .form-select {
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .form-label {
            font-weight: 500;
            color: #475569;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
        }
        
        .btn-submit:hover {
            background: #2f4ad0;
            transform: translateY(-1px);
        }
        
        .bank-selection-row {
            background: #ecfdf3;
            border: 1px solid #a7f3d0;
            border-radius: 12px;
            padding: 16px;
            margin-top: 16px;
            margin-bottom: 8px;
        }
        
        .bank-selection-label {
            font-weight: 600;
            color: #047857;
            font-size: 14px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
        }
        
        .empty-state-icon {
            font-size: 64px;
            color: #cbd5e1;
            margin-bottom: 16px;
        }
        
        .empty-state-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
        }
        
        .empty-state-text {
            color: #64748b;
            margin-bottom: 24px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .purchase-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-card .row {
                flex-direction: column;
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
            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Purchase Payments</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Manage and track all purchase payments</p>
                </div>
                <div>
                    <a href="add-purchase.php" class="btn-outline-custom me-2">
                        <i class="bi bi-arrow-left"></i> Back to Purchases
                    </a>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="bi bi-cart"></i>
                    </div>
                    <div class="stat-label">Total Purchases</div>
                    <div class="stat-value"><?php echo $total_purchases; ?></div>
                    <div class="stat-sub"><?php echo $total_payments; ?> payments made</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="bi bi-currency-rupee"></i>
                    </div>
                    <div class="stat-label">Total Amount</div>
                    <div class="stat-value">₹<?php echo money2($total_amount); ?></div>
                    <div class="stat-sub">Across all purchases</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="bi bi-wallet"></i>
                    </div>
                    <div class="stat-label">Total Paid</div>
                    <div class="stat-value">₹<?php echo money2($total_paid); ?></div>
                    <div class="stat-sub"><?php echo round(($total_paid / max($total_amount, 1)) * 100); ?>% of total</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="bi bi-hourglass"></i>
                    </div>
                    <div class="stat-label">Pending Amount</div>
                    <div class="stat-value">₹<?php echo money2($total_pending); ?></div>
                    <div class="stat-sub">To be paid</div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <div class="filter-title">
                    <i class="bi bi-funnel"></i>
                    Filter Purchases
                </div>
                
                <form method="GET" action="purchase-payments.php" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Purchase #, Supplier..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Payment Status</label>
                            <select class="form-select" name="status">
                                <option value="">All Status</option>
                                <option value="success" <?php echo $status_filter === 'success' ? 'selected' : ''; ?>>Paid</option>
                                <option value="warning" <?php echo $status_filter === 'warning' ? 'selected' : ''; ?>>Partial</option>
                                <option value="danger" <?php echo $status_filter === 'danger' ? 'selected' : ''; ?>>Unpaid</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Supplier</label>
                            <select class="form-select" name="supplier_id">
                                <option value="">All Suppliers</option>
                                <?php if ($suppliers && $suppliers->num_rows > 0): ?>
                                    <?php while ($supplier = $suppliers->fetch_assoc()): ?>
                                        <option value="<?php echo $supplier['id']; ?>" 
                                            <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="date_from" 
                                   value="<?php echo $date_from; ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="date_to" 
                                   value="<?php echo $date_to; ?>">
                        </div>
                        
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <a href="purchase-payments.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Purchases Grid -->
            <?php if (count($filtered_purchases) > 0): ?>
                <div class="purchase-grid">
                    <?php foreach ($filtered_purchases as $purchase): 
                        $status = getPaymentStatus($purchase['total'], $purchase['total_paid']);
                        $balance_due = $purchase['total'] - $purchase['total_paid'];
                    ?>
                        <div class="purchase-card">
                            <div class="purchase-header">
                                <a href="view-purchase.php?id=<?php echo $purchase['id']; ?>" class="purchase-number">
                                    <i class="bi bi-receipt"></i> <?php echo htmlspecialchars($purchase['purchase_no']); ?>
                                </a>
                                <span class="purchase-status status-<?php echo $status['badge']; ?>">
                                    <?php echo $status['text']; ?>
                                </span>
                            </div>
                            
                            <div class="purchase-body">
                                <div class="supplier-info">
                                    <div class="supplier-avatar">
                                        <?php echo strtoupper(substr($purchase['supplier_name'] ?? 'S', 0, 1)); ?>
                                    </div>
                                    <div class="supplier-details">
                                        <div class="supplier-name">
                                            <?php echo htmlspecialchars($purchase['supplier_name'] ?? 'Unknown Supplier'); ?>
                                        </div>
                                        <div class="supplier-meta">
                                            <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($purchase['supplier_phone'] ?? 'No phone'); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="amount-info">
                                    <span class="amount-label">Total Amount:</span>
                                    <span class="amount-value">₹<?php echo money2($purchase['total']); ?></span>
                                </div>
                                
                                <div class="amount-info">
                                    <span class="amount-label">Paid Amount:</span>
                                    <span class="amount-value text-success">₹<?php echo money2($purchase['total_paid']); ?></span>
                                </div>
                                
                                <div class="amount-info">
                                    <span class="amount-label">Balance Due:</span>
                                    <span class="amount-value <?php echo $balance_due > 0 ? 'text-danger' : 'text-success'; ?>">
                                        ₹<?php echo money2($balance_due); ?>
                                    </span>
                                </div>
                                
                                <div class="progress-bar-container">
                                    <div class="progress-bar-fill" style="width: <?php echo $status['progress']; ?>%;"></div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="payment-badge">
                                        <i class="bi bi-credit-card"></i>
                                        <?php echo $purchase['payment_count']; ?> payments
                                    </span>
                                    <?php if (!empty($purchase['invoice_num'])): ?>
                                        <span class="payment-badge">
                                            <i class="bi bi-receipt"></i>
                                            Inv: <?php echo htmlspecialchars($purchase['invoice_num']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="purchase-footer">
                                <div class="last-payment">
                                    <?php if ($purchase['last_payment_date']): ?>
                                        <i class="bi bi-clock"></i> Last: <?php echo date('d M Y', strtotime($purchase['last_payment_date'])); ?>
                                    <?php else: ?>
                                        <i class="bi bi-clock"></i> No payments yet
                                    <?php endif; ?>
                                </div>
                                
                                <button type="button" class="btn-payment" 
                                        onclick="openPaymentModal(<?php echo $purchase['id']; ?>, '<?php echo htmlspecialchars($purchase['purchase_no']); ?>', <?php echo $purchase['total']; ?>, <?php echo $purchase['total_paid']; ?>)">
                                    <i class="bi bi-plus-circle"></i> Add Payment
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-credit-card"></i>
                    </div>
                    <h4 class="empty-state-title">No Purchases Found</h4>
                    <p class="empty-state-text">
                        <?php if (!empty($search) || !empty($status_filter) || $supplier_filter > 0 || !empty($date_from) || !empty($date_to)): ?>
                            No purchases match your filter criteria. Try adjusting your filters.
                        <?php else: ?>
                            No purchase records found. Create your first purchase to start tracking payments.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($search) || !empty($status_filter) || $supplier_filter > 0 || !empty($date_from) || !empty($date_to)): ?>
                        <a href="purchase-payments.php" class="btn btn-outline-primary">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    <?php else: ?>
                        <a href="add-purchase.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Add Purchase
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Recent Payments -->
            <?php if ($recent_payments && $recent_payments->num_rows > 0): ?>
            <div class="recent-payments-card">
                <h5 class="mb-3">
                    <i class="bi bi-clock-history me-2" style="color: var(--primary);"></i>
                    Recent Payments
                </h5>
                
                <?php while ($payment = $recent_payments->fetch_assoc()): 
                    $balance_after = $payment['purchase_total'] - ($payment['running_total'] ?? $payment['paid_amount']);
                ?>
                    <div class="payment-item">
                        <div class="payment-icon">
                            <?php 
                            $method_icon = [
                                'cash' => 'bi-cash',
                                'card' => 'bi-credit-card',
                                'upi' => 'bi-phone',
                                'bank' => 'bi-bank'
                            ];
                            $icon = $method_icon[$payment['payment_method']] ?? 'bi-wallet';
                            ?>
                            <i class="bi <?php echo $icon; ?>"></i>
                        </div>
                        <div class="payment-details">
                            <div class="payment-title">
                                <?php echo htmlspecialchars($payment['purchase_no']); ?> - 
                                <?php echo htmlspecialchars($payment['supplier_name']); ?>
                            </div>
                            <div class="payment-meta">
                                <span><i class="bi bi-calendar"></i> <?php echo date('d M Y, h:i A', strtotime($payment['payment_date'])); ?></span>
                                <span><i class="bi bi-credit-card"></i> <?php echo ucfirst($payment['payment_method']); ?></span>
                                <?php if (!empty($payment['bank_account_name'])): ?>
                                    <span class="bank-badge">
                                        <i class="bi bi-bank"></i> <?php echo htmlspecialchars($payment['bank_account_name']); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="balance-badge">
                                    <i class="bi bi-wallet2"></i> After: ₹<?php echo money2($balance_after); ?>
                                </span>
                                <?php if (!empty($payment['notes'])): ?>
                                    <span><i class="bi bi-chat"></i> <?php echo htmlspecialchars($payment['notes']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="payment-amount">
                            ₹<?php echo money2($payment['paid_amount']); ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle"></i>
                    Add Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form method="POST" action="purchase-payments.php" id="paymentForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_payment">
                    <input type="hidden" name="purchase_id" id="modal_purchase_id">
                    
                    <div class="mb-4">
                        <div class="bg-light p-3 rounded-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Purchase #:</span>
                                <strong id="modal_purchase_no" class="text-primary"></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Amount:</span>
                                <strong id="modal_total" class="text-dark"></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Already Paid:</span>
                                <strong id="modal_paid" class="text-success"></strong>
                            </div>
                            <div class="d-flex justify-content-between mt-2 pt-2 border-top">
                                <span>Balance Due:</span>
                                <strong id="modal_balance" class="text-danger"></strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">₹</span>
                            <input type="number" class="form-control" name="paid_amount" 
                                   id="modal_amount" step="0.01" min="0.01" required>
                        </div>
                        <small class="text-muted">Enter the amount you want to pay (Max: <span id="maxAmount"></span>)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" name="payment_method" id="payment_method" onchange="toggleBankFields()" required>
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="upi">UPI</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>
                    
                    <!-- Bank Account Selection (for UPI/Card/Bank payments) -->
                    <div id="bankAccountFields" style="display: none;">
                        <div class="bank-selection-row">
                            <div class="bank-selection-label">
                                <i class="bi bi-bank"></i> Bank Account Details
                            </div>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label">Select Bank Account <span class="text-danger">*</span></label>
                                    <select class="form-select" name="bank_account_id" id="bank_account_id">
                                        <option value="">Select Bank Account</option>
                                        <?php 
                                        if ($bank_accounts && $bank_accounts->num_rows > 0):
                                            mysqli_data_seek($bank_accounts, 0);
                                            while ($acc = $bank_accounts->fetch_assoc()): 
                                                $selected = ($last_bank_account && $last_bank_account['id'] == $acc['id']) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo $acc['id']; ?>" <?php echo $selected; ?>>
                                                <?php echo htmlspecialchars($acc['account_name'] . ' - ' . $acc['bank_name'] . ' (Balance: ₹' . money2($acc['current_balance']) . ')'); ?>
                                                <?php echo $acc['is_default'] ? ' [Default]' : ''; ?>
                                            </option>
                                        <?php 
                                            endwhile; 
                                        endif; 
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">UPI Ref / Transaction ID</label>
                                    <input type="text" class="form-control" name="upi_ref_no" 
                                           placeholder="Enter UPI reference or transaction ID">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Transaction Reference</label>
                                    <input type="text" class="form-control" name="transaction_ref_no" 
                                           placeholder="Enter transaction reference">
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-12">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i> 
                                        Transaction will be recorded in the selected bank account and balance will be updated.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" rows="2" 
                                  placeholder="Add any notes about this payment..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-submit" id="submitPayment">
                        <i class="bi bi-check-circle"></i> Add Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Initialize Select2
$(document).ready(function() {
    // Initialize Select2 for all select elements
    $('.form-select').not('#filterForm .form-select').select2({
        minimumResultsForSearch: 10,
        dropdownParent: $('#paymentModal')
    });
    
    // For filter form selects, don't set dropdownParent
    $('#filterForm .form-select').select2({
        minimumResultsForSearch: 10
    });
});

// Bank account fields toggle
function toggleBankFields() {
    const paymentMethod = document.getElementById('payment_method').value;
    const bankFields = document.getElementById('bankAccountFields');
    const bankSelect = document.getElementById('bank_account_id');
    
    if (paymentMethod === 'upi' || paymentMethod === 'card' || paymentMethod === 'bank') {
        bankFields.style.display = 'block';
        bankSelect.required = true;
        
        // Reinitialize Select2 for bank account dropdown
        setTimeout(function() {
            $('#bank_account_id').select2({
                minimumResultsForSearch: 10,
                dropdownParent: $('#paymentModal')
            });
        }, 100);
    } else {
        bankFields.style.display = 'none';
        bankSelect.required = false;
    }
}

// Save bank account selection to cookie
document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'bank_account_id') {
        const accountId = e.target.value;
        if (accountId) {
            document.cookie = "last_purchase_payment_bank_account=" + accountId + "; path=/; max-age=" + (30 * 24 * 60 * 60);
        }
    }
});

// Payment Modal Functions
function openPaymentModal(purchaseId, purchaseNo, total, paid) {
    const balance = total - paid;
    
    document.getElementById('modal_purchase_id').value = purchaseId;
    document.getElementById('modal_purchase_no').textContent = purchaseNo;
    document.getElementById('modal_total').textContent = '₹' + formatMoney(total);
    document.getElementById('modal_paid').textContent = '₹' + formatMoney(paid);
    document.getElementById('modal_balance').textContent = '₹' + formatMoney(balance);
    document.getElementById('maxAmount').textContent = '₹' + formatMoney(balance);
    
    // Set max amount to balance due
    document.getElementById('modal_amount').max = balance;
    document.getElementById('modal_amount').value = balance.toFixed(2);
    
    // Reset payment method to cash and hide bank fields initially
    document.getElementById('payment_method').value = 'cash';
    toggleBankFields();
    
    // Re-initialize Select2 for the modal
    setTimeout(function() {
        $('#paymentModal select[name="payment_method"]').select2({
            minimumResultsForSearch: 10,
            dropdownParent: $('#paymentModal')
        });
        $('#bank_account_id').select2({
            minimumResultsForSearch: 10,
            dropdownParent: $('#paymentModal')
        });
    }, 100);
    
    // Show modal
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

// Format money helper
function formatMoney(amount) {
    return amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

// Form validation
document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('modal_amount').value);
    const balance = parseFloat(document.getElementById('modal_balance').textContent.replace('₹', '').replace(/,/g, ''));
    const paymentMethod = document.getElementById('payment_method').value;
    const bankAccountId = document.getElementById('bank_account_id')?.value;
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid amount greater than 0');
        return;
    }
    
    if (amount > balance) {
        e.preventDefault();
        alert('Payment amount cannot exceed the balance due');
        return;
    }
    
    // Validate bank account for UPI/Card/Bank payments
    if ((paymentMethod === 'upi' || paymentMethod === 'card' || paymentMethod === 'bank') && !bankAccountId) {
        e.preventDefault();
        alert('Please select a bank account for ' + paymentMethod.toUpperCase() + ' payments');
        return;
    }
    
    // Disable submit button
    document.getElementById('submitPayment').disabled = true;
    document.getElementById('submitPayment').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
});

// Auto-submit filter on enter
document.querySelectorAll('#filterForm input, #filterForm select').forEach(element => {
    element.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Alt + N to focus search
    if (e.altKey && e.key === 'n') {
        e.preventDefault();
        document.querySelector('input[name="search"]')?.focus();
    }
});

// Fix for modal backdrop issue
document.addEventListener('hidden.bs.modal', function(event) {
    // Remove any lingering backdrops
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    
    // Clean up Select2
    if ($('#bank_account_id').hasClass('select2-hidden-accessible')) {
        $('#bank_account_id').select2('destroy');
    }
});
</script>

</body>
</html>