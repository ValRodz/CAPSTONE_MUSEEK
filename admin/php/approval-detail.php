<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/models/Registration.php';
require_once __DIR__ . '/models/Studio.php';
require_once __DIR__ . '/models/AuditLog.php';
// Replace missing DocumentLink model usage with direct token generation + email
require_once __DIR__ . '/config/database.php'; // ADD THIS LINE
require_once __DIR__ . '/../../shared/config/mail_config.php';

$regId = $_GET['id'] ?? 0;
$registration = new Registration();
$studio = new Studio();
$auditLog = new AuditLog();

$reg = $registration->getById($regId);

if (!$reg) {
    header('Location: ../php/approvals.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrf_token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $note = $_POST['decision_note'] ?? '';
        $adminId = $_SESSION['admin_id'];
        
        if ($action === 'request_documents') {
            // Generate document upload token and send email
            $db = Database::getInstance()->getConnection();
            try {
                $toEmail = trim($reg['owner_email'] ?? '');
                $toName  = trim($reg['owner_name'] ?? '');
                $token   = bin2hex(random_bytes(32));
                $stmt = $db->prepare("INSERT INTO document_upload_tokens (registration_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
                $stmt->execute([$regId, $token]);

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $uploadUrl = $scheme . '://' . $host . '/auth/php/upload-documents.php?token=' . $token;

                $subject = 'Museek – Document Upload Link';
                $studioName = $reg['business_name'] ?? 'Your Studio';
                $html = '<p>Hello ' . htmlspecialchars($toName ?: 'Studio Owner') . ',</p>' .
                        '<p>Please upload your verification documents for "' . htmlspecialchars($studioName) . '" using the secure link below:</p>' .
                        '<p><a href="' . htmlspecialchars($uploadUrl) . '" style="display:inline-block;padding:10px 16px;background:#111;color:#fff;text-decoration:none;border-radius:6px">Open Upload Page</a></p>' .
                        '<p>This link will expire in 7 days.</p>' .
                        '<p>Regards,<br>Museek Admin</p>';
                $alt = "Please complete your registration here: $uploadUrl\nThis link will expire in 7 days.";
                if ($toEmail !== '') {
                    sendTransactionalEmail($toEmail, $toName ?: $toEmail, $subject, $html, $alt);
                }

                $auditLog->log('Admin', $adminId, 'REQUESTED_DOCUMENTS', 'Registration', $regId, "Document upload link generated for {$reg['owner_email']}");
                $success = "Document upload link generated and emailed to the owner!<br><strong>" . htmlspecialchars($uploadUrl) . "</strong>";
                $reg = $registration->getById($regId);
            } catch (Throwable $e) {
                $error = 'Failed to generate document upload link.';
            }
        } elseif ($action === 'delete_document') {
            // Admin deletes a single document (DB + file)
            $docId = (int)($_POST['document_id'] ?? 0);
            if ($docId <= 0) {
                $error = 'Invalid document ID.';
            } else {
                try {
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("SELECT document_id, registration_id, file_path, file_name, document_type FROM documents WHERE document_id = ? AND registration_id = ?");
                    $stmt->execute([$docId, $regId]);
                    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$doc) {
                        $error = 'Document not found for this registration.';
                    } else {
                        // Resolve absolute path and constrain deletion to uploads/documents/<regId>
                        $baseDir = realpath(__DIR__ . '/../../uploads/documents/' . $regId);
                        $absPath = realpath(__DIR__ . '/../../' . ($doc['file_path'] ?? ''));
                        if ($absPath && $baseDir && strpos($absPath, $baseDir) === 0 && file_exists($absPath)) {
                            @unlink($absPath);
                        }
                        // Delete DB record
                        $del = $db->prepare("DELETE FROM documents WHERE document_id = ? AND registration_id = ?");
                        $del->execute([$docId, $regId]);
                        // Audit log
                        $auditLog->log('Admin', $adminId, 'DELETED_DOCUMENT', 'Registration', $regId, "Deleted {$doc['document_type']} – {$doc['file_name']}");
                        $success = 'Document deleted successfully.';
                        // Refresh registration and documents
                        $reg = $registration->getById($regId);
                        $documents = $registration->getDocuments($regId);
                    }
                } catch (Throwable $e) {
                    $error = 'Failed to delete document.';
                }
            }
        } elseif ($action === 'update_coordinates') {
            $latitude = $_POST['latitude'] ?? null;
            $longitude = $_POST['longitude'] ?? null;
            
            if ($latitude && $longitude) {
                // FIXED: Use correct column names and StudioID PK
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("UPDATE studios SET Latitude = ?, Longitude = ? WHERE StudioID = ?");
                if ($stmt->execute([$latitude, $longitude, $reg['studio_id']])) {
                    $auditLog->log('Admin', $adminId, 'UPDATED_COORDINATES', 'Studio', $reg['studio_id'], "Set coordinates to {$latitude}, {$longitude}");
                    $success = 'Coordinates updated successfully!';
                    $reg = $registration->getById($regId);
                } else {
                    $error = 'Failed to update coordinates.';
                }
            } else {
                $error = 'Both latitude and longitude are required.';
            }
        } elseif ($action === 'approve') {
            if ($registration->approve($regId, $adminId, $note)) {
                $auditLog->log('Admin', $adminId, 'APPROVED', 'Studio', $reg['studio_id'], "Registration #$regId approved. Note: $note");
                $success = 'Studio approved successfully!';
                $reg = $registration->getById($regId);
            } else {
                $error = 'Failed to approve registration.';
            }
        } elseif ($action === 'reject') {
            if (empty($note)) {
                $error = 'Decision note is required for rejection.';
            } else {
                if ($registration->reject($regId, $adminId, $note)) {
                    $auditLog->log('Admin', $adminId, 'REJECTED', 'Studio', $reg['studio_id'], "Registration #$regId rejected. Reason: $note");
                    $success = 'Studio rejected successfully!';
                    $reg = $registration->getById($regId);
                } else {
                    $error = 'Failed to reject registration.';
                }
            }
        } elseif ($action === 'verify_payment') {
            $paymentId = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
            if ($paymentId > 0) {
                $db = Database::getInstance()->getConnection();
                
                // Fetch payment info for email
                $paymentStmt = $db->prepare("SELECT amount FROM registration_payments WHERE payment_id = ?");
                $paymentStmt->execute([$paymentId]);
                $paymentData = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                
                $updateStmt = $db->prepare(
                    "UPDATE registration_payments 
                     SET payment_status = 'completed', 
                         processed_by = ?, 
                         updated_at = NOW()
                     WHERE payment_id = ? AND registration_id = ?"
                );
                if ($updateStmt->execute([$adminId, $paymentId, $regId])) {
                    $auditLog->log('Admin', $adminId, 'PAYMENT_VERIFIED', 'Registration', $regId, "Payment ID $paymentId verified");
                    
                    // Send payment confirmation email to owner
                    $subject = 'Payment Verified - Museek Studio Registration';
                    $amount = $paymentData ? number_format((float)$paymentData['amount'], 2) : '0.00';
                    $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">' .
                            '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">' .
                            '<h1 style="color: white; margin: 0; font-size: 28px;">Payment Verified! ✓</h1>' .
                            '</div>' .
                            '<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 0 0 8px 8px;">' .
                            '<p style="font-size: 16px; color: #333;">Dear <strong>' . htmlspecialchars($reg['owner_name']) . '</strong>,</p>' .
                            '<p style="font-size: 15px; color: #555; line-height: 1.6;">Great news! Your payment for <strong>' . htmlspecialchars($reg['business_name']) . '</strong> has been successfully verified.</p>' .
                            '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">' .
                            '<p style="margin: 0; font-size: 14px; color: #666;">Payment Amount</p>' .
                            '<p style="margin: 5px 0 0 0; font-size: 32px; font-weight: bold; color: #28a745;">₱' . $amount . '</p>' .
                            '</div>' .
                            '<div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;">' .
                            '<p style="margin: 0; font-size: 14px; color: #856404;"><strong>⏳ What\'s Next?</strong></p>' .
                            '<p style="margin: 8px 0 0 0; font-size: 14px; color: #856404;">Our admin team is now reviewing your studio registration. You will receive another email once your studio has been approved and is live on the Museek platform.</p>' .
                            '</div>' .
                            '<p style="font-size: 14px; color: #666; margin-top: 30px;">Thank you for choosing Museek! We\'re excited to have your studio join our platform.</p>' .
                            '<hr style="border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;">' .
                            '<p style="font-size: 13px; color: #999; margin: 0;">If you have any questions, please contact our support team.</p>' .
                            '<p style="font-size: 14px; color: #333; margin-top: 20px;">Best regards,<br><strong>Museek Admin Team</strong></p>' .
                            '</div>' .
                            '</div>';
                    $alt = "Payment Verified!\n\n" .
                           "Dear " . $reg['owner_name'] . ",\n\n" .
                           "Your payment for " . $reg['business_name'] . " has been successfully verified.\n\n" .
                           "Payment Amount: ₱" . $amount . "\n\n" .
                           "What's Next?\n" .
                           "Our admin team is now reviewing your studio registration. You will receive another email once your studio has been approved.\n\n" .
                           "Thank you for choosing Museek!\n\n" .
                           "Best regards,\n" .
                           "Museek Admin Team";
                    
                    sendTransactionalEmail($reg['owner_email'], $reg['owner_name'], $subject, $html, $alt);
                    
                    $success = 'Payment verified successfully! Confirmation email sent to owner. You can now proceed to approve the studio registration.';
                    $reg = $registration->getById($regId);
                } else {
                    $error = 'Failed to verify payment.';
                }
            } else {
                $error = 'Invalid payment ID.';
            }
        } elseif ($action === 'reject_payment') {
            $paymentId = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
            if ($paymentId > 0) {
                $db = Database::getInstance()->getConnection();
                $updateStmt = $db->prepare(
                    "UPDATE registration_payments 
                     SET payment_status = 'failed', 
                         processed_by = ?, 
                         notes = CONCAT(notes, ' [REJECTED BY ADMIN]'),
                         updated_at = NOW()
                     WHERE payment_id = ? AND registration_id = ?"
                );
                if ($updateStmt->execute([$adminId, $paymentId, $regId])) {
                    $auditLog->log('Admin', $adminId, 'PAYMENT_REJECTED', 'Registration', $regId, "Payment ID $paymentId rejected");
                    $success = 'Payment rejected. Owner will be notified to resubmit.';
                    $reg = $registration->getById($regId);
                } else {
                    $error = 'Failed to reject payment.';
                }
            } else {
                $error = 'Invalid payment ID.';
            }
        }
    }
}

