<?php
session_start();

// Set timezone to Philippine Time
date_default_timezone_set('Asia/Manila');

include '../../shared/config/db.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'client') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get booking ID
$data = json_decode(file_get_contents('php://input'), true);
$bookingId = isset($data['booking_id']) ? intval($data['booking_id']) : 0;
$clientId = intval($_SESSION['user_id']);

if ($bookingId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid booking ID']);
    exit;
}

try {
    // Verify booking belongs to this client and get studio details
    $verify_query = "SELECT b.BookingID, b.StudioID, s.StudioName, s.Time_IN, s.Time_OUT 
                     FROM bookings b 
                     JOIN studios s ON b.StudioID = s.StudioID
                     WHERE b.BookingID = ? AND b.ClientID = ?";
    $stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($stmt, "ii", $bookingId, $clientId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $booking = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$booking) {
        echo json_encode(['success' => false, 'error' => 'Booking not found']);
        exit;
    }
    
    $studioId = $booking['StudioID'];
    $studioData = [
        'StudioID' => $booking['StudioID'],
        'StudioName' => $booking['StudioName'],
        'Time_IN' => $booking['Time_IN'],
        'Time_OUT' => $booking['Time_OUT']
    ];
    
    // Get owner ID for this studio (matching booking.php logic)
    $owner_query = "SELECT so.OwnerID FROM studio_owners so 
                    JOIN studios st ON so.OwnerID = st.OwnerID 
                    WHERE st.StudioID = ?";
    $stmt = mysqli_prepare($conn, $owner_query);
    mysqli_stmt_bind_param($stmt, "i", $studioId);
    mysqli_stmt_execute($stmt);
    $owner_result = mysqli_stmt_get_result($stmt);
    $owner_row = mysqli_fetch_assoc($owner_result);
    $owner_id = $owner_row ? $owner_row['OwnerID'] : 0;
    mysqli_stmt_close($stmt);
    
    // Fetch all services for the studio (matching booking.php structure)
    $services_query = "SELECT se.ServiceID, se.ServiceType, se.Description, se.Price
                      FROM studio_services ss
                      LEFT JOIN services se ON ss.ServiceID = se.ServiceID
                      WHERE ss.StudioID = ?
                      ORDER BY se.ServiceType";
    $stmt = mysqli_prepare($conn, $services_query);
    mysqli_stmt_bind_param($stmt, "i", $studioId);
    mysqli_stmt_execute($stmt);
    $services_result = mysqli_stmt_get_result($stmt);
    
    $services = [];
    while ($row = mysqli_fetch_assoc($services_result)) {
        $services[$row['ServiceID']] = [
            'ServiceID' => $row['ServiceID'],
            'ServiceType' => $row['ServiceType'],
            'Description' => $row['Description'],
            'Price' => $row['Price'],
            'Instructors' => []
        ];
    }
    mysqli_stmt_close($stmt);
    
    // Determine if studio restricts instructors via studio_instructors mapping (matching booking.php)
    $restricted = false;
    $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM studio_instructors WHERE StudioID = ?");
    if ($cntStmt) {
        mysqli_stmt_bind_param($cntStmt, "i", $studioId);
        mysqli_stmt_execute($cntStmt);
        $cntRes = mysqli_stmt_get_result($cntStmt);
        if ($cntRes && ($cntRow = mysqli_fetch_assoc($cntRes))) {
            $restricted = ((int)$cntRow['cnt']) > 0;
        }
        mysqli_stmt_close($cntStmt);
    }
    
    // Fetch instructors for this studio considering restriction (matching booking.php logic)
    if ($restricted) {
        $instructors_query = "
            SELECT DISTINCT i.InstructorID, i.Name AS InstructorName, s.ServiceID, i.Availability
            FROM studio_instructors si
            JOIN instructors i ON i.InstructorID = si.InstructorID
            JOIN instructor_services ins ON ins.InstructorID = i.InstructorID
            JOIN services s ON s.ServiceID = ins.ServiceID
            JOIN studio_services ss ON ss.ServiceID = s.ServiceID AND ss.StudioID = si.StudioID
            WHERE si.StudioID = ? AND i.OwnerID = ? AND i.Availability = 'Avail'
        ";
        $stmt = mysqli_prepare($conn, $instructors_query);
        mysqli_stmt_bind_param($stmt, "ii", $studioId, $owner_id);
    } else {
        $instructors_query = "
            SELECT DISTINCT i.InstructorID, i.Name AS InstructorName, s.ServiceID, i.Availability
            FROM instructors i
            JOIN instructor_services ins ON ins.InstructorID = i.InstructorID
            JOIN services s ON s.ServiceID = ins.ServiceID
            JOIN studio_services ss ON ss.ServiceID = s.ServiceID
            WHERE ss.StudioID = ? AND i.OwnerID = ? AND i.Availability = 'Avail'
        ";
        $stmt = mysqli_prepare($conn, $instructors_query);
        mysqli_stmt_bind_param($stmt, "ii", $studioId, $owner_id);
    }
    
    mysqli_stmt_execute($stmt);
    $instructors_result = mysqli_stmt_get_result($stmt);
    
    // Organize instructors by service (matching booking.php structure)
    while ($instructor_row = mysqli_fetch_assoc($instructors_result)) {
        if (isset($services[$instructor_row['ServiceID']])) {
            $services[$instructor_row['ServiceID']]['Instructors'][] = [
                'InstructorID' => $instructor_row['InstructorID'],
                'InstructorName' => $instructor_row['InstructorName']
            ];
        }
    }
    mysqli_stmt_close($stmt);
    
    // Fetch equipment for each service (matching booking.php structure)
    $equipment_by_service = [];
    foreach ($services as $service_id => $service_data) {
        $equipment_query = "SELECT equipment_id, equipment_name, description, rental_price, quantity_available, equipment_image
                           FROM equipment_addons
                           WHERE service_id = ? AND is_available = 1
                           ORDER BY equipment_name";
        $eq_stmt = mysqli_prepare($conn, $equipment_query);
        mysqli_stmt_bind_param($eq_stmt, "i", $service_id);
        mysqli_stmt_execute($eq_stmt);
        $eq_result = mysqli_stmt_get_result($eq_stmt);
        
        $equipment_by_service[$service_id] = [];
        while ($eq_row = mysqli_fetch_assoc($eq_result)) {
            $equipment_by_service[$service_id][] = [
                'equipment_id' => $eq_row['equipment_id'],
                'equipment_name' => $eq_row['equipment_name'],
                'description' => $eq_row['description'],
                'rental_price' => $eq_row['rental_price'],
                'quantity_available' => $eq_row['quantity_available'],
                'equipment_image' => $eq_row['equipment_image']
            ];
        }
        mysqli_stmt_close($eq_stmt);
    }
    
    // Fetch current booking services
    $current_services_query = "SELECT bs.ServiceID, bs.InstructorID, bs.service_price,
                                      s.ServiceType
                               FROM booking_services bs
                               JOIN services s ON bs.ServiceID = s.ServiceID
                               WHERE bs.BookingID = ?";
    $stmt = mysqli_prepare($conn, $current_services_query);
    mysqli_stmt_bind_param($stmt, "i", $bookingId);
    mysqli_stmt_execute($stmt);
    $current_services_result = mysqli_stmt_get_result($stmt);
    
    $current_services = [];
    while ($row = mysqli_fetch_assoc($current_services_result)) {
        $current_services[] = $row;
    }
    mysqli_stmt_close($stmt);
    
    // Fetch current booking equipment
    $current_equipment_query = "SELECT be.equipment_id, be.quantity, be.rental_price,
                                       ea.equipment_name, bs.ServiceID
                                FROM booking_equipment be
                                JOIN booking_services bs ON be.booking_service_id = bs.booking_service_id
                                JOIN equipment_addons ea ON be.equipment_id = ea.equipment_id
                                WHERE bs.BookingID = ?";
    $stmt = mysqli_prepare($conn, $current_equipment_query);
    mysqli_stmt_bind_param($stmt, "i", $bookingId);
    mysqli_stmt_execute($stmt);
    $current_equipment_result = mysqli_stmt_get_result($stmt);
    
    $current_equipment = [];
    while ($row = mysqli_fetch_assoc($current_equipment_result)) {
        $key = $row['ServiceID'] . '_' . $row['equipment_id'];
        $current_equipment[$key] = [
            'equipment_id' => $row['equipment_id'],
            'quantity' => $row['quantity'],
            'rental_price' => $row['rental_price'],
            'equipment_name' => $row['equipment_name']
        ];
    }
    mysqli_stmt_close($stmt);
    
    // Convert services associative array to indexed array for JSON (but keep structure)
    $services_list = array_values($services);
    
    echo json_encode([
        'success' => true,
        'studio' => $studioData,  // Include studio data for time slots
        'services' => $services,  // Keep as associative array for easy lookup
        'services_list' => $services_list,  // Also provide as list for iteration
        'equipment_by_service' => $equipment_by_service,
        'current_services' => $current_services,
        'current_equipment' => $current_equipment
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

mysqli_close($conn);
?>

