<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include '../../shared/config/db pdo.php';

$ownerId = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : 0;
if ($ownerId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'owner_id required']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, question
         FROM studio_chatbot_faq
         WHERE owner_id = ? AND is_active = 1
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([$ownerId]);
    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'faqs' => $faqs]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'message' => $e->getMessage()]);
}