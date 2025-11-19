<?php
session_start();
include '../../shared/config/db.php';

// Check if user is authenticated and is a client
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'client') {
    header('Location: ../../auth/php/login.php');
    exit();
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_SESSION['user_id'];
    $password = $_POST['password'] ?? '';

    // Validate password is provided
    if (empty($password)) {
        $_SESSION['delete_message'] = 'Password is required to delete your account.';
        $_SESSION['delete_status'] = 'error';
        header('Location: client_profile.php');
        exit();
    }

    // Fetch client password
    $query = "SELECT Password FROM clients WHERE ClientID = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $client_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $client = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$client) {
        $_SESSION['delete_message'] = 'Account not found.';
        $_SESSION['delete_status'] = 'error';
        header('Location: client_profile.php');
        exit();
    }

    // Verify password (plaintext comparison - adjust if you use hashed passwords)
    if ($password !== $client['Password']) {
        $_SESSION['delete_message'] = 'Incorrect password. Account deletion cancelled.';
        $_SESSION['delete_status'] = 'error';
        header('Location: client_profile.php');
        exit();
    }

    // Set V_StatsID to 3 (Inactive) instead of deleting the account
    $update_query = "UPDATE clients SET V_StatsID = 3 WHERE ClientID = ?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, "i", $client_id);

    if (mysqli_stmt_execute($update_stmt)) {
        mysqli_stmt_close($update_stmt);
        mysqli_close($conn);

        // Destroy session
        session_destroy();

        // Redirect to login with success message
        session_start();
        $_SESSION['delete_success'] = 'Your account has been deactivated successfully. Contact support if you wish to reactivate it.';
        header('Location: ../../auth/php/login.php');
        exit();
    } else {
        mysqli_stmt_close($update_stmt);
        mysqli_close($conn);

        $_SESSION['delete_message'] = 'Error deactivating account. Please try again.';
        $_SESSION['delete_status'] = 'error';
        header('Location: client_profile.php');
        exit();
    }
} else {
    // If accessed directly without POST
    header('Location: client_profile.php');
    exit();
}
?>

