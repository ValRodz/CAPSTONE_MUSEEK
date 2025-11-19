<?php
// Configure error reporting without outputting to the browser (prevents PDF corruption)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header("Location: login.php");
    exit();
}

include '../../shared/config/db pdo.php';
include_once '../../shared/config/paths.php';

// Include Dompdf library
require_once('../../shared/libraries/dompdf/autoload.inc.php');
use Dompdf\Dompdf;
use Dompdf\Options;

// No GD library dependency required for this version

/**
 * Generate a text-based summary of revenue data
 * 
 * @param array $data Revenue data array
 * @return string HTML table with summary
 */
function generateRevenueTable($data) {
    $html = '<div class="summary-table">
        <h3>Revenue Summary Table</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>';
    
    $totalRevenue = 0;
    foreach ($data as $item) {
        $html .= '<tr>
            <td>' . date('M d, Y', strtotime($item['date'])) . '</td>
            <td class="peso">' . number_format($item['revenue'], 2) . '</td>
        </tr>';
        $totalRevenue += $item['revenue'];
    }
    
    $html .= '</tbody>
        <tfoot>
            <tr>
                <th>Total</th>
                <th class="peso">' . number_format($totalRevenue, 2) . '</th>
            </tr>
        </tfoot>
    </table></div>';
    
    return $html;
}

/**
 * Generate a text-based summary of bookings data
 * 
 * @param array $data Bookings data array
 * @return string HTML table with summary
 */
function generateBookingsTable($data) {
    $html = '<div class="summary-table">
        <h3>Bookings Summary Table</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Number of Bookings</th>
                </tr>
            </thead>
            <tbody>';
    
    $totalBookings = 0;
    foreach ($data as $item) {
        $html .= '<tr>
            <td>' . date('M d, Y', strtotime($item['date'])) . '</td>
            <td>' . $item['count'] . '</td>
        </tr>';
        $totalBookings += $item['count'];
    }
    
    $html .= '</tbody>
        <tfoot>
            <tr>
                <th>Total</th>
                <th>' . $totalBookings . '</th>
            </tr>
        </tfoot>
    </table></div>';
    
    return $html;
}

/**
 * Generate a text-based summary of feedback ratings
 * 
 * @param array $data Feedback data array
 * @return string HTML table with summary
 */
function generateFeedbackTable($data) {
    // Calculate average ratings by date
    $ratingsByDate = [];
    foreach ($data as $item) {
        $date = $item['date'];
        if (!isset($ratingsByDate[$date])) {
            $ratingsByDate[$date] = ['sum' => 0, 'count' => 0];
        }
        $ratingsByDate[$date]['sum'] += $item['rating'];
        $ratingsByDate[$date]['count']++;
    }
    
    $html = '<div class="summary-table">
        <h3>Feedback Ratings Summary</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Average Rating</th>
                    <th>Number of Ratings</th>
                </tr>
            </thead>
            <tbody>';
    
    $totalRatings = 0;
    $totalSum = 0;
    foreach ($ratingsByDate as $date => $info) {
        $average = $info['sum'] / $info['count'];
        $html .= '<tr>
            <td>' . date('M d, Y', strtotime($date)) . '</td>
            <td>' . number_format($average, 1) . ' / 5.0</td>
            <td>' . $info['count'] . '</td>
        </tr>';
        $totalRatings += $info['count'];
        $totalSum += $info['sum'];
    }
    
    $overallAverage = $totalRatings > 0 ? $totalSum / $totalRatings : 0;
    
    $html .= '</tbody>
        <tfoot>
            <tr>
                <th>Overall Average</th>
                <th>' . number_format($overallAverage, 1) . ' / 5.0</th>
                <th>' . $totalRatings . '</th>
            </tr>
        </tfoot>
    </table></div>';
    
    return $html;
}

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Get report type
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'revenue';

