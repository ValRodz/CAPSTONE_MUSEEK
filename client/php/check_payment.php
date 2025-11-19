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

$booking_id = (int)$_POST['booking_id'];
$client_id = $_SESSION['user_id'];

try {
    // Verify the booking belongs to the client and is confirmed
    $verify_query = "SELECT b.BookingID, b.Book_StatsID, p.PaymentStatus 
                     FROM bookings b 
                     LEFT JOIN payments p ON b.BookingID = p.BookingID 
                     WHERE b.BookingID = ? AND b.ClientID = ? 
                     AND b.Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = 'Confirmed')";
    
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
            'error' => 'Booking not found or not confirmed. Only confirmed bookings can be finished.',
            'error_code' => 'BOOKING_NOT_FOUND'
        ]);
        exit();
    }

    $booking_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    // Check if payment is completed
    if (!$booking_data['PaymentStatus'] || $booking_data['PaymentStatus'] !== 'Completed') {
        echo json_encode([
            'success' => false, 
            'error' => 'Payment not completed. Please complete payment before finishing the booking.',
            'error_code' => 'PAYMENT_NOT_COMPLETED'
        ]);
        exit();
    }

    // Update booking status to Finished
    $update_query = "UPDATE bookings SET Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = 'Finished') WHERE BookingID = ?";
    $stmt = mysqli_prepare($conn, $update_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $booking_id);

    if (mysqli_stmt_execute($stmt)) {
        error_log("Booking $booking_id finished by ClientID $client_id");
        echo json_encode([
            'success' => true,
            'message' => 'Booking has been successfully finished.',
            'booking_id' => $booking_id,
            'redirect' => 'client_feedback.php?booking_id=' . $booking_id
        ]);
    } else {
        throw new Exception('Failed to update booking status: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_close($stmt);
    
} catch (Exception $e) {
    error_log("Error finishing booking $booking_id: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'An error occurred while finishing the booking. Please try again.',
        'error_code' => 'DATABASE_ERROR'
    ]);
}

mysqli_close($conn);
?>
