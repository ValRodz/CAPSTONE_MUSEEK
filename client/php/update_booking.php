<?php
// Set session cookie parameters before starting the session
session_set_cookie_params([
    'lifetime' => 1440, // 24 minutes
    'path' => '/',
    'secure' => false, // Set to true if using HTTPS, false for localhost
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
include __DIR__ . '/../../shared/config/db.php';

// Set content type to JSON
header('Content-Type: application/json');

// Debug logging
error_log("=== UPDATE BOOKING DEBUG START ===");
error_log("Session data: " . json_encode($_SESSION));
error_log("POST data: " . json_encode($_POST));

// Check if user is authenticated and is a client
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'client') {
    echo json_encode([
        'success' => false, 
        'error' => 'Unauthorized access. Please log in as a client.',
        'error_code' => 'UNAUTHORIZED'
    ]);
    exit();
}

// Validate required fields
$required_fields = ['booking_id', 'new_date', 'time_slot', 'services_data'];
foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || empty($_POST[$field])) {
        echo json_encode([
            'success' => false, 
            'error' => "Missing required field: $field",
            'error_code' => 'MISSING_FIELD'
        ]);
        exit();
    }
}

$booking_id = (int)$_POST['booking_id'];
$new_date = $_POST['new_date'];
$time_slot = $_POST['time_slot'];
$client_id = $_SESSION['user_id'];

// Parse services and equipment data
$services_data = json_decode($_POST['services_data'], true);
$equipment_data = isset($_POST['equipment_data']) ? json_decode($_POST['equipment_data'], true) : [];

if (!$services_data || !is_array($services_data) || count($services_data) === 0) {
    echo json_encode([
        'success' => false, 
        'error' => 'At least one service must be selected.',
        'error_code' => 'NO_SERVICES'
    ]);
    exit();
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid date format. Please use YYYY-MM-DD format.',
        'error_code' => 'INVALID_DATE_FORMAT'
    ]);
    exit();
}

// Validate time slot format
if (!preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $time_slot)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid time slot format. Please use HH:MM-HH:MM format.',
        'error_code' => 'INVALID_TIME_FORMAT'
    ]);
    exit();
}

// Parse time slot
$time_parts = explode('-', $time_slot);
$start_time = $time_parts[0] . ':00';
$end_time = $time_parts[1] . ':00';

