<?php
session_start();

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Mark all notifications as read by setting count to 0
$_SESSION['unread_notifications'] = 0;

echo json_encode([
    'success' => true,
    'message' => 'All notifications marked as read'
]);
