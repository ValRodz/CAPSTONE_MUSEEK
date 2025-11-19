<?php
session_start();
header('Content-Type: application/json');

// Check if index is provided
if (!isset($_POST['index'])) {
    echo json_encode(['success' => false, 'message' => 'No index provided']);
    exit;
}

$index = (int)$_POST['index'];

// Check if selected_slots exists in session
if (!isset($_SESSION['selected_slots']) || !is_array($_SESSION['selected_slots'])) {
    echo json_encode(['success' => false, 'message' => 'No time slots found in session']);
    exit;
}

// Check if index is valid
if (!isset($_SESSION['selected_slots'][$index])) {
    echo json_encode(['success' => false, 'message' => 'Invalid time slot index']);
    exit;
}

// Remove the time slot at the specified index
array_splice($_SESSION['selected_slots'], $index, 1);

// If no slots left, clear the array
if (empty($_SESSION['selected_slots'])) {
    unset($_SESSION['selected_slots']);
}

echo json_encode([
    'success' => true, 
    'message' => 'Time slot removed successfully',
    'remaining_slots' => count($_SESSION['selected_slots'] ?? [])
]);
?>
