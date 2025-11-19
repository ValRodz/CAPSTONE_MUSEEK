<?php
// Include the database connection (PDO)
require_once __DIR__ . '/../../shared/config/db pdo.php';

// Set headers for JSON response
header('Content-Type: application/json');

// Get owner ID
$ownerId = isset($_GET['owner_id']) ? intval($_GET['owner_id']) : 0;

// Validate inputs
if ($ownerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid owner ID']);
    exit;
}

try {
    session_start();
    
    // Get all unique conversations for this owner
    $conv_stmt = $pdo->prepare("
        SELECT DISTINCT ClientID, StudioID 
        FROM chatlog 
        WHERE OwnerID = ? 
        AND Sender_Type = 'Client'
    ");
    $conv_stmt->execute([$ownerId]);
    $conversations = $conv_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalUnread = 0;
    
    // Calculate unread count for each conversation
    foreach ($conversations as $conv) {
        $clientId = $conv['ClientID'];
        $studioId = $conv['StudioID'] ?? 0;
        $conversation_key = 'last_viewed_owner_' . $clientId . '_' . $studioId;
        $last_viewed = isset($_SESSION[$conversation_key]) ? $_SESSION[$conversation_key] : '1970-01-01 00:00:00';
        
        // Count messages from Client sent after last viewed time
        $unread_stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM chatlog 
            WHERE OwnerID = ? 
            AND ClientID = ? 
            AND (StudioID = ? OR (StudioID IS NULL AND ? = 0))
            AND Sender_Type = 'Client' 
            AND Timestamp > ?
        ");
        $unread_stmt->execute([$ownerId, $clientId, $studioId, $studioId, $last_viewed]);
        $unread_result = $unread_stmt->fetch(PDO::FETCH_ASSOC);
        $totalUnread += $unread_result ? (int)$unread_result['unread_count'] : 0;
    }

    echo json_encode([
        'success' => true,
        'count' => $totalUnread
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>