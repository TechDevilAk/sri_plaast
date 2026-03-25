<?php
$currentPage = 'create-job';
$pageTitle = 'Create Job';
require_once 'includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $job_type = trim($_POST['job_type'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $c_quantity = intval($_POST['c_quantity'] ?? 0);
    $media = trim($_POST['media'] ?? '');
    $rate = floatval($_POST['rate'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $others = floatval($_POST['others'] ?? 0);
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $payment_mode = trim($_POST['payment_mode'] ?? 'cash');
    $expenses = floatval($_POST['expenses'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    $total_amount = round($amount + $others, 2);

    if (empty($customer_name) || empty($job_type)) {
        $error = 'Customer name and job type are required.';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO jobs (customer_name, job_type, quantity, c_quantity, media, rate, amount, others, total_amount, payment_mode, expenses, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiisddddsds", $customer_name, $job_type, $quantity, $c_quantity, $media, $rate, $amount, $others, $total_amount, $payment_mode, $expenses, $notes);
            if (!$stmt->execute()) throw new Exception('Failed to create job: ' . $stmt->error);
            $jobId = $stmt->insert_id;
            $stmt->close();

            $checkCust = $conn->prepare("SELECT id FROM customers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))");
            $checkCust->bind_param("s", $customer_name);
            $checkCust->execute();
            $custResult = $checkCust->get_result();
            if ($custResult->num_rows === 0) {
                $insertCust = $conn->prepare("INSERT INTO customers (name) VALUES (?)");
                $insertCust->bind_param("s", $customer_name);
                if (!$insertCust->execute()) throw new Exception('Failed to create customer.');
                $insertCust->close();
            }
            $checkCust->close();

            $invoicePrefix = 'INV-';
            $defaultTaxRate = 0;
            $prefRow = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'invoice_prefix'");
            if ($prefRow && $r = $prefRow->fetch_assoc()) $invoicePrefix = $r['setting_value'];
            $taxRow = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'tax_rate'");
            if ($taxRow && $r = $taxRow->fetch_assoc()) $defaultTaxRate = floatval($r['setting_value']);

            $lastInv = $conn->query("SELECT invoice_number FROM invoices WHERE invoice_number LIKE '" . $conn->real_escape_string($invoicePrefix) . "%' ORDER BY id DESC LIMIT 1");
            $nextSeq = 1;
            if ($lastInv && $lr = $lastInv->fetch_assoc()) {
                $lastNum = intval(str_replace($invoicePrefix, '', $lr['invoice_number']));
                $nextSeq = $lastNum + 1;
            }
            $invoiceNumber = $invoicePrefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

            $subtotal = $total_amount;
            $taxAmount = round($subtotal * $defaultTaxRate / 100, 2);
            $invoiceTotal = round($subtotal + $taxAmount, 2);
            $jobIdsStr = strval($jobId);
            $dueDate = date('Y-m-d', strtotime('+30 days'));
            $invStatus = 'unpaid';

            $invStmt = $conn->prepare("INSERT INTO invoices (invoice_number, customer_name, job_ids, subtotal, tax_percent, tax_amount, total, status, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $invStmt->bind_param("sssddddss", $invoiceNumber, $customer_name, $jobIdsStr, $subtotal, $defaultTaxRate, $taxAmount, $invoiceTotal, $invStatus, $dueDate);
            if (!$invStmt->execute()) throw new Exception('Failed to create invoice.');
            $invStmt->close();

            $conn->commit();
            $success = "Job created successfully! Job #$jobId | Invoice $invoiceNumber generated.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Surya Press - Create Job</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
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
          <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Create New Job</h4>
          <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Fill in the job card details below</p>
        </div>
        <a href="jobs.php" class="btn-outline-custom">
          <i class="bi bi-arrow-left"></i> Back to Jobs
        </a>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success d-flex align-items-center gap-2" role="alert" data-testid="alert-success">
          <i class="bi bi-check-circle-fill"></i>
          <?php echo htmlspecialchars($success); ?>
          <a href="create-job.php" class="ms-auto btn btn-sm btn-success">Create Another</a>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2" role="alert" data-testid="alert-error">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <!-- Job Creation Form -->
      <form method="POST" action="create-job.php" data-testid="form-create-job">
        <div class="row g-3">

          <!-- Left Column: Job Details -->
          <div class="col-lg-8">
            <div class="dashboard-card">
              <div class="card-header">
                <div>
                  <h5><i class="bi bi-clipboard2-plus me-2"></i>Job Card Details</h5>
                  <p>Enter the printing job information</p>
                </div>
              </div>
              <div class="card-body">
                <div class="row g-3">

                  <!-- Customer Name -->
                  <div class="col-md-6">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" class="form-control" placeholder="Enter customer name" required data-testid="input-customer-name"
                      value="<?php echo htmlspecialchars($_POST['customer_name'] ?? ''); ?>">
                  </div>

                  <!-- Job Type -->
                  <div class="col-md-6">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Job Type <span class="text-danger">*</span></label>
                    <select name="job_type" class="form-select" required data-testid="select-job-type">
                      <option value="">Select Job Type</option>
                      <optgroup label="B - Black & White">
                        <option value="B-A3" <?php echo ($_POST['job_type'] ?? '') === 'B-A3' ? 'selected' : ''; ?>>B - A3</option>
                        <option value="B-A4" <?php echo ($_POST['job_type'] ?? '') === 'B-A4' ? 'selected' : ''; ?>>B - A4</option>
                      </optgroup>
                      <optgroup label="C - Color">
                        <option value="C-A3" <?php echo ($_POST['job_type'] ?? '') === 'C-A3' ? 'selected' : ''; ?>>C - A3</option>
                        <option value="C-A4" <?php echo ($_POST['job_type'] ?? '') === 'C-A4' ? 'selected' : ''; ?>>C - A4</option>
                      </optgroup>
                      <optgroup label="C - Long">
                        <option value="C-Long SS" <?php echo ($_POST['job_type'] ?? '') === 'C-Long SS' ? 'selected' : ''; ?>>C - Long S.S (Single Side)</option>
                        <option value="C-Long DS" <?php echo ($_POST['job_type'] ?? '') === 'C-Long DS' ? 'selected' : ''; ?>>C - Long D.S (Double Side)</option>
                      </optgroup>
                    </select>
                  </div>

                  <!-- Quantity -->
                  <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Quantity</label>
                    <input type="number" name="quantity" class="form-control" placeholder="0" min="0" data-testid="input-quantity"
                      value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>">
                  </div>

                  <!-- C.Quantity -->
                  <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size: 13px;">C.Quantity</label>
                    <input type="number" name="c_quantity" class="form-control" placeholder="0" min="0" data-testid="input-c-quantity"
                      value="<?php echo htmlspecialchars($_POST['c_quantity'] ?? ''); ?>">
                  </div>

                  <!-- Media -->
                  <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Media</label>
                    <input type="text" name="media" class="form-control" placeholder="e.g. Glossy, Matte, Bond" data-testid="input-media"
                      value="<?php echo htmlspecialchars($_POST['media'] ?? ''); ?>">
                  </div>

                  <!-- Rate -->
                  <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Rate (&#8377;)</label>
                    <input type="number" name="rate" class="form-control" placeholder="0.00" min="0" step="0.01" data-testid="input-rate"
                      value="<?php echo htmlspecialchars($_POST['rate'] ?? ''); ?>" oninput="calculateTotal()">
                  </div>

                  <!-- Amount -->
                  <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Amount (&#8377;)</label>
                    <input type="number" name="amount" class="form-control" placeholder="0.00" min="0" step="0.01" data-testid="input-amount"
                      value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" oninput="calculateTotal()">
                  </div>

                  <!-- Others -->
                  <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Others (&#8377;)</label>
                    <input type="number" name="others" class="form-control" placeholder="0.00" min="0" step="0.01" data-testid="input-others"
                      value="<?php echo htmlspecialchars($_POST['others'] ?? ''); ?>" oninput="calculateTotal()">
                  </div>

                  <!-- Total Amount -->
                  <div class="col-md-6">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Total Amount (&#8377;)</label>
                    <input type="number" name="total_amount" class="form-control fw-bold" placeholder="0.00" min="0" step="0.01" data-testid="input-total-amount"
                      value="<?php echo htmlspecialchars($_POST['total_amount'] ?? ''); ?>"
                      style="font-size: 18px; background: var(--primary-bg); border-color: var(--primary-light);">
                  </div>

                  <!-- Expenses -->
                  <div class="col-md-6">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Expenses (&#8377;)</label>
                    <input type="number" name="expenses" class="form-control" placeholder="0.00" min="0" step="0.01" data-testid="input-expenses"
                      value="<?php echo htmlspecialchars($_POST['expenses'] ?? ''); ?>">
                  </div>

                  <!-- Notes -->
                  <div class="col-12">
                    <label class="form-label fw-semibold" style="font-size: 13px;">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Any additional notes or special instructions..." data-testid="input-notes"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                  </div>

                </div>
              </div>
            </div>
          </div>

          <!-- Right Column: Payment & Submit -->
          <div class="col-lg-4">
            <div class="dashboard-card mb-3">
              <div class="card-header">
                <div>
                  <h5><i class="bi bi-credit-card me-2"></i>Payment</h5>
                  <p>Select payment method</p>
                </div>
              </div>
              <div class="card-body">
                <div class="d-flex flex-column gap-2">
                  <label class="d-flex align-items-center gap-3 p-3 rounded-2 border cursor-pointer payment-option" style="cursor: pointer;">
                    <input type="radio" name="payment_mode" value="cash" class="form-check-input m-0" <?php echo ($_POST['payment_mode'] ?? 'cash') === 'cash' ? 'checked' : ''; ?> data-testid="radio-cash">
                    <div class="d-flex align-items-center gap-2">
                      <i class="bi bi-cash-stack" style="font-size: 20px; color: var(--success);"></i>
                      <div>
                        <div class="fw-semibold" style="font-size: 14px;">Cash</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Payment received in cash</div>
                      </div>
                    </div>
                  </label>
                  <label class="d-flex align-items-center gap-3 p-3 rounded-2 border cursor-pointer payment-option" style="cursor: pointer;">
                    <input type="radio" name="payment_mode" value="bank" class="form-check-input m-0" <?php echo ($_POST['payment_mode'] ?? '') === 'bank' ? 'checked' : ''; ?> data-testid="radio-bank">
                    <div class="d-flex align-items-center gap-2">
                      <i class="bi bi-bank" style="font-size: 20px; color: var(--primary);"></i>
                      <div>
                        <div class="fw-semibold" style="font-size: 14px;">Bank Transfer</div>
                        <div style="font-size: 12px; color: var(--text-muted);">UPI / NEFT / IMPS / Cheque</div>
                      </div>
                    </div>
                  </label>
                </div>
              </div>
            </div>

            <!-- Submit -->
            <div class="dashboard-card">
              <div class="card-body">
                <button type="submit" class="btn-primary-custom w-100 justify-content-center py-3" style="font-size: 15px;" data-testid="button-submit-job">
                  <i class="bi bi-check-circle"></i> Create Job
                </button>
                <a href="jobs.php" class="btn-outline-custom w-100 justify-content-center mt-2 py-2" data-testid="button-cancel">
                  Cancel
                </a>
              </div>
            </div>
          </div>

        </div>
      </form>

    </div>

    <?php include 'includes/footer.php'; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function calculateTotal() {
    const amount = parseFloat(document.querySelector('[name="amount"]').value) || 0;
    const others = parseFloat(document.querySelector('[name="others"]').value) || 0;
    document.querySelector('[name="total_amount"]').value = (amount + others).toFixed(2);
  }
</script>
</body>
</html>
