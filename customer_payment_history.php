<?php
session_start();
$currentPage = 'customers';
$pageTitle = 'Customer Payment History';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view, but only admin can modify payments
checkRoleAccess(['admin', 'sale']);

// Get customer ID from URL
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if ($customer_id <= 0) {
    header('Location: customers.php');
    exit;
}

// Get customer details
$customer_query = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$customer_query->bind_param("i", $customer_id);
$customer_query->execute();
$customer_result = $customer_query->get_result();

if ($customer_result->num_rows == 0) {
    header('Location: customers.php');
    exit;
}

$customer = $customer_result->fetch_assoc();

$success = '';
$error = '';

// Get bank accounts for dropdown
$bank_accounts = $conn->query("SELECT id, account_name, bank_name, current_balance FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, account_name ASC");

// ==================== HANDLE OPENING BALANCE PAYMENT ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_opening_balance') {
    // Check if user is admin for payment operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to collect payments.';
    } else {
        $opening_balance = floatval($customer['opening_balance']);
        $paid_amount = floatval($_POST['paid_amount']);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $bank_account_id = isset($_POST['bank_account_id']) && $_POST['bank_account_id'] !== '' ? intval($_POST['bank_account_id']) : null;
        $reference_no = trim($_POST['reference_no'] ?? '');
        $cheque_number = trim($_POST['cheque_number'] ?? '');
        $cheque_date = !empty($_POST['cheque_date']) ? $_POST['cheque_date'] : null;
        $cheque_bank = trim($_POST['cheque_bank'] ?? '');
        $upi_ref_no = trim($_POST['upi_ref_no'] ?? '');
        $transaction_ref_no = trim($_POST['transaction_ref_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($opening_balance > 0) {
            if ($paid_amount > 0 && $paid_amount <= $opening_balance) {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    $new_opening_balance = $opening_balance - $paid_amount;
                    
                    // Update customer opening balance
                    $update = $conn->prepare("UPDATE customers SET opening_balance = ? WHERE id = ?");
                    $update->bind_param("di", $new_opening_balance, $customer_id);
                    $update->execute();
                    
                    // Save opening balance payment record
                    $insert_payment = $conn->prepare("
                        INSERT INTO opening_balance_payments 
                        (customer_id, payment_date, amount, payment_method, reference_no, notes, created_by) 
                        VALUES (?, NOW(), ?, ?, ?, ?, ?)
                    ");
                    $insert_payment->bind_param("idsssi", $customer_id, $paid_amount, $payment_method, $reference_no, $notes, $_SESSION['user_id']);
                    $insert_payment->execute();
                    
                    // Create bank transaction for non-cash payments
                    if ($payment_method !== 'credit' && $paid_amount > 0 && in_array($payment_method, ['bank', 'upi', 'card', 'cheque'])) {
                        if (!$bank_account_id) {
                            throw new Exception("Bank account is required for {$payment_method} payment.");
                        }
                        
                        // Verify bank account exists and is active
                        $bank_check = $conn->prepare("SELECT id, current_balance FROM bank_accounts WHERE id = ? AND status = 1");
                        $bank_check->bind_param("i", $bank_account_id);
                        $bank_check->execute();
                        $bank_data = $bank_check->get_result()->fetch_assoc();
                        
                        if (!$bank_data) {
                            throw new Exception("Selected bank account is not active or does not exist.");
                        }
                        $bank_check->close();
                        
                        // Insert bank transaction
                        $transaction_date = date('Y-m-d');
                        $party_name = $customer['customer_name'];
                        
                        $tx_stmt = $conn->prepare("
                            INSERT INTO bank_transactions 
                            (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, 
                             reference_number, party_name, party_type, description, amount, payment_method, 
                             status, cheque_number, cheque_date, cheque_bank, upi_ref_no, transaction_ref_no, 
                             notes, created_by) 
                            VALUES (?, ?, 'sale_credit', 'opening_balance', NULL, 
                                    ?, ?, 'customer', ?, ?, ?, 'completed', 
                                    ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $description = "Opening balance payment received from {$customer['customer_name']}";
                        $ref_number = "OPENING-" . date('Ymd') . "-" . $customer_id;
                        
                        $tx_stmt->bind_param("issssdsssssssi", 
                            $bank_account_id, $transaction_date, $ref_number, $party_name, 
                            $description, $paid_amount, $payment_method,
                            $cheque_number, $cheque_date, $cheque_bank, $upi_ref_no, $transaction_ref_no,
                            $notes, $_SESSION['user_id']
                        );
                        
                        if (!$tx_stmt->execute()) {
                            throw new Exception("Failed to create bank transaction: " . $conn->error);
                        }
                        $tx_stmt->close();
                        
                        // Update bank account balance
                        $new_balance = $bank_data['current_balance'] + $paid_amount;
                        $update_balance = $conn->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
                        $update_balance->bind_param("di", $new_balance, $bank_account_id);
                        
                        if (!$update_balance->execute()) {
                            throw new Exception("Failed to update bank account balance.");
                        }
                        $update_balance->close();
                    } elseif ($payment_method === 'cash') {
                        // For cash payments, try to find a cash account
                        $cash_account_query = $conn->prepare("SELECT id FROM bank_accounts WHERE account_name LIKE '%CASH%' OR account_type LIKE '%cash%' LIMIT 1");
                        $cash_account_query->execute();
                        $cash_account = $cash_account_query->get_result()->fetch_assoc();
                        $cash_account_query->close();
                        
                        if ($cash_account) {
                            // Insert cash transaction
                            $transaction_date = date('Y-m-d');
                            $party_name = $customer['customer_name'];
                            $ref_number = "CASH-OPENING-" . date('Ymd') . "-" . $customer_id;
                            
                            $tx_stmt = $conn->prepare("
                                INSERT INTO bank_transactions 
                                (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, 
                                 reference_number, party_name, party_type, description, amount, payment_method, 
                                 status, notes, created_by) 
                                VALUES (?, ?, 'sale_credit', 'opening_balance', NULL, 
                                        ?, ?, 'customer', ?, ?, 'cash', 'completed', ?, ?)
                            ");
                            
                            $description = "Cash payment received for opening balance from {$customer['customer_name']}";
                            
                            $tx_stmt->bind_param("issssdsi", 
                                $cash_account['id'], $transaction_date, $ref_number, $party_name,
                                $description, $paid_amount, $notes, $_SESSION['user_id']
                            );
                            
                            if ($tx_stmt->execute()) {
                                $tx_stmt->close();
                                
                                // Update cash account balance
                                $update_balance = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                                $update_balance->bind_param("di", $paid_amount, $cash_account['id']);
                                $update_balance->execute();
                                $update_balance->close();
                            }
                        }
                    }
                    
                    // Log activity
                    $log_desc = "Opening balance payment collected of ₹" . number_format($paid_amount, 2) . " from customer: " . $customer['customer_name'] . " via " . strtoupper($payment_method);
                    if (in_array($payment_method, ['bank', 'upi', 'card', 'cheque']) && $bank_account_id) {
                        $bank_query = $conn->prepare("SELECT account_name FROM bank_accounts WHERE id = ?");
                        $bank_query->bind_param("i", $bank_account_id);
                        $bank_query->execute();
                        $bank_info = $bank_query->get_result()->fetch_assoc();
                        if ($bank_info) {
                            $log_desc .= " to " . $bank_info['account_name'];
                        }
                        $bank_query->close();
                    }
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'payment', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $conn->commit();
                    $success = "Opening balance payment of ₹" . number_format($paid_amount, 2) . " collected successfully. Remaining opening balance: ₹" . number_format($new_opening_balance, 2);
                    
                    // Refresh customer data
                    $customer_query = $conn->prepare("SELECT * FROM customers WHERE id = ?");
                    $customer_query->bind_param("i", $customer_id);
                    $customer_query->execute();
                    $customer_result = $customer_query->get_result();
                    $customer = $customer_result->fetch_assoc();
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to collect opening balance payment: " . $e->getMessage();
                }
            } else {
                $error = "Invalid payment amount. Maximum allowed: ₹" . number_format($opening_balance, 2);
            }
        } else {
            $error = "No opening balance pending for this customer.";
        }
    }
}

// Handle single invoice payment collection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_payment') {
    // Check if user is admin for payment operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to collect payments.';
    } else {
        $invoice_id = intval($_POST['invoice_id']);
        $paid_amount = floatval($_POST['paid_amount']);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $bank_account_id = isset($_POST['bank_account_id']) && $_POST['bank_account_id'] !== '' ? intval($_POST['bank_account_id']) : null;
        $reference_no = trim($_POST['reference_no'] ?? '');
        $cheque_number = trim($_POST['cheque_number'] ?? '');
        $cheque_date = !empty($_POST['cheque_date']) ? $_POST['cheque_date'] : null;
        $cheque_bank = trim($_POST['cheque_bank'] ?? '');
        $upi_ref_no = trim($_POST['upi_ref_no'] ?? '');
        $transaction_ref_no = trim($_POST['transaction_ref_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // Get invoice details
        $invoice_query = $conn->prepare("SELECT inv_num, total, cash_received, pending_amount FROM invoice WHERE id = ? AND customer_id = ?");
        $invoice_query->bind_param("ii", $invoice_id, $customer_id);
        $invoice_query->execute();
        $invoice_result = $invoice_query->get_result();
        
        if ($invoice_result->num_rows > 0) {
            $invoice = $invoice_result->fetch_assoc();
            
            if ($paid_amount > 0 && $paid_amount <= $invoice['pending_amount']) {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    $new_paid = $invoice['cash_received'] + $paid_amount;
                    $new_pending = $invoice['pending_amount'] - $paid_amount;
                    
                    // Update invoice
                    $update = $conn->prepare("UPDATE invoice SET cash_received = ?, pending_amount = ?, payment_method = ? WHERE id = ?");
                    $update->bind_param("ddsi", $new_paid, $new_pending, $payment_method, $invoice_id);
                    $update->execute();
                    
                    // Create bank transaction for non-cash payments
                    if ($payment_method !== 'credit' && $paid_amount > 0 && in_array($payment_method, ['bank', 'upi', 'card', 'cheque'])) {
                        if (!$bank_account_id) {
                            throw new Exception("Bank account is required for {$payment_method} payment.");
                        }
                        
                        // Verify bank account exists and is active
                        $bank_check = $conn->prepare("SELECT id, current_balance FROM bank_accounts WHERE id = ? AND status = 1");
                        $bank_check->bind_param("i", $bank_account_id);
                        $bank_check->execute();
                        $bank_data = $bank_check->get_result()->fetch_assoc();
                        
                        if (!$bank_data) {
                            throw new Exception("Selected bank account is not active or does not exist.");
                        }
                        $bank_check->close();
                        
                        // Insert bank transaction
                        $transaction_date = date('Y-m-d');
                        $party_name = $customer['customer_name'];
                        
                        $tx_stmt = $conn->prepare("
                            INSERT INTO bank_transactions 
                            (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, 
                             reference_number, party_name, party_type, description, amount, payment_method, 
                             status, cheque_number, cheque_date, cheque_bank, upi_ref_no, transaction_ref_no, 
                             notes, created_by) 
                            VALUES (?, ?, 'sale', 'invoice', ?, 
                                    ?, ?, 'customer', ?, ?, ?, 'completed', 
                                    ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $description = "Payment received for invoice {$invoice['inv_num']} from {$customer['customer_name']}";
                        
                        $tx_stmt->bind_param("isisdsssssssi", 
                            $bank_account_id, $transaction_date, $invoice_id, $invoice['inv_num'], $party_name, 
                            $description, $paid_amount, $payment_method,
                            $cheque_number, $cheque_date, $cheque_bank, $upi_ref_no, $transaction_ref_no,
                            $notes, $_SESSION['user_id']
                        );
                        
                        if (!$tx_stmt->execute()) {
                            throw new Exception("Failed to create bank transaction: " . $conn->error);
                        }
                        
                        $transaction_id = $tx_stmt->insert_id;
                        $tx_stmt->close();
                        
                        // Update invoice with bank transaction reference
                        $update_invoice = $conn->prepare("
                            UPDATE invoice SET bank_account_id = ?, bank_transaction_id = ? 
                            WHERE id = ?
                        ");
                        $update_invoice->bind_param("iii", $bank_account_id, $transaction_id, $invoice_id);
                        $update_invoice->execute();
                        $update_invoice->close();
                        
                        // Update bank account balance
                        $new_balance = $bank_data['current_balance'] + $paid_amount;
                        $update_balance = $conn->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
                        $update_balance->bind_param("di", $new_balance, $bank_account_id);
                        
                        if (!$update_balance->execute()) {
                            throw new Exception("Failed to update bank account balance.");
                        }
                        $update_balance->close();
                    } elseif ($payment_method === 'cash') {
                        // For cash payments, try to find a cash account
                        $cash_account_query = $conn->prepare("SELECT id FROM bank_accounts WHERE account_name LIKE '%CASH%' OR account_type LIKE '%cash%' LIMIT 1");
                        $cash_account_query->execute();
                        $cash_account = $cash_account_query->get_result()->fetch_assoc();
                        $cash_account_query->close();
                        
                        if ($cash_account) {
                            // Insert cash transaction
                            $transaction_date = date('Y-m-d');
                            $party_name = $customer['customer_name'];
                            
                            $tx_stmt = $conn->prepare("
                                INSERT INTO bank_transactions 
                                (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, 
                                 reference_number, party_name, party_type, description, amount, payment_method, 
                                 status, notes, created_by) 
                                VALUES (?, ?, 'sale', 'invoice', ?, 
                                        ?, ?, 'customer', ?, ?, 'cash', 'completed', ?, ?)
                            ");
                            
                            $description = "Cash payment received for invoice {$invoice['inv_num']} from {$customer['customer_name']}";
                            
                            $tx_stmt->bind_param("isisddssi", 
                                $cash_account['id'], $transaction_date, $invoice_id,
                                $invoice['inv_num'], $party_name, $description, $paid_amount,
                                $notes, $_SESSION['user_id']
                            );
                            
                            if ($tx_stmt->execute()) {
                                $transaction_id = $tx_stmt->insert_id;
                                $tx_stmt->close();
                                
                                // Update invoice with cash transaction reference
                                $update_invoice = $conn->prepare("
                                    UPDATE invoice SET bank_account_id = ?, bank_transaction_id = ? 
                                    WHERE id = ?
                                ");
                                $update_invoice->bind_param("iii", $cash_account['id'], $transaction_id, $invoice_id);
                                $update_invoice->execute();
                                $update_invoice->close();
                                
                                // Update cash account balance
                                $update_balance = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                                $update_balance->bind_param("di", $paid_amount, $cash_account['id']);
                                $update_balance->execute();
                                $update_balance->close();
                            }
                        }
                    }
                    
                    // Log activity
                    $log_desc = "Payment collected of ₹" . number_format($paid_amount, 2) . " for invoice #" . $invoice['inv_num'] . " from customer: " . $customer['customer_name'] . " via " . strtoupper($payment_method);
                    if (in_array($payment_method, ['bank', 'upi', 'card', 'cheque']) && $bank_account_id) {
                        $bank_query = $conn->prepare("SELECT account_name FROM bank_accounts WHERE id = ?");
                        $bank_query->bind_param("i", $bank_account_id);
                        $bank_query->execute();
                        $bank_info = $bank_query->get_result()->fetch_assoc();
                        if ($bank_info) {
                            $log_desc .= " to " . $bank_info['account_name'];
                        }
                        $bank_query->close();
                    }
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'payment', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $conn->commit();
                    $success = "Payment of ₹" . number_format($paid_amount, 2) . " collected successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to collect payment: " . $e->getMessage();
                }
            } else {
                $error = "Invalid payment amount. Maximum allowed: ₹" . number_format($invoice['pending_amount'], 2);
            }
        } else {
            $error = "Invoice not found.";
        }
    }
}

// Handle overall pending payment collection (including opening balance)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_overall_pending') {
    // Check if user is admin for payment operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to collect payments.';
    } else {
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $bank_account_id = isset($_POST['bank_account_id']) && $_POST['bank_account_id'] !== '' ? intval($_POST['bank_account_id']) : null;
        $reference_no = trim($_POST['reference_no'] ?? '');
        $cheque_number = trim($_POST['cheque_number'] ?? '');
        $cheque_date = !empty($_POST['cheque_date']) ? $_POST['cheque_date'] : null;
        $cheque_bank = trim($_POST['cheque_bank'] ?? '');
        $upi_ref_no = trim($_POST['upi_ref_no'] ?? '');
        $transaction_ref_no = trim($_POST['transaction_ref_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $total_collected = 0;
            $collected_items = [];
            
            // 1. Handle opening balance collection
            $opening_balance = floatval($customer['opening_balance']);
            if ($opening_balance > 0) {
                $new_opening_balance = 0;
                $update_opening = $conn->prepare("UPDATE customers SET opening_balance = ? WHERE id = ?");
                $update_opening->bind_param("di", $new_opening_balance, $customer_id);
                $update_opening->execute();
                
                // Save opening balance payment record
                $insert_payment = $conn->prepare("
                    INSERT INTO opening_balance_payments 
                    (customer_id, payment_date, amount, payment_method, reference_no, notes, created_by) 
                    VALUES (?, NOW(), ?, ?, ?, ?, ?)
                ");
                $insert_payment->bind_param("idsssi", $customer_id, $opening_balance, $payment_method, $reference_no, $notes, $_SESSION['user_id']);
                $insert_payment->execute();
                
                $total_collected += $opening_balance;
                $collected_items[] = "Opening Balance: ₹" . number_format($opening_balance, 2);
            }
            
            // 2. Get all pending invoices for this customer
            $pending_query = $conn->prepare("SELECT id, inv_num, total, cash_received, pending_amount FROM invoice WHERE customer_id = ? AND pending_amount > 0");
            $pending_query->bind_param("i", $customer_id);
            $pending_query->execute();
            $pending_result = $pending_query->get_result();
            
            $invoice_ids = [];
            if ($pending_result->num_rows > 0) {
                while ($invoice = $pending_result->fetch_assoc()) {
                    $new_paid = $invoice['total'];
                    $new_pending = 0;
                    
                    // Update invoice
                    $update = $conn->prepare("UPDATE invoice SET cash_received = ?, pending_amount = ?, payment_method = ? WHERE id = ?");
                    $update->bind_param("ddsi", $new_paid, $new_pending, $payment_method, $invoice['id']);
                    $update->execute();
                    
                    $total_collected += $invoice['pending_amount'];
                    $collected_items[] = "Invoice #" . $invoice['inv_num'] . ": ₹" . number_format($invoice['pending_amount'], 2);
                    $invoice_ids[] = $invoice['id'];
                }
            }
            
            if ($total_collected > 0) {
                // Create bank transaction for non-cash payments
                if ($payment_method !== 'credit' && $total_collected > 0 && in_array($payment_method, ['bank', 'upi', 'card', 'cheque'])) {
                    if (!$bank_account_id) {
                        throw new Exception("Bank account is required for {$payment_method} payment.");
                    }
                    
                    // Verify bank account exists and is active
                    $bank_check = $conn->prepare("SELECT id, current_balance FROM bank_accounts WHERE id = ? AND status = 1");
                    $bank_check->bind_param("i", $bank_account_id);
                    $bank_check->execute();
                    $bank_data = $bank_check->get_result()->fetch_assoc();
                    
                    if (!$bank_data) {
                        throw new Exception("Selected bank account is not active or does not exist.");
                    }
                    $bank_check->close();
                    
                    // Insert bank transaction
                    $transaction_date = date('Y-m-d');
                    $party_name = $customer['customer_name'];
                    $ref_number = "OVERALL-" . date('Ymd') . "-" . $customer_id;
                    
                    $description = "Overall payment received from {$customer['customer_name']} for " . 
                                   (count($collected_items) > 0 ? implode(", ", array_slice($collected_items, 0, 3)) : "multiple invoices") .
                                   (count($collected_items) > 3 ? " and " . (count($collected_items) - 3) . " more" : "");
                    
                    $tx_stmt = $conn->prepare("
                        INSERT INTO bank_transactions 
                        (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, 
                         reference_number, party_name, party_type, description, amount, payment_method, 
                         status, cheque_number, cheque_date, cheque_bank, upi_ref_no, transaction_ref_no, 
                         notes, created_by) 
                        VALUES (?, ?, 'sale_credit', 'overall_payment', NULL, 
                                ?, ?, 'customer', ?, ?, ?, 'completed', 
                                ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $tx_stmt->bind_param("issssdsssssssi", 
                        $bank_account_id, $transaction_date, $ref_number, $party_name, 
                        $description, $total_collected, $payment_method,
                        $cheque_number, $cheque_date, $cheque_bank, $upi_ref_no, $transaction_ref_no,
                        $notes, $_SESSION['user_id']
                    );
                    
                    if (!$tx_stmt->execute()) {
                        throw new Exception("Failed to create bank transaction: " . $conn->error);
                    }
                    $tx_stmt->close();
                    
                    // Update bank account balance
                    $new_balance = $bank_data['current_balance'] + $total_collected;
                    $update_balance = $conn->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
                    $update_balance->bind_param("di", $new_balance, $bank_account_id);
                    
                    if (!$update_balance->execute()) {
                        throw new Exception("Failed to update bank account balance.");
                    }
                    $update_balance->close();
                } elseif ($payment_method === 'cash') {
                    // For cash payments, try to find a cash account
                    $cash_account_query = $conn->prepare("SELECT id FROM bank_accounts WHERE account_name LIKE '%CASH%' OR account_type LIKE '%cash%' LIMIT 1");
                    $cash_account_query->execute();
                    $cash_account = $cash_account_query->get_result()->fetch_assoc();
                    $cash_account_query->close();
                    
                    if ($cash_account) {
                        // Insert cash transaction
                        $transaction_date = date('Y-m-d');
                        $party_name = $customer['customer_name'];
                        $ref_number = "OVERALL-CASH-" . date('Ymd') . "-" . $customer_id;
                        
                        $tx_stmt = $conn->prepare("
                            INSERT INTO bank_transactions 
                            (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, 
                             reference_number, party_name, party_type, description, amount, payment_method, 
                             status, notes, created_by) 
                            VALUES (?, ?, 'sale_credit', 'overall_payment', NULL, 
                                    ?, ?, 'customer', ?, ?, 'cash', 'completed', ?, ?)
                        ");
                        
                        $description = "Overall cash payment received from {$customer['customer_name']} for " . 
                                      (count($collected_items) > 0 ? implode(", ", array_slice($collected_items, 0, 3)) : "multiple invoices");
                        
                        $tx_stmt->bind_param("issssdsi", 
                            $cash_account['id'], $transaction_date, $ref_number, $party_name,
                            $description, $total_collected, $notes, $_SESSION['user_id']
                        );
                        
                        if ($tx_stmt->execute()) {
                            $tx_stmt->close();
                            
                            // Update cash account balance
                            $update_balance = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                            $update_balance->bind_param("di", $total_collected, $cash_account['id']);
                            $update_balance->execute();
                            $update_balance->close();
                        }
                    }
                }
                
                // Log activity for each collected item
                foreach ($collected_items as $item) {
                    $log_desc = "Payment collected of " . $item . " from customer: " . $customer['customer_name'] . " via " . strtoupper($payment_method);
                    if (in_array($payment_method, ['bank', 'upi', 'card', 'cheque']) && $bank_account_id) {
                        $bank_query = $conn->prepare("SELECT account_name FROM bank_accounts WHERE id = ?");
                        $bank_query->bind_param("i", $bank_account_id);
                        $bank_query->execute();
                        $bank_info = $bank_query->get_result()->fetch_assoc();
                        if ($bank_info) {
                            $log_desc .= " to " . $bank_info['account_name'];
                        }
                        $bank_query->close();
                    }
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'payment', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
                
                $conn->commit();
                $success = "Overall payment of ₹" . number_format($total_collected, 2) . " collected successfully.<br>";
                $success .= "Collected items:<br>";
                foreach ($collected_items as $item) {
                    $success .= "• " . $item . "<br>";
                }
                
                // Refresh customer data
                $customer_query = $conn->prepare("SELECT * FROM customers WHERE id = ?");
                $customer_query->bind_param("i", $customer_id);
                $customer_query->execute();
                $customer_result = $customer_query->get_result();
                $customer = $customer_result->fetch_assoc();
            } else {
                $conn->rollback();
                $error = "No pending payments found for this customer.";
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to collect overall payment: " . $e->getMessage();
        }
    }
}

// Handle delete invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_invoice' && isset($_POST['invoice_id'])) {
    // Check if user is admin for delete operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete invoices.';
    } else {
        $deleteId = intval($_POST['invoice_id']);
        
        // Get invoice details for logging and stock reversal
        $inv_query = $conn->prepare("SELECT inv_num, total FROM invoice WHERE id = ? AND customer_id = ?");
        $inv_query->bind_param("ii", $deleteId, $customer_id);
        $inv_query->execute();
        $inv_result = $inv_query->get_result();
        $inv_data = $inv_result->fetch_assoc();
        
        if ($inv_data) {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get invoice items to reverse stock
                $items_query = $conn->prepare("SELECT cat_id, quantity FROM invoice_item WHERE invoice_id = ?");
                $items_query->bind_param("i", $deleteId);
                $items_query->execute();
                $items_result = $items_query->get_result();
                
                while ($item = $items_result->fetch_assoc()) {
                    // Add back the quantity to category stock
                    if (!empty($item['cat_id'])) {
                        $update_stock = $conn->prepare("UPDATE category SET total_quantity = total_quantity + ? WHERE id = ?");
                        $update_stock->bind_param("di", $item['quantity'], $item['cat_id']);
                        $update_stock->execute();
                    }
                }
                
                // Delete invoice items (cascade will handle due to foreign key)
                $stmt = $conn->prepare("DELETE FROM invoice WHERE id = ?");
                $stmt->bind_param("i", $deleteId);
                
                if ($stmt->execute()) {
                    // Log activity
                    $log_desc = "Deleted invoice: " . $inv_data['inv_num'] . " (Total: ₹" . number_format($inv_data['total'], 2) . ") for customer: " . $customer['customer_name'];
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $conn->commit();
                    $success = "Invoice deleted successfully and stock updated.";
                } else {
                    throw new Exception("Failed to delete invoice");
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to delete invoice: " . $e->getMessage();
            }
        } else {
            $error = "Invoice not found.";
        }
    }
}

// Get all invoices for this customer with payment details
$invoices_query = $conn->prepare("
    SELECT i.*, 
           (SELECT COUNT(*) FROM invoice_item WHERE invoice_id = i.id) as item_count
    FROM invoice i 
    WHERE i.customer_id = ? 
    ORDER BY i.created_at DESC
");
$invoices_query->bind_param("i", $customer_id);
$invoices_query->execute();
$invoices = $invoices_query->get_result();

// Get opening balance payments for this customer
$opening_payments_query = $conn->prepare("
    SELECT * FROM opening_balance_payments 
    WHERE customer_id = ? 
    ORDER BY payment_date DESC
");
$opening_payments_query->bind_param("i", $customer_id);
$opening_payments_query->execute();
$opening_payments_result = $opening_payments_query->get_result();

// Calculate totals
$total_billed = 0;
$total_paid = 0;
$total_pending = 0;
$total_opening_paid = 0;

$invoices_data = [];
while ($inv = $invoices->fetch_assoc()) {
    $invoices_data[] = $inv;
    $total_billed += $inv['total'];
    $total_paid += $inv['cash_received'];
    $total_pending += $inv['pending_amount'];
}

// Calculate total opening balance payments
$opening_payments_list = [];
while ($payment = $opening_payments_result->fetch_assoc()) {
    $opening_payments_list[] = $payment;
    $total_opening_paid += $payment['amount'];
}

// Get current opening balance
$opening_balance = floatval($customer['opening_balance']);
$grand_total_pending = $total_pending + $opening_balance;

// Format helpers
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function getPaymentStatusBadge($pending_amount) {
    if ($pending_amount == 0) {
        return '<span class="paid-badge"><i class="bi bi-check-circle"></i> Paid</span>';
    } else if ($pending_amount > 0 && $pending_amount < 100) {
        return '<span class="pending-badge" style="background: #fef3c7; color: #d97706;"><i class="bi bi-clock-history"></i> Partial</span>';
    } else {
        return '<span class="pending-badge"><i class="bi bi-exclamation-circle"></i> Pending</span>';
    }
}

$is_admin = ($_SESSION['user_role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
    <style>
        .invoice-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, #2463eb 0%, #1e4fbd 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
        }
        
        .invoice-avatar.small {
            width: 32px;
            height: 32px;
            font-size: 14px;
        }
        
        .invoice-info-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .invoice-number-text {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        
        .invoice-meta-text {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
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
            transition: all 0.2s;
        }
        
        .stat-card-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
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
        
        .pending-badge {
            background: #fee2e2;
            color: #dc2626;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .paid-badge {
            background: #dcfce7;
            color: #16a34a;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .customer-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            color: white;
        }
        
        .customer-header h4 {
            margin: 0 0 8px 0;
            font-weight: 600;
        }
        
        .customer-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }
        
        .customer-contact {
            display: flex;
            gap: 20px;
            margin-top: 15px;
        }
        
        .customer-contact span {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            background: rgba(255,255,255,0.1);
            padding: 6px 12px;
            border-radius: 20px;
        }
        
        .overall-pending-card {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .overall-pending-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .overall-pending-amount {
            font-size: 24px;
            font-weight: 700;
            color: #b45309;
        }
        
        .opening-balance-card {
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .opening-balance-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .opening-balance-amount {
            font-size: 24px;
            font-weight: 700;
            color: #7c3aed;
        }
        
        .payment-method-selector {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .payment-method-option {
            flex: 1;
            min-width: 80px;
        }
        
        .payment-method-option input[type="radio"] {
            display: none;
        }
        
        .payment-method-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 5px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 12px;
        }
        
        .payment-method-option input[type="radio"]:checked + label {
            border-color: #2463eb;
            background: #eef2ff;
            color: #2463eb;
        }
        
        .payment-method-option label i {
            font-size: 20px;
            margin-bottom: 4px;
        }
        
        .back-button {
            background: white;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-button:hover {
            background: #f8fafc;
        }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        .collect-payment-btn {
            background: #10b981;
            color: white;
            border: none;
        }
        
        .collect-payment-btn:hover {
            background: #059669;
            color: white;
        }
        
        .collect-opening-btn {
            background: #8b5cf6;
            color: white;
            border: none;
        }
        
        .collect-opening-btn:hover {
            background: #7c3aed;
            color: white;
        }
        
        .nav-tabs-custom {
            display: flex;
            gap: 10px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 10px;
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
        
        .bank-account-fields {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            display: none;
            border: 1px solid #e2e8f0;
        }
        
        .bank-account-fields.visible {
            display: block;
        }
        
        .field-group {
            margin-bottom: 12px;
        }
        
        .field-group label {
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 5px;
            display: block;
            color: #334155;
        }
        
        .field-group input, .field-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .field-group input:focus, .field-group select:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 2px rgba(59,130,246,0.1);
        }
        
        .reference-hint {
            font-size: 11px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .modal-lg-custom {
            max-width: 800px;
        }
        
        hr {
            margin: 15px 0;
        }
        
        .text-danger {
            color: #dc2626;
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <!-- Page Header with Back Button and Navigation Tabs -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div class="d-flex align-items-center gap-3">
                    <a href="customers.php" class="back-button">
                        <i class="bi bi-arrow-left"></i> Back to Customers
                    </a>
                    <div>
                        <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Payment History</h4>
                        <p style="font-size: 14px; color: var(--text-muted); margin: 0;">View and manage customer payments</p>
                    </div>
                </div>
                
                <!-- Navigation Tabs -->
                <div class="nav-tabs-custom">
                    <a href="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>" class="nav-tab-custom active">
                        <i class="bi bi-list-ul"></i> Payment History
                    </a>
                    <a href="customer_pay_history.php?customer_id=<?php echo $customer_id; ?>" class="nav-tab-custom">
                        <i class="bi bi-bank"></i> Payment Statement
                    </a>
                </div>
                
                <?php if (!$is_admin): ?>
                    <span class="permission-badge"><i class="bi bi-eye"></i> View Only Mode</span>
                <?php endif; ?>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Customer Header -->
            <div class="customer-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <h4><?php echo htmlspecialchars($customer['customer_name']); ?></h4>
                        <p>Customer ID: #<?php echo $customer['id']; ?></p>
                        
                        <div class="customer-contact">
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
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo formatCurrency($total_billed); ?></div>
                            <div class="stat-label">Total Billed</div>
                        </div>
                        <div class="stat-icon blue" style="width: 48px; height: 48px;">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo formatCurrency($total_paid); ?></div>
                            <div class="stat-label">Total Paid (Invoices)</div>
                        </div>
                        <div class="stat-icon green" style="width: 48px; height: 48px;">
                            <i class="bi bi-cash"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large" style="color: #dc2626;"><?php echo formatCurrency($total_pending); ?></div>
                            <div class="stat-label">Pending (Invoices)</div>
                        </div>
                        <div class="stat-icon orange" style="width: 48px; height: 48px;">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-custom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value-large"><?php echo count($invoices_data); ?></div>
                            <div class="stat-label">Total Invoices</div>
                        </div>
                        <div class="stat-icon purple" style="width: 48px; height: 48px;">
                            <i class="bi bi-file-text"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Opening Balance Section -->
            <?php if ($opening_balance > 0): ?>
                <div class="opening-balance-card">
                    <div class="opening-balance-info">
                        <div>
                            <div style="font-size: 14px; color: #6b21a5; margin-bottom: 5px;">
                                <i class="bi bi-wallet2 me-1"></i>
                                Opening Balance (Previous Dues)
                            </div>
                            <div class="opening-balance-amount"><?php echo formatCurrency($opening_balance); ?></div>
                            <?php if ($total_opening_paid > 0): ?>
                                <div style="font-size: 11px; color: #6b21a5; margin-top: 5px;">
                                    <i class="bi bi-check-circle"></i> Previously paid: <?php echo formatCurrency($total_opening_paid); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($is_admin): ?>
                            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#openingBalanceModal">
                                <i class="bi bi-cash me-2"></i>
                                Collect Opening Balance
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Overall Pending Section (includes both invoices and opening balance) -->
            <?php if ($grand_total_pending > 0 && $is_admin): ?>
                <div class="overall-pending-card">
                    <div class="overall-pending-info">
                        <div>
                            <div style="font-size: 14px; color: #92400e; margin-bottom: 5px;">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                Overall Pending Amount
                            </div>
                            <div class="overall-pending-amount"><?php echo formatCurrency($grand_total_pending); ?></div>
                            <?php if ($opening_balance > 0 && $total_pending > 0): ?>
                                <div style="font-size: 11px; color: #b45309; margin-top: 5px;">
                                    (Opening: <?php echo formatCurrency($opening_balance); ?> + Invoices: <?php echo formatCurrency($total_pending); ?>)
                                </div>
                            <?php elseif ($opening_balance > 0): ?>
                                <div style="font-size: 11px; color: #b45309; margin-top: 5px;">
                                    (Opening Balance Only)
                                </div>
                            <?php elseif ($total_pending > 0): ?>
                                <div style="font-size: 11px; color: #b45309; margin-top: 5px;">
                                    (Invoice Pending Only)
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#overallPaymentModal">
                            <i class="bi bi-cash-stack me-2"></i>
                            Collect Overall Pending
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Opening Balance Payment History -->
            <?php if (!empty($opening_payments_list)): ?>
            <div class="dashboard-card mt-4">
                <div class="card-header py-3" style="background: white; border-bottom: 1px solid #eef2f6;">
                    <h5 class="mb-0 fw-semibold" style="font-size: 16px;">
                        <i class="bi bi-wallet2 me-2" style="color: #8b5cf6;"></i>
                        Opening Balance Payment History
                    </h5>
                </div>
                <div class="table-responsive">
                    <table class="table-custom" id="openingPaymentsTable">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Reference No.</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($opening_payments_list as $payment): ?>
                                <tr>
                                    <td><?php echo date('d M Y h:i A', strtotime($payment['payment_date'])); ?></td>
                                    <td class="fw-semibold" style="color: #10b981;"><?php echo formatCurrency($payment['amount']); ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <i class="bi bi-<?php 
                                                echo $payment['payment_method'] == 'cash' ? 'cash' : 
                                                    ($payment['payment_method'] == 'card' ? 'credit-card' : 
                                                    ($payment['payment_method'] == 'upi' ? 'phone' : 
                                                    ($payment['payment_method'] == 'cheque' ? 'journal-check' : 'bank'))); 
                                            ?> me-1"></i>
                                            <?php echo ucfirst($payment['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['reference_no'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['notes'] ?: '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="fw-bold" style="background: #f8fafc;">
                                <td><strong>Total Opening Balance Payments</strong></td>
                                <td style="color: #10b981;"><?php echo formatCurrency($total_opening_paid); ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Invoices Table -->
            <div class="dashboard-card mt-4" data-testid="invoices-table">
                <div class="card-header py-3" style="background: white; border-bottom: 1px solid #eef2f6;">
                    <h5 class="mb-0 fw-semibold" style="font-size: 16px;">
                        <i class="bi bi-receipt me-2" style="color: #3b82f6;"></i>
                        Invoice Payment History
                    </h5>
                </div>
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="paymentHistoryTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Invoice Details</th>
                                <th>Items</th>
                                <th>Total Amount</th>
                                <th>Paid Amount</th>
                                <th>Pending Amount</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                                <th>Date</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($invoices_data)): ?>
                                <?php foreach ($invoices_data as $invoice): ?>
                                    <tr>
                                        <td><span class="order-id">#<?php echo $invoice['id']; ?></span></td>
                                        <td>
                                            <div class="invoice-info-cell">
                                                <div class="invoice-avatar small">INV</div>
                                                <div>
                                                    <div class="invoice-number-text"><?php echo htmlspecialchars($invoice['inv_num']); ?></div>
                                                    <div class="invoice-meta-text">
                                                        <i class="bi bi-box-seam"></i> <?php echo $invoice['item_count']; ?> items
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center"><?php echo $invoice['item_count']; ?></td>
                                        <td class="fw-semibold"><?php echo formatCurrency($invoice['total']); ?></td>
                                        <td class="fw-semibold" style="color: #10b981;"><?php echo formatCurrency($invoice['cash_received']); ?></td>
                                        <td class="fw-semibold" style="color: <?php echo $invoice['pending_amount'] > 0 ? '#dc2626' : '#64748b'; ?>;">
                                            <?php echo formatCurrency($invoice['pending_amount']); ?>
                                        </td>
                                        <td>
                                            <span class="payment-method-badge">
                                                <i class="bi bi-<?php 
                                                    echo $invoice['payment_method'] == 'cash' ? 'cash' : 
                                                        ($invoice['payment_method'] == 'card' ? 'credit-card' : 
                                                        ($invoice['payment_method'] == 'upi' ? 'phone' : 
                                                        ($invoice['payment_method'] == 'cheque' ? 'journal-check' : 'bank'))); 
                                                ?>"></i>
                                                <?php echo ucfirst($invoice['payment_method']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo getPaymentStatusBadge($invoice['pending_amount']); ?>
                                        </td>
                                        <td style="color: var(--text-muted); white-space: nowrap;">
                                            <?php echo date('d M Y', strtotime($invoice['created_at'])); ?>
                                            <div class="text-muted" style="font-size: 10px;"><?php echo date('h:i A', strtotime($invoice['created_at'])); ?></div>
                                        </td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-info" style="font-size: 12px; padding: 3px 8px;" title="View Invoice">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    
                                                    <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size: 12px; padding: 3px 8px;" title="Print Invoice">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                    
                                                    <?php if ($invoice['pending_amount'] > 0): ?>
                                                        <button class="btn btn-sm collect-payment-btn" style="font-size: 12px; padding: 3px 8px;" 
                                                                data-bs-toggle="modal" data-bs-target="#paymentModal<?php echo $invoice['id']; ?>" 
                                                                title="Collect Payment">
                                                            <i class="bi bi-cash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <form method="POST" action="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>" 
                                                          style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this invoice? This will reverse the stock and cannot be undone.')">
                                                        <input type="hidden" name="action" value="delete_invoice">
                                                        <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" 
                                                                title="Delete Invoice">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Payment Collection Modal for Invoice -->
                                    <?php if ($invoice['pending_amount'] > 0): ?>
                                        <div class="modal fade" id="paymentModal<?php echo $invoice['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-lg-custom">
                                                <div class="modal-content">
                                                    <form method="POST" action="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>" id="paymentForm<?php echo $invoice['id']; ?>">
                                                        <input type="hidden" name="action" value="collect_payment">
                                                        <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                        
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">
                                                                <i class="bi bi-cash me-2"></i>
                                                                Collect Payment - <?php echo $invoice['inv_num']; ?>
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Invoice Total</label>
                                                                <input type="text" class="form-control" value="<?php echo formatCurrency($invoice['total']); ?>" readonly disabled>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Already Paid</label>
                                                                <input type="text" class="form-control" value="<?php echo formatCurrency($invoice['cash_received']); ?>" readonly disabled>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Pending Amount</label>
                                                                <input type="text" class="form-control bg-light text-danger fw-bold" 
                                                                       value="<?php echo formatCurrency($invoice['pending_amount']); ?>" readonly disabled>
                                                            </div>
                                                            
                                                            <hr>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                                                <div class="payment-method-selector" id="paymentMethodSelector<?php echo $invoice['id']; ?>">
                                                                    <div class="payment-method-option">
                                                                        <input type="radio" name="payment_method" id="cash<?php echo $invoice['id']; ?>" value="cash" checked>
                                                                        <label for="cash<?php echo $invoice['id']; ?>">
                                                                            <i class="bi bi-cash"></i>
                                                                            <span>Cash</span>
                                                                        </label>
                                                                    </div>
                                                                    
                                                                    <div class="payment-method-option">
                                                                        <input type="radio" name="payment_method" id="card<?php echo $invoice['id']; ?>" value="card">
                                                                        <label for="card<?php echo $invoice['id']; ?>">
                                                                            <i class="bi bi-credit-card"></i>
                                                                            <span>Card</span>
                                                                        </label>
                                                                    </div>
                                                                    
                                                                    <div class="payment-method-option">
                                                                        <input type="radio" name="payment_method" id="upi<?php echo $invoice['id']; ?>" value="upi">
                                                                        <label for="upi<?php echo $invoice['id']; ?>">
                                                                            <i class="bi bi-phone"></i>
                                                                            <span>UPI</span>
                                                                        </label>
                                                                    </div>
                                                                    
                                                                    <div class="payment-method-option">
                                                                        <input type="radio" name="payment_method" id="bank<?php echo $invoice['id']; ?>" value="bank">
                                                                        <label for="bank<?php echo $invoice['id']; ?>">
                                                                            <i class="bi bi-bank"></i>
                                                                            <span>Bank</span>
                                                                        </label>
                                                                    </div>
                                                                    
                                                                    <div class="payment-method-option">
                                                                        <input type="radio" name="payment_method" id="cheque<?php echo $invoice['id']; ?>" value="cheque">
                                                                        <label for="cheque<?php echo $invoice['id']; ?>">
                                                                            <i class="bi bi-journal-check"></i>
                                                                            <span>Cheque</span>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Bank Account Fields -->
                                                            <div class="bank-account-fields" id="bankFields<?php echo $invoice['id']; ?>">
                                                                <div class="field-group">
                                                                    <label>Select Bank Account <span class="text-danger">*</span></label>
                                                                    <select name="bank_account_id" class="form-select">
                                                                        <option value="">Select Bank Account</option>
                                                                        <?php 
                                                                        $bank_accounts->data_seek(0);
                                                                        while ($bank = $bank_accounts->fetch_assoc()): 
                                                                        ?>
                                                                            <option value="<?php echo $bank['id']; ?>" <?php echo ($bank['is_default'] ?? false) ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($bank['account_name'] . ' - ' . $bank['bank_name']); ?>
                                                                                (Balance: <?php echo formatCurrency($bank['current_balance']); ?>)
                                                                            </option>
                                                                        <?php endwhile; ?>
                                                                    </select>
                                                                </div>
                                                                
                                                                <div class="field-group" id="referenceNoField<?php echo $invoice['id']; ?>">
                                                                    <label>Reference Number / UPI ID / Transaction ID</label>
                                                                    <input type="text" name="reference_no" class="form-control" placeholder="Enter reference number, UPI ID, or transaction ID">
                                                                    <div class="reference-hint">
                                                                        <i class="bi bi-info-circle"></i> For UPI: UPI ID or transaction reference<br>
                                                                        For Bank Transfer: Transaction reference number<br>
                                                                        For Card: Last 4 digits or transaction ID
                                                                    </div>
                                                                </div>
                                                                
                                                                <div class="field-group" id="chequeNumberField<?php echo $invoice['id']; ?>" style="display: none;">
                                                                    <label>Cheque Number <span class="text-danger">*</span></label>
                                                                    <input type="text" name="cheque_number" class="form-control" placeholder="Enter cheque number">
                                                                </div>
                                                                
                                                                <div class="field-group" id="chequeDateField<?php echo $invoice['id']; ?>" style="display: none;">
                                                                    <label>Cheque Date</label>
                                                                    <input type="date" name="cheque_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                                                </div>
                                                                
                                                                <div class="field-group" id="chequeBankField<?php echo $invoice['id']; ?>" style="display: none;">
                                                                    <label>Cheque Bank</label>
                                                                    <input type="text" name="cheque_bank" class="form-control" placeholder="Bank name on cheque">
                                                                </div>
                                                                
                                                                <div class="field-group" id="upiRefField<?php echo $invoice['id']; ?>" style="display: none;">
                                                                    <label>UPI Reference Number</label>
                                                                    <input type="text" name="upi_ref_no" class="form-control" placeholder="Enter UPI transaction reference">
                                                                </div>
                                                                
                                                                <div class="field-group" id="transactionRefField<?php echo $invoice['id']; ?>" style="display: none;">
                                                                    <label>Transaction Reference Number</label>
                                                                    <input type="text" name="transaction_ref_no" class="form-control" placeholder="Enter transaction reference number">
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mb-3 mt-3">
                                                                <label class="form-label">Amount to Collect <span class="text-danger">*</span></label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">₹</span>
                                                                    <input type="number" name="paid_amount" class="form-control" 
                                                                           step="0.01" min="0.01" max="<?php echo $invoice['pending_amount']; ?>" 
                                                                           value="<?php echo $invoice['pending_amount']; ?>" required
                                                                           onchange="validatePayment(this, <?php echo $invoice['pending_amount']; ?>)">
                                                                </div>
                                                                <small class="text-muted">Maximum: <?php echo formatCurrency($invoice['pending_amount']); ?></small>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Notes (Optional)</label>
                                                                <textarea name="notes" class="form-control" rows="2" placeholder="Add any additional notes about this payment"></textarea>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success">Collect Payment</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <script>
                                        document.addEventListener('DOMContentLoaded', function() {
                                            const paymentMethodRadios = document.querySelectorAll('#paymentMethodSelector<?php echo $invoice['id']; ?> input[type="radio"]');
                                            const bankFields = document.getElementById('bankFields<?php echo $invoice['id']; ?>');
                                            
                                            paymentMethodRadios.forEach(function(radio) {
                                                radio.addEventListener('change', function() {
                                                    const method = this.value;
                                                    
                                                    if (method === 'bank' || method === 'upi' || method === 'card' || method === 'cheque') {
                                                        bankFields.classList.add('visible');
                                                        
                                                        // Show/hide specific fields based on method
                                                        const chequeNumberField = document.getElementById('chequeNumberField<?php echo $invoice['id']; ?>');
                                                        const chequeDateField = document.getElementById('chequeDateField<?php echo $invoice['id']; ?>');
                                                        const chequeBankField = document.getElementById('chequeBankField<?php echo $invoice['id']; ?>');
                                                        const upiRefField = document.getElementById('upiRefField<?php echo $invoice['id']; ?>');
                                                        const transactionRefField = document.getElementById('transactionRefField<?php echo $invoice['id']; ?>');
                                                        const referenceNoField = document.getElementById('referenceNoField<?php echo $invoice['id']; ?>');
                                                        
                                                        // Hide all method-specific fields first
                                                        if (chequeNumberField) chequeNumberField.style.display = 'none';
                                                        if (chequeDateField) chequeDateField.style.display = 'none';
                                                        if (chequeBankField) chequeBankField.style.display = 'none';
                                                        if (upiRefField) upiRefField.style.display = 'none';
                                                        if (transactionRefField) transactionRefField.style.display = 'none';
                                                        if (referenceNoField) referenceNoField.style.display = 'block';
                                                        
                                                        if (method === 'cheque') {
                                                            if (chequeNumberField) chequeNumberField.style.display = 'block';
                                                            if (chequeDateField) chequeDateField.style.display = 'block';
                                                            if (chequeBankField) chequeBankField.style.display = 'block';
                                                            if (referenceNoField) referenceNoField.style.display = 'none';
                                                        } else if (method === 'upi') {
                                                            if (upiRefField) upiRefField.style.display = 'block';
                                                        } else if (method === 'bank') {
                                                            if (transactionRefField) transactionRefField.style.display = 'block';
                                                        } else if (method === 'card') {
                                                            if (transactionRefField) transactionRefField.style.display = 'block';
                                                        }
                                                    } else {
                                                        bankFields.classList.remove('visible');
                                                    }
                                                });
                                            });
                                        });
                                        </script>
                                    <?php endif; ?>
                                <?php endforeach; ?>
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

<!-- Opening Balance Payment Modal -->
<?php if ($opening_balance > 0 && $is_admin): ?>
    <div class="modal fade" id="openingBalanceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg-custom">
            <div class="modal-content">
                <form method="POST" action="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>">
                    <input type="hidden" name="action" value="collect_opening_balance">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-wallet2 me-2"></i>
                            Collect Opening Balance
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Customer</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($customer['customer_name']); ?>" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Opening Balance (Previous Dues)</label>
                            <input type="text" class="form-control bg-light text-danger fw-bold" 
                                   value="<?php echo formatCurrency($opening_balance); ?>" readonly disabled>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            This is the opening balance set when the customer was created.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <div class="payment-method-selector" id="openingPaymentMethodSelector">
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="opening_cash" value="cash" checked>
                                    <label for="opening_cash">
                                        <i class="bi bi-cash"></i>
                                        <span>Cash</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="opening_card" value="card">
                                    <label for="opening_card">
                                        <i class="bi bi-credit-card"></i>
                                        <span>Card</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="opening_upi" value="upi">
                                    <label for="opening_upi">
                                        <i class="bi bi-phone"></i>
                                        <span>UPI</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="opening_bank" value="bank">
                                    <label for="opening_bank">
                                        <i class="bi bi-bank"></i>
                                        <span>Bank</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="opening_cheque" value="cheque">
                                    <label for="opening_cheque">
                                        <i class="bi bi-journal-check"></i>
                                        <span>Cheque</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Bank Account Fields for Opening Balance -->
                        <div class="bank-account-fields" id="openingBankFields">
                            <div class="field-group">
                                <label>Select Bank Account <span class="text-danger">*</span></label>
                                <select name="bank_account_id" class="form-select">
                                    <option value="">Select Bank Account</option>
                                    <?php 
                                    $bank_accounts->data_seek(0);
                                    while ($bank = $bank_accounts->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $bank['id']; ?>" <?php echo ($bank['is_default'] ?? false) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bank['account_name'] . ' - ' . $bank['bank_name']); ?>
                                            (Balance: <?php echo formatCurrency($bank['current_balance']); ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="field-group" id="openingReferenceNoField">
                                <label>Reference Number / UPI ID / Transaction ID</label>
                                <input type="text" name="reference_no" class="form-control" placeholder="Enter reference number, UPI ID, or transaction ID">
                                <div class="reference-hint">
                                    <i class="bi bi-info-circle"></i> For UPI: UPI ID or transaction reference<br>
                                    For Bank Transfer: Transaction reference number<br>
                                    For Card: Last 4 digits or transaction ID
                                </div>
                            </div>
                            
                            <div class="field-group" id="openingChequeNumberField" style="display: none;">
                                <label>Cheque Number <span class="text-danger">*</span></label>
                                <input type="text" name="cheque_number" class="form-control" placeholder="Enter cheque number">
                            </div>
                            
                            <div class="field-group" id="openingChequeDateField" style="display: none;">
                                <label>Cheque Date</label>
                                <input type="date" name="cheque_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="field-group" id="openingChequeBankField" style="display: none;">
                                <label>Cheque Bank</label>
                                <input type="text" name="cheque_bank" class="form-control" placeholder="Bank name on cheque">
                            </div>
                            
                            <div class="field-group" id="openingUpiRefField" style="display: none;">
                                <label>UPI Reference Number</label>
                                <input type="text" name="upi_ref_no" class="form-control" placeholder="Enter UPI transaction reference">
                            </div>
                            
                            <div class="field-group" id="openingTransactionRefField" style="display: none;">
                                <label>Transaction Reference Number</label>
                                <input type="text" name="transaction_ref_no" class="form-control" placeholder="Enter transaction reference number">
                            </div>
                        </div>
                        
                        <div class="mb-3 mt-3">
                            <label class="form-label">Amount to Collect <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="paid_amount" class="form-control" 
                                       step="0.01" min="0.01" max="<?php echo $opening_balance; ?>" 
                                       value="<?php echo $opening_balance; ?>" required
                                       onchange="validatePayment(this, <?php echo $opening_balance; ?>)">
                            </div>
                            <small class="text-muted">Maximum: <?php echo formatCurrency($opening_balance); ?></small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes about this payment"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-cash me-2"></i>
                            Collect Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const openingRadios = document.querySelectorAll('#openingPaymentMethodSelector input[type="radio"]');
        const openingBankFields = document.getElementById('openingBankFields');
        
        openingRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                const method = this.value;
                
                if (method === 'bank' || method === 'upi' || method === 'card' || method === 'cheque') {
                    openingBankFields.classList.add('visible');
                    
                    const chequeNumberField = document.getElementById('openingChequeNumberField');
                    const chequeDateField = document.getElementById('openingChequeDateField');
                    const chequeBankField = document.getElementById('openingChequeBankField');
                    const upiRefField = document.getElementById('openingUpiRefField');
                    const transactionRefField = document.getElementById('openingTransactionRefField');
                    const referenceNoField = document.getElementById('openingReferenceNoField');
                    
                    if (chequeNumberField) chequeNumberField.style.display = 'none';
                    if (chequeDateField) chequeDateField.style.display = 'none';
                    if (chequeBankField) chequeBankField.style.display = 'none';
                    if (upiRefField) upiRefField.style.display = 'none';
                    if (transactionRefField) transactionRefField.style.display = 'none';
                    if (referenceNoField) referenceNoField.style.display = 'block';
                    
                    if (method === 'cheque') {
                        if (chequeNumberField) chequeNumberField.style.display = 'block';
                        if (chequeDateField) chequeDateField.style.display = 'block';
                        if (chequeBankField) chequeBankField.style.display = 'block';
                        if (referenceNoField) referenceNoField.style.display = 'none';
                    } else if (method === 'upi') {
                        if (upiRefField) upiRefField.style.display = 'block';
                    } else if (method === 'bank') {
                        if (transactionRefField) transactionRefField.style.display = 'block';
                    } else if (method === 'card') {
                        if (transactionRefField) transactionRefField.style.display = 'block';
                    }
                } else {
                    openingBankFields.classList.remove('visible');
                }
            });
        });
    });
    </script>
<?php endif; ?>

<!-- Overall Payment Modal -->
<?php if ($grand_total_pending > 0 && $is_admin): ?>
    <div class="modal fade" id="overallPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg-custom">
            <div class="modal-content">
                <form method="POST" action="customer_payment_history.php?customer_id=<?php echo $customer_id; ?>">
                    <input type="hidden" name="action" value="collect_overall_pending">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-cash-stack me-2"></i>
                            Collect Overall Pending
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Customer</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($customer['customer_name']); ?>" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Total Pending Amount</label>
                            <input type="text" class="form-control bg-light text-danger fw-bold" 
                                   value="<?php echo formatCurrency($grand_total_pending); ?>" readonly disabled>
                        </div>
                        
                        <?php if ($opening_balance > 0 && $total_pending > 0): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Breakdown:</strong><br>
                                • Opening Balance: <?php echo formatCurrency($opening_balance); ?><br>
                                • Invoice Pending: <?php echo formatCurrency($total_pending); ?>
                            </div>
                        <?php elseif ($opening_balance > 0): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Only opening balance pending: <?php echo formatCurrency($opening_balance); ?>
                            </div>
                        <?php elseif ($total_pending > 0): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Only invoice pending: <?php echo formatCurrency($total_pending); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            This will clear all pending amounts including opening balance and all pending invoices.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <div class="payment-method-selector" id="overallPaymentMethodSelector">
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="overall_cash" value="cash" checked>
                                    <label for="overall_cash">
                                        <i class="bi bi-cash"></i>
                                        <span>Cash</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="overall_card" value="card">
                                    <label for="overall_card">
                                        <i class="bi bi-credit-card"></i>
                                        <span>Card</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="overall_upi" value="upi">
                                    <label for="overall_upi">
                                        <i class="bi bi-phone"></i>
                                        <span>UPI</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="overall_bank" value="bank">
                                    <label for="overall_bank">
                                        <i class="bi bi-bank"></i>
                                        <span>Bank</span>
                                    </label>
                                </div>
                                
                                <div class="payment-method-option">
                                    <input type="radio" name="payment_method" id="overall_cheque" value="cheque">
                                    <label for="overall_cheque">
                                        <i class="bi bi-journal-check"></i>
                                        <span>Cheque</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Bank Account Fields for Overall Payment -->
                        <div class="bank-account-fields" id="overallBankFields">
                            <div class="field-group">
                                <label>Select Bank Account <span class="text-danger">*</span></label>
                                <select name="bank_account_id" class="form-select">
                                    <option value="">Select Bank Account</option>
                                    <?php 
                                    $bank_accounts->data_seek(0);
                                    while ($bank = $bank_accounts->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $bank['id']; ?>" <?php echo ($bank['is_default'] ?? false) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bank['account_name'] . ' - ' . $bank['bank_name']); ?>
                                            (Balance: <?php echo formatCurrency($bank['current_balance']); ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="field-group" id="overallReferenceNoField">
                                <label>Reference Number / UPI ID / Transaction ID</label>
                                <input type="text" name="reference_no" class="form-control" placeholder="Enter reference number, UPI ID, or transaction ID">
                                <div class="reference-hint">
                                    <i class="bi bi-info-circle"></i> For UPI: UPI ID or transaction reference<br>
                                    For Bank Transfer: Transaction reference number<br>
                                    For Card: Last 4 digits or transaction ID
                                </div>
                            </div>
                            
                            <div class="field-group" id="overallChequeNumberField" style="display: none;">
                                <label>Cheque Number <span class="text-danger">*</span></label>
                                <input type="text" name="cheque_number" class="form-control" placeholder="Enter cheque number">
                            </div>
                            
                            <div class="field-group" id="overallChequeDateField" style="display: none;">
                                <label>Cheque Date</label>
                                <input type="date" name="cheque_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="field-group" id="overallChequeBankField" style="display: none;">
                                <label>Cheque Bank</label>
                                <input type="text" name="cheque_bank" class="form-control" placeholder="Bank name on cheque">
                            </div>
                            
                            <div class="field-group" id="overallUpiRefField" style="display: none;">
                                <label>UPI Reference Number</label>
                                <input type="text" name="upi_ref_no" class="form-control" placeholder="Enter UPI transaction reference">
                            </div>
                            
                            <div class="field-group" id="overallTransactionRefField" style="display: none;">
                                <label>Transaction Reference Number</label>
                                <input type="text" name="transaction_ref_no" class="form-control" placeholder="Enter transaction reference number">
                            </div>
                        </div>
                        
                        <div class="mb-3 mt-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes about this payment"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to collect all pending payments? This will clear all dues including opening balance and all pending invoices.')">
                            <i class="bi bi-check-circle me-2"></i>
                            Collect ₹<?php echo number_format($grand_total_pending, 2); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const overallRadios = document.querySelectorAll('#overallPaymentMethodSelector input[type="radio"]');
        const overallBankFields = document.getElementById('overallBankFields');
        
        overallRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                const method = this.value;
                
                if (method === 'bank' || method === 'upi' || method === 'card' || method === 'cheque') {
                    overallBankFields.classList.add('visible');
                    
                    const chequeNumberField = document.getElementById('overallChequeNumberField');
                    const chequeDateField = document.getElementById('overallChequeDateField');
                    const chequeBankField = document.getElementById('overallChequeBankField');
                    const upiRefField = document.getElementById('overallUpiRefField');
                    const transactionRefField = document.getElementById('overallTransactionRefField');
                    const referenceNoField = document.getElementById('overallReferenceNoField');
                    
                    if (chequeNumberField) chequeNumberField.style.display = 'none';
                    if (chequeDateField) chequeDateField.style.display = 'none';
                    if (chequeBankField) chequeBankField.style.display = 'none';
                    if (upiRefField) upiRefField.style.display = 'none';
                    if (transactionRefField) transactionRefField.style.display = 'none';
                    if (referenceNoField) referenceNoField.style.display = 'block';
                    
                    if (method === 'cheque') {
                        if (chequeNumberField) chequeNumberField.style.display = 'block';
                        if (chequeDateField) chequeDateField.style.display = 'block';
                        if (chequeBankField) chequeBankField.style.display = 'block';
                        if (referenceNoField) referenceNoField.style.display = 'none';
                    } else if (method === 'upi') {
                        if (upiRefField) upiRefField.style.display = 'block';
                    } else if (method === 'bank') {
                        if (transactionRefField) transactionRefField.style.display = 'block';
                    } else if (method === 'card') {
                        if (transactionRefField) transactionRefField.style.display = 'block';
                    }
                } else {
                    overallBankFields.classList.remove('visible');
                }
            });
        });
    });
    </script>
<?php endif; ?>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize payment history table
    $('#paymentHistoryTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search invoices:",
            lengthMenu: "Show _MENU_ invoices",
            info: "Showing _START_ to _END_ of _TOTAL_ invoices",
            emptyTable: "No invoices available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: -1 }
            <?php endif; ?>
        ],
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                title: 'Payment_History_<?php echo $customer['customer_name']; ?>',
                className: 'btn btn-sm btn-outline-success',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 8],
                    format: {
                        body: function(data, row, column, node) {
                            if (column === 3 || column === 4 || column === 5) {
                                return data.replace(/[₹,]/g, '');
                            }
                            return data;
                        }
                    }
                }
            },
            {
                extend: 'csvHtml5',
                text: '<i class="bi bi-file-earmark-spreadsheet"></i> CSV',
                title: 'Payment_History_<?php echo $customer['customer_name']; ?>',
                className: 'btn btn-sm btn-outline-primary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 8],
                    format: {
                        body: function(data, row, column, node) {
                            if (column === 3 || column === 4 || column === 5) {
                                return data.replace(/[₹,]/g, '');
                            }
                            return data;
                        }
                    }
                }
            }
        ]
    });
    
    // Initialize opening payments table
    <?php if (!empty($opening_payments_list)): ?>
    $('#openingPaymentsTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search opening balance payments:",
            lengthMenu: "Show _MENU_ payments",
            info: "Showing _START_ to _END_ of _TOTAL_ payments",
            emptyTable: "No opening balance payments found"
        },
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                title: 'Opening_Balance_Payments_<?php echo $customer['customer_name']; ?>',
                className: 'btn btn-sm btn-outline-success',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4],
                    format: {
                        body: function(data, row, column, node) {
                            if (column === 1) {
                                return data.replace(/[₹,]/g, '');
                            }
                            return data;
                        }
                    }
                }
            },
            {
                extend: 'csvHtml5',
                text: '<i class="bi bi-file-earmark-spreadsheet"></i> CSV',
                title: 'Opening_Balance_Payments_<?php echo $customer['customer_name']; ?>',
                className: 'btn btn-sm btn-outline-primary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4],
                    format: {
                        body: function(data, row, column, node) {
                            if (column === 1) {
                                return data.replace(/[₹,]/g, '');
                            }
                            return data;
                        }
                    }
                }
            }
        ]
    });
    <?php endif; ?>
});

// Validate payment amount
function validatePayment(input, maxAmount) {
    let value = parseFloat(input.value) || 0;
    if (value > maxAmount) {
        input.value = maxAmount;
        alert('Amount cannot exceed pending amount: ₹' + maxAmount.toFixed(2));
    }
    if (value < 0.01) {
        input.value = 0.01;
    }
}
</script>
</body>
</html>