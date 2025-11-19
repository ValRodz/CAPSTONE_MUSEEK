<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once '../../shared/config/db pdo.php';

// Get the logged-in owner's ID
$ownerId = $_SESSION['user_id'];

// Get instructor ID from query parameter
$instructorId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$instructorId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing instructor ID']);
    exit();
}

try {
    // Verify instructor exists and belongs to the owner
    $instructorCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM instructors 
        WHERE InstructorID = ? AND OwnerID = ?
    ");
    $instructorCheck->execute([$instructorId, $ownerId]);
    if ($instructorCheck->fetchColumn() == 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Instructor not found or unauthorized']);
        exit();
    }

    // Fetch assigned services
    $query = $pdo->prepare("
        SELECT ins.ServiceID
        FROM instructor_services ins
        JOIN instructors i ON ins.InstructorID = i.InstructorID
        WHERE i.InstructorID = ? AND i.OwnerID = ?
        ORDER BY ins.ServiceID
    ");
    $query->execute([$instructorId, $ownerId]);
    $services = $query->fetchAll(PDO::FETCH_COLUMN);

    // Return services (empty array if none assigned)
    echo json_encode(['services' => array_map('intval', $services)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit();
}
?>
