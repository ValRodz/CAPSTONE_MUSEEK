<?php
// get_realtime_slots.php - Fetch real-time available time slots for a specific studio and date
session_start();

// Set timezone to Philippine Time
date_default_timezone_set('Asia/Manila');

// Set content type to JSON
header('Content-Type: application/json');

// Enhanced error handling for database connection
$db_path = __DIR__ . '/../../shared/config/db.php';

// Check if database config file exists
if (!file_exists($db_path)) {
    error_log("Database config file not found at: " . $db_path);
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database configuration file not found',
        'debug_info' => [
            'expected_path' => $db_path,
            'current_dir' => __DIR__,
            'file_exists' => false
        ]
    ]);
    exit();
}

// Include database connection
include $db_path;

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    $error_msg = isset($conn) ? $conn->connect_error : 'Database connection variable not set';
    error_log("Database connection failed: " . $error_msg);
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database connection failed',
        'debug_info' => [
            'connection_error' => $error_msg,
            'db_path' => $db_path
        ]
    ]);
    exit();
}

// Check if user is authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'client') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);
$date = isset($input['date']) ? $input['date'] : '';
$studio_id = isset($input['studio_id']) ? intval($input['studio_id']) : 0;
$booking_id = isset($input['booking_id']) ? intval($input['booking_id']) : 0; // For update modal
$services = isset($input['services']) && is_array($input['services']) ? $input['services'] : []; // Selected services and instructors

// Log the received data for debugging
error_log("get_realtime_slots.php - Studio ID: $studio_id, Date: $date, Booking ID: $booking_id, Services: " . json_encode($services));

