<?php
session_start();
require_once '../../shared/config/db pdo.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: ../../auth/php/login.php");
    exit();
}

$ownerId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['bookingId']) && isset($data['clientId'])) {
    $bookingId = $data['bookingId'];
    $clientId = $data['clientId'];
    $message = $data['message'];

    try {
        // Update booking status to Confirmed (assuming Book_StatsID = 3)
        $updateStmt = $pdo->prepare("UPDATE bookings SET Book_StatsID = 1 WHERE BookingID = ? AND StudioID IN (SELECT StudioID FROM studios WHERE OwnerID = ?)");
        $updateStmt->execute([$bookingId, $ownerId]);

        // Insert notification for the client
        $notifyStmt = $pdo->prepare("INSERT INTO notifications (OwnerID, ClientID, Type, Message, Created_At) VALUES (?, ?, 'booking_confirmation', ?, NOW())");
        $notifyStmt->execute([$ownerId, $clientId, $message]);

        echo json_encode(['success' => true, 'message' => 'Booking confirmed']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>
