<?php
session_start();
include '../../shared/config/db.php';
header('Content-Type: application/json');

// Require basic auth via session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$owner_id = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : 0;
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$limit = isset($_GET['limit']) ? min(100, (int)$_GET['limit']) : 50;

if (!$owner_id || !$client_id) {
    echo json_encode(['success' => false, 'error' => 'owner_id and client_id are required']);
    exit;
}

$params = [$owner_id, $client_id, $limit];
$sql = "SELECT ChatID, OwnerID, ClientID, Content, Sender_Type, Timestamp FROM chatlog WHERE OwnerID = ? AND ClientID = ? ORDER BY Timestamp DESC LIMIT ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param('iii', ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) { $rows[] = $r; }

echo json_encode(['success' => true, 'rows' => $rows]);

?>
