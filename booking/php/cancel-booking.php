<?php
session_start();
require_once '../../shared/config/db pdo.php';

// Set timezone to PST
date_default_timezone_set('America/Los_Angeles');

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("HTTP/1.1 403 Forbidden");
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

$ownerId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['bookingId']) || !isset($data['clientId']) || !isset($data['message'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

$bookingId = (int)$data['bookingId'];
$clientId = (int)$data['clientId'];
$message = trim($data['message']);

try {
    // Verify booking exists, belongs to owner, is cancellable, and is not past-dated
    $stmt = $pdo->prepare("
        SELECT b.BookingID, b.ClientID, b.Book_StatsID, s.OwnerID, sch.Sched_Date
        FROM bookings b
        JOIN studios s ON b.StudioID = s.StudioID
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.BookingID = ? AND s.OwnerID = ?
    ");
    $stmt->execute([$bookingId, $ownerId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        error_log("Booking not found for BookingID: $bookingId, OwnerID: $ownerId");
        echo json_encode(['success' => false, 'error' => 'Booking not found']);
        exit();
    }

    // Check if booking is cancellable (Pending or Confirmed)
    if (!in_array($booking['Book_StatsID'], [1, 2])) {
        error_log("Non-cancellable status for BookingID: $bookingId, Status: {$booking['Book_StatsID']}");
        echo json_encode(['success' => false, 'error' => 'Booking is not cancellable']);
        exit();
    }

    // Check if ClientID matches
    if ($booking['ClientID'] !== $clientId) {
        error_log("ClientID mismatch for BookingID: $bookingId, Expected: {$booking['ClientID']}, Got: $clientId");
        echo json_encode(['success' => false, 'error' => 'Client ID mismatch']);
        exit();
    }

    // Check if the booking date is in the past
    $currentDate = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
    $schedDate = new DateTime($booking['Sched_Date'], new DateTimeZone('America/Los_Angeles'));
    $isPast = $schedDate < $currentDate->setTime(0, 0, 0);

    if ($isPast) {
        error_log("Cannot cancel past booking for BookingID: $bookingId, Sched_Date: {$booking['Sched_Date']}");
        echo json_encode(['success' => false, 'error' => 'Cannot cancel past bookings']);
        exit();
    }

    $pdo->beginTransaction();

    // Update booking status to Cancelled
    $updateStmt = $pdo->prepare("UPDATE bookings SET Book_StatsID = 3 WHERE BookingID = ?");
    $updateStmt->execute([$bookingId]);

    // Update payment status to Failed (if exists)
    $updatePaymentStmt = $pdo->prepare("UPDATE payment SET Pay_Stats = 'Failed' WHERE BookingID = ?");
    $updatePaymentStmt->execute([$bookingId]);

    // Insert notification
    $notifyStmt = $pdo->prepare("
        INSERT INTO notifications (OwnerID, ClientID, Type, Message, RelatedID, For_User, Created_At, IsRead)
        VALUES (?, ?, 'booking_cancellation', ?, ?, 'Owner', NOW(), 0)
    ");
    $notifyStmt->execute([$ownerId, $clientId, $message, $bookingId]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Booking cancelled']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Cancel Booking Error for BookingID: $bookingId: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
?>
