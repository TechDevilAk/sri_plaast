<?php
session_start();
$currentPage = 'expenses';
$pageTitle = 'Expense Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view expenses, but only admin can modify
checkRoleAccess(['admin', 'sale']);

$success = '';
$error   = '';
$is_admin = ($_SESSION['user_role'] === 'admin');

// ---------- Helpers ----------
function formatCurrency($amount) {
    return '₹' . number_format((float)$amount, 2);
}
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) unset($params[$key]);
    return count($params) ? '?' . http_build_query($params) : '';
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

// ---------- Safe activity log insert ----------
function insertActivityLog($conn, $action, $description) {
    $user_id = getCurrentUserId($conn);
    
    $action = mysqli_real_escape_string($conn, $action);
    $description = mysqli_real_escape_string($conn, $description);
    
    if ($user_id > 0) {
        mysqli_query($conn, "INSERT INTO activity_log (user_id, action, description) VALUES ($user_id, '$action', '$description')");
    } else {
        mysqli_query($conn, "INSERT INTO activity_log (user_id, action, description) VALUES (NULL, '$action', '$description')");
    }
}

// ---------- Save bank transaction ----------
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
        
        // Update bank account balance (expense is money out)
        $balance_query = "SELECT current_balance FROM bank_accounts WHERE id = $bank_account_id";
        $balance_result = mysqli_query($conn, $balance_query);
        $current_balance = mysqli_fetch_assoc($balance_result)['current_balance'];
        
        // For expenses (money out)
        $new_balance = $current_balance - $amount;
        
        $update_balance = "UPDATE bank_accounts SET current_balance = $new_balance WHERE id = $bank_account_id";
        mysqli_query($conn, $update_balance);
        
        // Update expense with bank transaction ID
        $update_expense = "UPDATE expense SET bank_account_id = $bank_account_id, bank_transaction_id = $transaction_id WHERE id = $reference_id";
        mysqli_query($conn, $update_expense);
        
        return $transaction_id;
    }
    
    return false;
}

// ---------- Get last used bank account for user ----------
function getLastUsedBankAccount($conn, $user_id) {
    // First check if there's a cookie with last selection
    if (isset($_COOKIE['last_expense_bank_account'])) {
        $account_id = (int)$_COOKIE['last_expense_bank_account'];
        $result = mysqli_query($conn, "SELECT * FROM bank_accounts WHERE id = $account_id AND status = 1");
        if ($row = mysqli_fetch_assoc($result)) {
            return $row;
        }
    }
    
    // If no cookie or account not found, return default account
    $result = mysqli_query($conn, "SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, id DESC LIMIT 1");
    return mysqli_fetch_assoc($result);
}

