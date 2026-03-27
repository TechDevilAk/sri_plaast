<?php
// new-sale.php
session_start();
$currentPage = 'new-sale';
$pageTitle   = 'New Sale / Quotation';
require_once 'includes/db.php';
require_once 'auth_check.php';

checkRoleAccess(['admin', 'sale']);
header_remove("X-Powered-By");

// --------------------------
// Helpers
// --------------------------
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function money2($n) {
    return number_format((float)$n, 2, '.', '');
}

function escape($conn, $str) {
    return mysqli_real_escape_string($conn, $str);
}

// --------------------------
// Get current user ID safely
// --------------------------
function getCurrentUserId($conn) {
    // Check if user_id is set in session
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return 0;
    }
    
    $user_id = (int)$_SESSION['user_id'];
    
    // Verify user exists in database
    $result = mysqli_query($conn, "SELECT id FROM users WHERE id = $user_id LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        return $user_id;
    }
    
    return 0;
}

// --------------------------
// Safe activity log insert
// --------------------------
function insertActivityLog($conn, $action, $description) {
    $user_id = getCurrentUserId($conn);
    
    $action = escape($conn, $action);
    $description = escape($conn, $description);
    
    if ($user_id > 0) {
        // Insert with valid user_id
        mysqli_query($conn, "INSERT INTO activity_log (user_id, action, description) VALUES ($user_id, '$action', '$description')");
    } else {
        // Insert without user_id (allows NULL)
        mysqli_query($conn, "INSERT INTO activity_log (user_id, action, description) VALUES (NULL, '$action', '$description')");
    }
}

// --------------------------
// Get last used bank account for user
// --------------------------
function getLastUsedBankAccount($conn, $user_id) {
    if ($user_id <= 0) {
        // Return default account if no user
        $result = mysqli_query($conn, "SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, id DESC LIMIT 1");
        return mysqli_fetch_assoc($result);
    }
    
    // First try to get from user preference in session or database
    // For simplicity, we'll check if there's a cookie
    if (isset($_COOKIE['last_bank_account'])) {
        $account_id = (int)$_COOKIE['last_bank_account'];
        $result = mysqli_query($conn, "SELECT * FROM bank_accounts WHERE id = $account_id AND status = 1");
        if ($row = mysqli_fetch_assoc($result)) {
            return $row;
        }
    }
    
    // Otherwise return default account
    $result = mysqli_query($conn, "SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, id DESC LIMIT 1");
    return mysqli_fetch_assoc($result);
}

// --------------------------
// Save bank transaction
// --------------------------
function saveBankTransaction($conn, $data) {
    $bank_account_id = (int)$data['bank_account_id'];
    $transaction_date = $data['transaction_date'];
    $transaction_type = $data['transaction_type'];
    $reference_type = $data['reference_type'];
    $reference_id = (int)$data['reference_id'];
    $reference_number = escape($conn, $data['reference_number']);
    $party_name = escape($conn, $data['party_name']);
    $party_type = $data['party_type'];
    $description = escape($conn, $data['description']);
    $amount = (float)$data['amount'];
    $payment_method = $data['payment_method'];
    $status = 'completed';
    $cheque_number = isset($data['cheque_number']) ? escape($conn, $data['cheque_number']) : '';
    $cheque_date = isset($data['cheque_date']) && !empty($data['cheque_date']) ? "'" . escape($conn, $data['cheque_date']) . "'" : 'NULL';
    $cheque_bank = isset($data['cheque_bank']) ? escape($conn, $data['cheque_bank']) : '';
    $upi_ref_no = isset($data['upi_ref_no']) ? escape($conn, $data['upi_ref_no']) : '';
    $transaction_ref_no = isset($data['transaction_ref_no']) ? escape($conn, $data['transaction_ref_no']) : '';
    $notes = isset($data['notes']) ? escape($conn, $data['notes']) : '';
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
        
        // Update bank account balance
        $balance_query = "SELECT current_balance FROM bank_accounts WHERE id = $bank_account_id";
        $balance_result = mysqli_query($conn, $balance_query);
        $current_balance = mysqli_fetch_assoc($balance_result)['current_balance'];
        
        // For money coming in (sale, in, sale_credit)
        $new_balance = $current_balance + $amount;
        
        $update_balance = "UPDATE bank_accounts SET current_balance = $new_balance WHERE id = $bank_account_id";
        mysqli_query($conn, $update_balance);
        
        return $transaction_id;
    }
    
    return false;
}

// --------------------------
// Invoice number generator
// --------------------------
function generateUniqueInvoiceNumber($conn, $is_gst = 1) {
    $prefix = $is_gst ? 'SP' : 'E';

    // Use transactions to prevent race conditions
    mysqli_begin_transaction($conn);
    
    try {
        $result = mysqli_query($conn, "SELECT id, counter_value FROM invoice_counter WHERE prefix = '$prefix' LIMIT 1 FOR UPDATE");
        $row = mysqli_fetch_assoc($result);

        if (!$row) {
            mysqli_query($conn, "INSERT INTO invoice_counter (prefix, counter_value) VALUES ('$prefix', 1)");
            $counterId = mysqli_insert_id($conn);
            $counterValue = 1;
        } else {
            $counterId = (int)$row['id'];
            $counterValue = (int)$row['counter_value'];
        }

        $max_attempts = 100;
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $num = $counterValue + $attempt;
            $inv_num = $prefix . str_pad((string)$num, 4, "0", STR_PAD_LEFT);

            $chk = mysqli_query($conn, "SELECT id FROM invoice WHERE inv_num = '$inv_num' LIMIT 1");
            if (mysqli_num_rows($chk) === 0) {
                $next = $num + 1;
                mysqli_query($conn, "UPDATE invoice_counter SET counter_value = $next WHERE id = $counterId");
                mysqli_commit($conn);
                return $inv_num;
            }
        }
        
        mysqli_rollback($conn);
        return $prefix . date('YmdHis');
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return $prefix . date('YmdHis');
    }
}

// --------------------------
// Quotation number generator
// --------------------------
function generateUniqueQuotationNumber($conn) {
    $prefix = 'Q';

    mysqli_begin_transaction($conn);
    
    try {
        $result = mysqli_query($conn, "SELECT id, counter_value FROM quotation_counter WHERE prefix = '$prefix' LIMIT 1 FOR UPDATE");
        $row = mysqli_fetch_assoc($result);

        if (!$row) {
            mysqli_query($conn, "INSERT INTO quotation_counter (prefix, counter_value) VALUES ('$prefix', 1)");
            $counterId = mysqli_insert_id($conn);
            $counterValue = 1;
        } else {
            $counterId = (int)$row['id'];
            $counterValue = (int)$row['counter_value'];
        }

        $max_attempts = 100;
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $num = $counterValue + $attempt;
            $quote_num = $prefix . str_pad((string)$num, 5, "0", STR_PAD_LEFT);

            $chk = mysqli_query($conn, "SELECT id FROM quotation WHERE quote_num = '$quote_num' LIMIT 1");
            if (mysqli_num_rows($chk) === 0) {
                $next = $num + 1;
                mysqli_query($conn, "UPDATE quotation_counter SET counter_value = $next WHERE id = $counterId");
                mysqli_commit($conn);
                return $quote_num;
            }
        }

        mysqli_rollback($conn);
        return $prefix . date('YmdHis');
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return $prefix . date('YmdHis');
    }
}

// --------------------------
// Check if editing existing invoice
// --------------------------
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_invoice = null;
$edit_items = [];

if ($edit_id > 0) {
    $inv_query = mysqli_query($conn, "SELECT * FROM invoice WHERE id = $edit_id");
    $edit_invoice = mysqli_fetch_assoc($inv_query);

    if ($edit_invoice) {
        $items_query = mysqli_query($conn, "SELECT * FROM invoice_item WHERE invoice_id = $edit_id");
        while ($row = mysqli_fetch_assoc($items_query)) {
            $edit_items[] = $row;
        }

        // Load invoice items into session cart once
        if (!isset($_SESSION['edit_cart_loaded']) || (int)$_SESSION['edit_cart_loaded'] !== $edit_id) {
            $cart_items = [];
            foreach ($edit_items as $item) {
                $qty_db  = (float)($item['quantity'] ?? 0);
                $pcs_db  = (float)($item['no_of_pcs'] ?? 0);
                $rate_db = (float)($item['selling_price'] ?? 0);

                $cart_items[] = [
                    'product_id'     => (int)($item['product_id'] ?? 0),
                    'product_name'   => (string)($item['product_name'] ?? ''),
                    'cat_id'         => (int)($item['cat_id'] ?? 0),
                    'cat_name'       => (string)($item['cat_name'] ?? ''),
                    'unit'           => (string)($item['unit'] ?? ''),
                    'qty'            => $qty_db,
                    'pcs_per_bag'    => 0,
                    'converted_qty'  => $pcs_db > 0 ? $pcs_db : $qty_db,
                    'no_of_pcs'      => $pcs_db > 0 ? $pcs_db : $qty_db,
                    'rate'           => $rate_db,
                    'total'          => (float)($item['total'] ?? 0),
                    'taxable'        => (float)($item['taxable'] ?? 0),
                    'hsn_code'       => (string)($item['hsn'] ?? ''),
                    'cgst'           => (float)($item['cgst'] ?? 0),
                    'sgst'           => (float)($item['sgst'] ?? 0),
                    'cgst_amt'       => (float)($item['cgst_amount'] ?? 0),
                    'sgst_amt'       => (float)($item['sgst_amount'] ?? 0),
                    'is_category_sale' => false
                ];
            }
            $_SESSION['sale_cart'] = $cart_items;
            $_SESSION['edit_cart_loaded'] = $edit_id;
        }
    }
}

// Initialize session cart if not exists
if (!isset($_SESSION['sale_cart'])) {
    $_SESSION['sale_cart'] = [];
}

// Clear cart if requested
if (isset($_GET['clear_cart'])) {
    $_SESSION['sale_cart'] = [];
    unset($_SESSION['edit_cart_loaded']);
    header("Location: new-sale.php");
    exit;
}

// Get last used bank account for current user
$current_user_id = getCurrentUserId($conn);
$last_bank_account = getLastUsedBankAccount($conn, $current_user_id);

// Get all active bank accounts for dropdown
$bank_accounts_query = "SELECT * FROM bank_accounts WHERE status = 1 ORDER BY is_default DESC, account_name ASC";
$bank_accounts = mysqli_query($conn, $bank_accounts_query);

// Get all categories for bulk sale
$categories_query = "SELECT id, category_name, purchase_price, total_quantity, gram_value 
                     FROM category 
                     WHERE total_quantity > 0 
                     ORDER BY category_name ASC";
$categories = mysqli_query($conn, $categories_query);

// Get a default product for category sales (to satisfy foreign key constraint)
$default_product_query = "SELECT id, product_name FROM product LIMIT 1";
$default_product_result = mysqli_query($conn, $default_product_query);
$default_product = mysqli_fetch_assoc($default_product_result);
$default_product_id = $default_product ? $default_product['id'] : 0;
$default_product_name = $default_product ? $default_product['product_name'] : 'Category Sale';

// --------------------------
// AJAX endpoints
// --------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] !== '') {
    $ajax = $_GET['ajax'];

    if ($ajax === 'customers') {
        $term = escape($conn, trim($_GET['term'] ?? ''));

        $res = mysqli_query($conn, "
            SELECT id, customer_name, phone, gst_number
            FROM customers
            WHERE customer_name LIKE '%$term%' OR phone LIKE '%$term%' OR gst_number LIKE '%$term%'
            ORDER BY customer_name ASC
            LIMIT 50
        ");

        $items = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $label = $row['customer_name'];
            if (!empty($row['phone'])) $label .= " • " . $row['phone'];
            if (!empty($row['gst_number'])) $label .= " • " . $row['gst_number'];
            $items[] = ["id" => (int)$row['id'], "text" => $label];
        }
        json_response(["results" => $items]);
    }

    if ($ajax === 'customer_details') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_response(["ok" => false, "message" => "Invalid customer id"], 400);

        $res = mysqli_query($conn, "SELECT * FROM customers WHERE id = $id LIMIT 1");
        $row = mysqli_fetch_assoc($res);

        if (!$row) json_response(["ok" => false, "message" => "Customer not found"], 404);
        json_response(["ok" => true, "customer" => $row]);
    }

    if ($ajax === 'products') {
        $term = escape($conn, trim($_GET['term'] ?? ''));

        $res = mysqli_query($conn, "
            SELECT p.id, p.product_name, p.hsn_code, p.primary_qty, p.primary_unit, p.sec_qty, p.sec_unit,
                   COALESCE(g.cgst,0) AS cgst, COALESCE(g.sgst,0) AS sgst, COALESCE(g.igst,0) AS igst
            FROM product p
            LEFT JOIN gst g ON p.hsn_code = g.hsn AND g.status = 1
            WHERE p.product_name LIKE '%$term%' OR p.hsn_code LIKE '%$term%'
            ORDER BY p.product_name ASC
            LIMIT 50
        ");

        $items = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $label = $row['product_name'];
            if (!empty($row['hsn_code'])) $label .= " • HSN " . $row['hsn_code'];

            $items[] = [
                "id" => (int)$row['id'],
                "text" => $label,
                "meta" => [
                    "product_name" => (string)$row['product_name'],
                    "hsn_code"     => (string)$row['hsn_code'],
                    "primary_qty"  => (float)$row['primary_qty'],
                    "primary_unit" => (string)$row['primary_unit'],
                    "sec_qty"      => (float)$row['sec_qty'],
                    "sec_unit"     => (string)$row['sec_unit'],
                    "cgst"         => (float)$row['cgst'],
                    "sgst"         => (float)$row['sgst'],
                    "igst"         => (float)$row['igst'],
                ]
            ];
        }
        json_response(["results" => $items]);
    }

    if ($ajax === 'categories') {
        $term = escape($conn, trim($_GET['term'] ?? ''));

        $res = mysqli_query($conn, "
            SELECT id, category_name, purchase_price, total_quantity, gram_value
            FROM category
            WHERE category_name LIKE '%$term%'
            ORDER BY category_name ASC
            LIMIT 50
        ");

        $items = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $label = $row['category_name'];
            $label .= " • Rate ₹" . money2($row['purchase_price']);
            $label .= " • Stock: " . number_format((float)$row['total_quantity'], 2) . " pcs";
            if ($row['gram_value'] > 0) {
                $label .= " • " . number_format($row['gram_value'], 3) . "g per pcs";
            }

            $items[] = [
                "id" => (int)$row['id'],
                "text" => $label,
                "meta" => [
                    "category_name"   => (string)$row['category_name'],
                    "default_rate"    => (float)$row['purchase_price'],
                    "available_stock" => (float)$row['total_quantity'],
                    "gram_value"      => (float)$row['gram_value']
                ]
            ];
        }
        json_response(["results" => $items]);
    }

    json_response(["ok" => false, "message" => "Unknown ajax endpoint"], 404);
}

