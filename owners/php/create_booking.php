<?php
// Owner-side endpoint to create a new booking (and schedule if needed)
// Returns JSON and enforces studio ownership
session_start();
header('Content-Type: application/json');

// Auth: must be logged in as owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../shared/config/db pdo.php';

$ownerId = (int)$_SESSION['user_id'];

// Accept JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    // Accept form-encoded as fallback
    $data = $_POST;
}

function respond($ok, $msg, $extra = []) {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit();
}

try {
    // Extract fields
    $studioId      = isset($data['studio_id']) ? (int)$data['studio_id'] : 0;
    $scheduleId    = isset($data['schedule_id']) ? (int)$data['schedule_id'] : 0;
    $slotDate      = isset($data['slot_date']) ? trim($data['slot_date']) : ''; // YYYY-MM-DD
    $timeStart     = isset($data['time_start']) ? trim($data['time_start']) : ''; // HH:MM:SS or HH:MM
    $timeEnd       = isset($data['time_end']) ? trim($data['time_end']) : '';
    $serviceId     = isset($data['service_id']) ? (int)$data['service_id'] : 0;
    $instructorId  = isset($data['instructor_id']) && $data['instructor_id'] !== '' ? (int)$data['instructor_id'] : null;
    $clientId      = isset($data['client_id']) ? (int)$data['client_id'] : 0;
    $clientName    = isset($data['client_name']) ? trim($data['client_name']) : '';
    $clientEmail   = isset($data['client_email']) ? trim($data['client_email']) : '';
    $clientPhone   = isset($data['client_phone']) ? trim($data['client_phone']) : '';

    // Basic validation
    if ($studioId <= 0) {
        respond(false, 'Studio is required');
    }
    if ($serviceId <= 0) {
        respond(false, 'Service is required');
    }
    if ($scheduleId <= 0) {
        if (empty($slotDate) || empty($timeStart) || empty($timeEnd)) {
            respond(false, 'Either a schedule_id or slot date & times are required');
        }
    }
    if ($clientId <= 0 && empty($clientName)) {
        respond(false, 'Client is required (select existing or enter details)');
    }

    // Ensure studio belongs to this owner
    $st = $pdo->prepare('SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ?');
    $st->execute([$studioId, $ownerId]);
    if (!$st->fetch()) {
        respond(false, 'Invalid studio or access denied');
    }

    // Create client on-the-fly if information provided and no client_id
    if ($clientId <= 0) {
        // If email provided, try to find existing client by email
        $cid = null;
        if (!empty($clientEmail)) {
            $cst = $pdo->prepare('SELECT ClientID FROM clients WHERE Email = ? LIMIT 1');
            $cst->execute([$clientEmail]);
            $cid = $cst->fetchColumn();
        }
        if ($cid) {
            $clientId = (int)$cid;
        } else {
            // Insert minimal client record
            $ins = $pdo->prepare('INSERT INTO clients (Name, Email, Phone) VALUES (?, ?, ?)');
            $ins->execute([$clientName, $clientEmail, $clientPhone]);
            $clientId = (int)$pdo->lastInsertId();
        }
    }

    // Prevent creating bookings in the past (based on server date)
    if ($scheduleId <= 0 && !empty($slotDate)) {
        $today = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
        $slot = DateTime::createFromFormat('Y-m-d', $slotDate, new DateTimeZone('America/Los_Angeles'));
        if ($slot && $slot < $today->setTime(0,0,0)) {
            respond(false, 'Selected date is in the past');
        }
    }

    // Resolve or create schedule
    if ($scheduleId > 0) {
        // Validate schedule belongs to studio and owner
        $sch = $pdo->prepare('SELECT sch.ScheduleID, sch.Avail_StatsID FROM schedules sch JOIN studios s ON sch.StudioID = s.StudioID WHERE sch.ScheduleID = ? AND sch.StudioID = ? AND s.OwnerID = ?');
        $sch->execute([$scheduleId, $studioId, $ownerId]);
        $schedule = $sch->fetch(PDO::FETCH_ASSOC);
        if (!$schedule) {
            respond(false, 'Invalid schedule for selected studio');
        }
        // Mark schedule as booked (2) if not already
        if ((int)$schedule['Avail_StatsID'] !== 2) {
            $upd = $pdo->prepare('UPDATE schedules SET Avail_StatsID = 2 WHERE ScheduleID = ?');
            $upd->execute([$scheduleId]);
        }
    } else {
        // Create new schedule for this booking (set to Booked = 2)
        // Validate times
        $tsStart = strtotime($timeStart);
        $tsEnd   = strtotime($timeEnd);
        if ($tsStart === false || $tsEnd === false || $tsEnd <= $tsStart) {
            respond(false, 'Invalid time range');
        }

        // Check overlapping booked schedules for the same studio/date
        $overlap = $pdo->prepare('SELECT COUNT(*) FROM schedules WHERE StudioID = ? AND Sched_Date = ? AND Avail_StatsID = 2 AND ((Time_Start <= ? AND Time_End > ?) OR (Time_Start < ? AND Time_End >= ?) OR (Time_Start >= ? AND Time_End <= ?))');
        $overlap->execute([$studioId, $slotDate, $timeStart, $timeStart, $timeEnd, $timeEnd, $timeStart, $timeEnd]);
        if ((int)$overlap->fetchColumn() > 0) {
            respond(false, 'Time slot overlaps with an existing booked schedule');
        }

        $insSch = $pdo->prepare('INSERT INTO schedules (OwnerID, StudioID, Sched_Date, Time_Start, Time_End, Avail_StatsID) VALUES (?, ?, ?, ?, ?, 2)');
        $insSch->execute([$ownerId, $studioId, $slotDate, $timeStart, $timeEnd]);
        $scheduleId = (int)$pdo->lastInsertId();
    }

    // Insert booking (new schema: ServiceID and InstructorID are in booking_services table)
    $pdo->beginTransaction();
    try {
        // Insert into bookings table (no ServiceID or InstructorID here anymore)
        $stmt = $pdo->prepare('INSERT INTO bookings (ClientID, StudioID, ScheduleID, Book_StatsID, booking_date) VALUES (?, ?, ?, 2, NOW())');
        $stmt->execute([$clientId, $studioId, $scheduleId]);
        $bookingId = (int)$pdo->lastInsertId();
        
        // Get service price
        $priceStmt = $pdo->prepare('SELECT Price FROM services WHERE ServiceID = ?');
        $priceStmt->execute([$serviceId]);
        $servicePrice = (float)($priceStmt->fetchColumn() ?: 0);
        
        // Insert into booking_services junction table
        if ($instructorId === null || $instructorId <= 0) {
            $bsStmt = $pdo->prepare('INSERT INTO booking_services (BookingID, ServiceID, InstructorID, service_price) VALUES (?, ?, NULL, ?)');
            $bsStmt->execute([$bookingId, $serviceId, $servicePrice]);
        } else {
            $bsStmt = $pdo->prepare('INSERT INTO booking_services (BookingID, ServiceID, InstructorID, service_price) VALUES (?, ?, ?, ?)');
            $bsStmt->execute([$bookingId, $serviceId, $instructorId, $servicePrice]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        respond(false, 'Failed to create booking: ' . $e->getMessage());
    }

    respond(true, 'Booking created', ['booking_id' => $bookingId, 'schedule_id' => $scheduleId]);
} catch (Exception $e) {
    respond(false, 'Server error: ' . $e->getMessage());
}

?>