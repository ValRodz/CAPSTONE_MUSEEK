<?php
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Return JSON error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

// Include database connection
include '../../shared/config/db pdo.php';

// Get owner ID from session
$ownerId = $_SESSION['user_id'];

// Check if booking ID and status are provided
if (!isset($_GET['id']) || !isset($_GET['status'])) {
    // Return JSON error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing booking ID or status']);
    exit();
}

$bookingId = $_GET['id'];
$status = $_GET['status'];

// Validate status
$validStatuses = ['pending', 'confirmed', 'cancelled', 'completed'];
if (!in_array(strtolower($status), $validStatuses)) {
    // Return JSON error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid booking status']);
    exit();
}

try {
    // First, verify that the booking belongs to this owner's studio
    $checkBooking = $pdo->prepare("
        SELECT b.BookingID 
        FROM bookings b
        JOIN studios s ON b.StudioID = s.StudioID
        WHERE b.BookingID = ? AND s.OwnerID = ?
    ");
    $checkBooking->execute([$bookingId, $ownerId]);
    
    if ($checkBooking->rowCount() === 0) {
        // Return JSON error response
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'You don\'t have permission to update this booking']);
        exit();
    }
    
    // Update booking status using Book_StatsID
    $updateStatus = $pdo->prepare("
        UPDATE bookings 
        SET Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = ?) 
        WHERE BookingID = ?
    ");
    $updateStatus->execute([ucfirst($status), $bookingId]);
    
    // Create notification for the client
    $createNotification = $pdo->prepare("
        INSERT INTO notifications (ClientID, Type, Message, RelatedID, For_User) 
        SELECT b.ClientID, 'booking', CONCAT('Your booking status has been updated to ', ?), b.BookingID, 'Client'
        FROM bookings b
        WHERE b.BookingID = ?
    ");
    $createNotification->execute([ucfirst($status), $bookingId]);
    
    // Return JSON success response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Booking status updated successfully to ' . ucfirst($status) . '.'
    ]);
    
} catch (PDOException $e) {
    // Return JSON error response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Error updating booking status: ' . $e->getMessage()
    ]);
}
?>