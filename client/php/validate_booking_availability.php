<?php
// validate_booking_availability.php - Dual validation system for booking availability
session_start();
include '../../shared/config/db.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'client') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);
$studio_id = isset($input['studio_id']) ? intval($input['studio_id']) : 0;
$date = isset($input['date']) ? $input['date'] : '';
$start_time = isset($input['start_time']) ? $input['start_time'] : '';
$end_time = isset($input['end_time']) ? $input['end_time'] : '';

// Validate input
if (!$studio_id || !$date || !$start_time || !$end_time) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// Validate date format
if (!DateTime::createFromFormat('Y-m-d', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit();
}

// Validate time format
if (!DateTime::createFromFormat('H:i:s', $start_time) || !DateTime::createFromFormat('H:i:s', $end_time)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid time format']);
    exit();
}

try {
    $validation_results = [];
    
    // 1. Validate against studio operating hours
    $studio_query = "SELECT StudioName, Time_IN, Time_OUT FROM studios WHERE StudioID = ?";
    $stmt = mysqli_prepare($conn, $studio_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $studio_result = mysqli_stmt_get_result($stmt);
    $studio = mysqli_fetch_assoc($studio_result);
    mysqli_stmt_close($stmt);
    
    if (!$studio) {
        echo json_encode(['success' => false, 'error' => 'Studio not found']);
        exit();
    }
    
    // Check if booking is within operating hours
    if ($start_time < $studio['Time_IN'] || $end_time > $studio['Time_OUT']) {
        $validation_results[] = [
            'type' => 'operating_hours',
            'valid' => false,
            'message' => 'Booking time is outside studio operating hours'
        ];
    } else {
        $validation_results[] = [
            'type' => 'operating_hours',
            'valid' => true,
            'message' => 'Booking time is within operating hours'
        ];
    }
    
    // 2. Check if end time is after start time
    if ($end_time <= $start_time) {
        $validation_results[] = [
            'type' => 'time_sequence',
            'valid' => false,
            'message' => 'End time must be after start time'
        ];
    } else {
        $validation_results[] = [
            'type' => 'time_sequence',
            'valid' => true,
            'message' => 'Time sequence is valid'
        ];
    }
    
    // 3. Check for minimum booking duration (1 hour)
    $start_timestamp = strtotime($start_time);
    $end_timestamp = strtotime($end_time);
    $duration_hours = ($end_timestamp - $start_timestamp) / 3600;
    
    if ($duration_hours < 1) {
        $validation_results[] = [
            'type' => 'minimum_duration',
            'valid' => false,
            'message' => 'Minimum booking duration is 1 hour'
        ];
    } else {
        $validation_results[] = [
            'type' => 'minimum_duration',
            'valid' => true,
            'message' => 'Booking duration meets minimum requirement'
        ];
    }
    
    // 4. Check for overlapping bookings
    $overlap_query = "
        SELECT COUNT(*) as overlap_count 
        FROM bookings b 
        JOIN schedules s ON b.ScheduleID = s.ScheduleID 
        WHERE b.StudioID = ? 
        AND s.Sched_Date = ? 
        AND b.Book_StatsID IN (1, 2)
        AND (
            (s.Time_Start < ? AND s.Time_End > ?) OR
            (s.Time_Start < ? AND s.Time_End > ?) OR
            (s.Time_Start >= ? AND s.Time_End <= ?)
        )
    ";
    
    $stmt = mysqli_prepare($conn, $overlap_query);
    mysqli_stmt_bind_param($stmt, "isssssss", 
        $studio_id, $date, 
        $end_time, $start_time,    // Check if existing booking starts before our end and ends after our start
        $start_time, $end_time,    // Check if existing booking starts before our start and ends after our end
        $start_time, $end_time     // Check if existing booking is completely within our time range
    );
    mysqli_stmt_execute($stmt);
    $overlap_result = mysqli_stmt_get_result($stmt);
    $overlap_row = mysqli_fetch_assoc($overlap_result);
    mysqli_stmt_close($stmt);
    
    if ($overlap_row['overlap_count'] > 0) {
        $validation_results[] = [
            'type' => 'booking_overlap',
            'valid' => false,
            'message' => 'Time slot conflicts with existing booking'
        ];
    } else {
        $validation_results[] = [
            'type' => 'booking_overlap',
            'valid' => true,
            'message' => 'No conflicts with existing bookings'
        ];
    }
    
    // 5. Check against session selected slots
    $session_conflict = false;
    if (isset($_SESSION['selected_slots'])) {
        foreach ($_SESSION['selected_slots'] as $selected_slot) {
            if (isset($selected_slot['studio_id']) && $selected_slot['studio_id'] == $studio_id && 
                isset($selected_slot['date']) && $selected_slot['date'] == $date) {
                
                // Handle both possible key formats for backward compatibility
                $selected_start = isset($selected_slot['start_time']) ? $selected_slot['start_time'] : 
                                 (isset($selected_slot['start']) ? $selected_slot['start'] . ':00' : '');
                $selected_end = isset($selected_slot['end_time']) ? $selected_slot['end_time'] : 
                               (isset($selected_slot['end']) ? $selected_slot['end'] . ':00' : '');
                
                // Only check for overlap if we have valid start and end times
                if ($selected_start && $selected_end) {
                    // Check for overlap
                    if (($start_time < $selected_end && $end_time > $selected_start)) {
                        $session_conflict = true;
                        break;
                    }
                }
            }
        }
    }
    
    if ($session_conflict) {
        $validation_results[] = [
            'type' => 'session_conflict',
            'valid' => false,
            'message' => 'Time slot conflicts with already selected slot in session'
        ];
    } else {
        $validation_results[] = [
            'type' => 'session_conflict',
            'valid' => true,
            'message' => 'No conflicts with session selections'
        ];
    }
    
    // 6. Check if booking is in the past or after studio closing
    $today = date('Y-m-d');
    $current_time = date('H:i:s');
    $is_past = false;
    $is_after_closing = false;
    
    // Check if date is in the past
    if ($date < $today) {
        $is_past = true;
    }
    // Check if it's today and time has passed or studio is closed
    else if ($date === $today) {
        if ($start_time <= $current_time) {
            $is_past = true;
        }
        // Check if current time is past studio closing time
        else if ($current_time >= $studio['Time_OUT']) {
            $is_after_closing = true;
        }
    }
    
    if ($is_past) {
        $validation_results[] = [
            'type' => 'past_time',
            'valid' => false,
            'message' => 'Cannot book time slots in the past'
        ];
    } else if ($is_after_closing) {
        $validation_results[] = [
            'type' => 'studio_closed',
            'valid' => false,
            'message' => 'Studio is closed for today'
        ];
    } else {
        $validation_results[] = [
            'type' => 'time_availability',
            'valid' => true,
            'message' => 'Booking time is available'
        ];
    }
    
    // Determine overall validity
    $all_valid = true;
    foreach ($validation_results as $result) {
        if (!$result['valid']) {
            $all_valid = false;
            break;
        }
    }
    
    // Return validation results
    echo json_encode([
        'success' => true,
        'valid' => $all_valid,
        'validation_results' => $validation_results,
        'studio' => [
            'id' => $studio_id,
            'name' => $studio['StudioName'],
            'time_in' => $studio['Time_IN'],
            'time_out' => $studio['Time_OUT']
        ],
        'booking_details' => [
            'date' => $date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'duration_hours' => $duration_hours
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error in validate_booking_availability.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

// Close database connection
mysqli_close($conn);
?>