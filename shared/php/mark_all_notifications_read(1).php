<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'error' => 'unauthenticated']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$userType = $_SESSION['user_type'];

if ($userType === 'owner') {
    $sql = "UPDATE notifications SET IsRead = 1 WHERE OwnerID = ?";
} else {
    $sql = "UPDATE notifications SET IsRead = 1 WHERE ClientID = ?";
}

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $userId);
$ok = mysqli_stmt_execute($stmt);
$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

echo json_encode(['success' => (bool)$ok, 'affected' => (int)$affected]);