// --------------------------
// Handle Submit Sale
// --------------------------
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Get current user ID (don't show error if not found)
    $current_user_id = getCurrentUserId($conn);
    
    // If user_id is 0, we'll still proceed but log without user_id

    // ==========================
    // CREATE QUOTATION
    // ==========================
    if (($_POST['action'] ?? '') === 'create_quotation') {
        $is_gst = (int)($_POST['is_gst'] ?? 1);
        $is_gst = ($is_gst === 1) ? 1 : 0;

        // Customer
        $customer_mode = $_POST['customer_mode'] ?? 'existing';
        $customer_id   = (int)($_POST['customer_id'] ?? 0);

        $customer_name = escape($conn, trim($_POST['customer_name'] ?? ''));
        $phone         = escape($conn, trim($_POST['phone'] ?? ''));
        $email         = escape($conn, trim($_POST['email'] ?? ''));
        $address       = escape($conn, trim($_POST['address'] ?? ''));
        $gst_number    = escape($conn, trim($_POST['gst_number'] ?? ''));

        // Items from session
        $items = $_SESSION['sale_cart'] ?? [];

        $valid_until = escape($conn, $_POST['valid_until'] ?? date('Y-m-d', strtotime('+30 days')));
        $notes = escape($conn, trim($_POST['quotation_notes'] ?? ''));

        // Overall discount
        $overall_discount = (float)($_POST['overall_discount'] ?? 0);
        $overall_discount_type = $_POST['overall_discount_type'] ?? 'amount';
        if (!in_array($overall_discount_type, ['amount','percentage'], true)) $overall_discount_type = 'amount';

        if (!is_array($items) || count($items) === 0) $error = "Please add at least one item.";

        // Resolve customer
        $final_customer_id = 'NULL';
        $final_customer_name = 'NULL';

        if ($error === '') {
            if ($customer_mode === 'existing') {
                if ($customer_id <= 0) {
                    $error = "Please select an existing customer.";
                } else {
                    $cust_res = mysqli_query($conn, "SELECT id, customer_name FROM customers WHERE id = $customer_id LIMIT 1");
                    $cust = mysqli_fetch_assoc($cust_res);
                    if (!$cust) {
                        $error = "Selected customer not found.";
                    } else {
                        $final_customer_id = (int)$cust['id'];
                        $final_customer_name = "'" . escape($conn, $cust['customer_name']) . "'";
                    }
                }
            } else {
                if ($customer_name === '') {
                    $error = "Customer name is required (manual entry).";
                } else {
                    $insert = mysqli_query($conn, "
                        INSERT INTO customers (customer_name, phone, email, address, gst_number)
                        VALUES ('$customer_name', '$phone', '$email', '$address', '$gst_number')
                    ");
                    if (!$insert) {
                        $error = "Failed to create customer: " . mysqli_error($conn);
                    } else {
                        $final_customer_id = mysqli_insert_id($conn);
                        $final_customer_name = "'$customer_name'";

                        $log_desc = "Added new customer: {$customer_name}" . ($phone ? " (Phone: {$phone})" : "");
                        insertActivityLog($conn, 'create', $log_desc);
                    }
                }
            }
        }

        if ($error === '') {
            $quote_num = generateUniqueQuotationNumber($conn);

            $subtotal = 0.0;
            $taxable_total = 0.0;
            $cgst_amount_total = 0.0;
            $sgst_amount_total = 0.0;

            foreach ($items as $it) {
                $subtotal += (float)($it['taxable'] ?? 0);
                $taxable_total += (float)($it['taxable'] ?? 0);
                $cgst_amount_total += (float)($it['cgst_amt'] ?? 0);
                $sgst_amount_total += (float)($it['sgst_amt'] ?? 0);
            }

            if (count($items) === 0) {
                $error = "No valid items to save.";
            } else {
                $overall_disc_amt = 0.0;
                if ($overall_discount > 0) {
                    $overall_disc_amt = ($overall_discount_type === 'percentage')
                        ? ($subtotal * $overall_discount) / 100.0
                        : $overall_discount;
                }
                if ($overall_disc_amt > $subtotal) $overall_disc_amt = $subtotal;

                $net_after_overall = $subtotal - $overall_disc_amt;
                $factor = ($subtotal > 0) ? ($net_after_overall / $subtotal) : 1.0;

                $taxable_total = $taxable_total * $factor;
                $cgst_amount_total = $cgst_amount_total * $factor;
                $sgst_amount_total = $sgst_amount_total * $factor;
                $grand_total = $taxable_total + $cgst_amount_total + $sgst_amount_total;

                mysqli_begin_transaction($conn);

                try {
                    $created_by = ($current_user_id > 0) ? $current_user_id : 'NULL';
                    
                    $insert_quote = mysqli_query($conn, "
                        INSERT INTO quotation
                        (
                            quote_num, customer_id, customer_name, subtotal, overall_discount,
                            overall_discount_type, total, taxable, cgst, cgst_amount, sgst, sgst_amount,
                            is_gst, valid_until, notes, status, created_by
                        )
                        VALUES
                        (
                            '$quote_num', $final_customer_id, $final_customer_name, $subtotal, $overall_disc_amt,
                            '$overall_discount_type', $grand_total, $taxable_total, 0, $cgst_amount_total, 0, $sgst_amount_total,
                            $is_gst, '$valid_until', '$notes', 'draft', $created_by
                        )
                    ");
                    if (!$insert_quote) throw new Exception("Failed to save quotation: " . mysqli_error($conn));

                    $quotation_id = mysqli_insert_id($conn);

                    foreach ($items as $li) {
                        // For category sales, use default product_id to satisfy foreign key
                        $product_id = (int)$li['product_id'];
                        if ($product_id <= 0) {
                            $product_id = $default_product_id;
                        }
                        
                        $product_name = escape($conn, (string)$li['product_name']);
                        $cat_id = (int)$li['cat_id'];
                        $cat_name = escape($conn, (string)$li['cat_name']);
                        $qty = (float)$li['qty'];
                        $unit = escape($conn, (string)$li['unit']);
                        $converted_qty = (float)$li['converted_qty'];
                        $no_of_pcs = (float)$li['converted_qty'];
                        $pcs_per_bag = (float)($li['pcs_per_bag'] ?? 0);
                        $sell_price = (float)$li['rate'];

                        $total_i = (float)($li['total'] ?? 0) * $factor;
                        $hsn_i = escape($conn, (string)($li['hsn_code'] ?? ''));
                        $taxable_i = (float)($li['taxable'] ?? 0) * $factor;
                        $cgst_i = (float)($li['cgst'] ?? 0);
                        $cgst_amt_i = (float)($li['cgst_amt'] ?? 0) * $factor;
                        $sgst_i = (float)($li['sgst'] ?? 0);
                        $sgst_amt_i = (float)($li['sgst_amt'] ?? 0) * $factor;

                        $insert_item = mysqli_query($conn, "
                            INSERT INTO quotation_item
                            (
                                quotation_id, product_id, product_name, cat_id, cat_name,
                                quantity, unit, pcs_per_bag, converted_qty, selling_price, total, hsn,
                                taxable, cgst, cgst_amount, sgst, sgst_amount
                            )
                            VALUES
                            (
                                $quotation_id, $product_id, '$product_name', $cat_id, '$cat_name',
                                $qty, '$unit', $pcs_per_bag, $converted_qty, $sell_price, $total_i, '$hsn_i',
                                $taxable_i, $cgst_i, $cgst_amt_i, $sgst_i, $sgst_amt_i
                            )
                        ");
                        if (!$insert_item) throw new Exception("Failed to save quotation item: " . mysqli_error($conn));
                    }

                    $log_desc = "Created quotation {$quote_num} (Total: ₹" . money2($grand_total) . ")";
                    insertActivityLog($conn, 'create', $log_desc);

                    mysqli_commit($conn);

                    $_SESSION['sale_cart'] = [];
                    unset($_SESSION['edit_cart_loaded']);

                    if (($_POST['action_type'] ?? '') === 'print_quote') {
                        header("Location: print_quotation.php?id=" . $quotation_id);
                    } else {
                        header("Location: quotations.php");
                    }
                    exit;

                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = $e->getMessage();
                }
            }
        }
    }

    // ==========================
    // CREATE / UPDATE INVOICE
    // ==========================
    if (($_POST['action'] ?? '') === 'create_invoice') {
        $is_gst = (int)($_POST['is_gst'] ?? 1);
        $is_gst = ($is_gst === 1) ? 1 : 0;

        $customer_mode = $_POST['customer_mode'] ?? 'existing';
        $customer_id   = (int)($_POST['customer_id'] ?? 0);

        $customer_name = escape($conn, trim($_POST['customer_name'] ?? ''));
        $phone         = escape($conn, trim($_POST['phone'] ?? ''));
        $email         = escape($conn, trim($_POST['email'] ?? ''));
        $address       = escape($conn, trim($_POST['address'] ?? ''));
        $gst_number    = escape($conn, trim($_POST['gst_number'] ?? ''));

        // Items from session
        $items = $_SESSION['sale_cart'] ?? [];

        $overall_discount = (float)($_POST['overall_discount'] ?? 0);
        $overall_discount_type = $_POST['overall_discount_type'] ?? 'amount';
        if (!in_array($overall_discount_type, ['amount','percentage'], true)) $overall_discount_type = 'amount';

        $cash_amount   = (float)($_POST['cash_amount'] ?? 0);
        $upi_amount    = (float)($_POST['upi_amount'] ?? 0);
        $card_amount   = (float)($_POST['card_amount'] ?? 0);
        $bank_amount   = (float)($_POST['bank_amount'] ?? 0);
        $cheque_amount = (float)($_POST['cheque_amount'] ?? 0);
        $credit_amount = (float)($_POST['credit_amount'] ?? 0);

        $cheque_number = escape($conn, trim($_POST['cheque_number'] ?? ''));
        $cheque_date   = escape($conn, trim($_POST['cheque_date'] ?? ''));
        $cheque_bank   = escape($conn, trim($_POST['cheque_bank'] ?? ''));
        $credit_due_date = escape($conn, trim($_POST['credit_due_date'] ?? ''));
        $credit_notes = escape($conn, trim($_POST['credit_notes'] ?? ''));

        // Bank account selection
        $bank_account_id = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;
        
        // UPI Reference Number (optional)
        $upi_ref_no = escape($conn, trim($_POST['upi_ref_no'] ?? ''));
        
        // Transaction Reference Number (optional)
        $transaction_ref_no = escape($conn, trim($_POST['transaction_ref_no'] ?? ''));

        // E-Way Bill Number
        $e_way_bill = escape($conn, trim($_POST['e_way_bill'] ?? ''));

        // Shipping fields - auto-fetched from customer table
        $shipping_address = escape($conn, trim($_POST['shipping_address'] ?? ''));
        $shipping_charges = 0; // Set to 0 - no manual shipping charges
        $shipping_method = ''; // Set to empty - no manual shipping method
        $delivery_date = ''; // Not set - removed from form
        $delivery_time = ''; // Not set - removed from form
        $tracking_number = ''; // Not set - removed from form
        $shipping_cgst = 0; // Set to 0 - no shipping GST
        $shipping_sgst = 0; // Set to 0 - no shipping GST
        $shipping_cgst_amount = 0; // Set to 0
        $shipping_sgst_amount = 0; // Set to 0
        $shipping_total = 0; // Set to 0
        
        // New fields for invoice
        $dispatch_through = escape($conn, trim($_POST['dispatch_through'] ?? ''));
        $other_reference = escape($conn, trim($_POST['other_reference'] ?? ''));

        $cheque_date_sql = ($cheque_date === '') ? 'NULL' : ("'".$cheque_date."'");
        $credit_due_date_sql = ($credit_due_date === '') ? 'NULL' : ("'".$credit_due_date."'");
        $delivery_date_sql = ($delivery_date === '') ? 'NULL' : ("'".$delivery_date."'");
        $delivery_time_sql = ($delivery_time === '') ? 'NULL' : ("'".$delivery_time."'");

        if (!is_array($items) || count($items) === 0) {
            $error = "Please add at least one item.";
        }

        // Resolve customer
        $final_customer_id = 'NULL';
        $final_customer_name = 'NULL';

        if ($error === '') {
            if ($customer_mode === 'existing') {
                if ($customer_id <= 0) {
                    $error = "Please select an existing customer.";
                } else {
                    $cust_res = mysqli_query($conn, "SELECT id, customer_name FROM customers WHERE id = $customer_id LIMIT 1");
                    $cust = mysqli_fetch_assoc($cust_res);
                    if (!$cust) {
                        $error = "Selected customer not found.";
                    } else {
                        $final_customer_id = (int)$cust['id'];
                        $final_customer_name = "'" . escape($conn, $cust['customer_name']) . "'";
                    }
                }
            } else {
                if ($customer_name === '') {
                    $error = "Customer name is required (manual entry).";
                } else {
                    $insert = mysqli_query($conn, "
                        INSERT INTO customers (customer_name, phone, email, address, gst_number)
                        VALUES ('$customer_name', '$phone', '$email', '$address', '$gst_number')
                    ");
                    if (!$insert) {
                        $error = "Failed to create customer: " . mysqli_error($conn);
                    } else {
                        $final_customer_id = mysqli_insert_id($conn);
                        $final_customer_name = "'$customer_name'";

                        $log_desc = "Added new customer: {$customer_name}" . ($phone ? " (Phone: {$phone})" : "");
                        insertActivityLog($conn, 'create', $log_desc);
                    }
                }
            }
        }

        // Stock check only for NEW invoice
        if ($error === '') {
            $stock_errors = [];
            foreach ($items as $it) {
                $product_id = (int)($it['product_id'] ?? 0);
                $cat_id     = (int)($it['cat_id'] ?? 0);
                $qty = (float)($it['qty'] ?? 0);
                $unit = (string)($it['unit'] ?? '');
                $is_category_sale = isset($it['is_category_sale']) && $it['is_category_sale'] === true;

                if ($cat_id <= 0 || $qty <= 0) {
                    $stock_errors[] = "Invalid item data.";
                    continue;
                }

                if (!$edit_id) {
                    $cat_res = mysqli_query($conn, "SELECT category_name, total_quantity FROM category WHERE id = $cat_id LIMIT 1");
                    $cat_data = mysqli_fetch_assoc($cat_res);

                    if (!$cat_data) {
                        $stock_errors[] = "Category ID {$cat_id} not found.";
                        continue;
                    }

                    // For category sales in KG, check against total_quantity
                    if ((float)$cat_data['total_quantity'] < $qty) {
                        $stock_errors[] = "Insufficient stock for category '{$cat_data['category_name']}'. Available: " .
                            number_format((float)$cat_data['total_quantity'], 2) . " " . $unit . ", Required: " .
                            number_format($qty, 2) . " " . $unit;
                    }
                }
            }

            if (!empty($stock_errors)) $error = implode("<br>", $stock_errors);
        }

        if ($error === '') {
            $inv_num = $edit_id ? (string)$edit_invoice['inv_num'] : generateUniqueInvoiceNumber($conn, $is_gst);

            $subtotal = 0.0;
            $taxable_total = 0.0;
            $cgst_amount_total = 0.0;
            $sgst_amount_total = 0.0;

            foreach ($items as $it) {
                $subtotal += (float)($it['taxable'] ?? 0);
                $taxable_total += (float)($it['taxable'] ?? 0);
                $cgst_amount_total += (float)($it['cgst_amt'] ?? 0);
                $sgst_amount_total += (float)($it['sgst_amt'] ?? 0);
            }

            $overall_disc_amt = 0.0;
            if ($overall_discount > 0) {
                $overall_disc_amt = ($overall_discount_type === 'percentage')
                    ? ($subtotal * $overall_discount) / 100.0
                    : $overall_discount;
            }
            if ($overall_disc_amt > $subtotal) $overall_disc_amt = $subtotal;

            $net_after_overall = $subtotal - $overall_disc_amt;
            $factor = ($subtotal > 0) ? ($net_after_overall / $subtotal) : 1.0;

            $taxable_total = $taxable_total * $factor;
            $cgst_amount_total = $cgst_amount_total * $factor;
            $sgst_amount_total = $sgst_amount_total * $factor;
            $grand_total = $taxable_total + $cgst_amount_total + $sgst_amount_total + $shipping_total;

            // Credit should NOT be counted as received payment
            $total_received_split = $cash_amount + $upi_amount + $card_amount + $bank_amount + $cheque_amount;

            // Amount actually received
            $cash_received = $total_received_split;

            // Calculate pending
            if ($total_received_split >= $grand_total) {
                $change_give = $total_received_split - $grand_total;
                $pending_amount = 0.0;
            } else {
                $pending_amount = $grand_total - $total_received_split;
                $change_give = 0.0;
            }

            // If credit entered, treat it as pending
            if ($credit_amount > 0) {
                $pending_amount = $credit_amount;
            }

            // Determine payment method based on splits
            $payment_method = 'cash';
            if ($upi_amount > 0 && $total_received_split == $upi_amount) $payment_method = 'upi';
            else if ($card_amount > 0 && $total_received_split == $card_amount) $payment_method = 'card';
            else if ($bank_amount > 0 && $total_received_split == $bank_amount) $payment_method = 'bank';
            else if ($cheque_amount > 0 && $total_received_split == $cheque_amount) $payment_method = 'cheque';
            else if ($credit_amount > 0 && $total_received_split == 0) $payment_method = 'credit';
            else if ($total_received_split > 0 && $credit_amount > 0) $payment_method = 'mixed';
            else if ($total_received_split > 0) $payment_method = 'mixed';

            // Prefetch category purchase prices
            $cat_prices = [];
            $cat_price_res = mysqli_query($conn, "SELECT id, purchase_price FROM category");
            while ($r = mysqli_fetch_assoc($cat_price_res)) {
                $cat_prices[(int)$r['id']] = (float)$r['purchase_price'];
            }

            mysqli_begin_transaction($conn);

            try {
                if ($edit_id) {
                    $update_invoice = mysqli_query($conn, "
                        UPDATE invoice SET
                            customer_id = $final_customer_id,
                            customer_name = $final_customer_name,
                            subtotal = $subtotal,
                            overall_discount = $overall_disc_amt,
                            overall_discount_type = '$overall_discount_type',
                            total = $grand_total,
                            taxable = $taxable_total,
                            cgst_amount = $cgst_amount_total,
                            sgst_amount = $sgst_amount_total,
                            cash_received = $cash_received,
                            change_give = $change_give,
                            pending_amount = $pending_amount,
                            payment_method = '$payment_method',
                            is_gst = $is_gst,
                            cash_amount = $cash_amount,
                            upi_amount = $upi_amount,
                            card_amount = $card_amount,
                            bank_amount = $bank_amount,
                            cheque_amount = $cheque_amount,
                            credit_amount = $credit_amount,
                            cheque_number = '$cheque_number',
                            cheque_date = $cheque_date_sql,
                            cheque_bank = '$cheque_bank',
                            credit_due_date = $credit_due_date_sql,
                            credit_notes = '$credit_notes',
                            e_way_bill = '$e_way_bill',
                            shipping_address = '$shipping_address',
                            shipping_charges = $shipping_charges,
                            shipping_method = '$shipping_method',
                            dispatch_through = '$dispatch_through',
                            other_reference = '$other_reference',
                            delivery_date = $delivery_date_sql,
                            delivery_time = $delivery_time_sql,
                            tracking_number = '$tracking_number',
                            shipping_cgst = $shipping_cgst,
                            shipping_sgst = $shipping_sgst,
                            shipping_cgst_amount = $shipping_cgst_amount,
                            shipping_sgst_amount = $shipping_sgst_amount,
                            shipping_total = $shipping_total
                        WHERE id = $edit_id
                    ");
                    if (!$update_invoice) throw new Exception("Failed to update invoice: " . mysqli_error($conn));

                    mysqli_query($conn, "DELETE FROM invoice_item WHERE invoice_id = $edit_id");
                    $invoice_id = $edit_id;
                } else {
                    $insert_invoice = mysqli_query($conn, "
                        INSERT INTO invoice
                        (
                            inv_num, e_way_bill, customer_id, customer_name, subtotal, overall_discount, overall_discount_type, total,
                            taxable, cgst, cgst_amount, sgst, sgst_amount, cash_received, change_give, pending_amount, payment_method,
                            is_gst, cash_amount, upi_amount, card_amount, bank_amount, cheque_amount, credit_amount,
                            cheque_number, cheque_date, cheque_bank, credit_due_date, credit_notes,
                            shipping_address, shipping_charges, shipping_method, dispatch_through, other_reference,
                            delivery_date, delivery_time, tracking_number,
                            shipping_cgst, shipping_sgst, shipping_cgst_amount, shipping_sgst_amount, shipping_total
                        )
                        VALUES
                        (
                            '$inv_num', '$e_way_bill', $final_customer_id, $final_customer_name, $subtotal, $overall_disc_amt, '$overall_discount_type', $grand_total,
                            $taxable_total, 0, $cgst_amount_total, 0, $sgst_amount_total, $cash_received, $change_give, $pending_amount, '$payment_method',
                            $is_gst, $cash_amount, $upi_amount, $card_amount, $bank_amount, $cheque_amount, $credit_amount,
                            '$cheque_number', $cheque_date_sql, '$cheque_bank', $credit_due_date_sql, '$credit_notes',
                            '$shipping_address', $shipping_charges, '$shipping_method', '$dispatch_through', '$other_reference',
                            $delivery_date_sql, $delivery_time_sql, '$tracking_number',
                            $shipping_cgst, $shipping_sgst, $shipping_cgst_amount, $shipping_sgst_amount, $shipping_total
                        )
                    ");
                    if (!$insert_invoice) throw new Exception("Failed to save invoice: " . mysqli_error($conn));
                    $invoice_id = mysqli_insert_id($conn);
                }

                // Insert items with purchase_price from category
                foreach ($items as $li) {
                    // For category sales, use default product_id to satisfy foreign key
                    $product_id = (int)$li['product_id'];
                    if ($product_id <= 0) {
                        $product_id = $default_product_id;
                    }
                    
                    $product_name = escape($conn, (string)$li['product_name']);
                    if (empty($product_name)) {
                        $product_name = $default_product_name;
                    }
                    
                    $cat_id = (int)$li['cat_id'];
                    $cat_name = escape($conn, (string)$li['cat_name']);

                    $qty = (float)$li['qty'];
                    $unit = escape($conn, (string)$li['unit']);

                    // For category sales, converted_qty might be same as qty
                    $converted_qty = (float)($li['converted_qty'] ?? $qty);
                    $no_of_pcs = (float)($li['converted_qty'] ?? $qty);

                    $sell_price = (float)$li['rate'];

                    $total_i = (float)($li['total'] ?? 0) * $factor;
                    $hsn_i = escape($conn, (string)($li['hsn_code'] ?? ''));
                    $taxable_i = (float)($li['taxable'] ?? 0) * $factor;
                    $cgst_i = (float)($li['cgst'] ?? 0);
                    $cgst_amt_i = (float)($li['cgst_amt'] ?? 0) * $factor;
                    $sgst_i = (float)($li['sgst'] ?? 0);
                    $sgst_amt_i = (float)($li['sgst_amt'] ?? 0) * $factor;

                    $purchase_price = $cat_prices[$cat_id] ?? null;
                    if ($purchase_price === null) {
                        throw new Exception("Category purchase price not found for category ID: $cat_id");
                    }
                    $purchase_price = (float)$purchase_price;

                    $insert_item = mysqli_query($conn, "
                        INSERT INTO invoice_item
                        (
                            invoice_id, product_id, product_name, cat_id, cat_name, quantity, unit, no_of_pcs,
                            purchase_price, selling_price, discount, discount_type, total, hsn,
                            taxable, cgst, cgst_amount, sgst, sgst_amount
                        )
                        VALUES
                        (
                            $invoice_id, $product_id, '$product_name', $cat_id, '$cat_name', $qty, '$unit', $no_of_pcs,
                            $purchase_price, $sell_price, 0, 'amount', $total_i, '$hsn_i',
                            $taxable_i, $cgst_i, $cgst_amt_i, $sgst_i, $sgst_amt_i
                        )
                    ");
                    if (!$insert_item) throw new Exception("Failed to save invoice item: " . mysqli_error($conn));

                    // Stock reduce only for NEW invoices
                    if (!$edit_id) {
                        $update_stock = mysqli_query($conn, "UPDATE category SET total_quantity = total_quantity - $qty WHERE id = $cat_id");
                        if (!$update_stock) throw new Exception("Failed to update stock for category: " . $cat_name);
                    }
                }

                // Save bank account transactions for UPI and Bank payments
                if (!$edit_id) {
                    // Get customer name for transaction
                    $customer_display_name = '';
                    if ($customer_mode === 'existing' && $final_customer_id !== 'NULL') {
                        $cust_res = mysqli_query($conn, "SELECT customer_name FROM customers WHERE id = $final_customer_id");
                        $cust = mysqli_fetch_assoc($cust_res);
                        $customer_display_name = $cust['customer_name'];
                    } else {
                        $customer_display_name = $customer_name;
                    }

                    // Save UPI payment transaction
                    if ($upi_amount > 0 && $bank_account_id > 0) {
                        $tx_data = [
                            'bank_account_id' => $bank_account_id,
                            'transaction_date' => date('Y-m-d'),
                            'transaction_type' => 'sale',
                            'reference_type' => 'invoice',
                            'reference_id' => $invoice_id,
                            'reference_number' => $inv_num,
                            'party_name' => $customer_display_name,
                            'party_type' => 'customer',
                            'description' => "UPI payment received for invoice {$inv_num}",
                            'amount' => $upi_amount,
                            'payment_method' => 'upi',
                            'cheque_number' => '',
                            'cheque_date' => '',
                            'cheque_bank' => '',
                            'upi_ref_no' => $upi_ref_no,
                            'transaction_ref_no' => $upi_ref_no ?: $inv_num . '-UPI',
                            'notes' => "UPI payment received"
                        ];
                        saveBankTransaction($conn, $tx_data);
                        
                        // Set cookie for last used bank account
                        if ($current_user_id > 0) {
                            setcookie('last_bank_account', $bank_account_id, time() + (86400 * 30), '/'); // 30 days
                        }
                    }

                    // Save Bank transfer payment transaction
                    if ($bank_amount > 0 && $bank_account_id > 0) {
                        $tx_data = [
                            'bank_account_id' => $bank_account_id,
                            'transaction_date' => date('Y-m-d'),
                            'transaction_type' => 'sale',
                            'reference_type' => 'invoice',
                            'reference_id' => $invoice_id,
                            'reference_number' => $inv_num,
                            'party_name' => $customer_display_name,
                            'party_type' => 'customer',
                            'description' => "Bank transfer payment received for invoice {$inv_num}",
                            'amount' => $bank_amount,
                            'payment_method' => 'bank',
                            'cheque_number' => '',
                            'cheque_date' => '',
                            'cheque_bank' => '',
                            'upi_ref_no' => '',
                            'transaction_ref_no' => $transaction_ref_no ?: $inv_num . '-BANK',
                            'notes' => "Bank transfer payment received"
                        ];
                        saveBankTransaction($conn, $tx_data);
                        
                        // Set cookie for last used bank account
                        if ($current_user_id > 0) {
                            setcookie('last_bank_account', $bank_account_id, time() + (86400 * 30), '/'); // 30 days
                        }
                    }
                }

                $action_type = $edit_id ? 'update' : 'create';
                $log_desc = ($edit_id ? "Updated" : "Created") . " invoice {$inv_num} (Total: ₹" . money2($grand_total) . ")";
                insertActivityLog($conn, $action_type, $log_desc);

                mysqli_commit($conn);

                $_SESSION['sale_cart'] = [];
                unset($_SESSION['edit_cart_loaded']);

                if (($_POST['action_type'] ?? '') === 'print') {
                    header("Location: print_invoice.php?id=" . $invoice_id);
                } else {
                    header("Location: invoices.php");
                }
                exit;

            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
            }
        }
    }
}

// Load cart from session for display
$cart_items = $_SESSION['sale_cart'] ?? [];

// Debug: Check if user is logged in properly (don't show error, just log)
$current_user_id = getCurrentUserId($conn);
if ($current_user_id == 0) {
    // Silently log for debugging (optional)
    error_log("Warning: User session invalid in new-sale.php");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        /* Keep your existing CSS here */
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background:#f0f4f8; color:#1e293b; line-height:1.4;
            font-size: 12px;
        }
        .full-screen { min-height:100vh; width:100%; padding:14px 18px; background:#F0F0F0; }
        .page-header { margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
        .page-header h1 { font-size:20px; font-weight:700; color:#0f172a; margin-bottom:2px; }
        .page-header p { font-size:12px; color:#475569; margin:0; }
        .nav-buttons { display:flex; gap:8px; }
        .btn-nav {
            padding:6px 12px; border-radius:20px; font-weight:600; font-size:12px;
            display:inline-flex; align-items:center; gap:6px; transition:all .2s; text-decoration:none;
        }
        .btn-nav-back { background:white; color:#475569; border:1px solid #e2e8f0; }
        .btn-nav-close { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .btn-nav-quote { background:#8b5cf6; color:white; border:1px solid #7c3aed; }
        .btn-nav-clear { background:#f59e0b; color:white; border:1px solid #d97706; }

        .card-custom {
            background:#f1f5f9; border-radius:12px; box-shadow:0 6px 20px rgba(0,0,0,.04);
            padding:12px; margin-bottom:12px; border:1px solid #e9eef2;
        }
        .card-header-custom {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid #eef2f6;
        }
        .card-header-custom h5 { font-size:13px; font-weight:700; margin:0; color:#0f172a; }
        .badge-custom {
            background:#e6f0ff; color:#2563eb; padding:4px 8px; border-radius:30px;
            font-size:11px; font-weight:600;
        }
        .badge-quote {
            background:#f3e8ff; color:#8b5cf6; padding:4px 8px; border-radius:30px;
            font-size:11px; font-weight:600;
        }
        .badge-category {
            background:#dcfce7; color:#166534; padding:4px 8px; border-radius:30px;
            font-size:11px; font-weight:600;
        }
        .badge-edit {
            background:#fef3c7; color:#92400e; padding:4px 8px; border-radius:30px;
            font-size:11px; font-weight:600;
        }
        .bank-badge {
            background:#dbeafe; color:#1e40af; padding:4px 8px; border-radius:30px;
            font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px;
        }
        .form-label { font-weight:600; font-size:11px; color:#475569; margin-bottom:4px; }
        .form-control, .form-select {
            border:1px solid #dbe3eb; border-radius:8px; padding:4px 8px; font-size:12px;
            min-height:32px;
        }
        .form-control:focus, .form-select:focus {
            border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.08); outline:none;
        }
        .select2-container--default .select2-selection--single {
            border:1px solid #dbe3eb !important; border-radius:8px !important;
            height:32px !important; padding:1px 8px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height:28px !important; font-size:12px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height:30px !important;
        }
        .select2-dropdown { border:1px solid #dbe3eb !important; border-radius:8px !important; }
        .calc-box {
            background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px;
        }
        .small-muted {
            font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.3px;
        }
        .line-total { font-weight:700; font-size:18px; color:#0f172a; line-height:1.2; }
        .conversion-detail {
            background:#e8f2ff; padding:8px 10px; border-radius:8px; font-size:12px; color:#2563eb;
            border:1px solid #cddfff;
        }
        .total-breakdown {
            background:#f8fafc; border-left:3px solid #2563eb; white-space:pre-line; font-size:11px;
            padding:8px; border-radius:6px; margin-top:8px;
        }
        .btn { padding:6px 12px; font-weight:600; font-size:12px; border-radius:8px; border:none; }
        .btn-primary { background:#2563eb; color:white; }
        .btn-success { background:#059669; color:white; }
        .btn-quote { background:#8b5cf6; color:white; }
        .btn-save-print { background:#8b5cf6; color:white; }
        .btn-outline-danger { background:white; border:1px solid #ef4444; color:#ef4444; padding:4px 8px; }
        .btn-outline-warning { background:white; border:1px solid #f59e0b; color:#f59e0b; padding:4px 8px; }
        .btn-warning { background:#f59e0b; color:white; }
        .btn-category { background:#166534; color:white; }
        .btn-category:hover { background:#14532d; color:white; }
        .table { border-radius:10px; overflow:hidden; border:1px solid #e2e8f0; margin-bottom:0; }
        .table thead th {
            background:#f8fafc; font-weight:700; font-size:11px; color:#475569; padding:8px 6px; border-bottom:1px solid #e2e8f0;
        }
        .table tbody td { padding:8px 6px; font-size:12px; border-bottom:1px solid #eef2f6; vertical-align:middle; }
        .alert { border-radius:10px; padding:10px 12px; border:none; font-size:12px; margin-bottom:12px; }
        .alert-success { background:#d1fae5; color:#065f46; }
        .alert-danger { background:#fee2e2; color:#991b1b; }
        .stock-warning { color:#ef4444; font-size:11px; margin-top:4px; }
        .stock-ok { color:#10b981; font-size:11px; margin-top:4px; }
        .badge-unit {
            font-size:10px; padding:3px 6px; border-radius:20px; background:#f1f5f9;
            color:#0f172a; font-weight:700; text-transform:uppercase;
        }
        .badge-category-item {
            font-size:10px; padding:3px 6px; border-radius:20px; background:#d1fae5;
            color:#166534; font-weight:700;
        }
        .action-buttons { display:flex; justify-content:flex-end; gap:8px; margin-top:12px; flex-wrap:wrap; }
        .btn-save { min-width:120px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .summary-grid .calc-box { height:100%; }
        .tiny { font-size: 11px; }
        .quote-actions { display:flex; gap:8px; margin-top:8px; }
        .nav-tabs {
            display:flex; gap:2px; background:#f1f5f9; padding:4px; border-radius:30px; margin-bottom:15px;
        }
        .nav-tab {
            padding:6px 16px; border-radius:30px; font-size:12px; font-weight:600;
            cursor:pointer; border:none; background:transparent; color:#64748b;
        }
        .nav-tab.active {
            background:white; color:#0f172a; box-shadow:0 2px 8px rgba(0,0,0,.04);
        }
        .e-way-bill-row, .dispatch-row {
            background: #f0f7ff;
            border: 1px solid #b8d4ff;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 15px;
        }
        .e-way-bill-label, .dispatch-label {
            font-weight: 600;
            color: #0a58ca;
            font-size: 12px;
        }
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
        .category-sale-section {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
        }
        .category-sale-label {
            font-weight: 600;
            color: #166534;
            font-size: 12px;
            margin-bottom: 8px;
        }
        .item-type-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 600;
            margin-left: 5px;
        }
        .item-type-product {
            background: #dbeafe;
            color: #1e40af;
        }
        .item-type-category {
            background: #dcfce7;
            color: #166534;
        }
        @media (max-width: 768px) {
            .full-screen { padding:10px; }
            .action-buttons { flex-direction:column; }
            .btn-save, .btn-save-print, .btn-quote { width:100%; }
            .page-header { flex-direction:column; align-items:flex-start; }
        }
    </style>
</head>
<body>
<div class="full-screen">

    <div class="page-header">
        <div>
            <h1>
                <?php echo $edit_id ? 'Edit Invoice #' . htmlspecialchars($edit_invoice['inv_num'] ?? '') : 'New Sale / Quotation'; ?>
            </h1>
            <p>Compact billing • GST/Non-GST switch • Add products or category bulk sales (KG)</p>
        </div>
        <div class="nav-buttons">
            <a href="?clear_cart=1" class="btn-nav btn-nav-clear" onclick="return confirm('Clear all items?')"><i class="bi bi-cart-x"></i> Clear Cart</a>
            <a href="quotations.php" class="btn-nav btn-nav-quote"><i class="bi bi-file-text"></i> Quotations List</a>
            <a href="invoices.php" class="btn-nav btn-nav-back"><i class="bi bi-arrow-left"></i> Back</a>
            <a href="invoices.php" class="btn-nav btn-nav-close"><i class="bi bi-x-lg"></i> Close</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($edit_id): ?>
    <div class="alert alert-warning">
        <i class="bi bi-pencil-fill me-1"></i> You are editing invoice #<?php echo htmlspecialchars($edit_invoice['inv_num'] ?? ''); ?>. Changes will be saved to this invoice.
    </div>
    <?php endif; ?>

    <div class="nav-tabs">
        <button class="nav-tab <?php echo !$edit_id ? 'active' : ''; ?>" id="tabInvoice">Create Invoice</button>
        <button class="nav-tab" id="tabQuotation">Create Quotation</button>
    </div>

    <form method="POST" action="new-sale.php<?php echo $edit_id ? '?edit_id=' . $edit_id : ''; ?>" id="saleForm">
        <input type="hidden" name="action" id="formAction" value="<?php echo $edit_id ? 'create_invoice' : 'create_invoice'; ?>">
        <input type="hidden" name="items_json" id="items_json" value='<?php echo json_encode($cart_items); ?>'>
        <input type="hidden" name="is_gst" id="is_gst" value="<?php echo ($edit_invoice['is_gst'] ?? 1); ?>">

        <!-- Invoice Type -->
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <div class="form-label mb-1">Document Type</div>
                    <div class="tiny text-muted">
                        GST prefix: <span class="mono">SP</span> (Invoice) / <span class="mono">Q</span> (Quotation) &nbsp;|&nbsp; 
                        Non-GST prefix: <span class="mono">E</span> (Invoice) / <span class="mono">Q</span> (Quotation)
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="tiny">Non-GST</span>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" id="isGstSwitch" <?php echo ($edit_invoice['is_gst'] ?? 1) ? 'checked' : ''; ?>>
                    </div>
                    <span class="tiny">GST</span>
                </div>
            </div>
        </div>

        <!-- Customer -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h5><i class="bi bi-person me-1"></i>Customer</h5>
                <span class="badge-custom">Required</span>
            </div>

            <div class="d-flex align-items-center flex-wrap gap-3 mb-2">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="customer_mode" id="custExisting" value="existing" <?php echo (!$edit_invoice || $edit_invoice['customer_id'] > 0) ? 'checked' : ''; ?>>
                    <label class="form-check-label tiny" for="custExisting">Existing</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="customer_mode" id="custManual" value="manual" <?php echo ($edit_invoice && $edit_invoice['customer_id'] == 0) ? 'checked' : ''; ?>>
                    <label class="form-check-label tiny" for="custManual">New Customer</label>
                </div>
            </div>

            <div id="existingCustomerBox">
                <label class="form-label">Search Customer</label>
                <select class="form-select" id="customerSelect" name="customer_id" style="width:100%">
                    <?php if ($edit_invoice && $edit_invoice['customer_id'] > 0): ?>
                    <option value="<?php echo $edit_invoice['customer_id']; ?>" selected>
                        <?php echo htmlspecialchars($edit_invoice['customer_name']); ?>
                    </option>
                    <?php endif; ?>
                </select>

                <div class="row g-2 mt-1" id="customerDetails" style="<?php echo ($edit_invoice && $edit_invoice['customer_id'] > 0) ? 'display:block;' : 'display:none;'; ?>">
                    <div class="col-md-3"><div class="calc-box"><div class="small-muted">Phone</div><div id="custPhone">-</div></div></div>
                    <div class="col-md-3"><div class="calc-box"><div class="small-muted">Email</div><div id="custEmail">-</div></div></div>
                    <div class="col-md-3"><div class="calc-box"><div class="small-muted">GST</div><div id="custGST">-</div></div></div>
                    <div class="col-md-3"><div class="calc-box"><div class="small-muted">Address</div><div id="custAddress">-</div></div></div>
                </div>
            </div>

            <div id="manualCustomerBox" style="<?php echo ($edit_invoice && $edit_invoice['customer_id'] == 0) ? 'display:block;' : 'display:none;'; ?>">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Customer Name *</label>
                        <input type="text" class="form-control" name="customer_name" id="m_customer_name" value="<?php echo htmlspecialchars($edit_invoice['customer_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone" id="m_phone" value="<?php echo htmlspecialchars($edit_invoice['customer_phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Email</label>
                        <input type="text" class="form-control" name="email" id="m_email" value="<?php echo htmlspecialchars($edit_invoice['customer_email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">GST Number</label>
                        <input type="text" class="form-control" name="gst_number" id="m_gst" value="<?php echo htmlspecialchars($edit_invoice['customer_gst'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Address</label>
                        <input type="text" class="form-control" name="address" id="m_address" value="<?php echo htmlspecialchars($edit_invoice['customer_address'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Bank Account Selection (for Invoice mode only) -->
        <div id="bankAccountFields" class="bank-selection-row">
            <div class="row align-items-center">
                <div class="col-md-2">
                    <label class="bank-selection-label"><i class="bi bi-bank"></i> Bank Account</label>
                </div>
                <div class="col-md-4">
                    <select class="form-select" name="bank_account_id" id="bank_account_id">
                        <option value="">Select Bank Account (for UPI/Bank payments)</option>
                        <?php 
                        if ($bank_accounts && mysqli_num_rows($bank_accounts) > 0):
                            mysqli_data_seek($bank_accounts, 0);
                            while ($acc = mysqli_fetch_assoc($bank_accounts)): 
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
                    <div class="tiny text-muted mt-1">
                        <i class="bi bi-info-circle"></i> Required only for UPI/Bank payments to create transaction records
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex gap-2 align-items-center">
                        <span class="bank-badge"><i class="bi bi-phone"></i> UPI Ref:</span>
                        <input type="text" class="form-control form-control-sm" name="upi_ref_no" id="upi_ref_no" placeholder="UPI Reference No." style="width:150px;">
                        <span class="bank-badge"><i class="bi bi-upc-scan"></i> Transaction Ref:</span>
                        <input type="text" class="form-control form-control-sm" name="transaction_ref_no" id="transaction_ref_no" placeholder="Transaction Ref No." style="width:150px;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Dispatch Through and Other Reference Row (for Invoice mode only) -->
        <div id="dispatchFields" class="dispatch-row">
            <div class="row align-items-center">
                <div class="col-md-2">
                    <label class="dispatch-label"><i class="bi bi-truck"></i> Dispatch Through</label>
                </div>
                <div class="col-md-4">
                    <select class="form-select" name="dispatch_through" id="dispatch_through">
                        <option value="">Select Mode</option>
                        <option value="Road" <?php echo (($edit_invoice['dispatch_through'] ?? '') == 'Road') ? 'selected' : ''; ?>>Road Transport</option>
                        <option value="Rail" <?php echo (($edit_invoice['dispatch_through'] ?? '') == 'Rail') ? 'selected' : ''; ?>>Rail Transport</option>
                        <option value="Air" <?php echo (($edit_invoice['dispatch_through'] ?? '') == 'Air') ? 'selected' : ''; ?>>Air Transport</option>
                        <option value="Ship" <?php echo (($edit_invoice['dispatch_through'] ?? '') == 'Ship') ? 'selected' : ''; ?>>Ship Transport</option>
                        <option value="Courier" <?php echo (($edit_invoice['dispatch_through'] ?? '') == 'Courier') ? 'selected' : ''; ?>>Courier Service</option>
                        <option value="Self" <?php echo (($edit_invoice['dispatch_through'] ?? '') == 'Self') ? 'selected' : ''; ?>>Self Pickup</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="dispatch-label"><i class="bi bi-file-text"></i> Other Reference</label>
                </div>
                <div class="col-md-4">
                    <input type="text" class="form-control" name="other_reference" id="other_reference" 
                           value="<?php echo htmlspecialchars($edit_invoice['other_reference'] ?? ''); ?>" 
                           placeholder="e.g., PO No., Order No.">
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-12">
                    <small class="text-muted"><i class="bi bi-info-circle"></i> Specify dispatch mode and any reference number for tracking</small>
                </div>
            </div>
        </div>

        <!-- E-Way Bill Number Field (only for Invoice mode) -->
        <div id="eWayBillField" class="e-way-bill-row">
            <div class="row align-items-center">
                <div class="col-md-2">
                    <label class="e-way-bill-label"><i class="bi bi-truck"></i> E-Way Bill No</label>
                </div>
                <div class="col-md-4">
                    <input type="text" class="form-control" name="e_way_bill" id="e_way_bill" 
                           value="<?php echo htmlspecialchars($edit_invoice['e_way_bill'] ?? ''); ?>" 
                           placeholder="Enter E-Way Bill Number">
                </div>
                <div class="col-md-6">
                    <small class="text-muted">Required for inter-state transport of goods value > ₹50,000</small>
                </div>
            </div>
        </div>

        <!-- Quotation Specific Fields (hidden by default) -->
        <div id="quotationFields" style="display:none;">
            <div class="card-custom">
                <div class="card-header-custom">
                    <h5><i class="bi bi-calendar me-1"></i>Quotation Details</h5>
                    <span class="badge-quote">Quotation</span>
                </div>
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label">Valid Until</label>
                        <input type="date" class="form-control" name="valid_until" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Notes</label>
                        <input type="text" class="form-control" name="quotation_notes" placeholder="Additional notes for quotation...">
                    </div>
                </div>
            </div>
        </div>

        <!-- Shipping Details - Only for Invoice mode -->
        <div class="card-custom" id="shippingFields">
            <!-- Hidden field to store shipping address from customer table -->
            <input type="hidden" name="shipping_address" id="shipping_address" value="">
        </div>

        <!-- Product Sale Section -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h5><i class="bi bi-box me-1"></i>Add Product Item (Pieces/Bag)</h5>
                <span class="badge-custom" id="gstPricingBadge">GST Inclusive Pricing</span>
            </div>

            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Product</label>
                    <select class="form-select" id="productSelect" style="width:100%"></select>
                    <div class="tiny text-muted mt-1" id="productMeta"></div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select class="form-select" id="categorySelect" style="width:100%"></select>
                    <div class="tiny text-muted mt-1" id="categoryMeta"></div>
                </div>

                <div class="col-md-1">
                    <label class="form-label">Unit</label>
                    <select class="form-select" id="unitSelect" disabled>
                        <option value="">Unit</option>
                    </select>
                </div>

                <div class="col-md-1">
                    <label class="form-label">Pcs/Bag</label>
                    <input type="number" class="form-control" id="pcsPerBagInput" step="0.001" min="0" disabled>
                </div>

                <div class="col-md-1">
                    <label class="form-label">Qty</label>
                    <input type="number" class="form-control" id="qtyInput" step="0.001" min="0" disabled>
                </div>

                <div class="col-md-1">
                    <label class="form-label">Rate / pcs</label>
                    <input type="number" class="form-control" id="rateInput" step="0.01" min="0" disabled>
                </div>

                <div class="col-md-2">
                    <button type="button" class="btn btn-primary w-100" id="addItemBtn" disabled>
                        <i class="bi bi-plus-circle me-1"></i>Add Item
                    </button>
                </div>
            </div>

            <div class="row g-2 mt-2 summary-grid">
                <div class="col-md-6">
                    <div class="calc-box">
                        <div class="small-muted mb-1">Conversion / Stock</div>
                        <div id="autoConversionText" class="conversion-detail">
                            <i class="bi bi-arrow-left-right"></i> Select product and category
                        </div>
                        <div id="stockStatus"></div>
                        <div class="tiny text-muted mt-1" id="conversionHint">Select product & category first</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="calc-box">
                        <div class="small-muted mb-1">Line Total</div>
                        <div class="line-total" id="lineTotalText">₹0.00</div>
                        <div class="total-breakdown" id="lineBreakdown"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category Bulk Sale Section (KG only) -->
        <div class="category-sale-section">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="category-sale-label"><i class="bi bi-boxes"></i> Direct Category Sale (KG only)</span>
                <span class="badge-category">Sell by KG directly</span>
            </div>

            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Select Category</label>
                    <select class="form-select" id="bulkCategorySelect" style="width:100%">
                        <option value="">Choose category...</option>
                        <?php 
                        mysqli_data_seek($categories, 0);
                        while ($cat = mysqli_fetch_assoc($categories)): 
                            $max_kg = $cat['total_quantity']; // Direct KG quantity
                        ?>
                            <option value="<?php echo $cat['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($cat['category_name']); ?>"
                                    data-purchase-price="<?php echo $cat['purchase_price']; ?>"
                                    data-stock="<?php echo $cat['total_quantity']; ?>"
                                    data-unit="KG">
                                <?php echo htmlspecialchars($cat['category_name']); ?> 
                                (Stock: <?php echo number_format($cat['total_quantity'], 2); ?> KG)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Selling Price (₹/KG)</label>
                    <div class="d-flex align-items-center">
                        <span class="me-1">₹</span>
                        <input type="number" class="form-control" id="bulkSellingPrice" step="0.01" min="0" placeholder="Price per KG">
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Quantity (KG)</label>
                    <input type="number" class="form-control" id="bulkKgQuantity" step="0.001" min="0.001" placeholder="Enter KG">
                    <small class="text-muted" id="bulkMaxKgHint"></small>
                </div>

                <div class="col-md-2">
                    <button type="button" class="btn btn-category w-100" id="addBulkCategoryBtn">
                        <i class="bi bi-plus-circle"></i> Add Category
                    </button>
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-md-12">
                    <div id="bulkCategoryInfo" class="tiny text-muted" style="display: none;"></div>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h5><i class="bi bi-list-check me-1"></i>Items</h5>
                <span class="badge-custom">Items: <span id="itemCount"><?php echo count($cart_items); ?></span></span>
            </div>

            <div class="table-responsive">
                <table class="table" id="itemsTable">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Type</th>
                        <th>Product/Category</th>
                        <th>Unit</th>
                        <th class="text-end">Quantity</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($cart_items)): ?>
                    <tr id="noItemsRow">
                        <td colspan="8" class="text-center text-muted py-3">No items added yet</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($cart_items as $idx => $it): 
                            $is_category_sale = isset($it['is_category_sale']) && $it['is_category_sale'] === true;
                            $display_name = $is_category_sale ? $it['cat_name'] : $it['product_name'] . ' - ' . $it['cat_name'];
                        ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td>
                                <?php if ($is_category_sale): ?>
                                    <span class="badge-category-item">Category (KG)</span>
                                <?php else: ?>
                                    <span class="badge-unit">Product</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($display_name); ?></td>
                            <td><span class="badge-unit"><?php echo htmlspecialchars($it['unit']); ?></span></td>
                            <td class="text-end"><?php echo number_format((float)$it['qty'], 3); ?></td>
                            <td class="text-end">₹<?php echo number_format((float)$it['rate'], 2); ?></td>
                            <td class="text-end fw-bold">₹<?php echo number_format((float)$it['total'], 2); ?></td>
                            <td class="text-center">
                                <button type="button" class="btn btn-outline-danger btn-sm" data-remove="<?php echo $idx; ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="row g-2 mt-2">
                <div class="col-md-2">
                    <label class="form-label">Overall Discount</label>
                    <input type="number" class="form-control" name="overall_discount" value="<?php echo $edit_invoice['overall_discount'] ?? 0; ?>" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Discount Type</label>
                    <select class="form-select" name="overall_discount_type">
                        <option value="amount" <?php echo (($edit_invoice['overall_discount_type'] ?? 'amount') == 'amount') ? 'selected' : ''; ?>>Amount (₹)</option>
                        <option value="percentage" <?php echo (($edit_invoice['overall_discount_type'] ?? '') == 'percentage') ? 'selected' : ''; ?>>Percentage (%)</option>
                    </select>
                </div>
                <div class="col-md-8" id="paymentFields">
                    <label class="form-label">Multi Payment Split (single invoice)</label>
                    <div class="row g-2">
                        <div class="col-md-2 col-6">
                            <label class="form-label mb-1">Cash</label>
                            <input type="number" class="form-control pay-input" name="cash_amount" id="cash_amount" value="<?php echo $edit_invoice['cash_amount'] ?? 0; ?>" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="col-md-2 col-6">
                            <label class="form-label mb-1">UPI</label>
                            <input type="number" class="form-control pay-input" name="upi_amount" id="upi_amount" value="<?php echo $edit_invoice['upi_amount'] ?? 0; ?>" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="col-md-2 col-6">
                            <label class="form-label mb-1">Card</label>
                            <input type="number" class="form-control pay-input" name="card_amount" id="card_amount" value="<?php echo $edit_invoice['card_amount'] ?? 0; ?>" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="col-md-2 col-6">
                            <label class="form-label mb-1">Bank</label>
                            <input type="number" class="form-control pay-input" name="bank_amount" id="bank_amount" value="<?php echo $edit_invoice['bank_amount'] ?? 0; ?>" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="col-md-2 col-6">
                            <label class="form-label mb-1">Cheque</label>
                            <input type="number" class="form-control pay-input" name="cheque_amount" id="cheque_amount" value="<?php echo $edit_invoice['cheque_amount'] ?? 0; ?>" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="col-md-2 col-6">
                            <label class="form-label mb-1">Credit</label>
                            <input type="number" class="form-control pay-input" name="credit_amount" id="credit_amount" value="<?php echo $edit_invoice['credit_amount'] ?? 0; ?>" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="chequeFields" class="row g-2 mt-2">
                <div class="col-md-3">
                    <label class="form-label">Cheque No</label>
                    <input type="text" class="form-control" name="cheque_number" value="<?php echo htmlspecialchars($edit_invoice['cheque_number'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cheque Date</label>
                    <input type="date" class="form-control" name="cheque_date" value="<?php echo htmlspecialchars($edit_invoice['cheque_date'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cheque Bank</label>
                    <input type="text" class="form-control" name="cheque_bank" value="<?php echo htmlspecialchars($edit_invoice['cheque_bank'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Credit Due Date</label>
                    <input type="date" class="form-control" name="credit_due_date" value="<?php echo htmlspecialchars($edit_invoice['credit_due_date'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Credit Notes</label>
                    <input type="text" class="form-control" name="credit_notes" value="<?php echo htmlspecialchars($edit_invoice['credit_notes'] ?? ''); ?>">
                </div>
            </div>

            <div class="row g-2 mt-3">
                <div class="col-md-12">
                    <div class="calc-box">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <div class="small-muted">Summary</div>
                                <div class="tiny text-muted" id="gstRatesUsedText">GST Rates Used: -</div>
                            </div>
                            <div class="text-end">
                                <div class="small-muted">GRAND TOTAL</div>
                                <div class="line-total" id="grandTotalText">₹0.00</div>
                            </div>
                        </div>

                        <div class="row g-2 mt-2">
                            <div class="col-md-3 col-6">
                                <div class="tiny text-muted">Taxable Subtotal</div>
                                <div class="fw-bold" id="taxableSubtotalText">₹0.00</div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="tiny text-muted">Overall Discount</div>
                                <div class="fw-bold text-danger" id="overallDiscountText">-₹0.00</div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="tiny text-muted">CGST Total</div>
                                <div class="fw-bold" id="cgstTotalText">₹0.00</div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="tiny text-muted">SGST Total</div>
                                <div class="fw-bold" id="sgstTotalText">₹0.00</div>
                            </div>
                        </div>

                        <div class="tiny text-muted mt-2" id="invoiceSummaryNote"></div>
                    </div>
                </div>
            </div>

            <div class="tiny text-muted mt-2" id="paymentSplitSummary">Split total: ₹0.00</div>

            <div class="action-buttons">
                <button type="submit" name="action_type" value="save" class="btn btn-success btn-save" id="saveBtn">
                    <i class="bi bi-check-circle me-1"></i><?php echo $edit_id ? 'Update' : 'Save'; ?>
                </button>
                <button type="submit" name="action_type" value="print" class="btn btn-save-print btn-save" id="printBtn">
                    <i class="bi bi-printer me-1"></i><?php echo $edit_id ? 'Update & Print' : 'Save & Print'; ?>
                </button>
                <button type="submit" name="action_type" value="print_quote" class="btn btn-quote btn-save" id="quotePrintBtn" style="display:none;">
                    <i class="bi bi-file-text me-1"></i>Create & Print Quotation
                </button>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function() {
    let selectedProduct = null;
    let selectedCategory = null;
    let items = <?php echo json_encode($cart_items); ?>;

    // Tab switching
    const tabInvoice = document.getElementById('tabInvoice');
    const tabQuotation = document.getElementById('tabQuotation');
    const quotationFields = document.getElementById('quotationFields');
    const paymentFields = document.getElementById('paymentFields');
    const chequeFields = document.getElementById('chequeFields');
    const eWayBillField = document.getElementById('eWayBillField');
    const dispatchFields = document.getElementById('dispatchFields');
    const bankAccountFields = document.getElementById('bankAccountFields');
    const shippingFields = document.getElementById('shippingFields');
    const saveBtn = document.getElementById('saveBtn');
    const printBtn = document.getElementById('printBtn');
    const quotePrintBtn = document.getElementById('quotePrintBtn');
    const formAction = document.getElementById('formAction');

    function switchToInvoice() {
        tabInvoice.classList.add('active');
        tabQuotation.classList.remove('active');
        quotationFields.style.display = 'none';
        paymentFields.style.display = 'block';
        chequeFields.style.display = 'flex';
        eWayBillField.style.display = 'block';
        dispatchFields.style.display = 'block';
        bankAccountFields.style.display = 'block';
        shippingFields.style.display = 'block';
        saveBtn.style.display = 'inline-block';
        printBtn.style.display = 'inline-block';
        quotePrintBtn.style.display = 'none';
        formAction.value = 'create_invoice';
    }

    function switchToQuotation() {
        tabQuotation.classList.add('active');
        tabInvoice.classList.remove('active');
        quotationFields.style.display = 'block';
        paymentFields.style.display = 'none';
        chequeFields.style.display = 'none';
        eWayBillField.style.display = 'none';
        dispatchFields.style.display = 'none';
        bankAccountFields.style.display = 'none';
        shippingFields.style.display = 'none';
        saveBtn.style.display = 'none';
        printBtn.style.display = 'none';
        quotePrintBtn.style.display = 'inline-block';
        formAction.value = 'create_quotation';
    }

    tabInvoice.addEventListener('click', switchToInvoice);
    tabQuotation.addEventListener('click', switchToQuotation);

    // Set initial tab state
    <?php if ($edit_id): ?>
    switchToInvoice();
    <?php endif; ?>

    // Customer mode toggle
    function updateCustomerModeUI() {
        const manual = document.getElementById('custManual').checked;
        document.getElementById('existingCustomerBox').style.display = manual ? 'none' : 'block';
        document.getElementById('manualCustomerBox').style.display = manual ? 'block' : 'none';
    }
    document.getElementById('custExisting').addEventListener('change', updateCustomerModeUI);
    document.getElementById('custManual').addEventListener('change', updateCustomerModeUI);
    updateCustomerModeUI();

    // Save bank account selection to cookie when changed
    document.getElementById('bank_account_id').addEventListener('change', function() {
        const accountId = this.value;
        if (accountId) {
            document.cookie = "last_bank_account=" + accountId + "; path=/; max-age=" + (30 * 24 * 60 * 60);
        }
    });

    // Invoice Summary
    function updateInvoiceSummary() {
        const elTaxable = document.getElementById('taxableSubtotalText');
        const elDisc = document.getElementById('overallDiscountText');
        const elCgst = document.getElementById('cgstTotalText');
        const elSgst = document.getElementById('sgstTotalText');
        const elGrand = document.getElementById('grandTotalText');
        const elRates = document.getElementById('gstRatesUsedText');
        const elNote = document.getElementById('invoiceSummaryNote');
        if (!elTaxable || !elDisc || !elCgst || !elSgst || !elGrand || !elRates || !elNote) return;

        let taxableSubtotal = 0;
        let cgstTotal = 0;
        let sgstTotal = 0;

        const gstRatesSet = new Set();
        const isGstInvoice = document.getElementById('is_gst').value === '1';

        items.forEach(it => {
            taxableSubtotal += Number(it.taxable || 0);
            cgstTotal += Number(it.cgst_amt || 0);
            sgstTotal += Number(it.sgst_amt || 0);

            const rate = (Number(it.cgst || 0) + Number(it.sgst || 0));
            if (isGstInvoice && rate > 0) gstRatesSet.add(rate.toFixed(2) + '%');
        });

        const discInput = document.querySelector('input[name="overall_discount"]');
        const discTypeSel = document.querySelector('select[name="overall_discount_type"]');
        const discValue = parseFloat(discInput?.value || 0);
        const discType = (discTypeSel?.value === 'percentage') ? 'percentage' : 'amount';

        let overallDiscAmt = 0;
        if (discValue > 0) {
            overallDiscAmt = (discType === 'percentage')
                ? (taxableSubtotal * discValue / 100)
                : discValue;
        }
        if (overallDiscAmt > taxableSubtotal) overallDiscAmt = taxableSubtotal;

        const netAfterDiscount = taxableSubtotal - overallDiscAmt;
        const factor = (taxableSubtotal > 0) ? (netAfterDiscount / taxableSubtotal) : 1;

        const taxableAfter = taxableSubtotal * factor;
        const cgstAfter = cgstTotal * factor;
        const sgstAfter = sgstTotal * factor;
        
        // Check if shipping fields exist and are visible
        let shippingTotal = 0;
        if (tabInvoice.classList.contains('active')) {
            shippingTotal = parseFloat(document.getElementById('shipping_total')?.value || 0);
        }
        
        const grandTotal = taxableAfter + cgstAfter + sgstAfter + shippingTotal;

        elTaxable.textContent = '₹' + taxableSubtotal.toFixed(2);
        elDisc.textContent = '-₹' + overallDiscAmt.toFixed(2);
        elCgst.textContent = '₹' + cgstAfter.toFixed(2);
        elSgst.textContent = '₹' + sgstAfter.toFixed(2);
        elGrand.textContent = '₹' + grandTotal.toFixed(2);

        const ratesText = (isGstInvoice && gstRatesSet.size > 0)
            ? Array.from(gstRatesSet).sort((a,b) => parseFloat(a) - parseFloat(b)).join(', ')
            : '-';
        elRates.textContent = 'GST Rates Used: ' + ratesText;

        if (tabInvoice.classList.contains('active')) {
            elNote.textContent = isGstInvoice
                ? 'Grand Total = Taxable(after discount) + CGST + SGST + Shipping (Rate/pcs is GST inclusive)'
                : 'Grand Total = Taxable(after discount) + Shipping (Non-GST invoice)';
        } else {
            elNote.textContent = isGstInvoice
                ? 'Grand Total = Taxable(after discount) + CGST + SGST (Rate/pcs is GST inclusive)'
                : 'Grand Total = Taxable(after discount) (Non-GST quotation)';
        }
        
        autoFillPayments(grandTotal);
    }

    function autoFillPayments(grandTotal) {
        const cashInput = document.getElementById('cash_amount');
        const upiInput = document.getElementById('upi_amount');
        const cardInput = document.getElementById('card_amount');
        const bankInput = document.getElementById('bank_amount');
        const chequeInput = document.getElementById('cheque_amount');
        const creditInput = document.getElementById('credit_amount');
        
        const cash = parseFloat(cashInput?.value || 0);
        const upi = parseFloat(upiInput?.value || 0);
        const card = parseFloat(cardInput?.value || 0);
        const bank = parseFloat(bankInput?.value || 0);
        const cheque = parseFloat(chequeInput?.value || 0);
        const credit = parseFloat(creditInput?.value || 0);
        
        const totalEntered = cash + upi + card + bank + cheque + credit;
        
        if (totalEntered === 0 && grandTotal > 0 && cashInput) {
            cashInput.value = grandTotal.toFixed(2);
        }
    }

    document.querySelector('input[name="overall_discount"]')
        ?.addEventListener('input', updateInvoiceSummary);
    document.querySelector('select[name="overall_discount_type"]')
        ?.addEventListener('change', updateInvoiceSummary);

    // GST switch
    function syncGstSwitch() {
        const isGst = document.getElementById('isGstSwitch').checked ? 1 : 0;
        document.getElementById('is_gst').value = String(isGst);
        document.getElementById('gstPricingBadge').textContent = isGst ? 'GST Inclusive Pricing' : 'Non-GST Pricing';
        recalcLine();
        updateInvoiceSummary();
    }
    document.getElementById('isGstSwitch').addEventListener('change', syncGstSwitch);
    syncGstSwitch();

    // Select2 initialization
    $('#customerSelect').select2({
        placeholder: 'Search customer...',
        allowClear: true,
        ajax: {
            url: 'new-sale.php?ajax=customers',
            dataType: 'json',
            delay: 250,
            data: params => ({ term: params.term || '' }),
            processResults: data => data
        }
    });

    $('#productSelect').select2({
        placeholder: 'Search product...',
        allowClear: true,
        ajax: {
            url: 'new-sale.php?ajax=products',
            dataType: 'json',
            delay: 250,
            data: params => ({ term: params.term || '' }),
            processResults: data => data
        }
    });

    $('#categorySelect').select2({
        placeholder: 'Select category...',
        allowClear: true,
        ajax: {
            url: 'new-sale.php?ajax=categories',
            dataType: 'json',
            delay: 250,
            data: params => ({ term: params.term || '' }),
            processResults: data => data
        }
    });

    // Bulk category select (simple dropdown, not ajax)
    $('#bulkCategorySelect').select2({
        placeholder: 'Choose category for bulk sale...',
        allowClear: true,
        width: '100%'
    });

    // Customer details
    $('#customerSelect').on('select2:select', function(e) {
        const id = e.params.data.id;
        fetch('new-sale.php?ajax=customer_details&id=' + encodeURIComponent(id))
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                const c = d.customer;
                $('#customerDetails').show();
                $('#custPhone').text(c.phone || '-');
                $('#custEmail').text(c.email || '-');
                $('#custGST').text(c.gst_number || '-');
                $('#custAddress').text(c.address || '-');
                // Auto-populate shipping address from customer's shipping_address field
                document.getElementById('shipping_address').value = c.shipping_address || '';
            });
    });
    $('#customerSelect').on('select2:clear', function() {
        $('#customerDetails').hide();
        // Clear shipping address when customer is cleared
        document.getElementById('shipping_address').value = '';
    });

    <?php if ($edit_invoice && $edit_invoice['customer_id'] > 0): ?>
    setTimeout(function() {
        const custId = <?php echo $edit_invoice['customer_id']; ?>;
        if (custId) {
            fetch('new-sale.php?ajax=customer_details&id=' + custId)
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) return;
                    const c = d.customer;
                    $('#customerDetails').show();
                    $('#custPhone').text(c.phone || '-');
                    $('#custEmail').text(c.email || '-');
                    $('#custGST').text(c.gst_number || '-');
                    $('#custAddress').text(c.address || '-');
                    // Auto-populate shipping address from customer's shipping_address field
                    document.getElementById('shipping_address').value = c.shipping_address || '';
                });
        }
    }, 500);
    <?php endif; ?>

    // Bulk category selection change
    $('#bulkCategorySelect').on('select2:select', function(e) {
        const selected = e.params.data;
        const stock = selected.element.dataset.stock || 0;
        document.getElementById('bulkMaxKgHint').innerHTML = `Available stock: ${parseFloat(stock).toFixed(2)} KG`;
        
        // Suggest selling price based on purchase price
        const purchasePrice = parseFloat(selected.element.dataset.purchasePrice || 0);
        if (purchasePrice > 0) {
            const suggestedPrice = (purchasePrice * 1.2).toFixed(2);
            document.getElementById('bulkSellingPrice').placeholder = `Suggested: ₹${suggestedPrice}`;
        }
        
        document.getElementById('bulkCategoryInfo').style.display = 'block';
        document.getElementById('bulkCategoryInfo').innerHTML = `<i class="bi bi-info-circle"></i> Selected: ${selected.text}`;
    });

    $('#bulkCategorySelect').on('select2:clear', function() {
        document.getElementById('bulkMaxKgHint').innerHTML = '';
        document.getElementById('bulkCategoryInfo').style.display = 'none';
    });

    // Add bulk category item
    document.getElementById('addBulkCategoryBtn').addEventListener('click', function() {
        const select = document.getElementById('bulkCategorySelect');
        const selected = select.options[select.selectedIndex];
        
        if (!select.value) {
            alert('Please select a category');
            return;
        }
        
        const sellingPrice = parseFloat(document.getElementById('bulkSellingPrice').value);
        if (!sellingPrice || sellingPrice <= 0) {
            alert('Please enter a valid selling price');
            return;
        }
        
        const kgQty = parseFloat(document.getElementById('bulkKgQuantity').value);
        if (!kgQty || kgQty <= 0) {
            alert('Please enter KG quantity');
            return;
        }
        
        const categoryId = select.value;
        const categoryName = selected.dataset.name;
        const purchasePrice = parseFloat(selected.dataset.purchasePrice || 0);
        const stock = parseFloat(selected.dataset.stock || 0);
        
        // Check if enough stock
        const isQuotation = tabQuotation.classList.contains('active');
        const isEdit = <?php echo $edit_id ? 'true' : 'false'; ?>;
        
        if (!isQuotation && !isEdit && kgQty > stock) {
            alert(`Insufficient stock! Available: ${stock.toFixed(2)} KG, Required: ${kgQty.toFixed(2)} KG`);
            return;
        }
        
        // Calculate total
        const totalAmount = kgQty * sellingPrice;
        
        // Use default GST rates - you can customize this as needed
        // For category sales, you might want to fetch GST rates from somewhere
        const cgst = 0;
        const sgst = 0;
        const isGstInvoice = document.getElementById('is_gst').value === '1';
        
        let taxableAmount = totalAmount;
        let cgstAmt = 0;
        let sgstAmt = 0;
        
        if (isGstInvoice && (cgst + sgst) > 0) {
            const totalGst = cgst + sgst;
            const gstFactor = 1 + (totalGst / 100);
            taxableAmount = totalAmount / gstFactor;
            const gstAmt = totalAmount - taxableAmount;
            cgstAmt = gstAmt / 2;
            sgstAmt = gstAmt / 2;
        }
        
        // Get default product ID from PHP
        const defaultProductId = <?php echo $default_product_id; ?>;
        const defaultProductName = '<?php echo $default_product_name; ?>';
        
        // Create item - ensure product_id is set to default product
        const newItem = {
            product_id: defaultProductId,
            product_name: defaultProductName,
            hsn_code: '',
            cat_id: parseInt(categoryId),
            cat_name: categoryName,
            unit: 'KG',
            qty: kgQty,
            pcs_per_bag: 0,
            rate: sellingPrice,
            converted_qty: kgQty, // No conversion, keep as KG
            no_of_pcs: kgQty,
            total: totalAmount,
            taxable: taxableAmount,
            cgst: cgst,
            sgst: sgst,
            cgst_amt: cgstAmt,
            sgst_amt: sgstAmt,
            is_category_sale: true // Flag to identify category sales
        };
        
        items.push(newItem);
        renderItems();
        
        // Clear inputs
        $('#bulkCategorySelect').val('').trigger('change');
        document.getElementById('bulkSellingPrice').value = '';
        document.getElementById('bulkKgQuantity').value = '';
        document.getElementById('bulkMaxKgHint').innerHTML = '';
        document.getElementById('bulkCategoryInfo').style.display = 'none';
    });

    function resetItemInputs() {
        selectedProduct = null;
        selectedCategory = null;
        $('#productMeta').text('');
        $('#categoryMeta').text('');

        const unitSelect = document.getElementById('unitSelect');
        unitSelect.innerHTML = '<option value="">Unit</option>';
        unitSelect.disabled = true;

        ['qtyInput','rateInput','pcsPerBagInput'].forEach(id => {
            const el = document.getElementById(id);
            el.value = '';
            el.disabled = true;
        });

        document.getElementById('conversionHint').textContent = 'Select product & category first';
        document.getElementById('autoConversionText').innerHTML = '<i class="bi bi-arrow-left-right"></i> Select product and category';
        document.getElementById('stockStatus').innerHTML = '';
        document.getElementById('lineTotalText').textContent = '₹0.00';
        document.getElementById('lineBreakdown').textContent = '';
        document.getElementById('addItemBtn').disabled = true;
    }

    function setupUnitsAndDefaults() {
        if (!selectedProduct || !selectedCategory) return;

        const m = selectedProduct.meta || {};
        const catMeta = selectedCategory.meta || {};
        const unitSelect = document.getElementById('unitSelect');

        unitSelect.innerHTML = '<option value="">Unit</option>';

        if (m.primary_unit && String(m.primary_unit).trim() !== '') {
            const op = document.createElement('option');
            op.value = String(m.primary_unit).trim();
            op.textContent = m.primary_unit;
            unitSelect.appendChild(op);
        }
        if (m.sec_unit && String(m.sec_unit).trim() !== '') {
            const op = document.createElement('option');
            op.value = String(m.sec_unit).trim();
            op.textContent = m.sec_unit;
            unitSelect.appendChild(op);
        }

        unitSelect.disabled = false;
        document.getElementById('qtyInput').disabled = false;
        document.getElementById('rateInput').disabled = false;
        document.getElementById('pcsPerBagInput').disabled = false;

        const defRate = parseFloat(catMeta.default_rate || 0);
        document.getElementById('rateInput').value = defRate > 0 ? defRate.toFixed(2) : '';

        const defaultPcsPerBag = parseFloat(m.sec_qty || 0);
        document.getElementById('pcsPerBagInput').value = defaultPcsPerBag > 0 ? defaultPcsPerBag.toFixed(3) : '';

        const pu = m.primary_unit || '';
        const su = m.sec_unit || '';
        if (defaultPcsPerBag > 0 && pu && su) {
            document.getElementById('conversionHint').textContent = `Default: 1 ${pu} = ${defaultPcsPerBag} ${su}`;
        } else {
            document.getElementById('conversionHint').textContent = 'No conversion ratio set for this product.';
        }

        recalcLine();
    }

    $('#productSelect').on('select2:select', function(e) {
        selectedProduct = e.params.data;
        const m = selectedProduct.meta || {};
        const isGst = document.getElementById('is_gst').value === '1';
        const gstTotal = isGst ? (parseFloat(m.cgst || 0) + parseFloat(m.sgst || 0)).toFixed(2) : '0.00';

        $('#productMeta').text(`HSN: ${m.hsn_code || 'N/A'} • GST: ${gstTotal}% • Units: ${m.primary_unit || '-'} / ${m.sec_unit || '-'}`);
        setupUnitsAndDefaults();
    });
    $('#productSelect').on('select2:clear', function() { resetItemInputs(); });

    $('#categorySelect').on('select2:select', function(e) {
        selectedCategory = e.params.data;
        const m = selectedCategory.meta || {};
        const modeText = document.getElementById('is_gst').value === '1' ? 'GST incl.' : 'Non-GST';
        $('#categoryMeta').text(`Rate/pcs: ₹${parseFloat(m.default_rate || 0).toFixed(2)} (${modeText}) • Stock: ${parseFloat(m.available_stock || 0).toFixed(2)} pcs`);
        setupUnitsAndDefaults();
    });
    $('#categorySelect').on('select2:clear', function() { resetItemInputs(); });

    function recalcLine() {
        if (!selectedProduct || !selectedCategory) return;

        const m = selectedProduct.meta || {};
        const catMeta = selectedCategory.meta || {};

        const unit = document.getElementById('unitSelect').value;
        const inputQty = parseFloat(document.getElementById('qtyInput').value || 0);
        const ratePerPiece = parseFloat(document.getElementById('rateInput').value || 0);
        const isGstInvoice = document.getElementById('is_gst').value === '1';

        if (!unit || inputQty <= 0 || ratePerPiece < 0) {
            document.getElementById('autoConversionText').innerHTML = '<i class="bi bi-arrow-left-right"></i> Enter valid qty and rate';
            document.getElementById('stockStatus').innerHTML = '';
            document.getElementById('lineTotalText').textContent = '₹0.00';
            document.getElementById('lineBreakdown').textContent = '';
            document.getElementById('addItemBtn').disabled = true;
            return;
        }

        const pu = String(m.primary_unit || '');
        const su = String(m.sec_unit || '');
        const defaultPcsPerBag = parseFloat(m.sec_qty || 0);
        const enteredPcsPerBag = parseFloat(document.getElementById('pcsPerBagInput').value || 0);
        const pcsPerBag = enteredPcsPerBag > 0 ? enteredPcsPerBag : defaultPcsPerBag;

        const cgst = isGstInvoice ? parseFloat(m.cgst || 0) : 0;
        const sgst = isGstInvoice ? parseFloat(m.sgst || 0) : 0;
        const totalGst = cgst + sgst;

        const availableStock = parseFloat(catMeta.available_stock || 0);

        let convertedQty = inputQty;
        let conversionText = '';

        if (unit === pu) {
            if (pcsPerBag <= 0) {
                convertedQty = 0;
                conversionText = '<i class="bi bi-exclamation-triangle"></i> Enter valid Pcs/Bag';
            } else {
                convertedQty = inputQty * pcsPerBag;
                conversionText = `<i class="bi bi-arrow-left-right"></i> <strong>${inputQty} ${escapeHtml(pu)}</strong> = <strong>${convertedQty.toFixed(2)} ${escapeHtml(su || 'pcs')}</strong><br><small>1 ${escapeHtml(pu)} = ${pcsPerBag.toFixed(3)} ${escapeHtml(su || 'pcs')}</small>`;
            }
        } else if (unit === su) {
            convertedQty = inputQty;
            conversionText = `<i class="bi bi-check-circle"></i> <strong>${inputQty} ${escapeHtml(su)}</strong> (direct pieces)<br><small>1 ${escapeHtml(pu || 'bag')} = ${pcsPerBag > 0 ? pcsPerBag.toFixed(3) : 'N/A'} ${escapeHtml(su)}</small>`;
        } else {
            convertedQty = 0;
            conversionText = '<i class="bi bi-exclamation-triangle"></i> Unit not recognized';
        }

        const isQuotation = tabQuotation.classList.contains('active');
        const isEdit = <?php echo $edit_id ? 'true' : 'false'; ?>;
        
        if (!isQuotation && !isEdit && convertedQty > availableStock) {
            document.getElementById('stockStatus').innerHTML =
                `<div class="stock-warning"><i class="bi bi-exclamation-triangle-fill"></i> Insufficient stock! Available: ${availableStock.toFixed(2)} pcs, Required: ${convertedQty.toFixed(2)} pcs</div>`;
            document.getElementById('addItemBtn').disabled = true;
        } else if (convertedQty > 0) {
            let stockMsg = '';
            if (isQuotation) {
                stockMsg = `<div class="stock-ok"><i class="bi bi-info-circle-fill"></i> Quotation mode: Stock not deducted</div>`;
            } else if (isEdit) {
                stockMsg = `<div class="stock-ok"><i class="bi bi-info-circle-fill"></i> Edit mode: Stock not checked</div>`;
            } else {
                stockMsg = `<div class="stock-ok"><i class="bi bi-check-circle-fill"></i> Stock available: ${availableStock.toFixed(2)} pcs</div>`;
            }
            document.getElementById('stockStatus').innerHTML = stockMsg;
            document.getElementById('addItemBtn').disabled = false;
        } else {
            document.getElementById('stockStatus').innerHTML = '';
            document.getElementById('addItemBtn').disabled = true;
        }

        const totalAmount = convertedQty * ratePerPiece;
        let taxableAmount = totalAmount;
        let cgstAmt = 0, sgstAmt = 0, finalTotal = totalAmount;

        if (isGstInvoice && totalGst > 0) {
            const factor = 1 + (totalGst / 100);
            taxableAmount = totalAmount / factor;
            const gstAmount = totalAmount - taxableAmount;
            cgstAmt = gstAmount / 2;
            sgstAmt = gstAmount / 2;
            finalTotal = totalAmount;
        }

        document.getElementById('autoConversionText').innerHTML = conversionText;
        document.getElementById('lineTotalText').textContent = '₹' + finalTotal.toFixed(2);

        let breakdown = `${inputQty} ${unit}`;
        if (unit === pu && pcsPerBag > 0) {
            breakdown += ` × ${pcsPerBag.toFixed(3)} = ${convertedQty.toFixed(2)} pcs`;
        } else {
            breakdown += ` = ${convertedQty.toFixed(2)} pcs`;
        }
        breakdown += `\n${convertedQty.toFixed(2)} pcs × ₹${ratePerPiece.toFixed(2)} = ₹${totalAmount.toFixed(2)}`;

        if (isGstInvoice) {
            breakdown += ` (GST inclusive)`;
            breakdown += `\nTaxable: ₹${taxableAmount.toFixed(2)}`;
            breakdown += `\nCGST (${cgst}%): ₹${cgstAmt.toFixed(2)} | SGST (${sgst}%): ₹${sgstAmt.toFixed(2)}`;
        } else {
            breakdown += ` (Non-GST)`;
        }
        breakdown += `\nLine Total: ₹${finalTotal.toFixed(2)}`;

        document.getElementById('lineBreakdown').textContent = breakdown;
    }

    ['unitSelect','qtyInput','rateInput','pcsPerBagInput'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', recalcLine);
            el.addEventListener('change', recalcLine);
        }
    });

    function renderItems() {
        const tbody = document.querySelector('#itemsTable tbody');
        tbody.innerHTML = '';

        if (items.length === 0) {
            tbody.innerHTML = `<tr id="noItemsRow"><td colspan="8" class="text-center text-muted py-3">No items added yet</td></tr>`;
            document.getElementById('itemCount').textContent = '0';
        } else {
            items.forEach((it, idx) => {
                const isCategorySale = it.is_category_sale === true;
                const displayName = isCategorySale ? it.cat_name : (it.product_name + ' - ' + it.cat_name);
                const typeBadge = isCategorySale ? 
                    '<span class="badge-category-item">Category (KG)</span>' : 
                    '<span class="badge-unit">Product</span>';
                
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${idx + 1}</td>
                    <td>${typeBadge}</td>
                    <td>${escapeHtml(displayName)}</td>
                    <td><span class="badge-unit">${escapeHtml(it.unit)}</span></td>
                    <td class="text-end">${Number(it.qty).toFixed(3)}</td>
                    <td class="text-end">₹${Number(it.rate).toFixed(2)}</td>
                    <td class="text-end fw-bold">₹${Number(it.total).toFixed(2)}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-outline-danger btn-sm" data-remove="${idx}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
            document.getElementById('itemCount').textContent = String(items.length);
        }

        updateSessionCart();
        updateInvoiceSummary();
    }

    function updateSessionCart() {
        fetch('update_cart.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ items: items })
        }).catch(error => console.error('Error updating cart:', error));
    }

    document.getElementById('addItemBtn').addEventListener('click', function() {
        if (!selectedProduct || !selectedCategory) {
            alert('Please select product and category.');
            return;
        }

        const pm = selectedProduct.meta || {};
        const cm = selectedCategory.meta || {};

        const unit = String(document.getElementById('unitSelect').value || '').trim();
        const qty = parseFloat(document.getElementById('qtyInput').value || 0);
        const ratePerPiece = parseFloat(document.getElementById('rateInput').value || 0);
        const pcsPerBag = parseFloat(document.getElementById('pcsPerBagInput').value || 0);
        const isGstInvoice = document.getElementById('is_gst').value === '1';

        if (!unit) { alert('Please select unit.'); return; }
        if (qty <= 0) { alert('Please enter valid quantity.'); return; }
        if (ratePerPiece < 0) { alert('Please enter valid rate.'); return; }

        const pu = String(pm.primary_unit || '');
        const defaultPcsPerBag = parseFloat(pm.sec_qty || 0);
        const finalPcsPerBag = pcsPerBag > 0 ? pcsPerBag : defaultPcsPerBag;
        
        let convertedQty = qty;
        if (unit === pu && finalPcsPerBag > 0) {
            convertedQty = qty * finalPcsPerBag;
        } else {
            convertedQty = qty;
        }

        if (convertedQty <= 0) {
            alert('Invalid conversion quantity.');
            return;
        }

        const availableStock = parseFloat(cm.available_stock || 0);
        const isQuotation = tabQuotation.classList.contains('active');
        const isEdit = <?php echo $edit_id ? 'true' : 'false'; ?>;
        
        if (!isQuotation && !isEdit && convertedQty > availableStock) {
            alert('Insufficient stock!');
            return;
        }

        const cgst = isGstInvoice ? parseFloat(pm.cgst || 0) : 0;
        const sgst = isGstInvoice ? parseFloat(pm.sgst || 0) : 0;
        const totalGst = cgst + sgst;

        const totalAmount = convertedQty * ratePerPiece;
        let taxableAmount = totalAmount;
        let cgstAmt = 0, sgstAmt = 0, finalTotal = totalAmount;

        if (isGstInvoice && totalGst > 0) {
            const gstFactor = 1 + (totalGst / 100);
            taxableAmount = totalAmount / gstFactor;
            const gstAmt = totalAmount - taxableAmount;
            cgstAmt = gstAmt / 2;
            sgstAmt = gstAmt / 2;
        }

        const newItem = {
            product_id: Number(selectedProduct.id),
            product_name: pm.product_name || selectedProduct.text || '',
            hsn_code: pm.hsn_code || '',
            cat_id: Number(selectedCategory.id),
            cat_name: cm.category_name || selectedCategory.text || '',
            unit: unit,
            qty: qty,
            pcs_per_bag: finalPcsPerBag,
            rate: ratePerPiece,
            converted_qty: convertedQty,
            no_of_pcs: convertedQty,
            total: finalTotal,
            taxable: taxableAmount,
            cgst: cgst,
            sgst: sgst,
            cgst_amt: cgstAmt,
            sgst_amt: sgstAmt,
            is_category_sale: false // Regular product sale
        };

        items.push(newItem);
        renderItems();

        document.getElementById('qtyInput').value = '';
        recalcLine();
    });

    document.querySelector('#itemsTable tbody').addEventListener('click', function(e) {
        const btn = e.target.closest('[data-remove]');
        if (btn) {
            const idx = parseInt(btn.getAttribute('data-remove'), 10);
            if (!isNaN(idx)) {
                items.splice(idx, 1);
                renderItems();
            }
        }
    });

    function updatePaymentSplitSummary() {
        const ids = ['cash_amount','upi_amount','card_amount','bank_amount','cheque_amount','credit_amount'];
        let total = 0;
        ids.forEach(id => {
            const el = document.getElementById(id);
            total += parseFloat(el?.value || 0);
        });
        const el = document.getElementById('paymentSplitSummary');
        if (el) el.textContent = `Split total: ₹${total.toFixed(2)}`;
    }
    document.querySelectorAll('.pay-input').forEach(el => {
        el.addEventListener('input', updatePaymentSplitSummary);
        el.addEventListener('change', updatePaymentSplitSummary);
    });
    updatePaymentSplitSummary();

    function calculateShipping() {
        // Only calculate shipping if in invoice mode
        if (!tabInvoice.classList.contains('active')) {
            return;
        }
        
        const shippingCharges = parseFloat(document.getElementById('shipping_charges')?.value || 0);
        const shippingGstRate = parseFloat(document.getElementById('shipping_gst')?.value || 0);
        const isGstInvoice = document.getElementById('is_gst').value === '1';
        
        if (shippingCharges > 0 && isGstInvoice) {
            const cgstRate = shippingGstRate / 2;
            const sgstRate = shippingGstRate / 2;
            
            const gstFactor = 1 + (shippingGstRate / 100);
            const taxableShipping = shippingCharges / gstFactor;
            const gstAmount = shippingCharges - taxableShipping;
            const cgstAmount = gstAmount / 2;
            const sgstAmount = gstAmount / 2;
            
            document.getElementById('shippingCgstRate').textContent = cgstRate.toFixed(2);
            document.getElementById('shippingSgstRate').textContent = sgstRate.toFixed(2);
            document.getElementById('shippingCgstAmount').textContent = '₹' + cgstAmount.toFixed(2);
            document.getElementById('shippingSgstAmount').textContent = '₹' + sgstAmount.toFixed(2);
            document.getElementById('shippingTotal').textContent = '₹' + shippingCharges.toFixed(2);
            
            if (!document.getElementById('shipping_cgst')) {
                const shippingCgst = document.createElement('input');
                shippingCgst.type = 'hidden';
                shippingCgst.name = 'shipping_cgst';
                shippingCgst.id = 'shipping_cgst';
                shippingCgst.value = cgstRate.toFixed(2);
                document.getElementById('saleForm').appendChild(shippingCgst);
                
                const shippingSgst = document.createElement('input');
                shippingSgst.type = 'hidden';
                shippingSgst.name = 'shipping_sgst';
                shippingSgst.id = 'shipping_sgst';
                shippingSgst.value = sgstRate.toFixed(2);
                document.getElementById('saleForm').appendChild(shippingSgst);
                
                const shippingCgstAmt = document.createElement('input');
                shippingCgstAmt.type = 'hidden';
                shippingCgstAmt.name = 'shipping_cgst_amount';
                shippingCgstAmt.id = 'shipping_cgst_amount';
                shippingCgstAmt.value = cgstAmount.toFixed(2);
                document.getElementById('saleForm').appendChild(shippingCgstAmt);
                
                const shippingSgstAmt = document.createElement('input');
                shippingSgstAmt.type = 'hidden';
                shippingSgstAmt.name = 'shipping_sgst_amount';
                shippingSgstAmt.id = 'shipping_sgst_amount';
                shippingSgstAmt.value = sgstAmount.toFixed(2);
                document.getElementById('saleForm').appendChild(shippingSgstAmt);
                
                const shippingTotal = document.createElement('input');
                shippingTotal.type = 'hidden';
                shippingTotal.name = 'shipping_total';
                shippingTotal.id = 'shipping_total';
                shippingTotal.value = shippingCharges.toFixed(2);
                document.getElementById('saleForm').appendChild(shippingTotal);
            } else {
                document.getElementById('shipping_cgst').value = cgstRate.toFixed(2);
                document.getElementById('shipping_sgst').value = sgstRate.toFixed(2);
                document.getElementById('shipping_cgst_amount').value = cgstAmount.toFixed(2);
                document.getElementById('shipping_sgst_amount').value = sgstAmount.toFixed(2);
                document.getElementById('shipping_total').value = shippingCharges.toFixed(2);
            }
            
            document.getElementById('shippingBreakdown').style.display = 'block';
            document.getElementById('shippingChargesDisplay').textContent = '₹' + shippingCharges.toFixed(2);
        } else {
            document.getElementById('shippingBreakdown').style.display = 'none';
            if (document.getElementById('shipping_cgst')) {
                document.getElementById('shipping_cgst').value = '0';
                document.getElementById('shipping_sgst').value = '0';
                document.getElementById('shipping_cgst_amount').value = '0';
                document.getElementById('shipping_sgst_amount').value = '0';
                document.getElementById('shipping_total').value = shippingCharges > 0 ? shippingCharges.toFixed(2) : '0';
            }
        }
        
        updateInvoiceSummary();
    }

    document.getElementById('sameAsBillingAddress')?.addEventListener('change', function(e) {
        if (this.checked) {
            const custAddress = document.getElementById('custAddress')?.textContent;
            if (custAddress && custAddress !== '-') {
                document.getElementById('shipping_address').value = custAddress;
            } else {
                const manualAddress = document.getElementById('m_address')?.value;
                if (manualAddress) {
                    document.getElementById('shipping_address').value = manualAddress;
                }
            }
        } else {
            document.getElementById('shipping_address').value = '';
        }
    });

    ['shipping_charges', 'shipping_gst'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', calculateShipping);
            el.addEventListener('change', calculateShipping);
        }
    });

    document.getElementById('saleForm').addEventListener('submit', function(e) {
        if (items.length === 0) {
            e.preventDefault();
            alert('Please add at least one item.');
            return false;
        }

        for (let i = 0; i < items.length; i++) {
            if (!(Number(items[i].qty) > 0)) {
                e.preventDefault();
                alert('Invalid item quantity detected.');
                return false;
            }
            // Ensure product_id is set for all items
            if (!items[i].product_id || items[i].product_id <= 0) {
                items[i].product_id = <?php echo $default_product_id; ?>;
                items[i].product_name = '<?php echo $default_product_name; ?>';
            }
        }

        // Validate bank account for UPI or Bank payments only in invoice mode
        if (tabInvoice.classList.contains('active')) {
            const upiAmount = parseFloat(document.getElementById('upi_amount')?.value || 0);
            const bankAmount = parseFloat(document.getElementById('bank_amount')?.value || 0);
            const bankAccountId = document.getElementById('bank_account_id')?.value;
            
            if ((upiAmount > 0 || bankAmount > 0) && !bankAccountId) {
                e.preventDefault();
                alert('Please select a bank account for UPI or Bank payments.');
                return false;
            }
        }

        document.getElementById('items_json').value = JSON.stringify(items);
    });

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    renderItems();
    calculateShipping();
    updateInvoiceSummary();

    setTimeout(function() {
        const grandTotalEl = document.getElementById('grandTotalText');
        if (grandTotalEl) {
            const grandTotal = parseFloat(grandTotalEl.textContent.replace('₹', '') || 0);
            autoFillPayments(grandTotal);
        }
    }, 500);
})();
</script>
</body>
</html>