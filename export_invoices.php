<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can export
checkRoleAccess(['admin']);

$format = $_GET['format'] ?? 'excel';

// Get filter parameters
$filterStatus = $_GET['filter_status'] ?? '';
$filterCustomer = $_GET['customer_id'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterSearch = $_GET['search'] ?? '';
$filterPaymentMethod = $_GET['payment_method'] ?? '';

// Build WHERE clause
$where = "1=1";
$params = [];
$types = "";

if (!empty($filterSearch)) {
    $where .= " AND (i.inv_num LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ?)";
    $searchTerm = "%$filterSearch%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

if (!empty($filterCustomer) && is_numeric($filterCustomer)) {
    $where .= " AND i.customer_id = ?";
    $params[] = $filterCustomer;
    $types .= "i";
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

if (!empty($filterPaymentMethod)) {
    $where .= " AND i.payment_method = ?";
    $params[] = $filterPaymentMethod;
    $types .= "s";
}

if ($filterStatus === 'pending') {
    $where .= " AND i.pending_amount > 0";
} elseif ($filterStatus === 'paid') {
    $where .= " AND i.pending_amount = 0";
} elseif ($filterStatus === 'today') {
    $where .= " AND DATE(i.created_at) = CURDATE()";
} elseif ($filterStatus === 'week') {
    $where .= " AND YEARWEEK(i.created_at) = YEARWEEK(CURDATE())";
} elseif ($filterStatus === 'month') {
    $where .= " AND MONTH(i.created_at) = MONTH(CURDATE()) AND YEAR(i.created_at) = YEAR(CURDATE())";
}

// Get invoice data with items
$sql = "SELECT 
            i.id,
            i.inv_num,
            i.created_at as invoice_date,
            c.customer_name,
            c.phone,
            c.email,
            c.gst_number,
            i.subtotal,
            i.overall_discount,
            i.total as grand_total,
            i.taxable,
            i.cgst,
            i.cgst_amount,
            i.sgst,
            i.sgst_amount,
            i.cash_received,
            i.pending_amount,
            i.payment_method,
            (SELECT COUNT(*) FROM invoice_item WHERE invoice_id = i.id) as item_count
        FROM invoice i 
        LEFT JOIN customers c ON i.customer_id = c.id 
        WHERE $where 
        ORDER BY i.created_at DESC";

$invoices = null;
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $invoices = $stmt->get_result();
} else {
    $invoices = $conn->query($sql);
}

// Get invoice items for each invoice
$invoice_items = [];
if ($invoices && $invoices->num_rows > 0) {
    $invoice_ids = [];
    while ($row = $invoices->fetch_assoc()) {
        $invoice_ids[] = $row['id'];
    }
    
    if (!empty($invoice_ids)) {
        $ids_string = implode(',', $invoice_ids);
        $items_query = "SELECT 
                            ii.invoice_id,
                            ii.product_name,
                            ii.cat_name,
                            ii.quantity,
                            ii.unit,
                            ii.selling_price,
                            ii.discount,
                            ii.total as item_total,
                            ii.hsn,
                            ii.taxable,
                            ii.cgst,
                            ii.cgst_amount,
                            ii.sgst,
                            ii.sgst_amount
                        FROM invoice_item ii
                        WHERE ii.invoice_id IN ($ids_string)
                        ORDER BY ii.invoice_id, ii.id";
        
        $items_result = $conn->query($items_query);
        while ($item = $items_result->fetch_assoc()) {
            $invoice_items[$item['invoice_id']][] = $item;
        }
    }
    
    // Reset pointer
    $invoices->data_seek(0);
}

// Export based on format
if ($format === 'excel') {
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="invoices_export_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    // Create Excel file with HTML table
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'th { background-color: #f2f2f2; font-weight: bold; }';
    echo 'td, th { border: 1px solid #ddd; padding: 8px; }';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo '.invoice-header { background-color: #e6f3ff; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Summary section
    echo '<h2>Invoices Export - ' . date('d M Y') . '</h2>';
    
    // Filter summary
    echo '<p><strong>Filters applied:</strong> ';
    $filters = [];
    if ($filterStatus) $filters[] = 'Status: ' . ucfirst($filterStatus);
    if ($filterCustomer) {
        $cust_query = $conn->query("SELECT customer_name FROM customers WHERE id = $filterCustomer");
        $cust = $cust_query->fetch_assoc();
        $filters[] = 'Customer: ' . $cust['customer_name'];
    }
    if ($filterDateFrom) $filters[] = 'From: ' . $filterDateFrom;
    if ($filterDateTo) $filters[] = 'To: ' . $filterDateTo;
    if ($filterPaymentMethod) $filters[] = 'Payment: ' . ucfirst($filterPaymentMethod);
    if ($filterSearch) $filters[] = 'Search: ' . $filterSearch;
    
    if (empty($filters)) {
        echo 'All Invoices';
    } else {
        echo implode(' | ', $filters);
    }
    echo '</p>';
    
    // Totals
    $total_grand = 0;
    $total_paid = 0;
    $total_pending = 0;
    
    if ($invoices && $invoices->num_rows > 0) {
        $invoices->data_seek(0);
        while ($row = $invoices->fetch_assoc()) {
            $total_grand += $row['grand_total'];
            $total_paid += $row['cash_received'];
            $total_pending += $row['pending_amount'];
        }
        $invoices->data_seek(0);
    }
    
    echo '<p><strong>Summary:</strong> Total Invoices: ' . $invoices->num_rows . 
         ' | Total Amount: ₹' . number_format($total_grand, 2) . 
         ' | Total Paid: ₹' . number_format($total_paid, 2) . 
         ' | Total Pending: ₹' . number_format($total_pending, 2) . '</p>';
    
    // Main invoice table
    echo '<table>';
    echo '<tr>';
    echo '<th>Invoice #</th>';
    echo '<th>Date</th>';
    echo '<th>Customer</th>';
    echo '<th>Phone</th>';
    echo '<th>GSTIN</th>';
    echo '<th>Items</th>';
    echo '<th>Subtotal</th>';
    echo '<th>Discount</th>';
    echo '<th>Taxable</th>';
    echo '<th>CGST</th>';
    echo '<th>SGST</th>';
    echo '<th>Total</th>';
    echo '<th>Paid</th>';
    echo '<th>Pending</th>';
    echo '<th>Payment Method</th>';
    echo '<th>Status</th>';
    echo '</tr>';
    
    while ($invoice = $invoices->fetch_assoc()) {
        $status = $invoice['pending_amount'] > 0 ? 'Pending' : 'Paid';
        $status_color = $invoice['pending_amount'] > 0 ? '#ffebee' : '#e8f5e8';
        
        echo '<tr style="background-color: ' . $status_color . ';">';
        echo '<td>' . htmlspecialchars($invoice['inv_num']) . '</td>';
        echo '<td>' . date('d-m-Y H:i', strtotime($invoice['invoice_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer') . '</td>';
        echo '<td>' . htmlspecialchars($invoice['phone'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($invoice['gst_number'] ?: '-') . '</td>';
        echo '<td style="text-align: center;">' . $invoice['item_count'] . '</td>';
        echo '<td style="text-align: right;">₹' . number_format($invoice['subtotal'], 2) . '</td>';
        echo '<td style="text-align: right;">' . ($invoice['overall_discount'] ? '₹' . number_format($invoice['overall_discount'], 2) : '-') . '</td>';
        echo '<td style="text-align: right;">₹' . number_format($invoice['taxable'], 2) . '</td>';
        echo '<td style="text-align: right;">' . ($invoice['cgst'] ? $invoice['cgst'] . '% (₹' . number_format($invoice['cgst_amount'], 2) . ')' : '-') . '</td>';
        echo '<td style="text-align: right;">' . ($invoice['sgst'] ? $invoice['sgst'] . '% (₹' . number_format($invoice['sgst_amount'], 2) . ')' : '-') . '</td>';
        echo '<td style="text-align: right; font-weight: bold;">₹' . number_format($invoice['grand_total'], 2) . '</td>';
        echo '<td style="text-align: right;">₹' . number_format($invoice['cash_received'], 2) . '</td>';
        echo '<td style="text-align: right; ' . ($invoice['pending_amount'] > 0 ? 'color: #d32f2f; font-weight: bold;' : '') . '">₹' . number_format($invoice['pending_amount'], 2) . '</td>';
        echo '<td>' . ucfirst($invoice['payment_method']) . '</td>';
        echo '<td>' . $status . '</td>';
        echo '</tr>';
        
        // Add items if available
        if (isset($invoice_items[$invoice['id']]) && count($invoice_items[$invoice['id']]) > 0) {
            echo '<tr>';
            echo '<td colspan="16" style="padding: 0;">';
            echo '<table style="width: 95%; margin: 5px auto; background-color: #f9f9f9;">';
            echo '<tr style="background-color: #e0e0e0;">';
            echo '<th>Product</th>';
            echo '<th>Quantity</th>';
            echo '<th>Unit</th>';
            echo '<th>Price</th>';
            echo '<th>Discount</th>';
            echo '<th>HSN</th>';
            echo '<th>Taxable</th>';
            echo '<th>CGST</th>';
            echo '<th>SGST</th>';
            echo '<th>Total</th>';
            echo '</tr>';
            
            foreach ($invoice_items[$invoice['id']] as $item) {
                $product_name = $item['product_name'] ?: $item['cat_name'];
                echo '<tr>';
                echo '<td>' . htmlspecialchars($product_name) . '</td>';
                echo '<td style="text-align: center;">' . number_format($item['quantity'], 3) . '</td>';
                echo '<td>' . ($item['unit'] ?: '-') . '</td>';
                echo '<td style="text-align: right;">₹' . number_format($item['selling_price'], 2) . '</td>';
                echo '<td style="text-align: right;">' . ($item['discount'] ? '₹' . number_format($item['discount'], 2) : '-') . '</td>';
                echo '<td>' . ($item['hsn'] ?: '-') . '</td>';
                echo '<td style="text-align: right;">₹' . number_format($item['taxable'], 2) . '</td>';
                echo '<td style="text-align: right;">' . ($item['cgst'] ? $item['cgst'] . '%' : '-') . '</td>';
                echo '<td style="text-align: right;">' . ($item['sgst'] ? $item['sgst'] . '%' : '-') . '</td>';
                echo '<td style="text-align: right; font-weight: bold;">₹' . number_format($item['item_total'], 2) . '</td>';
                echo '</tr>';
            }
            
            echo '</table>';
            echo '</td>';
            echo '</tr>';
        }
    }
    
    echo '</table>';
    
    // Footer
    echo '<p style="margin-top: 20px; font-size: 12px; color: #666;">';
    echo 'Generated on: ' . date('d-m-Y H:i:s') . ' | Sri Plaast Invoice Management System';
    echo '</p>';
    
    echo '</body>';
    echo '</html>';
    
} elseif ($format === 'pdf') {
    // For PDF, we'll redirect to a PDF generation library
    // You can implement TCPDF, Dompdf, or any other library here
    // For now, we'll just show a message
    header('Content-Type: text/html');
    echo '<html><body>';
    echo '<h2>PDF Export</h2>';
    echo '<p>PDF export functionality requires additional library integration.</p>';
    echo '<p>You can implement TCPDF, Dompdf, or mPDF for PDF generation.</p>';
    echo '<p><a href="invoices.php">Back to Invoices</a></p>';
    echo '</body></html>';
}

// Log the export activity
$log_desc = "Exported invoices to " . strtoupper($format) . " with filters: " . 
            ($filterStatus ? "Status: $filterStatus " : "") .
            ($filterCustomer ? "Customer ID: $filterCustomer " : "") .
            ($filterDateFrom ? "From: $filterDateFrom " : "") .
            ($filterDateTo ? "To: $filterDateTo " : "") .
            ($filterPaymentMethod ? "Payment: $filterPaymentMethod" : "");
            
$log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'export', ?)";
$log_stmt = $conn->prepare($log_query);
$log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
$log_stmt->execute();

exit;
?>