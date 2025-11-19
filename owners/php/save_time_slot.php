<?php
session_start();
header('Content-Type: application/json');

// Check if required parameters are provided
if (!isset($_POST['date']) || !isset($_POST['start']) || !isset($_POST['end'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Initialize selected_slots array if it doesn't exist
if (!isset($_SESSION['selected_slots'])) {
    $_SESSION['selected_slots'] = [];
}

// Create a new time slot with proper formatting
$newSlot = [
    'date' => trim($_POST['date']),
    'start' => trim($_POST['start']),
    'end' => trim($_POST['end']),
    'studio_id' => isset($_POST['studio_id']) ? intval($_POST['studio_id']) : 0,
    'service_id' => isset($_POST['service_id']) ? intval($_POST['service_id']) : 0,
    'price_per_hour' => isset($_POST['price_per_hour']) ? floatval($_POST['price_per_hour']) : 0,
    'instructor_id' => isset($_POST['instructor_id']) && !empty($_POST['instructor_id']) ? intval($_POST['instructor_id']) : null
];

// Validate the time slot
if (empty($newSlot['date']) || empty($newSlot['start']) || empty($newSlot['end'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid time slot data']);
    exit;
}

// Check for duplicate time slots
foreach ($_SESSION['selected_slots'] as $index => $slot) {
    if ($slot['date'] === $newSlot['date'] && 
        $slot['start'] === $newSlot['start'] && 
        $slot['end'] === $newSlot['end']) {
        echo json_encode(['success' => false, 'message' => 'This time slot is already selected']);
        exit;
    }
}

// Add the new time slot to the session
$_SESSION['selected_slots'][] = $newSlot;

// Debug output (can be removed in production)
error_log('Saved time slot: ' . print_r($newSlot, true));
error_log('All saved slots: ' . print_r($_SESSION['selected_slots'], true));

echo json_encode([
    'success' => true, 
    'message' => 'Time slot added successfully',
    'slot_count' => count($_SESSION['selected_slots'])
]);
?>
