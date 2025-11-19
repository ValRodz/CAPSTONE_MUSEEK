<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

// Include database connection
include '../../shared/config/db pdo.php';

// Set timezone to PST
date_default_timezone_set('America/Los_Angeles');

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Fetch owner information
$ownerStmt = $pdo->prepare("SELECT Name, Email FROM studio_owners WHERE OwnerID = ?");
$ownerStmt->execute([$ownerId]);
$owner = $ownerStmt->fetch();

if (!$owner) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Archive past bookings that are not confirmed (run in a transaction to avoid race conditions)
try {
    $pdo->beginTransaction();
    $archiveStmt = $pdo->prepare("
        UPDATE bookings b
        JOIN schedules s ON b.ScheduleID = s.ScheduleID
        JOIN studios st ON b.StudioID = st.StudioID
        SET b.Book_StatsID = 4 -- Archived status
        WHERE st.OwnerID = ?
        AND s.Sched_Date < CURDATE()
        AND b.Book_StatsID = 2 -- Pending status
    ");
    $archiveStmt->execute([$ownerId]);
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Archive Bookings Error: " . $e->getMessage());
}

// Get active tab (pending, completed, all, archived)
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'pending';

// Pagination settings
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// Get filters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Build the base query
$baseQuery = "
    SELECT b.BookingID, b.booking_date, c.Name as client_name, c.ClientID, c.Phone as client_phone, c.Email as client_email,
           s.StudioName, s.StudioID, sch.Time_Start, sch.Time_End, sch.Sched_Date,
           bs.Book_Stats as status, bs.Book_StatsID,
           COALESCE(p.Amount, 0) as amount, 
           COALESCE(p.Pay_Stats, 'N/A') as payment_status,
           GROUP_CONCAT(DISTINCT srv.ServiceType ORDER BY srv.ServiceType SEPARATOR ', ') as service_name,
           GROUP_CONCAT(DISTINCT srv.Description ORDER BY srv.ServiceType SEPARATOR ' | ') as service_description,
           SUM(bsrv.service_price) as service_price,
           GROUP_CONCAT(DISTINCT i.Name ORDER BY i.Name SEPARATOR ', ') as instructor_name,
           (SELECT bsrv2.InstructorID FROM booking_services bsrv2 WHERE bsrv2.BookingID = b.BookingID LIMIT 1) as InstructorID
    FROM bookings b
    JOIN clients c ON b.ClientID = c.ClientID
    JOIN studios s ON b.StudioID = s.StudioID
    JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
    JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
    LEFT JOIN payment p ON b.BookingID = p.BookingID
    LEFT JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
    LEFT JOIN services srv ON bsrv.ServiceID = srv.ServiceID
    LEFT JOIN instructors i ON bsrv.InstructorID = i.InstructorID
    WHERE s.OwnerID = :ownerId
";

$countBaseQuery = "
    SELECT COUNT(*) 
    FROM bookings b
    JOIN studios s ON b.StudioID = s.StudioID
    JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
    JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
    LEFT JOIN payment p ON b.BookingID = p.BookingID
    WHERE s.OwnerID = :ownerId
";

$params = [':ownerId' => $ownerId];

// Add tab-specific filters (BEFORE GROUP BY)
if ($activeTab === 'pending') {
    $baseQuery .= " AND (bs.Book_Stats = 'Pending' OR bs.Book_Stats = 'Confirmed') AND sch.Sched_Date >= CURDATE()";
    $countBaseQuery .= " AND (bs.Book_Stats = 'Pending' OR bs.Book_Stats = 'Confirmed') AND sch.Sched_Date >= CURDATE()";
} elseif ($activeTab === 'completed') {
    $baseQuery .= " AND (bs.Book_Stats = 'Finished' OR (bs.Book_Stats = 'Confirmed' AND sch.Sched_Date < CURDATE()) OR p.Pay_Stats = 'Completed')";
    $countBaseQuery .= " AND (bs.Book_Stats = 'Finished' OR (bs.Book_Stats = 'Confirmed' AND sch.Sched_Date < CURDATE()) OR p.Pay_Stats = 'Completed')";
} elseif ($activeTab === 'archived') {
    $baseQuery .= " AND bs.Book_Stats = 'Archived'";
    $countBaseQuery .= " AND bs.Book_Stats = 'Archived'";
}

// Add status filter if provided
if (!empty($statusFilter)) {
    $baseQuery .= " AND bs.Book_Stats = :status";
    $countBaseQuery .= " AND bs.Book_Stats = :status";
    $params[':status'] = $statusFilter;
}

// Add date filter if provided
if (!empty($dateFilter)) {
    $baseQuery .= " AND sch.Sched_Date = :date";
    $countBaseQuery .= " AND sch.Sched_Date = :date";
    $params[':date'] = $dateFilter;
}

// Add search filter if provided
if (!empty($search)) {
    $baseQuery .= " AND (c.Name LIKE :search OR s.StudioName LIKE :search OR srv.ServiceType LIKE :search OR i.Name LIKE :search OR b.BookingID = :searchId)";
    $countBaseQuery .= " AND (b.ClientID IN (SELECT ClientID FROM clients WHERE Name LIKE :search) OR b.StudioID IN (SELECT StudioID FROM studios WHERE StudioName LIKE :search) OR b.BookingID IN (SELECT DISTINCT bsrv.BookingID FROM booking_services bsrv JOIN services srv ON bsrv.ServiceID = srv.ServiceID WHERE srv.ServiceType LIKE :search) OR b.BookingID IN (SELECT DISTINCT bsrv.BookingID FROM booking_services bsrv JOIN instructors i ON bsrv.InstructorID = i.InstructorID WHERE i.Name LIKE :search) OR b.BookingID = :searchId)";
    $params[':search'] = "%$search%";
    $params[':searchId'] = ctype_digit($search) ? (int)$search : -1;
}

// Add GROUP BY (must come after WHERE clause)
$baseQuery .= " GROUP BY b.BookingID";

// Add order and limit
$query = $baseQuery . " ORDER BY sch.Sched_Date DESC, sch.Time_Start DESC LIMIT :offset, :limit";

// Execute the queries
$stmt = $pdo->prepare($query);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$countStmt = $pdo->prepare($countBaseQuery);
foreach ($params as $key => $value) {
    if ($key !== ':offset' && $key !== ':limit') {
        $countStmt->bindValue($key, $value);
    }
}
$countStmt->execute();
$totalBookings = $countStmt->fetchColumn();
$totalPages = ceil($totalBookings / $itemsPerPage);

// Check for unread notifications
$notificationsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE OwnerID = ? 
    AND IsRead = 0
");
$notificationsStmt->execute([$ownerId]);
$unreadNotifications = $notificationsStmt->fetchColumn();

// Handle AJAX request for detailed booking data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_booking_details' && isset($_GET['booking_id'])) {
    header('Content-Type: application/json');
    $bookingId = (int)$_GET['booking_id'];
    
    try {
        // Fetch booking details with services and payment proof
        $detailStmt = $pdo->prepare("
            SELECT 
                bs.booking_service_id,
                srv.ServiceID,
                srv.ServiceType,
                srv.Description as ServiceDescription,
                bs.service_price,
                i.InstructorID,
                i.Name as InstructorName,
                i.Phone as InstructorPhone
            FROM booking_services bs
            JOIN services srv ON bs.ServiceID = srv.ServiceID
            LEFT JOIN instructors i ON bs.InstructorID = i.InstructorID
            WHERE bs.BookingID = ?
            ORDER BY srv.ServiceType
        ");
        $detailStmt->execute([$bookingId]);
        $services = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch payment proof information
        $paymentProofStmt = $pdo->prepare("
            SELECT 
                g.GCashID,
                g.Ref_Num,
                g.gcash_sender_number,
                g.payment_proof_path,
                g.payment_notes,
                g.payment_submitted_at
            FROM payment p
            LEFT JOIN g_cash g ON p.GCashID = g.GCashID
            WHERE p.BookingID = ?
        ");
        $paymentProofStmt->execute([$bookingId]);
        $paymentProof = $paymentProofStmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch equipment for each service
        foreach ($services as &$service) {
            $equipStmt = $pdo->prepare("
                SELECT 
                    ea.equipment_id,
                    ea.equipment_name,
                    ea.rental_price as unit_price,
                    be.quantity,
                    be.rental_price as total_price
                FROM booking_equipment be
                JOIN equipment_addons ea ON be.equipment_id = ea.equipment_id
                WHERE be.booking_service_id = ?
                ORDER BY ea.equipment_name
            ");
            $equipStmt->execute([$service['booking_service_id']]);
            $service['equipment'] = $equipStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true, 
            'services' => $services,
            'payment_proof' => $paymentProof
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch studios for this owner (for new booking form)
$studiosStmt = $pdo->prepare("
    SELECT StudioID, StudioName, Time_IN, Time_OUT
    FROM studios
    WHERE OwnerID = ? AND approved_by_admin IS NOT NULL
");
$studiosStmt->execute([$ownerId]);
$studios = $studiosStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available instructors for the edit dropdown
$instructorsStmt = $pdo->prepare("
    SELECT InstructorID, Name 
    FROM instructors 
    WHERE OwnerID = ?
");
$instructorsStmt->execute([$ownerId]);
$instructors = $instructorsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch services mapped to studios for this owner (to populate modal)
$servicesMapStmt = $pdo->prepare("\n    SELECT ss.StudioID, srv.ServiceID, srv.ServiceType\n    FROM studio_services ss\n    JOIN services srv ON ss.ServiceID = srv.ServiceID\n    JOIN studios st ON ss.StudioID = st.StudioID\n    WHERE st.OwnerID = ? AND st.approved_by_admin IS NOT NULL\n");
$servicesMapStmt->execute([$ownerId]);
$servicesByStudio = [];
foreach ($servicesMapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $sid = (int)$row['StudioID'];
    if (!isset($servicesByStudio[$sid])) { $servicesByStudio[$sid] = []; }
    $servicesByStudio[$sid][] = [
        'StudioID' => $sid,
        'ServiceID' => (int)$row['ServiceID'],
        'ServiceType' => $row['ServiceType']
    ];
}

$studioHours = [];
foreach ($studios as $st) {
    $sid = (int)$st['StudioID'];
    $studioHours[$sid] = [
        'Time_IN' => isset($st['Time_IN']) ? $st['Time_IN'] : '06:00',
        'Time_OUT' => isset($st['Time_OUT']) ? $st['Time_OUT'] : '22:00'
    ];
}

// Build instructors mapping per studio and service following booking.php logic
$instructorsByStudioService = [];
foreach ($studios as $st) {
    $sid = (int)$st['StudioID'];
    $restrictCheck = $pdo->prepare("SELECT COUNT(*) FROM studio_instructors WHERE StudioID = ?");
    $restrictCheck->execute([$sid]);
    $restricted = ((int)$restrictCheck->fetchColumn()) > 0;

    if ($restricted) {
        $insStmt = $pdo->prepare("\n            SELECT DISTINCT i.InstructorID, i.Name AS InstructorName, s.ServiceID\n            FROM studio_instructors si\n            JOIN instructors i ON i.InstructorID = si.InstructorID\n            JOIN instructor_services ins ON ins.InstructorID = i.InstructorID\n            JOIN services s ON s.ServiceID = ins.ServiceID\n            JOIN studio_services ss ON ss.ServiceID = s.ServiceID AND ss.StudioID = si.StudioID\n            WHERE si.StudioID = ? AND i.OwnerID = ? AND i.Availability = 'Avail'\n        ");
        $insStmt->execute([$sid, $ownerId]);
    } else {
        $insStmt = $pdo->prepare("\n            SELECT DISTINCT i.InstructorID, i.Name AS InstructorName, s.ServiceID\n            FROM instructors i\n            JOIN instructor_services ins ON ins.InstructorID = i.InstructorID\n            JOIN services s ON s.ServiceID = ins.ServiceID\n            JOIN studio_services ss ON ss.ServiceID = s.ServiceID\n            WHERE ss.StudioID = ? AND i.OwnerID = ? AND i.Availability = 'Avail'\n        ");
        $insStmt->execute([$sid, $ownerId]);
    }

    foreach ($insStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $svcId = (int)$row['ServiceID'];
        if (!isset($instructorsByStudioService[$sid])) { $instructorsByStudioService[$sid] = []; }
        if (!isset($instructorsByStudioService[$sid][$svcId])) { $instructorsByStudioService[$sid][$svcId] = []; }
        $instructorsByStudioService[$sid][$svcId][] = [
            'InstructorID' => (int)$row['InstructorID'],
            'InstructorName' => $row['InstructorName']
        ];
    }
}

// Fetch existing clients tied to this owner's bookings for quick selection
$clientsStmt = $pdo->prepare("\n    SELECT DISTINCT c.ClientID, c.Name, c.Email\n    FROM clients c\n    JOIN bookings b ON c.ClientID = b.ClientID\n    JOIN studios s ON b.StudioID = s.StudioID\n    WHERE s.OwnerID = ?\n    ORDER BY c.Name\n");
$clientsStmt->execute([$ownerId]);
$existingClients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to get status badge color
function getStatusBadge($status)
{
    switch (strtolower($status)) {
        case 'confirmed':
            return '<span class="badge bg-red-600">confirmed</span>';
        case 'pending':
            return '<span class="badge bg-transparent border border-gray-500 text-gray-300">pending</span>';
        case 'cancelled':
            return '<span class="badge bg-gray-700">cancelled</span>';
        case 'archived':
            return '<span class="badge bg-blue-700">archived</span>';
        case 'finished':
            return '<span class="badge bg-purple-600">finished</span>';
        default:
            return '<span class="badge bg-gray-600">' . htmlspecialchars($status) . '</span>';
    }
}

// Helper function to get payment status badge
function getPaymentStatusBadge($status)
{
    switch (strtolower($status)) {
        case 'completed':
            return '<span class="badge bg-green-600">completed</span>';
        case 'pending':
            return '<span class="badge bg-yellow-600">pending</span>';
        case 'failed':
            return '<span class="badge bg-red-600">failed</span>';
        case 'cancelled':
            return '<span class="badge bg-gray-700">cancelled</span>';
        case 'n/a':
            return '<span class="badge bg-gray-600">N/A</span>';
        default:
            return '<span class="badge bg-gray-600">' . htmlspecialchars($status) . '</span>';
    }
}

// Helper function to get customer initials
function getInitials($name)
{
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Helper function to check if payment request is allowed
function canRequestPayment($bookingStatus, $paymentStatus)
{
    return (strtolower($bookingStatus) === 'pending' &&
        (strtolower($paymentStatus) === 'pending' || strtolower($paymentStatus) === 'n/a'));
}

// Helper function to check if booking confirmation is allowed
function canConfirmBooking($bookingStatus)
{
    return strtolower($bookingStatus) === 'pending';
}

// Helper function to check if payment confirmation is allowed
function canConfirmPayment($bookingStatus, $paymentStatus)
{
    return (strtolower($bookingStatus) === 'confirmed' &&
        (strtolower($paymentStatus) !== 'completed'));
}

// Helper function to check if finishing a booking is allowed
function canFinishBooking($bookingStatus, $paymentStatus)
{
    return (strtolower($bookingStatus) === 'confirmed' &&
        strtolower($paymentStatus) === 'completed');
}

// Helper function to check if cancelling a booking is allowed
function canCancelBooking($bookingStatus)
{
    return (strtolower($bookingStatus) === 'pending' || strtolower($bookingStatus) === 'confirmed');
}

// Helper function to check if archiving a booking is allowed
function canArchiveBooking($bookingStatus)
{
    return strtolower($bookingStatus) !== 'archived';
}

// Helper function to check if a date is in the past
function isDatePast($date)
{
    $currentDate = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
    $schedDate = new DateTime($date, new DateTimeZone('America/Los_Angeles'));
    return $schedDate < $currentDate->setTime(0, 0, 0);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>MuSeek - Bookings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        body {
            font-family: "Inter", sans-serif;
        }

        .sidebar {
            display: none;
            height: 100vh;
            width: 250px;
            position: fixed;
            z-index: 40;
            top: 0;
            left: 0;
            transition: transform 0.3s ease;
        }

        .sidebar.active {
            display: block;
        }

        .main-content {
            margin-left: var(--sidebar-collapsed-width);
            min-height: 100vh;
            background: var(--netflix-black);
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
        }

        .avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 9999px;
            background-color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .booking-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .booking-table th {
            text-align: left;
            padding: 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #9ca3af;
            background-color: #0a0a0a;
            border-bottom: 1px solid #222222;
        }

        .booking-table td {
            padding: 0.75rem;
            font-size: 0.875rem;
            border-bottom: 1px solid #222222;
        }

        .booking-table tr {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .booking-table tr:hover {
            background-color: #111111;
        }

        .date-picker-container {
            position: relative;
        }

        .date-picker-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: auto;
            color: #ffffff;
            cursor: pointer;
        }

        .pagination-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            height: 2 .5rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background-color 0.2s;
            padding: 0 0.75rem;
            border: 1px solid #222222;
            margin: 0 0.25rem;
        }

        .pagination-button.active {
            background-color: #dc2626;
            color: white;
            border-color: #dc2626;
        }

        .pagination-button:hover:not(.active):not(:disabled) {
            background-color: #222222;
        }

        .pagination-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
        }

        .modal-content {
            background-color: #0a0a0a;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            width: 80%;
            max-width: 600px;
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            bottom: 100%;
            margin-bottom: 5px;
            background-color: #0a0a0a;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 1;
            border-radius: 0.375rem;
            border: 1px solid #222222;
        }

        .dropdown-content a {
            color: white;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 0.875rem;
        }

        .dropdown-content a:hover {
            background-color: #222222;
        }

        .dropdown:hover .dropdown-content {
            display: block;
        }

        .past-date {
            color: #6b7280;
            background-color: rgba(75, 85, 99, 0.1);
        }

        /* Deep-link highlight for a specific booking row */
        .row-highlight {
            position: relative;
            background-color: rgba(220, 38, 38, 0.08);
            outline: 2px solid #dc2626;
        }

        @keyframes pulseRowHighlight {
            0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
        }

        .row-highlight {
            animation: pulseRowHighlight 2s ease-out 2;
        }

        .tab-button {
            padding: 0.75rem 1.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.375rem 0.375rem 0 0;
            border: 1px solid #222222;
            border-bottom: none;
            background-color: #0a0a0a;
            color: #9ca3af;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .tab-button.active {
            background-color: #dc2626;
            color: white;
            border-color: #dc2626;
        }

        .tab-button:hover:not(.active) {
            background-color: #161616;
            color: white;
        }

        .booking-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 9999px;
            background-color: #374151;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .tab-button.active .booking-count {
            background-color: white;
            color: #dc2626;
        }

        .edit-instructor-btn {
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 0.25rem;
            transition: color 0.2s;
        }

        .edit-instructor-btn:hover {
            color: #dc2626;
        }
    </style>
</head>

<body class="bg-[#161616] text-white">

<?php include __DIR__ . '/sidebar_netflix.php'; ?>
    <!-- Main Content -->
    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-1">BOOKINGS</h1>
        </header>

        <div class="p-6">
            <!-- Filters and Actions -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                <div class="flex items-center gap-4 flex-wrap">
                    <div>
                        <label for="status-filter" class="block text-xs text-gray-400 mb-1">Status</label>
                        <select id="status-filter" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-36">
                            <option value="">All Statuses</option>
                            <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            <option value="archived" <?php echo $statusFilter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            <option value="finished" <?php echo $statusFilter === 'finished' ? 'selected' : ''; ?>>Finished</option>
                        </select>
                    </div>
                    <div class="date-picker-container">
                        <label for="date-filter" class="block text-xs text-gray-400 mb-1">Date</label>
                        <input type="date" id="date-filter" value="<?php echo $dateFilter; ?>" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-36 pr-8">
                        <div class="date-picker-icon text-white">
                            <i class="far fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div>
                        <label for="per-page" class="block text-xs text-gray-400 mb-1">Show</label>
                        <select id="per-page" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-24">
                            <option value="10" <?php echo $itemsPerPage === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $itemsPerPage === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $itemsPerPage === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $itemsPerPage === 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                    <div class="w-64">
                        <label for="search" class="block text-xs text-gray-400 mb-1">Search</label>
                        <div class="relative">
                            <input type="text" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Client, studio, service, instructor, ID" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full pr-8">
                            <button type="button" id="searchBtn" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">&nbsp;</label>
                        <button type="button" id="clearFilters" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-2 text-sm">
                            Clear Filters
                        </button>
                    </div>
                </div>
                <a href="#" id="openNewBookingModal" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-plus"></i>
                    <span>New Booking</span>
                </a>
            </div>

            <!-- Booking Tabs -->
            <div class="flex mb-0 border-b border-[#222222]">
                <a href="?tab=pending<?php echo !empty($dateFilter) ? '&date=' . $dateFilter : ''; ?><?php echo !empty($statusFilter) ? '&status=' . $statusFilter : ''; ?><?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>"
                    class="tab-button <?php echo $activeTab === 'pending' ? 'active' : ''; ?>">
                    Pending & Upcoming
                    <?php
                    $pendingStmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
                        WHERE s.OwnerID = ? 
                        AND (bs.Book_Stats = 'Pending' OR bs.Book_Stats = 'Confirmed')
                        AND sch.Sched_Date >= CURDATE()
                    ");
                    $pendingStmt->execute([$ownerId]);
                    $pendingCount = $pendingStmt->fetchColumn();
                    ?>
                    <span class="booking-count"><?php echo $pendingCount; ?></span>
                </a>
                <a href="?tab=completed<?php echo !empty($dateFilter) ? '&date=' . $dateFilter : ''; ?><?php echo !empty($statusFilter) ? '&status=' . $statusFilter : ''; ?><?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>"
                    class="tab-button <?php echo $activeTab === 'completed' ? 'active' : ''; ?>">
                    Completed
                    <?php
                    $completedStmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
                        LEFT JOIN payment p ON b.BookingID = p.BookingID
                        WHERE s.OwnerID = ? 
                        AND (bs.Book_Stats = 'Finished' OR (bs.Book_Stats = 'Confirmed' AND sch.Sched_Date < CURDATE()) OR p.Pay_Stats = 'Completed')
                    ");
                    $completedStmt->execute([$ownerId]);
                    $completedCount = $completedStmt->fetchColumn();
                    ?>
                    <span class="booking-count"><?php echo $completedCount; ?></span>
                </a>
                <a href="?tab=archived<?php echo !empty($dateFilter) ? '&date=' . $dateFilter : ''; ?><?php echo !empty($statusFilter) ? '&status=' . $statusFilter : ''; ?><?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>"
                    class="tab-button <?php echo $activeTab === 'archived' ? 'active' : ''; ?>">
                    Archived
                    <?php
                    $archivedStmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                        WHERE s.OwnerID = ? 
                        AND bs.Book_Stats = 'Archived'
                    ");
                    $archivedStmt->execute([$ownerId]);
                    $archivedCount = $archivedStmt->fetchColumn();
                    ?>
                    <span class="booking-count"><?php echo $archivedCount; ?></span>
                </a>
                <a href="?tab=all<?php echo !empty($dateFilter) ? '&date=' . $dateFilter : ''; ?><?php echo !empty($statusFilter) ? '&status=' . $statusFilter : ''; ?><?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>"
                    class="tab-button <?php echo $activeTab === 'all' ? 'active' : ''; ?>">
                    All Bookings
                    <?php
                    $allStmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        WHERE s.OwnerID = ?
                    ");
                    $allStmt->execute([$ownerId]);
                    $allCount = $allStmt->fetchColumn();
                    ?>
                    <span class="booking-count"><?php echo $allCount; ?></span>
                </a>
            </div>

            <!-- Bookings Table -->
            <div class="bg-[#0a0a0a] rounded-b-lg border-x border-b border-[#222222] overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="booking-table w-full">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Studio</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Instructor</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Amount</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-gray-400">No bookings found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bookings as $index => $booking): ?>
                                    <?php $isPastDate = isDatePast($booking['Sched_Date']); ?>
                                    <tr
                                        id="booking-row-<?php echo (int)$booking['BookingID']; ?>"
                                        data-booking-id="<?php echo (int)$booking['BookingID']; ?>"
                                        class="<?php echo $isPastDate ? 'past-date' : ''; ?>"
                                        onclick="viewBookingDetails(<?php echo $index; ?>)"
                                    >
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <div class="avatar">
                                                    <?php echo getInitials($booking['client_name']); ?>
                                                </div>
                                                <span><?php echo htmlspecialchars($booking['client_name']); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($booking['StudioName']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($booking['Sched_Date'])); ?></td>
                                        <td><?php echo date('g:i A', strtotime($booking['Time_Start'])) . ' - ' . date('g:i A', strtotime($booking['Time_End'])); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($booking['instructor_name'] ?? 'Not assigned'); ?>
                                            <?php if (!empty($instructors) && !$isPastDate): ?>
                                                <button class="edit-instructor-btn" onclick="event.stopPropagation(); showEditInstructorModal(<?php echo $index; ?>, <?php echo $booking['BookingID']; ?>, <?php echo $booking['InstructorID'] ?: 'null'; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo getStatusBadge($booking['status']); ?></td>
                                        <td><?php echo isset($booking['payment_status']) ? getPaymentStatusBadge($booking['payment_status']) : '<span class="badge bg-gray-600">N/A</span>'; ?></td>
                                        <td>₱<?php echo number_format($booking['amount'], 2); ?></td>
                                        <td class="text-right">
                                            <div class="flex items-center justify-end gap-2" onclick="event.stopPropagation();">
                                                <?php if (canConfirmBooking($booking['status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-green-600 hover:bg-green-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="confirmAction('confirmBooking', <?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>, '<?php echo addslashes($booking['StudioName']); ?>', '<?php echo $booking['Sched_Date']; ?>', '<?php echo $booking['Time_Start']; ?>', '<?php echo $booking['Time_End']; ?>')">
                                                        Confirm Booking
                                                    </button>
                                                <?php elseif (canConfirmPayment($booking['status'], $booking['payment_status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="confirmAction('confirmPayment', <?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Confirm Payment
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canFinishBooking($booking['status'], $booking['payment_status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-purple-600 hover:bg-purple-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="confirmAction('finishBooking', <?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Finish Booking
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canRequestPayment($booking['status'], $booking['payment_status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-yellow-600 hover:bg-yellow-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="confirmAction('requestPayment', <?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Request Payment
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canCancelBooking($booking['status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-red-600 hover:bg-red-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="confirmAction('cancelBooking', <?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Cancel
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canArchiveBooking($booking['status'])): ?>
                                                    <button
                                                        class="bg-gray-600 hover:bg-gray-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="confirmAction('archiveBooking', <?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Archive
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div class="flex flex-col md:flex-row justify-between items-center mt-6 gap-4">
                <div class="text-sm text-gray-400">
                    Showing <span class="font-medium text-white"><?php echo count($bookings); ?></span> of
                    <span class="font-medium text-white"><?php echo $totalBookings; ?></span> bookings
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="flex items-center justify-center flex-wrap">
                        <button
                            class="pagination-button"
                            <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>
                            onclick="changePage(<?php echo $currentPage - 1; ?>)">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <?php
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $startPage + 4);
                        if ($endPage - $startPage < 4) {
                            $startPage = max(1, $endPage - 4);
                        }
                        if ($startPage > 1) {
                            echo '<button class="pagination-button" onclick="changePage(1)">1</button>';
                            if ($startPage > 2) {
                                echo '<span class="px-1">...</span>';
                            }
                        }
                        for ($i = $startPage; $i <= $endPage; $i++) {
                            $activeClass = $i === $currentPage ? 'active' : '';
                            echo "<button class=\"pagination-button $activeClass\" onclick=\"changePage($i)\">$i</button>";
                        }
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) {
                                echo '<span class="px-1">...</span>';
                            }
                            echo "<button class=\"pagination-button\" onclick=\"changePage($totalPages)\">$totalPages</button>";
                        }
                        ?>
                        <button
                            class="pagination-button"
                            <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>
                            onclick="changePage(<?php echo $currentPage + 1; ?>)">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- New Booking Modal -->
    <div id="newBookingModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">New Booking</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeNewBookingModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="newBookingForm" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Studio</label>
                        <select id="nbStudio" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full" required>
                            <option value="">Select studio</option>
                            <?php foreach ($studios as $st): ?>
                                <option value="<?php echo (int)$st['StudioID']; ?>"><?php echo htmlspecialchars($st['StudioName']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Service</label>
                        <select id="nbService" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full" required>
                            <option value="">Select service</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Instructor (optional)</label>
                        <select id="nbInstructor" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full">
                            <option value="">None</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Date</label>
                        <input type="date" id="nbDate" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full" required disabled style="pointer-events: none;" />
                    </div>
                    <div id="calendarContainer" class="p-3 bg-[#0a0a0a] border border-[#222222] rounded-md">
                        <div class="flex items-center justify-between mb-2">
                            <button type="button" id="calPrev" class="bg-[#222222] hover:bg-[#333333] text-white rounded px-2 py-1 text-xs"><i class="fas fa-chevron-left"></i></button>
                            <div id="calMonthLabel" class="text-sm"></div>
                            <button type="button" id="calNext" class="bg-[#222222] hover:bg-[#333333] text-white rounded px-2 py-1 text-xs"><i class="fas fa-chevron-right"></i></button>
                        </div>
                        <div class="grid grid-cols-7 gap-1 text-center text-xs text-gray-400">
                            <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
                        </div>
                        <div id="calDays" class="grid grid-cols-7 gap-1 mt-1"></div>
                    </div>
                    <div id="timeSlotsContainer" class="p-3 bg-[#0a0a0a] border border-[#222222] rounded-md">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm text-gray-400">Available time slots</span>
                            <button type="button" id="clearSlotSelection" class="text-xs bg-[#222222] hover:bg-[#333333] text-white rounded px-2 py-1">Clear</button>
                        </div>
                        <div id="timeSlots" class="grid grid-cols-4 gap-2"></div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Start Time</label>
                        <select id="nbTimeStart" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full" required disabled style="pointer-events: none;">
                            <option value="">Select start time</option>
                            <?php for ($h = 0; $h < 24; $h++): 
                                $hh = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
                                $val = $hh . ':00';
                                $ampm = $h >= 12 ? 'PM' : 'AM';
                                $h12 = ($h % 12) ?: 12;
                            ?>
                                <option value="<?php echo $val; ?>"><?php echo $h12 . ':00 ' . $ampm; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">End Time</label>
                        <select id="nbTimeEnd" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full" required disabled style="pointer-events: none;">
                            <option value="">Select end time</option>
                            <?php for ($h = 0; $h < 24; $h++): 
                                $hh = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
                                $val = $hh . ':00';
                                $ampm = $h >= 12 ? 'PM' : 'AM';
                                $h12 = ($h % 12) ?: 12;
                            ?>
                                <option value="<?php echo $val; ?>"><?php echo $h12 . ':00 ' . $ampm; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block text-xs text-gray-400 mb-2">Client</label>
                    <div class="flex items-center gap-4 mb-3">
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="clientMode" value="existing" checked />
                            <span class="text-sm">Existing</span>
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="clientMode" value="new" />
                            <span class="text-sm">New</span>
                        </label>
                    </div>
                    <div id="existingClientBlock" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Select Client</label>
                            <select id="nbClient" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full">
                                <option value="">Select client</option>
                                <?php foreach ($existingClients as $cli): ?>
                                    <option value="<?php echo (int)$cli['ClientID']; ?>"><?php echo htmlspecialchars($cli['Name']); ?><?php echo !empty($cli['Email']) ? ' (' . htmlspecialchars($cli['Email']) . ')' : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div id="newClientBlock" class="grid grid-cols-1 md:grid-cols-3 gap-4" style="display: none;">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Name</label>
                            <input type="text" id="nbClientName" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Email</label>
                            <input type="email" id="nbClientEmail" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Phone</label>
                            <input type="text" id="nbClientPhone" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-full" />
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="bg-gray-600 hover:bg-gray-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeNewBookingModal()">Cancel</button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium">Create Booking</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Booking Details Modal -->
    <div id="bookingDetailsModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Details</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeBookingModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="bookingDetailsContent" class="space-y-4">
                <!-- Content will be populated dynamically -->
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button id="confirmBookingBtn" class="hidden bg-green-600 hover:bg-green-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Confirm Booking
                </button>
                <button id="confirmPaymentBtn" class="hidden bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Confirm Payment
                </button>
                <button id="finishBookingBtn" class="hidden bg-purple-600 hover:bg-purple-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Finish Booking
                </button>
                <button id="requestPaymentBtn" class="hidden bg-yellow-600 hover:bg-yellow-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Request Payment
                </button>
                <button id="cancelBookingBtn" class="hidden bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Cancel Booking
                </button>
                <button id="archiveBookingBtn" class="hidden bg-gray-600 hover:bg-gray-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Archive
                </button>
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeBookingModal()">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Payment Request Modal -->
    <div id="paymentRequestModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Payment Request</h3>
                <button class="text-gray-400 hover:text-white" onclick="closePaymentModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Payment request has been sent to the client. They will be notified to complete their payment.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closePaymentModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Booking Confirmation Modal -->
    <div id="bookingConfirmationModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Confirmation</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeConfirmationModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Booking has been confirmed. A notification has been sent to the client.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeConfirmationModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Payment Confirmation Modal -->
    <div id="paymentConfirmationModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Payment Confirmation</h3>
                <button class="text-gray-400 hover:text-white" onclick="closePaymentConfirmationModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Payment has been confirmed. A notification has been sent to the client.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closePaymentConfirmationModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Booking Finished Modal -->
    <div id="bookingFinishedModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Finished</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeBookingFinishedModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Booking has been marked as finished. A notification has been sent to the client.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeBookingFinishedModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Booking Cancelled Modal -->
    <div id="bookingCancelledModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Cancelled</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeBookingCancelledModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Booking has been cancelled. A notification has been sent to the client.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeBookingCancelledModal()">
                    OK
                </button>
            </div>
        </div>
</div>

    <!-- Booking Archived Modal -->
    <div id="bookingArchivedModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Archived</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeBookingArchivedModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Booking has been archived.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeBookingArchivedModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Action Confirmation Modal -->
    <div id="actionConfirmModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 id="actionConfirmTitle" class="text-lg font-bold">Confirm Action</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeActionConfirmModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p id="actionConfirmMessage" class="mb-4"></p>
            <div class="flex justify-end gap-3">
                <button class="bg-[#222222] hover:bg-[#333333] text-white rounded px-4 py-2 text-sm font-medium" onclick="closeActionConfirmModal()">Cancel</button>
                <button id="actionConfirmYes" class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Payment Proof Modal -->
    <div id="paymentProofModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Payment Proof</h3>
                <button class="text-gray-400 hover:text-white" onclick="closePaymentProofModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="paymentProofContent" class="space-y-4">
                <!-- Content will be populated dynamically -->
            </div>
            <div class="mt-6 flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closePaymentProofModal()">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Store bookings data for modal display
        const bookingsData = <?php echo json_encode($bookings); ?>;

        // Sidebar toggling is handled within sidebar_netflix; legacy handlers removed to avoid layout conflicts.
        const mainContent = document.getElementById('mainContent');

        // Filter functionality
        const statusFilter = document.getElementById('status-filter');
        const dateFilter = document.getElementById('date-filter');
        const perPageFilter = document.getElementById('per-page');
        const searchInput = document.getElementById('search');

        function applyFilters() {
            const status = statusFilter.value;
            const date = dateFilter.value;
            const perPage = perPageFilter.value;
            const tab = new URLSearchParams(window.location.search).get('tab') || 'pending';

            const params = new URLSearchParams();
            params.set('tab', tab);

            if (status) params.set('status', status);
            if (date) params.set('date', date);
            if (perPage) params.set('per_page', perPage);
            if (searchInput && searchInput.value) params.set('q', searchInput.value);

            params.set('page', '1');

            const queryString = params.toString();
            window.location.href = 'bookings_netflix.php' + (queryString ? '?' + queryString : '');
        }

        statusFilter.addEventListener('change', applyFilters);
        dateFilter.addEventListener('change', applyFilters);
        perPageFilter.addEventListener('change', applyFilters);
        document.getElementById('searchBtn').addEventListener('click', applyFilters);
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    applyFilters();
                }
            });
        }
        const clearBtn = document.getElementById('clearFilters');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                statusFilter.value = '';
                dateFilter.value = '';
                perPageFilter.value = '10';
                if (searchInput) searchInput.value = '';
                applyFilters();
            });
        }

        document.querySelector('.date-picker-container').addEventListener('click', function() {
            try { dateFilter.showPicker(); } catch (err) {}
        });
        const dateIcon = document.querySelector('.date-picker-icon');
        if (dateIcon) {
            dateIcon.addEventListener('click', function(e) {
                e.stopPropagation();
                try { dateFilter.showPicker(); } catch (err) {}
            });
        }

        function changePage(page) {
            const params = new URLSearchParams(window.location.search);
            params.set('page', page);
            window.location.href = 'bookings_netflix.php?' + params.toString();
        }

        function confirmAction(action, bookingId, clientId, studioName, schedDate, timeStart, timeEnd) {
            const modal = document.getElementById('actionConfirmModal');
            const titleEl = document.getElementById('actionConfirmTitle');
            const msgEl = document.getElementById('actionConfirmMessage');
            const yesBtn = document.getElementById('actionConfirmYes');

            let title = 'Confirm Action';
            let message = 'Are you sure?';
            let handler = null;

            switch (action) {
                case 'confirmBooking':
                    title = 'Confirm Booking';
                    message = 'Confirm this booking?';
                    handler = function() { confirmBooking(bookingId, clientId, studioName, schedDate, timeStart, timeEnd); };
                    break;
                case 'confirmPayment':
                    title = 'Confirm Payment';
                    message = 'Confirm payment for this booking?';
                    handler = function() { confirmPayment(bookingId, clientId); };
                    break;
                case 'finishBooking':
                    title = 'Finish Booking';
                    message = 'Mark this booking as finished?';
                    handler = function() { finishBooking(bookingId, clientId); };
                    break;
                case 'requestPayment':
                    title = 'Request Payment';
                    message = 'Send payment request to client?';
                    handler = function() { requestPayment(bookingId, clientId); };
                    break;
                case 'cancelBooking':
                    title = 'Cancel Booking';
                    message = 'Cancel this booking?';
                    handler = function() { cancelBooking(bookingId, clientId); };
                    break;
                case 'archiveBooking':
                    title = 'Archive Booking';
                    message = 'Archive this booking?';
                    handler = function() { archiveBooking(bookingId, clientId); };
                    break;
            }

            titleEl.textContent = title;
            msgEl.textContent = message;

            yesBtn.onclick = function() {
                closeActionConfirmModal();
                if (handler) handler();
            };

            modal.style.display = 'block';
        }

        function closeActionConfirmModal() {
            document.getElementById('actionConfirmModal').style.display = 'none';
        }

        function viewBookingDetails(index) {
            const booking = bookingsData[index];
            const modal = document.getElementById('bookingDetailsModal');
            const content = document.getElementById('bookingDetailsContent');
            const confirmBookingBtn = document.getElementById('confirmBookingBtn');
            const confirmPaymentBtn = document.getElementById('confirmPaymentBtn');
            const finishBookingBtn = document.getElementById('finishBookingBtn');
            const requestPaymentBtn = document.getElementById('requestPaymentBtn');
            const cancelBookingBtn = document.getElementById('cancelBookingBtn');
            const archiveBookingBtn = document.getElementById('archiveBookingBtn');

            // Show loading state
            content.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Loading details...</div>';

            // Fetch detailed booking data
            fetch(`?ajax=get_booking_details&booking_id=${booking.BookingID}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.error || 'Failed to load booking details');
                    }

                    const services = data.services || [];
                    const paymentProof = data.payment_proof || null;
                    
                    // Build services HTML
                    let servicesHTML = '';
                    if (services.length > 0) {
                        services.forEach((service, idx) => {
                            const equipmentHTML = service.equipment && service.equipment.length > 0
                                ? service.equipment.map(eq => `
                                    <div class="flex justify-between items-center py-1 text-xs">
                                        <span>${eq.equipment_name} <span class="text-gray-500">(x${eq.quantity})</span></span>
                                        <span class="text-gray-400">₱${parseFloat(eq.total_price || 0).toFixed(2)}</span>
                                    </div>
                                `).join('')
                                : '<p class="text-xs text-gray-500 italic">No equipment rented</p>';

                            servicesHTML += `
                                <div class="mt-3 p-3 bg-[#0a0a0a] rounded-md border border-[#222222]">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <h5 class="text-sm font-semibold text-white">${service.ServiceType}</h5>
                                            ${service.ServiceDescription ? `<p class="text-xs text-gray-400 mt-1">${service.ServiceDescription}</p>` : ''}
                                        </div>
                                        <span class="text-sm font-medium text-red-400">₱${parseFloat(service.service_price || 0).toFixed(2)}</span>
                                    </div>
                                    ${service.InstructorName ? `
                                        <div class="mt-2 pt-2 border-t border-[#222222]">
                                            <p class="text-xs text-gray-400">Instructor:</p>
                                            <p class="text-sm text-white">${service.InstructorName}</p>
                                        </div>
                                    ` : '<p class="text-xs text-gray-500 italic mt-2 pt-2 border-t border-[#222222]">No instructor assigned</p>'}
                                    ${service.equipment && service.equipment.length > 0 ? `
                                        <div class="mt-2 pt-2 border-t border-[#222222]">
                                            <p class="text-xs text-gray-400 mb-1">Equipment Rented:</p>
                                            ${equipmentHTML}
                                        </div>
                                    ` : ''}
                                </div>
                            `;
                        });
                    } else {
                        servicesHTML = '<p class="text-sm text-gray-500 italic">No services found for this booking</p>';
                    }

                    let html = `
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <h4 class="text-sm font-medium text-gray-400">Client Information</h4>
                                <div class="mt-2 p-3 bg-[#161616] rounded-md">
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="avatar">
                                            ${getInitials(booking.client_name)}
                                        </div>
                                        <span class="font-medium">${booking.client_name}</span>
                                    </div>
                                    <p class="text-sm text-gray-400">Client ID: #${booking.ClientID}</p>
                                    <p class="text-sm text-gray-400">Phone: ${booking.client_phone || 'N/A'}</p>
                                    <p class="text-sm text-gray-400">Email: ${booking.client_email || 'N/A'}</p>
                                </div>
                            </div>
                            
                            <div>
                                <h4 class="text-sm font-medium text-gray-400">Booking Information</h4>
                                <div class="mt-2 p-3 bg-[#161616] rounded-md">
                                    <p class="text-sm"><span class="text-gray-400">Booking ID:</span> #${booking.BookingID}</p>
                                    <p class="text-sm"><span class="text-gray-400">Studio:</span> ${booking.StudioName}</p>
                                    <p class="text-sm"><span class="text-gray-400">Date:</span> ${formatDate(booking.Sched_Date)}</p>
                                    <p class="text-sm"><span class="text-gray-400">Time:</span> ${formatTime(booking.Time_Start)} - ${formatTime(booking.Time_End)}</p>
                                    <p class="text-sm"><span class="text-gray-400">Booking Date:</span> ${formatDate(booking.booking_date)}</p>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-2">Services, Equipment & Instructors</h4>
                            ${servicesHTML}
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                            <div>
                                <h4 class="text-sm font-medium text-gray-400">Status</h4>
                                <div class="mt-2 p-3 bg-[#161616] rounded-md">
                                    ${getStatusBadgeHTML(booking.status)}
                                </div>
                            </div>
                            
                            <div>
                                <h4 class="text-sm font-medium text-gray-400">Payment Status</h4>
                                <div class="mt-2 p-3 bg-[#161616] rounded-md">
                                    ${getPaymentStatusBadgeHTML(booking.payment_status)}
                                    ${paymentProof && paymentProof.payment_proof_path ? `
                                        <button 
                                            onclick="event.stopPropagation(); viewPaymentProof(${booking.BookingID})"
                                            class="mt-2 w-full bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-2 text-xs font-medium flex items-center justify-center gap-2">
                                            <i class="fas fa-receipt"></i>
                                            <span>View Payment Proof</span>
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                            
                            <div>
                                <h4 class="text-sm font-medium text-gray-400">Total Amount</h4>
                                <div class="mt-2 p-3 bg-[#161616] rounded-md">
                                    <p class="text-lg font-bold">₱${parseFloat(booking.amount).toFixed(2)}</p>
                                </div>
                            </div>
                        </div>
                    `;

                    content.innerHTML = html;

                    const isPastDate = new Date(booking.Sched_Date) < new Date().setHours(0, 0, 0, 0);

                    confirmBookingBtn.classList.add('hidden');
                    confirmPaymentBtn.classList.add('hidden');
                    finishBookingBtn.classList.add('hidden');
                    requestPaymentBtn.classList.add('hidden');
                    cancelBookingBtn.classList.add('hidden');
                    archiveBookingBtn.classList.add('hidden');

                    if (canConfirmBooking(booking.status) && !isPastDate) {
                        confirmBookingBtn.classList.remove('hidden');
                        confirmBookingBtn.onclick = function() {
                            confirmBooking(booking.BookingID, booking.ClientID, booking.StudioName, booking.Sched_Date, booking.Time_Start, booking.Time_End);
                        };
                    }

                    if (canConfirmPayment(booking.status, booking.payment_status) && !isPastDate) {
                        confirmPaymentBtn.classList.remove('hidden');
                        confirmPaymentBtn.onclick = function() {
                            confirmPayment(booking.BookingID, booking.ClientID);
                        };
                    }

                    if (canFinishBooking(booking.status, booking.payment_status) && !isPastDate) {
                        finishBookingBtn.classList.remove('hidden');
                        finishBookingBtn.onclick = function() {
                            finishBooking(booking.BookingID, booking.ClientID);
                        };
                    }

                    if (canRequestPayment(booking.status, booking.payment_status) && !isPastDate) {
                        requestPaymentBtn.classList.remove('hidden');
                        requestPaymentBtn.onclick = function() {
                            requestPayment(booking.BookingID, booking.ClientID);
                        };
                    }

                    if (canCancelBooking(booking.status) && !isPastDate) {
                        cancelBookingBtn.classList.remove('hidden');
                        cancelBookingBtn.onclick = function() {
                            cancelBooking(booking.BookingID, booking.ClientID);
                        };
                    }

                    if (canArchiveBooking(booking.status)) {
                        archiveBookingBtn.classList.remove('hidden');
                        archiveBookingBtn.onclick = function() {
                            archiveBooking(booking.BookingID, booking.ClientID);
                        };
                    }

                    modal.style.display = 'block';
                })
                .catch(error => {
                    console.error('Error loading booking details:', error);
                    content.innerHTML = '<div class="text-center py-4 text-red-500"><i class="fas fa-exclamation-circle"></i> Failed to load booking details</div>';
                });
        }

        function closeBookingModal() {
            document.getElementById('bookingDetailsModal').style.display = 'none';
        }

        function confirmBooking(bookingId, clientId, studioName, bookDate, timeStart, timeEnd) {
            const formattedDate = formatDate(bookDate);
            const formattedTimeStart = formatTime(timeStart);
            const formattedTimeEnd = formatTime(timeEnd);
            const message = `Your booking for ${studioName} on ${formattedDate} from ${formattedTimeStart} to ${formattedTimeEnd} has been confirmed`;

            fetch('confirm-booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        clientId: clientId,
                        ownerId: <?php echo json_encode($ownerId); ?>,
                        message: message
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('bookingConfirmationModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to confirm booking. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error confirming booking:', error);
                    alert('Failed to confirm booking. Please try again.');
                });
        }

        function confirmPayment(bookingId, clientId) {
            fetch('confirm-payment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        clientId: clientId,
                        ownerId: <?php echo json_encode($ownerId); ?>,
                        message: 'Payment for your booking #' + bookingId + ' has been confirmed'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('paymentConfirmationModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to confirm payment. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error confirming payment:', error);
                    alert('Failed to confirm payment. Please try again.');
                });
        }

        function finishBooking(bookingId, clientId) {
            fetch('finish-booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        clientId: clientId,
                        ownerId: <?php echo json_encode($ownerId); ?>,
                        message: 'Your booking #' + bookingId + ' has been marked as finished'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('bookingFinishedModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        const target = data.redirect || 'bookings_netflix.php?status=completed';
                        setTimeout(() => window.location.href = target, 1500);
                    } else {
                        alert(data.error || 'Failed to finish booking. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error finishing booking:', error);
                    alert('Failed to finish booking. Please try again.');
                });
        }

        function requestPayment(bookingId, clientId) {
            fetch('create-notification.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        clientId: clientId,
                        bookingId: bookingId,
                        type: 'payment_request',
                        message: 'Please complete your payment for booking #' + bookingId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('paymentRequestModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to send payment request. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error creating notification:', error);
                    document.getElementById('paymentRequestModal').style.display = 'block';
                    document.getElementById('bookingDetailsModal').style.display = 'none';
                });
        }

        function cancelBooking(bookingId, clientId) {
            fetch('cancel-booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        clientId: clientId,
                        message: 'Your booking #' + bookingId + ' has been cancelled'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('bookingCancelledModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to cancel booking. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error cancelling booking:', error);
                    alert('Failed to cancel booking. Please try again.');
                });
        }

        function archiveBooking(bookingId, clientId) {
            fetch('archive-booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        ownerId: <?php echo json_encode($ownerId); ?>
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('bookingArchivedModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to archive booking. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error archiving booking:', error);
                    alert('Failed to archive booking. Please try again.');
                });
        }

        function closePaymentModal() {
            document.getElementById('paymentRequestModal').style.display = 'none';
        }

        function closeConfirmationModal() {
            document.getElementById('bookingConfirmationModal').style.display = 'none';
        }

        function closePaymentConfirmationModal() {
            document.getElementById('paymentConfirmationModal').style.display = 'none';
        }

        function closeBookingFinishedModal() {
            document.getElementById('bookingFinishedModal').style.display = 'none';
        }

        function closeBookingCancelledModal() {
            document.getElementById('bookingCancelledModal').style.display = 'none';
        }

        function closeBookingArchivedModal() {
            document.getElementById('bookingArchivedModal').style.display = 'none';
        }

        // Payment Proof Modal
        function viewPaymentProof(bookingId) {
            const modal = document.getElementById('paymentProofModal');
            const content = document.getElementById('paymentProofContent');
            
            // Show loading state
            content.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Loading payment proof...</div>';
            modal.style.display = 'block';
            
            // Fetch payment proof details
            fetch(`?ajax=get_booking_details&booking_id=${bookingId}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.success || !data.payment_proof) {
                        throw new Error('Payment proof not found');
                    }
                    
                    const proof = data.payment_proof;
                    const imagePath = proof.payment_proof_path ? `../../uploads/payment_proofs/${proof.payment_proof_path}` : null;
                    
                    let html = `
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <h4 class="text-sm font-medium text-gray-400 mb-2">Payment Information</h4>
                                <div class="p-4 bg-[#161616] rounded-md space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-sm text-gray-400">Reference Number:</span>
                                        <span class="text-sm font-medium">${proof.Ref_Num || 'N/A'}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-sm text-gray-400">Sender GCash Number:</span>
                                        <span class="text-sm font-medium">${proof.gcash_sender_number || 'N/A'}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-sm text-gray-400">Submitted:</span>
                                        <span class="text-sm font-medium">${proof.payment_submitted_at ? formatDate(proof.payment_submitted_at) : 'N/A'}</span>
                                    </div>
                                    ${proof.payment_notes ? `
                                        <div class="mt-3 pt-3 border-t border-[#222222]">
                                            <span class="text-sm text-gray-400 block mb-1">Notes:</span>
                                            <p class="text-sm">${proof.payment_notes}</p>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-gray-400 mb-2">Payment Screenshot</h4>
                                <div class="p-4 bg-[#161616] rounded-md">
                                    ${imagePath ? `
                                        <img src="${imagePath}" alt="Payment Proof" 
                                             class="w-full rounded-md cursor-pointer hover:opacity-90 transition-opacity"
                                             onclick="window.open('${imagePath}', '_blank')"
                                             onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'200\\' height=\\'200\\'%3E%3Crect fill=\\'%23222\\' width=\\'200\\' height=\\'200\\'/%3E%3Ctext fill=\\'%23888\\' font-family=\\'Arial\\' font-size=\\'14\\' x=\\'50%25\\' y=\\'50%25\\' text-anchor=\\'middle\\' dy=\\'.3em\\'%3EImage not found%3C/text%3E%3C/svg%3E';">
                                        <p class="text-xs text-gray-500 mt-2 text-center">Click image to view full size</p>
                                    ` : '<p class="text-sm text-gray-500 italic text-center py-8">No screenshot uploaded</p>'}
                                </div>
                            </div>
                        </div>
                    `;
                    
                    content.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error loading payment proof:', error);
                    content.innerHTML = '<div class="text-center py-4 text-red-500"><i class="fas fa-exclamation-circle"></i> Failed to load payment proof</div>';
                });
        }
        
        function closePaymentProofModal() {
            document.getElementById('paymentProofModal').style.display = 'none';
        }

        // New Booking Modal logic
        const servicesByStudio = <?php echo json_encode($servicesByStudio); ?>;
        const instructorsByStudioService = <?php echo json_encode($instructorsByStudioService); ?>;
        const studioHours = <?php echo json_encode($studioHours); ?>;
        const openNewBtn = document.getElementById('openNewBookingModal');
        const newBookingModal = document.getElementById('newBookingModal');
        const newBookingForm = document.getElementById('newBookingForm');
        const nbStudio = document.getElementById('nbStudio');
        const nbService = document.getElementById('nbService');
        const nbInstructor = document.getElementById('nbInstructor');
        const nbDate = document.getElementById('nbDate');
        const nbTimeStart = document.getElementById('nbTimeStart');
        const nbTimeEnd = document.getElementById('nbTimeEnd');
        const calPrev = document.getElementById('calPrev');
        const calNext = document.getElementById('calNext');
        const calMonthLabel = document.getElementById('calMonthLabel');
        const calDays = document.getElementById('calDays');
        const timeSlots = document.getElementById('timeSlots');
        const clearSlotSelection = document.getElementById('clearSlotSelection');
        const existingClientBlock = document.getElementById('existingClientBlock');
        const newClientBlock = document.getElementById('newClientBlock');
        const nbClientSelect = document.getElementById('nbClient');
        const nbClientName = document.getElementById('nbClientName');
        const nbClientEmail = document.getElementById('nbClientEmail');
        const nbClientPhone = document.getElementById('nbClientPhone');

        if (openNewBtn) {
            openNewBtn.addEventListener('click', (e) => {
                e.preventDefault();
                newBookingModal.style.display = 'block';
            });
        }

        function closeNewBookingModal() {
            newBookingModal.style.display = 'none';
        }

        // Toggle client mode
        const clientModeRadios = document.querySelectorAll('input[name="clientMode"]');
        clientModeRadios.forEach(r => {
            r.addEventListener('change', () => {
                const mode = document.querySelector('input[name="clientMode"]:checked').value;
                if (mode === 'existing') {
                    existingClientBlock.style.display = '';
                    newClientBlock.style.display = 'none';
                } else {
                    existingClientBlock.style.display = 'none';
                    newClientBlock.style.display = '';
                }
            });
        });

        // Populate services when studio changes
        function populateServicesForStudio(studioId) {
            nbService.innerHTML = '<option value="">Select service</option>';
            const list = servicesByStudio[String(studioId)] || servicesByStudio[parseInt(studioId, 10)] || [];
            for (const svc of list) {
                const opt = document.createElement('option');
                opt.value = String(svc.ServiceID);
                opt.textContent = svc.ServiceType;
                nbService.appendChild(opt);
            }
        }
        nbStudio && nbStudio.addEventListener('change', (e) => { populateServicesForStudio(e.target.value); nbInstructor.innerHTML = '<option value="">None</option>'; renderSlots(); });

        function populateInstructorsForSelection(studioId, serviceId) {
            nbInstructor.innerHTML = '<option value="">None</option>';
            const studioMap = instructorsByStudioService[String(studioId)] || instructorsByStudioService[parseInt(studioId, 10)] || {};
            const list = studioMap[String(serviceId)] || studioMap[parseInt(serviceId, 10)] || [];
            for (const ins of list) {
                const opt = document.createElement('option');
                opt.value = String(ins.InstructorID);
                opt.textContent = ins.InstructorName;
                nbInstructor.appendChild(opt);
            }
        }
        nbService && nbService.addEventListener('change', () => { const st = nbStudio.value; const sv = nbService.value; if (st && sv) { populateInstructorsForSelection(st, sv); } else { nbInstructor.innerHTML = '<option value="">None</option>'; } renderSlots(); });

        let calYear, calMonth;
        function formatLocalYMD(d){ const y=d.getFullYear(); const m=String(d.getMonth()+1).padStart(2,'0'); const day=String(d.getDate()).padStart(2,'0'); return y+'-'+m+'-'+day; }
        async function checkSlotAvailability(studioId, date, start, end){ const params=new URLSearchParams(); params.append('studio_id', studioId); params.append('date', date); params.append('timeStart', start); params.append('timeEnd', end); try{ const res=await fetch('../../booking/php/check_availability.php',{ method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body:params.toString() }); const data=await res.json(); return !!(data && data.success); }catch(e){ return false; } }
        function renderCalendar() {
            const first = new Date(calYear, calMonth, 1);
            const last = new Date(calYear, calMonth + 1, 0);
            const monthName = first.toLocaleString('en-US', { month: 'long' });
            calMonthLabel.textContent = monthName + ' ' + calYear;
            calDays.innerHTML = '';
            const startDay = first.getDay();
            for (let i = 0; i < startDay; i++) { const cell = document.createElement('div'); cell.className = 'h-8'; calDays.appendChild(cell); }
            for (let d = 1; d <= last.getDate(); d++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = String(d);
                btn.className = 'h-8 text-sm rounded hover:bg-[#222222]';
                const sel = new Date(calYear, calMonth, d);
                const today = new Date(); today.setHours(0,0,0,0);
                const isPast = sel < today;
                if (isPast) {
                    btn.className = 'h-8 text-sm rounded opacity-40 cursor-not-allowed';
                } else {
                    btn.onclick = function() { const v=formatLocalYMD(sel); nbDate.value=v; renderSlots(); };
                }
                calDays.appendChild(btn);
            }
        }
        async function renderSlots() {
            timeSlots.innerHTML = '';
            const st = nbStudio.value;
            const dateVal = nbDate.value;
            if (!st || !dateVal) return;
            const hours = studioHours[String(st)] || studioHours[parseInt(st,10)] || null;
            if (!hours) return;
            const start = hours.Time_IN;
            const end = hours.Time_OUT;
            if (!start || !end) return;
            const startH = parseInt(start.split(':')[0],10);
            const endH = parseInt(end.split(':')[0],10);
            let selectionStart = null;
            let selectionEnd = null;
            function setSelection(s,e){ nbTimeStart.value = s || ''; nbTimeEnd.value = e || ''; }
            for (let h=startH; h<endH; h++){
                const hh = String(h).padStart(2,'0');
                const label = new Date(dateVal+'T'+hh+':00:00');
                const ampm = label.getHours()>=12?'PM':'AM';
                const hour12 = (label.getHours()%12)||12;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'px-2 py-2 rounded bg-[#161616] hover:bg-[#222222] text-xs';
                btn.textContent = hour12+':00 '+ampm;
                btn.dataset.time = hh+':00';
                const startTime = hh+':00';
                const endTime = String(h+1).padStart(2,'0')+':00';
                const available = await checkSlotAvailability(parseInt(st,10), dateVal, startTime, endTime);
                if (!available){ btn.className = 'px-2 py-2 rounded bg-[#161616] text-xs opacity-40 cursor-not-allowed'; btn.disabled = true; btn.onclick = null; }
                else {
                    btn.onclick = async function(){
                        const t = btn.dataset.time;
                        if (!selectionStart){ selectionStart = t; selectionEnd = null; setSelection(selectionStart, ''); highlight(); return; }
                        if (!selectionEnd){ if (t>selectionStart){ const ok = await checkSlotAvailability(parseInt(st,10), dateVal, selectionStart, t); if (ok){ selectionEnd = t; setSelection(selectionStart, selectionEnd); highlight(); } else { selectionEnd = null; setSelection(selectionStart, ''); alert('Selected time range is not available'); } } else { selectionStart = t; selectionEnd = null; setSelection(selectionStart, ''); highlight(); } return; }
                        selectionStart = t; selectionEnd = null; setSelection(selectionStart, ''); highlight();
                    };
                }
                timeSlots.appendChild(btn);
            }
            function highlight(){
                const nodes = timeSlots.querySelectorAll('button');
                nodes.forEach(n=>{ n.classList.remove('bg-red-600'); n.classList.add('bg-[#161616]'); });
                if (selectionStart){
                    nodes.forEach(n=>{
                        const t = n.dataset.time;
                        if (selectionEnd){ if (t>=selectionStart && t<selectionEnd){ n.classList.remove('bg-[#161616]'); n.classList.add('bg-red-600'); } }
                        else { if (t===selectionStart){ n.classList.remove('bg-[#161616]'); n.classList.add('bg-red-600'); }
                        }
                    });
                }
            }
            clearSlotSelection.onclick = function(){ selectionStart=null; selectionEnd=null; setSelection('',''); highlight(); };
        }
        (function(){ const now=new Date(); calYear=now.getFullYear(); calMonth=now.getMonth(); renderCalendar(); })();
        calPrev && calPrev.addEventListener('click', function(){ if (calMonth===0){ calMonth=11; calYear--; } else { calMonth--; } renderCalendar(); });
        calNext && calNext.addEventListener('click', function(){ if (calMonth===11){ calMonth=0; calYear++; } else { calMonth++; } renderCalendar(); });
        nbDate && nbDate.addEventListener('change', renderSlots);

        // Submit new booking
        newBookingForm && newBookingForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const studioId = nbStudio.value;
            const serviceId = nbService.value;
            const instructorId = nbInstructor.value;
            const slotDate = nbDate.value;
            const timeStart = nbTimeStart.value;
            const timeEnd = nbTimeEnd.value;
            const mode = document.querySelector('input[name="clientMode"]:checked').value;
            const payload = {
                studio_id: studioId ? parseInt(studioId, 10) : 0,
                service_id: serviceId ? parseInt(serviceId, 10) : 0,
                instructor_id: instructorId ? parseInt(instructorId, 10) : null,
                slot_date: slotDate,
                time_start: timeStart,
                time_end: timeEnd
            };
            if (mode === 'existing') {
                const cid = nbClientSelect.value;
                payload.client_id = cid ? parseInt(cid, 10) : 0;
            } else {
                payload.client_name = nbClientName.value.trim();
                payload.client_email = nbClientEmail.value.trim();
                payload.client_phone = nbClientPhone.value.trim();
            }

            fetch('create_booking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    closeNewBookingModal();
                    // Reload to reflect new booking
                    setTimeout(() => location.reload(), 500);
                } else {
                    alert((data && data.message) ? data.message : 'Failed to create booking');
                }
            })
            .catch(err => {
                console.error('Create booking error:', err);
                alert('Failed to create booking');
            });
        });

        function getInitials(name) {
            const words = name.split(' ');
            let initials = '';
            for (const word of words) {
                if (word.length > 0) {
                    initials += word[0].toUpperCase();
                }
            }
            return initials.substring(0, 2);
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }

        function formatTime(timeString) {
            const [hours, minutes] = timeString.split(':');
            const hour = parseInt(hours, 10);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour % 12 || 12;
            return `${hour12}:${minutes} ${ampm}`;
        }

        function getStatusBadgeHTML(status) {
            const statusLower = status.toLowerCase();
            if (statusLower === 'confirmed') {
                return '<span class="badge bg-red-600">confirmed</span>';
            } else if (statusLower === 'pending') {
                return '<span class="badge bg-transparent border border-gray-500 text-gray-300">pending</span>';
            } else if (statusLower === 'cancelled') {
                return '<span class="badge bg-gray-700">cancelled</span>';
            } else if (statusLower === 'archived') {
                return '<span class="badge bg-blue-700">archived</span>';
            } else if (statusLower === 'finished') {
                return '<span class="badge bg-purple-600">finished</span>';
            } else {
                return `<span class="badge bg-gray-600">${status}</span>`;
            }
        }

        function getPaymentStatusBadgeHTML(status) {
            const statusLower = status.toLowerCase();
            if (statusLower === 'completed') {
                return '<span class="badge bg-green-600">completed</span>';
            } else if (statusLower === 'pending') {
                return '<span class="badge bg-yellow-600">pending</span>';
            } else if (statusLower === 'failed') {
                return '<span class="badge bg-red-600">failed</span>';
            } else if (statusLower === 'cancelled') {
                return '<span class="badge bg-gray-700">cancelled</span>';
            } else if (statusLower === 'n/a') {
                return '<span class="badge bg-gray-600">N/A</span>';
            } else {
                return `<span class="badge bg-gray-600">${status}</span>`;
            }
        }

        function canConfirmBooking(status) {
            return status.toLowerCase() === 'pending';
        }

        function canConfirmPayment(status, paymentStatus) {
            return (status.toLowerCase() === 'confirmed' && paymentStatus.toLowerCase() !== 'completed');
        }

        function canFinishBooking(status, paymentStatus) {
            return (status.toLowerCase() === 'confirmed' && paymentStatus.toLowerCase() === 'completed');
        }

        function canRequestPayment(status, paymentStatus) {
            return (status.toLowerCase() === 'pending' && (paymentStatus.toLowerCase() === 'pending' || paymentStatus.toLowerCase() === 'n/a'));
        }

        function canCancelBooking(status) {
            return (status.toLowerCase() === 'pending' || status.toLowerCase() === 'confirmed');
        }

        function canArchiveBooking(status) {
            return status.toLowerCase() !== 'archived';
        }

        // Highlight a booking row if booking_id is present in the URL
        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            const targetId = params.get('booking_id');
            if (targetId) {
                const row = document.querySelector('tbody tr[data-booking-id="' + targetId + '"]');
                if (row) {
                    row.classList.add('row-highlight');
                    // Ensure the row is visible to the user
                    try { row.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
                }
            }
        });

        window.onclick = function(event) {
            const bookingModal = document.getElementById('bookingDetailsModal');
            const paymentModal = document.getElementById('paymentRequestModal');
            const confirmationModal = document.getElementById('bookingConfirmationModal');
            const paymentConfirmationModal = document.getElementById('paymentConfirmationModal');
            const bookingFinishedModal = document.getElementById('bookingFinishedModal');
            const bookingCancelledModal = document.getElementById('bookingCancelledModal');
            const bookingArchivedModal = document.getElementById('bookingArchivedModal');
            const newBookingModalRef = document.getElementById('newBookingModal');
            const paymentProofModal = document.getElementById('paymentProofModal');

            if (event.target === bookingModal) {
                bookingModal.style.display = 'none';
            }
            if (event.target === paymentModal) {
                paymentModal.style.display = 'none';
            }
            if (event.target === confirmationModal) {
                confirmationModal.style.display = 'none';
            }
            if (event.target === paymentConfirmationModal) {
                paymentConfirmationModal.style.display = 'none';
            }
            if (event.target === bookingFinishedModal) {
                bookingFinishedModal.style.display = 'none';
            }
            if (event.target === bookingCancelledModal) {
                bookingCancelledModal.style.display = 'none';
            }
            if (event.target === bookingArchivedModal) {
                bookingArchivedModal.style.display = 'none';
            }
            if (event.target === newBookingModalRef) {
                newBookingModalRef.style.display = 'none';
            }
            if (event.target === paymentProofModal) {
                paymentProofModal.style.display = 'none';
            }
        };
    </script>
</body>

</html>