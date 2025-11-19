<?php
session_start();
require_once '../../shared/config/db pdo.php';

$response = ['success' => false, 'message' => ''];

try {
    // Validate inputs
    if (!isset($_POST['token']) || !isset($_POST['payment_id']) || !isset($_POST['reference_number'])) {
        throw new Exception('Missing required fields');
    }

    $token = $_POST['token'];
    $payment_id = intval($_POST['payment_id']);
    $reference_number = trim($_POST['reference_number']);
    $phone_number = trim($_POST['phone_number']);

    // Validate token and payment
    $db = Database::getInstance()->getConnection();
    $tokenStmt = $db->prepare("
        SELECT 
            dut.*, 
            sr.id as registration_id,
            sr.studio_id,
            sr.workflow_stage,
            rp.id as payment_id,
            rp.payment_status
        FROM document_upload_tokens dut
        JOIN studio_registrations sr ON dut.registration_id = sr.id
        JOIN registration_payments rp ON sr.id = rp.registration_id
        WHERE dut.token = ? 
          AND rp.id = ?
          AND dut.is_active = 1 
          AND dut.expires_at > NOW()
    ");
    $tokenStmt->bind_param("si", $token, $payment_id);
    $tokenStmt->execute();
    $tokenData = $tokenStmt->get_result()->fetch_assoc();

    if (!$tokenData) {
        throw new Exception('Invalid payment request');
    }

    // Check if payment already submitted
    if ($tokenData['payment_status'] !== 'pending') {
        throw new Exception('Payment has already been submitted');
    }

    // Validate file upload (payment proof)
    if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Payment proof is required');
    }

    $file = $_FILES['payment_proof'];
    $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
    $max_size = 5 * 1024 * 1024; // 5MB

    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        throw new Exception('Invalid file type. Only JPG, PNG, and PDF files are allowed.');
    }

    // Validate file size
    if ($file['size'] > $max_size) {
        throw new Exception('File too large. Maximum size is 5MB.');
    }

    // Create upload directory
    $upload_dir = 'uploads/payments/' . $tokenData['studio_id'];
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'payment_proof_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $file_path = $upload_dir . '/' . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('Failed to save payment proof. Please try again.');
    }

    // Update payment record
    $updateStmt = $db->prepare("
        UPDATE registration_payments 
        SET gcash_reference_number = ?,
            gcash_phone_number = ?,
            payment_proof_path = ?,
            payment_status = 'submitted',
            payment_date = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->bind_param("sssi", $reference_number, $phone_number, $file_path, $payment_id);

    if (!$updateStmt->execute()) {
        unlink($file_path); // Delete uploaded file on error
        throw new Exception('Database error. Please try again.');
    }

    // Update registration workflow
    $workflowStmt = $db->prepare("
        UPDATE studio_registrations 
        SET workflow_stage = 'payment_completed',
            status = 'payment_submitted',
            payment_completed_at = NOW(),
            admin_notified_at = NOW()
        WHERE id = ?
    ");
    $workflowStmt->bind_param("i", $tokenData['registration_id']);
    $workflowStmt->execute();

    // Create notification for admin
    $notifStmt = $db->prepare("
        INSERT INTO notifications 
        (StudioOwnerID, Message, notification_type, action_url, IsRead, CreatedAt)
        SELECT 
            NULL as StudioOwnerID,
            CONCAT('New registration payment received from Studio ID ', ?) as Message,
            'payment_received' as notification_type,
            CONCAT('admin/approval-detail.php?id=', ?) as action_url,
            0 as IsRead,
            NOW() as CreatedAt
        FROM admin_users
        WHERE role IN ('admin', 'super_admin')
        LIMIT 1
    ");
    $notifStmt->bind_param("ii", $tokenData['studio_id'], $tokenData['registration_id']);
    $notifStmt->execute();

    // Send confirmation notification to owner
    $ownerNotifStmt = $db->prepare("
        INSERT INTO notifications 
        (StudioOwnerID, Message, notification_type, IsRead, CreatedAt)
        VALUES (
            (SELECT OwnerID FROM studios WHERE StudioID = ?),
            'Your payment has been received and is being verified by our team. You will be notified once your registration is approved.',
            'payment_received',
            0,
            NOW()
        )
    ");
    $ownerNotifStmt->bind_param("i", $tokenData['studio_id']);
    $ownerNotifStmt->execute();

    $response['success'] = true;
    $response['message'] = 'Payment submitted successfully! Admin will review your registration shortly.';
    $response['redirect'] = 'upload-documents.php?token=' . urlencode($token);

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// Redirect with message
if ($response['success']) {
    header('Location: upload-documents.php?token=' . urlencode($token) . '&success=' . urlencode($response['message']));
} else {
    header('Location: upload-documents.php?token=' . urlencode($token) . '&error=' . urlencode($response['message']));
}
exit;
