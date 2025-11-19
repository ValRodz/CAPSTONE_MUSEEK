<?php
session_start();
require_once '../../shared/config/db pdo.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: login.html");
    exit();
}

$ownerId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['bookingId'])) {
    $bookingId = $data['bookingId'];

    try {
        // Update booking status to Archived (Book_StatsID = 4)
        $updateStmt = $pdo->prepare("UPDATE bookings SET Book_StatsID = 4 WHERE BookingID = ? AND StudioID IN (SELECT StudioID FROM studios WHERE OwnerID = ?)");
        $updateStmt->execute([$bookingId, $ownerId]);

        echo json_encode(['success' => true, 'message' => 'Booking archived']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>
