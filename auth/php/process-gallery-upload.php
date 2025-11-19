<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../shared/config/db pdo.php';

$response = ['success' => false, 'message' => ''];

try {
    // Validate inputs
    if (!isset($_POST['token']) || !isset($_POST['registration_id'])) {
        throw new Exception('Missing required fields');
    }

    $token = trim($_POST['token']);
    $registration_id = intval($_POST['registration_id']);
    
    // Validate token and get studio info
    $db = $pdo; // Use the same connection as gallery.php
    $tokenStmt = $db->prepare(
        "SELECT 
            dut.token_id, dut.token, dut.expires_at, dut.is_used, dut.registration_id,
            sr.registration_status, sr.studio_id
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

    $studioId = $tokenData['studio_id'];
    if (!$studioId) {
        throw new Exception('Gallery photos cannot be uploaded yet. Your registration must be approved first, which creates your studio record. Please upload your verification documents and wait for admin approval.');
    }
    
    // Verify studio exists in database
    $studioCheckStmt = $db->prepare("SELECT StudioID FROM studios WHERE StudioID = ?");
    $studioCheckStmt->execute([$studioId]);
    if (!$studioCheckStmt->fetch()) {
        throw new Exception("Studio ID {$studioId} does not exist in studios table. Please contact support.");
    }

    // Validate file upload
    if (!isset($_FILES['gallery_photo']) || $_FILES['gallery_photo']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error. Please try again.');
    }

    $file = $_FILES['gallery_photo'];
    $allowed_types = ['image/jpeg', 'image/png'];
    $max_size = 5 * 1024 * 1024; // 5MB

    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types)) {
        throw new Exception('Invalid file type. Only JPG and PNG images are allowed.');
    }

    // Validate file size
    if ($file['size'] > $max_size) {
        throw new Exception('File size exceeds maximum limit of 5MB.');
    }

    // Create upload directory if it doesn't exist
    $uploadDir = __DIR__ . '/../../uploads/studios/' . $studioId . '/gallery/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename with microseconds for uniqueness
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $timestamp = str_replace('.', '', microtime(true)); // e.g., 1700000000123456
    $randomHash = bin2hex(random_bytes(6));
    $newFileName = $timestamp . '_' . $randomHash . '.' . $extension;
    $uploadPath = $uploadDir . $newFileName;
    $dbPath = 'uploads/studios/' . $studioId . '/gallery/' . $newFileName;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception('Failed to save uploaded file.');
    }

    // Get next sort order (each image gets a new sort order)
    $sortStmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM studio_gallery WHERE StudioID = ?");
    $sortStmt->execute([$studioId]);
    $sortOrder = (int)$sortStmt->fetchColumn();
    
    error_log("DEBUG: About to insert gallery photo - StudioID={$studioId}, Path={$dbPath}, SortOrder={$sortOrder}");

    // Insert into studio_gallery table (ALWAYS INSERT, NEVER UPDATE)
    // Note: uploaded_at is not specified - relies on database DEFAULT CURRENT_TIMESTAMP
    $insertStmt = $db->prepare(
        "INSERT INTO studio_gallery (StudioID, file_path, caption, sort_order) 
         VALUES (?, ?, NULL, ?)"
    );
    
    try {
        $result = $insertStmt->execute([$studioId, $dbPath, $sortOrder]);
        
        if (!$result) {
            $errorInfo = $insertStmt->errorInfo();
            error_log("Gallery insert failed - Error Info: " . json_encode($errorInfo));
            throw new Exception('Database insert failed: ' . $errorInfo[2]);
        }
        
        $insertedId = $db->lastInsertId();
        
        if (!$insertedId || $insertedId <= 0) {
            throw new Exception('Insert succeeded but no ID returned. Check database constraints.');
        }
        
        error_log("SUCCESS: Gallery photo inserted - ID={$insertedId}, StudioID={$studioId}, Path={$dbPath}, SortOrder={$sortOrder}");
        
        // Verify the insert by querying back
        $verifyStmt = $db->prepare("SELECT image_id, StudioID, file_path, sort_order FROM studio_gallery WHERE image_id = ?");
        $verifyStmt->execute([$insertedId]);
        $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$verifyRow) {
            throw new Exception('Insert reported success but record not found in database!');
        }
        
        error_log("VERIFIED: Record exists in database - " . json_encode($verifyRow));
        
        $response['success'] = true;
        $response['message'] = 'Gallery photo uploaded successfully';
        $response['image_id'] = $insertedId;
        $response['file_path'] = $dbPath;
        $response['sort_order'] = $sortOrder;
        $response['debug'] = [
            'studio_id' => $studioId,
            'inserted_id' => $insertedId,
            'verified' => true
        ];
        
    } catch (PDOException $e) {
        error_log("PDO Exception during gallery insert: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        throw new Exception('Database error: ' . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;

} catch (Exception $e) {
    error_log("Gallery upload error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    $response['error_type'] = get_class($e);
    echo json_encode($response);
    exit;
} catch (Throwable $e) {
    error_log("Gallery upload fatal error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $response['success'] = false;
    $response['message'] = 'Fatal error: ' . $e->getMessage();
    $response['error_type'] = get_class($e);
    echo json_encode($response);
    exit;
}

