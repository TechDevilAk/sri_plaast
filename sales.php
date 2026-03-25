<?php
// sales.php (FULL UPDATED CODE WITH ENHANCED EXPORT FUNCTIONALITY)
session_start();
$currentPage = 'sales';
$pageTitle = 'Sales';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view sales
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Helper function to build query string with current filters
function buildQueryString($exclude = []) {
    $params = $_GET;
    
    // List of all possible filter parameters
    $allFilters = [
        'filter_date', 'filter_customer', 'filter_payment', 'filter_status',
        'filter_eway_bill', 'filter_dispatched', 'filter_destination',
        'filter_invoice_no', 'filter_date_from', 'filter_date_to',
        'filter_min_amount', 'filter_max_amount', 'filter_month'
    ];
    
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    
    // Only include filter parameters that exist
    $filteredParams = [];
    foreach ($params as $key => $value) {
        if (in_array($key, $allFilters) && !empty($value)) {
            $filteredParams[$key] = $value;
        }
    }
    
    return count($filteredParams) ? '?' . http_build_query($filteredParams) : '';
}

// Status badge helper
function getPaymentStatus($pending) {
    if ($pending == 0) {
        return ['class' => 'completed', 'text' => 'Paid', 'icon' => 'bi-check-circle'];
    } else {
        return ['class' => 'pending', 'text' => 'Pending', 'icon' => 'bi-clock-history'];
    }
}

// Payment method badge helper
function getPaymentMethodBadge($method) {
    switch($method) {
        case 'cash':
            return ['class' => 'success', 'icon' => 'bi-cash-stack', 'text' => 'Cash'];
        case 'card':
            return ['class' => 'primary', 'icon' => 'bi-credit-card', 'text' => 'Card'];
        case 'upi':
            return ['class' => 'info', 'icon' => 'bi-phone', 'text' => 'UPI'];
        case 'bank':
            return ['class' => 'warning', 'icon' => 'bi-bank', 'text' => 'Bank'];
        case 'credit':
            return ['class' => 'danger', 'icon' => 'bi-clock-history', 'text' => 'Credit'];
        case 'mixed':
            return ['class' => 'secondary', 'icon' => 'bi-shuffle', 'text' => 'Mixed'];
        default:
            return ['class' => 'secondary', 'icon' => 'bi-question-circle', 'text' => ucfirst((string)$method)];
    }
}

// Check if user is admin for certain actions
$is_admin = ($_SESSION['user_role'] === 'admin');

