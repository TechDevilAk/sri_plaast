<?php
// invoice-setting.php
session_start();
$currentPage = 'invoice-setting';
$pageTitle = 'Invoice Settings';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can manage invoice settings
checkRoleAccess(['admin']);

header_remove("X-Powered-By");

// --------------------------
// Handle Form Submission
// --------------------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        $company_name = trim($_POST['company_name'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $invoice_prefix = trim($_POST['invoice_prefix'] ?? 'INV');
        $invoice_start = intval($_POST['invoice_start'] ?? 1);
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $ifsc = trim($_POST['ifsc'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $upi_id = trim($_POST['upi_id'] ?? '');
        
        // Handle logo upload
        $logo = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/invoice/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_ext, $allowed_ext)) {
                $logo_name = 'invoice_logo_' . time() . '.' . $file_ext;
                $logo_path = $upload_dir . $logo_name;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $logo_path)) {
                    $logo = $logo_path;
                } else {
                    $error = "Failed to upload logo.";
                }
            } else {
                $error = "Invalid file type. Only JPG, JPEG, PNG, GIF, and WEBP are allowed.";
            }
        }
        
        if (empty($error)) {
            // Check if settings already exist
            $check = $conn->query("SELECT id FROM invoice_setting LIMIT 1");
            
            if ($check && $check->num_rows > 0) {
                // Update existing settings
                $setting = $check->fetch_assoc();
                $id = $setting['id'];
                
                $sql = "UPDATE invoice_setting SET 
                        company_name = ?, company_address = ?, phone = ?, email = ?, 
                        gst_number = ?, invoice_prefix = ?, invoice_start = ?,
                        bank_name = ?, account_number = ?, ifsc = ?, branch = ?, upi_id = ?";
                
                $params = [$company_name, $company_address, $phone, $email, $gst_number, 
                          $invoice_prefix, $invoice_start, $bank_name, $account_number, 
                          $ifsc, $branch, $upi_id];
                $types = "ssssssisssss";
                
                if ($logo) {
                    $sql .= ", logo = ?";
                    $params[] = $logo;
                    $types .= "s";
                }
                
                $sql .= " WHERE id = ?";
                $params[] = $id;
                $types .= "i";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                
            } else {
                // Insert new settings
                if ($logo) {
                    $sql = "INSERT INTO invoice_setting 
                            (company_name, company_address, phone, email, gst_number, 
                             invoice_prefix, invoice_start, logo,
                             bank_name, account_number, ifsc, branch, upi_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssssssissssss", 
                        $company_name, $company_address, $phone, $email, $gst_number,
                        $invoice_prefix, $invoice_start, $logo,
                        $bank_name, $account_number, $ifsc, $branch, $upi_id
                    );
                } else {
                    $sql = "INSERT INTO invoice_setting 
                            (company_name, company_address, phone, email, gst_number, 
                             invoice_prefix, invoice_start,
                             bank_name, account_number, ifsc, branch, upi_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssissssss", 
                        $company_name, $company_address, $phone, $email, $gst_number,
                        $invoice_prefix, $invoice_start,
                        $bank_name, $account_number, $ifsc, $branch, $upi_id
                    );
                }
            }
            
            if ($stmt && $stmt->execute()) {
                // Log activity
                $log_desc = "Updated invoice settings";
                $log_stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)");
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $success = "Invoice settings updated successfully!";
            } else {
                $error = "Failed to update settings: " . ($conn->error ?? 'Unknown error');
            }
        }
    }
    
    if ($action === 'reset_settings') {
        // Reset to default settings
        $check = $conn->query("SELECT id FROM invoice_setting LIMIT 1");
        
        if ($check && $check->num_rows > 0) {
            $setting = $check->fetch_assoc();
            $id = $setting['id'];
            
            $sql = "UPDATE invoice_setding SET 
                    company_name = 'SRI PLAAST',
                    company_address = 'No: 5/268-6, PERIYAR NAGAR, H.DHOTTAMPATTI ROAD, HARUR, DHARMAPURI DT-636903.',
                    phone = '9688011887, 9865133431',
                    email = 'sriplaats@gmail.com',
                    gst_number = '33BWZPA4843D1Z',
                    invoice_prefix = 'INV',
                    invoice_start = 1,
                    bank_name = 'UNION BANK OF INDIA',
                    account_number = '75970501000003',
                    ifsc = 'UBIN0575976',
                    branch = 'HARUR',
                    upi_id = ''
                    WHERE id = ?";
                    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $log_desc = "Reset invoice settings to default";
                $log_stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)");
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $success = "Invoice settings reset to default values!";
            } else {
                $error = "Failed to reset settings.";
            }
        }
    }
}

