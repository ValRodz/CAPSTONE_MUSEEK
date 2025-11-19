<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

// Include database connection
include '../../shared/config/db pdo.php';

// Get the logged-in owner's ID
$ownerId = $_SESSION['user_id'];

// Initialize error and success messages
$_SESSION['flash_message'] = '';

// Function to get subscription limits
function getSubscriptionLimits($pdo, $owner_id) {
    $stmt = $pdo->prepare("
        SELECT sp.max_instructors, sp.plan_name
        FROM studio_owners so
        LEFT JOIN subscription_plans sp ON so.subscription_plan_id = sp.plan_id
        WHERE so.OwnerID = ?
    ");
    $stmt->execute([$owner_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || !$result['max_instructors']) {
        return ['max_instructors' => 10, 'plan_name' => 'Starter'];
    }
    
    return $result;
}

// Function to count instructors
function countInstructors($pdo, $owner_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM instructors WHERE OwnerID = ?");
    $stmt->execute([$owner_id]);
    return (int)$stmt->fetchColumn();
}

try {
    // Check if the request is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }
    
    // Check subscription limit
    $limits = getSubscriptionLimits($pdo, $ownerId);
    $current_count = countInstructors($pdo, $ownerId);
    
    if ($current_count >= $limits['max_instructors']) {
        throw new Exception("Instructor limit reached! Your {$limits['plan_name']} plan allows up to {$limits['max_instructors']} instructors. Please upgrade your plan to add more instructors.");
    }

    // Get and sanitize form data
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
    if (empty($name) || empty($specialty) || empty($contact) || empty($email) || empty($services)) {
        throw new Exception('All fields are required, including at least one service.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format.');
    }

    /*
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

    // Insert instructor into instructors table
    $insertInstructor = $pdo->prepare("
        INSERT INTO instructors (OwnerID, member_type, Name, Role, Phone, Email)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insertInstructor->execute([$ownerId, $member_type, $name, $specialty, $contact, $email]);
    $instructorId = $pdo->lastInsertId();
    // Link selected services to this instructor (many-to-many)
    $insertInstructorService = $pdo->prepare("\n        INSERT INTO instructor_services (InstructorID, ServiceID)\n        VALUES (?, ?)\n    ");
    foreach ($services as $serviceId) {
        $insertInstructorService->execute([$instructorId, $serviceId]);
    }

    /*
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
    */

    // Commit transaction
    $pdo->commit();

    // Set success message
    $member_label = ($member_type === 'staff') ? 'Staff member' : 'Instructor';
    $_SESSION['flash_message'] = $member_label . ' added successfully.';

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