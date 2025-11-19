<?php
session_start();
require_once '../../admin/php/config/database.php';

// Get token from URL
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

$error = '';
$success = isset($_GET['success']) ? $_GET['success'] : '';
$tokenData = null;
$paymentRow = null;

if (empty($token)) {
        die('Invalid or missing payment link. Please use the link provided in your email.');
}

// Validate token and fetch registration
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare(
    "SELECT 
        dut.token, dut.expires_at, dut.registration_id,
        sr.registration_id AS registration_id,
        sr.business_name, sr.owner_name, sr.owner_email, sr.registration_status,
        sr.plan_id, sr.subscription_duration,
        sp.plan_name, sp.monthly_price, sp.yearly_price
     FROM document_upload_tokens dut
     JOIN studio_registrations sr ON dut.registration_id = sr.registration_id
     LEFT JOIN subscription_plans sp ON sr.plan_id = sp.plan_id
     WHERE dut.token = ? AND dut.expires_at > NOW()"
);
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenData) {
    die('Invalid or expired payment link. Please contact support.');
}

// Determine the correct price based on subscription duration
$planPrice = ($tokenData['subscription_duration'] === 'yearly') 
    ? (float)$tokenData['yearly_price'] 
    : (float)$tokenData['monthly_price'];

// Fetch subscription payment status from registration_payments table
$registrationId = (int)$tokenData['registration_id'];
$payStmt = $db->prepare("SELECT payment_id, amount, payment_status, payment_date, payment_reference, phone_num, notes FROM registration_payments WHERE registration_id = ? ORDER BY payment_id DESC LIMIT 1");
    $payStmt->execute([$registrationId]);
$paymentRow = $payStmt->fetch(PDO::FETCH_ASSOC);

