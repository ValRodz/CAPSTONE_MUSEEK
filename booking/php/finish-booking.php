<?php
session_start();
require_once '../../shared/config/db pdo.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: login.html");
    exit();
}

$ownerId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['bookingId']) && isset($data['clientId'])) {
    $bookingId = $data['bookingId'];
    $clientId = $data['clientId'];
    $message = $data['message'];

    try {
        // Update booking status to Finished (Book_StatsID = 5)
        $updateStmt = $pdo->prepare("UPDATE bookings SET Book_StatsID = 5 WHERE BookingID = ? AND StudioID IN (SELECT StudioID FROM studios WHERE OwnerID = ?)");
        $updateStmt->execute([$bookingId, $ownerId]);

        // Insert notification for the client
        $notifyStmt = $pdo->prepare("INSERT INTO notifications (OwnerID, ClientID, Type, Message, RelatedID, For_User, Created_At) VALUES (?, ?, 'booking_finished', ?, ?, 'Client', NOW())");
        $notifyStmt->execute([$ownerId, $clientId, $message, $bookingId]);

        echo json_encode(['success' => true, 'message' => 'Booking finished']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>