$studioId = $reg['studio_id'] ?? null;
$services = $studioId ? $studio->getServices($studioId) : [];
$instructors = $studioId ? $studio->getInstructors($studioId) : [];
$schedules = $studioId ? $studio->getSchedules($studioId, 5) : [];
// Documents are linked to the registration, not the studio record
$documents = $registration->getDocuments($regId);
// Fetch studio info if studio already exists (for hours/location display)
$studioInfo = $studioId ? $studio->getById($studioId) : null;

// Fetch gallery photos if studio exists
$galleryPhotos = [];
if ($studioId) {
    $db = Database::getInstance()->getConnection();
    $galleryStmt = $db->prepare(
        "SELECT image_id, StudioID, file_path, caption, sort_order, uploaded_at 
         FROM studio_gallery 
         WHERE StudioID = ?
         ORDER BY sort_order ASC, uploaded_at DESC"
    );
    $galleryStmt->execute([$studioId]);
    $galleryPhotos = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch payment information
$paymentInfo = null;
$db = Database::getInstance()->getConnection();
$paymentStmt = $db->prepare(
    "SELECT payment_id, registration_id, amount, phone_num, payment_reference, 
            payment_status, payment_date, processed_by, notes, created_at, updated_at
     FROM registration_payments 
     WHERE registration_id = ?
     ORDER BY payment_id DESC LIMIT 1"
);
$paymentStmt->execute([$regId]);
$paymentInfo = $paymentStmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Registration Detail';
include __DIR__ . '/views/components/header.php';
?>

<div class="flex-between mb-4">
            <div class="page-header" style="margin-bottom: 0;">
<h1><?= htmlspecialchars($reg['business_name'] ?? 'Studio Registration') ?></h1>
                <p>Owner: <?= htmlspecialchars($reg['owner_name']) ?> (<?= htmlspecialchars($reg['owner_email']) ?>)</p>
            </div>
            <div>
                <?php
                $statusBadges = [
                    'approved' => 'badge-success',
                    'pending' => 'badge-warning',
                    'rejected' => 'badge-danger'
                ];
                $badgeClass = $statusBadges[$reg['registration_status']] ?? 'badge-secondary';
                ?>
                <span class="badge <?= $badgeClass ?>"><?= ucfirst($reg['registration_status']) ?></span>
            </div>
        </div>
        
        <?php /* Top quick action buttons removed; use Decision Panel below */ ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        
        <div class="tabs">
            <button class="tab active" onclick="switchTab('profile')">Profile</button>
            <button class="tab" onclick="switchTab('gallery')">Gallery (<?= count($galleryPhotos) ?>)</button>
            <button class="tab" onclick="switchTab('documents')">Documents</button>
            <button class="tab" onclick="switchTab('payment')">
                Payment 
                <?php if ($paymentInfo): ?>
                    <?php if ($paymentInfo['payment_status'] === 'completed'): ?>
                        ✓
                    <?php elseif (!empty($paymentInfo['payment_reference'])): ?>
                        ⏳
                    <?php else: ?>
                        ⚠
                    <?php endif; ?>
                <?php endif; ?>
            </button>
            <button class="tab" onclick="switchTab('map')">Map & Location</button>
            <button class="tab" onclick="switchTab('summary')">Summary</button>
        </div>
        
        <!-- PROFILE TAB -->
        <div class="tab-content active" id="profile">
            <div class="card">
                <h2>Studio Profile</h2>
                <table>
                    <tr>
                        <th style="width: 200px;">Studio Name</th>
                        <td><?= htmlspecialchars($reg['business_name'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Description</th>
                        <td><?= htmlspecialchars(isset($reg['business_type']) ? ucwords(str_replace('_', ' ', $reg['business_type'])) : 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Address</th>
                        <td><?= htmlspecialchars($studioInfo['Loc_Desc'] ?? ($reg['business_address'] ?? 'N/A')) ?></td>
                    </tr>
                    <tr>
                        <th>Phone</th>
                        <td><?= htmlspecialchars($reg['owner_phone'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td><?= htmlspecialchars($reg['owner_email'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Subscription Plan</th>
                        <td>
                            <?php
                            $planName = $reg['plan_name'] ?? 'Unknown';
                            $isFree = (stripos($planName, 'free') !== false);
                            $badgeClass = $isFree ? 'badge-success' : 'badge-primary';
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($planName) ?></span>
                            <?php if ($isFree): ?>
                                <span class="text-muted text-sm"> (No payment required)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Hours</th>
                        <td>
                            <?php if (!empty($studioInfo['Time_IN']) && !empty($studioInfo['Time_OUT'])): ?>
                                <?= date('H:i', strtotime($studioInfo['Time_IN'])) ?> - <?= date('H:i', strtotime($studioInfo['Time_OUT'])) ?>
                            <?php else: ?>
                                N/A - N/A
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Amenities</th>
                        <td>N/A</td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- GALLERY TAB -->
        <div class="tab-content" id="gallery">
            <div class="card">
                <h2><i class="bi bi-images"></i> Studio Gallery Photos (<?= count($galleryPhotos) ?>)</h2>
                <?php if (empty($galleryPhotos)): ?>
                    <div class="empty-state">
                        <p>No gallery photos uploaded yet</p>
                        <small class="text-muted">Studio owner can upload photos through their document upload link</small>
                    </div>
                <?php else: ?>
                    <div class="row g-3" style="margin-top: 20px;">
                        <?php foreach ($galleryPhotos as $photo): ?>
                            <div class="col-md-3 col-sm-4 col-6">
                                <div class="card h-100" style="overflow: hidden; border: 2px solid #ddd;">
                                    <div onclick="openFileModal('/<?= htmlspecialchars($photo['file_path']) ?>', 'Gallery Photo #<?= $photo['image_id'] ?>', 'jpg')" style="cursor: pointer;">
                                        <img src="/<?= htmlspecialchars($photo['file_path']) ?>" 
                                             class="card-img-top" 
                                             style="height: 200px; object-fit: cover; transition: transform 0.2s;"
                                             onmouseover="this.style.transform='scale(1.05)'"
                                             onmouseout="this.style.transform='scale(1)'"
                                             alt="Studio photo"
                                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23ddd\' width=\'200\' height=\'200\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3EImage not found%3C/text%3E%3C/svg%3E'">
                    </div>
                                    <div class="card-body p-2">
                                        <?php if (!empty($photo['caption'])): ?>
                                            <p class="mb-1" style="font-size: 13px; font-weight: 500;"><?= htmlspecialchars($photo['caption']) ?></p>
                <?php endif; ?>
                                        <small class="text-muted d-block">
                                            <i class="bi bi-calendar"></i> <?= date('M d, Y', strtotime($photo['uploaded_at'])) ?>
                                        </small>
                                        <small class="text-muted d-block">
                                            <i class="bi bi-sort-numeric-down"></i> Order: <?= $photo['sort_order'] ?>
                                        </small>
            </div>
        </div>
                            </div>
                                <?php endforeach; ?>
        </div>
        
                    <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                        <h5 style="margin-bottom: 10px; font-size: 14px;"><i class="bi bi-info-circle"></i> Gallery Info</h5>
                        <p style="margin: 0; font-size: 13px; color: #666;">
                            Total Photos: <strong><?= count($galleryPhotos) ?></strong><br>
                            These photos are displayed publicly on the studio's profile page.<br>
                            Photos are sorted by upload order (sort_order).
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- DOCUMENTS TAB -->
        <div class="tab-content" id="documents">
            <div class="card">
                <h2>Verification Documents (<?= count($documents) ?>)</h2>
                <?php if (empty($documents)): ?>
                    <div class="empty-state"><p>No verification documents uploaded</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>File Name</th><th>Type</th><th>Uploaded At</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                    <tr>
                                        <td>
                                            <?php
                                                $fileName = $doc['file_name'] ?? 'Document';
                                                $filePath = $doc['file_path'] ?? '';
                                                $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                                            ?>
                                            <?php if (!empty($filePath)): ?>
                                                <a href="javascript:void(0)" onclick="openFileModal('/<?= htmlspecialchars($filePath) ?>', '<?= htmlspecialchars($fileName) ?>', '<?= $fileType ?>')" style="cursor: pointer;">
                                                    <i class="bi bi-file-earmark-<?= in_array($fileType, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? 'image' : 'pdf' ?>"></i>
                                                    <?= htmlspecialchars($fileName) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= htmlspecialchars($fileName) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge badge-secondary"><?= htmlspecialchars(isset($doc['document_type']) ? ucwords(str_replace('_',' ', $doc['document_type'])) : 'Unknown') ?></span></td>
                                        <td><?= isset($doc['uploaded_at']) ? date('M d, Y H:i', strtotime($doc['uploaded_at'])) : 'N/A' ?></td>
                                        <td>
                                            <form method="POST" action="" onsubmit="return confirm('Delete this document? This action cannot be undone.');" style="display:inline;">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_document">
                                                <input type="hidden" name="document_id" value="<?= (int)($doc['document_id'] ?? 0) ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- PAYMENT TAB -->
        <div class="tab-content" id="payment">
            <div class="card">
                <h2>Subscription Payment Status</h2>
                
                <?php if (!$paymentInfo): ?>
                    <div class="empty-state">
                        <p>No payment record found for this registration.</p>
                        <p class="text-muted">Payment record will be created when owner receives payment link.</p>
                    </div>
                <?php else: ?>
                    <!-- Payment Status Card -->
                    <div style="margin-bottom: 20px; padding: 20px; background: <?= $paymentInfo['payment_status'] === 'completed' ? '#d4edda' : ($paymentInfo['payment_status'] === 'failed' ? '#f8d7da' : '#fff3cd') ?>; border-radius: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h3 style="margin: 0 0 10px 0; font-size: 18px;">
                                    <?php if ($paymentInfo['payment_status'] === 'completed'): ?>
                                        <i class="bi bi-check-circle-fill" style="color: #28a745;"></i> Payment Verified
                                    <?php elseif ($paymentInfo['payment_status'] === 'failed'): ?>
                                        <i class="bi bi-x-circle-fill" style="color: #dc3545;"></i> Payment Failed
                                    <?php elseif (!empty($paymentInfo['payment_reference'])): ?>
                                        <i class="bi bi-clock-fill" style="color: #ffc107;"></i> Awaiting Verification
                                    <?php else: ?>
                                        <i class="bi bi-info-circle-fill" style="color: #17a2b8;"></i> Payment Pending
                                    <?php endif; ?>
                                </h3>
                                <p style="margin: 0; color: #666;">
                                    <?= $paymentInfo['payment_status'] === 'completed' ? 'Payment has been verified and approved.' : 
                                        ($paymentInfo['payment_status'] === 'failed' ? 'Payment verification failed.' : 
                                        (!empty($paymentInfo['payment_reference']) ? 'Owner has submitted payment proof. Please verify below.' : 
                                        'Waiting for owner to submit payment.')) ?>
                                </p>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 24px; font-weight: bold; color: #667eea;">
                                    ₱<?= number_format((float)$paymentInfo['amount'], 2) ?>
                                </div>
                                <small class="text-muted">GCash Payment</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Details Table -->
                    <table>
                        <tr>
                            <th style="width: 200px;">Payment ID</th>
                            <td>#<?= $paymentInfo['payment_id'] ?></td>
                        </tr>
                        <tr>
                            <th>Amount</th>
                            <td>₱<?= number_format((float)$paymentInfo['amount'], 2) ?></td>
                        </tr>
                        <tr>
                            <th>GCash Number</th>
                            <td>
                                <?php if (!empty($paymentInfo['phone_num'])): ?>
                                    <code><?= htmlspecialchars($paymentInfo['phone_num']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">Not submitted yet</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>GCash Reference Number</th>
                            <td>
                                <?php if (!empty($paymentInfo['payment_reference'])): ?>
                                    <code><?= htmlspecialchars($paymentInfo['payment_reference']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">Not submitted yet</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <span class="badge badge-<?= $paymentInfo['payment_status'] === 'completed' ? 'success' : ($paymentInfo['payment_status'] === 'failed' ? 'danger' : 'warning') ?>">
                                    <?= ucfirst($paymentInfo['payment_status']) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Submitted Date</th>
                            <td>
                                <?= $paymentInfo['payment_date'] ? date('M d, Y h:i A', strtotime($paymentInfo['payment_date'])) : '<span class="text-muted">Not submitted</span>' ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Created At</th>
                            <td><?= date('M d, Y h:i A', strtotime($paymentInfo['created_at'])) ?></td>
                        </tr>
                        <tr>
                            <th>Last Updated</th>
                            <td><?= date('M d, Y h:i A', strtotime($paymentInfo['updated_at'])) ?></td>
                        </tr>
                        <?php if (!empty($paymentInfo['notes'])): ?>
                        <tr>
                            <th>Notes</th>
                            <td><?= nl2br(htmlspecialchars($paymentInfo['notes'])) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <!-- Payment Proof -->
                    <?php if (!empty($paymentInfo['notes']) && !empty($paymentInfo['payment_reference'])): ?>
                        <?php
                        // Extract payment proof filename from notes if available
                        $proofMatch = [];
                        if (preg_match('/Proof:\s*([^\s]+)/', $paymentInfo['notes'], $proofMatch)) {
                            $proofFilename = $proofMatch[1];
                            $proofPath = 'uploads/payment_proofs/' . $paymentInfo['registration_id'] . '/' . $proofFilename;
                            if (file_exists('../../' . $proofPath)):
                        ?>
                            <div style="margin-top: 30px;">
                                <h3>Payment Proof Screenshot</h3>
                                <div style="text-align: center; margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                                    <img src="/<?= htmlspecialchars($proofPath) ?>" 
                                         alt="Payment Proof" 
                                         style="max-width: 100%; max-height: 600px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer;"
                                         onclick="openFileModal('/<?= htmlspecialchars($proofPath) ?>', 'Payment Proof', 'jpg')">
                                    <p class="text-muted mt-2">
                                        <i class="bi bi-info-circle"></i> Click image to view full size
                                    </p>
                                </div>
                            </div>
                        <?php 
                            endif;
                        }
                        ?>
                    <?php endif; ?>
                    
                    <!-- Admin Actions -->
                    <?php if ($paymentInfo['payment_status'] === 'pending' && !empty($paymentInfo['payment_reference'])): ?>
                        <div style="margin-top: 30px; padding: 20px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 8px;">
                            <h3><i class="bi bi-exclamation-triangle"></i> Payment Verification Required</h3>
                            <p class="text-muted">The owner has submitted their payment proof. Please verify the GCash transaction before proceeding with studio approval.</p>
                            
                            <div style="background: #fff; padding: 15px; border-radius: 8px; margin: 15px 0;">
                                <strong>Verification Checklist:</strong>
                                <ul style="margin: 10px 0;">
                                    <li>✓ Reference number matches the screenshot</li>
                                    <li>✓ Amount matches (₱<?= number_format((float)$paymentInfo['amount'], 2) ?>)</li>
                                    <li>✓ GCash number is valid</li>
                                    <li>✓ Receipt is genuine and clear</li>
                                </ul>
                            </div>
                            
                            <form method="POST" style="display: inline-block; margin-right: 10px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="verify_payment">
                                <input type="hidden" name="payment_id" value="<?= $paymentInfo['payment_id'] ?>">
                                <button type="submit" class="btn btn-success" onclick="return confirm('Confirm that you have verified this GCash payment? This only marks the payment as received, you still need to approve the studio separately.')">
                                    <i class="bi bi-check-circle"></i> Verify Payment
                                </button>
                            </form>
                            
                            <form method="POST" style="display: inline-block;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reject_payment">
                                <input type="hidden" name="payment_id" value="<?= $paymentInfo['payment_id'] ?>">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this payment? The owner will need to resubmit.')">
                                    <i class="bi bi-x-circle"></i> Reject Payment
                                </button>
                            </form>
                            
                            <p style="margin-top: 15px; font-size: 13px; color: #856404;">
                                <i class="bi bi-info-circle"></i> <strong>Note:</strong> Payment verification is separate from studio approval. After verifying payment, go to the Summary tab to approve or disapprove the studio registration.
                            </p>
                        </div>
                    <?php elseif ($paymentInfo['payment_status'] === 'completed'): ?>
                        <div style="margin-top: 20px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 8px;">
                            <p style="margin: 0; color: #155724;">
                                <i class="bi bi-check-circle-fill"></i> <strong>Payment has been verified.</strong> You can now proceed to approve or disapprove the studio registration in the Summary tab.
                            </p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- MAP TAB -->
        <div class="tab-content" id="map">
            <div class="card">
                <h2>Map & Location</h2>
                <p class="text-muted">Click on the map to set the studio location. These coordinates will be used on approval.</p>

                <!-- Leaflet CSS/JS -->
                <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

                <div id="regMap" style="height: 400px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 12px;"></div>

                <div class="form-group">
                    <label class="form-label">Search Location</label>
                    <input type="text" id="locationSearch" class="form-input" placeholder="Enter address or location name..." value="<?= htmlspecialchars($reg['business_address'] ?? '') ?>" />
                    <small class="text-muted">Type a location and press Enter to search and place marker</small>
                </div>

                <!-- Hidden fields for coordinates -->
                <input type="hidden" id="mapLat" value="" />
                <input type="hidden" id="mapLng" value="" />

                <small class="text-muted">Registered Address: <?= htmlspecialchars($reg['business_address'] ?? 'Not provided') ?></small>

                <script>
                (function() {
                    const defaultCenter = [10.6764, 122.9503]; // Bacolod fallback
                    const latEl = document.getElementById('mapLat');
                    const lngEl = document.getElementById('mapLng');
                    const locationSearch = document.getElementById('locationSearch');
                    const existingLat = <?= isset($studioInfo['Latitude']) ? json_encode((float)$studioInfo['Latitude']) : 'null' ?>;
                    const existingLng = <?= isset($studioInfo['Longitude']) ? json_encode((float)$studioInfo['Longitude']) : 'null' ?>;
                    const hasExisting = (existingLat !== null && existingLng !== null && !isNaN(existingLat) && !isNaN(existingLng));
                    const startCenter = hasExisting ? [existingLat, existingLng] : defaultCenter;
                    const startZoom = hasExisting ? 15 : 13;
                    const map = L.map('regMap').setView(startCenter, startZoom);
                    window.regMap = map; // expose for tab show invalidateSize
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
                    let marker = null;

                    function updateHidden(lat, lng) {
                        const dLat = document.getElementById('decisionLat');
                        const dLng = document.getElementById('decisionLng');
                        if (dLat) dLat.value = lat;
                        if (dLng) dLng.value = lng;
                    }

                    function placeMarker(lat, lng) {
                        if (marker) map.removeLayer(marker);
                        marker = L.marker([lat, lng]).addTo(map);
                        latEl.value = lat.toFixed(6);
                        lngEl.value = lng.toFixed(6);
                        updateHidden(lat, lng);
                    }

                    // Geocode location using Nominatim
                    function searchLocation(query) {
                        if (!query.trim()) return;
                        
                        locationSearch.disabled = true;
                        fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1`)
                            .then(response => response.json())
                            .then(data => {
                                locationSearch.disabled = false;
                                if (data && data.length > 0) {
                                    const result = data[0];
                                    const lat = parseFloat(result.lat);
                                    const lng = parseFloat(result.lon);
                                    
                                    map.setView([lat, lng], 15);
                                    placeMarker(lat, lng);
                                    
                                    // Update search field with formatted address
                                    if (result.display_name) {
                                        locationSearch.value = result.display_name;
                                    }
                                } else {
                                    alert('Location not found. Please try a different search term.');
                                }
                            })
                            .catch(error => {
                                locationSearch.disabled = false;
                                console.error('Geocoding error:', error);
                                alert('Error searching for location. Please try again.');
                            });
                    }

                    // Search on Enter key
                    locationSearch.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            searchLocation(this.value);
                        }
                    });

                    // Click on map to place marker
                    map.on('click', function(e) { 
                        placeMarker(e.latlng.lat, e.latlng.lng);
                        
                        // Reverse geocode to update search field
                        fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${e.latlng.lat}&lon=${e.latlng.lng}`)
                            .then(response => response.json())
                            .then(data => {
                                if (data && data.display_name) {
                                    locationSearch.value = data.display_name;
                                }
                            })
                            .catch(error => console.error('Reverse geocoding error:', error));
                    });

                    // Place initial marker
                    placeMarker(startCenter[0], startCenter[1]);

                    setTimeout(() => map.invalidateSize(), 200);
                })();
                </script>
            </div>
        </div>
        
        <!-- SUMMARY TAB -->
        <div class="tab-content" id="summary">
            <div class="card">
                <h2>Registration Summary</h2>
                
                <?php
                // Calculate completeness dynamically
                $completenessScore = 0;
                $completenessFactors = [
                    'business_name' => ['value' => !empty($reg['business_name']), 'weight' => 15],
                    'owner_info' => ['value' => !empty($reg['owner_name']) && !empty($reg['owner_email']), 'weight' => 15],
                    'phone' => ['value' => !empty($reg['owner_phone']), 'weight' => 10],
                    'address' => ['value' => !empty($reg['business_address']), 'weight' => 10],
                    'plan' => ['value' => !empty($reg['plan_id']), 'weight' => 10],
                    'documents' => ['value' => count($documents) > 0, 'weight' => 20],
                    'payment' => ['value' => ($paymentInfo && $paymentInfo['payment_status'] === 'completed'), 'weight' => 20]
                ];
                
                foreach ($completenessFactors as $factor) {
                    if ($factor['value']) {
                        $completenessScore += $factor['weight'];
                    }
                }
                $comp = (int)$completenessScore;
                ?>
                
                <div style="margin-bottom: 24px; padding: 20px; background: <?= $comp >= 80 ? '#d4edda' : ($comp >= 50 ? '#fff3cd' : '#f8d7da') ?>; border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h3 style="margin: 0 0 5px 0; font-size: 16px;">Registration Completeness</h3>
                            <p style="margin: 0; color: #666; font-size: 14px;">
                                <?= $comp >= 80 ? '✓ Ready for final review' : ($comp >= 50 ? '⚠ Some items pending' : '❌ Major items missing') ?>
                            </p>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 36px; font-weight: bold; color: <?= $comp >= 80 ? '#28a745' : ($comp >= 50 ? '#ffc107' : '#dc3545') ?>;">
                                <?= $comp ?>%
                            </div>
                        </div>
                    </div>
                </div>
                
                <table>
                    <tr>
                        <th style="width: 200px;">Registration ID</th>
                        <td>#<?= $reg['registration_id'] ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td>
                            <?php
                            $statusBadges = [
                                'approved' => 'badge-success',
                                'pending' => 'badge-warning',
                                'payment_submitted' => 'badge-info',
                                'rejected' => 'badge-danger'
                            ];
                            $badgeClass = $statusBadges[$reg['registration_status']] ?? 'badge-secondary';
                            $statusLabel = ucwords(str_replace('_', ' ', $reg['registration_status']));
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th>Submitted At</th>
                        <td><?= !empty($reg['submitted_at']) ? date('M d, Y h:i A', strtotime($reg['submitted_at'])) : 'N/A' ?></td>
                    </tr>
                    <?php if (!empty($reg['reviewed_at']) || !empty($reg['approved_at'])): ?>
                        <tr>
                            <th>Reviewed At</th>
                            <td>
                                <?php
                                $reviewDate = $reg['approved_at'] ?? $reg['reviewed_at'] ?? null;
                                echo $reviewDate ? date('M d, Y h:i A', strtotime($reviewDate)) : 'N/A';
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($reg['reviewed_by']) || !empty($reg['approved_by'])): ?>
                        <tr>
                            <th>Reviewed By</th>
                            <td>Admin ID: <?= $reg['approved_by'] ?? $reg['reviewed_by'] ?? 'N/A' ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($reg['admin_notes'])): ?>
                        <tr>
                            <th>Admin Notes</th>
                            <td><?= nl2br(htmlspecialchars($reg['admin_notes'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($reg['rejection_reason'])): ?>
                        <tr>
                            <th>Rejection Reason</th>
                            <td style="color: #dc3545;"><strong><?= nl2br(htmlspecialchars($reg['rejection_reason'])) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Documents Uploaded</th>
                        <td>
                            <strong><?= count($documents) ?></strong> file(s)
                            <?php if (count($documents) === 0): ?>
                                <span class="badge badge-warning">Missing</span>
                            <?php else: ?>
                                <span class="badge badge-success">✓</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Payment Status</th>
                        <td>
                            <?php if ($paymentInfo): ?>
                                <span class="badge badge-<?= $paymentInfo['payment_status'] === 'completed' ? 'success' : ($paymentInfo['payment_status'] === 'failed' ? 'danger' : 'warning') ?>">
                                    <?= ucfirst($paymentInfo['payment_status']) ?>
                                </span>
                                <?php if ($paymentInfo['payment_status'] === 'completed'): ?>
                                    <span style="color: #28a745;">✓</span>
                                <?php elseif (!empty($paymentInfo['payment_reference'])): ?>
                                    <span class="text-muted">(Awaiting verification)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-secondary">No record</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Gallery Photos</th>
                        <td>
                            <strong><?= count($galleryPhotos) ?></strong> photo(s)
                        </td>
                    </tr>
                </table>
                
                <div style="margin-top: 24px; padding: 16px; background: #f8f9fa; border-radius: 8px;">
                    <h3 style="font-size: 15px; margin: 0 0 12px 0;"><i class="bi bi-flag"></i> System Flags</h3>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        <?php
                        $flags = [];
                        if (empty($reg['business_name'])) $flags[] = 'Missing business name';
                        if (empty($reg['owner_phone'])) $flags[] = 'Missing phone';
                        if (empty($reg['business_address'])) $flags[] = 'Missing address';
                        if (count($documents) === 0) $flags[] = 'No verification documents';
                        if ($paymentInfo && $paymentInfo['payment_status'] !== 'completed') {
                            $planName = $reg['plan_name'] ?? '';
                            $isFree = (stripos($planName, 'free') !== false);
                            if (!$isFree) {
                                $flags[] = 'Payment incomplete';
                            }
                        }
                        if (count($services) === 0) $flags[] = 'No services added';
                        
                        if (empty($flags)): ?>
                            <span class="badge badge-success"><i class="bi bi-check-circle"></i> No issues detected</span>
                        <?php else: ?>
                            <?php foreach ($flags as $flag): ?>
                                <span class="badge badge-warning" style="font-size: 13px; padding: 6px 12px;">
                                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($flag) ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($reg['registration_status'] === 'approved'): ?>
                    <div style="margin-top: 20px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 8px;">
                        <p style="margin: 0; color: #155724;">
                            <i class="bi bi-check-circle-fill"></i> <strong>This registration has been approved.</strong> The studio is now live on the platform.
                        </p>
                    </div>
                <?php elseif ($reg['registration_status'] === 'rejected'): ?>
                    <div style="margin-top: 20px; padding: 15px; background: #f8d7da; border-left: 4px solid #dc3545; border-radius: 8px;">
                        <p style="margin: 0; color: #721c24;">
                            <i class="bi bi-x-circle-fill"></i> <strong>This registration has been rejected.</strong>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ACTION PANELS -->
        <?php if ($reg['registration_status'] === 'pending' || $reg['registration_status'] === 'payment_submitted'): ?>
            
            <!-- COMMUNICATION PANEL -->
            <div class="card">
                <h2>Communication & Reminders</h2>
                <p class="text-muted">Send feedback or reminders to the studio owner</p>
                
                <!-- Quick Action Buttons -->
                <div style="margin-bottom: 16px;">
                    <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">Quick Actions</h4>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-sm btn-info" onclick="sendDocumentUploadLink()">
                            📄 Send Documents
                        </button>
                        <?php
                        $planName = $reg['plan_name'] ?? 'Unknown';
                        $isFree = (stripos($planName, 'free') !== false);
                        if (!$isFree):
                        ?>
                        <button type="button" class="btn btn-sm btn-warning" onclick="requestSubscriptionPayment(<?= (int)$regId ?>)">
                            💳 Request Payment
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-warning" onclick="sendQuickReminder('missing_docs')">
                            📋 Missing Documents
                        </button>
                        <button type="button" class="btn btn-sm btn-warning" onclick="sendQuickReminder('incomplete_info')">
                            ⚠️ Incomplete Information
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="sendQuickReminder('deadline_warning')">
                            ⏰ Deadline Reminder
                        </button>
                        <?php if (!$isFree): ?>
                        <button type="button" class="btn btn-sm btn-info" onclick="sendQuickReminder('payment_pending')">
                            💰 Payment Pending
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Custom Message Section -->
                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #ddd;">
                    <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">Custom Message</h4>
                    <form id="customMessageForm" onsubmit="sendCustomMessage(event)">
                        <?= csrfField() ?>
                        <div class="form-group">
                            <label class="form-label">Email Subject</label>
                            <input type="text" id="emailSubject" class="form-input" placeholder="e.g., Action Required: Studio Registration" />
                        </div>
                        <div class="form-group">
                            <label class="form-label">Message Template</label>
                            <select id="messageTemplate" class="form-input" onchange="loadTemplate()">
                                <option value="">-- Select Template (Optional) --</option>
                                <option value="missing_docs">Missing Documents</option>
                                <option value="incomplete_services">Incomplete Services Setup</option>
                                <option value="deadline_3days">3-Day Deadline Warning</option>
                                <option value="deadline_7days">7-Day Deadline Warning</option>
                                <?php if (!$isFree): ?>
                                <option value="payment_pending">Payment Pending</option>
                                <?php endif; ?>
                                <option value="custom">Custom Message</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Message</label>
                            <textarea id="customMessage" class="form-textarea" rows="6" placeholder="Type your message here..."></textarea>
                            <small class="text-muted">This message will be sent to: <?= htmlspecialchars($reg['owner_email']) ?></small>
                        </div>
                        <button type="submit" class="btn btn-primary">Send Email</button>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <h2>Decision Panel</h2>
                <form method="POST" action="" id="decisionForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" id="decisionAction">
                    <input type="hidden" name="latitude" id="decisionLat">
                    <input type="hidden" name="longitude" id="decisionLng">
                    <div class="form-group">
                        <label class="form-label">Decision Note</label>
                        <textarea name="decision_note" class="form-textarea" placeholder="Add a note about your decision..."></textarea>
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="button" onclick="openApprovalModal()" class="btn btn-success">Approve Studio</button>
                        <button type="button" onclick="openRejectionModal()" class="btn btn-danger">Disapprove Studio</button>
                        <a href="../php/approvals.php" class="btn btn-secondary">Back to Queue</a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="card">
                <a href="../php/approvals.php" class="btn btn-secondary">Back to Queue</a>
            </div>
        <?php endif; ?>

<!-- Approval Modal -->
<div id="approvalModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: var(--card); padding: 24px; border-radius: 8px; max-width: 560px; width: 90%;">
        <h2>Confirm Approval</h2>
        <p class="text-muted">Are you sure you want to mark this registration as approved? This will update the registration status and record your decision.</p>
        <div style="display: flex; gap: 8px; margin-top: 16px;">
            <button onclick="submitApproval()" class="btn btn-success">Confirm Approval</button>
            <button onclick="closeApprovalModal()" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div id="rejectionModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: var(--card); padding: 24px; border-radius: 8px; max-width: 500px; width: 90%;">
        <h2>Disapprove Studio Registration</h2>
        <p>Please provide a reason for disapproval</p>
        
        <div class="form-group">
            <label>Disapproval Reason *</label>
            <textarea id="rejectionReason" class="form-input" rows="4" placeholder="e.g., Permit expired, Invalid documents, etc." required></textarea>
        </div>
        
        <div class="alert alert-warning">
            ⚠️ This will delete all uploaded documents
        </div>
        
        <div style="display: flex; gap: 8px; margin-top: 16px;">
            <button onclick="submitRejection()" class="btn btn-danger">Confirm Disapproval</button>
            <button onclick="closeRejectionModal()" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<script>
function openApprovalModal() {
    document.getElementById('approvalModal').style.display = 'flex';
}

function closeApprovalModal() {
    document.getElementById('approvalModal').style.display = 'none';
}

function submitApproval() {
    // Set decision to approve and submit the decision form
    const actionInput = document.getElementById('decisionAction');
    if (actionInput) actionInput.value = 'approve';
    const form = document.getElementById('decisionForm');
    if (form) {
        form.submit();
    } else {
        // Fallback: direct POST via fetch
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '<?= csrfField() ?>' + '&action=approve&decision_note=' + encodeURIComponent(document.querySelector('textarea[name="decision_note"]').value || '')
        }).then(() => location.reload());
    }
}

function openRejectionModal() {
    document.getElementById('rejectionModal').style.display = 'flex';
}

function closeRejectionModal() {
    document.getElementById('rejectionModal').style.display = 'none';
}

function submitRejection() {
    const reason = document.getElementById('rejectionReason').value.trim();
    const modal = document.getElementById('rejectionModal');
    
    if (!reason) {
        alert('Please provide a disapproval reason');
        return;
    }
    
    if (!confirm('⚠️ FINAL CONFIRMATION\n\nAre you sure you want to DISAPPROVE this studio?\n\nThis will permanently:\n• Delete the studio and owner account\n• Remove all documents and gallery photos\n• Delete all related data (services, schedules, etc.)\n• Send rejection email to owner\n• Archive registration record with rejection reason\n\nThis action CANNOT be undone!')) {
        return;
    }
    
    // Show loading overlay
    const loadingOverlay = document.createElement('div');
    loadingOverlay.id = 'loadingOverlay';
    loadingOverlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 99999; display: flex; align-items: center; justify-content: center;';
    loadingOverlay.innerHTML = `
        <style>
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .loading-spinner {
                border: 5px solid #f3f3f3;
                border-top: 5px solid #dc3545;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                animation: spin 1s linear infinite;
                margin: 0 auto 20px;
            }
        </style>
        <div style="background: white; padding: 40px 50px; border-radius: 12px; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.3); max-width: 500px;">
            <div class="loading-spinner"></div>
            <h3 style="color: #dc3545; margin-bottom: 10px; font-size: 20px;">Processing Disapproval</h3>
            <p style="color: #666; margin: 0; font-size: 15px;">Deleting studio data and sending email notification...</p>
            <p style="color: #999; font-size: 13px; margin-top: 10px;">Please wait, this may take a moment.</p>
        </div>
    `;
    document.body.appendChild(loadingOverlay);
    
    // Close modal
    if (modal) modal.style.display = 'none';
    
    fetch('api/approval-actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=reject_with_reason&registration_id=<?= $regId ?>&reason=${encodeURIComponent(reason)}`
    })
    .then(r => {
        if (!r.ok) {
            throw new Error('Network response was not ok');
        }
        return r.json();
    })
    .then(data => {
        // Remove loading overlay
        if (loadingOverlay && loadingOverlay.parentNode) {
            loadingOverlay.remove();
        }
        
        if (data.success) {
            // Show success message
            alert('✓ Studio disapproved successfully!\n\n• All studio data has been deleted\n• Owner has been notified via email\n• Registration record archived with rejection reason');
            window.location.href = 'approvals.php';
        } else {
            alert('❌ Error: ' + (data.message || 'Failed to disapprove studio'));
            if (modal) modal.style.display = 'flex';
        }
    })
    .catch(error => {
        // Remove loading overlay
        if (loadingOverlay && loadingOverlay.parentNode) {
            loadingOverlay.remove();
        }
        
        console.error('Rejection error:', error);
        alert('❌ Network error occurred. Please try again or contact support.\n\nError: ' + error.message);
        if (modal) modal.style.display = 'flex';
    });
}

function switchTab(tabName) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById(tabName).classList.add('active');
    if (tabName === 'map' && window.regMap) {
        setTimeout(() => { try { window.regMap.invalidateSize(true); } catch (e) {} }, 150);
    }
}

function confirmDecision(event) {
    const action = event.submitter.value;
    const note = document.querySelector('textarea[name="decision_note"]').value;
    
    if (action === 'approve') {
        return confirm('Approve this studio? Services and schedules will become public.');
    } else if (action === 'reject') {
        if (!note.trim()) {
            alert('Please provide a reason for disapproval.');
            event.preventDefault();
            return false;
        }
        return confirm('Disapprove this studio? The owner will be notified with your reason.');
    }
}

// CSRF token for AJAX calls
const CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? (function_exists('generateCSRFToken') ? generateCSRFToken() : '')) ?>';

// Request monthly subscription payment via existing approvals backend
function requestSubscriptionPayment(registrationId) {
    if (!registrationId) { alert('Missing registration ID'); return; }
    if (!confirm('Create a subscription payment request for this registration?')) return;
    fetch('../php/approvals.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=' + encodeURIComponent('create_sub_payment') +
              '&registration_id=' + encodeURIComponent(registrationId) +
              '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(r => r.json())
    .then(d => {
        if (d && d.success) {
            alert('Subscription payment request created and email sent to the owner.');
        } else {
            alert('Error: ' + (d && d.message ? d.message : 'Failed to create payment request'));
        }
    })
    .catch(() => alert('Network error'));
}

// ==================== COMMUNICATION PANEL FUNCTIONS ====================

// Message templates
const messageTemplates = {
    missing_docs: {
        subject: "Action Required: Upload Verification Documents",
        body: `Dear <?= htmlspecialchars($reg['owner_name']) ?>,

We are reviewing your studio registration for "<?= htmlspecialchars($reg['business_name'] ?? $reg['studio_name'] ?? 'your studio') ?>".

We noticed that some required verification documents are missing or incomplete. To proceed with your registration, please upload the following documents:

• Business Permit
• DTI Registration
• BIR Certificate
• Mayor's Permit
• Valid ID

Please submit these documents within 7 days to avoid delays in your registration approval.

[UPLOAD_LINK]

If you have any questions, please don't hesitate to contact us.

Best regards,
Museek Admin Team`
    },
    incomplete_info: {
        subject: "Complete Your Studio Setup - Services Required",
        body: `Dear <?= htmlspecialchars($reg['owner_name']) ?>,

Your studio registration for "<?= htmlspecialchars($reg['business_name'] ?? $reg['studio_name'] ?? 'your studio') ?>" is under review.

We noticed that your studio profile is missing some important information. Please complete the following:

<?php
$missing = [];
if (empty($services) || count($services) === 0) {
    $missing[] = "• Add at least one service to your studio";
}
if (empty($reg['owner_phone'])) {
    $missing[] = "• Add your contact phone number";
}
if (empty($documents) || count($documents) === 0) {
    $missing[] = "• Upload verification documents";
}
echo implode("\n", $missing);
?>

This information is required for approval. Please complete this within 5 days.

Best regards,
Museek Admin Team`
    },
    deadline_warning: {
        subject: "Urgent: Complete Your Studio Registration",
        body: `Dear <?= htmlspecialchars($reg['owner_name']) ?>,

This is a reminder that your studio registration deadline is approaching.

You have LIMITED TIME to complete all requirements for "<?= htmlspecialchars($reg['business_name'] ?? $reg['studio_name'] ?? 'your studio') ?>".

Pending items:
<?php
$pending = [];
if (empty($services) || count($services) === 0) {
    $pending[] = "• Add services";
}
if (empty($documents) || count($documents) === 0) {
    $pending[] = "• Upload documents";
}
if (empty($paymentInfo['payment_status']) || $paymentInfo['payment_status'] !== 'completed') {
    $pending[] = "• Payment Complete";
}
if (!empty($pending)) {
    echo implode("\n", $pending);
} else {
    echo "• Awaiting admin review";
}
?>

Please complete these requirements immediately to avoid registration cancellation.

Best regards,
Museek Admin Team`
    },
    payment_pending: {
        subject: "Payment Required: Complete Your Subscription",
        body: `Dear <?= htmlspecialchars($reg['owner_name']) ?>,

Your studio registration is almost complete!

To activate your studio on the Museek platform, please complete your subscription payment.

[PAYMENT_LINK]

Once payment is confirmed, we will proceed with the final review of your registration.

If you have any questions or need assistance, please contact our support team.

Best regards,
Museek Admin Team`
    },
    custom: {
        subject: "",
        body: ""
    }
};

async function sendQuickReminder(type) {
    if (!messageTemplates[type]) {
        alert('Invalid reminder type');
        return;
    }
    
    const template = messageTemplates[type];
    if (!confirm(`Send "${template.subject}" to the owner?`)) return;
    
    // If type is missing_docs or payment_reminder, generate the link first
    let messageBody = template.body;
    
    if (type === 'missing_docs' && messageBody.includes('[UPLOAD_LINK]')) {
        try {
            const response = await fetch('../php/approvals.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=generate_upload_link&registration_id=<?= $regId ?>&csrf_token=${CSRF_TOKEN}`
            });
            const data = await response.json();
            if (data && data.success && data.link) {
                const linkHtml = `\n\nClick the button below to upload your documents:\n${data.link}\n\n`;
                messageBody = messageBody.replace('[UPLOAD_LINK]', linkHtml);
            } else {
                alert('Warning: Could not generate upload link. Sending without link.');
                messageBody = messageBody.replace('[UPLOAD_LINK]', '\n\n[Please contact admin for upload link]\n\n');
            }
        } catch (e) {
            console.error('Failed to generate upload link:', e);
            alert('Warning: Could not generate upload link. Sending without link.');
            messageBody = messageBody.replace('[UPLOAD_LINK]', '\n\n[Please contact admin for upload link]\n\n');
        }
    }
    
    if (type === 'payment_pending' && messageBody.includes('[PAYMENT_LINK]')) {
        try {
            const response = await fetch('../php/approvals.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=generate_payment_link&registration_id=<?= $regId ?>&csrf_token=${CSRF_TOKEN}`
            });
            const data = await response.json();
            if (data && data.success && data.link) {
                const linkHtml = `\n\nClick the button below to complete your payment:\n${data.link}\n\n`;
                messageBody = messageBody.replace('[PAYMENT_LINK]', linkHtml);
            } else {
                alert('Warning: Could not generate payment link. Sending without link.');
                messageBody = messageBody.replace('[PAYMENT_LINK]', '\n\n[Please contact admin for payment link]\n\n');
            }
        } catch (e) {
            console.error('Failed to generate payment link:', e);
            alert('Warning: Could not generate payment link. Sending without link.');
            messageBody = messageBody.replace('[PAYMENT_LINK]', '\n\n[Please contact admin for payment link]\n\n');
        }
    }
    
    fetch('../php/approvals.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=send_reminder&registration_id=<?= $regId ?>&type=${type}&message_override=${encodeURIComponent(messageBody)}&csrf_token=${CSRF_TOKEN}`
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            alert('✓ Email sent successfully!');
            // Optionally reload to show communication history
            // location.reload();
        } else {
            alert('Error: ' + (data && data.message ? data.message : 'Failed to send email'));
        }
    })
    .catch(() => alert('Network error'));
}