// --------------------------
// Get Current Settings
// --------------------------
$settings = $conn->query("SELECT * FROM invoice_setting ORDER BY id ASC LIMIT 1")->fetch_assoc();

// Default values if no settings exist
if (!$settings) {
    $settings = [
        'company_name' => 'SRI PLAAST',
        'company_address' => 'No: 5/268-6, PERIYAR NAGAR, H.DHOTTAMPATTI ROAD, HARUR, DHARMAPURI DT-636903.',
        'phone' => '9688011887, 9865133431',
        'email' => 'sriplaats@gmail.com',
        'gst_number' => '33BWZPA4843D1Z',
        'logo' => null,
        'invoice_prefix' => 'INV',
        'invoice_start' => 1,
        'bank_name' => 'UNION BANK OF INDIA',
        'account_number' => '75970501000003',
        'ifsc' => 'UBIN0575976',
        'branch' => 'HARUR',
        'upi_id' => ''
    ];
}

// Get the latest invoice number preview
$next_invoice = $settings['invoice_prefix'] . str_pad($settings['invoice_start'], 5, '0', STR_PAD_LEFT);

// Get recent activity for this page
$recent_activity = $conn->query("
    SELECT al.*, u.name as user_name 
    FROM activity_log al 
    LEFT JOIN users u ON al.user_id = u.id 
    WHERE al.action IN ('update') 
    AND al.description LIKE '%invoice%'
    ORDER BY al.created_at DESC 
    LIMIT 10
");
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
        
        /* Settings Container */
        .settings-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Card Styles */
        .card-custom {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            margin-bottom: 24px;
            border: 1px solid #e9eef2;
            overflow: hidden;
        }
        
        .card-header-custom {
            padding: 20px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom: none;
        }
        
        .card-header-custom h5 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .card-header-custom h5 i {
            font-size: 20px;
        }
        
        .card-header-custom .badge-custom {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .card-body-custom {
            padding: 24px;
        }
        
        /* Section Divider */
        .section-divider {
            margin: 30px 0 20px;
            position: relative;
        }
        
        .section-divider h6 {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-divider h6 i {
            color: var(--primary);
        }
        
        /* Form Controls */
        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #475569;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .form-control, .form-select {
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(67,97,238,0.1);
            outline: none;
        }
        
        .form-control:disabled, .form-select:disabled {
            background-color: #f8fafc;
            border-color: #e2e8f0;
        }
        
        .input-group-text {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px 0 0 12px;
            font-weight: 500;
            color: #475569;
        }
        
        /* Preview Card */
        .preview-card {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .preview-title {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 4px;
        }
        
        .preview-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .preview-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .preview-row:last-child {
            border-bottom: none;
        }
        
        /* Logo Upload */
        .logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .logo-preview {
            width: 120px;
            height: 120px;
            border: 2px dashed #e2e8f0;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            overflow: hidden;
        }
        
        .logo-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .logo-preview .no-logo {
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
        }
        
        .logo-preview .no-logo i {
            font-size: 32px;
            margin-bottom: 8px;
            display: block;
        }
        
        .logo-upload-btn {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        
        .logo-upload-btn input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
            font-weight: 600;
            font-size: 14px;
            border-radius: 40px;
            transition: all 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: #2f4ad0;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67,97,238,0.3);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #0ca678;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16,185,129,0.3);
        }
        
        .btn-outline-secondary {
            background: white;
            border: 1.5px solid #e2e8f0;
            color: #475569;
        }
        
        .btn-outline-secondary:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        
        .btn-outline-danger {
            background: white;
            border: 1.5px solid var(--danger);
            color: var(--danger);
        }
        
        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
        }
        
        /* Info Box */
        .info-box {
            background: #f0f9ff;
            border-left: 4px solid var(--info);
            padding: 16px;
            border-radius: 12px;
            font-size: 13px;
            color: #0369a1;
            margin: 20px 0;
        }
        
        .info-box i {
            margin-right: 8px;
            font-size: 16px;
        }
        
        /* Activity Log */
        .activity-log {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            margin-top: 20px;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }
        
        .activity-time {
            font-size: 11px;
            color: #64748b;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .card-header-custom {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .logo-container {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .preview-card {
                margin-top: 20px;
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
            <div class="settings-container">

                <!-- Page Header -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                    <div>
                        <h4 class="fw-bold mb-1">Invoice Settings</h4>
                        <p class="text-muted">Configure your invoice layout, company details, and numbering</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="invoices.php" class="btn-outline-custom">
                            <i class="bi bi-receipt"></i> View Invoices
                        </a>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Preview Card -->
                <div class="preview-card">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="preview-title">Next Invoice Number</div>
                            <div class="preview-value"><?php echo htmlspecialchars($next_invoice); ?></div>
                            <div class="preview-row">
                                <span>Prefix:</span>
                                <span class="fw-semibold"><?php echo htmlspecialchars($settings['invoice_prefix']); ?></span>
                            </div>
                            <div class="preview-row">
                                <span>Start Number:</span>
                                <span class="fw-semibold"><?php echo $settings['invoice_start']; ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="preview-title">Company GSTIN</div>
                            <div class="preview-value"><?php echo htmlspecialchars($settings['gst_number']); ?></div>
                            <div class="preview-row">
                                <span>Bank:</span>
                                <span class="fw-semibold"><?php echo htmlspecialchars($settings['bank_name']); ?></span>
                            </div>
                            <div class="preview-row">
                                <span>Account:</span>
                                <span class="fw-semibold"><?php echo htmlspecialchars(substr($settings['account_number'], -4)); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Settings Form -->
                <form method="POST" action="invoice-setting.php" enctype="multipart/form-data" id="settingsForm">
                    <input type="hidden" name="action" value="update_settings">

                    <!-- Company Information Card -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-building"></i> Company Information</h5>
                            <span class="badge-custom">Required</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="company_name" 
                                           value="<?php echo htmlspecialchars($settings['company_name']); ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">GST Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="gst_number" 
                                           value="<?php echo htmlspecialchars($settings['gst_number']); ?>" 
                                           placeholder="22AAAAA0000A1Z5" required>
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label">Company Address</label>
                                    <textarea class="form-control" name="company_address" rows="3"><?php echo htmlspecialchars($settings['company_address']); ?></textarea>
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone" 
                                           value="<?php echo htmlspecialchars($settings['phone']); ?>">
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo htmlspecialchars($settings['email']); ?>">
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label">Company Logo</label>
                                    <div class="logo-container">
                                        <div class="logo-preview">
                                            <?php if (!empty($settings['logo']) && file_exists($settings['logo'])): ?>
                                                <img src="<?php echo htmlspecialchars($settings['logo']); ?>" alt="Company Logo">
                                            <?php else: ?>
                                                <div class="no-logo">
                                                    <i class="bi bi-image"></i>
                                                    No Logo
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="logo-upload-btn btn btn-outline-secondary">
                                            <i class="bi bi-upload"></i> Upload New Logo
                                            <input type="file" name="logo" accept="image/*">
                                        </div>
                                    </div>
                                    <small class="text-muted">Recommended size: 200x100px. Max size: 2MB</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Invoice Numbering Card -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-sort-numeric-up"></i> Invoice Numbering</h5>
                            <span class="badge-custom">Auto-increment</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="row g-4">
                                <div class="col-md-4">
                                    <label class="form-label">Invoice Prefix</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Prefix</span>
                                        <input type="text" class="form-control" name="invoice_prefix" 
                                               value="<?php echo htmlspecialchars($settings['invoice_prefix']); ?>" 
                                               placeholder="INV" maxlength="10">
                                    </div>
                                    <small class="text-muted">e.g., INV, SALE, BILL</small>
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label">Starting Number</label>
                                    <input type="number" class="form-control" name="invoice_start" 
                                           value="<?php echo $settings['invoice_start']; ?>" min="1" step="1">
                                    <small class="text-muted">Next invoice will be: <strong><?php echo htmlspecialchars($next_invoice); ?></strong></small>
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label">Format Preview</label>
                                    <div class="form-control bg-light" style="border: none; padding: 12px 16px;">
                                        <code><?php echo htmlspecialchars($settings['invoice_prefix']); ?>_00001</code> → 
                                        <code><?php echo htmlspecialchars($next_invoice); ?></code>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-box mt-3">
                                <i class="bi bi-info-circle"></i>
                                <strong>How it works:</strong> The invoice number automatically increments after each sale. 
                                For example, if your prefix is "INV" and start number is 1, the first invoice will be INV00001, 
                                then INV00002, and so on.
                            </div>
                        </div>
                    </div>

                    <!-- Bank Details Card -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-bank"></i> Bank Details</h5>
                            <span class="badge-custom">Shown on invoice</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" name="bank_name" 
                                           value="<?php echo htmlspecialchars($settings['bank_name']); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Branch</label>
                                    <input type="text" class="form-control" name="branch" 
                                           value="<?php echo htmlspecialchars($settings['branch']); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Account Number</label>
                                    <input type="text" class="form-control" name="account_number" 
                                           value="<?php echo htmlspecialchars($settings['account_number']); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">IFSC Code</label>
                                    <input type="text" class="form-control" name="ifsc" 
                                           value="<?php echo htmlspecialchars($settings['ifsc']); ?>" 
                                           placeholder="UBIN0575976">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">UPI ID (Optional)</label>
                                    <input type="text" class="form-control" name="upi_id" 
                                           value="<?php echo htmlspecialchars($settings['upi_id']); ?>" 
                                           placeholder="sriplaats@okhdfcbank">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="d-flex justify-content-between gap-3 mb-4">
                        <div>
                            <button type="button" class="btn btn-outline-danger" onclick="confirmReset()">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset to Default
                            </button>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="dashboard.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-success" id="submitBtn">
                                <i class="bi bi-check-circle"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Reset Form (Hidden) -->
                <form method="POST" action="invoice-setting.php" id="resetForm">
                    <input type="hidden" name="action" value="reset_settings">
                </form>

                <!-- Recent Activity -->
                <?php if ($recent_activity && $recent_activity->num_rows > 0): ?>
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-clock-history"></i> Recent Activity</h5>
                            <span class="badge-custom">Last 10 updates</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="activity-log">
                                <?php while ($activity = $recent_activity->fetch_assoc()): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="bi bi-pencil"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?> updated invoice settings
                                            </div>
                                            <div class="activity-time">
                                                <i class="bi bi-clock me-1"></i>
                                                <?php echo date('d M Y, h:i A', strtotime($activity['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Help Section -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-question-circle"></i> Help & Information</h5>
                    </div>
                    <div class="card-body-custom">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="d-flex gap-3">
                                    <div style="font-size: 24px; color: var(--primary);">
                                        <i class="bi bi-file-text"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-semibold mb-1">Invoice Number Format</h6>
                                        <p class="text-muted small">Use any prefix followed by 5-digit sequential numbers. The system automatically increments after each sale.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="d-flex gap-3">
                                    <div style="font-size: 24px; color: var(--primary);">
                                        <i class="bi bi-building"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-semibold mb-1">Company Details</h6>
                                        <p class="text-muted small">These details appear on every invoice. Ensure your GSTIN is correct as it's legally required.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="d-flex gap-3">
                                    <div style="font-size: 24px; color: var(--primary);">
                                        <i class="bi bi-bank"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-semibold mb-1">Bank Information</h6>
                                        <p class="text-muted small">Bank details are printed at the bottom of invoices for customer payment reference.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Reset Confirmation Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Reset</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to reset all invoice settings to default values?</p>
                <p class="text-danger"><small>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="document.getElementById('resetForm').submit();">
                    Reset Settings
                </button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize any select2 if needed
    $('.form-select').select2({
        minimumResultsForSearch: 10
    });
});

// Preview invoice number as user types
document.getElementById('invoice_prefix')?.addEventListener('input', updatePreview);
document.getElementById('invoice_start')?.addEventListener('input', updatePreview);

function updatePreview() {
    const prefix = document.getElementById('invoice_prefix').value || 'INV';
    const start = document.getElementById('invoice_start').value || 1;
    const preview = prefix + String(parseInt(start)).padStart(5, '0');
    document.querySelector('.preview-value').textContent = preview;
}

// Logo upload preview
document.querySelector('input[name="logo"]')?.addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.querySelector('.logo-preview');
            preview.innerHTML = `<img src="${e.target.result}" alt="Company Logo">`;
        }
        reader.readAsDataURL(this.files[0]);
    }
});

// Reset confirmation
function confirmReset() {
    const modal = new bootstrap.Modal(document.getElementById('resetModal'));
    modal.show();
}

// Form submission
document.getElementById('settingsForm')?.addEventListener('submit', function(e) {
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
});

// Preview start number as user types
document.getElementById('invoice_start')?.addEventListener('input', function() {
    const val = parseInt(this.value) || 1;
    if (val < 1) this.value = 1;
});
</script>
</body>
</html>