// If no payment record exists, create one
if (!$paymentRow) {
    $amount = !empty($planPrice) ? $planPrice : 500.00;
    $insertStmt = $db->prepare("INSERT INTO registration_payments (registration_id, amount, payment_status, notes) VALUES (?, ?, 'pending', 'Subscription fee - awaiting payment')");
    $insertStmt->execute([$registrationId, $amount]);
    $paymentRow = [
        'payment_id' => $db->lastInsertId(),
        'amount' => $amount,
        'payment_status' => 'pending',
        'payment_date' => null,
        'payment_reference' => null,
        'phone_num' => null,
        'notes' => 'Subscription fee - awaiting payment'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Payment – Museek</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 40px 0; }
        .container-narrow { max-width: 900px; margin: 0 auto; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px 15px 0 0 !important; padding: 25px; }
        .progress-steps { display: flex; justify-content: space-between; margin: 30px 0; }
        .step { flex: 1; text-align: center; position: relative; }
        .step::after { content: ''; position: absolute; top: 20px; left: 50%; width: 100%; height: 2px; background: #dee2e6; z-index: -1; }
        .step:last-child::after { display: none; }
        .step-circle { width: 40px; height: 40px; border-radius: 50%; background: #dee2e6; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px; }
        .step.active .step-circle { background: #667eea; color: white; }
        .step.completed .step-circle { background: #28a745; color: white; }
        .gcash-info { background: #e7f3ff; border-left: 4px solid #0056b3; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .btn-submit { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; padding: 12px 40px; color: white; }
        .qr-container { text-align: center; margin: 30px 0; }
        .qr-container img { max-width: 300px; border: 3px solid #fff; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .payment-form { background: #f8f9fa; padding: 25px; border-radius: 10px; margin-top: 20px; }
        .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25); }
        .status-badge { font-size: 1rem; padding: 8px 15px; }
    </style>
</head>
<body>
    <div class="container-narrow">
        <div class="card">
            <div class="card-header">
                <h2 class="mb-0"><i class="bi bi-wallet2"></i> Subscription Payment</h2>
                <p class="mb-0 mt-2">Welcome, <?= htmlspecialchars($tokenData['owner_name']) ?>!</p>
            </div>
            <div class="card-body p-4">
                <!-- Progress Steps -->
                <div class="progress-steps">
                    <div class="step <?= ($paymentRow['payment_status'] === 'pending' && empty($paymentRow['payment_reference'])) ? 'active' : 'completed' ?>">
                        <div class="step-circle"><i class="bi bi-send"></i></div>
                        <small>Send via GCash</small>
                    </div>
                    <div class="step <?= (!empty($paymentRow['payment_reference']) && $paymentRow['payment_status'] === 'pending') ? 'active' : ($paymentRow['payment_status'] === 'completed' ? 'completed' : '') ?>">
                        <div class="step-circle"><i class="bi bi-file-text"></i></div>
                        <small>Submit Details</small>
                    </div>
                    <div class="step <?= ($paymentRow['payment_status'] === 'completed') ? 'completed active' : '' ?>">
                        <div class="step-circle"><i class="bi bi-check-circle"></i></div>
                        <small>Verified</small>
                    </div>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Payment Status Badge -->
                <div class="text-center mb-4">
                    <?php if ($paymentRow['payment_status'] === 'completed'): ?>
                        <span class="badge bg-success status-badge">
                            <i class="bi bi-check-circle-fill"></i> Payment Verified
                        </span>
                    <?php elseif (!empty($paymentRow['payment_reference'])): ?>
                        <span class="badge bg-warning text-dark status-badge">
                            <i class="bi bi-clock-fill"></i> Awaiting Admin Verification
                        </span>
                    <?php else: ?>
                        <span class="badge bg-info status-badge">
                            <i class="bi bi-info-circle-fill"></i> Payment Required
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Payment Details -->
                <div class="gcash-info">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-wallet2 me-3" style="font-size: 32px;"></i>
                        <div>
                            <strong>GCash Payment – <?= htmlspecialchars($tokenData['plan_name'] ?? 'Subscription') ?></strong>
                            <div class="text-muted">Amount: <strong class="text-primary">₱<?= number_format((float)$paymentRow['amount'], 2) ?></strong></div>
                            <?php if ($paymentRow['payment_date']): ?>
                                <div class="text-muted small">Submitted: <?= date('M d, Y h:i A', strtotime($paymentRow['payment_date'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($paymentRow['payment_status'] === 'completed'): ?>
                    <!-- Payment Completed -->
                    <div class="alert alert-success">
                        <h5><i class="bi bi-check-circle-fill"></i> Payment Verified!</h5>
                        <p class="mb-2">Your payment has been verified by our admin team.</p>
                        <?php if ($paymentRow['payment_reference']): ?>
                            <small>Reference Number: <strong><?= htmlspecialchars($paymentRow['payment_reference']) ?></strong></small>
                    <?php endif; ?>
                </div>

                    <div class="alert alert-info">
                        <h6><i class="bi bi-hourglass-split"></i> Next Steps:</h6>
                        <p class="mb-0">
                            Your payment is confirmed. Our admin team will now review your studio registration documents and details. 
                            You will receive an email notification once your studio is <strong>approved or if any additional information is required</strong>.
                        </p>
                    </div>

                <?php elseif (!empty($paymentRow['payment_reference'])): ?>
                    <!-- Payment Submitted, Awaiting Verification -->
                    <div class="alert alert-warning">
                        <h5><i class="bi bi-clock"></i> Payment Under Review</h5>
                        <p class="mb-2">We've received your payment submission and it's being verified by our admin team.</p>
                        <small>Reference Number: <strong><?= htmlspecialchars($paymentRow['payment_reference']) ?></strong></small>
                    </div>

                <?php else: ?>
                    <!-- Payment Form -->
                    <!-- GCash Payment Details -->
                    <div style="background: #fff; border: 3px solid #007bff; border-radius: 12px; padding: 30px; margin: 30px 0; text-align: center;">
                        <h4 style="color: #007bff; margin-bottom: 20px;">
                            <i class="bi bi-wallet2"></i> Send Payment to GCash
                        </h4>
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                            <p style="margin: 0; font-size: 14px; color: #666;">Amount to Send:</p>
                            <h3 style="margin: 5px 0; color: #28a745; font-weight: bold; font-size: 28px;">
                                ₱<?= number_format((float)$paymentRow['amount'], 2) ?>
                            </h3>
                            <hr style="margin: 15px 0;">
                            <p style="margin: 0; font-size: 14px; color: #666;">GCash Number:</p>
                            <h2 style="margin: 10px 0; color: #007bff; font-weight: bold; font-size: 36px;" id="gcashNumber">
                                09508199489
                            </h2>
                            <button type="button" class="btn btn-sm btn-primary mt-2" onclick="copyGCashNumber()" id="copyBtn">
                                <i class="bi bi-clipboard"></i> Copy Number
                            </button>
                            <p style="margin: 10px 0 0 0; font-size: 13px; color: #666;">
                                <i class="bi bi-building"></i> Museek Studio Registration
                            </p>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('qrSection').style.display = document.getElementById('qrSection').style.display === 'none' ? 'block' : 'none'">
                                <i class="bi bi-qr-code"></i> Or Use QR Code (Optional)
                            </button>
                        </div>
                    </div>
                    
                    <script>
                    function copyGCashNumber() {
                        const number = '09508199489';
                        navigator.clipboard.writeText(number).then(function() {
                            const btn = document.getElementById('copyBtn');
                            const original = btn.innerHTML;
                            btn.innerHTML = '<i class="bi bi-check-circle"></i> Copied!';
                            btn.classList.remove('btn-primary');
                            btn.classList.add('btn-success');
                            setTimeout(function() {
                                btn.innerHTML = original;
                                btn.classList.remove('btn-success');
                                btn.classList.add('btn-primary');
                            }, 2000);
                        });
                    }
                    
                    // Auto-format GCash Reference Number (13 digits: XXXX XXXX XXXXX)
                    document.addEventListener('DOMContentLoaded', function() {
                        const refInput = document.getElementById('reference_number');
                        const refError = document.getElementById('refError');
                        
                        if (refInput) {
                            refInput.addEventListener('input', function(e) {
                                // Remove all non-numeric characters
                                let value = e.target.value.replace(/\D/g, '');
                                
                                // Limit to 13 digits
                                value = value.substring(0, 13);
                                
                                // Format as XXXX XXXX XXXXX
                                let formatted = '';
                                if (value.length > 0) {
                                    formatted = value.substring(0, 4);
                                }
                                if (value.length > 4) {
                                    formatted += ' ' + value.substring(4, 8);
                                }
                                if (value.length > 8) {
                                    formatted += ' ' + value.substring(8, 13);
                                }
                                
                                e.target.value = formatted;
                                
                                // Validation feedback
                                const digitCount = value.length;
                                if (digitCount > 0 && digitCount < 13) {
                                    refError.style.display = 'block';
                                    refInput.classList.add('is-invalid');
                                } else if (digitCount === 13) {
                                    refError.style.display = 'none';
                                    refInput.classList.remove('is-invalid');
                                    refInput.classList.add('is-valid');
                                } else {
                                    refError.style.display = 'none';
                                    refInput.classList.remove('is-invalid', 'is-valid');
                                }
                            });
                            
                            // Prevent paste of non-numeric characters
                            refInput.addEventListener('paste', function(e) {
                                e.preventDefault();
                                const pasteData = (e.clipboardData || window.clipboardData).getData('text');
                                const numericOnly = pasteData.replace(/\D/g, '');
                                
                                // Trigger input event to format
                                e.target.value = numericOnly;
                                e.target.dispatchEvent(new Event('input'));
                            });
                        }
                    });
                    </script>

                    <!-- QR Code Section (Collapsible) -->
                    <div id="qrSection" style="display: none; text-align: center; margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                        <img src="../../shared/assets/images/images/GCash.webp" alt="GCash QR Code" style="max-width: 250px; border: 2px solid #ddd; border-radius: 8px;">
                        <p class="mt-2 text-muted">
                            <i class="bi bi-info-circle"></i> Scan this QR code with your GCash app
                        </p>
                    </div>

                    <!-- Payment Submission Form -->
                    <div class="payment-form">
                        <h5 class="mb-3"><i class="bi bi-file-text"></i> After Payment, Submit Your Details:</h5>
                        <p class="text-muted">Once you've sent the payment via GCash, submit your transaction information below:</p>
                        
                        <form id="paymentForm" enctype="multipart/form-data">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            <input type="hidden" name="payment_id" value="<?= $paymentRow['payment_id'] ?>">
                            
                            <div class="mb-3">
                                <label for="sender_number" class="form-label">
                                    <i class="bi bi-phone"></i> Your GCash Mobile Number <span class="text-danger">*</span>
                                </label>
                                <input type="tel" class="form-control" id="sender_number" name="sender_number" 
                                       placeholder="09XX XXX XXXX" required pattern="[0-9]{11}">
                                <small class="form-text text-muted">Enter the 11-digit mobile number you used to send the payment</small>
                            </div>

                            <div class="mb-3" style="background: #fff3cd; padding: 15px; border-radius: 8px; border: 2px solid #ffc107;">
                                <label for="reference_number" class="form-label">
                                    <i class="bi bi-hash"></i> <strong>GCash Reference Number</strong> <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control form-control-lg" id="reference_number" name="reference_number" 
                                       placeholder="1234 5678 90123" required maxlength="17" 
                                       style="font-size: 18px; font-weight: bold; letter-spacing: 1px;" 
                                       inputmode="numeric">
                                <small class="form-text" style="color: #856404;">
                                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>IMPORTANT:</strong> This is the unique 13-digit number from your GCash receipt. Double-check before submitting!
                                </small>
                                <div id="refError" class="text-danger mt-1" style="font-size: 13px; display: none;">
                                    <i class="bi bi-exclamation-circle"></i> Reference number must be exactly 13 digits
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="payment_proof" class="form-label">
                                    <i class="bi bi-image"></i> Payment Screenshot <span class="text-danger">*</span>
                                </label>
                                <input type="file" class="form-control" id="payment_proof" name="payment_proof" 
                                       accept="image/jpeg,image/png,image/jpg" required>
                                <small class="form-text text-muted">Upload a clear screenshot of your GCash receipt (Max 5MB, JPG/PNG only)</small>
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">
                                    <i class="bi bi-chat-left-text"></i> Additional Notes (Optional)
                                </label>
                                <textarea class="form-control" id="notes" name="notes" rows="2" 
                                          placeholder="Any additional information..."></textarea>
                            </div>

                            <div id="alertArea"></div>

                            <button type="submit" class="btn btn-submit w-100" id="submitBtn">
                                <i class="bi bi-send"></i> Submit Payment Proof
                            </button>
                        </form>
                    </div>

                    <!-- Payment Instructions -->
                    <div class="alert alert-info mt-3">
                        <h6><i class="bi bi-lightbulb"></i> How to Complete Payment:</h6>
                        <ol class="mb-0">
                            <li>Open your GCash app</li>
                            <li>Select "Send Money"</li>
                            <li>Enter the GCash number shown above: <strong>09XX XXX XXXX</strong></li>
                            <li>Enter the amount: <strong>₱<?= number_format((float)$paymentRow['amount'], 2) ?></strong></li>
                            <li>Complete the transaction</li>
                            <li>Take a screenshot of your receipt</li>
                            <li>Copy your <strong>13-digit Reference Number</strong> from the receipt</li>
                            <li>Fill out the form above with your GCash number, reference number, and upload the screenshot</li>
                        </ol>
                        <hr>
                        <p class="mb-0"><i class="bi bi-info-circle-fill"></i> <strong>Important:</strong> The Reference Number is the most critical information for verification. Make sure it's correct!</p>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer text-center text-muted">
                <small>Need help? Contact us at support@museek.com</small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('paymentForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            const alertArea = document.getElementById('alertArea');
            const refInput = document.getElementById('reference_number');
            
            // Validate reference number has exactly 13 digits
            const refDigits = refInput.value.replace(/\D/g, '');
            if (refDigits.length !== 13) {
                alertArea.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Reference number must be exactly 13 digits</div>';
                refInput.focus();
                return;
            }
            
            // Create FormData and strip spaces from reference number
            const formData = new FormData(this);
            formData.set('reference_number', refDigits); // Send only digits to backend
            
            // Validate file size
            const fileInput = document.getElementById('payment_proof');
            if (fileInput.files[0] && fileInput.files[0].size > 5 * 1024 * 1024) {
                alertArea.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> File size must be less than 5MB</div>';
                return;
            }
            
            // Disable button and show loading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
            alertArea.innerHTML = '';
            
            try {
                const response = await fetch('process-payment-submission.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alertArea.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle"></i> ' + data.message + '</div>';
                    setTimeout(() => {
                        window.location.href = 'subscription-payment.php?token=<?= urlencode($token) ?>&success=' + encodeURIComponent(data.message);
                    }, 2000);
                } else {
                    alertArea.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> ' + data.message + '</div>';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-send"></i> Submit Payment Proof';
                }
            } catch (error) {
                alertArea.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Network error. Please try again.</div>';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-send"></i> Submit Payment Proof';
            }
        });
    </script>
</body>
</html>
