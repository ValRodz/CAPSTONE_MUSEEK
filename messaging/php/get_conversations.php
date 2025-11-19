<?php
// get_conversations.php - Endpoint for fetching conversations via AJAX
include '../../shared/config/db.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get all users the current user has chatted with
$stmt = $conn->prepare("SELECT u.id, u.name, u.user_type,
                        (SELECT message FROM chat_messages
                         WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id)
                         ORDER BY created_at DESC LIMIT 1) as last_message,
                        (SELECT created_at FROM chat_messages
                         WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id)
                         ORDER BY created_at DESC LIMIT 1) as last_message_time,
                        (SELECT COUNT(*) FROM chat_messages
                         WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0) as unread_count
                        FROM users u
                        WHERE u.id IN (
                            SELECT DISTINCT
                            CASE
                                WHEN sender_id = ? THEN receiver_id
                                ELSE sender_id
                            END as contact_id
                            FROM chat_messages
                            WHERE sender_id = ? OR receiver_id = ?
                        )
                        ORDER BY last_message_time DESC");
$stmt->bind_param("iiiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$conversations_result = $stmt->get_result();
$conversations = [];
while ($row = $conversations_result->fetch_assoc()) {
    // Sanitize data for JSON output
    $conversations[] = [
        'id' => $row['id'],
        'name' => htmlspecialchars($row['name']),
        'user_type' => $row['user_type'],
        'last_message' => htmlspecialchars($row['last_message']),
        'last_message_time' => $row['last_message_time'],
        'unread_count' => (int)$row['unread_count']
    ];
}

// Get total unread messages
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM chat_messages WHERE receiver_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_total = (int)$stmt->get_result()->fetch_assoc()['count'];

// Return JSON response
echo json_encode([
    'success' => true,
    'conversations' => $conversations,
    'unread_total' => $unread_total
]);