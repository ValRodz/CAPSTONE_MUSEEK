<?php
session_start();
include __DIR__ . '/../../shared/config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$owner_id = $_SESSION['user_id'];
$studio_id = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;
if (!$studio_id) {
    echo json_encode(['success' => false, 'error' => 'Missing studio_id']);
    exit;
}
// Only allow studios owned by this owner
$studio_check = mysqli_prepare($conn, "SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ?");
mysqli_stmt_bind_param($studio_check, "ii", $studio_id, $owner_id);
mysqli_stmt_execute($studio_check);
$studio_result = mysqli_stmt_get_result($studio_check);
if (mysqli_num_rows($studio_result) === 0) {
    echo json_encode(['success' => false, 'error' => 'Studio not found or not owned by you']);
    exit;
}
mysqli_stmt_close($studio_check);
// Get all unique clients who have messaged this studio (by OwnerID and StudioID)
$query = "SELECT c.ClientID, c.Name
FROM clients c
WHERE c.ClientID IN (
    SELECT DISTINCT ClientID FROM chatlog WHERE OwnerID = ?
)
ORDER BY c.Name";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $owner_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$clients = [];
while ($row = mysqli_fetch_assoc($result)) {
    $clients[] = $row;
}
mysqli_stmt_close($stmt);
echo json_encode(['success' => true, 'clients' => $clients]);

