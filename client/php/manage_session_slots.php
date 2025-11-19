<?php
// manage_session_slots.php - Manage selected slots in session for real-time booking
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
$action = isset($input['action']) ? $input['action'] : '';

try {
    switch ($action) {
        case 'add':
            $studio_id = isset($input['studio_id']) ? intval($input['studio_id']) : 0;
            $date = isset($input['date']) ? $input['date'] : '';
            $start_time = isset($input['start_time']) ? $input['start_time'] : '';
            $end_time = isset($input['end_time']) ? $input['end_time'] : '';
            $studio_name = isset($input['studio_name']) ? $input['studio_name'] : '';
            
            // Validate input
            if (!$studio_id || !$date || !$start_time || !$end_time) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                exit();
            }
            
            // Initialize session array if not exists
            if (!isset($_SESSION['selected_slots'])) {
                $_SESSION['selected_slots'] = [];
            }
            
            // Check if slot already exists
            $slot_exists = false;
            foreach ($_SESSION['selected_slots'] as $slot) {
                if ($slot['studio_id'] == $studio_id && 
                    $slot['date'] == $date && 
                    $slot['start_time'] == $start_time) {
                    $slot_exists = true;
                    break;
                }
            }
            
            if (!$slot_exists) {
                $_SESSION['selected_slots'][] = [
                    'studio_id' => $studio_id,
                    'studio_name' => $studio_name,
                    'date' => $date,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'timestamp' => time()
                ];
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Slot added to session',
                'selected_slots' => $_SESSION['selected_slots']
            ]);
            break;
            
        case 'remove':
            $studio_id = isset($input['studio_id']) ? intval($input['studio_id']) : 0;
            $date = isset($input['date']) ? $input['date'] : '';
            $start_time = isset($input['start_time']) ? $input['start_time'] : '';
            
            if (isset($_SESSION['selected_slots'])) {
                $_SESSION['selected_slots'] = array_filter($_SESSION['selected_slots'], function($slot) use ($studio_id, $date, $start_time) {
                    return !($slot['studio_id'] == $studio_id && 
                            $slot['date'] == $date && 
                            $slot['start_time'] == $start_time);
                });
                
                // Re-index array
                $_SESSION['selected_slots'] = array_values($_SESSION['selected_slots']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Slot removed from session',
                'selected_slots' => isset($_SESSION['selected_slots']) ? $_SESSION['selected_slots'] : []
            ]);
            break;
            
        case 'clear':
            $_SESSION['selected_slots'] = [];
            echo json_encode([
                'success' => true,
                'message' => 'All slots cleared from session'
            ]);
            break;
            
        case 'get':
            echo json_encode([
                'success' => true,
                'selected_slots' => isset($_SESSION['selected_slots']) ? $_SESSION['selected_slots'] : []
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Error in manage_session_slots.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

// Close database connection
mysqli_close($conn);
?>