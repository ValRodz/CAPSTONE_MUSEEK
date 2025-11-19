<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include '../../shared/config/db pdo.php';

$faqId = 0;
if (isset($_POST['faq_id'])) {
    $faqId = (int)$_POST['faq_id'];
} elseif (isset($_GET['faq_id'])) {
    $faqId = (int)$_GET['faq_id'];
}

if ($faqId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'faq_id required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT answer FROM studio_chatbot_faq WHERE id = ? AND is_active = 1");
    $stmt->execute([$faqId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'answer' => $row ? $row['answer'] : 'No answer available for this question.'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'message' => $e->getMessage()]);
}