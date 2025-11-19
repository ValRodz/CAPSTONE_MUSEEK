<?php
session_start();
include '../../shared/config/db.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is authenticated and is a client
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'client') {
    echo json_encode([
        'success' => false, 
        'error' => 'Unauthorized access. Please log in as a client.',
        'error_code' => 'UNAUTHORIZED'
    ]);
    exit();
}

// Check if booking_id is provided
if (!isset($_POST['booking_id']) || !is_numeric($_POST['booking_id'])) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid booking ID provided.',
        'error_code' => 'INVALID_BOOKING_ID'
    ]);
    exit();
}

// Get cancellation reason (optional but recommended)
$cancellation_reason = isset($_POST['cancellation_reason']) ? trim($_POST['cancellation_reason']) : null;

// Validate cancellation reason if provided
if ($cancellation_reason !== null && strlen($cancellation_reason) > 500) {
    echo json_encode([
        'success' => false, 
        'error' => 'Cancellation reason is too long. Please limit to 500 characters.',
        'error_code' => 'REASON_TOO_LONG'
    ]);
    exit();
}

$booking_id = (int)$_POST['booking_id'];
$client_id = $_SESSION['user_id'];

try {
    // Verify the booking belongs to the client and is pending, also get ScheduleID
    $verify_query = "SELECT b.Book_StatsID, b.ScheduleID FROM bookings b WHERE b.BookingID = ? AND b.ClientID = ? AND b.Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = 'Pending')";
    $stmt = mysqli_prepare($conn, $verify_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "ii", $booking_id, $client_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) === 0) {
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => false, 
            'error' => 'Booking not found or cannot be cancelled. Only pending bookings can be cancelled.',
            'error_code' => 'BOOKING_NOT_FOUND'
        ]);
        exit();
    }
    
    $booking_data = mysqli_fetch_assoc($result);
    $schedule_id = $booking_data['ScheduleID'];
    mysqli_stmt_close($stmt);

    // Start transaction to ensure both updates succeed or fail together
    mysqli_autocommit($conn, false);

    // Update booking status to Cancelled and store cancellation reason
    $update_booking_query = "UPDATE bookings SET Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = 'Cancelled'), CancellationReason = ? WHERE BookingID = ?";
    $stmt = mysqli_prepare($conn, $update_booking_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "si", $cancellation_reason, $booking_id);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to update booking status: ' . mysqli_error($conn));
    }
    mysqli_stmt_close($stmt);

    // Update schedule availability to Available
    $update_schedule_query = "UPDATE schedules SET Avail_StatsID = 1 WHERE ScheduleID = ?";
    $stmt = mysqli_prepare($conn, $update_schedule_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $schedule_id);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to update schedule availability: ' . mysqli_error($conn));
    }
    mysqli_stmt_close($stmt);

    // Commit the transaction
    mysqli_commit($conn);
    mysqli_autocommit($conn, true);

    $reason_log = $cancellation_reason ? " with reason: '$cancellation_reason'" : " (no reason provided)";
    error_log("Booking $booking_id canceled by ClientID $client_id$reason_log, Schedule $schedule_id made available");
    
    echo json_encode([
        'success' => true,
        'message' => 'Booking has been successfully cancelled and time slot is now available.',
        'booking_id' => $booking_id
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    mysqli_autocommit($conn, true);
    
    error_log("Error canceling booking $booking_id: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'An error occurred while cancelling the booking. Please try again.',
        'error_code' => 'DATABASE_ERROR'
    ]);
}

mysqli_close($conn);
?>
