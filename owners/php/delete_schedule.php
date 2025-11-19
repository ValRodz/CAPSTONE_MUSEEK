<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include '../../shared/config/db pdo.php';

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Get schedule ID from either POST or GET request
$scheduleId = $_POST['schedule_id'] ?? $_GET['id'] ?? '';

// Determine if this is an AJAX request
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST') || 
          (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// Validate form data
if (empty($scheduleId)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Schedule ID is required']);
    } else {
        header("Location: schedule.php?error=" . urlencode('Schedule ID is required'));
    }
    exit();
}

// Check if the schedule belongs to the owner and get the date for redirection
$scheduleStmt = $pdo->prepare("
    SELECT s.ScheduleID, s.Sched_Date, s.Avail_StatsID 
    FROM schedules s
    JOIN studios st ON s.StudioID = st.StudioID
    WHERE s.ScheduleID = ? AND st.OwnerID = ?
");
$scheduleStmt->execute([$scheduleId, $ownerId]);
$schedule = $scheduleStmt->fetch();

if (!$schedule) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid schedule selected']);
    } else {
        header("Location: schedule.php?error=" . urlencode('Invalid schedule selected'));
    }
    exit();
}

// Check if the schedule is booked (cannot delete booked schedules)
if ($schedule['Avail_StatsID'] == 2) { // 2 = Booked
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Cannot delete a booked schedule']);
    } else {
        $scheduleDate = $schedule['Sched_Date'];
        header("Location: schedule.php?view=monthly&month=" . date('m', strtotime($scheduleDate)) . "&year=" . date('Y', strtotime($scheduleDate)) . "&error=" . urlencode('Cannot delete a booked schedule'));
    }
    exit();
}

// Store the date for redirection
$scheduleDate = $schedule['Sched_Date'];

// Delete the schedule
$deleteStmt = $pdo->prepare("DELETE FROM schedules WHERE ScheduleID = ?");

try {
    $deleteStmt->execute([$scheduleId]);
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Schedule deleted successfully']);
    } else {
        // Redirect back to schedule page
        header("Location: schedule.php?view=monthly&month=" . date('m', strtotime($scheduleDate)) . "&year=" . date('Y', strtotime($scheduleDate)) . "&success=" . urlencode('Schedule deleted successfully'));
    }
    exit();
} catch (PDOException $e) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } else {
        header("Location: schedule.php?view=monthly&month=" . date('m', strtotime($scheduleDate)) . "&year=" . date('Y', strtotime($scheduleDate)) . "&error=" . urlencode('Database error: ' . $e->getMessage()));
    }
    exit();
}
