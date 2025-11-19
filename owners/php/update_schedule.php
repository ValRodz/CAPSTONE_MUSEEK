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

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Get form data
$scheduleId = $_POST['schedule_id'] ?? '';
$slotDate = $_POST['slot_date'] ?? '';
$timeStart = $_POST['time_start'] ?? '';
$timeEnd = $_POST['time_end'] ?? '';
$studioId = $_POST['studio_id'] ?? '';
$availability = $_POST['availability'] ?? '';

// Validate form data
if (empty($scheduleId) || empty($slotDate) || empty($timeStart) || empty($timeEnd) || empty($studioId) || empty($availability)) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

// Validate time range
if (strtotime($timeStart) >= strtotime($timeEnd)) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'End time must be after start time']);
    exit();
}

// Check if the schedule belongs to the owner
$scheduleStmt = $pdo->prepare("
    SELECT s.ScheduleID, s.Avail_StatsID 
    FROM schedules s
    JOIN studios st ON s.StudioID = st.StudioID
    WHERE s.ScheduleID = ? AND st.OwnerID = ?
");
$scheduleStmt->execute([$scheduleId, $ownerId]);
$schedule = $scheduleStmt->fetch();

if (!$schedule) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid schedule selected']);
    exit();
}

// Booked schedules can be edited per updated policy.

// Check if the studio belongs to the owner
$studioStmt = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ?");
$studioStmt->execute([$studioId, $ownerId]);
$studio = $studioStmt->fetch();

if (!$studio) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid studio selected']);
    exit();
}

// Check for overlapping schedules (excluding the current schedule)
$overlapStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM schedules 
    WHERE StudioID = ? 
    AND Sched_Date = ? 
    AND ScheduleID != ?
    AND (
        (Time_Start <= ? AND Time_End > ?) OR
        (Time_Start < ? AND Time_End >= ?) OR
        (Time_Start >= ? AND Time_End <= ?)
    )
");
$overlapStmt->execute([
    $studioId, 
    $slotDate, 
    $scheduleId,
    $timeStart, $timeStart, 
    $timeEnd, $timeEnd, 
    $timeStart, $timeEnd
]);
$overlapCount = $overlapStmt->fetchColumn();

if ($overlapCount > 0) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'This time slot overlaps with an existing schedule']);
    exit();
}

// Update the schedule
$updateStmt = $pdo->prepare("
    UPDATE schedules 
    SET StudioID = ?, Sched_Date = ?, Time_Start = ?, Time_End = ?, Avail_StatsID = ?
    WHERE ScheduleID = ?
");

try {
    $updateStmt->execute([$studioId, $slotDate, $timeStart, $timeEnd, $availability, $scheduleId]);

    // Notify clients with bookings for this schedule about the update
    $bookingStmt = $pdo->prepare("
        SELECT b.BookingID, b.ClientID, sch.Sched_Date, sch.Time_Start, sch.Time_End, st.StudioName
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        JOIN studios st ON sch.StudioID = st.StudioID
        WHERE b.ScheduleID = ?
    ");
    $bookingStmt->execute([$scheduleId]);
    $bookings = $bookingStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($bookings) {
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (OwnerID, ClientID, RelatedID, Type, Message, For_User, Created_At, IsRead)
            VALUES (?, ?, ?, ?, ?, 'Client', NOW(), 0)
        ");
        foreach ($bookings as $bk) {
            $msg = sprintf(
                "Schedule updated: %s on %s, %s–%s",
                $bk['StudioName'],
                date('M d, Y', strtotime($bk['Sched_Date'])),
                substr($bk['Time_Start'], 0, 5),
                substr($bk['Time_End'], 0, 5)
            );
            $notifStmt->execute([$ownerId, $bk['ClientID'], $scheduleId, 'schedule_update', $msg]);
        }
    }
    
    // Redirect back to schedule page
    header("Location: schedule.php?view=monthly&month=" . date('m', strtotime($slotDate)) . "&year=" . date('Y', strtotime($slotDate)) . "&date=" . urlencode($slotDate) . "&toast=" . urlencode("Schedule updated for " . date('M d, Y', strtotime($slotDate))) . "&status=success");
    exit();
} catch (PDOException $e) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}

