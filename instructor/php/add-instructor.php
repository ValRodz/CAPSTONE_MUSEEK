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

// Initialize error and success messages
$_SESSION['flash_message'] = '';

try {
    // Check if the request is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    // Get and sanitize form data
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING));
    $specialty = trim(filter_input(INPUT_POST, 'specialty', FILTER_SANITIZE_STRING));
    $contact = trim(filter_input(INPUT_POST, 'contact', FILTER_SANITIZE_STRING));
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $studios = isset($_POST['studios']) && is_array($_POST['studios']) ? $_POST['studios'] : [];

    // Validate inputs
    if (empty($name) || empty($specialty) || empty($contact) || empty($email) || empty($studios)) {
        throw new Exception('All fields are required, including at least one studio.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format.');
    }

    // Validate studio IDs (ensure they belong to the owner)
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

    // Insert instructor into instructors table
    $insertInstructor = $pdo->prepare("
        INSERT INTO instructors (OwnerID, Name, Profession, Phone, Email)
        VALUES (?, ?, ?, ?, ?)
    ");
    $insertInstructor->execute([$ownerId, $name, $specialty, $contact, $email]);
    $instructorId = $pdo->lastInsertId();

    // Insert a service for the instructor
    $insertService = $pdo->prepare("
        INSERT INTO services (InstructorID)
        VALUES (?)
    ");
    $insertService->execute([$instructorId]);
    $serviceId = $pdo->lastInsertId();

    // Assign the service to selected studios
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
    $_SESSION['flash_message'] = 'Instructor added successfully.';

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
