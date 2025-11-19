<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include '../../shared/config/db pdo.php';

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Get form data
$slotDate = $_POST['slot_date'] ?? '';
$timeStart = $_POST['time_start'] ?? '';
$timeEnd = $_POST['time_end'] ?? '';
$studioId = $_POST['studio_id'] ?? '';
$availability = $_POST['availability'] ?? '';

// Validate form data
if (empty($slotDate) || empty($timeStart) || empty($timeEnd) || empty($studioId) || empty($availability)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'All fields are required'
    ]);
    exit();
}

// Validate time range
if (strtotime($timeStart) >= strtotime($timeEnd)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'End time must be after start time'
    ]);
    exit();
}

// Check if the studio belongs to the owner
$studioStmt = $pdo->prepare("SELECT StudioID, Time_IN, Time_OUT FROM studios WHERE StudioID = ? AND OwnerID = ?");
$studioStmt->execute([$studioId, $ownerId]);
$studio = $studioStmt->fetch();

if (!$studio) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid studio selected']);
    exit();
}

// Validate against studio hours
$studioOpen = $studio['Time_IN'] ?? null;
$studioClose = $studio['Time_OUT'] ?? null;
if ($studioOpen && $studioClose) {
    $startTs = strtotime($slotDate . ' ' . $timeStart);
    $endTs = strtotime($slotDate . ' ' . $timeEnd);
    $openTs = strtotime($slotDate . ' ' . $studioOpen);
    $closeTs = strtotime($slotDate . ' ' . $studioClose);
    if ($startTs < $openTs || $endTs > $closeTs) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Time must be within studio hours (' . $studioOpen . '–' . $studioClose . ')']);
        exit();
    }
}

// Check for overlapping schedules
$overlapStmt = $pdo->prepare("\n    SELECT COUNT(*) \n    FROM schedules \n    WHERE StudioID = ? \n    AND Sched_Date = ? \n    AND (\n        (Time_Start <= ? AND Time_End > ?) OR\n        (Time_Start < ? AND Time_End >= ?) OR\n        (Time_Start >= ? AND Time_End <= ?)\n    )\n");
$overlapStmt->execute([
    $studioId, 
    $slotDate, 
    $timeStart, $timeStart, 
    $timeEnd, $timeEnd, 
    $timeStart, $timeEnd
]);
$overlapCount = $overlapStmt->fetchColumn();

if ($overlapCount > 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'This time slot overlaps with an existing schedule']);
    exit();
}

// Insert the new time slot
$insertStmt = $pdo->prepare("\n    INSERT INTO schedules (OwnerID, StudioID, Sched_Date, Time_Start, Time_End, Avail_StatsID)\n    VALUES (?, ?, ?, ?, ?, ?)\n");

try {
    $insertStmt->execute([$ownerId, $studioId, $slotDate, $timeStart, $timeEnd, $availability]);
    $scheduleId = $pdo->lastInsertId();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Time slot added successfully',
        'schedule_id' => (int)$scheduleId
    ]);
    exit();
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit();
}
