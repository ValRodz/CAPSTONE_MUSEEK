<?php
// fetch_conversations.php - JSON API endpoint for fetching conversations for a studio owner
// Uses existing schema: chatlog, clients, studios
include '../../shared/config/db.php'; // mysqli $conn

header('Content-Type: application/json');

// Validate required parameter
if (!isset($_GET['owner_id']) || $_GET['owner_id'] === '') {
    echo json_encode(['success' => false, 'error' => 'Owner ID is required']);
    exit();
}

$owner_id = (int)$_GET['owner_id'];

// Detect if 'StudioID' column exists to avoid runtime SQL errors
$hasStudioId = false;
$colCheck = $conn->query("SHOW COLUMNS FROM chatlog LIKE 'StudioID'");
if ($colCheck && $colCheck->num_rows > 0) { $hasStudioId = true; }
if ($colCheck) { $colCheck->close(); }

if ($hasStudioId) {
    // Conversations grouped by Client + Studio
    $sql = "SELECT 
                c.ClientID AS ID,
                c.Name AS Name,
                'client' AS UserType,
                cl.StudioID AS studio_id,
                s.StudioName AS studio_name,
                MAX(cl.Timestamp) AS last_message_time,
                SUBSTRING_INDEX(MAX(CONCAT(cl.Timestamp, '|', cl.Content)), '|', -1) AS last_message,
                SUBSTRING_INDEX(MAX(CONCAT(cl.Timestamp, '|', cl.Sender_Type)), '|', -1) AS last_sender
            FROM chatlog cl
            JOIN clients c ON c.ClientID = cl.ClientID
            LEFT JOIN studios s ON s.StudioID = cl.StudioID
            WHERE cl.OwnerID = ?
            GROUP BY c.ClientID, cl.StudioID
            ORDER BY last_message_time DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare statement']);
        exit();
    }
    $stmt->bind_param('i', $owner_id);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    // Fallback: conversations grouped by Client only (no StudioID column)
    $sql = "SELECT 
                c.ClientID AS ID,
                c.Name AS Name,
                'client' AS UserType,
                MAX(cl.Timestamp) AS last_message_time,
                SUBSTRING_INDEX(MAX(CONCAT(cl.Timestamp, '|', cl.Content)), '|', -1) AS last_message,
                SUBSTRING_INDEX(MAX(CONCAT(cl.Timestamp, '|', cl.Sender_Type)), '|', -1) AS last_sender
            FROM chatlog cl
            JOIN clients c ON c.ClientID = cl.ClientID
            WHERE cl.OwnerID = ?
            GROUP BY c.ClientID
            ORDER BY last_message_time DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare statement']);
        exit();
    }
    $stmt->bind_param('i', $owner_id);
    $stmt->execute();
    $result = $stmt->get_result();
}

$conversations = [];
while ($row = $result->fetch_assoc()) {
    $conversations[] = [
        'ID' => (int)($row['ID'] ?? 0),
        'Name' => htmlspecialchars($row['Name'] ?? ''),
        'UserType' => $row['UserType'] ?? 'client',
        'studio_id' => $hasStudioId ? (int)($row['studio_id'] ?? 0) : 0,
        'studio_name' => $hasStudioId ? htmlspecialchars($row['studio_name'] ?? '') : '',
        'last_message' => htmlspecialchars($row['last_message'] ?? ''),
        'last_message_time' => $row['last_message_time'] ?? null,
        'last_sender' => $row['last_sender'] ?? null,
        'unread_count' => 0
    ];
}

echo json_encode(['success' => true, 'conversations' => $conversations]);
$stmt->close();
$conn->close();
?>