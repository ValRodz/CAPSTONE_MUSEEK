<?php
// get_studio_services.php - Fetch services and instructors for a specific studio
session_start();

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
    echo json_encode([
        'success' => false, 
        'error' => 'Unauthorized',
        'debug_info' => [
            'session_user_id' => isset($_SESSION['user_id']) ? 'set' : 'not set',
            'session_user_type' => isset($_SESSION['user_type']) ? $_SESSION['user_type'] : 'not set'
        ]
    ]);
    exit();
}

// Get studio ID from POST data
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);

// Enhanced input validation with debugging
if ($input === null) {
    error_log("JSON decode error: " . json_last_error_msg() . " | Raw input: " . $raw_input);
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid JSON input',
        'debug_info' => [
            'json_error' => json_last_error_msg(),
            'raw_input' => $raw_input
        ]
    ]);
    exit();
}

$studio_id = isset($input['studio_id']) ? intval($input['studio_id']) : 0;

if ($studio_id <= 0) {
    error_log("Invalid studio ID provided: " . ($input['studio_id'] ?? 'not set'));
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid studio ID',
        'debug_info' => [
            'provided_studio_id' => $input['studio_id'] ?? 'not provided',
            'parsed_studio_id' => $studio_id
        ]
    ]);
    exit();
}

try {
    // Fetch studio information including TIME_OUT
    $studio_query = "SELECT StudioID, StudioName, OwnerID, Time_IN, Time_OUT FROM studios WHERE StudioID = ?";
    $stmt = mysqli_prepare($conn, $studio_query);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare studio query: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Failed to execute studio query: " . mysqli_stmt_error($stmt));
    }
    
    $studio_result = mysqli_stmt_get_result($stmt);
    $studio = mysqli_fetch_assoc($studio_result);
    
    if (!$studio) {
        error_log("Studio not found for ID: " . $studio_id);
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'error' => 'Studio not found',
            'debug_info' => [
                'studio_id' => $studio_id,
                'query' => $studio_query
            ]
        ]);
        exit();
    }
    mysqli_stmt_close($stmt);

    // Fetch services available in this studio
    $services_query = "
        SELECT DISTINCT s.ServiceID, s.ServiceType, s.Description, s.Price
        FROM services s
        JOIN studio_services ss ON s.ServiceID = ss.ServiceID
        WHERE ss.StudioID = ?
        ORDER BY s.ServiceType
    ";
    $stmt = mysqli_prepare($conn, $services_query);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare services query: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Failed to execute services query: " . mysqli_stmt_error($stmt));
    }
    
    $services_result = mysqli_stmt_get_result($stmt);
    
    $services = [];
    while ($service = mysqli_fetch_assoc($services_result)) {
        $services[] = $service;
    }
    mysqli_stmt_close($stmt);

    // Determine restricted mode: if studio_instructors has any rows for this studio
    $restricted_mode = false;
    $restricted_query = "SELECT COUNT(*) AS cnt FROM studio_instructors WHERE StudioID = ?";
    $stmt = mysqli_prepare($conn, $restricted_query);
    if (!$stmt) { throw new Exception("Failed to prepare restricted check: " . mysqli_error($conn)); }
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    if (!mysqli_stmt_execute($stmt)) { throw new Exception("Failed to execute restricted check: " . mysqli_stmt_error($stmt)); }
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) { $restricted_mode = ((int)$row['cnt'] > 0); }
    mysqli_stmt_close($stmt);

    // Fetch instructors available for this studio, honoring restricted mode
    if ($restricted_mode) {
        // Only instructors explicitly assigned to this studio and matching any offered service
        $instructors_query = "
            SELECT DISTINCT i.InstructorID, i.Name, i.Profession,
                   GROUP_CONCAT(DISTINCT s.ServiceType SEPARATOR ', ') as services_text,
                   GROUP_CONCAT(DISTINCT s.ServiceID SEPARATOR ',') as services
            FROM instructors i
            JOIN studio_instructors si ON si.InstructorID = i.InstructorID AND si.StudioID = ?
            JOIN instructor_services ins ON i.InstructorID = ins.InstructorID
            JOIN services s ON ins.ServiceID = s.ServiceID
            JOIN studio_services ss ON s.ServiceID = ss.ServiceID AND ss.StudioID = si.StudioID
            GROUP BY i.InstructorID, i.Name, i.Profession
            ORDER BY i.Name
        ";
        $stmt = mysqli_prepare($conn, $instructors_query);
        if (!$stmt) { throw new Exception("Failed to prepare restricted instructors query: " . mysqli_error($conn)); }
        mysqli_stmt_bind_param($stmt, "i", $studio_id);
    } else {
        // Automatic mode: any instructor matching services offered by the studio
        $instructors_query = "
            SELECT DISTINCT i.InstructorID, i.Name, i.Profession,
                   GROUP_CONCAT(DISTINCT s.ServiceType SEPARATOR ', ') as services_text,
                   GROUP_CONCAT(DISTINCT s.ServiceID SEPARATOR ',') as services
            FROM instructors i
            JOIN instructor_services ins ON i.InstructorID = ins.InstructorID
            JOIN services s ON ins.ServiceID = s.ServiceID
            JOIN studio_services ss ON s.ServiceID = ss.ServiceID
            WHERE ss.StudioID = ?
            GROUP BY i.InstructorID, i.Name, i.Profession
            ORDER BY i.Name
        ";
        $stmt = mysqli_prepare($conn, $instructors_query);
        if (!$stmt) { throw new Exception("Failed to prepare instructors query: " . mysqli_error($conn)); }
        mysqli_stmt_bind_param($stmt, "i", $studio_id);
    }

    if (!mysqli_stmt_execute($stmt)) { throw new Exception("Failed to execute instructors query: " . mysqli_stmt_error($stmt)); }
    $instructors_result = mysqli_stmt_get_result($stmt);
    $instructors = [];
    while ($instructor = mysqli_fetch_assoc($instructors_result)) {
        if (!empty($instructor['services'])) {
            $instructor['services'] = array_map('intval', explode(',', $instructor['services']));
        } else {
            $instructor['services'] = [];
        }
        $instructors[] = $instructor;
    }
    mysqli_stmt_close($stmt);

    // Log successful data retrieval for debugging
    error_log("Successfully retrieved data for studio ID " . $studio_id . ": " . 
              count($services) . " services, " . count($instructors) . " instructors");

    // Return the data
    echo json_encode([
        'success' => true,
        'studio' => $studio,
        'services' => $services,
        'instructors' => $instructors,
        'restricted_mode' => $restricted_mode,
        'debug_info' => [
            'studio_id' => $studio_id,
            'services_count' => count($services),
            'instructors_count' => count($instructors)
        ]
    ]);

} catch (Exception $e) {
    error_log("Error in get_studio_services.php: " . $e->getMessage() . " | Studio ID: " . $studio_id);
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage(),
        'debug_info' => [
            'studio_id' => $studio_id,
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine()
        ]
    ]);
}

mysqli_close($conn);
?>