// Validate input
if (empty($date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Date is required']);
    exit();
}

if ($studio_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Studio ID is required']);
    exit();
}

// Validate date format
if (!DateTime::createFromFormat('Y-m-d', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit();
}

try {
    // Get studio information including operating hours
    $studio_query = "SELECT StudioID, StudioName, Time_IN, Time_OUT FROM studios WHERE StudioID = ?";
    $stmt = mysqli_prepare($conn, $studio_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $studio_result = mysqli_stmt_get_result($stmt);
    $studio = mysqli_fetch_assoc($studio_result);
    
    if (!$studio) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Studio not found']);
        exit();
    }
    mysqli_stmt_close($stmt);
    
    $time_in = $studio['Time_IN'];
    $time_out = $studio['Time_OUT'];
    
    // Check if the selected date is today
    $today = date('Y-m-d');
    $is_today = ($date === $today);
    $current_time = date('H:i:s');
    $current_datetime = new DateTime();
    
    // Check if studio is closed for today (current time past Time_OUT)
    $today_disabled = false;
    if ($is_today && $current_time >= $time_out) {
        $today_disabled = true;
        echo json_encode([
            'success' => true,
            'slots' => [],
            'date' => $date,
            'studio' => [
                'id' => $studio_id,
                'name' => $studio['StudioName'],
                'time_in' => $time_in,
                'time_out' => $time_out
            ],
            'is_today' => $is_today,
            'current_time' => $current_time,
            'today_disabled' => $today_disabled,
            'booking_id' => $booking_id,
            'is_update' => $booking_id > 0,
            'message' => 'Studio is closed for today'
        ]);
        exit();
    }
    
    // Also check if current time is before studio opening time for today
    if ($is_today && $current_time < $time_in) {
        // Studio hasn't opened yet today, but we can still show future slots
        // No need to exit, just mark past slots as unavailable
    }
    
    // Generate time slots within studio operating hours (matching booking2.php)
    // Include closing time as a valid end time option
    $slots = [];
    $start_hour = intval(substr($time_in, 0, 2));
    $end_hour = intval(substr($time_out, 0, 2));
    
    // Generate slots from opening to closing, INCLUSIVE of closing time
    for ($hour = $start_hour; $hour <= $end_hour; $hour++) {
        $slot_time = sprintf('%02d:00:00', $hour);
        $slot_display = sprintf('%02d:00', $hour);
        
        // Determine if this is the closing time (last slot)
        $is_closing_time = ($hour === $end_hour);
        
        // Check availability based on various conditions
        $available = true;
        $reason = '';
        $is_end_time_only = false;
        
        // Closing time can only be selected as END time, not START time
        if ($is_closing_time) {
            $is_end_time_only = true;
            $available = true; // Available for end time selection
        }
        // Check if slot is in the past (for today) or before studio opening
        else if ($is_today) {
            // Check if slot has already passed
            if ($slot_time <= $current_time) {
                $available = false;
                $reason = 'Past time';
            }
            // Check if slot is before studio opening time
            else if ($slot_time < $time_in) {
                $available = false;
                $reason = 'Studio not open yet';
            }
        }
        // For future dates, check if slot is within studio operating hours
        else if (!$is_closing_time) {
            if ($slot_time < $time_in) {
                $available = false;
                $reason = 'Outside studio hours';
            }
        }
        
        // Check if this time slot overlaps with existing bookings (only for non-closing times)
        if ($available && !$is_closing_time) {
            // If specific services are selected, check for service/instructor conflicts
            // Otherwise, check for studio-level conflicts
            if (!empty($services)) {
                // Check for conflicts with specific services or instructors
                $has_conflict = false;
                $conflict_reason = '';
                
                foreach ($services as $service) {
                    $service_id = isset($service['service_id']) ? intval($service['service_id']) : 0;
                    $instructor_id = isset($service['instructor_id']) ? intval($service['instructor_id']) : 0;
                    
                    if ($service_id <= 0) continue;
                    
                    // Check if this service or instructor is already booked at this time
                    $conflict_query = "
                        SELECT COUNT(*) as conflict_count,
                               GROUP_CONCAT(DISTINCT srv.ServiceType) as conflicted_services,
                               GROUP_CONCAT(DISTINCT i.Name) as conflicted_instructors
                        FROM bookings b 
                        JOIN schedules s ON b.ScheduleID = s.ScheduleID 
                        JOIN booking_services bs ON b.BookingID = bs.BookingID
                        LEFT JOIN services srv ON bs.ServiceID = srv.ServiceID
                        LEFT JOIN instructors i ON bs.InstructorID = i.InstructorID
                        WHERE b.StudioID = ? 
                        AND s.Sched_Date = ? 
                        AND s.Avail_StatsID IN (1, 2)
                        AND (
                            (s.Time_Start <= ? AND s.Time_End > ?)
                            OR (s.Time_Start < ? AND s.Time_End >= ?)
                        )
                        AND b.Book_StatsID IN (1, 2)
                        AND (bs.ServiceID = ? OR bs.InstructorID = ?)
                    ";
                    
                    // If updating a booking, exclude current booking from check
                    if ($booking_id > 0) {
                        $conflict_query .= " AND b.BookingID != ?";
                        $stmt = mysqli_prepare($conn, $conflict_query);
                        mysqli_stmt_bind_param($stmt, "isssssiii", 
                            $studio_id, $date, $slot_time, $slot_time, $slot_time, $slot_time,
                            $service_id, $instructor_id, $booking_id);
                    } else {
                        $stmt = mysqli_prepare($conn, $conflict_query);
                        mysqli_stmt_bind_param($stmt, "issssii", 
                            $studio_id, $date, $slot_time, $slot_time, $slot_time, $slot_time,
                            $service_id, $instructor_id);
                    }
                    
                    mysqli_stmt_execute($stmt);
                    $conflict_result = mysqli_stmt_get_result($stmt);
                    $conflict_row = mysqli_fetch_assoc($conflict_result);
                    mysqli_stmt_close($stmt);
                    
                    if ($conflict_row['conflict_count'] > 0) {
                        $has_conflict = true;
                        if (!empty($conflict_row['conflicted_services'])) {
                            $conflict_reason = 'Service already booked';
                        } elseif (!empty($conflict_row['conflicted_instructors'])) {
                            $conflict_reason = 'Instructor already booked';
                        } else {
                            $conflict_reason = 'Already booked';
                        }
                        break; // Found a conflict, no need to check other services
                    }
                }
                
                if ($has_conflict) {
                    $available = false;
                    $reason = $conflict_reason;
                }
            } else {
                // No services selected, check studio-level conflicts (original behavior)
                $booking_query = "
                    SELECT COUNT(*) as booking_count 
                    FROM bookings b 
                    JOIN schedules s ON b.ScheduleID = s.ScheduleID 
                    WHERE b.StudioID = ? 
                    AND s.Sched_Date = ? 
                    AND s.Avail_StatsID IN (1, 2)
                    AND (
                        (s.Time_Start <= ? AND s.Time_End > ?)
                        OR (s.Time_Start < ? AND s.Time_End >= ?)
                    )
                    AND b.Book_StatsID IN (1, 2)
                ";
                
                // If updating a booking, exclude current booking from check
                if ($booking_id > 0) {
                    $booking_query .= " AND b.BookingID != ?";
                    $stmt = mysqli_prepare($conn, $booking_query);
                    mysqli_stmt_bind_param($stmt, "isssssi", $studio_id, $date, $slot_time, $slot_time, $slot_time, $slot_time, $booking_id);
                } else {
                    $stmt = mysqli_prepare($conn, $booking_query);
                    mysqli_stmt_bind_param($stmt, "isssss", $studio_id, $date, $slot_time, $slot_time, $slot_time, $slot_time);
                }
                
                mysqli_stmt_execute($stmt);
                $booking_result = mysqli_stmt_get_result($stmt);
                $booking_row = mysqli_fetch_assoc($booking_result);
                mysqli_stmt_close($stmt);
                
                if ($booking_row['booking_count'] > 0) {
                    $available = false;
                    $reason = 'Studio already booked';
                }
            }
        }
        
        // Check if this slot is already selected in the current session (skip if updating)
        if ($available && !$is_closing_time && isset($_SESSION['selected_slots']) && $booking_id === 0) {
            foreach ($_SESSION['selected_slots'] as $selected_slot) {
                // Handle both possible key formats for backward compatibility
                $session_start = isset($selected_slot['start']) ? $selected_slot['start'] : '';
                $session_end = isset($selected_slot['end']) ? $selected_slot['end'] : '';
                
                // Check if this slot overlaps with any session booking
                if (isset($selected_slot['studio_id']) && $selected_slot['studio_id'] == $studio_id && 
                    isset($selected_slot['date']) && $selected_slot['date'] == $date) {
                    
                    $slot_time_hm = substr($slot_time, 0, 5);
                    
                    // Check for overlap
                    if ($slot_time_hm >= $session_start && $slot_time_hm < $session_end) {
                        $available = false;
                        $reason = 'Already selected in session';
                        break;
                    }
                }
            }
        }
        
        // Add all slots (both available and unavailable) to the array
        $slots[] = [
            'time' => $slot_display,
            'start_time' => substr($slot_time, 0, 5), // Format as HH:MM
            'display' => $slot_display,
            'available' => $available,
            'reason' => $reason,
            'is_end_time_only' => $is_end_time_only,
            'is_closing_time' => $is_closing_time
        ];
    }
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'slots' => $slots,
        'date' => $date,
        'studio' => [
            'id' => $studio_id,
            'name' => $studio['StudioName'],
            'time_in' => $time_in,
            'time_out' => $time_out
        ],
        'is_today' => $is_today,
        'current_time' => $current_time,
        'today_disabled' => $today_disabled,
        'booking_id' => $booking_id, // Include booking_id if updating
        'is_update' => $booking_id > 0 // Flag to indicate if this is an update operation
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_realtime_slots.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

// Close database connection
mysqli_close($conn);
?>