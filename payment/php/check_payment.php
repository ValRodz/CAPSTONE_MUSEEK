<?php
session_start();
require_once '../../shared/config/db pdo.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'client') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

if (!isset($_POST['booking_id']) || !is_numeric($_POST['booking_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid booking ID']);
    exit();
}

$booking_id = (int)$_POST['booking_id'];
$client_id = $_SESSION['user_id'];

try {
    // Verify booking belongs to client and is in "Confirmed" status
    $verify_query = "SELECT b.BookingID 
                    FROM bookings b
                    JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                    WHERE b.BookingID = ? AND b.ClientID = ? AND bs.Book_Stats = 'Confirmed'";
    $stmt = $pdo->prepare($verify_query);
    $stmt->execute([$booking_id, $client_id]);
    
    if ($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Booking not found or not confirmed']);
        exit();
    }
    
    // Check if payment is completed
    $payment_query = "SELECT PaymentID FROM payment WHERE BookingID = ? AND Pay_Stats = 'Completed'";
    $stmt = $pdo->prepare($payment_query);
    $stmt->execute([$booking_id]);
    
    if ($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Payment not completed. Please complete the payment first.']);
        exit();
    }
    
    // Update booking status to Finished
    $update_query = "UPDATE bookings SET Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = 'Finished') WHERE BookingID = ?";
    $stmt = $pdo->prepare($update_query);
    
    if ($stmt->execute([$booking_id])) {
        // Get studio owner ID for notification
        $owner_query = "SELECT s.OwnerID 
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        WHERE b.BookingID = ?";
        $stmt = $pdo->prepare($owner_query);
        $stmt->execute([$booking_id]);
        $owner_id = $stmt->fetchColumn();
        
        // Insert notification for the owner
        if ($owner_id) {
            $notify_stmt = $pdo->prepare("INSERT INTO notifications (OwnerID, ClientID, Type, Message, Created_At) VALUES (?, ?, 'booking_finished', ?, NOW())");
            $notify_stmt->execute([$owner_id, $client_id, 'Booking has been marked as finished.']);
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'redirect' => "../../client/php/client_feedback.php?booking_id=$booking_id"]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to update booking status']);
    }
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>