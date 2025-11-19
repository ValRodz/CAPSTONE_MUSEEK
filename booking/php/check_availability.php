<?php
session_start();
include '../../shared/config/db.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please log in to check availability.']);
    exit;
}

// Validate POST data
if (!isset($_POST['studio_id']) || !isset($_POST['date']) || !isset($_POST['timeStart']) || !isset($_POST['timeEnd'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

$studio_id = (int)$_POST['studio_id'];
$date = $_POST['date'];
$time_start = $_POST['timeStart'];
$time_end = $_POST['timeEnd'];

// Validate input formats
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
    !preg_match('/^\d{2}:\d{2}$/', $time_start) ||
    !preg_match('/^\d{2}:\d{2}$/', $time_end)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date or time format.']);
    exit;
}

// Combine date and time for comparison
$start_datetime = "$date $time_start:00";
$end_datetime = "$date $time_end:00";

// Validate that end time is after start time
if (strtotime($end_datetime) <= strtotime($start_datetime)) {
    echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
    exit;
}

// Fetch studio operating hours
$studio_query = "SELECT Time_IN, Time_OUT FROM studios WHERE StudioID = ?";
$stmt = mysqli_prepare($conn, $studio_query);
mysqli_stmt_bind_param($stmt, "i", $studio_id);
mysqli_stmt_execute($stmt);
$studio_result = mysqli_stmt_get_result($stmt);

if ($studio = mysqli_fetch_assoc($studio_result)) {
    $time_in = $studio['Time_IN'];
    $time_out = $studio['Time_OUT'];
} else {
    mysqli_stmt_close($stmt);
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Studio not found.']);
    exit;
}
mysqli_stmt_close($stmt);

// Check if the slot is within studio hours
$studio_start = "$date $time_in";
$studio_end = "$date $time_out";
if (strtotime($start_datetime) < strtotime($studio_start) || strtotime($end_datetime) > strtotime($studio_end)) {
    echo json_encode(['success' => false, 'message' => 'Selected time is outside studio operating hours.']);
    exit;
}

// Check for overlapping bookings
$bookings_query = "SELECT ScheduleID FROM schedules 
                  WHERE StudioID = ? 
                  AND Sched_Date = ? 
                  AND (
                      (Time_Start < ? AND Time_End > ?) OR 
                      (Time_Start < ? AND Time_End > ?) OR 
                      (Time_Start > ? AND Time_End < ?)
                  )";
$stmt = mysqli_prepare($conn, $bookings_query);
mysqli_stmt_bind_param($stmt, "isssssss", 
    $studio_id, 
    $date, 
    $end_datetime, $start_datetime, // (Time_Start < $end_datetime AND Time_End > $start_datetime)
    $end_datetime, $start_datetime, // (Time_Start < $end_datetime AND Time_End > $start_datetime)
    $start_datetime, $end_datetime  // (Time_Start > $start_datetime AND Time_End < $end_datetime)
);
mysqli_stmt_execute($stmt);
$bookings_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($bookings_result) > 0) {
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => false, 'message' => 'This time slot is already booked.']);
    exit;
}
mysqli_stmt_close($stmt);

// Check against user's selected slots in session to prevent double booking
if (isset($_SESSION['selected_slots']) && is_array($_SESSION['selected_slots'])) {
    $selectedStart = strtotime($start_datetime);
    $selectedEnd = strtotime($end_datetime);
    
    foreach ($_SESSION['selected_slots'] as $slot) {
        // Only consider slots for the same studio
        if ((int)($slot['studio_id'] ?? 0) !== (int)$studio_id) { continue; }
        // Skip if the slot is for a different date
        if ($slot['date'] !== $date) continue;
        
        $slotStart = strtotime($slot['date'] . ' ' . $slot['start'] . ':00');
        $slotEnd = strtotime($slot['date'] . ' ' . $slot['end'] . ':00');
        
        // Check for any overlap with existing selected slots
        if (($selectedStart < $slotEnd && $selectedEnd > $slotStart)) {
            echo json_encode(['success' => false, 'message' => 'This time slot overlaps with one of your selected slots.']);
            exit;
        }
    }
}

// If no conflicts, the slot is available
echo json_encode(['success' => true, 'message' => 'This time slot is available!']);
?>
