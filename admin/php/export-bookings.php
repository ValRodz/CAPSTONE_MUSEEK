<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/AuditLog.php';

$db = Database::getInstance()->getConnection();
$auditLog = new AuditLog();

// Get filter parameters
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$studioId = $_GET['studio_id'] ?? null;
$status = $_GET['status'] ?? '';

// Unified query aligned with current schema (bookings + schedules + book_stats + payment)
$sql = "SELECT 
    b.BookingID AS BookingID,
    sc.Sched_Date AS booking_date,
    sc.Time_Start AS Time_IN,
    sc.Time_End AS Time_OUT,
    c.Name AS client_name,
    c.Email AS client_email,
    c.Phone AS client_phone,
    s.StudioName AS studio_name,
    so.Name AS owner_name,
    GROUP_CONCAT(DISTINCT sv.ServiceType ORDER BY sv.ServiceType SEPARATOR ', ') AS service_name,
    SUM(bsrv.service_price) AS service_price,
    bs.Book_Stats AS status,
    p.Amount AS payment_amount,
    p.Pay_Stats AS payment_status
FROM bookings b
INNER JOIN clients c ON b.ClientID = c.ClientID
INNER JOIN studios s ON b.StudioID = s.StudioID
LEFT JOIN studio_owners so ON s.OwnerID = so.OwnerID
LEFT JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
LEFT JOIN services sv ON bsrv.ServiceID = sv.ServiceID
LEFT JOIN schedules sc ON b.ScheduleID = sc.ScheduleID
LEFT JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
LEFT JOIN payment p ON b.BookingID = p.BookingID
WHERE 1=1";

$params = [];

if (!empty($startDate)) {
    $sql .= " AND DATE(sc.Sched_Date) >= ?";
    $params[] = $startDate;
}

if (!empty($endDate)) {
    $sql .= " AND DATE(sc.Sched_Date) <= ?";
    $params[] = $endDate;
}

if (!empty($studioId)) {
    $sql .= " AND b.StudioID = ?";
    $params[] = $studioId;
}

if (!empty($status)) {
    $sql .= " AND b.Book_StatsID = ?";
    $params[] = $status;
}

$sql .= " GROUP BY b.BookingID ORDER BY sc.Sched_Date DESC, sc.Time_Start DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log export action
$adminId = $_SESSION['admin_id'];
$auditLog->log('Admin', $adminId, 'EXPORTED_BOOKINGS', 'Booking', 0, "Exported " . count($bookings) . " bookings to CSV");

// Set headers for CSV download
$filename = 'bookings_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers
$headers = [
    'Booking ID',
    'Booking Date',
    'Time In',
    'Time Out',
    'Client Name',
    'Client Email',
    'Client Phone',
    'Studio Name',
    'Studio Owner',
    'Service Name',
    'Service Price',
    'Status',
    'Payment Amount',
    'Payment Status'
];

fputcsv($output, $headers);

// Add data rows
foreach ($bookings as $booking) {
    $row = [
        $booking['BookingID'],
        $booking['booking_date'],
        $booking['Time_IN'] ?? '',
        $booking['Time_OUT'] ?? '',
        $booking['client_name'],
        $booking['client_email'],
        $booking['client_phone'],
        $booking['studio_name'],
        $booking['owner_name'],
        $booking['service_name'],
        number_format($booking['service_price'], 2),
        $booking['status'],
        $booking['payment_amount'] ? number_format($booking['payment_amount'], 2) : '0.00',
        $booking['payment_status'] ?: 'Pending'
    ];
    
    fputcsv($output, $row);
}

fclose($output);
exit;
?>