// New function to send document upload link directly
function sendDocumentUploadLink() {
    if (!confirm('Send document upload link to the owner?')) return;
    
    // Use the existing form submission
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = CSRF_TOKEN;
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'request_documents';
    
    form.appendChild(csrfInput);
    form.appendChild(actionInput);
    document.body.appendChild(form);
    form.submit();
}

async function loadTemplate() {
    const templateSelect = document.getElementById('messageTemplate');
    const subjectInput = document.getElementById('emailSubject');
    const messageTextarea = document.getElementById('customMessage');
    
    const templateType = templateSelect.value;
    if (templateType && messageTemplates[templateType]) {
        const template = messageTemplates[templateType];
        subjectInput.value = template.subject;
        let body = template.body;
        
        // Show loading state
        if (body.includes('[UPLOAD_LINK]') || body.includes('[PAYMENT_LINK]')) {
            messageTextarea.value = body.replace('[UPLOAD_LINK]', '⏳ Generating upload link...').replace('[PAYMENT_LINK]', '⏳ Generating payment link...');
            messageTextarea.disabled = true;
        }
        
        // Automatically generate links for placeholders
        if (body.includes('[UPLOAD_LINK]')) {
            try {
                const response = await fetch('../php/approvals.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=generate_upload_link&registration_id=<?= $regId ?>&csrf_token=${CSRF_TOKEN}`
                });
                const data = await response.json();
                if (data && data.success && data.link) {
                    const linkHtml = `\n\nClick the button below to upload your documents:\n${data.link}\n\n`;
                    body = body.replace('[UPLOAD_LINK]', linkHtml);
                } else {
                    body = body.replace('[UPLOAD_LINK]', '\n\n[Upload link generation failed]\n\n');
                }
            } catch (e) {
                console.error('Failed to generate upload link:', e);
                body = body.replace('[UPLOAD_LINK]', '\n\n[Upload link generation failed]\n\n');
            }
        }
        
        if (body.includes('[PAYMENT_LINK]')) {
            try {
                const response = await fetch('../php/approvals.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=generate_payment_link&registration_id=<?= $regId ?>&csrf_token=${CSRF_TOKEN}`
                });
                const data = await response.json();
                if (data && data.success && data.link) {
                    const linkHtml = `\n\nClick the button below to complete your payment:\n${data.link}\n\n`;
                    body = body.replace('[PAYMENT_LINK]', linkHtml);
                } else {
                    body = body.replace('[PAYMENT_LINK]', '\n\n[Payment link generation failed]\n\n');
                }
            } catch (e) {
                console.error('Failed to generate payment link:', e);
                body = body.replace('[PAYMENT_LINK]', '\n\n[Payment link generation failed]\n\n');
            }
        }
        
        messageTextarea.value = body;
        messageTextarea.disabled = false;
    } else {
        subjectInput.value = '';
        messageTextarea.value = '';
        messageTextarea.disabled = false;
    }
}

