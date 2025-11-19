<?php
require_once __DIR__ . '/config/session.php';
requireLogin();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        throw new Exception('Invalid request. Please refresh and try again.');
    }
    
    // Validate registration ID
    if (!isset($_POST['registration_id']) || !is_numeric($_POST['registration_id'])) {
        throw new Exception('Invalid registration ID.');
    }
    
    $registration_id = intval($_POST['registration_id']);
    $admin_id = $_SESSION['admin_id'];
    
    // Get registration details
    $stmt = $conn->prepare("
        SELECT 
            sr.id,
            sr.studio_id,
            sr.status,
            sr.workflow_stage,
            s.StudioName,
            s.owner_name,
            s.owner_email
        FROM studio_registrations sr
        JOIN studios s ON sr.studio_id = s.StudioID
        WHERE sr.id = ?
    ");
    $stmt->bind_param("i", $registration_id);
    $stmt->execute();
    $registration = $stmt->get_result()->fetch_assoc();
    
    if (!$registration) {
        throw new Exception('Registration not found.');
    }
    
    // Check if already sent
    if ($registration['workflow_stage'] !== 'initial') {
        throw new Exception('Upload link has already been sent for this registration.');
    }
    
    // Generate unique token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    // Insert token
    $tokenStmt = $conn->prepare("
        INSERT INTO document_upload_tokens 
        (registration_id, token, sent_to_email, sent_by_admin, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $tokenStmt->bind_param(
        "issis",
        $registration_id,
        $token,
        $registration['owner_email'],
        $admin_id,
        $expires_at
    );
    
    if (!$tokenStmt->execute()) {
        throw new Exception('Failed to generate upload link.');
    }
    
    // Update registration workflow
    $updateStmt = $conn->prepare("
        UPDATE studio_registrations 
        SET workflow_stage = 'upload_link_sent',
            status = 'awaiting_documents',
            upload_link_sent_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->bind_param("i", $registration_id);
    $updateStmt->execute();
    
    // Generate upload URL
    $upload_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
                . "://" . $_SERVER['HTTP_HOST'] 
                . dirname(dirname($_SERVER['SCRIPT_NAME'])) 
                . "/upload-documents.php?token=" . $token;
    
    // Send email notification (simplified version - you should use PHPMailer or similar)
    $to = $registration['owner_email'];
    $subject = "Complete Your Studio Registration - Museek";
    $message = "
Hello {$registration['owner_name']},

Thank you for registering your studio \"{$registration['StudioName']}\" on Museek!

To complete your registration, please:
1. Upload required documents (Business Permit, Valid ID, Studio Photos)
2. Submit registration payment via GCash (PHP 1,500.00)

Click the link below to get started:
{$upload_url}

This link will expire in 7 days.

If you have any questions, please contact us at support@museek.com

Best regards,
Museek Team
";
    
    $headers = "From: Museek Admin <noreply@museek.com>\r\n";
    $headers .= "Reply-To: support@museek.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    // Attempt to send email
    $emailSent = @mail($to, $subject, $message, $headers);
    
    // Create notification for studio owner
    $notifStmt = $conn->prepare("
        INSERT INTO notifications 
        (StudioOwnerID, Message, notification_type, action_url, IsRead, CreatedAt)
        VALUES (
            (SELECT OwnerID FROM studios WHERE StudioID = ?),
            'Please complete your studio registration by uploading required documents and payment proof. Check your email for the secure upload link.',
            'upload_link_sent',
            ?,
            0,
            NOW()
        )
    ");
    $notifStmt->bind_param("is", $registration['studio_id'], $upload_url);
    $notifStmt->execute();
    
    // Log audit trail
    $auditStmt = $conn->prepare("
        INSERT INTO audit_logs 
        (admin_id, action, entity_type, entity_id, new_value, workflow_stage)
        VALUES (?, 'SENT_UPLOAD_LINK', 'studio_registration', ?, ?, 'upload_link_sent')
    ");
    $upload_link_info = json_encode([
        'email' => $registration['owner_email'],
        'expires_at' => $expires_at,
        'token_id' => $conn->insert_id
    ]);
    $auditStmt->bind_param("iis", $admin_id, $registration_id, $upload_link_info);
    $auditStmt->execute();
    
    $response['success'] = true;
    $response['message'] = $emailSent 
        ? 'Upload link sent successfully to ' . $registration['owner_email']
        : 'Upload link generated (email notification may have failed - please contact owner directly)';
    $response['upload_url'] = $upload_url; // For admin reference
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