try {
    // Get the old schedule ID before updating
    $old_schedule_query = "SELECT ScheduleID, StudioID FROM bookings WHERE BookingID = ? AND ClientID = ?";
    $stmt = mysqli_prepare($conn, $old_schedule_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "ii", $booking_id, $client_id);
    mysqli_stmt_execute($stmt);
    $old_schedule_result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($old_schedule_result) === 0) {
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => false, 
            'error' => 'Booking not found or cannot be updated.',
            'error_code' => 'BOOKING_NOT_FOUND'
        ]);
        exit();
    }
    
    $booking_row = mysqli_fetch_assoc($old_schedule_result);
    $old_schedule_id = $booking_row['ScheduleID'];
    $studio_id = $booking_row['StudioID'];
    mysqli_stmt_close($stmt);

    // Verify the booking is pending
    $verify_query = "SELECT b.BookingID 
                     FROM bookings b 
                     WHERE b.BookingID = ? AND b.ClientID = ? 
                     AND b.Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = 'Pending')";
    
    $stmt = mysqli_prepare($conn, $verify_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "ii", $booking_id, $client_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) === 0) {
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => false, 
            'error' => 'Only pending bookings can be modified.',
            'error_code' => 'BOOKING_NOT_PENDING'
        ]);
        exit();
    }
    mysqli_stmt_close($stmt);

    // Validate all services belong to the studio
    $service_ids = array_column($services_data, 'service_id');
    $service_placeholders = str_repeat('?,', count($service_ids) - 1) . '?';
    
    $service_query = "SELECT ServiceID, ServiceType, Price 
                      FROM services 
                      WHERE ServiceID IN ($service_placeholders) AND StudioID = ?";
    $stmt = mysqli_prepare($conn, $service_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    $bind_params = array_merge($service_ids, [$studio_id]);
    $types = str_repeat('i', count($service_ids)) . 'i';
    mysqli_stmt_bind_param($stmt, $types, ...$bind_params);
    mysqli_stmt_execute($stmt);
    $service_result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($service_result) !== count($service_ids)) {
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => false, 
            'error' => 'Invalid service(s) selected for this studio.',
            'error_code' => 'INVALID_SERVICE'
        ]);
        exit();
    }
    
    $services_info = [];
    while ($row = mysqli_fetch_assoc($service_result)) {
        $services_info[$row['ServiceID']] = $row;
    }
    mysqli_stmt_close($stmt);

    // Check for schedule conflicts
    $conflict_query = "SELECT ScheduleID FROM schedules 
                       WHERE StudioID = ? AND Sched_Date = ? 
                       AND ((Time_Start < ? AND Time_End > ?) OR (Time_Start < ? AND Time_End > ?))
                       AND ScheduleID NOT IN (SELECT ScheduleID FROM bookings WHERE BookingID = ?)
                       AND Avail_StatsID = 2";
    
    $stmt = mysqli_prepare($conn, $conflict_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "isssssi", $studio_id, $new_date, $end_time, $start_time, $start_time, $end_time, $booking_id);
    mysqli_stmt_execute($stmt);
    $conflict_result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($conflict_result) > 0) {
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => false, 
            'error' => 'Time slot conflict detected. Please choose a different time.',
            'error_code' => 'TIME_CONFLICT'
        ]);
        exit();
    }
    mysqli_stmt_close($stmt);

    // Find or create schedule
    $schedule_query = "SELECT ScheduleID FROM schedules WHERE StudioID = ? AND Sched_Date = ? AND Time_Start = ? AND Time_End = ?";
    $stmt = mysqli_prepare($conn, $schedule_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "isss", $studio_id, $new_date, $start_time, $end_time);
    mysqli_stmt_execute($stmt);
    $schedule_result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($schedule_result) > 0) {
        $schedule_row = mysqli_fetch_assoc($schedule_result);
        $schedule_id = $schedule_row['ScheduleID'];
    } else {
        // Create new schedule with Avail_StatsID = 2 (Booked)
        mysqli_stmt_close($stmt);
        $create_schedule_query = "INSERT INTO schedules (StudioID, Sched_Date, Time_Start, Time_End, Avail_StatsID) VALUES (?, ?, ?, ?, 2)";
        $stmt = mysqli_prepare($conn, $create_schedule_query);
        
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "isss", $studio_id, $new_date, $start_time, $end_time);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to create schedule: ' . mysqli_error($conn));
        }
        
        $schedule_id = mysqli_insert_id($conn);
    }
    mysqli_stmt_close($stmt);

    // Start transaction for data consistency
    mysqli_autocommit($conn, false);
    
    try {
        error_log("=== BOOKING UPDATE TRANSACTION START ===");
        error_log("BookingID: $booking_id, Old ScheduleID: $old_schedule_id, New ScheduleID: $schedule_id");
        
        // Update the booking (only ScheduleID, no ServiceID or InstructorID)
        $update_query = "UPDATE bookings SET ScheduleID = ? WHERE BookingID = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        
        if (!$stmt) {
            throw new Exception('Database prepare error for booking update: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "ii", $schedule_id, $booking_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to update booking: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_close($stmt);
        
        // Delete old booking_services
        $delete_services_query = "DELETE FROM booking_services WHERE BookingID = ?";
        $stmt = mysqli_prepare($conn, $delete_services_query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $booking_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        
        // Delete old booking_equipment (via booking_services)
        $delete_equipment_query = "DELETE be FROM booking_equipment be
                                   INNER JOIN booking_services bs ON be.booking_service_id = bs.booking_service_id
                                   WHERE bs.BookingID = ?";
        $stmt = mysqli_prepare($conn, $delete_equipment_query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $booking_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        
        // Insert new booking_services
        $insert_service_query = "INSERT INTO booking_services (BookingID, ServiceID, InstructorID, service_price) VALUES (?, ?, ?, ?)";
        $stmt_service = mysqli_prepare($conn, $insert_service_query);
        
        if (!$stmt_service) {
            throw new Exception('Failed to prepare service insert: ' . mysqli_error($conn));
        }
        
        $booking_service_ids = [];
        foreach ($services_data as $service) {
            $service_id = (int)$service['service_id'];
            $instructor_id = (int)$service['instructor_id'];
            $service_price = (float)$services_info[$service_id]['Price'];
            
            mysqli_stmt_bind_param($stmt_service, "iiid", $booking_id, $service_id, $instructor_id, $service_price);
            
            if (!mysqli_stmt_execute($stmt_service)) {
                throw new Exception('Failed to insert service: ' . mysqli_error($conn));
            }
            
            $booking_service_ids[$service_id] = mysqli_insert_id($conn);
        }
        mysqli_stmt_close($stmt_service);
        
        // Insert new booking_equipment
        if (!empty($equipment_data)) {
            $insert_equipment_query = "INSERT INTO booking_equipment (booking_service_id, equipment_id, quantity, rental_price) 
                                       SELECT bs.booking_service_id, ?, ?, ea.rental_price
                                       FROM booking_services bs
                                       JOIN equipment_addons ea ON ea.equipment_id = ?
                                       WHERE bs.BookingID = ? AND bs.ServiceID = ?";
            $stmt_equipment = mysqli_prepare($conn, $insert_equipment_query);
            
            if (!$stmt_equipment) {
                throw new Exception('Failed to prepare equipment insert: ' . mysqli_error($conn));
            }
            
            foreach ($equipment_data as $equipment) {
                $equipment_id = (int)$equipment['equipment_id'];
                $quantity = (int)$equipment['quantity'];
                $service_id_for_eq = (int)$equipment['service_id'];
                
                mysqli_stmt_bind_param($stmt_equipment, "iiiii", $equipment_id, $quantity, $equipment_id, $booking_id, $service_id_for_eq);
                
                if (!mysqli_stmt_execute($stmt_equipment)) {
                    throw new Exception('Failed to insert equipment: ' . mysqli_error($conn));
                }
            }
            mysqli_stmt_close($stmt_equipment);
        }
        
        // Handle old schedule availability
        if ($old_schedule_id && $old_schedule_id != $schedule_id) {
            $check_remaining_query = "SELECT COUNT(*) as booking_count FROM bookings WHERE ScheduleID = ?";
            $stmt_check = mysqli_prepare($conn, $check_remaining_query);
            
            if ($stmt_check) {
                mysqli_stmt_bind_param($stmt_check, "i", $old_schedule_id);
                mysqli_stmt_execute($stmt_check);
                $remaining_result = mysqli_stmt_get_result($stmt_check);
                $remaining_row = mysqli_fetch_assoc($remaining_result);
                $remaining_bookings_count = $remaining_row['booking_count'];
                mysqli_stmt_close($stmt_check);
                
                // If old schedule has no remaining bookings, set it to Available
                if ($remaining_bookings_count == 0) {
                    $update_old_schedule_query = "UPDATE schedules SET Avail_StatsID = 1 WHERE ScheduleID = ?";
                    $stmt_update = mysqli_prepare($conn, $update_old_schedule_query);
                    
                    if ($stmt_update) {
                        mysqli_stmt_bind_param($stmt_update, "i", $old_schedule_id);
                        mysqli_stmt_execute($stmt_update);
                        mysqli_stmt_close($stmt_update);
                    }
                }
            }
        }
        
        // Set the new schedule to Booked
        $update_new_schedule_query = "UPDATE schedules SET Avail_StatsID = 2 WHERE ScheduleID = ? AND Avail_StatsID != 2";
        $stmt_update_new = mysqli_prepare($conn, $update_new_schedule_query);
        
        if ($stmt_update_new) {
            mysqli_stmt_bind_param($stmt_update_new, "i", $schedule_id);
            mysqli_stmt_execute($stmt_update_new);
            mysqli_stmt_close($stmt_update_new);
        }
        
        // Commit transaction
        mysqli_commit($conn);
        error_log("=== BOOKING UPDATE TRANSACTION COMMITTED ===");

        error_log("Booking $booking_id updated by ClientID $client_id");
        
        $service_names = array_column($services_info, 'ServiceType');
        
        echo json_encode([
            'success' => true,
            'message' => 'Booking has been successfully updated.',
            'booking_id' => $booking_id,
            'new_service' => implode(', ', $service_names),
            'new_date' => $new_date,
            'new_time' => $time_slot,
            'new_price' => array_sum(array_column($services_info, 'Price'))
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        error_log("=== BOOKING UPDATE TRANSACTION ROLLED BACK ===");
        error_log("Transaction error: " . $e->getMessage());
        throw $e;
    } finally {
        // Restore autocommit
        mysqli_autocommit($conn, true);
    }
    
} catch (Exception $e) {
    error_log("Error updating booking $booking_id: " . $e->getMessage());
    error_log("Exception details: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage(),
        'error_code' => 'DATABASE_ERROR'
    ]);
}

error_log("=== UPDATE BOOKING DEBUG END ===");
mysqli_close($conn);
?>
