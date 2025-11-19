<?php
session_start();
require_once __DIR__ . '/../../admin/php/config/database.php';

$response = ['success' => false, 'message' => ''];

try {
    // Validate inputs
    if (!isset($_POST['token']) || !isset($_POST['registration_id']) || !isset($_POST['document_type'])) {
        throw new Exception('Missing required fields');
    }

    $token = trim($_POST['token']);
    $registration_id = intval($_POST['registration_id']);
    $document_type = trim($_POST['document_type']);
    
    // Validate document type (align with museek.sql enum)
    $allowed_types = ['business_permit', 'dti_registration', 'bir_certificate', 'mayors_permit', 'id_proof', 'other'];
    if (!in_array($document_type, $allowed_types)) {
        throw new Exception('Invalid document type');
    }

    // Validate token
    $db = Database::getInstance()->getConnection();
    $tokenStmt = $db->prepare(
        "SELECT 
            dut.token_id, dut.token, dut.expires_at, dut.is_used, dut.registration_id,
            sr.registration_status
         FROM document_upload_tokens dut
         JOIN studio_registrations sr ON dut.registration_id = sr.registration_id
         WHERE dut.token = ?
           AND dut.registration_id = ?
           AND dut.is_used = 0
           AND dut.expires_at > NOW()"
    );
    $tokenStmt->execute([$token, $registration_id]);
    $tokenData = $tokenStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenData) {
        throw new Exception('Invalid or expired upload link');
    }

    // Validate file upload
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error. Please try again.');
    }

    $file = $_FILES['document'];
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

    // Prevent duplicate insert for same file within a short window
    $dupStmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM documents 
         WHERE registration_id = ? 
           AND document_type = ? 
           AND file_name = ? 
           AND file_size = ? 
           AND mime_type = ? 
           AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)"
    );
    $dupStmt->execute([
        $registration_id,
        $document_type,
        $file['name'],
        (int)$file['size'],
        $mime_type
    ]);
    $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($dup['cnt']) && (int)$dup['cnt'] > 0) {
        $response['success'] = true;
        $response['message'] = 'Duplicate submission detected; existing document kept.';
        header('Location: upload-documents.php?token=' . urlencode($token) . '&success=' . urlencode($response['message']));
        exit;
    }

    // Create upload directory if it doesn't exist (store under project uploads/documents/<registration_id>)
    $upload_dir = __DIR__ . '/../../uploads/documents/' . $registration_id;
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $document_type . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $full_path = $upload_dir . '/' . $filename;
    $public_path = 'uploads/documents/' . $registration_id . '/' . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $full_path)) {
        throw new Exception('Failed to save file. Please try again.');
    }

    // Insert document record
    $insertStmt = $db->prepare(
        "INSERT INTO documents 
        (registration_id, document_type, file_name, file_path, file_size, mime_type)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $ok = $insertStmt->execute([
        $registration_id,
        $document_type,
        $file['name'],
        $public_path,
        (int)$file['size'],
        $mime_type
    ]);

    if (!$ok) {
        unlink($full_path); // Delete uploaded file on DB error
        throw new Exception('Database error. Please try again.');
    }

    // Update workflow stage if needed
    // Optional: count documents; no status update since schema uses registration_status (pending/approved/rejected)
    $countStmt = $db->prepare("SELECT COUNT(*) AS doc_count FROM documents WHERE registration_id = ?");
    $countStmt->execute([$registration_id]);
    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);

    // Note: Do NOT mark token as used here to allow multiple uploads.

    $response['success'] = true;
    $response['message'] = 'Document uploaded successfully!';

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log('Document upload error: ' . $e->getMessage());
}

// Always redirect back to upload page
if ($response['success']) {
    header('Location: upload-documents.php?token=' . urlencode($token) . '&success=' . urlencode($response['message']));
} else {
    header('Location: upload-documents.php?token=' . urlencode($token) . '&error=' . urlencode($response['message']));
}
exit;
