<?php
session_start();
include '../../shared/config/db.php';

// Always return JSON
header('Content-Type: application/json');

// Ensure user is authenticated as a client
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'client') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Accept booking_id from POST or GET
if (isset($_POST['booking_id']) && is_numeric($_POST['booking_id'])) {
    $booking_id = (int)$_POST['booking_id'];
} elseif (isset($_GET['booking_id']) && is_numeric($_GET['booking_id'])) {
    $booking_id = (int)$_GET['booking_id'];
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid booking ID']);
    exit();
}

$client_id = (int)$_SESSION['user_id'];

try {
    // Verify booking belongs to client and is Pending; also fetch ScheduleID
    $verify_sql = "SELECT b.Book_StatsID, b.ScheduleID FROM bookings b
                   WHERE b.BookingID = ? AND b.ClientID = ?
                   AND b.Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = 'Pending')";
    $stmt = mysqli_prepare($conn, $verify_sql);
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'ii', $booking_id, $client_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result || mysqli_num_rows($result) === 0) {
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => false, 'error' => 'Booking not found or cannot be cancelled']);
        exit();
    }

    $booking = mysqli_fetch_assoc($result);
    $schedule_id = (int)$booking['ScheduleID'];
    mysqli_stmt_close($stmt);

    // Begin transaction to update booking, payments, and schedule atomically
    mysqli_autocommit($conn, false);

    // 1) Update booking status to Cancelled
    $update_booking_sql = "UPDATE bookings
                            SET Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = 'Cancelled')
                            WHERE BookingID = ?";
    $stmt = mysqli_prepare($conn, $update_booking_sql);
    if (!$stmt) { throw new Exception('Database prepare error: ' . mysqli_error($conn)); }
    mysqli_stmt_bind_param($stmt, 'i', $booking_id);
    if (!mysqli_stmt_execute($stmt)) { throw new Exception('Failed to update booking: ' . mysqli_error($conn)); }
    mysqli_stmt_close($stmt);

    // 2) Update payment status to Failed for this BookingID (if payment exists)
    $update_payment_sql = "UPDATE payment SET Pay_Stats = 'Failed' WHERE BookingID = ?";
    $stmt = mysqli_prepare($conn, $update_payment_sql);
    if (!$stmt) { throw new Exception('Database prepare error: ' . mysqli_error($conn)); }
    mysqli_stmt_bind_param($stmt, 'i', $booking_id);
    if (!mysqli_stmt_execute($stmt)) { throw new Exception('Failed to update payment: ' . mysqli_error($conn)); }
    mysqli_stmt_close($stmt);

    // 3) Update schedule availability back to Available (Avail_StatsID = 1)
    $update_schedule_sql = "UPDATE schedules SET Avail_StatsID = 1 WHERE ScheduleID = ?";
    $stmt = mysqli_prepare($conn, $update_schedule_sql);
    if (!$stmt) { throw new Exception('Database prepare error: ' . mysqli_error($conn)); }
    mysqli_stmt_bind_param($stmt, 'i', $schedule_id);
    if (!mysqli_stmt_execute($stmt)) { throw new Exception('Failed to update schedule: ' . mysqli_error($conn)); }
    mysqli_stmt_close($stmt);

    // Commit all
    mysqli_commit($conn);
    mysqli_autocommit($conn, true);

    error_log("ClientID $client_id cancelled BookingID $booking_id; ScheduleID $schedule_id set available; payment set to Failed");
    echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully']);

} catch (Exception $e) {
    // Rollback on error
    mysqli_rollback($conn);
    mysqli_autocommit($conn, true);
    error_log('Cancel booking error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

mysqli_close($conn);
