<?php
session_start();
require_once __DIR__ . '/../../shared/config/db pdo.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is authenticated and is an admin/owner
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode([
        'success' => false, 
        'error' => 'Unauthorized access. Please log in as an admin.',
        'error_code' => 'UNAUTHORIZED'
    ]);
    exit();
}

// Check if required parameters are provided
if (!isset($_POST['month']) || !isset($_POST['year']) || !isset($_POST['studio_ids'])) {
    echo json_encode([
        'success' => false, 
        'error' => 'Missing required parameters (month, year, studio_ids).',
        'error_code' => 'MISSING_PARAMETERS'
    ]);
    exit();
}

$month = (int)$_POST['month'];
$year = (int)$_POST['year'];
$studioIds = $_POST['studio_ids'];

// Validate month and year
if ($month < 1 || $month > 12 || $year < 2020 || $year > 2030) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid month or year provided.',
        'error_code' => 'INVALID_DATE'
    ]);
    exit();
}

// Validate studio IDs
if (!is_array($studioIds) || empty($studioIds)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid studio IDs provided.',
        'error_code' => 'INVALID_STUDIO_IDS'
    ]);
    exit();
}

// Sanitize studio IDs
$studioIds = array_map('intval', $studioIds);
$studioIds = array_filter($studioIds, function($id) { return $id > 0; });

if (empty($studioIds)) {
    echo json_encode([
        'success' => false, 
        'error' => 'No valid studio IDs provided.',
        'error_code' => 'NO_VALID_STUDIO_IDS'
    ]);
    exit();
}

try {
    // Fetch calendar bookings for the specified month and year
    $placeholders = str_repeat('?,', count($studioIds) - 1) . '?';
    $calendarBookingsStmt = $pdo->prepare("
        SELECT DAY(sch.Sched_Date) as day, COUNT(*) as count
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.StudioID IN ($placeholders)
        AND MONTH(sch.Sched_Date) = ?
        AND YEAR(sch.Sched_Date) = ?
        GROUP BY sch.Sched_Date
        ORDER BY sch.Sched_Date
    ");
    
    $params = array_merge($studioIds, [$month, $year]);
    $calendarBookingsStmt->execute($params);
    $calendarBookings = $calendarBookingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Log the request for debugging
    error_log("Calendar bookings fetched for month: $month, year: $year, studios: " . implode(',', $studioIds) . ", found: " . count($calendarBookings) . " days with bookings");

    echo json_encode([
        'success' => true,
        'bookings' => $calendarBookings,
        'month' => $month,
        'year' => $year,
        'studio_count' => count($studioIds)
    ]);

} catch (Exception $e) {
    error_log("Error fetching calendar bookings: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'An error occurred while fetching calendar bookings. Please try again.',
        'error_code' => 'DATABASE_ERROR'
    ]);
}
?>
