<?php
// Include the database connection
require_once 'db pdo.php';

// Set headers for JSON response
header('Content-Type: application/json');

// Get owner ID
$ownerId = isset($_POST['owner_id']) ? intval($_POST['owner_id']) : 0;

// Validate inputs
if ($ownerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid owner ID']);
    exit;
}

try {
    // Mark all notifications as read
    $stmt = $pdo->prepare("UPDATE notifications SET IsRead = 1 WHERE OwnerID = ?");
    $stmt->execute([$ownerId]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>