function sendCustomMessage(event) {
    event.preventDefault();
    
    const subject = document.getElementById('emailSubject').value.trim();
    const message = document.getElementById('customMessage').value.trim();
    
    if (!subject || !message) {
        alert('Please fill in both subject and message');
        return;
    }
    
    if (!confirm('Send this custom message to the owner?')) return;
    
    fetch('../php/approvals.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=send_custom_message&registration_id=<?= $regId ?>&subject=${encodeURIComponent(subject)}&message=${encodeURIComponent(message)}&csrf_token=${CSRF_TOKEN}`
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            alert('✓ Email sent successfully!');
            document.getElementById('customMessageForm').reset();
            // Optionally reload to show communication history
            // location.reload();
        } else {
            alert('Error: ' + (data && data.message ? data.message : 'Failed to send email'));
        }
    })
    .catch(() => alert('Network error'));
}

// ==================== FILE VIEWER MODAL ====================
function openFileModal(filePath, fileName, fileType) {
    const modal = document.getElementById('fileViewerModal');
    const modalTitle = document.getElementById('fileModalTitle');
    const modalBody = document.getElementById('fileModalBody');
    const downloadBtn = document.getElementById('fileModalDownload');
    
    // Set title
    modalTitle.textContent = fileName;
    
    // Set download link
    downloadBtn.href = filePath;
    downloadBtn.download = fileName;
    
    // Clear previous content
    modalBody.innerHTML = '';
    
    // Check file type and render accordingly
    const imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    const pdfTypes = ['pdf'];
    
    if (imageTypes.includes(fileType.toLowerCase())) {
        // Display image
        const img = document.createElement('img');
        img.src = filePath;
        img.style.width = '100%';
        img.style.height = 'auto';
        img.style.maxHeight = '70vh';
        img.style.objectFit = 'contain';
        img.alt = fileName;
        img.onerror = function() {
            modalBody.innerHTML = '<div style="padding: 40px; text-align: center; color: #dc3545;"><i class="bi bi-exclamation-triangle" style="font-size: 48px;"></i><p style="margin-top: 16px;">Failed to load image</p></div>';
        };
        modalBody.appendChild(img);
    } else if (pdfTypes.includes(fileType.toLowerCase())) {
        // Display PDF in iframe
        const iframe = document.createElement('iframe');
        iframe.src = filePath;
        iframe.style.width = '100%';
        iframe.style.height = '70vh';
        iframe.style.border = 'none';
        iframe.onerror = function() {
            modalBody.innerHTML = '<div style="padding: 40px; text-align: center; color: #dc3545;"><i class="bi bi-exclamation-triangle" style="font-size: 48px;"></i><p style="margin-top: 16px;">Failed to load PDF</p><p><a href="' + filePath + '" target="_blank" class="btn btn-primary">Open in new tab</a></p></div>';
        };
        modalBody.appendChild(iframe);
    } else {
        // Unsupported file type
        modalBody.innerHTML = '<div style="padding: 40px; text-align: center;"><i class="bi bi-file-earmark" style="font-size: 48px; color: #6c757d;"></i><p style="margin-top: 16px;">Preview not available for this file type</p><p><a href="' + filePath + '" target="_blank" class="btn btn-primary">Open in new tab</a></p></div>';
    }
    
    // Show modal
    modal.style.display = 'flex';
}

