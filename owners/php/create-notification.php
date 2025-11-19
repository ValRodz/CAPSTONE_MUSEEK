<?php
session_start();
require_once __DIR__ . '/../../shared/config/db pdo.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Owner') {
    header("Location: ../../auth/php/login.php");
    exit();
}

$ownerId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['clientId']) && isset($data['bookingId']) && isset($data['type']) && isset($data['message'])) {
    $clientId = $data['clientId'];
    $bookingId = $data['bookingId'];
    $type = $data['type'];
    $message = $data['message'];

    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (OwnerID, ClientID, RelatedID, Type, Message, Created_At) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$ownerId, $clientId, $bookingId, $type, $message]);
        echo json_encode(['success' => true, 'message' => 'Notification created']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>

