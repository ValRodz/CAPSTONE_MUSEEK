<?php
session_start();
require_once '../../admin/php/config/database.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Validate required fields
    if (empty($_POST['token']) || empty($_POST['payment_id']) || empty($_POST['reference_number']) || empty($_POST['sender_number'])) {
        throw new Exception('All required fields must be filled');
    }

    $token = trim($_POST['token']);
    $paymentId = (int)$_POST['payment_id'];
    $referenceNumber = trim($_POST['reference_number']);
    $senderNumber = trim($_POST['sender_number']);
    $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : '';

    // Validate file upload
    if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Payment screenshot is required');
    }

    $file = $_FILES['payment_proof'];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG and PNG images are allowed');
    }

    if ($file['size'] > $maxSize) {
        throw new Exception('File size must be less than 5MB');
    }

    // Validate token and payment
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare(
        "SELECT 
            dut.token, dut.registration_id,
            sr.business_name, sr.owner_name, sr.owner_email,
            rp.payment_id, rp.payment_status, rp.registration_id
         FROM document_upload_tokens dut
         JOIN studio_registrations sr ON dut.registration_id = sr.registration_id
         JOIN registration_payments rp ON sr.registration_id = rp.registration_id
         WHERE dut.token = ? 
           AND rp.payment_id = ?
           AND dut.expires_at > NOW()"
    );
    $stmt->execute([$token, $paymentId]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenData) {
        throw new Exception('Invalid payment request or expired link');
    }

    // Check if payment already submitted
    if ($tokenData['payment_status'] === 'completed') {
        throw new Exception('This payment has already been verified');
    }

    if ($tokenData['payment_status'] !== 'pending') {
        throw new Exception('Payment is not in a valid state for submission');
    }

    // Create upload directory
    $uploadDir = '../../uploads/payment_proofs/' . $tokenData['registration_id'] . '/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = 'payment_proof_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $filePath = $uploadDir . $fileName;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Failed to save payment proof');
    }

    // Update payment record
    $updateNotes = 'Payment submitted by owner. Proof: ' . $fileName;
    if (!empty($notes)) {
        $updateNotes .= '. Additional notes: ' . $notes;
    }

    $relativePath = 'uploads/payment_proofs/' . $tokenData['registration_id'] . '/' . $fileName;

    $updateStmt = $db->prepare(
        "UPDATE registration_payments 
         SET payment_reference = ?,
             phone_num = ?,
             payment_date = NOW(),
             notes = ?,
             updated_at = NOW()
         WHERE payment_id = ?"
    );
    
    if (!$updateStmt->execute([$referenceNumber, $senderNumber, $updateNotes, $paymentId])) {
        // Rollback: delete uploaded file
        unlink($filePath);
        throw new Exception('Failed to update payment record');
    }

    // Update registration status
    $regUpdateStmt = $db->prepare(
        "UPDATE studio_registrations 
         SET registration_status = 'payment_submitted',
             updated_at = NOW()
         WHERE registration_id = ?"
    );
    $regUpdateStmt->execute([$tokenData['registration_id']]);

    // Log the activity
    error_log("Payment proof submitted - Registration ID: {$tokenData['registration_id']}, Payment ID: {$paymentId}, Reference: {$referenceNumber}");

    $response['success'] = true;
    $response['message'] = 'Payment proof submitted successfully! Admin will verify your payment shortly.';

} catch (Exception $e) {
    error_log("Payment submission error: " . $e->getMessage());
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

