<?php
// Include the database connection
require_once 'db pdo.php';

// Set headers for JSON response
header('Content-Type: application/json');

// Get notification ID
$notificationId = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;

// Validate inputs
if ($notificationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
    exit;
}

try {
    // Mark notification as read
    $stmt = $pdo->prepare("UPDATE notifications SET IsRead = 1 WHERE NotificationID = ?");
    $stmt->execute([$notificationId]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>