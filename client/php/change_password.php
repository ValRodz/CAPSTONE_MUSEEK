<?php
session_start();
include '../../shared/config/db.php';

// Check if user is logged in and is a client
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'client') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $currentPassword = $_POST['currentPassword'] ?? '';
    $newPassword = $_POST['newPassword'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    
    // Validate inputs
    if (empty($currentPassword)) {
        $response['message'] = 'Current password is required';
    } elseif (empty($newPassword)) {
        $response['message'] = 'New password is required';
    } elseif (strlen($newPassword) < 8) {
        $response['message'] = 'New password must be at least 8 characters';
    } elseif ($newPassword !== $confirmPassword) {
        $response['message'] = 'New passwords do not match';
    } else {
        try {
            // Get the current password hash from database
            $stmt = $conn->prepare("SELECT Password FROM clients WHERE ClientID = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verify current password - handle both hashed and plain text passwords
                $storedPassword = $user['Password'];
                $passwordMatches = false;
                
                // First try direct comparison for plain text passwords
                if ($currentPassword === $storedPassword) {
                    $passwordMatches = true;
                } 
                // Then try password_verify for hashed passwords
                else if (password_verify($currentPassword, $storedPassword)) {
                    $passwordMatches = true;
                }
                
                if ($passwordMatches) {
                    // Store the new password as plain text
                    $updateStmt = $conn->prepare("UPDATE clients SET Password = ? WHERE ClientID = ?");
                    $updateStmt->bind_param("si", $newPassword, $userId);
                    
                    if ($updateStmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Password updated successfully';
                        
                        // Set session variable to show success message on profile page
                        $_SESSION['password_updated'] = true;
                    } else {
                        $response['message'] = 'Failed to update password. Please try again.';
                    }
                    
                    $updateStmt->close();
                } else {
                    $response['message'] = 'Current password is incorrect';
                }
            } else {
                $response['message'] = 'User not found';
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $response['message'] = 'An error occurred. Please try again.';
        }
    }
    
    // Redirect back to profile page with appropriate message
    if ($response['success']) {
        $_SESSION['password_message'] = 'Password updated successfully';
        $_SESSION['password_status'] = 'success';
    } else {
        $_SESSION['password_message'] = $response['message'];
        $_SESSION['password_status'] = 'error';
    }
    header('Location: client_profile.php');
    exit;
}

// If not a POST request, redirect to profile page
header('Location: client_profile.php');
exit;
?>