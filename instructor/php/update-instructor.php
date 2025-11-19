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
    $studios = isset($_POST['studios']) && is_array($_POST['studios']) ? $_POST['studios'] : [];

    // Validate inputs
    if (!$instructorId || empty($name) || empty($specialty) || empty($contact) || empty($email) || empty($studios)) {
        throw new Exception('All fields are required, including at least one studio.');
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

    // Begin transaction
    $pdo->beginTransaction();

    // Update instructor details
    $updateInstructor = $pdo->prepare("
        UPDATE instructors
        SET Name = ?, Profession = ?, Phone = ?, Email = ?
        WHERE InstructorID = ? AND OwnerID = ?
    ");
    $updateInstructor->execute([$name, $specialty, $contact, $email, $instructorId, $ownerId]);

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

    // Commit transaction
    $pdo->commit();

    // Set success message
    $_SESSION['flash_message'] = 'Instructor updated successfully.';

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
