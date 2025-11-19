<?php
// check_instrument.php - Real-time instrument availability check
session_start();
header('Content-Type: application/json');

include '../../shared/config/db.php';

function json_error($message, $status = 400, $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

function json_ok($data) {
    echo json_encode(['success' => true] + $data);
    exit;
}

// Require login
if (!isset($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}

// Read parameters (support GET and POST)
$instrument_id = isset($_REQUEST['instrument_id']) ? (int)$_REQUEST['instrument_id'] : 0;
$studio_id     = isset($_REQUEST['studio_id']) ? (int)$_REQUEST['studio_id'] : 0;
$date          = isset($_REQUEST['date']) ? $_REQUEST['date'] : '';
$start_time    = isset($_REQUEST['start_time']) ? $_REQUEST['start_time'] : '';
$end_time      = isset($_REQUEST['end_time']) ? $_REQUEST['end_time'] : '';
$quantity      = isset($_REQUEST['quantity']) ? (int)$_REQUEST['quantity'] : 1;

if ($instrument_id <= 0 || $studio_id <= 0 || empty($date) || empty($start_time) || empty($end_time)) {
    json_error('Missing or invalid parameters', 422, [
        'params' => compact('instrument_id','studio_id','date','start_time','end_time','quantity')
    ]);
}

// Validate date/time format
$start_dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $start_time);
$end_dt   = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $end_time);
if (!$start_dt || !$end_dt || $start_dt >= $end_dt) {
    json_error('Invalid date/time range');
}

// Fetch instrument details
$stmt = $conn->prepare('SELECT InstrumentID, StudioID, Name, HourlyRate, Quantity, IsActive FROM instruments WHERE InstrumentID = ?');
if (!$stmt) json_error('DB error: prepare failed (instrument)');
$stmt->bind_param('i', $instrument_id);
$stmt->execute();
$res = $stmt->get_result();
$instrument = $res->fetch_assoc();
$stmt->close();

if (!$instrument) json_error('Instrument not found', 404);
if ((int)$instrument['StudioID'] !== $studio_id) json_error('Instrument does not belong to this studio', 422);
if ((int)$instrument['IsActive'] !== 1) json_error('Instrument is inactive', 422);

$total_qty = (int)$instrument['Quantity'];

// Compute overlapping reserved quantity
// Overlap: (s.Time_Start < end_time) AND (s.Time_End > start_time) on same s.Sched_Date
$overlap_sql = "SELECT COALESCE(SUM(ba.Quantity), 0) AS reserved_qty
                FROM booking_addons ba
                JOIN bookings b ON ba.BookingID = b.BookingID
                JOIN schedules s ON b.ScheduleID = s.ScheduleID
                WHERE ba.InstrumentID = ?
                  AND s.Sched_Date = ?
                  AND s.Time_Start < ?
                  AND s.Time_End > ?
                  AND b.Book_StatsID IN (1,2)";

$stmt = $conn->prepare($overlap_sql);
if (!$stmt) json_error('DB error: prepare failed (overlap)');
$end_param = $end_dt->format('H:i:00');
$start_param = $start_dt->format('H:i:00');
$stmt->bind_param('isss', $instrument_id, $date, $end_param, $start_param);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

$reserved = (int)($row ? $row['reserved_qty'] : 0);
$available = max(0, $total_qty - $reserved);
$can_rent = $available >= $quantity;

json_ok([
    'instrument_id' => $instrument_id,
    'studio_id' => $studio_id,
    'date' => $date,
    'start_time' => $start_dt->format('H:i'),
    'end_time' => $end_dt->format('H:i'),
    'total' => $total_qty,
    'reserved' => $reserved,
    'available' => $available,
    'requested' => $quantity,
    'can_rent' => $can_rent
]);