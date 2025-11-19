<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once '../../shared/config/db pdo.php';

// Get the logged-in owner's ID
$ownerId = $_SESSION['user_id'];

// Initialize flash message
$_SESSION['flash_message'] = '';

try {
    // Check if the request is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    // Get and sanitize form data
    $instructorId = filter_input(INPUT_POST, 'instructor_id', FILTER_VALIDATE_INT);
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING));
    $specialty = trim(filter_input(INPUT_POST, 'specialty', FILTER_SANITIZE_STRING));
    $contact = trim(filter_input(INPUT_POST, 'contact', FILTER_SANITIZE_STRING));
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $services = isset($_POST['services']) && is_array($_POST['services']) ? array_map('intval', $_POST['services']) : [];
    
    // Get member_type (instructor or staff)
    $member_type = isset($_POST['member_type']) ? $_POST['member_type'] : 'instructor';
    // Validate member_type
    if (!in_array($member_type, ['instructor', 'staff'])) {
        $member_type = 'instructor';
    }

    // Validate inputs
    if (!$instructorId || empty($name) || empty($specialty) || empty($contact) || empty($email) || empty($services)) {
        throw new Exception('All fields are required, including at least one service.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format.');
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

    /*
    // Validate studio IDs
    $studioCheck = $pdo->prepare("
        SELECT StudioID
        FROM studios
        WHERE OwnerID = ? AND StudioID IN (" . implode(',', array_fill(0, count($studios), '?')) . ")
    ");
    $studioCheck->execute(array_merge([$ownerId], $studios));
    $validStudios = $studioCheck->fetchAll(PDO::FETCH_COLUMN);

    if (count($validStudios) !== count($studios)) {
        throw new Exception('Invalid studio selection.');
    }
    */
    // Validate service IDs exist
    $svcPlaceholders = implode(',', array_fill(0, count($services), '?'));
    $serviceCheck = $pdo->prepare("\n        SELECT ServiceID\n        FROM services\n        WHERE ServiceID IN ($svcPlaceholders)\n    ");
    $serviceCheck->execute($services);
    $validServices = $serviceCheck->fetchAll(PDO::FETCH_COLUMN);

    if (count($validServices) !== count($services)) {
        throw new Exception('Invalid service selection.');
    }

    // Begin transaction
    $pdo->beginTransaction();

    // Update instructor details
    $updateInstructor = $pdo->prepare("
        UPDATE instructors
        SET member_type = ?, Name = ?, Role = ?, Phone = ?, Email = ?
        WHERE InstructorID = ? AND OwnerID = ?
    ");
    $updateInstructor->execute([$member_type, $name, $specialty, $contact, $email, $instructorId, $ownerId]);

    /*
    // Get existing service for the instructor
    $serviceCheck = $pdo->prepare("
        SELECT ServiceID 
        FROM services 
        WHERE InstructorID = ?
        LIMIT 1
    ");
    $serviceCheck->execute([$instructorId]);
    $serviceId = $serviceCheck->fetchColumn();

    if (!$serviceId) {
        // Create a new service if none exists
        $insertService = $pdo->prepare("
            INSERT INTO services (InstructorID)
            VALUES (?)
        ");
        $insertService->execute([$instructorId]);
        $serviceId = $pdo->lastInsertId();
    }

    // Delete existing studio assignments
    $deleteStudioServices = $pdo->prepare("
        DELETE FROM studio_services
        WHERE ServiceID = ?
    ");
    $deleteStudioServices->execute([$serviceId]);

    // Insert new studio assignments
    $insertStudioService = $pdo->prepare("
        INSERT INTO studio_services (ServiceID, StudioID)
        VALUES (?, ?)
    ");
    foreach ($studios as $studioId) {
        $insertStudioService->execute([$serviceId, $studioId]);
    }
    */

    // Refresh instructor_services mappings
    $deleteInstructorServices = $pdo->prepare("\n        DELETE FROM instructor_services\n        WHERE InstructorID = ?\n    ");
    $deleteInstructorServices->execute([$instructorId]);

    $insertInstructorService = $pdo->prepare("\n        INSERT INTO instructor_services (InstructorID, ServiceID)\n        VALUES (?, ?)\n    ");
    foreach ($services as $serviceId) {
        $insertInstructorService->execute([$instructorId, $serviceId]);
    }

    // Commit transaction
    $pdo->commit();

    // Set success message
    $member_label = ($member_type === 'staff') ? 'Staff member' : 'Instructor';
    $_SESSION['flash_message'] = $member_label . ' updated successfully.';

} catch (Exception $e) {
    // Roll back transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Set error message
    $_SESSION['flash_message'] = 'Error: ' . $e->getMessage();
}

// Redirect back to unified Offerings page
header("Location: manage_services.php");
exit();
?>