<?php
session_start();
include '../../shared/config/db pdo.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // Always return JSON response for consistency
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get booking_id from either GET or POST
$booking_id = 0;
if (isset($_GET['booking_id'])) {
    $booking_id = intval($_GET['booking_id']);
} elseif (isset($_POST['booking_id'])) {
    $booking_id = intval($_POST['booking_id']);
} else {
    // Always return JSON response
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // For owners, we just need to verify the booking exists and get schedule_id
    $verify_stmt = $pdo->prepare("SELECT BookingID, ScheduleID FROM bookings WHERE BookingID = ?");
    $verify_stmt->execute([$booking_id]);
    $booking = $verify_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }
    
    $schedule_id = $booking['ScheduleID'];
    
    // 1. Update the booking status to 'Cancelled'
    $stmt = $pdo->prepare("UPDATE bookings SET Book_StatsID = '3' WHERE BookingID = ?");
    $stmt->execute([$booking_id]);
    
    // 2. Update payment status to 'Cancelled'
    $payment_stmt = $pdo->prepare("UPDATE payments SET Pay_Stats = 'Failed' WHERE BookingID = ?");
    $payment_stmt->execute([$booking_id]);
    
    // 3. Update schedule Avail_Stats back to 'Available'
    $schedule_stmt = $pdo->prepare("UPDATE schedules SET Avail_StatsID = '1' WHERE ScheduleID = ?");
    $schedule_stmt->execute([$schedule_id]);
    
    // Commit transaction
    $pdo->commit();
    
    // Always return JSON response for consistency
    echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully']);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    error_log("Cancel booking error: " . $e->getMessage());
    
    // Always return JSON response for consistency with more detailed error message
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