// Get owner data
$owner = $pdo->prepare("
    SELECT Name, Email 
    FROM studio_owners 
    WHERE OwnerID = ?
");
$owner->execute([$ownerId]);
$owner = $owner->fetch(PDO::FETCH_ASSOC);

// Configure Dompdf options
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');
// Set chroot to MUSEEK_PATH for proper image loading
$options->set('chroot', MUSEEK_PATH);
$options->set('isFontSubsettingEnabled', true);
$options->set('debugKeepTemp', false);
$options->set('debugCss', false);

// Create new Dompdf instance
$dompdf = new Dompdf($options);

// Report title based on type
$reportTitle = '';
switch ($reportType) {
    case 'revenue':
        $reportTitle = 'Revenue Report';
        break;
    case 'bookings':
        $reportTitle = 'Bookings Report';
        break;
    case 'services':
        $reportTitle = 'Services Report';
        break;
    case 'feedback':
        $reportTitle = 'Feedback Report';
        break;
    default:
        $reportTitle = 'Studio Report';
}

// Start building HTML content
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>' . $reportTitle . '</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header img {
            max-width: 150px;
            margin-bottom: 10px;
        }
        h1 {
            color: #333;
            margin: 0;
            padding: 0;
        }
        .report-meta {
            color: #666;
            margin: 10px 0 20px;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .summary {
            margin: 20px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #999;
        }
        .page-break {
            page-break-after: always;
        }
        .chart-container {
            width: 100%;
            height: 300px;
            margin: 20px 0;
        }
        .peso:before {
            content: "PHP ";
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>MuSeek</h1>
        <h2>' . $reportTitle . '</h2>
        <div class="report-meta">
            Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '<br>
            Generated on: ' . date('M d, Y') . '
        </div>
    </div>
';

// Generate report content based on type
switch ($reportType) {
    case 'revenue':
        $html .= generateRevenueReport($pdo, $ownerId, $startDate, $endDate);
        break;
    case 'bookings':
        $html .= generateBookingsReport($pdo, $ownerId, $startDate, $endDate);
        break;
    case 'services':
        $html .= generateServicesReport($pdo, $ownerId, $startDate, $endDate);
        break;
    case 'feedback':
        $html .= generateFeedbackReport($pdo, $ownerId, $startDate, $endDate);
        break;
    default:
        $html .= generateRevenueReport($pdo, $ownerId, $startDate, $endDate);
}

// Close HTML document
$html .= '
    <div class="footer">
        &copy; ' . date('Y') . ' MuSeek Studios. All rights reserved.
    </div>
</body>
</html>
';

// Load HTML content
$dompdf->loadHtml($html);

// Set paper size and orientation
$dompdf->setPaper('A4', 'portrait');

// Render PDF (generate)
$dompdf->render();

// Output PDF
$dompdf->stream('museek_report_' . $reportType . '_' . date('Y-m-d') . '.pdf', array('Attachment' => false));
exit();

// Function to generate revenue report
function generateRevenueReport($pdo, $ownerId, $startDate, $endDate) {
    // Fetch revenue data
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
    
    // Calculate total revenue
    $totalRevenue = 0;
    foreach ($revenueData as $data) {
        $totalRevenue += $data['revenue'];
    }
    
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
    
    // Calculate average booking value
    $avgBookingValue = $totalBookings > 0 ? $totalRevenue / $totalBookings : 0;
    
    // Build HTML for revenue report
    $html = '
    <div class="summary">
        <h2>Revenue Summary</h2>
        <p><strong>Total Revenue:</strong> <span class="peso">' . number_format($totalRevenue, 2) . '</span></p>
        <p><strong>Total Bookings:</strong> ' . $totalBookings . '</p>
        <p><strong>Average Booking Value:</strong> <span class="peso">' . number_format($avgBookingValue, 2) . '</span></p>
    </div>
    
    ' . generateRevenueTable($revenueData) . '
    
    <h2>Revenue Details</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Revenue</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($revenueData as $data) {
        $html .= '
            <tr>
                <td>' . date('M d, Y', strtotime($data['date'])) . '</td>
                <td><span class="peso">' . number_format($data['revenue'], 2) . '</span></td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>';
    
    return $html;
}

// Function to generate bookings report
function generateBookingsReport($pdo, $ownerId, $startDate, $endDate) {
    // Fetch bookings data (updated for new schema with booking_services)
    $bookingsData = $pdo->prepare("
        SELECT b.booking_date, b.Book_StatsID, 
               c.Name as client_name, c.Email as client_email,
               GROUP_CONCAT(DISTINCT s.ServiceType ORDER BY s.ServiceType SEPARATOR ', ') as service_name,
               st.StudioName as studio_name
        FROM bookings b
        JOIN clients c ON b.ClientID = c.ClientID
        LEFT JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
        LEFT JOIN services s ON bsrv.ServiceID = s.ServiceID
        JOIN studios st ON b.StudioID = st.StudioID
        WHERE st.OwnerID = ?
        AND b.booking_date BETWEEN ? AND ?
        GROUP BY b.BookingID
        ORDER BY b.booking_date
    ");
    $bookingsData->execute([$ownerId, $startDate, $endDate]);
    $bookingsData = $bookingsData->fetchAll(PDO::FETCH_ASSOC);
    
    // Count bookings by status
    $statusCounts = [
        'Pending' => 0,
        'Confirmed' => 0,
        'Completed' => 0,
        'Cancelled' => 0
    ];
    
    foreach ($bookingsData as $booking) {
        if (isset($statusCounts[$booking['status']])) {
            $statusCounts[$booking['status']]++;
        }
    }
    
    // Build HTML for bookings report
    $html = '
    <div class="summary">
        <h2>Bookings Summary</h2>
        <p><strong>Total Bookings:</strong> ' . count($bookingsData) . '</p>
        <p><strong>Pending:</strong> ' . $statusCounts['Pending'] . '</p>
        <p><strong>Confirmed:</strong> ' . $statusCounts['Confirmed'] . '</p>
        <p><strong>Completed:</strong> ' . $statusCounts['Completed'] . '</p>
        <p><strong>Cancelled:</strong> ' . $statusCounts['Cancelled'] . '</p>
    </div>
    
    <h2>Bookings Details</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Time</th>
                <th>Client</th>
                <th>Service</th>
                <th>Studio</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($bookingsData as $booking) {
        $html .= '
            <tr>
                <td>' . date('M d, Y', strtotime($booking['booking_date'])) . '</td>
                <td>' . $booking['booking_time'] . '</td>
                <td>' . $booking['client_name'] . '</td>
                <td>' . $booking['service_name'] . '</td>
                <td>' . $booking['studio_name'] . '</td>
                <td>' . $booking['status'] . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>';
    
    return $html;
}

// Function to generate services report (updated for new schema with booking_services)
function generateServicesReport($pdo, $ownerId, $startDate, $endDate) {
    // Fetch services data
    $servicesData = $pdo->prepare("
        SELECT s.ServiceType as service_name, s.Description, s.Price,
               COUNT(DISTINCT b.BookingID) as booking_count
        FROM services s
        LEFT JOIN booking_services bsrv ON s.ServiceID = bsrv.ServiceID
        LEFT JOIN bookings b ON bsrv.BookingID = b.BookingID AND b.booking_date BETWEEN ? AND ?
        WHERE s.OwnerID = ?
        GROUP BY s.ServiceID
        ORDER BY booking_count DESC
    ");
    $servicesData->execute([$startDate, $endDate, $ownerId]);
    $servicesData = $servicesData->fetchAll(PDO::FETCH_ASSOC);
    
    // Build HTML for services report
    $html = '
    <div class="summary">
        <h2>Services Summary</h2>
        <p><strong>Total Services:</strong> ' . count($servicesData) . '</p>
    </div>
    
    <h2>Services Details</h2>
    <table>
        <thead>
            <tr>
                <th>Service Name</th>
                <th>Description</th>
                <th>Price</th>
                <th>Bookings</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($servicesData as $service) {
        $html .= '
            <tr>
                <td>' . $service['service_name'] . '</td>
                <td>' . $service['Description'] . '</td>
                <td>₱' . number_format($service['Price'], 2) . '</td>
                <td>' . $service['booking_count'] . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>';
    
    return $html;
}

// Function to generate feedback report
function generateFeedbackReport($pdo, $ownerId, $startDate, $endDate) {
    // Fetch feedback data
    $feedbackData = $pdo->prepare("
        SELECT f.Rating, f.Comment, f.Date,
               c.Name as client_name,
               s.Name as service_name
        FROM feedback f
        JOIN clients c ON f.ClientID = c.ClientID
        JOIN services s ON f.ServiceID = s.ServiceID
        JOIN studios st ON s.StudioID = st.StudioID
        WHERE st.OwnerID = ?
        AND f.Date BETWEEN ? AND ?
        ORDER BY f.Date DESC
    ");
    $feedbackData->execute([$ownerId, $startDate, $endDate]);
    $feedbackData = $feedbackData->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate average rating
    $totalRating = 0;
    $ratingCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    
    foreach ($feedbackData as $feedback) {
        $totalRating += $feedback['Rating'];
        $ratingCounts[$feedback['Rating']]++;
    }
    
    $avgRating = count($feedbackData) > 0 ? $totalRating / count($feedbackData) : 0;
    
    // Build HTML for feedback report
    $html = '
    <div class="summary">
        <h2>Feedback Summary</h2>
        <p><strong>Total Feedback:</strong> ' . count($feedbackData) . '</p>
        <p><strong>Average Rating:</strong> ' . number_format($avgRating, 1) . ' / 5</p>
        <p><strong>Rating Distribution:</strong></p>
        <ul>
            <li>5 Stars: ' . $ratingCounts[5] . '</li>
            <li>4 Stars: ' . $ratingCounts[4] . '</li>
            <li>3 Stars: ' . $ratingCounts[3] . '</li>
            <li>2 Stars: ' . $ratingCounts[2] . '</li>
            <li>1 Star: ' . $ratingCounts[1] . '</li>
        </ul>
    </div>
    
    <h2>Feedback Details</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Client</th>
                <th>Service</th>
                <th>Rating</th>
                <th>Comment</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($feedbackData as $feedback) {
        $html .= '
            <tr>
                <td>' . date('M d, Y', strtotime($feedback['Date'])) . '</td>
                <td>' . $feedback['client_name'] . '</td>
                <td>' . $feedback['service_name'] . '</td>
                <td>' . $feedback['Rating'] . ' / 5</td>
                <td>' . $feedback['Comment'] . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>';
    
    return $html;
}
?>
