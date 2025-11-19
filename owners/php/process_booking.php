<?php
// Include the database connection and PushManager
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/push_manager.php';

// Process booking form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $clientId = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    $studioId = isset($_POST['studio_id']) ? intval($_POST['studio_id']) : 0;
    $bookingDate = isset($_POST['booking_date']) ? $_POST['booking_date'] : '';
    $bookingTime = isset($_POST['booking_time']) ? $_POST['booking_time'] : '';
    $duration = isset($_POST['duration']) ? intval($_POST['duration']) : 1;
    
    // Validate inputs
    if ($clientId <= 0 || $studioId <= 0 || empty($bookingDate) || empty($bookingTime)) {
        header('Location: bookings.php?error=invalid_input');
        exit;
    }
    
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Insert booking
        $stmt = $pdo->prepare("
            INSERT INTO bookings (ClientID, StudioID, booking_date, booking_time, Duration, Status, Created_At) 
            VALUES (?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        $stmt->execute([$clientId, $studioId, $bookingDate, $bookingTime, $duration]);
        
        // Get the booking ID
        $bookingId = $pdo->lastInsertId();
        
        // Get studio owner ID
        $stmt = $pdo->prepare("SELECT OwnerID FROM studios WHERE StudioID = ?");
        $stmt->execute([$studioId]);
        $ownerId = $stmt->fetch(PDO::FETCH_ASSOC)['OwnerID'];
        
        // Get client name
        $stmt = $pdo->prepare("SELECT Name FROM clients WHERE ClientID = ?");
        $stmt->execute([$clientId]);
        $clientName = $stmt->fetch(PDO::FETCH_ASSOC)['Name'];
        
        // Get studio name
        $stmt = $pdo->prepare("SELECT StudioName FROM studios WHERE StudioID = ?");
        $stmt->execute([$studioId]);
        $studioName = $stmt->fetch(PDO::FETCH_ASSOC)['StudioName'];
        
        // Create notification message
        $message = "$clientName booked $studioName on " . date('F j, Y', strtotime($bookingDate)) . 
                   " at " . date('g:i A', strtotime($bookingTime));
        
        // Create notification in database
        $stmt = $pdo->prepare("
            INSERT INTO notifications (OwnerID, Type, Message, RelatedID, IsRead, Created_At) 
            VALUES (?, 'booking', ?, ?, 0, NOW())
        ");
        $stmt->execute([$ownerId, $message, $bookingId]);
        
        // Commit transaction
        $pdo->commit();
        
        // Send push notification
        $pushManager = new PushManager($pdo);
        $pushManager->sendBookingNotification($bookingId);
        
        // Redirect to success page
        header('Location: bookings.php?success=booking_created');
        exit;
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        header('Location: bookings.php?error=database_error');
        exit;
    }
}
?>