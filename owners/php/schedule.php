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

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Get current date
$today = new DateTime('now', new DateTimeZone('America/Los_Angeles'));

// Force monthly view only
$viewType = 'monthly';

// Determine the default date for display
if (isset($_GET['month']) && isset($_GET['year'])) {
    $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
    $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
    if ($month && $year && $month >= 1 && $month <= 12) {
        // Ensure month is zero-padded for DateTime constructor
        $monthPadded = str_pad($month, 2, '0', STR_PAD_LEFT);
        $defaultDate = new DateTime("$year-$monthPadded-01", new DateTimeZone('America/Los_Angeles'));
    } else {
        $defaultDate = clone $today;
    }
} else {
    $defaultDate = clone $today;
}

// Get filter for studio
$studioFilter = isset($_GET['studio']) ? (int)$_GET['studio'] : 0;

// Fetch studios for this owner
$studios = $pdo->prepare("
    SELECT StudioID, StudioName, Time_IN, Time_OUT
    FROM studios
    WHERE OwnerID = ?
");
$studios->execute([$ownerId]);
$studios = $studios->fetchAll(PDO::FETCH_ASSOC);

// Fetch availability statuses
$availStats = $pdo->prepare("SELECT Avail_StatsID, Avail_Name FROM avail_stats ORDER BY Avail_StatsID");
$availStats->execute();
$availStats = $availStats->fetchAll(PDO::FETCH_ASSOC);

// Build the query based on filters
$scheduleQuery = "
    SELECT sch.ScheduleID, sch.Sched_Date, sch.Time_Start, sch.Time_End, 
           s.StudioName, s.StudioID, s.Time_IN, s.Time_OUT, a.Avail_Name as availability, a.Avail_StatsID,
           b.BookingID, c.Name as client_name, c.ClientID, c.Email as client_email, c.Phone as client_phone,
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
    WHERE s.OwnerID = :ownerId
";

$params = [':ownerId' => $ownerId];

// Add date range filter for monthly view
$monthStart = clone $defaultDate;
$monthStart->modify('first day of this month');
$monthEnd = clone $defaultDate;
$monthEnd->modify('last day of this month');

$scheduleQuery .= " AND sch.Sched_Date BETWEEN :startDate AND :endDate";
$params[':startDate'] = $monthStart->format('Y-m-d');
$params[':endDate'] = $monthEnd->format('Y-m-d');

// Add studio filter if selected
if ($studioFilter > 0) {
    $scheduleQuery .= " AND s.StudioID = :studioId";
    $params[':studioId'] = $studioFilter;
}

$scheduleQuery .= " GROUP BY sch.ScheduleID ORDER BY sch.Sched_Date, sch.Time_Start";

$schedules = $pdo->prepare($scheduleQuery);
foreach ($params as $key => $value) {
    $schedules->bindValue($key, $value);
}
$schedules->execute();
$schedules = $schedules->fetchAll(PDO::FETCH_ASSOC);

// Organize schedules by day and time
$scheduledDays = [];

// Initialize for monthly view
$monthStart = clone $defaultDate;
$monthStart->modify('first day of this month');
$daysInMonth = (int)$monthStart->format('t');

for ($i = 0; $i < $daysInMonth; $i++) {
    $day = clone $monthStart;
    $day->modify("+$i days");
    $scheduledDays[$day->format('Y-m-d')] = [];
}

foreach ($schedules as $schedule) {
    $date = $schedule['Sched_Date'];
    if (!isset($scheduledDays[$date])) {
        $scheduledDays[$date] = [];
    }
    $scheduledDays[$date][] = $schedule;
}

// Check for unread notifications
$notificationsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE OwnerID = ? 
    AND IsRead = 0
");
$notificationsStmt->execute([$ownerId]);
$unreadNotifications = $notificationsStmt->fetchColumn();

// Fetch owner data
$owner = $pdo->prepare("
    SELECT Name, Email 
    FROM studio_owners 
    WHERE OwnerID = ?
");
$owner->execute([$ownerId]);
$owner = $owner->fetch(PDO::FETCH_ASSOC);

// Helper function to get availability class
function getAvailabilityClass($availability) {
    switch (strtolower($availability)) {
        case 'available':
            return 'bg-green-900/20 border-green-500 text-green-400';
        case 'booked':
            return 'bg-red-900/20 border-red-500 text-red-400';
        case 'maintenance':
            return 'bg-yellow-900/20 border-yellow-500 text-yellow-400';
        case 'unavailable':
            return 'bg-gray-900/20 border-gray-500 text-gray-400';
        default:
            return 'bg-gray-900/20 border-gray-500 text-gray-400';
    }
}

// Helper function to get customer initials
function getInitials($name) {
    if (!$name) return '';
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Helper function to check if a date is in the past
function isDatePast($date) {
    $dateObj = new DateTime($date, new DateTimeZone('America/Los_Angeles'));
    $today = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
    return $dateObj < $today->setTime(0, 0, 0);
}

// Helper function to check if entire day is closed (all slots are unavailable/maintenance)
function isDayClosed($slots) {
    if (empty($slots)) return false;
    
    foreach ($slots as $slot) {
        $avail = strtolower($slot['availability']);
        // If any slot is available or booked, day is not fully closed
        if ($avail === 'available' || $avail === 'booked') {
            return false;
        }
    }
    return true; // All slots are unavailable/maintenance/closed
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>MuSeek - Schedule</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
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
            box-sizing: border-box;
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
        .schedule-grid {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            gap: 0.75rem;
        }
        .schedule-day {
            background-color: #0a0a0a;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            padding: 0.75rem;
        }
        .schedule-item {
            border-width: 1px;
            border-radius: 0.375rem;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.75rem;
        }
        .time-slot {
            font-size: 0.7rem;
            color: #9ca3af;
            margin-bottom: 0.25rem;
        }
        .month-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.25rem;
        }
        .month-day {
            background-color: #0a0a0a;
            border: 1px solid #222222;
            border-radius: 0.25rem;
            padding: 0.5rem;
            min-height: 100px;
        }
        .month-day.day-closed {
            background-color: #1a1a1a;
            border-color: #333333;
        }
        .month-day-header {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
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
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
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
        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.7);
        }
        .modal-content {
            background-color: #0a0a0a;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            width: 80%;
            max-width: 500px;
        }
        .past-date {
            opacity: 0.6;
        }
        @media (min-width: 768px) {
            .schedule-grid {
                grid-template-columns: repeat(1, 1fr);
            }
        }
        @media (min-width: 1024px) {
            .schedule-grid {
                grid-template-columns: repeat(1, 1fr);
            }
        }
    </style>
</head>
<body class="bg-[#161616] text-white">
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <!-- Main Content -->
    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-1">SCHEDULES</h1>
        </header>
        <div class="p-6">
            <!-- Flash Message -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="bg-gray-800 border border-gray-600 text-white p-4 rounded-md mb-4">
                    <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
                </div>
                <?php unset($_SESSION['flash_message']); ?>
            <?php endif; ?>

            <!-- Date Navigation and Filters -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                <div class="flex items-center gap-4">
                    <h2 class="text-lg font-medium">
                        <?php echo $defaultDate->format('F Y'); ?>
                    </h2>
                    <div class="flex gap-2">
                        <?php 
                            $prevMonth = clone $defaultDate;
                            $prevMonth->modify('-1 month');
                            $nextMonth = clone $defaultDate;
                            $nextMonth->modify('+1 month');
                        ?>
                        <a href="?month=<?php echo $prevMonth->format('n'); ?>&year=<?php echo $prevMonth->format('Y'); ?>&studio=<?php echo $studioFilter; ?>" class="bg-[#0a0a0a] border border-[#222222] rounded-md p-2">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <a href="?month=<?php echo $nextMonth->format('n'); ?>&year=<?php echo $nextMonth->format('Y'); ?>&studio=<?php echo $studioFilter; ?>" class="bg-[#0a0a0a] border border-[#222222] rounded-md p-2">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <a href="?studio=<?php echo $studioFilter; ?>" class="bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                        Current Month
                    </a>
                    <select id="studio-filter" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-40">
                        <option value="0" <?php echo $studioFilter == 0 ? 'selected' : ''; ?>>All Studios</option>
                        <?php foreach ($studios as $studio): ?>
                            <option value="<?php echo $studio['StudioID']; ?>" <?php echo $studioFilter == $studio['StudioID'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($studio['StudioName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button id="addTimeSlotBtn" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2">
                        <i class="fas fa-plus"></i>
                        <span>Add Time Slot</span>
                    </button>
                    <button id="blockDayBtn" class="bg-gray-700 hover:bg-gray-800 text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2">
                        <i class="fas fa-ban"></i>
                        <span>Block Day</span>
                    </button>
                </div>
            </div>
            
            <!-- Monthly Schedule View -->
                <div class="mb-4">
                    <div class="month-grid mb-2">
                        <div class="text-center text-xs font-medium text-gray-400 py-1">Sun</div>
                        <div class="text-center text-xs font-medium text-gray-400 py-1">Mon</div>
                        <div class="text-center text-xs font-medium text-gray-400 py-1">Tue</div>
                        <div class="text-center text-xs font-medium text-gray-400 py-1">Wed</div>
                        <div class="text-center text-xs font-medium text-gray-400 py-1">Thu</div>
                        <div class="text-center text-xs font-medium text-gray-400 py-1">Fri</div>
                        <div class="text-center text-xs font-medium text-gray-400 py-1">Sat</div>
                    </div>
                    <div class="month-grid">
                        <?php
                        $monthStart = clone $defaultDate;
                        $monthStart->modify('first day of this month');
                        $monthEnd = clone $defaultDate;
                        $monthEnd->modify('last day of this month');
                        $firstDayOfWeek = (int)$monthStart->format('w');
                        for ($i = 0; $i < $firstDayOfWeek; $i++) {
                            echo '<div class="month-day opacity-50"></div>';
                        }
                        $daysInMonth = (int)$monthStart->format('t');
                        for ($i = 1; $i <= $daysInMonth; $i++) {
                            $currentDate = clone $monthStart;
                            $currentDate->setDate($monthStart->format('Y'), $monthStart->format('m'), $i);
                            $dateStr = $currentDate->format('Y-m-d');
                            $isToday = $dateStr === $today->format('Y-m-d');
                            $isPast = isDatePast($dateStr);
                            $isClosed = !empty($scheduledDays[$dateStr]) && isDayClosed($scheduledDays[$dateStr]);
                            $dayClasses = [];
                            if ($isPast && !$isToday) $dayClasses[] = 'past-date';
                            if ($isClosed) $dayClasses[] = 'day-closed';
                            echo '<div class="month-day ' . implode(' ', $dayClasses) . '">';
                            echo '<div class="month-day-header">';
                            echo '<span class="' . ($isToday ? 'text-red-500 font-bold' : '') . '">' . $i . '</span>';
                            if (!$isPast || $isToday) {
                                echo '<div class="flex gap-1">';
                                echo '<button class="text-gray-400 hover:text-white text-xs" onclick="addTimeSlotForDate(\'' . $dateStr . '\')"><i class="fas fa-plus"></i></button>';
                                echo '<button class="text-gray-400 hover:text-white text-xs" onclick="blockDay(\'' . $dateStr . '\')"><i class="fas fa-ban"></i></button>';
                                echo '</div>';
                            }
                            echo '</div>';
                            if (!empty($scheduledDays[$dateStr])) {
                                // Check if entire day is closed
                                if (isDayClosed($scheduledDays[$dateStr])) {
                                    echo '<div class="flex items-center justify-center h-16">';
                                    echo '<span class="text-lg font-bold text-gray-500">CLOSED</span>';
                                    echo '</div>';
                                } else {
                                    echo '<div class="space-y-1">';
                                    foreach ($scheduledDays[$dateStr] as $slot) {
                                        // Skip available slots from display
                                        if (strtolower($slot['availability']) === 'available') {
                                            continue;
                                        }
                                        echo '<div class="text-xs p-1 rounded ' . getAvailabilityClass($slot['availability']) . '" onclick="viewScheduleDetails(' . $slot['ScheduleID'] . ')">';
                                        echo date('g:i A', strtotime($slot['Time_Start'])) . ' - ' . date('g:i A', strtotime($slot['Time_End']));
                                        echo '</div>';
                                    }
                                    echo '</div>';
                                }
                            }
                            echo '</div>';
                        }
                        $lastDayOfWeek = (int)$monthEnd->format('w');
                        $remainingCells = 6 - $lastDayOfWeek;
                        for ($i = 0; $i < $remainingCells; $i++) {
                            echo '<div class="month-day opacity-50"></div>';
                        }
                        ?>
                    </div>
                </div>
            <!-- Legend -->
            <div class="mt-6 flex flex-wrap items-center gap-6">
                <div class="text-sm font-medium">Legend:</div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full bg-red-500"></div>
                    <span class="text-xs">Booked</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                    <span class="text-xs">Maintenance</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full bg-gray-500"></div>
                    <span class="text-xs">Unavailable / Closed</span>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Time Slot Modal -->
    <div id="addTimeSlotModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Add Time Slot</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeModal('addTimeSlotModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="addTimeSlotForm" action="add_time_slot.php" method="post">
                <div class="space-y-4">
                    <div>
                        <label for="slot_date" class="block text-sm font-medium text-gray-400 mb-1">Date</label>
                        <input type="date" id="slot_date" name="slot_date" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="studio_id" class="block text-sm font-medium text-gray-400 mb-1">Studio</label>
                        <select id="studio_id" name="studio_id" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm" onchange="updateTimeRange()">
                            <?php foreach ($studios as $studio): ?>
                                <option value="<?php echo $studio['StudioID']; ?>" data-time-in="<?php echo $studio['Time_IN']; ?>" data-time-out="<?php echo $studio['Time_OUT']; ?>">
                                    <?php echo htmlspecialchars($studio['StudioName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="time_start" class="block text-sm font-medium text-gray-400 mb-1">Start Time</label>
                            <input type="time" id="time_start" name="time_start" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label for="time_end" class="block text-sm font-medium text-gray-400 mb-1">End Time</label>
                            <input type="time" id="time_end" name="time_end" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                        </div>
                    </div>
                    <div>
                        <label for="availability" class="block text-sm font-medium text-gray-400 mb-1">Availability</label>
                        <select id="availability" name="availability" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <?php foreach ($availStats as $stat): ?>
                                <?php if ($stat['Avail_StatsID'] != 2): // Exclude "Booked" status ?>
                                    <option value="<?php echo $stat['Avail_StatsID']; ?>">
                                        <?php echo htmlspecialchars($stat['Avail_Name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal('addTimeSlotModal')">
                            Cancel
                        </button>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                            Add Time Slot
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Block Day Modal -->
    <div id="blockDayModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Block Day</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeModal('blockDayModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="blockDayForm" action="block_day.php" method="post">
                <div class="space-y-4">
                    <div>
                        <label for="block_date" class="block text-sm font-medium text-gray-400 mb-1">Date</label>
                        <input type="date" id="block_date" name="block_date" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="block_studio_id" class="block text-sm font-medium text-gray-400 mb-1">Studio</label>
                        <select id="block_studio_id" name="block_studio_id" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <option value="all">All Studios</option>
                            <?php foreach ($studios as $studio): ?>
                                <option value="<?php echo $studio['StudioID']; ?>"><?php echo htmlspecialchars($studio['StudioName']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="block_reason" class="block text-sm font-medium text-gray-400 mb-1">Reason</label>
                        <select id="block_reason" name="block_reason" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <?php foreach ($availStats as $stat): ?>
                                <?php if ($stat['Avail_StatsID'] != 1 && $stat['Avail_StatsID'] != 2): // Exclude "Available" and "Booked" ?>
                                    <option value="<?php echo $stat['Avail_StatsID']; ?>">
                                        <?php echo htmlspecialchars($stat['Avail_Name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div id="other_reason_container" style="display: none;">
                        <label for="other_reason" class="block text-sm font-medium text-gray-400 mb-1">Specify Reason</label>
                        <input type="text" id="other_reason" name="other_reason" class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal('blockDayModal')">
                            Cancel
                        </button>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                            Block Day
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Schedule Details Modal -->
    <div id="scheduleDetailsModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Schedule Details</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeModal('scheduleDetailsModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="scheduleDetailsContent" class="space-y-4">
                <!-- Content will be populated dynamically -->
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button id="editScheduleBtn" class="bg-blue-600 hover:bg-blue-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                    Edit
                </button>
                <button id="deleteScheduleBtn" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                    Delete
                </button>
                <button class="bg-gray-600 hover:bg-gray-700 text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal('scheduleDetailsModal')">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Schedule Modal -->
    <div id="editScheduleModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Edit Schedule</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeModal('editScheduleModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editScheduleForm" action="update_schedule.php" method="post">
                <input type="hidden" id="edit_schedule_id" name="schedule_id">
                <div class="space-y-4">
                    <div>
                        <label for="edit_slot_date" class="block text-sm font-medium text-gray-400 mb-1">Date</label>
                        <input type="date" id="edit_slot_date" name="slot_date" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="edit_studio_id" class="block text-sm font-medium text-gray-400 mb-1">Studio</label>
                        <select id="edit_studio_id" name="studio_id" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm" onchange="updateEditTimeRange()">
                            <?php foreach ($studios as $studio): ?>
                                <option value="<?php echo $studio['StudioID']; ?>" data-time-in="<?php echo $studio['Time_IN']; ?>" data-time-out="<?php echo $studio['Time_OUT']; ?>">
                                    <?php echo htmlspecialchars($studio['StudioName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="edit_time_start" class="block text-sm font-medium text-gray-400 mb-1">Start Time</label>
                            <input type="time" id="edit_time_start" name="time_start" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label for="edit_time_end" class="block text-sm font-medium text-gray-400 mb-1">End Time</label>
                            <input type="time" id="edit_time_end" name="time_end" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                        </div>
                    </div>
                    <div>
                        <label for="edit_availability" class="block text-sm font-medium text-gray-400 mb-1">Availability</label>
                        <select id="edit_availability" name="availability" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <?php foreach ($availStats as $stat): ?>
                                <option value="<?php echo $stat['Avail_StatsID']; ?>">
                                    <?php echo htmlspecialchars($stat['Avail_Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal('editScheduleModal')">
                            Cancel
                        </button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                            Update Schedule
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Availability statuses from database
        const availabilityMap = {
            <?php 
            $mappings = [];
            foreach ($availStats as $stat) {
                $mappings[] = "'" . strtolower($stat['Avail_Name']) . "': " . $stat['Avail_StatsID'];
            }
            echo implode(",\n            ", $mappings);
            ?>
        };
        
        // Sidebar toggle removed; sidebar_netflix.php manages open/close state.
        
        // Studio filter functionality
        document.getElementById('studio-filter').addEventListener('change', function() {
            const studioId = this.value;
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('studio', studioId);
            window.location.href = currentUrl.toString();
        });
        
        // Modal functionality
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Add time slot for specific date
        function addTimeSlotForDate(date) {
            document.getElementById('slot_date').value = date;
            updateTimeRange();
            openModal('addTimeSlotModal');
        }
        
        // Block day functionality
        function blockDay(date) {
            document.getElementById('block_date').value = date;
            openModal('blockDayModal');
        }
        
        // View schedule details
        function viewScheduleDetails(scheduleId) {
            fetch('get_schedule_details.php?id=' + scheduleId)
                .then(response => response.json())
                .then(data => {
                    const content = document.getElementById('scheduleDetailsContent');
                    const editBtn = document.getElementById('editScheduleBtn');
                    const deleteBtn = document.getElementById('deleteScheduleBtn');
                    let html = `
                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <h4 class="text-sm font-medium text-gray-400 mb-2">Schedule Information</h4>
                                <div class="p-3 bg-[#161616] rounded-md space-y-2">
                                    <p class="text-sm"><span class="text-gray-400">Studio:</span> <span class="font-medium">${data.StudioName}</span></p>
                                    <p class="text-sm"><span class="text-gray-400">Date:</span> <span class="font-medium">${formatDate(data.Sched_Date)}</span></p>
                                    <p class="text-sm"><span class="text-gray-400">Time:</span> <span class="font-medium">${formatTime(data.Time_Start)} - ${formatTime(data.Time_End)}</span></p>
                                    <p class="text-sm"><span class="text-gray-400">Status:</span> <span class="font-medium">${data.availability}</span></p>
                                </div>
                            </div>
                    `;
                    if (data.client_name) {
                        html += `
                            <div>
                                <h4 class="text-sm font-medium text-gray-400 mb-2">Client Information</h4>
                                <div class="p-3 bg-[#161616] rounded-md">
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="avatar" style="width: 1.5rem; height: 1.5rem; font-size: 0.7rem;">
                                            ${getInitials(data.client_name)}
                                        </div>
                                        <span class="font-medium">${data.client_name}</span>
                                    </div>
                                    <p class="text-sm text-gray-400">Booking ID: #${data.BookingID || 'N/A'}</p>
                                    ${data.client_email ? `<p class="text-sm text-gray-400">Email: ${data.client_email}</p>` : ''}
                                    ${data.client_phone ? `<p class="text-sm text-gray-400">Phone: ${data.client_phone}</p>` : ''}
                                </div>
                            </div>
                        `;
                        
                        // Add Services & Instructors if available
                        if (data.services || data.instructors) {
                            html += `
                                <div>
                                    <h4 class="text-sm font-medium text-gray-400 mb-2">Booking Details</h4>
                                    <div class="p-3 bg-[#161616] rounded-md space-y-2">
                            `;
                            if (data.services) {
                                html += `<p class="text-sm"><span class="text-gray-400">Services:</span> <span class="font-medium">${data.services}</span></p>`;
                            }
                            if (data.instructors) {
                                html += `<p class="text-sm"><span class="text-gray-400">Instructors:</span> <span class="font-medium">${data.instructors}</span></p>`;
                            }
                            html += `</div></div>`;
                        }
                    }
                    html += `</div>`;
                    content.innerHTML = html;
                    
                    // Store schedule data for edit modal
                    window.currentScheduleData = data;
                    
                    editBtn.onclick = function() {
                        openEditScheduleModal(data);
                    };
                    deleteBtn.onclick = function() {
                        if (confirm('Are you sure you want to delete this schedule?')) {
                            window.location.href = `delete_schedule.php?id=${scheduleId}`;
                        }
                    };
                    if (data.availability.toLowerCase() === 'booked') {
                        editBtn.disabled = true;
                        editBtn.classList.add('opacity-50', 'cursor-not-allowed');
                        deleteBtn.disabled = true;
                        deleteBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        editBtn.disabled = false;
                        editBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                        deleteBtn.disabled = false;
                        deleteBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                    openModal('scheduleDetailsModal');
                })
                .catch(error => {
                    console.error('Error fetching schedule details:', error);
                    alert('Failed to load schedule details. Please try again.');
                });
        }
        
        // Open edit schedule modal with data
        function openEditScheduleModal(data) {
            // Get availability ID from availability name using dynamic map
            const availId = availabilityMap[data.availability.toLowerCase()] || 1;
            
            // Populate form fields
            document.getElementById('edit_schedule_id').value = data.ScheduleID;
            document.getElementById('edit_slot_date').value = data.Sched_Date;
            
            // Set studio ID if available
            const editStudioSelect = document.getElementById('edit_studio_id');
            if (data.StudioID && editStudioSelect) {
                editStudioSelect.value = data.StudioID;
            }
            
            document.getElementById('edit_time_start').value = data.Time_Start.slice(0, 5);
            document.getElementById('edit_time_end').value = data.Time_End.slice(0, 5);
            document.getElementById('edit_availability').value = availId;
            
            // Close details modal and open edit modal
            closeModal('scheduleDetailsModal');
            openModal('editScheduleModal');
        }
        
        // Update time range for edit modal based on studio operating hours
        function updateEditTimeRange() {
            const studioSelect = document.getElementById('edit_studio_id');
            const selectedOption = studioSelect.options[studioSelect.selectedIndex];
            const timeIn = selectedOption.getAttribute('data-time-in');
            const timeOut = selectedOption.getAttribute('data-time-out');
            document.getElementById('edit_time_start').value = timeIn.slice(0, 5);
            document.getElementById('edit_time_end').value = timeOut.slice(0, 5);
        }
        
        // Update time range based on studio operating hours
        function updateTimeRange() {
            const studioSelect = document.getElementById('studio_id');
            const selectedOption = studioSelect.options[studioSelect.selectedIndex];
            const timeIn = selectedOption.getAttribute('data-time-in');
            const timeOut = selectedOption.getAttribute('data-time-out');
            document.getElementById('time_start').value = timeIn.slice(0, 5);
            document.getElementById('time_end').value = timeOut.slice(0, 5);
        }
        
        // Show/hide other reason field based on selection
        document.getElementById('block_reason').addEventListener('change', function() {
            const otherReasonContainer = document.getElementById('other_reason_container');
            if (this.value === 'other') {
                otherReasonContainer.style.display = 'block';
                document.getElementById('other_reason').setAttribute('required', 'required');
            } else {
                otherReasonContainer.style.display = 'none';
                document.getElementById('other_reason').removeAttribute('required');
            }
        });
        
        // Add time slot button
        document.getElementById('addTimeSlotBtn').addEventListener('click', function() {
            updateTimeRange();
            openModal('addTimeSlotModal');
        });
        
        // Block day button
        document.getElementById('blockDayBtn').addEventListener('click', function() {
            openModal('blockDayModal');
        });
        
        // Handle Add Time Slot form submission
        document.getElementById('addTimeSlotForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('add_time_slot.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    closeModal('addTimeSlotModal');
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while adding the time slot.');
            });
        });
        
        // Handle Block Day form submission
        document.getElementById('blockDayForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('block_day.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    closeModal('blockDayModal');
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while blocking the day.');
            });
        });
        
        // Helper functions for formatting
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        }
        function formatTime(timeString) {
            const [hours, minutes] = timeString.split(':');
            const hour = parseInt(hours, 10);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour % 12 || 12;
            return `${hour12}:${minutes} ${ampm}`;
        }
        function getInitials(name) {
            if (!name) return '';
            const words = name.split(' ');
            let initials = '';
            for (const word of words) {
                if (word.length > 0) {
                    initials += word[0].toUpperCase();
                }
            }
            return initials.substring(0, 2);
        }
        
        // Initialize the time range on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateTimeRange();
            
            // Check for success/error messages in URL
            const urlParams = new URLSearchParams(window.location.search);
            const successMsg = urlParams.get('success');
            const errorMsg = urlParams.get('error');
            
            if (successMsg) {
                alert('✓ ' + successMsg);
                // Remove the parameter from URL
                urlParams.delete('success');
                const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                window.history.replaceState({}, '', newUrl);
            }
            
            if (errorMsg) {
                alert('✗ ' + errorMsg);
                // Remove the parameter from URL
                urlParams.delete('error');
                const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                window.history.replaceState({}, '', newUrl);
            }
        });
        
        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal(event.target.id);
            }
        });
    </script>
</body>
</html>