// -------------------------
// Handle payment collection
// -------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'collect_payment' &&
    isset($_POST['invoice_id']) &&
    is_numeric($_POST['invoice_id'])
) {

    $invoice_id = (int)$_POST['invoice_id'];
    $payment_amount = (float)($_POST['payment_amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';

    if ($payment_amount <= 0) {
        $error = "Please enter a valid payment amount.";
    } else {
        $invoice_query = $conn->prepare("SELECT * FROM invoice WHERE id = ?");
        $invoice_query->bind_param("i", $invoice_id);
        $invoice_query->execute();
        $invoice = $invoice_query->get_result()->fetch_assoc();

        if (!$invoice) {
            $error = "Invoice not found.";
        } elseif ((float)$invoice['pending_amount'] <= 0) {
            $error = "No pending amount for this invoice.";
        } elseif ($payment_amount > (float)$invoice['pending_amount']) {
            $error = "Payment amount exceeds pending amount. Pending: ₹" . number_format((float)$invoice['pending_amount'], 2);
        } else {
            $conn->begin_transaction();

            try {
                $new_pending = (float)$invoice['pending_amount'] - $payment_amount;
                if ($new_pending < 0) $new_pending = 0;

                // NOTE: Your schema has cash_received + split fields.
                // Keeping your original behavior: only pending is updated.
                $update = $conn->prepare("UPDATE invoice SET pending_amount = ? WHERE id = ?");
                $update->bind_param("di", $new_pending, $invoice_id);

                if (!$update->execute()) {
                    throw new Exception("Failed to update invoice.");
                }

                $log_desc = "Payment collected of ₹" . number_format($payment_amount, 2) .
                            " for invoice #" . $invoice['inv_num'] .
                            ". Remaining pending: ₹" . number_format($new_pending, 2);

                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'payment', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();

                $conn->commit();
                $success = "Payment of ₹" . number_format($payment_amount, 2) . " collected successfully.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

// -------------------------
// Handle invoice cancellation
// -------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'cancel_invoice' &&
    isset($_POST['invoice_id']) &&
    is_numeric($_POST['invoice_id'])
) {

    if ($_SESSION['user_role'] !== 'admin') {
        $error = "Only admins can cancel invoices.";
    } else {
        $invoice_id = (int)$_POST['invoice_id'];

        $invoice_query = $conn->prepare("SELECT * FROM invoice WHERE id = ?");
        $invoice_query->bind_param("i", $invoice_id);
        $invoice_query->execute();
        $invoice = $invoice_query->get_result()->fetch_assoc();

        if (!$invoice) {
            $error = "Invoice not found.";
        } else {
            $conn->begin_transaction();

            try {
                // Restore stock based on PCS (no_of_pcs), fallback to quantity if pcs missing
                $items_query = $conn->prepare("SELECT * FROM invoice_item WHERE invoice_id = ?");
                $items_query->bind_param("i", $invoice_id);
                $items_query->execute();
                $items = $items_query->get_result();

                while ($item = $items->fetch_assoc()) {
                    if (!empty($item['cat_id'])) {
                        $restore_qty = (float)($item['no_of_pcs'] ?? 0);
                        if ($restore_qty <= 0) $restore_qty = (float)($item['quantity'] ?? 0);

                        $update_stock = $conn->prepare("UPDATE category SET total_quantity = total_quantity + ? WHERE id = ?");
                        $update_stock->bind_param("di", $restore_qty, $item['cat_id']);
                        $update_stock->execute();
                    }
                }

                $delete_items = $conn->prepare("DELETE FROM invoice_item WHERE invoice_id = ?");
                $delete_items->bind_param("i", $invoice_id);
                $delete_items->execute();

                $delete_invoice = $conn->prepare("DELETE FROM invoice WHERE id = ?");
                $delete_invoice->bind_param("i", $invoice_id);
                $delete_invoice->execute();

                $log_desc = "Cancelled invoice #" . $invoice['inv_num'] . " (₹" . number_format((float)$invoice['total'], 2) . ")";
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'cancel', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();

                $conn->commit();
                $success = "Invoice cancelled successfully. Stock has been restored.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to cancel invoice: " . $e->getMessage();
            }
        }
    }
}

// -------------------------
// Handle Export Functionality - ENHANCED
// -------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['excel', 'csv', 'pdf', 'monthly_excel', 'monthly_csv', 'monthly_pdf'])) {
    $export_type = $_GET['export'];
    
    // Get the same filtered data as the main query
    $filterDate     = $_GET['filter_date'] ?? date('Y-m-d');
    $filterCustomer = $_GET['filter_customer'] ?? '';
    $filterPayment  = $_GET['filter_payment'] ?? '';
    $filterStatus   = $_GET['filter_status'] ?? '';
    $filterMonth    = $_GET['filter_month'] ?? date('Y-m');
    
    // Advanced filters
    $filterEwayBill = $_GET['filter_eway_bill'] ?? '';
    $filterDispatched = $_GET['filter_dispatched'] ?? '';
    $filterDestination = $_GET['filter_destination'] ?? '';
    $filterInvoiceNo = $_GET['filter_invoice_no'] ?? '';
    $filterDateFrom = $_GET['filter_date_from'] ?? '';
    $filterDateTo = $_GET['filter_date_to'] ?? '';
    $filterMinAmount = $_GET['filter_min_amount'] ?? '';
    $filterMaxAmount = $_GET['filter_max_amount'] ?? '';

    $where  = "1=1";
    $params = [];
    $types  = "";

    // Check if this is a monthly export
    $isMonthly = strpos($export_type, 'monthly_') === 0;
    
    if ($isMonthly) {
        // Monthly export uses the month filter
        if (!empty($filterMonth)) {
            $year = substr($filterMonth, 0, 4);
            $month = substr($filterMonth, 5, 2);
            $where .= " AND YEAR(i.created_at) = ? AND MONTH(i.created_at) = ?";
            $params[] = $year;
            $params[] = $month;
            $types .= "ii";
        }
    } else {
        // Regular export with all filters
        // Basic filters
        if ($filterDate && $filterDate !== 'all') {
            $where .= " AND DATE(i.created_at) = ?";
            $params[] = $filterDate;
            $types .= "s";
        }

        if ($filterCustomer && $filterCustomer !== 'all') {
            $where .= " AND i.customer_id = ?";
            $params[] = (int)$filterCustomer;
            $types .= "i";
        }

        if ($filterPayment && $filterPayment !== 'all') {
            $where .= " AND i.payment_method = ?";
            $params[] = $filterPayment;
            $types .= "s";
        }

        if ($filterStatus && $filterStatus !== 'all') {
            if ($filterStatus === 'paid') {
                $where .= " AND i.pending_amount = 0";
            } elseif ($filterStatus === 'pending') {
                $where .= " AND i.pending_amount > 0";
            } elseif ($filterStatus === 'overdue') {
                $where .= " AND i.pending_amount > 0 AND DATE(i.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            }
        }

        // Advanced filters
        if (!empty($filterEwayBill)) {
            $where .= " AND i.eway_bill_no LIKE ?";
            $params[] = "%$filterEwayBill%";
            $types .= "s";
        }

        if (!empty($filterDispatched)) {
            $where .= " AND i.dispatched_through LIKE ?";
            $params[] = "%$filterDispatched%";
            $types .= "s";
        }

        if (!empty($filterDestination)) {
            $where .= " AND i.destination LIKE ?";
            $params[] = "%$filterDestination%";
            $types .= "s";
        }

        if (!empty($filterInvoiceNo)) {
            $where .= " AND i.inv_num LIKE ?";
            $params[] = "%$filterInvoiceNo%";
            $types .= "s";
        }

        if (!empty($filterDateFrom)) {
            $where .= " AND DATE(i.created_at) >= ?";
            $params[] = $filterDateFrom;
            $types .= "s";
        }

        if (!empty($filterDateTo)) {
            $where .= " AND DATE(i.created_at) <= ?";
            $params[] = $filterDateTo;
            $types .= "s";
        }

        if (!empty($filterMinAmount)) {
            $where .= " AND i.total >= ?";
            $params[] = (float)$filterMinAmount;
            $types .= "d";
        }

        if (!empty($filterMaxAmount)) {
            $where .= " AND i.total <= ?";
            $params[] = (float)$filterMaxAmount;
            $types .= "d";
        }
    }

    // Get data for export
    $sql = "
        SELECT 
            i.id,
            i.inv_num,
            i.created_at,
            i.eway_bill_no,
            i.dispatched_through,
            i.destination,
            c.customer_name,
            c.phone,
            c.gst_number,
            i.subtotal,
            i.cgst_amount,
            i.sgst_amount,
            (i.cgst_amount + i.sgst_amount) as tax_total,
            i.total,
            i.payment_method,
            i.pending_amount,
            COALESCE(pf.profit, 0) AS profit_amount,
            CASE 
                WHEN i.pending_amount = 0 THEN 'Paid'
                WHEN i.pending_amount > 0 AND DATE(i.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 'Overdue'
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
        ORDER BY i.created_at DESC
    ";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    // Prepare data array
    $data = [];
    $total_sum = 0;
    $total_profit = 0;
    $total_pending = 0;
    $total_cgst = 0;
    $total_sgst = 0;
    $total_tax = 0;
    $total_subtotal = 0;
    
    // Payment method totals
    $payment_totals = [
        'cash' => 0,
        'card' => 0,
        'upi' => 0,
        'bank' => 0,
        'credit' => 0,
        'mixed' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $status = $row['payment_status'];
        $data[] = [
            'ID' => $row['id'],
            'Invoice No' => $row['inv_num'],
            'Date' => date('d-m-Y H:i', strtotime($row['created_at'])),
            'Customer Name' => $row['customer_name'] ?: 'Walk-in Customer',
            'Phone' => $row['phone'] ?: '-',
            'GST No' => $row['gst_number'] ?: '-',
            'E-Way Bill' => $row['eway_bill_no'] ?: '-',
            'Dispatched Through' => $row['dispatched_through'] ?: '-',
            'Destination' => $row['destination'] ?: '-',
            'Subtotal' => $row['subtotal'],
            'CGST' => $row['cgst_amount'],
            'SGST' => $row['sgst_amount'],
            'Tax Total' => $row['tax_total'],
            'Total' => $row['total'],
            'Payment Method' => ucfirst($row['payment_method']),
            'Status' => $status,
            'Pending Amount' => $row['pending_amount'],
            'Profit' => $row['profit_amount']
        ];
        
        $total_sum += $row['total'];
        $total_profit += $row['profit_amount'];
        $total_pending += $row['pending_amount'];
        $total_cgst += $row['cgst_amount'];
        $total_sgst += $row['sgst_amount'];
        $total_tax += $row['tax_total'];
        $total_subtotal += $row['subtotal'];
        
        // Add to payment method totals
        $method = $row['payment_method'];
        if (isset($payment_totals[$method])) {
            $payment_totals[$method] += $row['total'];
        }
    }

    // Get month name for filename
    $monthName = '';
    if ($isMonthly && !empty($filterMonth)) {
        $timestamp = strtotime($filterMonth . '-01');
        $monthName = date('F_Y', $timestamp);
    }

    // Handle different export types
    $base_export_type = str_replace('monthly_', '', $export_type);
    
    switch($base_export_type) {
        case 'excel':
        case 'csv':
            // Set headers for CSV download
            $filename = $isMonthly ? "sales_report_{$monthName}" : "sales_report_" . date('Y-m-d');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            // Create output stream
            $output = fopen('php://output', 'w');
            
            // Add UTF-8 BOM for Excel compatibility
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Add report header with filters
            fputcsv($output, ['SALES REPORT']);
            fputcsv($output, ['Generated on: ' . date('d-m-Y H:i:s')]);
            
            // Add filter information
            fputcsv($output, ['FILTERS APPLIED:']);
            $filter_info = [];
            if ($isMonthly) {
                $filter_info[] = "Month: " . date('F Y', strtotime($filterMonth . '-01'));
            } else {
                if ($filterDate && $filterDate != 'all') $filter_info[] = "Date: $filterDate";
                if ($filterDateFrom && $filterDateTo) $filter_info[] = "Date Range: $filterDateFrom to $filterDateTo";
                if ($filterCustomer && $filterCustomer != 'all') $filter_info[] = "Customer ID: $filterCustomer";
                if ($filterPayment && $filterPayment != 'all') $filter_info[] = "Payment Method: " . ucfirst($filterPayment);
                if ($filterStatus && $filterStatus != 'all') $filter_info[] = "Status: " . ucfirst($filterStatus);
                if ($filterMinAmount || $filterMaxAmount) {
                    $range = ($filterMinAmount ? '₹'.$filterMinAmount : 'Any') . ' - ' . ($filterMaxAmount ? '₹'.$filterMaxAmount : 'Any');
                    $filter_info[] = "Amount Range: $range";
                }
                if ($filterEwayBill) $filter_info[] = "E-Way Bill: $filterEwayBill";
                if ($filterDispatched) $filter_info[] = "Dispatched Through: $filterDispatched";
                if ($filterDestination) $filter_info[] = "Destination: $filterDestination";
                if ($filterInvoiceNo) $filter_info[] = "Invoice No: $filterInvoiceNo";
            }
            
            if (empty($filter_info)) {
                fputcsv($output, ['No filters applied - All records']);
            } else {
                foreach ($filter_info as $info) {
                    fputcsv($output, [$info]);
                }
            }
            
            fputcsv($output, []); // Empty row
            
            // Add headers
            if (!empty($data)) {
                fputcsv($output, array_keys($data[0]));
                
                // Add data rows
                foreach ($data as $row) {
                    // Format numeric values
                    $formatted_row = $row;
                    $formatted_row['Subtotal'] = '₹' . number_format($row['Subtotal'], 2);
                    $formatted_row['CGST'] = '₹' . number_format($row['CGST'], 2);
                    $formatted_row['SGST'] = '₹' . number_format($row['SGST'], 2);
                    $formatted_row['Tax Total'] = '₹' . number_format($row['Tax Total'], 2);
                    $formatted_row['Total'] = '₹' . number_format($row['Total'], 2);
                    $formatted_row['Pending Amount'] = '₹' . number_format($row['Pending Amount'], 2);
                    $formatted_row['Profit'] = '₹' . number_format($row['Profit'], 2);
                    fputcsv($output, $formatted_row);
                }
                
                fputcsv($output, []); // Empty row
                
                // Add summary section
                fputcsv($output, ['SUMMARY']);
                fputcsv($output, ['Total Invoices:', count($data)]);
                fputcsv($output, ['Total Subtotal:', '₹' . number_format($total_subtotal, 2)]);
                fputcsv($output, ['Total CGST:', '₹' . number_format($total_cgst, 2)]);
                fputcsv($output, ['Total SGST:', '₹' . number_format($total_sgst, 2)]);
                fputcsv($output, ['Total Tax:', '₹' . number_format($total_tax, 2)]);
                fputcsv($output, ['Total Sales:', '₹' . number_format($total_sum, 2)]);
                fputcsv($output, ['Total Profit:', '₹' . number_format($total_profit, 2)]);
                fputcsv($output, ['Total Pending:', '₹' . number_format($total_pending, 2)]);
                fputcsv($output, ['Net Collected:', '₹' . number_format($total_sum - $total_pending, 2)]);
                
                fputcsv($output, []); // Empty row
                
                // Add payment method breakdown
                fputcsv($output, ['PAYMENT METHOD BREAKDOWN']);
                foreach ($payment_totals as $method => $amount) {
                    if ($amount > 0) {
                        $percentage = ($amount / $total_sum) * 100;
                        fputcsv($output, [ucfirst($method) . ':', '₹' . number_format($amount, 2), number_format($percentage, 1) . '%']);
                    }
                }
            }
            
            fclose($output);
            exit;
            
        case 'pdf':
            // For PDF export, we'll use HTML to PDF approach
            ob_start();
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Sales Report</title>
                <style>
                    body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
                    h1 { color: #2463eb; text-align: center; margin-bottom: 5px; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .filters { background: #f8fafc; padding: 15px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #2463eb; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 11px; }
                    th { background: #2463eb; color: white; padding: 8px; text-align: left; }
                    td { border: 1px solid #ddd; padding: 6px; }
                    tr:nth-child(even) { background: #f8fafc; }
                    .summary { background: #e8f2ff; padding: 20px; border-radius: 8px; margin: 20px 0; }
                    .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
                    .summary-item { background: white; padding: 12px; border-radius: 6px; text-align: center; }
                    .summary-label { font-size: 11px; color: #64748b; text-transform: uppercase; }
                    .summary-value { font-size: 18px; font-weight: bold; color: #1e293b; }
                    .text-right { text-align: right; }
                    .footer { text-align: center; margin-top: 30px; font-size: 10px; color: #64748b; }
                    .payment-breakdown { margin-top: 20px; }
                    .payment-item { display: flex; justify-content: space-between; padding: 8px; border-bottom: 1px dashed #ddd; }
                    .badge { padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; }
                    .badge-paid { background: #dcfce7; color: #16a34a; }
                    .badge-pending { background: #fee2e2; color: #dc2626; }
                    .badge-overdue { background: #ffedd5; color: #c2410c; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>SALES REPORT</h1>
                    <p>Generated on: <?php echo date('d-m-Y H:i:s'); ?></p>
                    <?php if ($isMonthly && !empty($filterMonth)): ?>
                        <h2 style="color: #475569;">Month: <?php echo date('F Y', strtotime($filterMonth . '-01')); ?></h2>
                    <?php endif; ?>
                </div>
                
                <div class="filters">
                    <strong style="display: block; margin-bottom: 8px;">📊 Applied Filters:</strong>
                    <?php if ($isMonthly): ?>
                        <div>• Month: <?php echo date('F Y', strtotime($filterMonth . '-01')); ?></div>
                    <?php else: ?>
                        <?php if ($filterDate && $filterDate != 'all'): ?>
                            <div>• Date: <?php echo $filterDate; ?></div>
                        <?php endif; ?>
                        <?php if ($filterDateFrom && $filterDateTo): ?>
                            <div>• Date Range: <?php echo $filterDateFrom; ?> to <?php echo $filterDateTo; ?></div>
                        <?php endif; ?>
                        <?php if ($filterCustomer && $filterCustomer != 'all'): ?>
                            <div>• Customer ID: <?php echo $filterCustomer; ?></div>
                        <?php endif; ?>
                        <?php if ($filterPayment && $filterPayment != 'all'): ?>
                            <div>• Payment Method: <?php echo ucfirst($filterPayment); ?></div>
                        <?php endif; ?>
                        <?php if ($filterStatus && $filterStatus != 'all'): ?>
                            <div>• Status: <?php echo ucfirst($filterStatus); ?></div>
                        <?php endif; ?>
                        <?php if ($filterMinAmount || $filterMaxAmount): ?>
                            <div>• Amount Range: <?php echo $filterMinAmount ? '₹'.$filterMinAmount : 'Any'; ?> - <?php echo $filterMaxAmount ? '₹'.$filterMaxAmount : 'Any'; ?></div>
                        <?php endif; ?>
                        <?php if ($filterEwayBill): ?>
                            <div>• E-Way Bill: <?php echo $filterEwayBill; ?></div>
                        <?php endif; ?>
                        <?php if ($filterDispatched): ?>
                            <div>• Dispatched Through: <?php echo $filterDispatched; ?></div>
                        <?php endif; ?>
                        <?php if ($filterDestination): ?>
                            <div>• Destination: <?php echo $filterDestination; ?></div>
                        <?php endif; ?>
                        <?php if ($filterInvoiceNo): ?>
                            <div>• Invoice No: <?php echo $filterInvoiceNo; ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (empty($filter_info) && !$isMonthly): ?>
                        <div>• No filters applied - All records</div>
                    <?php endif; ?>
                </div>
                
                <!-- Summary Cards -->
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-label">Total Invoices</div>
                        <div class="summary-value"><?php echo count($data); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Total Sales</div>
                        <div class="summary-value">₹<?php echo number_format($total_sum, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Total Profit</div>
                        <div class="summary-value" style="color: #059669;">₹<?php echo number_format($total_profit, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Pending Amount</div>
                        <div class="summary-value" style="color: #dc2626;">₹<?php echo number_format($total_pending, 2); ?></div>
                    </div>
                </div>
                
                <!-- Data Table -->
                <table>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>E-Way Bill</th>
                            <th>Dispatch</th>
                            <th>Destination</th>
                            <th>Subtotal</th>
                            <th>Tax</th>
                            <th>Total</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $row): 
                            $status_class = '';
                            if ($row['Status'] == 'Paid') $status_class = 'badge-paid';
                            elseif ($row['Status'] == 'Pending') $status_class = 'badge-pending';
                            elseif ($row['Status'] == 'Overdue') $status_class = 'badge-overdue';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['Invoice No']); ?></td>
                            <td><?php echo htmlspecialchars($row['Date']); ?></td>
                            <td><?php echo htmlspecialchars($row['Customer Name']); ?></td>
                            <td><?php echo htmlspecialchars($row['E-Way Bill']); ?></td>
                            <td><?php echo htmlspecialchars($row['Dispatched Through']); ?></td>
                            <td><?php echo htmlspecialchars($row['Destination']); ?></td>
                            <td class="text-right">₹<?php echo number_format($row['Subtotal'], 2); ?></td>
                            <td class="text-right">₹<?php echo number_format($row['Tax Total'], 2); ?></td>
                            <td class="text-right"><strong>₹<?php echo number_format($row['Total'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['Payment Method']); ?></td>
                            <td><span class="badge <?php echo $status_class; ?>"><?php echo $row['Status']; ?></span></td>
                            <td class="text-right" style="color: #059669;">₹<?php echo number_format($row['Profit'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Detailed Summary -->
                <div class="summary">
                    <h3 style="margin-top: 0;">📈 Detailed Summary</h3>
                    
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                        <div><strong>Total Subtotal:</strong> ₹<?php echo number_format($total_subtotal, 2); ?></div>
                        <div><strong>Total CGST:</strong> ₹<?php echo number_format($total_cgst, 2); ?></div>
                        <div><strong>Total SGST:</strong> ₹<?php echo number_format($total_sgst, 2); ?></div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                        <div><strong>Total Tax:</strong> ₹<?php echo number_format($total_tax, 2); ?></div>
                        <div><strong>Net Collected:</strong> ₹<?php echo number_format($total_sum - $total_pending, 2); ?></div>
                        <div><strong>Average Invoice:</strong> ₹<?php echo number_format(count($data) > 0 ? $total_sum / count($data) : 0, 2); ?></div>
                    </div>
                    
                    <h4>Payment Method Breakdown</h4>
                    <?php foreach ($payment_totals as $method => $amount): ?>
                        <?php if ($amount > 0): 
                            $percentage = ($amount / $total_sum) * 100;
                        ?>
                        <div class="payment-item">
                            <span><strong><?php echo ucfirst($method); ?></strong></span>
                            <span>₹<?php echo number_format($amount, 2); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                
                <div class="footer">
                    <p>This is a computer-generated report. Valid without signature.</p>
                    <p>Generated by Sri Plaast ERP System</p>
                </div>
            </body>
            </html>
            <?php
            $html = ob_get_clean();
            
            // For PDF-like download, save as HTML
            $filename = $isMonthly ? "sales_report_{$monthName}" : "sales_report_" . date('Y-m-d');
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="' . $filename . '.html"');
            echo $html;
            exit;
    }
}

// -------------------------
// Filters - UPDATED with new fields
// -------------------------
$filterDate     = $_GET['filter_date'] ?? date('Y-m-d');
$filterCustomer = $_GET['filter_customer'] ?? '';
$filterPayment  = $_GET['filter_payment'] ?? '';
$filterStatus   = $_GET['filter_status'] ?? '';
$filterMonth    = $_GET['filter_month'] ?? date('Y-m');

// New advanced filters
$filterEwayBill = $_GET['filter_eway_bill'] ?? '';
$filterDispatched = $_GET['filter_dispatched'] ?? '';
$filterDestination = $_GET['filter_destination'] ?? '';
$filterInvoiceNo = $_GET['filter_invoice_no'] ?? '';
$filterDateFrom = $_GET['filter_date_from'] ?? '';
$filterDateTo = $_GET['filter_date_to'] ?? '';
$filterMinAmount = $_GET['filter_min_amount'] ?? '';
$filterMaxAmount = $_GET['filter_max_amount'] ?? '';

$where  = "1=1";
$params = [];
$types  = "";

// Apply month filter if set (overrides date filter)
if (!empty($filterMonth) && $filterMonth !== 'all') {
    $year = substr($filterMonth, 0, 4);
    $month = substr($filterMonth, 5, 2);
    $where .= " AND YEAR(i.created_at) = ? AND MONTH(i.created_at) = ?";
    $params[] = $year;
    $params[] = $month;
    $types .= "ii";
} else {
    // Basic filters (only if month filter not applied)
    if ($filterDate && $filterDate !== 'all') {
        $where .= " AND DATE(i.created_at) = ?";
        $params[] = $filterDate;
        $types .= "s";
    }
}

if ($filterCustomer && $filterCustomer !== 'all') {
    $where .= " AND i.customer_id = ?";
    $params[] = (int)$filterCustomer;
    $types .= "i";
}

if ($filterPayment && $filterPayment !== 'all') {
    $where .= " AND i.payment_method = ?";
    $params[] = $filterPayment;
    $types .= "s";
}

if ($filterStatus && $filterStatus !== 'all') {
    if ($filterStatus === 'paid') {
        $where .= " AND i.pending_amount = 0";
    } elseif ($filterStatus === 'pending') {
        $where .= " AND i.pending_amount > 0";
    } elseif ($filterStatus === 'overdue') {
        $where .= " AND i.pending_amount > 0 AND DATE(i.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    }
}

// Advanced filters for new fields
if (!empty($filterEwayBill)) {
    $where .= " AND i.eway_bill_no LIKE ?";
    $params[] = "%$filterEwayBill%";
    $types .= "s";
}

if (!empty($filterDispatched)) {
    $where .= " AND i.dispatched_through LIKE ?";
    $params[] = "%$filterDispatched%";
    $types .= "s";
}

if (!empty($filterDestination)) {
    $where .= " AND i.destination LIKE ?";
    $params[] = "%$filterDestination%";
    $types .= "s";
}

if (!empty($filterInvoiceNo)) {
    $where .= " AND i.inv_num LIKE ?";
    $params[] = "%$filterInvoiceNo%";
    $types .= "s";
}

if (!empty($filterDateFrom)) {
    $where .= " AND DATE(i.created_at) >= ?";
    $params[] = $filterDateFrom;
    $types .= "s";
}

if (!empty($filterDateTo)) {
    $where .= " AND DATE(i.created_at) <= ?";
    $params[] = $filterDateTo;
    $types .= "s";
}

if (!empty($filterMinAmount)) {
    $where .= " AND i.total >= ?";
    $params[] = (float)$filterMinAmount;
    $types .= "d";
}

if (!empty($filterMaxAmount)) {
    $where .= " AND i.total <= ?";
    $params[] = (float)$filterMaxAmount;
    $types .= "d";
}

// -------------------------
// MAIN LIST QUERY (with PROFIT)
// -------------------------
$sql = "
    SELECT 
        i.*,
        c.customer_name, c.phone, c.gst_number,
        COALESCE(pf.profit, 0) AS profit_amount
    FROM invoice i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN (
        SELECT 
            ii.invoice_id,
            COALESCE(
                SUM(ii.selling_price  * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity)) -
                SUM(ii.purchase_price * COALESCE(NULLIF(ii.no_of_pcs,0), ii.quantity))
            , 0) AS profit
        FROM invoice_item ii
        GROUP BY ii.invoice_id
    ) pf ON pf.invoice_id = i.id
    WHERE $where
    ORDER BY i.created_at DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $invoices = $stmt->get_result();
} else {
    $invoices = $conn->query($sql);
}

// Get customers for filter dropdown
$customers = $conn->query("SELECT id, customer_name FROM customers ORDER BY customer_name ASC");

// Get available months for filter
$months_query = $conn->query("
    SELECT DISTINCT 
        DATE_FORMAT(created_at, '%Y-%m') as month_value,
        DATE_FORMAT(created_at, '%M %Y') as month_name
    FROM invoice 
    ORDER BY created_at DESC
");
$available_months = $months_query->fetch_all(MYSQLI_ASSOC);

// -------------------------
// Stats (kept same)
// -------------------------
$today_sales     = $conn->query("SELECT COALESCE(SUM(total), 0) as total FROM invoice WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['total'];
$month_sales     = $conn->query("SELECT COALESCE(SUM(total), 0) as total FROM invoice WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetch_assoc()['total'];
$total_invoices  = $conn->query("SELECT COUNT(*) as cnt FROM invoice")->fetch_assoc()['cnt'];
$pending_amount  = $conn->query("SELECT COALESCE(SUM(pending_amount), 0) as total FROM invoice WHERE pending_amount > 0")->fetch_assoc()['total'];
$today_count     = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['cnt'];

$cash_count   = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'cash'")->fetch_assoc()['cnt'];
$card_count   = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'card'")->fetch_assoc()['cnt'];
$upi_count    = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'upi'")->fetch_assoc()['cnt'];
$bank_count   = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'bank'")->fetch_assoc()['cnt'];
$credit_count = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'credit'")->fetch_assoc()['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .invoice-card{background:white;border-radius:12px;padding:16px;border:1px solid #eef2f6;transition:all .2s;}
        .invoice-card:hover{box-shadow:0 4px 12px rgba(0,0,0,0.05);border-color:#cbd5e1;}
        .invoice-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #eef2f6;}
        .invoice-number{font-weight:700;color:#2463eb;font-size:16px;}
        .invoice-date{font-size:12px;color:#64748b;}
        .customer-info{margin-bottom:12px;}
        .customer-name{font-weight:600;color:#1e293b;margin-bottom:2px;}
        .customer-detail{font-size:11px;color:#64748b;}
        .amount-large{font-size:24px;font-weight:700;color:#1e293b;}
        .amount-label{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;}
        .pending-badge{background:#fee2e2;color:#dc2626;padding:4px 8px;border-radius:20px;font-size:12px;font-weight:500;display:inline-flex;align-items:center;gap:4px;}
        .paid-badge{background:#dcfce7;color:#16a34a;padding:4px 8px;border-radius:20px;font-size:12px;font-weight:500;display:inline-flex;align-items:center;gap:4px;}
        .method-badge{padding:4px 8px;border-radius:20px;font-size:11px;font-weight:500;display:inline-flex;align-items:center;gap:4px;}
        .method-badge.cash{background:#e8f2ff;color:#2463eb;}
        .method-badge.card{background:#f0fdf4;color:#16a34a;}
        .method-badge.upi{background:#fef3c7;color:#d97706;}
        .method-badge.bank{background:#f3e8ff;color:#9333ea;}
        .method-badge.credit{background:#fee2e2;color:#dc2626;}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
        .stat-box{background:white;border-radius:16px;padding:20px;border:1px solid #eef2f6;}
        .stat-value{font-size:28px;font-weight:700;color:#1e293b;line-height:1.2;margin-bottom:4px;}
        .stat-label{font-size:13px;color:#64748b;}
        .filter-section{background:white;border-radius:12px;padding:16px;border:1px solid #eef2f6;margin-bottom:20px;}
        .action-btn{padding:6px 12px;border-radius:8px;font-size:12px;font-weight:500;transition:all .2s;}
        .action-btn:hover{transform:translateY(-1px);}
        .payment-form{background:#f8fafc;border-radius:8px;padding:12px;margin-top:12px;border:1px solid #e2e8f0;}
        .permission-badge{font-size:11px;padding:2px 6px;border-radius:4px;background:#f1f5f9;color:#64748b;}
        .advanced-filters {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
            border: 1px solid #e2e8f0;
        }
        .filter-hint {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }
        .transport-badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            margin-left: 4px;
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
            min-width: 200px;
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
            padding: 12px 16px;
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
        .export-dropdown-content a:first-child {
            border-radius: 8px 8px 0 0;
        }
        .export-dropdown-content a:last-child {
            border-radius: 0 0 8px 8px;
            border-bottom: none;
        }
        .export-btn {
            background: #10b981;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .export-btn:hover {
            background: #059669;
        }
        .export-section {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 16px;
        }
        .month-filter {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            border: 1px solid #e2e8f0;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Sales</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">View and manage all invoices</p>
                </div>
                <div class="d-flex gap-2">
                    <!-- Export Dropdown -->
                    <div class="export-dropdown">
                        <button class="export-btn">
                            <i class="bi bi-download"></i> Export
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
                            <hr style="margin: 8px 0; border-color: #eef2f6;">
                            <a href="?export=monthly_csv&filter_month=<?php echo date('Y-m'); ?>" style="font-weight: 500;">
                                <i class="bi bi-calendar-month" style="color: #9333ea;"></i> This Month (CSV)
                            </a>
                            <a href="?export=monthly_excel&filter_month=<?php echo date('Y-m'); ?>" style="font-weight: 500;">
                                <i class="bi bi-calendar-month" style="color: #9333ea;"></i> This Month (Excel)
                            </a>
                            <a href="?export=monthly_pdf&filter_month=<?php echo date('Y-m'); ?>" style="font-weight: 500;">
                                <i class="bi bi-calendar-month" style="color: #9333ea;"></i> This Month (PDF)
                            </a>
                        </div>
                    </div>
                    
                    <a href="new-sale.php" class="btn-primary-custom">
                        <i class="bi bi-plus-circle"></i> New Invoice
                    </a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">₹<?php echo number_format((float)$today_sales, 2); ?></div>
                            <div class="stat-label">Today's Sales</div>
                        </div>
                        <div class="stat-icon blue" style="width: 48px; height: 48px;">
                            <i class="bi bi-calendar-day"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-muted" style="font-size: 12px;"><?php echo (int)$today_count; ?> invoices today</div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">₹<?php echo number_format((float)$month_sales, 2); ?></div>
                            <div class="stat-label">Monthly Sales</div>
                        </div>
                        <div class="stat-icon green" style="width: 48px; height: 48px;">
                            <i class="bi bi-calendar-month"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-muted" style="font-size: 12px;"><?php echo date('F Y'); ?></div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo (int)$total_invoices; ?></div>
                            <div class="stat-label">Total Invoices</div>
                        </div>
                        <div class="stat-icon purple" style="width: 48px; height: 48px;">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-danger">₹<?php echo number_format((float)$pending_amount, 2); ?></div>
                            <div class="stat-label">Pending Amount</div>
                        </div>
                        <div class="stat-icon orange" style="width: 48px; height: 48px;">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Methods Summary -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="dashboard-card">
                        <div class="card-body py-3">
                            <div class="d-flex gap-3 flex-wrap align-items-center">
                                <span class="fw-semibold">Payment Methods:</span>
                                <span class="method-badge cash"><i class="bi bi-cash-stack me-1"></i>Cash: <?php echo (int)$cash_count; ?></span>
                                <span class="method-badge card"><i class="bi bi-credit-card me-1"></i>Card: <?php echo (int)$card_count; ?></span>
                                <span class="method-badge upi"><i class="bi bi-phone me-1"></i>UPI: <?php echo (int)$upi_count; ?></span>
                                <span class="method-badge bank"><i class="bi bi-bank me-1"></i>Bank: <?php echo (int)$bank_count; ?></span>
                                <span class="method-badge credit"><i class="bi bi-clock-history me-1"></i>Credit: <?php echo (int)$credit_count; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export Info Card -->
            <div class="export-section">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle-fill text-success"></i>
                    <span class="text-muted">Export your sales data in multiple formats with custom filters. </span>
                    <span class="badge bg-success ms-2">New: Monthly exports available</span>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="sales.php" class="row g-3">
                    <!-- Month Filter (new) -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-calendar-month"></i> Select Month
                        </label>
                        <select name="filter_month" class="form-select">
                            <option value="all">All Months</option>
                            <?php foreach ($available_months as $month): ?>
                                <option value="<?php echo $month['month_value']; ?>" 
                                    <?php echo ($filterMonth == $month['month_value']) ? 'selected' : ''; ?>>
                                    <?php echo $month['month_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="filter-hint">Select month for monthly view</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="filter_date" class="form-control" value="<?php echo htmlspecialchars($filterDate); ?>" <?php echo (!empty($filterMonth) && $filterMonth != 'all') ? 'disabled' : ''; ?>>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="filter_customer" class="form-select">
                            <option value="all">All Customers</option>
                            <?php
                            if ($customers && $customers->num_rows > 0) {
                                while ($customer = $customers->fetch_assoc()):
                            ?>
                                <option value="<?php echo (int)$customer['id']; ?>" <?php echo ((string)$filterCustomer === (string)$customer['id']) ? 'selected' : ''; ?>>
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
                        <select name="filter_payment" class="form-select">
                            <option value="all">All</option>
                            <option value="cash" <?php echo $filterPayment === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="card" <?php echo $filterPayment === 'card' ? 'selected' : ''; ?>>Card</option>
                            <option value="upi"  <?php echo $filterPayment === 'upi'  ? 'selected' : ''; ?>>UPI</option>
                            <option value="bank" <?php echo $filterPayment === 'bank' ? 'selected' : ''; ?>>Bank</option>
                            <option value="credit" <?php echo $filterPayment === 'credit' ? 'selected' : ''; ?>>Credit</option>
                            <option value="mixed" <?php echo $filterPayment === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="filter_status" class="form-select">
                            <option value="all">All</option>
                            <option value="paid" <?php echo $filterStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="overdue" <?php echo $filterStatus === 'overdue' ? 'selected' : ''; ?>>Overdue (30+ days)</option>
                        </select>
                    </div>

                    <div class="col-md-6 d-flex align-items-end">
                        <div class="d-flex gap-2 w-100">
                            <button type="submit" class="btn-primary-custom flex-fill">
                                <i class="bi bi-funnel"></i> Apply Filters
                            </button>
                            <a href="sales.php" class="btn-outline-custom flex-fill">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                        </div>
                    </div>

                    <!-- Advanced Filters Toggle -->
                    <div class="col-12">
                        <button type="button" class="btn btn-link text-primary p-0" id="toggleAdvancedFilters" style="text-decoration: none;">
                            <i class="bi bi-chevron-down" id="toggleIcon"></i> Advanced Filters
                        </button>
                    </div>

                    <!-- Advanced Filters Section -->
                    <div class="col-12" id="advancedFilters" style="display: none;">
                        <div class="advanced-filters">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-upc-scan"></i> E-Way Bill No
                                    </label>
                                    <input type="text" name="filter_eway_bill" class="form-control" 
                                           placeholder="Search by E-Way Bill" 
                                           value="<?php echo htmlspecialchars($filterEwayBill); ?>">
                                    <div class="filter-hint">Enter E-Way bill number</div>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-truck"></i> Dispatched Through
                                    </label>
                                    <input type="text" name="filter_dispatched" class="form-control" 
                                           placeholder="Transport / Vehicle" 
                                           value="<?php echo htmlspecialchars($filterDispatched); ?>">
                                    <div class="filter-hint">Transport name or vehicle no</div>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-geo-alt"></i> Destination
                                    </label>
                                    <input type="text" name="filter_destination" class="form-control" 
                                           placeholder="Destination" 
                                           value="<?php echo htmlspecialchars($filterDestination); ?>">
                                    <div class="filter-hint">Delivery destination</div>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-hash"></i> Invoice Number
                                    </label>
                                    <input type="text" name="filter_invoice_no" class="form-control" 
                                           placeholder="Invoice #" 
                                           value="<?php echo htmlspecialchars($filterInvoiceNo); ?>">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-calendar-range"></i> From Date
                                    </label>
                                    <input type="date" name="filter_date_from" class="form-control" 
                                           value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-calendar-range"></i> To Date
                                    </label>
                                    <input type="date" name="filter_date_to" class="form-control" 
                                           value="<?php echo htmlspecialchars($filterDateTo); ?>">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-tag"></i> Min Amount (₹)
                                    </label>
                                    <input type="number" name="filter_min_amount" class="form-control" 
                                           placeholder="Min Amount" step="0.01" 
                                           value="<?php echo htmlspecialchars($filterMinAmount); ?>">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-tag"></i> Max Amount (₹)
                                    </label>
                                    <input type="number" name="filter_max_amount" class="form-control" 
                                           placeholder="Max Amount" step="0.01" 
                                           value="<?php echo htmlspecialchars($filterMaxAmount); ?>">
                                </div>

                                <div class="col-12 mt-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i> Apply Advanced Filters
                                    </button>
                                    <a href="sales.php" class="btn btn-outline-secondary ms-2">
                                        <i class="bi bi-eraser"></i> Clear All
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Invoices List -->
            <div class="dashboard-card">
                <div class="card-header-custom p-4">
                    <h5><i class="bi bi-receipt me-2"></i>Invoices</h5>
                    <p>Showing <?php echo $invoices ? (int)$invoices->num_rows : 0; ?> invoices</p>
                </div>

                <!-- Desktop Table View -->
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="salesTable">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Transport Details</th>
                                <th>Items</th>
                                <th>Subtotal</th>
                                <th>Tax</th>
                                <th>Total</th>
                                <th>Profit</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Pending</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($invoices && $invoices->num_rows > 0): ?>
                                <?php while ($invoice = $invoices->fetch_assoc()):
                                    $status = getPaymentStatus($invoice['pending_amount']);
                                    $method = getPaymentMethodBadge($invoice['payment_method']);

                                    $item_count_query = $conn->prepare("SELECT COUNT(*) as cnt FROM invoice_item WHERE invoice_id = ?");
                                    $item_count_query->bind_param("i", $invoice['id']);
                                    $item_count_query->execute();
                                    $item_count = (int)$item_count_query->get_result()->fetch_assoc()['cnt'];

                                    $profit_val = (float)($invoice['profit_amount'] ?? 0);
                                ?>
                                    <tr>
                                        <td><span class="order-id"><?php echo htmlspecialchars($invoice['inv_num']); ?></span></td>
                                        <td style="white-space: nowrap;">
                                            <?php echo date('d M Y', strtotime($invoice['created_at'])); ?>
                                            <div class="text-muted" style="font-size: 10px;"><?php echo date('h:i A', strtotime($invoice['created_at'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></div>
                                            <?php if (!empty($invoice['phone'])): ?>
                                                <div class="text-muted" style="font-size: 11px;"><?php echo htmlspecialchars($invoice['phone']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($invoice['eway_bill_no']) || !empty($invoice['dispatched_through']) || !empty($invoice['destination'])): ?>
                                                <?php if (!empty($invoice['eway_bill_no'])): ?>
                                                    <div><small class="text-muted">E-Way:</small> <span class="transport-badge"><?php echo htmlspecialchars($invoice['eway_bill_no']); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (!empty($invoice['dispatched_through'])): ?>
                                                    <div><small class="text-muted">Dispatch:</small> <?php echo htmlspecialchars($invoice['dispatched_through']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($invoice['destination'])): ?>
                                                    <div><small class="text-muted">Dest:</small> <?php echo htmlspecialchars($invoice['destination']); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?php echo $item_count; ?></td>
                                        <td>₹<?php echo number_format((float)$invoice['subtotal'], 2); ?></td>
                                        <td>₹<?php echo number_format((float)$invoice['cgst_amount'] + (float)$invoice['sgst_amount'], 2); ?></td>
                                        <td class="fw-semibold">₹<?php echo number_format((float)$invoice['total'], 2); ?></td>

                                        <!-- PROFIT -->
                                        <td class="fw-semibold <?php echo $profit_val >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            ₹<?php echo number_format($profit_val, 2); ?>
                                        </td>

                                        <td>
                                            <span class="method-badge <?php echo $method['class']; ?>">
                                                <i class="bi <?php echo $method['icon']; ?>"></i>
                                                <?php echo $method['text']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status['class']; ?>">
                                                <i class="bi <?php echo $status['icon']; ?>"></i>
                                                <?php echo $status['text']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ((float)$invoice['pending_amount'] > 0): ?>
                                                <span class="pending-badge">
                                                    <i class="bi bi-exclamation-circle"></i>
                                                    ₹<?php echo number_format((float)$invoice['pending_amount'], 2); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="paid-badge">
                                                    <i class="bi bi-check-circle"></i>
                                                    Paid
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <a href="invoice-view.php?id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-info" style="font-size: 12px; padding: 3px 8px;" title="View Invoice">
                                                    <i class="bi bi-eye"></i>
                                                </a>

                                                <a href="print_invoice.php?id=<?php echo (int)$invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size: 12px; padding: 3px 8px;" title="Print Invoice">
                                                    <i class="bi bi-printer"></i>
                                                </a>

                                                <?php if ((float)$invoice['pending_amount'] > 0): ?>
                                                    <button class="btn btn-sm btn-outline-success" style="font-size: 12px; padding: 3px 8px;"
                                                            onclick="showPaymentModal(<?php echo (int)$invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['inv_num']); ?>', <?php echo (float)$invoice['pending_amount']; ?>)"
                                                            title="Collect Payment">
                                                        <i class="bi bi-cash-stack"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($is_admin): ?>
                                                    <form method="POST"
                                                          action="sales.php<?php echo buildQueryString(['filter_date', 'filter_customer', 'filter_payment', 'filter_status', 'filter_eway_bill', 'filter_dispatched', 'filter_destination', 'filter_invoice_no', 'filter_date_from', 'filter_date_to', 'filter_min_amount', 'filter_max_amount']); ?>"
                                                          style="display:inline;"
                                                          onsubmit="return confirm('Are you sure you want to cancel this invoice? Stock will be restored.')">
                                                        <input type="hidden" name="action" value="cancel_invoice">
                                                        <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" title="Cancel Invoice">
                                                            <i class="bi bi-x-circle"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="13" class="text-center text-muted py-4">No invoices found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if ($invoices && $invoices->num_rows > 0): ?>
                        <?php
                        $invoices->data_seek(0);
                        while ($invoice = $invoices->fetch_assoc()):
                            $status = getPaymentStatus($invoice['pending_amount']);
                            $method = getPaymentMethodBadge($invoice['payment_method']);
                            $profit_val = (float)($invoice['profit_amount'] ?? 0);
                        ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header">
                                    <div>
                                        <span class="order-id"><?php echo htmlspecialchars($invoice['inv_num']); ?></span>
                                        <span class="customer-name ms-2"><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></span>
                                    </div>
                                    <span class="status-badge <?php echo $status['class']; ?>">
                                        <i class="bi <?php echo $status['icon']; ?>"></i>
                                        <?php echo $status['text']; ?>
                                    </span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Date & Time</span>
                                    <span class="mobile-card-value"><?php echo date('d M Y, h:i A', strtotime($invoice['created_at'])); ?></span>
                                </div>

                                <!-- Transport Details in Mobile View -->
                                <?php if (!empty($invoice['eway_bill_no']) || !empty($invoice['dispatched_through']) || !empty($invoice['destination'])): ?>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Transport</span>
                                    <span class="mobile-card-value">
                                        <?php if (!empty($invoice['eway_bill_no'])): ?>
                                            <span class="transport-badge">E-Way: <?php echo htmlspecialchars($invoice['eway_bill_no']); ?></span><br>
                                        <?php endif; ?>
                                        <?php if (!empty($invoice['dispatched_through'])): ?>
                                            <span>Dispatch: <?php echo htmlspecialchars($invoice['dispatched_through']); ?></span><br>
                                        <?php endif; ?>
                                        <?php if (!empty($invoice['destination'])): ?>
                                            <span>Dest: <?php echo htmlspecialchars($invoice['destination']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Payment Method</span>
                                    <span class="mobile-card-value">
                                        <span class="method-badge <?php echo $method['class']; ?>">
                                            <i class="bi <?php echo $method['icon']; ?>"></i>
                                            <?php echo $method['text']; ?>
                                        </span>
                                    </span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Subtotal</span>
                                    <span class="mobile-card-value">₹<?php echo number_format((float)$invoice['subtotal'], 2); ?></span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Tax (GST)</span>
                                    <span class="mobile-card-value">₹<?php echo number_format((float)$invoice['cgst_amount'] + (float)$invoice['sgst_amount'], 2); ?></span>
                                </div>

                                <div class="mobile-card-row">
                                    <span class="mobile-card-label fw-bold">Total</span>
                                    <span class="mobile-card-value fw-bold" style="color: var(--primary);">₹<?php echo number_format((float)$invoice['total'], 2); ?></span>
                                </div>

                                <!-- PROFIT -->
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label fw-bold">Profit</span>
                                    <span class="mobile-card-value fw-bold <?php echo $profit_val >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        ₹<?php echo number_format($profit_val, 2); ?>
                                    </span>
                                </div>

                                <?php if ((float)$invoice['pending_amount'] > 0): ?>
                                    <div class="mobile-card-row">
                                        <span class="mobile-card-label text-danger">Pending</span>
                                        <span class="mobile-card-value text-danger fw-semibold">₹<?php echo number_format((float)$invoice['pending_amount'], 2); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="mobile-card-actions">
                                    <a href="invoice-view.php?id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-info flex-fill">
                                        <i class="bi bi-eye me-1"></i>View
                                    </a>

                                    <a href="print_invoice.php?id=<?php echo (int)$invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary flex-fill">
                                        <i class="bi bi-printer me-1"></i>Print
                                    </a>

                                    <?php if ((float)$invoice['pending_amount'] > 0): ?>
                                        <button class="btn btn-sm btn-outline-success flex-fill"
                                                onclick="showPaymentModal(<?php echo (int)$invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['inv_num']); ?>', <?php echo (float)$invoice['pending_amount']; ?>)">
                                            <i class="bi bi-cash-stack me-1"></i>Pay
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($is_admin): ?>
                                        <form method="POST"
                                              action="sales.php<?php echo buildQueryString(['filter_date', 'filter_customer', 'filter_payment', 'filter_status', 'filter_eway_bill', 'filter_dispatched', 'filter_destination', 'filter_invoice_no', 'filter_date_from', 'filter_date_to', 'filter_min_amount', 'filter_max_amount']); ?>"
                                              style="flex: 1;"
                                              onsubmit="return confirm('Cancel this invoice? Stock will be restored.')">
                                            <input type="hidden" name="action" value="cancel_invoice">
                                            <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                <i class="bi bi-x-circle me-1"></i>Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-receipt d-block mb-2" style="font-size: 48px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No invoices found</div>
                            <div style="font-size: 13px;">
                                <?php if ($filterDate || $filterCustomer || $filterPayment || $filterStatus || $filterEwayBill || $filterDispatched || $filterDestination): ?>
                                    Try changing your filters or <a href="sales.php">view all invoices</a>
                                <?php else: ?>
                                    <a href="new-sale.php">Create your first invoice</a> to get started
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Payment Collection Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="sales.php<?php echo buildQueryString(['filter_date', 'filter_customer', 'filter_payment', 'filter_status', 'filter_eway_bill', 'filter_dispatched', 'filter_destination', 'filter_invoice_no', 'filter_date_from', 'filter_date_to', 'filter_min_amount', 'filter_max_amount']); ?>" id="paymentForm">
                <input type="hidden" name="action" value="collect_payment">
                <input type="hidden" name="invoice_id" id="paymentInvoiceId">

                <div class="modal-header">
                    <h5 class="modal-title">Collect Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Invoice Number</label>
                        <input type="text" class="form-control" id="paymentInvoiceNum" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Pending Amount</label>
                        <input type="text" class="form-control" id="paymentPending" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" name="payment_amount" class="form-control" step="0.01" min="0.01" required id="paymentAmount" oninput="validatePaymentAmount()">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="upi">UPI</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>

                    <div id="paymentError" class="alert alert-danger py-2" style="display: none; font-size: 12px;"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="submitPayment">Collect Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    $('#salesTable').DataTable({
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

    // Toggle advanced filters
    $('#toggleAdvancedFilters').click(function() {
        const advancedDiv = $('#advancedFilters');
        const toggleIcon = $('#toggleIcon');
        
        if (advancedDiv.is(':hidden')) {
            advancedDiv.slideDown();
            toggleIcon.removeClass('bi-chevron-down').addClass('bi-chevron-up');
        } else {
            advancedDiv.slideUp();
            toggleIcon.removeClass('bi-chevron-up').addClass('bi-chevron-down');
        }
    });

    // Show advanced filters by default if any advanced filter has value
    const urlParams = new URLSearchParams(window.location.search);
    const advancedFilters = ['filter_eway_bill', 'filter_dispatched', 'filter_destination', 
                            'filter_invoice_no', 'filter_date_from', 'filter_date_to',
                            'filter_min_amount', 'filter_max_amount'];
    
    let showAdvanced = false;
    advancedFilters.forEach(filter => {
        if (urlParams.has(filter) && urlParams.get(filter)) {
            showAdvanced = true;
        }
    });
    
    if (showAdvanced) {
        $('#advancedFilters').show();
        $('#toggleIcon').removeClass('bi-chevron-down').addClass('bi-chevron-up');
    }

    // Handle month filter disabling date filter
    $('select[name="filter_month"]').change(function() {
        const monthVal = $(this).val();
        if (monthVal !== 'all') {
            $('input[name="filter_date"]').prop('disabled', true).val('');
        } else {
            $('input[name="filter_date"]').prop('disabled', false);
        }
    });

    // Initial check
    if ($('select[name="filter_month"]').val() !== 'all') {
        $('input[name="filter_date"]').prop('disabled', true);
    }
});

// Show payment modal
function showPaymentModal(invoiceId, invoiceNum, pendingAmount) {
    document.getElementById('paymentInvoiceId').value = invoiceId;
    document.getElementById('paymentInvoiceNum').value = invoiceNum;
    document.getElementById('paymentPending').value = '₹' + parseFloat(pendingAmount).toFixed(2);
    document.getElementById('paymentAmount').value = parseFloat(pendingAmount).toFixed(2);
    document.getElementById('paymentAmount').max = parseFloat(pendingAmount);

    $('#paymentModal').modal('show');
}

// Validate payment amount
function validatePaymentAmount() {
    const amount = parseFloat(document.getElementById('paymentAmount').value) || 0;
    const pending = parseFloat(document.getElementById('paymentPending').value.replace('₹', '')) || 0;
    const errorDiv = document.getElementById('paymentError');
    const submitBtn = document.getElementById('submitPayment');

    if (amount > pending) {
        errorDiv.style.display = 'block';
        errorDiv.textContent = 'Payment amount cannot exceed pending amount of ₹' + pending.toFixed(2);
        submitBtn.disabled = true;
    } else if (amount <= 0) {
        errorDiv.style.display = 'block';
        errorDiv.textContent = 'Please enter a valid amount.';
        submitBtn.disabled = true;
    } else {
        errorDiv.style.display = 'none';
        submitBtn.disabled = false;
    }
}
</script>
</body>
</html>