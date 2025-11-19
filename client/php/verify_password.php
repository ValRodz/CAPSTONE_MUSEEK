<?php
session_start();
include '../../shared/config/db.php';

// Check if user is authenticated and is a client
$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'client';

if (!$is_authenticated) {
    echo json_encode(['valid' => false, 'message' => 'Not authenticated']);
    exit();
}

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the current password from POST data
    $current_password = $_POST['current_password'] ?? '';
    
    if (empty($current_password)) {
        echo json_encode(['valid' => false, 'message' => 'Password is required']);
        exit();
    }
    
    // Get the stored password hash from database
    $query = "SELECT Password FROM clients WHERE ClientID = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $stored_password = $row['Password'];
        
        // Debug output
        error_log("User ID: " . $_SESSION['user_id']);
        error_log("Stored password: " . $stored_password);
        error_log("Current password: " . $current_password);
        
        // First try direct comparison (for plain text passwords like '0000')
        $is_valid = ($current_password === $stored_password);
        
        // If not valid and it looks like a hash, try password_verify
        if (!$is_valid && strlen($stored_password) > 20) {
            $is_valid = password_verify($current_password, $stored_password);
        }
        
        echo json_encode([
            'valid' => $is_valid,
            'message' => $is_valid ? 'Password is correct' : 'Current password is incorrect'
        ]);
    } else {
        echo json_encode(['valid' => false, 'message' => 'User not found']);
    }
    
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(['valid' => false, 'message' => 'Invalid request method']);
}
?>