<?php
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Check if this is an AJAX request or GET request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    } else {
        header('Location: ../../auth/php/login.php');
    }
    exit;
}

// Include the database connection
require_once __DIR__ . '/../../shared/config/db pdo.php';

$ownerId = $_SESSION['user_id'];

// Determine if this is a GET request (from notification link) or POST request (from AJAX)
$isGetRequest = $_SERVER['REQUEST_METHOD'] === 'GET';

// Get notification ID from either POST or GET
$notificationId = 0;
if ($isGetRequest) {
    $notificationId = isset($_GET['notification_id']) ? intval($_GET['notification_id']) : 0;
} else {
    $notificationId = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;
}

// Validate inputs
if ($notificationId <= 0) {
    if ($isGetRequest) {
        // Redirect to dashboard if invalid ID
        header('Location: dashboard.php');
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        exit;
    }
}

try {
    // Mark notification as read (only for Owner and verify ownership)
    $stmt = $pdo->prepare("UPDATE notifications SET IsRead = 1 WHERE NotificationID = ? AND OwnerID = ? AND For_User = 'Owner'");
    $stmt->execute([$notificationId, $ownerId]);
    
    if ($isGetRequest) {
        // Redirect to the target page if specified
        $redirectUrl = isset($_GET['redirect']) ? $_GET['redirect'] : 'dashboard.php';
        
        // Basic validation to prevent open redirects
        // Only allow relative URLs or same-domain URLs
        if (strpos($redirectUrl, 'http://') === 0 || strpos($redirectUrl, 'https://') === 0) {
            // If it's a full URL, redirect to dashboard instead (security measure)
            $redirectUrl = 'dashboard.php';
        }
        
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        // Return JSON for AJAX requests
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    }
} catch (PDOException $e) {
    if ($isGetRequest) {
        // Redirect to dashboard on error
        header('Location: dashboard.php?error=notification_update_failed');
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}
?>

