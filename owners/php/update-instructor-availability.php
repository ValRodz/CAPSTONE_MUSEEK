<?php
session_start();
header('Content-Type: application/json');

// Check if owner is logged in
if (!isset($_SESSION['OwnerID'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$ownerId = (int)$_SESSION['OwnerID'];

// Validate input
if (!isset($_POST['instructor_id']) || !isset($_POST['blocked_dates'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$instructorId = (int)$_POST['instructor_id'];
$blockedDates = trim($_POST['blocked_dates']);

try {
    // Database connection
    require_once '../../shared/config/db pdo.php';
    $pdo = Database::getInstance()->getConnection();

    // Verify the instructor belongs to this owner
    $checkStmt = $pdo->prepare("SELECT InstructorID FROM instructors WHERE InstructorID = ? AND OwnerID = ?");
    $checkStmt->execute([$instructorId, $ownerId]);
    
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Instructor not found or unauthorized']);
        exit;
    }

    // Validate date format if not empty
    if (!empty($blockedDates)) {
        $dates = explode(',', $blockedDates);
        foreach ($dates as $date) {
            $date = trim($date);
            if (!empty($date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                echo json_encode(['success' => false, 'message' => 'Invalid date format']);
                exit;
            }
        }
    }

    // Update blocked dates
    $updateStmt = $pdo->prepare("UPDATE instructors SET blocked_dates = ? WHERE InstructorID = ? AND OwnerID = ?");
    $updateStmt->execute([
        !empty($blockedDates) ? $blockedDates : null,
        $instructorId,
        $ownerId
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Availability updated successfully'
    ]);

} catch (PDOException $e) {
    error_log("Update instructor availability error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>

