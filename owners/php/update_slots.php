<?php
session_start();
header('Content-Type: application/json');

// Check if request is POST and has JSON data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Validate required fields
if (!isset($data['slots']) || !is_array($data['slots']) || 
    !isset($data['studio_id']) || !isset($data['service_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // Clear existing slots
    $_SESSION['selected_slots'] = [];
    
    // Process and validate each slot
    foreach ($data['slots'] as $slot) {
        if (!isset($slot['date']) || !isset($slot['start']) || !isset($slot['end'])) {
            continue; // Skip invalid slots
        }
        
        // Validate date and times
        $date = trim($slot['date']);
        $start = trim($slot['start']);
        $end = trim($slot['end']);
        
        if (empty($date) || empty($start) || empty($end)) {
            continue; // Skip empty values
        }
        
        // Add to session
        $_SESSION['selected_slots'][] = [
            'studio_id' => intval($data['studio_id']),
            'service_id' => intval($data['service_id']),
            'instructor_id' => isset($data['instructor_id']) ? intval($data['instructor_id']) : 0,
            'date' => $date,
            'start' => $start,
            'end' => $end,
            'price_per_hour' => isset($slot['price_per_hour']) ? floatval($slot['price_per_hour']) : 0
        ];
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Time slots updated successfully',
        'slot_count' => count($_SESSION['selected_slots'])
    ]);
    
} catch (Exception $e) {
    error_log('Error updating time slots: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while updating time slots'
    ]);
}
?>
