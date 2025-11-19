<?php
session_start();
// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

// Include database connection
include '../../shared/config/db pdo.php';
require_once '../../shared/config/mail_config.php';
require_once '../../shared/config/paths.php';

$ownerId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['bookingId']) && isset($data['clientId'])) {
    $bookingId = $data['bookingId'];
    $clientId = $data['clientId'];
    $message = $data['message'];

    try {
        // Update booking status to Confirmed (assuming Book_StatsID = 3)
        $updateStmt = $pdo->prepare("UPDATE bookings SET Book_StatsID = 1 WHERE BookingID = ? AND StudioID IN (SELECT StudioID FROM studios WHERE OwnerID = ?)");
        $updateStmt->execute([$bookingId, $ownerId]);

        // Insert notification for the client
        $notifyStmt = $pdo->prepare("INSERT INTO notifications (OwnerID, ClientID, Type, Message, RelatedID, For_User, Created_At) VALUES (?, ?, 'booking_confirmation', ?, ?, 'Client', NOW())");
        $notifyStmt->execute([$ownerId, $clientId, $message, $bookingId]);

        // Send confirmation email to the client (updated for new schema with booking_services)
        $detailsStmt = $pdo->prepare("SELECT c.Name AS ClientName, c.Email AS ClientEmail, s.StudioName, s.Loc_Desc, sch.Sched_Date, sch.Time_Start, sch.Time_End, GROUP_CONCAT(DISTINCT sv.ServiceType ORDER BY sv.ServiceType SEPARATOR ', ') AS ServiceType, p.Init_Amount, p.Amount FROM bookings b JOIN clients c ON b.ClientID = c.ClientID JOIN studios s ON b.StudioID = s.StudioID JOIN schedules sch ON sch.ScheduleID = b.ScheduleID LEFT JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID LEFT JOIN services sv ON bsrv.ServiceID = sv.ServiceID LEFT JOIN payment p ON p.BookingID = b.BookingID WHERE b.BookingID = ? GROUP BY b.BookingID");
        $detailsStmt->execute([$bookingId]);
        $details = $detailsStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($details && !empty($details['ClientEmail'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $confirm_url = $scheme . '://' . $host . MUSEEK_URL . '/booking/php/booking_confirmation.php?booking_id=' . $bookingId;

            $subject = 'Booking Confirmed - Museek';
            $htmlBody = '<div style="font-family:Arial,sans-serif;color:#111">'
                      . '<h2 style="margin:0 0 8px">Your Booking is Confirmed</h2>'
                      . '<p style="margin:0 0 12px">Hi ' . htmlspecialchars($details['ClientName'] ?? 'Client') . ',</p>'
                      . '<p style="margin:0 0 12px">Your booking at <strong>' . htmlspecialchars($details['StudioName'] ?? '') . '</strong> has been confirmed.</p>'
                      . '<p style="margin:0 0 12px"><i>' . htmlspecialchars($details['Loc_Desc'] ?? '') . '</i></p>'
                      . '<ul style="margin:12px 0;padding-left:16px">'
                      . '<li><strong>Service:</strong> ' . htmlspecialchars($details['ServiceType'] ?? '') . '</li>'
                      . '<li><strong>Date:</strong> ' . htmlspecialchars($details['Sched_Date'] ?? '') . '</li>'
                      . '<li><strong>Time:</strong> ' . htmlspecialchars($details['Time_Start'] ?? '') . ' - ' . htmlspecialchars($details['Time_End'] ?? '') . '</li>'
                      . '</ul>'
                      . (!empty($message) ? '<p style="margin:12px 0"><em>Note from the studio:</em> ' . htmlspecialchars($message) . '</p>' : '')
                      . '<p style="margin:16px 0">View details: <a href="' . htmlspecialchars($confirm_url) . '" style="color:#0b5;text-decoration:none">Booking Confirmation</a></p>'
                      . '</div>';

            $altBody = "Your booking is confirmed\n"
                     . "Studio: " . ($details['StudioName'] ?? '') . "\n"
                     . "Date: " . ($details['Sched_Date'] ?? '') . "\n"
                     . "Time: " . ($details['Time_Start'] ?? '') . " - " . ($details['Time_End'] ?? '') . "\n"
                     . "View: $confirm_url";

            @sendTransactionalEmail($details['ClientEmail'], $details['ClientName'] ?? 'Client', $subject, $htmlBody, $altBody);
        }

        echo json_encode(['success' => true, 'message' => 'Booking confirmed']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>