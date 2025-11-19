<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

require_once '../../shared/config/db.php';

try {
    // Validate required fields
    if (!isset($_POST['booking_id']) || !isset($_POST['sender_number']) || 
        !isset($_POST['reference_number']) || !isset($_FILES['payment_proof'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    $booking_id = (int)$_POST['booking_id'];
    $sender_number = trim($_POST['sender_number']);
    $reference_number = trim($_POST['reference_number']);
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $is_multi = isset($_POST['is_multi']) && $_POST['is_multi'] === '1';
    $booking_datetime = isset($_POST['booking_datetime']) ? $_POST['booking_datetime'] : null;
    
    // Validate phone number (11 digits)
    if (!preg_match('/^[0-9]{11}$/', $sender_number)) {
        echo json_encode(['success' => false, 'message' => 'Invalid phone number format']);
        exit;
    }
    
    // Validate reference number (13 digits)
    if (!preg_match('/^[0-9]{13}$/', $reference_number)) {
        echo json_encode(['success' => false, 'message' => 'Reference number must be exactly 13 digits']);
        exit;
    }
    
    // Handle file upload
    $file = $_FILES['payment_proof'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG and PNG are allowed']);
        exit;
    }
    
    if ($file['size'] > $max_size) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
        exit;
    }
    
    // Create upload directory if it doesn't exist
    $upload_dir = '../../uploads/payment_proofs/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $unique_filename = 'booking_' . $booking_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
    $upload_path = $upload_dir . $unique_filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
        exit;
    }
    
    // Verify booking belongs to user
    $verify_query = "SELECT BookingID FROM bookings WHERE BookingID = ? AND ClientID = ?";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $booking_id, $_SESSION['user_id']);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($verify_result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking']);
        exit;
    }
    mysqli_stmt_close($verify_stmt);
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Get list of all bookings to update (single or multi-booking)
        $booking_ids = [$booking_id];
        
        if ($is_multi && $booking_datetime) {
            // Get all bookings created at the same time
            $multi_query = "SELECT BookingID FROM bookings WHERE ClientID = ? AND booking_date = ?";
            $multi_stmt = mysqli_prepare($conn, $multi_query);
            mysqli_stmt_bind_param($multi_stmt, "is", $_SESSION['user_id'], $booking_datetime);
            mysqli_stmt_execute($multi_stmt);
            $multi_result = mysqli_stmt_get_result($multi_stmt);
            
            $booking_ids = [];
            while ($row = mysqli_fetch_assoc($multi_result)) {
                $booking_ids[] = $row['BookingID'];
            }
            mysqli_stmt_close($multi_stmt);
        }
        
        // Insert into g_cash table
        $gcash_insert_query = "INSERT INTO g_cash 
                              (GCash_Num, Ref_Num, gcash_sender_number, payment_proof_path, payment_notes, payment_submitted_at) 
                              VALUES (?, ?, ?, ?, ?, NOW())";
        
        $gcash_stmt = mysqli_prepare($conn, $gcash_insert_query);
        
        // Merchant GCash number (the one receiving payment)
        $merchant_gcash = '0950 819 9489';
        
        mysqli_stmt_bind_param($gcash_stmt, "sssss", 
            $merchant_gcash,
            $reference_number,
            $sender_number,
            $unique_filename,
            $notes
        );
        
        if (!mysqli_stmt_execute($gcash_stmt)) {
            throw new Exception('Failed to insert GCash payment record');
        }
        
        // Get the inserted GCashID
        $gcash_id = mysqli_insert_id($conn);
        mysqli_stmt_close($gcash_stmt);
        
        // Update payment table to link to the g_cash record
        $update_query = "UPDATE payment 
                        SET GCashID = ?, 
                            Pay_Stats = 'Pending'
                        WHERE BookingID = ?";
        
        $update_stmt = mysqli_prepare($conn, $update_query);
        
        foreach ($booking_ids as $bid) {
            mysqli_stmt_bind_param($update_stmt, "ii", 
                $gcash_id,
                $bid
            );
            
            if (!mysqli_stmt_execute($update_stmt)) {
                throw new Exception('Failed to update payment information');
            }
        }
        
        mysqli_stmt_close($update_stmt);
        
        // Commit transaction
        mysqli_commit($conn);
        
        $count = count($booking_ids);
        $message = $count > 1 
            ? "Payment proof submitted successfully for {$count} bookings! Awaiting admin verification."
            : "Payment proof submitted successfully! Awaiting admin verification.";
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        // Delete uploaded file on error
        if (file_exists($upload_path)) {
            unlink($upload_path);
        }
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Booking payment submission error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your payment submission. Please try again.'
    ]);
}
?>

