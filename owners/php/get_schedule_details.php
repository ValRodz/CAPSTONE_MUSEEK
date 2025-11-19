<?php
// Return schedule details as JSON for the owner schedule page
session_start();

header('Content-Type: application/json');

// Ensure user is logged in as owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include DB connection
include '../../shared/config/db pdo.php';

$ownerId = $_SESSION['user_id'];

// Validate schedule id
$scheduleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($scheduleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid schedule id']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT sch.ScheduleID, sch.Sched_Date, sch.Time_Start, sch.Time_End,
               s.StudioID, s.StudioName, a.Avail_Name AS availability, 
               b.BookingID, c.Name AS client_name, c.Email AS client_email, c.Phone AS client_phone,
               GROUP_CONCAT(DISTINCT srv.ServiceType ORDER BY srv.ServiceType SEPARATOR ', ') as services,
               GROUP_CONCAT(DISTINCT i.Name ORDER BY i.Name SEPARATOR ', ') as instructors
        FROM schedules sch
        JOIN studios s ON sch.StudioID = s.StudioID
        JOIN avail_stats a ON sch.Avail_StatsID = a.Avail_StatsID
        LEFT JOIN bookings b ON sch.ScheduleID = b.ScheduleID
        LEFT JOIN clients c ON b.ClientID = c.ClientID
        LEFT JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
        LEFT JOIN services srv ON bsrv.ServiceID = srv.ServiceID
        LEFT JOIN instructors i ON bsrv.InstructorID = i.InstructorID
        WHERE sch.ScheduleID = :id AND s.OwnerID = :ownerId
        GROUP BY sch.ScheduleID
    ");
    $stmt->execute([':id' => $scheduleId, ':ownerId' => $ownerId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found']);
        exit();
    }

    // Normalize fields for frontend
    echo json_encode([
        'success' => true,
        'ScheduleID' => (int)$data['ScheduleID'],
        'StudioID' => (int)$data['StudioID'],
        'StudioName' => $data['StudioName'],
        'Sched_Date' => $data['Sched_Date'],
        'Time_Start' => $data['Time_Start'],
        'Time_End' => $data['Time_End'],
        'availability' => $data['availability'],
        'BookingID' => $data['BookingID'] ? (int)$data['BookingID'] : null,
        'client_name' => $data['client_name'] ?? null,
        'client_email' => $data['client_email'] ?? null,
        'client_phone' => $data['client_phone'] ?? null,
        'services' => $data['services'] ?? null,
        'instructors' => $data['instructors'] ?? null
    ]);
    exit();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}