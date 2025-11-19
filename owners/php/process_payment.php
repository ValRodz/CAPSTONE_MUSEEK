<?php
// Include the database connection and PushManager
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/push_manager.php';

// Process payment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $bookingId = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $paymentMethod = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
    
    // Validate inputs
    if ($bookingId <= 0 || $amount <= 0 || empty($paymentMethod)) {
        header('Location: payments.php?error=invalid_input');
        exit;
    }
    
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Insert payment
        $stmt = $pdo->prepare("
            INSERT INTO payment (BookingID, Amount, Pay_Method, Pay_Stats, Pay_Date) 
            VALUES (?, ?, ?, 'Completed', NOW())
        ");
        $stmt->execute([$bookingId, $amount, $paymentMethod]);
        
        // Get the payment ID
        $paymentId = $pdo->lastInsertId();
        
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.ClientID, b.StudioID, c.Name as client_name, s.StudioName, s.OwnerID
            FROM bookings b
            JOIN clients c ON b.ClientID = c.ClientID
            JOIN studios s ON b.StudioID = s.StudioID
            WHERE b.BookingID = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Create notification message
        $message = "{$booking['client_name']} paid $" . number_format($amount, 2) . 
                   " for {$booking['StudioName']}";
        
        // Create notification in database
        $stmt = $pdo->prepare("
            INSERT INTO notifications (OwnerID, Type, Message, RelatedID, IsRead, Created_At) 
            VALUES (?, 'payment', ?, ?, 0, NOW())
        ");
        $stmt->execute([$booking['OwnerID'], $message, $paymentId]);
        
        // Update booking status
        $stmt = $pdo->prepare("UPDATE bookings SET Status = 'Confirmed' WHERE BookingID = ?");
        $stmt->execute([$bookingId]);
        
        // Commit transaction
        $pdo->commit();
        
        // Send push notification
        $pushManager = new PushManager($pdo);
        $pushManager->sendPaymentNotification($paymentId);
        
        // Redirect to success page
        header('Location: payments.php?success=payment_processed');
        exit;
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        header('Location: payments.php?error=database_error');
        exit;
    }
}
?>