function closeFileModal() {
    const modal = document.getElementById('fileViewerModal');
    modal.style.display = 'none';
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('fileViewerModal');
    if (event.target === modal) {
        closeFileModal();
    }
});

// Close modal with Escape key
window.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeFileModal();
    }
});
</script>

<!-- File Viewer Modal -->
<div id="fileViewerModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: var(--card); border-radius: 12px; max-width: 90%; max-height: 90%; width: 1000px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
        <!-- Modal Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--border-color, #ddd);">
            <h3 id="fileModalTitle" style="margin: 0; font-size: 18px; font-weight: 600;">File Viewer</h3>
            <div style="display: flex; gap: 12px; align-items: center;">
                <a id="fileModalDownload" href="#" download class="btn btn-sm btn-primary" style="text-decoration: none;">
                    <i class="bi bi-download"></i> Download
                </a>
                <button onclick="closeFileModal()" class="btn btn-sm btn-secondary" style="font-size: 20px; padding: 4px 12px; line-height: 1;">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div id="fileModalBody" style="flex: 1; overflow: auto; padding: 24px; display: flex; align-items: center; justify-content: center; background: #f8f9fa;">
            <!-- File content will be dynamically inserted here -->
        </div>
    </div>
</div>

<?php include __DIR__ . '/views/components/footer.php'; ?>