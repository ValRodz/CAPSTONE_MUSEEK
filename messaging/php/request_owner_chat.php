<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

include '../../shared/config/db pdo.php';

$ownerId = isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : 0;
$initialMessage = isset($_POST['message']) ? trim((string)$_POST['message']) : '';
$clientId = (int)$_SESSION['user_id'];

if ($ownerId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'owner_id required']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO owner_chat_requests (owner_id, client_id, initial_message)
         VALUES (?, ?, ?)"
    );
    $stmt->execute([$ownerId, $clientId, $initialMessage]);
    $requestId = $pdo->lastInsertId();

    // Optionally add a notification insert here if you have a notifications table

    echo json_encode(['ok' => true, 'request_id' => $requestId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'message' => $e->getMessage()]);
}