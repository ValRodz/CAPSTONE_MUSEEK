<?php
// Start the session to access session variables
session_start();

        // Check if user is logged in as a studio owner
        if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
            // Redirect to login page if not logged in as owner
            header('Location: ../../auth/php/login.php');
            exit();
        }

        require_once __DIR__ . '/../../shared/config/db pdo.php';

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Month/year selection via dropdowns
$currentMonth = isset($_GET['month']) && is_numeric($_GET['month']) && $_GET['month'] >= 1 && $_GET['month'] <= 12
    ? (int)$_GET['month']
    : (int)date('n');
$currentYear = isset($_GET['year']) && is_numeric($_GET['year']) && $_GET['year'] >= 2000 && $_GET['year'] <= 2100
    ? (int)$_GET['year']
    : (int)date('Y');
$baseDate = strtotime(sprintf('%04d-%02d-01', $currentYear, $currentMonth));
$startDate = date('Y-m-01', $baseDate);
$endDate = date('Y-m-t', $baseDate);

// Get report type
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'revenue';

// Fetch revenue data for the selected period
$revenueData = $pdo->prepare("
    SELECT DATE_FORMAT(p.Pay_Date, '%Y-%m-%d') as date,
           SUM(p.Amount) as revenue
    FROM payment p
    WHERE p.OwnerID = ?
    AND p.Pay_Date BETWEEN ? AND ?
    AND p.Pay_Stats = 'Completed'
    GROUP BY DATE_FORMAT(p.Pay_Date, '%Y-%m-%d')
    ORDER BY date
");
$revenueData->execute([$ownerId, $startDate, $endDate]);
$revenueData = $revenueData->fetchAll(PDO::FETCH_ASSOC);

// Format revenue data for chart
$dates = [];
$revenues = [];
foreach ($revenueData as $data) {
    $dates[] = date('M d', strtotime($data['date']));
    $revenues[] = $data['revenue'];
}

// Calculate total revenue
$totalRevenue = array_sum($revenues);

// Fetch booking data by studio
$studioBookings = $pdo->prepare("
    SELECT s.StudioName, COUNT(b.BookingID) as booking_count
    FROM bookings b
    JOIN studios s ON b.StudioID = s.StudioID
    WHERE s.OwnerID = ?
    AND b.booking_date BETWEEN ? AND ?
    GROUP BY s.StudioID
    ORDER BY booking_count DESC
");
$studioBookings->execute([$ownerId, $startDate, $endDate]);
$studioBookings = $studioBookings->fetchAll(PDO::FETCH_ASSOC);

// Fetch service data (updated for new schema with booking_services)
$serviceData = $pdo->prepare("
    SELECT srv.ServiceType, COUNT(DISTINCT b.BookingID) as booking_count, SUM(p.Amount) as revenue
    FROM bookings b
    JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
    JOIN services srv ON bsrv.ServiceID = srv.ServiceID
    JOIN studios s ON b.StudioID = s.StudioID
    LEFT JOIN payment p ON b.BookingID = p.BookingID
    WHERE s.OwnerID = ?
    AND b.booking_date BETWEEN ? AND ?
    GROUP BY srv.ServiceID
    ORDER BY revenue DESC
");
$serviceData->execute([$ownerId, $startDate, $endDate]);
$serviceData = $serviceData->fetchAll(PDO::FETCH_ASSOC);

// Fetch top clients
$topClients = $pdo->prepare("
    SELECT c.Name, COUNT(b.BookingID) as booking_count, SUM(p.Amount) as total_spent
    FROM bookings b
    JOIN clients c ON b.ClientID = c.ClientID
    JOIN studios s ON b.StudioID = s.StudioID
    LEFT JOIN payment p ON b.BookingID = p.BookingID
    WHERE s.OwnerID = ?
    AND b.booking_date BETWEEN ? AND ?
    GROUP BY c.ClientID
    ORDER BY total_spent DESC
    LIMIT 5
");
$topClients->execute([$ownerId, $startDate, $endDate]);
$topClients = $topClients->fetchAll(PDO::FETCH_ASSOC);

// Fetch feedback data
$feedbackData = $pdo->prepare("
    SELECT f.FeedbackID, f.Rating, f.Comment, f.Date, 
           c.Name as client_name, s.StudioName
    FROM feedback f
    JOIN clients c ON f.ClientID = c.ClientID
    JOIN bookings b ON f.BookingID = b.BookingID
    JOIN studios s ON b.StudioID = s.StudioID
    WHERE f.OwnerID = ?
    AND f.Date BETWEEN ? AND ?
    ORDER BY f.Date DESC
");
$feedbackData->execute([$ownerId, $startDate, $endDate]);
$feedbackData = $feedbackData->fetchAll(PDO::FETCH_ASSOC);

// Calculate average rating
$totalRating = 0;
$ratingCount = count($feedbackData);
foreach ($feedbackData as $feedback) {
    $totalRating += $feedback['Rating'];
}
$averageRating = $ratingCount > 0 ? $totalRating / $ratingCount : 0;

// Check for unread notifications
$notificationsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE OwnerID = ? 
    AND IsRead = 0
");
$notificationsStmt->execute([$ownerId]);
$unreadNotifications = $notificationsStmt->fetchColumn();

// Get owner data
$owner = $pdo->prepare("
    SELECT Name, Email 
    FROM studio_owners 
    WHERE OwnerID = ?
");
$owner->execute([$ownerId]);
$owner = $owner->fetch(PDO::FETCH_ASSOC);

// Helper function to get customer initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Helper function to generate star rating HTML
function getStarRating($rating) {
    $fullStars = floor($rating);
    $halfStar = ($rating - $fullStars) >= 0.5;
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
    
    $html = '';
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $html .= '<i class="fas fa-star text-yellow-500"></i>';
    }
    
    // Half star
    if ($halfStar) {
        $html .= '<i class="fas fa-star-half-alt text-yellow-500"></i>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $html .= '<i class="far fa-star text-yellow-500"></i>';
    }
    
    return $html;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>MuSeek - Reports</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            transition: margin-left 0.3s ease;
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
        .report-card {
            background-color: #0a0a0a;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        .stat-card {
            background-color: #0a0a0a;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            padding: 1.5rem;
        }
        .client-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-bottom: 1px solid #222222;
        }
        .client-item:last-child {
            border-bottom: none;
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
        .tab-button {
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .tab-button.active {
            border-bottom-color: #dc2626;
            color: white;
        }
        .tab-button:not(.active) {
            color: #9ca3af;
        }
        .tab-button:hover:not(.active) {
            color: white;
            background-color: #111111;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .service-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-bottom: 1px solid #222222;
        }
        .service-item:last-child {
            border-bottom: none;
        }
        .service-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.5rem;
            background-color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
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
        .booking-table tr:hover {
            background-color: #111111;
        }
        .feedback-item {
            padding: 1rem;
            border-bottom: 1px solid #222222;
        }
        .feedback-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body class="bg-[#161616] text-white">
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <!-- Main Content -->
    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-6">Reports</h1>
        </header>
        
        <div class="p-6">
            <div class="bg-[#0a0a0a] rounded-lg border border-[#222222] p-4 mb-6">
                <form action="reports_netflix.php" method="get" class="flex flex-wrap items-end gap-4">
                    <div>
                        <label for="month" class="block text-sm font-medium text-gray-400 mb-1">Month</label>
                        <select id="month" name="month" class="bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $currentMonth === $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="year" class="block text-sm font-medium text-gray-400 mb-1">Year</label>
                        <select id="year" name="year" class="bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <?php $yearStart = (int)date('Y') - 5; $yearEnd = (int)date('Y') + 5; ?>
                            <?php for ($y = $yearStart; $y <= $yearEnd; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $currentYear === $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Period</label>
                        <span class="inline-block bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?>
                        </span>
                    </div>
                    <div>
                        <label for="report_type" class="block text-sm font-medium text-gray-400 mb-1">Report Type</label>
                        <select id="report_type" name="report_type" class="bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <option value="revenue" <?php echo $reportType === 'revenue' ? 'selected' : ''; ?>>Revenue</option>
                            <option value="bookings" <?php echo $reportType === 'bookings' ? 'selected' : ''; ?>>Bookings</option>
                            <option value="services" <?php echo $reportType === 'services' ? 'selected' : ''; ?>>Services</option>
                            <option value="feedback" <?php echo $reportType === 'feedback' ? 'selected' : ''; ?>>Feedback</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                            Generate Report
                        </button>
                    </div>
                    <div class="ml-auto">
                        <a href="export_report.php?start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&report_type=<?php echo $reportType; ?>" target="_blank" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i>
                            <span>Export as PDF</span>
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Report Tabs -->
            <div class="report-card mb-6">
                <div class="flex border-b border-[#222222]">
                    <button class="tab-button <?php echo $reportType === 'revenue' ? 'active' : ''; ?>" data-tab="revenue">Revenue</button>
                    <button class="tab-button <?php echo $reportType === 'bookings' ? 'active' : ''; ?>" data-tab="bookings">Bookings</button>
                    <button class="tab-button <?php echo $reportType === 'services' ? 'active' : ''; ?>" data-tab="services">Services</button>
                    <button class="tab-button <?php echo $reportType === 'feedback' ? 'active' : ''; ?>" data-tab="feedback">Feedback</button>
                </div>
                
                <!-- Revenue Tab -->
                <div id="revenue-tab" class="tab-content p-4 <?php echo $reportType === 'revenue' ? 'active' : ''; ?>">
                    <!-- Revenue Overview -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="stat-card">
                            <div class="flex items-center justify-between mb-2">
                                <div class="text-sm font-medium text-gray-400">Total Revenue</div>
                                <div class="p-2 rounded-full bg-blue-900/20">
                                    <i class="fas fa-peso-sign text-blue-500"></i>
                                </div>
                            </div>
                            <div class="text-2xl font-bold">₱<?php echo number_format($totalRevenue, 2); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                <?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="flex items-center justify-between mb-2">
                                <div class="text-sm font-medium text-gray-400">Total Bookings</div>
                                <div class="p-2 rounded-full bg-green-900/20">
                                    <i class="far fa-calendar-check text-green-500"></i>
                                </div>
                            </div>
                            <?php
                            // Get total bookings for the period
                            $totalBookings = $pdo->prepare("
                                SELECT COUNT(*) as count
                                FROM bookings b
                                JOIN studios s ON b.StudioID = s.StudioID
                                WHERE s.OwnerID = ?
                                AND b.booking_date BETWEEN ? AND ?
                            ");
                            $totalBookings->execute([$ownerId, $startDate, $endDate]);
                            $totalBookings = $totalBookings->fetch(PDO::FETCH_ASSOC)['count'];
                            ?>
                            <div class="text-2xl font-bold"><?php echo $totalBookings; ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                <?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="flex items-center justify-between mb-2">
                                <div class="text-sm font-medium text-gray-400">Average Booking Value</div>
                                <div class="p-2 rounded-full bg-purple-900/20">
                                    <i class="fas fa-chart-line text-purple-500"></i>
                                </div>
                            </div>
                            <?php
                            $avgBookingValue = $totalBookings > 0 ? $totalRevenue / $totalBookings : 0;
                            ?>
                            <div class="text-2xl font-bold">₱<?php echo number_format($avgBookingValue, 2); ?></div>
                            <div class="text-xs text-gray-500 mt-1">Per booking</div>
                        </div>
                    </div>
                    
                    <!-- Revenue Chart -->
                    <div style="height: 300px;">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
                
                <!-- Bookings Tab -->
                <div id="bookings-tab" class="tab-content p-4 <?php echo $reportType === 'bookings' ? 'active' : ''; ?>">
                    <!-- Studio Performance -->
                    <div class="mb-6">
                        <h2 class="text-lg font-bold mb-4">Studio Performance</h2>
                        <?php if (empty($studioBookings)): ?>
                            <p class="text-gray-400 text-center py-4">No booking data available for this period.</p>
                        <?php else: ?>
                            <div style="height: 300px;">
                                <canvas id="studioChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Booking Details Table -->
                    <div class="mt-6">
                        <h2 class="text-lg font-bold mb-4">Booking Details</h2>
                        <div class="overflow-x-auto">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th>Studio</th>
                                        <th>Client</th>
                                        <th>Date</th>
                                        <th>Service</th>
                                        <th>Status</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Fetch booking details
                                    $bookingDetails = $pdo->prepare("
                                        SELECT b.BookingID, b.booking_date, 
                                               c.Name as client_name, 
                                               s.StudioName,
                                               GROUP_CONCAT(DISTINCT srv.ServiceType ORDER BY srv.ServiceType SEPARATOR ', ') AS ServiceType,
                                               bs.Book_Stats as status,
                                               p.Amount
                                        FROM bookings b
                                        JOIN clients c ON b.ClientID = c.ClientID
                                        JOIN studios s ON b.StudioID = s.StudioID
                                        JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                                        LEFT JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
                                        LEFT JOIN services srv ON bsrv.ServiceID = srv.ServiceID
                                        LEFT JOIN payment p ON b.BookingID = p.BookingID
                                        WHERE s.OwnerID = ?
                                        AND b.booking_date BETWEEN ? AND ?
                                        GROUP BY b.BookingID
                                        ORDER BY b.booking_date DESC
                                    ");
                                    $bookingDetails->execute([$ownerId, $startDate, $endDate]);
                                    $bookingDetails = $bookingDetails->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    if (empty($bookingDetails)): 
                                    ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-gray-400">No bookings found for this period.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($bookingDetails as $booking): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($booking['StudioName']); ?></td>
                                                <td>
                                                    <div class="flex items-center gap-2">
                                                        <div class="avatar">
                                                            <?php echo getInitials($booking['client_name']); ?>
                                                        </div>
                                                        <span><?php echo htmlspecialchars($booking['client_name']); ?></span>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($booking['ServiceType']); ?></td>
                                                <td>
                                                    <span class="px-2 py-1 rounded-full text-xs font-medium
                                                        <?php 
                                                        echo strtolower($booking['status']) === 'confirmed' ? 'bg-green-900/20 text-green-500' : 
                                                            (strtolower($booking['status']) === 'pending' ? 'bg-yellow-900/20 text-yellow-500' : 
                                                            'bg-red-900/20 text-red-500'); 
                                                        ?>">
                                                        <?php echo htmlspecialchars($booking['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="font-medium">
                                                    <?php echo $booking['Amount'] ? '₱' . number_format($booking['Amount'], 2) : 'N/A'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Services Tab -->
                <div id="services-tab" class="tab-content p-4 <?php echo $reportType === 'services' ? 'active' : ''; ?>">
                    <!-- Services Overview -->
                    <div class="mb-6">
                        <h2 class="text-lg font-bold mb-4">Services Performance</h2>
                        <?php if (empty($serviceData)): ?>
                            <p class="text-gray-400 text-center py-4">No service data available for this period.</p>
                        <?php else: ?>
                            <div style="height: 300px;">
                                <canvas id="servicesChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Services Details -->
                    <div class="mt-6">
                        <h2 class="text-lg font-bold mb-4">Service Details</h2>
                        <?php if (empty($serviceData)): ?>
                            <p class="text-gray-400 text-center py-4">No service data available for this period.</p>
                        <?php else: ?>
                            <div class="bg-[#0a0a0a] rounded-lg border border-[#222222] overflow-hidden">
                                <?php foreach ($serviceData as $service): ?>
                                    <div class="service-item">
                                        <div class="service-icon">
                                            <i class="fas fa-music"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium"><?php echo htmlspecialchars($service['ServiceType']); ?></p>
                                            <p class="text-xs text-gray-400"><?php echo $service['booking_count']; ?> bookings</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-sm font-medium">₱<?php echo number_format($service['revenue'], 2); ?></p>
                                            <p class="text-xs text-gray-400">Total revenue</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Feedback Tab -->
                <div id="feedback-tab" class="tab-content p-4 <?php echo $reportType === 'feedback' ? 'active' : ''; ?>">
                    <!-- Feedback Overview -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="stat-card">
                            <div class="flex items-center justify-between mb-2">
                                <div class="text-sm font-medium text-gray-400">Average Rating</div>
                                <div class="p-2 rounded-full bg-yellow-900/20">
                                    <i class="fas fa-star text-yellow-500"></i>
                                </div>
                            </div>
                            <div class="text-2xl font-bold"><?php echo number_format($averageRating, 1); ?>/5.0</div>
                            <div class="text-xs text-gray-500 mt-1">
                                Based on <?php echo $ratingCount; ?> reviews
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="flex items-center justify-between mb-2">
                                <div class="text-sm font-medium text-gray-400">Total Feedback</div>
                                <div class="p-2 rounded-full bg-blue-900/20">
                                    <i class="far fa-comment-alt text-blue-500"></i>
                                </div>
                            </div>
                            <div class="text-2xl font-bold"><?php echo $ratingCount; ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                <?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?>
                            </div>
                        </div>
                        
                        <?php
                        // Calculate rating distribution
                        $ratingDistribution = [0, 0, 0, 0, 0];
                        foreach ($feedbackData as $feedback) {
                            $rating = intval($feedback['Rating']);
                            if ($rating >= 1 && $rating <= 5) {
                                $ratingDistribution[$rating - 1]++;
                            }
                        }
                        
                        // Find highest rated studio
                        $studioRatings = [];
                        foreach ($feedbackData as $feedback) {
                            $studioName = $feedback['StudioName'];
                            if (!isset($studioRatings[$studioName])) {
                                $studioRatings[$studioName] = ['total' => 0, 'count' => 0];
                            }
                            $studioRatings[$studioName]['total'] += $feedback['Rating'];
                            $studioRatings[$studioName]['count']++;
                        }
                        
                        $highestRatedStudio = '';
                        $highestRating = 0;
                        foreach ($studioRatings as $studio => $data) {
                            $avgRating = $data['count'] > 0 ? $data['total'] / $data['count'] : 0;
                            if ($avgRating > $highestRating) {
                                $highestRating = $avgRating;
                                $highestRatedStudio = $studio;
                            }
                        }
                        ?>
                        
                        <div class="stat-card">
                            <div class="flex items-center justify-between mb-2">
                                <div class="text-sm font-medium text-gray-400">Highest Rated Studio</div>
                                <div class="p-2 rounded-full bg-green-900/20">
                                    <i class="fas fa-trophy text-green-500"></i>
                                </div>
                            </div>
                            <?php if ($highestRatedStudio): ?>
                                <div class="text-2xl font-bold"><?php echo htmlspecialchars($highestRatedStudio); ?></div>
                                <div class="text-xs text-gray-500 mt-1">
                                    Rating: <?php echo number_format($highestRating, 1); ?>/5.0
                                </div>
                            <?php else: ?>
                                <div class="text-lg font-medium">No ratings yet</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Rating Distribution Chart -->
                    <div class="mb-6">
                        <h2 class="text-lg font-bold mb-4">Rating Distribution</h2>
                        <div style="height: 250px;">
                            <canvas id="ratingChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Feedback List -->
                    <div class="mt-6">
                        <h2 class="text-lg font-bold mb-4">Recent Feedback</h2>
                        <?php if (empty($feedbackData)): ?>
                            <p class="text-gray-400 text-center py-4">No feedback available for this period.</p>
                        <?php else: ?>
                            <div class="bg-[#0a0a0a] rounded-lg border border-[#222222] overflow-hidden">
                                <?php foreach ($feedbackData as $feedback): ?>
                                    <div class="feedback-item">
                                        <div class="flex items-center justify-between mb-2">
                                            <div class="flex items-center gap-2">
                                                <div class="avatar">
                                                    <?php echo getInitials($feedback['client_name']); ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-medium"><?php echo htmlspecialchars($feedback['client_name']); ?></p>
                                                    <p class="text-xs text-gray-400"><?php echo htmlspecialchars($feedback['StudioName']); ?></p>
                                                </div>
                                            </div>
                                            <div class="text-xs text-gray-400">
                                                <?php echo date('M d, Y', strtotime($feedback['Date'])); ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-1 mb-2">
                                            <?php echo getStarRating($feedback['Rating']); ?>
                                            <span class="text-xs text-gray-400 ml-2"><?php echo $feedback['Rating']; ?>.0/5.0</span>
                                        </div>
                                        <?php if (!empty($feedback['Comment'])): ?>
                                            <p class="text-sm text-gray-300"><?php echo htmlspecialchars($feedback['Comment']); ?></p>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-500 italic">No comment provided</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Top Clients -->
            <div class="report-card p-4">
                <h2 class="text-lg font-bold mb-4">Top Clients</h2>
                <?php if (empty($topClients)): ?>
                    <p class="text-gray-400 text-center py-4">No client data available for this period.</p>
                <?php else: ?>
                    <div class="space-y-1">
                        <?php foreach ($topClients as $client): ?>
                            <div class="client-item">
                                <div class="avatar">
                                    <?php echo getInitials($client['Name']); ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium"><?php echo htmlspecialchars($client['Name']); ?></p>
                                    <p class="text-xs text-gray-400"><?php echo $client['booking_count']; ?> bookings</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-medium">₱<?php echo number_format($client['total_spent'], 2); ?></p>
                                    <p class="text-xs text-gray-400">Total spent</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Sidebar interactions are handled by sidebar_netflix.php include
        
        // Tab functionality
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const tabName = button.getAttribute('data-tab');
                
                // Update URL with the selected tab
                const url = new URL(window.location);
                url.searchParams.set('report_type', tabName);
                window.history.pushState({}, '', url);
                
                // Update active tab
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                button.classList.add('active');
                document.getElementById(`${tabName}-tab`).classList.add('active');
            });
        });
        
        // Revenue Chart
        <?php if (!empty($revenueData)): ?>
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode($revenues); ?>,
                    backgroundColor: 'rgba(220, 38, 38, 0.2)',
                    borderColor: 'rgba(220, 38, 38, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointBackgroundColor: 'rgba(220, 38, 38, 1)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#222222'
                        },
                        ticks: {
                            color: '#9ca3af',
                            callback: function(value) {
                                return '₱' + value;
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#9ca3af'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.raw.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Studio Performance Chart
        <?php if (!empty($studioBookings)): ?>
        const studioCtx = document.getElementById('studioChart').getContext('2d');
        const studioChart = new Chart(studioCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($studioBookings, 'StudioName')); ?>,
                datasets: [{
                    label: 'Bookings',
                    data: <?php echo json_encode(array_column($studioBookings, 'booking_count')); ?>,
                    backgroundColor: [
                        'rgba(220, 38, 38, 0.7)',
                        'rgba(59, 130, 246, 0.7)',
                        'rgba(16, 185, 129, 0.7)',
                        'rgba(245, 158, 11, 0.7)',
                        'rgba(139, 92, 246, 0.7)'
                    ],
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#222222'
                        },
                        ticks: {
                            color: '#9ca3af',
                            precision: 0
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#9ca3af'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Services Chart
        <?php if (!empty($serviceData)): ?>
        const servicesCtx = document.getElementById('servicesChart').getContext('2d');
        const servicesChart = new Chart(servicesCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($serviceData, 'ServiceType')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($serviceData, 'revenue')); ?>,
                    backgroundColor: [
                        'rgba(220, 38, 38, 0.7)',
                        'rgba(59, 130, 246, 0.7)',
                        'rgba(16, 185, 129, 0.7)',
                        'rgba(245, 158, 11, 0.7)',
                        'rgba(139, 92, 246, 0.7)'
                    ],
                    borderWidth: 1,
                    borderColor: '#161616'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: '#9ca3af',
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ₱${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Rating Distribution Chart
        <?php if (!empty($feedbackData)): ?>
        const ratingCtx = document.getElementById('ratingChart').getContext('2d');
        const ratingChart = new Chart(ratingCtx, {
            type: 'bar',
            data: {
                labels: ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'],
                datasets: [{
                    label: 'Number of Ratings',
                    data: <?php echo json_encode($ratingDistribution); ?>,
                    backgroundColor: [
                        'rgba(239, 68, 68, 0.7)',
                        'rgba(249, 115, 22, 0.7)',
                        'rgba(245, 158, 11, 0.7)',
                        'rgba(132, 204, 22, 0.7)',
                        'rgba(34, 197, 94, 0.7)'
                    ],
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#222222'
                        },
                        ticks: {
                            color: '#9ca3af',
                            precision: 0
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#9ca3af'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
