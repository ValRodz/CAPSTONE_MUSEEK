<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once 'db pdo.php';

// Get the logged-in owner's ID
$ownerId = $_SESSION['user_id'];

// Initialize flash message
$_SESSION['flash_message'] = '';

try {
    // Check if the request is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    // Get and validate instructor ID
    $instructorId = filter_input(INPUT_POST, 'instructor_id', FILTER_VALIDATE_INT);
    if (!$instructorId) {
        throw new Exception('Invalid instructor ID.');
    }

    // Verify the instructor belongs to the owner
    $instructorCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM instructors 
        WHERE InstructorID = ? AND OwnerID = ?
    ");
    $instructorCheck->execute([$instructorId, $ownerId]);
    if ($instructorCheck->fetchColumn() == 0) {
        throw new Exception('Unauthorized or invalid instructor.');
    }

    // Check for active bookings (updated for new schema with booking_services)
    $bookingCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings b
        JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
        WHERE bsrv.InstructorID = ?
        AND b.booking_date >= CURDATE()
    ");
    $bookingCheck->execute([$instructorId]);
    if ($bookingCheck->fetchColumn() > 0) {
        throw new Exception('Cannot remove instructor with active or future bookings.');
    }

    // Begin transaction
    $pdo->beginTransaction();

    // Get service ID for the instructor
    $serviceCheck = $pdo->prepare("
        SELECT ServiceID 
        FROM services 
        WHERE InstructorID = ?
    ");
    $serviceCheck->execute([$instructorId]);
    $serviceId = $serviceCheck->fetchColumn();

    if ($serviceId) {
        // Delete studio assignments
        $deleteStudioServices = $pdo->prepare("
            DELETE FROM studio_services
            WHERE ServiceID = ?
        ");
        $deleteStudioServices->execute([$serviceId]);

        // Delete service
        $deleteService = $pdo->prepare("
            DELETE FROM services
            WHERE ServiceID = ?
        ");
        $deleteService->execute([$serviceId]);
    }

    // Delete instructor
    $deleteInstructor = $pdo->prepare("
        DELETE FROM instructors
        WHERE InstructorID = ? AND OwnerID = ?
    ");
    $deleteInstructor->execute([$instructorId, $ownerId]);

    // Commit transaction
    $pdo->commit();

    // Set success message
    $_SESSION['flash_message'] = 'Instructor removed successfully.';

} catch (Exception $e) {
    // Roll back transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Set error message
    $_SESSION['flash_message'] = 'Error: ' . $e->getMessage();
}

// Redirect back to instructors.php
header("Location: instructors.php");
exit();
?>