// ---------- ADD EXPENSE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_expense') {
    if (!$is_admin) {
        $error = 'You do not have permission to add expenses.';
    } else {
        $expense_date   = $_POST['expense_date'] ?? date('Y-m-d');
        $title          = trim($_POST['title'] ?? '');
        $description    = trim($_POST['description'] ?? '');
        $supplier_id    = (isset($_POST['supplier_id']) && $_POST['supplier_id'] !== '' && is_numeric($_POST['supplier_id'])) ? (int)$_POST['supplier_id'] : null;

        $amount         = (float)($_POST['amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $paid_amount    = (float)($_POST['paid_amount'] ?? 0);
        $pending_amount = ($_POST['pending_amount'] ?? '');

        $reference_no   = trim($_POST['reference_no'] ?? '');
        $bill_no        = trim($_POST['bill_no'] ?? '');
        
        // Bank account selection (for UPI/Bank payments)
        $bank_account_id = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;
        $upi_ref_no = trim($_POST['upi_ref_no'] ?? '');
        $transaction_ref_no = trim($_POST['transaction_ref_no'] ?? '');

        if ($title === '') {
            $error = 'Expense title is required.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than 0.';
        } elseif (($payment_method === 'upi' || $payment_method === 'bank') && $bank_account_id <= 0) {
            $error = 'Please select a bank account for UPI or Bank payments.';
        } else {
            // Auto-calc pending if not provided
            if ($pending_amount === '' || $pending_amount === null) {
                $pending_amount = max(0, $amount - $paid_amount);
            } else {
                $pending_amount = (float)$pending_amount;
            }

            // Start transaction for bank-related expenses
            $started_transaction = false;
            if ($payment_method === 'upi' || $payment_method === 'bank') {
                mysqli_begin_transaction($conn);
                $started_transaction = true;
            }

            $sql = "INSERT INTO expense
                    (expense_date, title, description, supplier_id, amount, payment_method, paid_amount, pending_amount, reference_no, bill_no, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);

            $uid = (int)$_SESSION['user_id'];

            // IMPORTANT: supplier_id can be NULL — this works fine with bind_param
            $stmt->bind_param(
                "sssidsddssi",
                $expense_date,
                $title,
                $description,
                $supplier_id,
                $amount,
                $payment_method,
                $paid_amount,
                $pending_amount,
                $reference_no,
                $bill_no,
                $uid
            );

            if ($stmt->execute()) {
                $expense_id = $stmt->insert_id;

                // Create bank transaction for UPI/Bank payments
                if (($payment_method === 'upi' || $payment_method === 'bank') && $bank_account_id > 0) {
                    try {
                        // Get supplier name for transaction
                        $supplier_name = '';
                        if ($supplier_id) {
                            $sup_res = mysqli_query($conn, "SELECT supplier_name FROM suppliers WHERE id = $supplier_id");
                            $sup = mysqli_fetch_assoc($sup_res);
                            $supplier_name = $sup['supplier_name'] ?? '';
                        }
                        
                        $party_name = $supplier_name ?: $title;
                        
                        $tx_data = [
                            'bank_account_id' => $bank_account_id,
                            'transaction_date' => $expense_date,
                            'transaction_type' => 'expense',
                            'reference_type' => 'expense',
                            'reference_id' => $expense_id,
                            'reference_number' => 'EXP-' . $expense_id,
                            'party_name' => $party_name,
                            'party_type' => 'supplier',
                            'description' => "Expense payment: {$title}" . ($reference_no ? " (Ref: {$reference_no})" : ""),
                            'amount' => $paid_amount > 0 ? $paid_amount : $amount,
                            'payment_method' => $payment_method,
                            'cheque_number' => '',
                            'cheque_date' => '',
                            'cheque_bank' => '',
                            'upi_ref_no' => $upi_ref_no,
                            'transaction_ref_no' => $transaction_ref_no ?: 'EXP-' . $expense_id,
                            'notes' => "Expense payment via " . strtoupper($payment_method)
                        ];
                        saveBankTransaction($conn, $tx_data);
                        
                        // Save last used bank account in cookie (only when user explicitly selects)
                        $current_user_id = getCurrentUserId($conn);
                        if ($current_user_id > 0 && $bank_account_id > 0) {
                            setcookie('last_expense_bank_account', $bank_account_id, time() + (86400 * 30), '/'); // 30 days
                        }
                        
                        if ($started_transaction) {
                            mysqli_commit($conn);
                        }
                    } catch (Exception $e) {
                        if ($started_transaction) {
                            mysqli_rollback($conn);
                        }
                        $error = "Failed to create bank transaction: " . $e->getMessage();
                    }
                } else {
                    // No bank transaction, just commit if we started one
                    if ($started_transaction) {
                        mysqli_commit($conn);
                    }
                }

                if (empty($error)) {
                    // Log activity
                    $log_desc  = "Added expense #EXP-" . $expense_id . ": " . $title . " (Amount: " . formatCurrency($amount) . ")";
                    insertActivityLog($conn, 'create', $log_desc);

                    $success = "Expense added successfully.";
                }
            } else {
                if ($started_transaction) {
                    mysqli_rollback($conn);
                }
                $error = "Failed to add expense. " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// ---------- EDIT EXPENSE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_expense' && isset($_POST['expense_id']) && is_numeric($_POST['expense_id'])) {
    if (!$is_admin) {
        $error = 'You do not have permission to edit expenses.';
    } else {
        $editId        = (int)$_POST['expense_id'];
        $expense_date  = $_POST['expense_date'] ?? date('Y-m-d');
        $title         = trim($_POST['title'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $supplier_id   = isset($_POST['supplier_id']) && is_numeric($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $amount        = (float)($_POST['amount'] ?? 0);
        $payment_method= $_POST['payment_method'] ?? 'cash';
        $paid_amount   = (float)($_POST['paid_amount'] ?? 0);
        $pending_amount= (float)($_POST['pending_amount'] ?? 0);
        $reference_no  = trim($_POST['reference_no'] ?? '');
        $bill_no       = trim($_POST['bill_no'] ?? '');
        
        // Bank account fields are read-only in edit mode

        if ($title === '') {
            $error = 'Expense title is required.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than 0.';
        } else {
            if (!isset($_POST['pending_amount']) || $_POST['pending_amount'] === '') {
                $pending_amount = max(0, $amount - $paid_amount);
            }

            // For editing, we won't modify bank transactions automatically
            // This prevents double entries and complexity

            $stmt = $conn->prepare("
                UPDATE expense
                SET expense_date=?, title=?, description=?, supplier_id=?, amount=?, payment_method=?, paid_amount=?, pending_amount=?, reference_no=?, bill_no=?
                WHERE id=?
            ");
            $sid = $supplier_id;
            $stmt->bind_param(
                "sssidsddssi",
                $expense_date, $title, $description, $sid,
                $amount, $payment_method, $paid_amount, $pending_amount,
                $reference_no, $bill_no, $editId
            );

            if ($stmt->execute()) {
                // Log activity
                $log_desc  = "Updated expense #EXP-" . $editId . ": " . $title;
                insertActivityLog($conn, 'update', $log_desc);

                $success = "Expense updated successfully.";
            } else {
                $error = "Failed to update expense.";
            }
            $stmt->close();
        }
    }
}

// ---------- DELETE EXPENSE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_expense' && isset($_POST['expense_id']) && is_numeric($_POST['expense_id'])) {
    if (!$is_admin) {
        $error = 'You do not have permission to delete expenses.';
    } else {
        $deleteId = (int)$_POST['expense_id'];

        // Get title for logging
        $q = $conn->prepare("SELECT title FROM expense WHERE id=?");
        $q->bind_param("i", $deleteId);
        $q->execute();
        $res = $q->get_result();
        $row = $res->fetch_assoc();
        $title = $row['title'] ?? 'Unknown';
        $q->close();

        $stmt = $conn->prepare("DELETE FROM expense WHERE id=?");
        $stmt->bind_param("i", $deleteId);

        if ($stmt->execute()) {
            $log_desc  = "Deleted expense #EXP-" . $deleteId . ": " . $title;
            insertActivityLog($conn, 'delete', $log_desc);

            $success = "Expense deleted successfully.";
        } else {
            $error = "Failed to delete expense.";
        }
        $stmt->close();
    }
}

// ---------- AJAX: GET EXPENSE ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_expense' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];

    $stmt = $conn->prepare("
        SELECT e.*,
               s.supplier_name,
               b.account_name as bank_account_name,
               b.bank_name
        FROM expense e
        LEFT JOIN suppliers s ON e.supplier_id = s.id
        LEFT JOIN bank_accounts b ON e.bank_account_id = b.id
        WHERE e.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $expense = $result->fetch_assoc();
        echo json_encode(['success' => true, 'expense' => $expense]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Expense not found']);
    }
    exit;
}

// ---------- FILTERS ----------
$filterMethod = $_GET['filter_method'] ?? '';
$filterFrom   = $_GET['from'] ?? '';
$filterTo     = $_GET['to'] ?? '';
$filterSearch = $_GET['search'] ?? '';

$where = "1=1";
$params = [];
$types = "";

if ($filterMethod !== '') {
    $where .= " AND e.payment_method = ?";
    $params[] = $filterMethod;
    $types .= "s";
}
if ($filterFrom !== '' && $filterTo !== '') {
    $where .= " AND e.expense_date BETWEEN ? AND ?";
    $params[] = $filterFrom;
    $params[] = $filterTo;
    $types .= "ss";
}
if ($filterSearch !== '') {
    $where .= " AND (e.title LIKE ? OR e.description LIKE ? OR e.reference_no LIKE ? OR e.bill_no LIKE ?)";
    $s = "%$filterSearch%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "ssss";
}

$sql = "
    SELECT e.*, s.supplier_name
    FROM expense e
    LEFT JOIN suppliers s ON e.supplier_id = s.id
    WHERE $where
    ORDER BY e.expense_date DESC, e.id DESC
";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $expenses = $stmt->get_result();
} else {
    $expenses = $conn->query($sql);
}

// ---------- STATS ----------
$totalExpenses = $conn->query("SELECT COALESCE(SUM(amount),0) total FROM expense")->fetch_assoc()['total'];
$todayExpenses = $conn->query("SELECT COALESCE(SUM(amount),0) total FROM expense WHERE expense_date = CURDATE()")->fetch_assoc()['total'];
$monthExpenses = $conn->query("SELECT COALESCE(SUM(amount),0) total FROM expense WHERE YEAR(expense_date)=YEAR(CURDATE()) AND MONTH(expense_date)=MONTH(CURDATE())")->fetch_assoc()['total'];
$totalPending  = $conn->query("SELECT COALESCE(SUM(pending_amount),0) total FROM expense WHERE pending_amount > 0")->fetch_assoc()['total'];

// suppliers for dropdown
$suppliers = $conn->query("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC");

// Get last used bank account for current user
$current_user_id = getCurrentUserId($conn);
$last_bank_account = getLastUsedBankAccount($conn, $current_user_id);

// Get all active bank accounts for dropdown
$bank_accounts = $conn->query("SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, account_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .stat-card-custom{background:#fff;border-radius:16px;padding:20px;border:1px solid #eef2f6;transition:.2s}
        .stat-card-custom:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.05)}
        .stat-value-large{font-size:28px;font-weight:700;color:#1e293b;line-height:1.2}
        .stat-label{font-size:13px;color:#64748b;margin-top:4px}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
        .pending-badge{background:#fee2e2;color:#dc2626;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:6px}
        .paid-badge{background:#dcfce7;color:#16a34a;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:6px}
        .permission-badge{font-size:11px;padding:2px 6px;border-radius:4px;background:#f1f5f9;color:#64748b}
        .bank-selection-row {
            background: #ecfdf3;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 15px;
        }
        .bank-selection-label {
            font-weight: 600;
            color: #047857;
            font-size: 12px;
        }
        .bank-badge {
            background:#dbeafe; color:#1e40af; padding:4px 8px; border-radius:30px;
            font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Expense Management</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Track and manage your business expenses</p>
                </div>
                <?php if ($is_admin): ?>
                    <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                        <i class="bi bi-plus-circle"></i> Add Expense
                    </button>
                <?php else: ?>
                    <span class="permission-badge"><i class="bi bi-eye"></i> View Only Mode</span>
                <?php endif; ?>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card-custom">
                    <div class="stat-value-large"><?php echo formatCurrency($totalExpenses); ?></div>
                    <div class="stat-label">Total Expenses</div>
                </div>
                <div class="stat-card-custom">
                    <div class="stat-value-large"><?php echo formatCurrency($todayExpenses); ?></div>
                    <div class="stat-label">Today</div>
                </div>
                <div class="stat-card-custom">
                    <div class="stat-value-large"><?php echo formatCurrency($monthExpenses); ?></div>
                    <div class="stat-label">This Month</div>
                </div>
                <div class="stat-card-custom">
                    <div class="stat-value-large" style="color:#dc2626;"><?php echo formatCurrency($totalPending); ?></div>
                    <div class="stat-label">Pending (Credit)</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="dashboard-card mb-4">
                <div class="card-body py-3">
                    <form method="GET" action="expenses.php" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Payment Method</label>
                            <select name="filter_method" class="form-select">
                                <option value="">All</option>
                                <?php foreach (['cash','card','upi','bank','cheque','credit'] as $m): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $filterMethod===$m?'selected':''; ?>>
                                        <?php echo strtoupper($m); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">From</label>
                            <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($filterFrom); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To</label>
                            <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($filterTo); ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Title / Ref / Bill..." value="<?php echo htmlspecialchars($filterSearch); ?>">
                        </div>

                        <div class="col-md-2 d-flex gap-2">
                            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                            <a href="expenses.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="dashboard-card">
                <div class="desktop-table" style="overflow-x:auto;">
                    <table class="table-custom" id="expensesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Title</th>
                                <th>Supplier</th>
                                <th>Amount</th>
                                <th>Paid</th>
                                <th>Pending</th>
                                <th>Method</th>
                                <?php if ($is_admin): ?><th style="text-align:center;">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($expenses && $expenses->num_rows > 0): ?>
                            <?php while($e = $expenses->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="order-id">#<?php echo (int)$e['id']; ?></span></td>
                                    <td><?php echo htmlspecialchars($e['expense_date']); ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($e['title']); ?></td>
                                    <td><?php echo htmlspecialchars($e['supplier_name'] ?? '-'); ?></td>
                                    <td class="fw-semibold"><?php echo formatCurrency($e['amount']); ?></td>
                                    <td><?php echo formatCurrency($e['paid_amount']); ?></td>
                                    <td>
                                        <?php if ((float)$e['pending_amount'] > 0): ?>
                                            <span class="pending-badge"><i class="bi bi-clock-history"></i><?php echo formatCurrency($e['pending_amount']); ?></span>
                                        <?php else: ?>
                                            <span class="paid-badge"><i class="bi bi-check-circle"></i>Paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo strtoupper(htmlspecialchars($e['payment_method'])); ?></td>
                                    <?php if ($is_admin): ?>
                                    <td>
                                        <div class="d-flex align-items-center justify-content-center gap-1" style="flex-wrap:wrap;">
                                            <button class="btn btn-sm btn-outline-info" onclick="viewExpense(<?php echo (int)$e['id']; ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editExpenseModal<?php echo (int)$e['id']; ?>" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" action="expenses.php<?php echo buildQueryString(['filter_method','from','to','search']); ?>"
                                                  onsubmit="return confirm('Delete this expense?')" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_expense">
                                                <input type="hidden" name="expense_id" value="<?php echo (int)$e['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editExpenseModal<?php echo (int)$e['id']; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <form method="POST" action="expenses.php<?php echo buildQueryString(['filter_method','from','to','search']); ?>">
                                                <input type="hidden" name="action" value="edit_expense">
                                                <input type="hidden" name="expense_id" value="<?php echo (int)$e['id']; ?>">

                                                <div class="modal-header">
                                                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Expense</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>

                                                <div class="modal-body">
                                                    <div class="row g-3">
                                                        <div class="col-md-4">
                                                            <label class="form-label">Date</label>
                                                            <input type="date" name="expense_date" class="form-control" value="<?php echo htmlspecialchars($e['expense_date']); ?>" required>
                                                        </div>
                                                        <div class="col-md-8">
                                                            <label class="form-label">Title <span class="text-danger">*</span></label>
                                                            <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($e['title']); ?>" required>
                                                        </div>

                                                        <div class="col-md-12">
                                                            <label class="form-label">Description</label>
                                                            <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($e['description']); ?></textarea>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Supplier (optional)</label>
                                                            <select name="supplier_id" class="form-select">
                                                                <option value="">-- None --</option>
                                                                <?php
                                                                // reset supplier cursor
                                                                $suppliers->data_seek(0);
                                                                while($s = $suppliers->fetch_assoc()):
                                                                ?>
                                                                    <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$e['supplier_id']===(int)$s['id'])?'selected':''; ?>>
                                                                        <?php echo htmlspecialchars($s['supplier_name']); ?>
                                                                    </option>
                                                                <?php endwhile; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-3">
                                                            <label class="form-label">Amount (₹) *</label>
                                                            <input type="number" name="amount" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($e['amount']); ?>" required>
                                                        </div>

                                                        <div class="col-md-3">
                                                            <label class="form-label">Method</label>
                                                            <select name="payment_method" class="form-select">
                                                                <?php foreach (['cash','card','upi','bank','cheque','credit'] as $m): ?>
                                                                    <option value="<?php echo $m; ?>" <?php echo ($e['payment_method']===$m)?'selected':''; ?>>
                                                                        <?php echo strtoupper($m); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Paid (₹)</label>
                                                            <input type="number" name="paid_amount" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($e['paid_amount']); ?>">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label">Pending (₹)</label>
                                                            <input type="number" name="pending_amount" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($e['pending_amount']); ?>">
                                                            <small class="text-muted">Leave blank to auto-calc</small>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label">Reference No</label>
                                                            <input type="text" name="reference_no" class="form-control" value="<?php echo htmlspecialchars($e['reference_no']); ?>">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label">Bill No</label>
                                                            <input type="text" name="bill_no" class="form-control" value="<?php echo htmlspecialchars($e['bill_no']); ?>">
                                                        </div>
                                                        
                                                        <!-- Bank account fields for UPI/Bank payments - read only in edit mode -->
                                                        <div class="col-md-12 mt-2" id="editBankFields_<?php echo (int)$e['id']; ?>">
                                                            <div class="bank-selection-row">
                                                                <label class="bank-selection-label"><i class="bi bi-bank"></i> Bank Account Information</label>
                                                                <div class="row g-2 mt-1">
                                                                    <div class="col-md-12">
                                                                        <small class="text-muted"><i class="bi bi-info-circle"></i> Bank transactions are created at the time of expense creation and cannot be modified here.</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                            <?php endwhile; ?>
                        <?php else: ?>
                           
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="expenses.php<?php echo buildQueryString(['filter_method','from','to','search']); ?>" id="addExpenseForm">
                <input type="hidden" name="action" value="add_expense">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Date</label>
                            <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" placeholder="Electricity / Salary / Transport..." required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Supplier (optional)</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">-- None --</option>
                                <?php 
                                $suppliers->data_seek(0);
                                while($s = $suppliers->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Amount (₹) *</label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0" value="0.00" required>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Method</label>
                            <select name="payment_method" class="form-select" id="paymentMethod" onchange="toggleBankFields()">
                                <?php foreach (['cash','card','upi','bank','cheque','credit'] as $m): ?>
                                    <option value="<?php echo $m; ?>"><?php echo strtoupper($m); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Bank Account Selection (for UPI/Bank payments) -->
                        <div class="col-md-12" id="bankAccountFields" style="display: none;">
                            <div class="bank-selection-row">
                                <div class="row align-items-center">
                                    <div class="col-md-3">
                                        <label class="bank-selection-label"><i class="bi bi-bank"></i> Bank Account</label>
                                    </div>
                                    <div class="col-md-9">
                                        <select class="form-select" name="bank_account_id" id="bank_account_id">
                                            <option value="">Select Bank Account (required for UPI/Bank payments)</option>
                                            <?php 
                                            if ($bank_accounts && mysqli_num_rows($bank_accounts) > 0):
                                                mysqli_data_seek($bank_accounts, 0);
                                                while ($acc = mysqli_fetch_assoc($bank_accounts)): 
                                                    $selected = ($last_bank_account && $last_bank_account['id'] == $acc['id']) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $acc['id']; ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($acc['account_name'] . ' - ' . $acc['bank_name'] . ' (Balance: ₹' . formatCurrency($acc['current_balance']) . ')'); ?>
                                                    <?php echo $acc['is_default'] ? ' [Default]' : ''; ?>
                                                </option>
                                            <?php 
                                                endwhile; 
                                            endif; 
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-3">
                                        <label class="form-label">UPI Ref No.</label>
                                        <input type="text" class="form-control" name="upi_ref_no" placeholder="UPI Reference">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Transaction Ref</label>
                                        <input type="text" class="form-control" name="transaction_ref_no" placeholder="Transaction Ref">
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted"><i class="bi bi-info-circle"></i> Transaction will be recorded in selected bank account</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Paid (₹)</label>
                            <input type="number" name="paid_amount" class="form-control" step="0.01" min="0" value="0.00">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Pending (₹)</label>
                            <input type="number" name="pending_amount" class="form-control" step="0.01" min="0" placeholder="Leave blank to auto-calc">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Reference No</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="UTR / Voucher / Ref...">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Bill No</label>
                            <input type="text" name="bill_no" class="form-control" placeholder="Bill/Invoice no...">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Expense</button>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- View Expense Modal -->
<div class="modal fade" id="viewExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt me-2" style="color:#2563eb;"></i>Expense Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewExpenseContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Loading expense details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-2"></i>Close</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    $('#expensesTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc']],
        dom: 'Bfrtip',
        buttons: [
            { extend:'excelHtml5', text:'<i class="bi bi-file-earmark-excel"></i> Excel', title:'Expenses', className:'btn btn-sm btn-outline-success' },
            { extend:'csvHtml5',   text:'<i class="bi bi-file-earmark-spreadsheet"></i> CSV', title:'Expenses', className:'btn btn-sm btn-outline-primary' },
            { extend:'pdfHtml5',   text:'<i class="bi bi-file-earmark-pdf"></i> PDF', title:'Expenses', className:'btn btn-sm btn-outline-danger', orientation:'landscape', pageSize:'A4' },
            { extend:'print',      text:'<i class="bi bi-printer"></i> Print', className:'btn btn-sm btn-outline-secondary' }
        ]
    });
    
    // Initialize bank fields visibility
    toggleBankFields();
    
    // Save bank account selection to cookie only when user manually changes it
    $('#bank_account_id').on('change', function() {
        const accountId = this.value;
        if (accountId) {
            // User explicitly selected a bank account, save their choice
            document.cookie = "last_expense_bank_account=" + accountId + "; path=/; max-age=" + (30 * 24 * 60 * 60);
        }
    });
});

function toggleBankFields() {
    const paymentMethod = document.getElementById('paymentMethod').value;
    const bankFields = document.getElementById('bankAccountFields');
    
    if (paymentMethod === 'upi' || paymentMethod === 'bank') {
        bankFields.style.display = 'block';
        document.getElementById('bank_account_id').required = true;
    } else {
        bankFields.style.display = 'none';
        document.getElementById('bank_account_id').required = false;
    }
}

// Form validation
document.getElementById('addExpenseForm')?.addEventListener('submit', function(e) {
    const paymentMethod = document.getElementById('paymentMethod').value;
    const bankAccountId = document.getElementById('bank_account_id')?.value;
    
    if ((paymentMethod === 'upi' || paymentMethod === 'bank') && !bankAccountId) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Bank Account Required',
            text: 'Please select a bank account for UPI or Bank payments.',
            confirmButtonText: 'OK'
        });
        return false;
    }
    return true;
});

// ✅ SweetAlert helper
function showSwalError(title, text) {
    Swal.fire({
        icon: 'error',
        title: title || 'Error',
        text: text || 'Something went wrong',
        confirmButtonText: 'OK'
    });
}

function viewExpense(id) {
    // Optional: show SweetAlert loading (instead of spinner inside modal)
    Swal.fire({
        title: 'Loading...',
        text: 'Fetching expense details',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: 'expenses.php?ajax=get_expense&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            Swal.close();

            if (!response.success) {
                showSwalError('Failed', response.message || 'Failed to load expense details.');
                return;
            }

            let e = response.expense;
            let pending = parseFloat(e.pending_amount || 0);

            let statusHtml = pending > 0
                ? `<span style="background:#fee2e2;color:#dc2626;padding:4px 10px;border-radius:20px;font-weight:600;">
                        <i class="bi bi-clock-history me-1"></i>Pending ₹${pending.toFixed(2)}
                   </span>`
                : `<span style="background:#dcfce7;color:#16a34a;padding:4px 10px;border-radius:20px;font-weight:600;">
                        <i class="bi bi-check-circle me-1"></i>Paid
                   </span>`;

            // Bank transaction info if available
            let bankInfo = '';
            if (e.bank_account_name) {
                bankInfo = `
                    <div class="mt-3" style="background:#ecfdf3;border:1px solid #a7f3d0;border-radius:8px;padding:10px;">
                        <div class="bank-selection-label"><i class="bi bi-bank"></i> Bank Transaction</div>
                        <div><b>Bank Account:</b> ${escapeHtml(e.bank_account_name)} - ${escapeHtml(e.bank_name || '')}</div>
                        <div><b>Transaction ID:</b> #${escapeHtml(String(e.bank_transaction_id || ''))}</div>
                    </div>
                `;
            }

            // ✅ Build modal HTML
            let html = `
                <div style="padding:5px;">
                    <div style="background:linear-gradient(135deg,#2463eb 0%,#7c3aed 100%);border-radius:12px;padding:18px;margin-bottom:16px;color:#fff;">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <div style="font-size:12px;opacity:.9;">Expense ID</div>
                                <div style="font-size:18px;font-weight:700;">EXP-${escapeHtml(String(e.id))}</div>
                            </div>
                            <div>${statusHtml}</div>
                        </div>
                        <div style="margin-top:10px;font-size:16px;font-weight:600;">${escapeHtml(e.title || '')}</div>
                        <div style="font-size:12px;opacity:.9;margin-top:4px;">
                            <i class="bi bi-calendar me-1"></i>${escapeHtml(e.expense_date || '')}
                            <span style="margin:0 10px;">•</span>
                            <i class="bi bi-credit-card me-1"></i>${escapeHtml((e.payment_method || '').toUpperCase())}
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;height:100%;">
                                <h6 style="color:#2563eb;border-bottom:1px solid #e2e8f0;padding-bottom:8px;margin-bottom:12px;">
                                    <i class="bi bi-info-circle me-2"></i>Details
                                </h6>
                                <div><b>Supplier:</b> ${escapeHtml(e.supplier_name || '-')}</div>
                                <div class="mt-2"><b>Description:</b><br>${escapeHtml(e.description || '-')}</div>
                                <div class="mt-2"><b>Reference No:</b> ${escapeHtml(e.reference_no || '-')}</div>
                                <div class="mt-1"><b>Bill No:</b> ${escapeHtml(e.bill_no || '-')}</div>
                                ${bankInfo}
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;height:100%;">
                                <h6 style="color:#2563eb;border-bottom:1px solid #e2e8f0;padding-bottom:8px;margin-bottom:12px;">
                                    <i class="bi bi-currency-rupee me-2"></i>Amounts
                                </h6>
                                <div><b>Amount:</b> ₹${parseFloat(e.amount || 0).toFixed(2)}</div>
                                <div class="mt-2"><b>Paid:</b> ₹${parseFloat(e.paid_amount || 0).toFixed(2)}</div>
                                <div class="mt-2"><b>Pending:</b> ₹${parseFloat(e.pending_amount || 0).toFixed(2)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // ✅ Load into Bootstrap modal
            $('#viewExpenseContent').html(html);
            $('#viewExpenseModal').modal('show');
        },
        error: function(xhr) {
            Swal.close();
            showSwalError('Network Error', 'Unable to load expense details. Please try again.');
        }
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>