<?php
session_start();
header('Content-Type: application/json');

// Check if it's an AJAX request
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

try {
    // Get parameters from POST or GET
    $data = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $studio_id = isset($data['studio_id']) ? (int)$data['studio_id'] : 0;
    $service_id = isset($data['service_id']) ? (int)$data['service_id'] : 0;

    // Clear all selected slots
    $previous_count = isset($_SESSION['selected_slots']) ? count($_SESSION['selected_slots']) : 0;
    $_SESSION['selected_slots'] = [];

    // Prepare response
    $response = [
        'success' => true,
        'message' => 'All time slots have been cleared. Pick a Time Slot.',
        'cleared_count' => $previous_count,
        'redirect_url' => 'booking2.php?studio_id=' . urlencode($studio_id) . '&service_id=' . urlencode($service_id) . '&from_confirm=1'
    ];

} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'success' => false,
        'message' => 'An error occurred while clearing time slots: ' . $e->getMessage()
    ];
}

// Return JSON response
if ($is_ajax) {
    echo json_encode($response);
    exit;
}

// Fallback for non-AJAX requests
if ($response['success']) {
    header('Location: ' . $response['redirect_url']);
} else {
    // Handle error for non-AJAX requests
    echo $response['message'];
}
exit;
?>
