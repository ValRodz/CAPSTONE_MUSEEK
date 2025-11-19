<?php
session_start();
include '../../shared/config/db.php';

// Initialize response array
$response = [
    'success' => false,
    'message' => ''
];

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data and sanitize
    $name = isset($_POST['name']) ? mysqli_real_escape_string($conn, trim($_POST['name'])) : '';
    $email = isset($_POST['email']) ? mysqli_real_escape_string($conn, trim($_POST['email'])) : '';
    $subject = isset($_POST['subject']) ? mysqli_real_escape_string($conn, trim($_POST['subject'])) : '';
    $message = isset($_POST['message']) ? mysqli_real_escape_string($conn, trim($_POST['message'])) : '';
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    $user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : 'Guest';
    
    // Validate required fields
    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        $response['message'] = 'All fields are required.';
        echo json_encode($response);
        exit;
    }
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Please enter a valid email address.';
        echo json_encode($response);
        exit;
    }
    
    // Current timestamp
    $timestamp = date('Y-m-d H:i:s');
    
    // Insert into database
    $query = "INSERT INTO contact_messages (name, email, subject, message, user_id, user_type, created_at, status) 
              VALUES (?, ?, ?, ?, ?, ?, ?, 'Unread')";
    
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "sssssss", $name, $email, $subject, $message, $user_id, $user_type, $timestamp);
        
        if (mysqli_stmt_execute($stmt)) {
            $response['success'] = true;
            $response['message'] = 'Your message has been sent successfully. We\'ll get back to you soon!';
            
            // Send notification email to admin (optional)
            // mail('admin@museek.com', 'New Contact Form Submission', "Name: $name\nEmail: $email\nSubject: $subject\nMessage: $message");
        } else {
            $response['message'] = 'Error saving your message. Please try again later.';
        }
        
        mysqli_stmt_close($stmt);
    } else {
        $response['message'] = 'Database error. Please try again later.';
    }
} else {
    $response['message'] = 'Invalid request method.';
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>