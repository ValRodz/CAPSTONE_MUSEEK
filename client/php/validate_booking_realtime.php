<?php
// validate_booking_realtime.php - Real-time validation for booking time slots against services and instructors
session_start();

// Set timezone to Philippine Time
date_default_timezone_set('Asia/Manila');

// Set content type to JSON
header('Content-Type: application/json');

// Include database connection
include __DIR__ . '/../../shared/config/db.php';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'valid' => false,
        'error' => 'Database connection failed'
    ]);
    exit();
}

// Check if user is authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'client') {
    http_response_code(401);
    echo json_encode(['success' => false, 'valid' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);
$studio_id = isset($input['studio_id']) ? intval($input['studio_id']) : 0;
$date = isset($input['date']) ? $input['date'] : '';
$start_time = isset($input['start_time']) ? $input['start_time'] : '';
$end_time = isset($input['end_time']) ? $input['end_time'] : '';
$services = isset($input['services']) ? $input['services'] : []; // Array of {service_id, instructor_id}
$booking_id = isset($input['booking_id']) ? intval($input['booking_id']) : 0;

// Log for debugging
error_log("validate_booking_realtime.php - Studio: $studio_id, Date: $date, Time: $start_time-$end_time, Services: " . json_encode($services));

// Validate input
if ($studio_id <= 0 || empty($date) || empty($start_time) || empty($end_time)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'valid' => false,
        'error' => 'Missing required parameters'
    ]);
    exit();
}

try {
    $validation_results = [];
    $all_valid = true;
    
    // 1. Check studio availability
    $studio_query = "
        SELECT COUNT(*) as booking_count 
        FROM bookings b 
        JOIN schedules s ON b.ScheduleID = s.ScheduleID 
        WHERE b.StudioID = ? 
        AND s.Sched_Date = ? 
        AND s.Avail_StatsID IN (1, 2)
        AND (
            (s.Time_Start < ? AND s.Time_End > ?)
            OR (s.Time_Start >= ? AND s.Time_Start < ?)
        )
        AND b.Book_StatsID IN (1, 2)
    ";
    
    if ($booking_id > 0) {
        $studio_query .= " AND b.BookingID != ?";
        $stmt = mysqli_prepare($conn, $studio_query);
        mysqli_stmt_bind_param($stmt, "isssssi", $studio_id, $date, $end_time, $start_time, $start_time, $end_time, $booking_id);
    } else {
        $stmt = mysqli_prepare($conn, $studio_query);
        mysqli_stmt_bind_param($stmt, "isssss", $studio_id, $date, $end_time, $start_time, $start_time, $end_time);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($row['booking_count'] > 0) {
        $all_valid = false;
        $validation_results[] = [
            'type' => 'studio',
            'valid' => false,
            'message' => 'Studio is already booked for this time slot'
        ];
    } else {
        $validation_results[] = [
            'type' => 'studio',
            'valid' => true,
            'message' => 'Studio is available'
        ];
    }
    
    // 2. Check each service's instructor availability
    if (!empty($services) && $all_valid) {
        foreach ($services as $service) {
            $service_id = isset($service['service_id']) ? intval($service['service_id']) : 0;
            $instructor_id = isset($service['instructor_id']) ? intval($service['instructor_id']) : 0;
            
            // Get service name
            $service_name_query = "SELECT ServiceType FROM services WHERE ServiceID = ?";
            $stmt = mysqli_prepare($conn, $service_name_query);
            mysqli_stmt_bind_param($stmt, "i", $service_id);
            mysqli_stmt_execute($stmt);
            $service_result = mysqli_stmt_get_result($stmt);
            $service_data = mysqli_fetch_assoc($service_result);
            $service_name = $service_data ? $service_data['ServiceType'] : "Service #$service_id";
            mysqli_stmt_close($stmt);
            
            // If no instructor selected (0 or null), skip instructor check
            if ($instructor_id <= 0) {
                $validation_results[] = [
                    'type' => 'service',
                    'service_id' => $service_id,
                    'service_name' => $service_name,
                    'instructor_id' => null,
                    'valid' => true,
                    'message' => "$service_name - No instructor selected"
                ];
                continue;
            }
            
            // Check if instructor is available
            $instructor_query = "
                SELECT COUNT(*) as instructor_bookings,
                       i.Name as instructor_name
                FROM booking_services bs
                JOIN bookings b ON bs.BookingID = b.BookingID
                JOIN schedules s ON b.ScheduleID = s.ScheduleID
                LEFT JOIN instructors i ON bs.InstructorID = i.InstructorID
                WHERE bs.InstructorID = ?
                AND s.Sched_Date = ?
                AND s.Avail_StatsID IN (1, 2)
                AND (
                    (s.Time_Start < ? AND s.Time_End > ?)
                    OR (s.Time_Start >= ? AND s.Time_Start < ?)
                )
                AND b.Book_StatsID IN (1, 2)
            ";
            
            if ($booking_id > 0) {
                $instructor_query .= " AND b.BookingID != ?";
                $stmt = mysqli_prepare($conn, $instructor_query);
                mysqli_stmt_bind_param($stmt, "isssssi", $instructor_id, $date, $end_time, $start_time, $start_time, $end_time, $booking_id);
            } else {
                $stmt = mysqli_prepare($conn, $instructor_query);
                mysqli_stmt_bind_param($stmt, "isssss", $instructor_id, $date, $end_time, $start_time, $start_time, $end_time);
            }
            
            mysqli_stmt_execute($stmt);
            $instructor_result = mysqli_stmt_get_result($stmt);
            $instructor_row = mysqli_fetch_assoc($instructor_result);
            mysqli_stmt_close($stmt);
            
            $instructor_name = $instructor_row['instructor_name'] ?? "Instructor #$instructor_id";
            
            if ($instructor_row['instructor_bookings'] > 0) {
                $all_valid = false;
                $validation_results[] = [
                    'type' => 'instructor',
                    'service_id' => $service_id,
                    'service_name' => $service_name,
                    'instructor_id' => $instructor_id,
                    'instructor_name' => $instructor_name,
                    'valid' => false,
                    'message' => "$service_name - Instructor $instructor_name is not available for this time"
                ];
            } else {
                $validation_results[] = [
                    'type' => 'instructor',
                    'service_id' => $service_id,
                    'service_name' => $service_name,
                    'instructor_id' => $instructor_id,
                    'instructor_name' => $instructor_name,
                    'valid' => true,
                    'message' => "$service_name - Instructor $instructor_name is available"
                ];
            }
        }
    }
    
    // Return validation results
    echo json_encode([
        'success' => true,
        'valid' => $all_valid,
        'validation_results' => $validation_results,
        'message' => $all_valid ? 
            'Time slot is available for all selected services and instructors' : 
            'Time slot has conflicts - please see details'
    ]);
    
} catch (Exception $e) {
    error_log("Error in validate_booking_realtime.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'valid' => false,
        'error' => 'Internal server error'
    ]);
}

// Close database connection
mysqli_close($conn);
?>

