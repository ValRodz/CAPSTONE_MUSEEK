<?php
session_start();
require_once '../../shared/config/db pdo.php';
require_once '../../shared/config/mail_config.php';

// Check if owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header('Location: ../../auth/php/login.php');
    exit();
}

$ownerId = (int)$_SESSION['owner_id'];
$error = '';
$success = '';

// Fetch owner information
try {
    $ownerStmt = $pdo->prepare("SELECT OwnerID, Name, Email, Phone FROM studio_owners WHERE OwnerID = ?");
    $ownerStmt->execute([$ownerId]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$owner) {
        session_destroy();
        header('Location: ../../auth/php/login.php');
        exit();
    }
} catch (PDOException $e) {
    $error = "Database error. Please try again later.";
    $owner = null;
}

// Count studios owned by this owner
$studioCount = 0;
$studios = [];
try {
    $studioStmt = $pdo->prepare("SELECT StudioID, StudioName FROM studios WHERE OwnerID = ?");
    $studioStmt->execute([$ownerId]);
    $studios = $studioStmt->fetchAll(PDO::FETCH_ASSOC);
    $studioCount = count($studios);
} catch (PDOException $e) {
    // Ignore error, will show 0 studios
}

// Handle account deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $password = $_POST['password'] ?? '';
    $confirmText = $_POST['confirm_text'] ?? '';
    
    // Verify password
    if (empty($password)) {
        $error = 'Please enter your password to confirm deletion.';
    } elseif (strtoupper(trim($confirmText)) !== 'DELETE') {
        $error = 'Please type DELETE to confirm account deletion.';
    } else {
        // Verify password is correct
        try {
            $pwStmt = $pdo->prepare("SELECT Password FROM studio_owners WHERE OwnerID = ?");
            $pwStmt->execute([$ownerId]);
            $ownerData = $pwStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ownerData || !password_verify($password, $ownerData['Password'])) {
                $error = 'Incorrect password. Please try again.';
            } else {
                // Begin transaction for account deletion
                try {
                    $pdo->beginTransaction();
                    
                    // Get all studios owned by this owner
                    $studioStmt = $pdo->prepare("SELECT StudioID FROM studios WHERE OwnerID = ?");
                    $studioStmt->execute([$ownerId]);
                    $ownedStudios = $studioStmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($ownedStudios as $studioId) {
                        // Delete gallery photos (files + DB)
                        $galleryStmt = $pdo->prepare("SELECT image_id, file_path FROM studio_gallery WHERE StudioID = ?");
                        $galleryStmt->execute([$studioId]);
                        $galleryPhotos = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($galleryPhotos as $photo) {
                            $photoPath = '../../' . $photo['file_path'];
                            if (file_exists($photoPath)) {
                                @unlink($photoPath);
                            }
                        }
                        
                        $pdo->prepare("DELETE FROM studio_gallery WHERE StudioID = ?")->execute([$studioId]);
                        
                        // Delete studio services
                        $pdo->prepare("DELETE FROM studio_services WHERE StudioID = ?")->execute([$studioId]);
                        
                        // Delete instructors
                        $pdo->prepare("DELETE FROM instructors WHERE StudioID = ?")->execute([$studioId]);
                        
                        // Delete schedules
                        $pdo->prepare("DELETE FROM schedules WHERE StudioID = ?")->execute([$studioId]);
                        
                        // Delete amenities
                        $pdo->prepare("DELETE FROM amenities WHERE StudioID = ?")->execute([$studioId]);
                        
                        // Delete reviews/ratings
                        $pdo->prepare("DELETE FROM reviews WHERE StudioID = ?")->execute([$studioId]);
                        
                        // Delete bookings (mark as cancelled instead of hard delete to preserve history)
                        $pdo->prepare("UPDATE bookings SET Status = 'cancelled', Notes = CONCAT(COALESCE(Notes, ''), ' [STUDIO DELETED]') WHERE StudioID = ?")->execute([$studioId]);
                    }
                    
                    // Delete all studios
                    $pdo->prepare("DELETE FROM studios WHERE OwnerID = ?")->execute([$ownerId]);
                    
                    // Delete verification documents for this owner's registrations
                    $regStmt = $pdo->prepare("SELECT registration_id FROM studio_registrations WHERE owner_email = ?");
                    $regStmt->execute([$owner['Email']]);
                    $registrations = $regStmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($registrations as $regId) {
                        // Delete document files
                        $docStmt = $pdo->prepare("SELECT document_id, file_path FROM documents WHERE registration_id = ?");
                        $docStmt->execute([$regId]);
                        $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($documents as $doc) {
                            $docPath = '../../' . $doc['file_path'];
                            if (file_exists($docPath)) {
                                @unlink($docPath);
                            }
                        }
                        
                        $pdo->prepare("DELETE FROM documents WHERE registration_id = ?")->execute([$regId]);
                        
                        // Delete payment proofs
                        $paymentStmt = $pdo->prepare("SELECT payment_id, notes FROM registration_payments WHERE registration_id = ?");
                        $paymentStmt->execute([$regId]);
                        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($payments as $payment) {
                            // Extract and delete payment proof file if exists
                            if (!empty($payment['notes']) && preg_match('/Proof:\s*([^\s]+)/', $payment['notes'], $match)) {
                                $proofPath = '../../uploads/payment_proofs/' . $regId . '/' . $match[1];
                                if (file_exists($proofPath)) {
                                    @unlink($proofPath);
                                }
                            }
                        }
                        
                        $pdo->prepare("DELETE FROM registration_payments WHERE registration_id = ?")->execute([$regId]);
                    }
                    
                    // Delete document upload tokens
                    $pdo->prepare("DELETE FROM document_upload_tokens WHERE registration_id IN (SELECT registration_id FROM studio_registrations WHERE owner_email = ?)")->execute([$owner['Email']]);
                    
                    // Delete studio registrations
                    $pdo->prepare("DELETE FROM studio_registrations WHERE owner_email = ?")->execute([$owner['Email']]);
                    
                    // Finally, delete the owner account
                    $pdo->prepare("DELETE FROM studio_owners WHERE OwnerID = ?")->execute([$ownerId]);
                    
                    $pdo->commit();
                    
                    // Send confirmation email
                    $subject = 'Account Deleted - Museek';
                    $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">' .
                            '<div style="background: #dc3545; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">' .
                            '<h1 style="color: white; margin: 0; font-size: 28px;">Account Deleted</h1>' .
                            '</div>' .
                            '<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 0 0 8px 8px;">' .
                            '<p style="font-size: 16px; color: #333;">Dear <strong>' . htmlspecialchars($owner['Name']) . '</strong>,</p>' .
                            '<p style="font-size: 15px; color: #555; line-height: 1.6;">Your Museek studio owner account has been successfully deleted.</p>' .
                            '<div style="background: #f8d7da; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;">' .
                            '<p style="margin: 0; font-size: 14px; color: #721c24;">All your studios, data, and registrations have been permanently removed from our system.</p>' .
                            '</div>' .
                            '<p style="font-size: 14px; color: #666;">If you deleted your account by mistake, please contact our support team immediately.</p>' .
                            '<p style="font-size: 14px; color: #666;">We\'re sorry to see you go. If you change your mind, you\'re always welcome to register again.</p>' .
                            '<hr style="border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;">' .
                            '<p style="font-size: 14px; color: #333; margin-top: 20px;">Best regards,<br><strong>Museek Team</strong></p>' .
                            '</div>' .
                            '</div>';
                    $alt = "Account Deleted\n\n" .
                           "Dear " . $owner['Name'] . ",\n\n" .
                           "Your Museek studio owner account has been successfully deleted.\n\n" .
                           "All your studios, data, and registrations have been permanently removed from our system.\n\n" .
                           "Best regards,\nMuseek Team";
                    
                    sendTransactionalEmail($owner['Email'], $owner['Name'], $subject, $html, $alt);
                    
                    // Clear session and redirect
                    session_destroy();
                    header('Location: account_deleted.php');
                    exit();
                    
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Failed to delete account. Please try again or contact support. Error: ' . $e->getMessage();
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error. Please try again later.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Account - Museek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="../../shared/assets/fonts/font-awesome.min.css" rel="stylesheet" type="text/css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Source Sans Pro', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 700px;
            width: 100%;
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        
        .warning-box h3 {
            color: #856404;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .warning-box ul {
            margin-left: 20px;
            color: #856404;
        }
        
        .warning-box li {
            margin-bottom: 8px;
        }
        
        .danger-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        
        .danger-box h3 {
            color: #721c24;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .danger-box p {
            color: #721c24;
            line-height: 1.6;
        }
        
        .info-box {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        
        .info-box h3 {
            color: #0c5460;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .info-box p {
            color: #0c5460;
            margin-bottom: 8px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #dc3545;
        }
        
        .form-note {
            font-size: 14px;
            color: #666;
            margin-top: 6px;
        }
        
        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
            flex: 1;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            flex: 1;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 15px;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .checkboxgroup {
            margin: 25px 0;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            cursor: pointer;
            accent-color: #dc3545;
        }
        
        .checkbox-item label {
            color: #333;
            font-size: 15px;
            cursor: pointer;
            margin: 0;
        }
        
        @media (max-width: 768px) {
            .content {
                padding: 30px 20px;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ Delete Account</h1>
            <p>Permanently delete your studio owner account</p>
        </div>
        
        <div class="content">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <h3>📋 Account Information</h3>
                <p><strong>Name:</strong> <?= htmlspecialchars($owner['Name']) ?></p>
                <p><strong>Email:</strong> <?= htmlspecialchars($owner['Email']) ?></p>
                <p><strong>Studios Owned:</strong> <?= $studioCount ?></p>
                <?php if ($studioCount > 0): ?>
                    <p><strong>Studio Names:</strong></p>
                    <ul style="margin-left: 20px; margin-top: 5px;">
                        <?php foreach ($studios as $studio): ?>
                            <li><?= htmlspecialchars($studio['StudioName']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <div class="danger-box">
                <h3>🚨 This Action Cannot Be Undone!</h3>
                <p>Deleting your account will <strong>permanently remove</strong> all your data from our system. This action is irreversible.</p>
            </div>
            
            <div class="warning-box">
                <h3>⚠️ What Will Be Deleted:</h3>
                <ul>
                    <li><strong>Your studio owner account</strong> and login credentials</li>
                    <li><strong>All studios</strong> you own (<?= $studioCount ?> studio<?= $studioCount !== 1 ? 's' : '' ?>)</li>
                    <li><strong>Studio services, instructors, and schedules</strong></li>
                    <li><strong>Gallery photos and verification documents</strong></li>
                    <li><strong>Registration records and payment information</strong></li>
                    <li><strong>All active bookings</strong> will be cancelled</li>
                </ul>
            </div>
            
            <form method="POST" onsubmit="return confirmDeletion(event)">
                <div class="checkboxgroup">
                    <div class="checkbox-item">
                        <input type="checkbox" id="understand1" required>
                        <label for="understand1">I understand that all my studios will be deleted</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="understand2" required>
                        <label for="understand2">I understand that all my data will be permanently removed</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="understand3" required>
                        <label for="understand3">I understand that this action cannot be undone</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Enter Your Password to Confirm *</label>
                    <input type="password" id="password" name="password" required placeholder="Enter your current password">
                </div>
                
                <div class="form-group">
                    <label for="confirm_text">Type "DELETE" to confirm *</label>
                    <input type="text" id="confirm_text" name="confirm_text" required placeholder="Type DELETE in capital letters">
                    <div class="form-note">You must type the word DELETE exactly as shown</div>
                </div>
                
                <div class="button-group">
                    <button type="submit" name="confirm_delete" class="btn btn-danger">
                        🗑️ Delete My Account Forever
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        ← Cancel, Keep My Account
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function confirmDeletion(event) {
            const confirmText = document.getElementById('confirm_text').value.trim();
            
            if (confirmText !== 'DELETE') {
                alert('Please type DELETE exactly as shown to confirm account deletion.');
                event.preventDefault();
                return false;
            }
            
            const finalConfirm = confirm(
                '⚠️ FINAL CONFIRMATION ⚠️\n\n' +
                'Are you absolutely sure you want to delete your account?\n\n' +
                'This will permanently delete:\n' +
                '• Your account and login\n' +
                '• All <?= $studioCount ?> studio(s)\n' +
                '• All your data and files\n\n' +
                'This action CANNOT be undone!\n\n' +
                'Click OK to proceed with deletion, or Cancel to keep your account.'
            );
            
            if (!finalConfirm) {
                event.preventDefault();
                return false;
            }
            
            return true;
        }
    </script>
</body>
</html>

