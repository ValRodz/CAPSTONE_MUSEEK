<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'error' => 'unauthenticated', 'data' => []]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$userType = $_SESSION['user_type'];
$limit = isset($_GET['limit']) ? max(1, min(20, (int) $_GET['limit'])) : 10;

// Build query per user type
if ($userType === 'owner') {
    $sql = "SELECT NotificationID, Type, Message, RelatedID, IsRead, Created_At FROM notifications WHERE OwnerID = ? AND For_User = 'Owner' ORDER BY Created_At DESC LIMIT ?";
} else {
    // Assume client - only show client-specific notifications
    $sql = "SELECT NotificationID, Type, Message, RelatedID, IsRead, Created_At FROM notifications WHERE ClientID = ? AND For_User = 'Client' ORDER BY Created_At DESC LIMIT ?";
}

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'ii', $userId, $limit);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$list = [];
while ($row = mysqli_fetch_assoc($res)) {
    // Basic normalization
    $list[] = [
        'id' => (int) $row['NotificationID'],
        'type' => $row['Type'],
        'message' => $row['Message'],
        'relatedId' => isset($row['RelatedID']) ? (int)$row['RelatedID'] : null,
        'isRead' => (int) $row['IsRead'] === 1,
        'createdAt' => $row['Created_At'],
    ];
}
mysqli_stmt_close($stmt);

echo json_encode(['success' => true, 'data' => $list]);