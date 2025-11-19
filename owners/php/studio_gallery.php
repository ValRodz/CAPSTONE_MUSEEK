<?php
session_start();
header('Content-Type: application/json');
include '../../shared/config/db pdo.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'owner') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$ownerId = (int)$_SESSION['user_id'];
$action = $_REQUEST['action'] ?? 'list';

function ensureStudioOwned(PDO $pdo, int $studioId, int $ownerId): bool {
    $chk = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
    $chk->execute([$studioId, $ownerId]);
    return (bool)$chk->fetch();
}

try {
    if ($action === 'list') {
        $studioId = (int)($_GET['studio_id'] ?? 0);
        if ($studioId <= 0 || !ensureStudioOwned($pdo, $studioId, $ownerId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid studio']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT image_id, file_path, caption, sort_order, uploaded_at FROM studio_gallery WHERE StudioID = ? ORDER BY sort_order ASC, image_id ASC");
        $stmt->execute([$studioId]);
        echo json_encode(['success' => true, 'images' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'upload') {
        $studioId = (int)($_POST['studio_id'] ?? 0);
        if ($studioId <= 0 || !ensureStudioOwned($pdo, $studioId, $ownerId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid studio']);
            exit;
        }
        $files = $_FILES['images'] ?? null;
        if (!$files) {
            echo json_encode(['success' => false, 'message' => 'No files provided']);
            exit;
        }

        $allowedExt = ['jpg','jpeg','png','webp'];
        $maxSize = 10 * 1024 * 1024; // 10MB

        $uploadDir = __DIR__ . '/../../uploads/studios/' . $studioId . '/gallery';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }

        $curMaxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM studio_gallery WHERE StudioID = ?");
        $curMaxStmt->execute([$studioId]);
        $curMax = (int)$curMaxStmt->fetchColumn();

        $count = is_array($files['name']) ? count($files['name']) : 0;
        $inserted = [];

        $ins = $pdo->prepare("INSERT INTO studio_gallery (StudioID, file_path, caption, sort_order) VALUES (?, ?, ?, ?)");

        for ($i = 0; $i < $count; $i++) {
            $name = $files['name'][$i];
            $tmp = $files['tmp_name'][$i];
            $size = (int)$files['size'][$i];
            $error = (int)$files['error'][$i];

            if ($error !== UPLOAD_ERR_OK) { continue; }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) { continue; }
            if ($size > $maxSize) { continue; }

            $unique = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $target = $uploadDir . '/' . $unique;
            $publicPath = 'uploads/studios/' . $studioId . '/gallery/' . $unique;

            if (!move_uploaded_file($tmp, $target)) { continue; }

            $curMax++;
            $ins->execute([$studioId, $publicPath, null, $curMax]);
            $inserted[] = [
                'image_id' => (int)$pdo->lastInsertId(),
                'file_path' => $publicPath,
                'caption' => null,
                'sort_order' => $curMax,
            ];
        }

        echo json_encode(['success' => true, 'uploaded' => $inserted]);
        exit;
    }

    if ($action === 'delete') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        if ($imageId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid image']); exit; }

        $sel = $pdo->prepare("SELECT g.file_path, g.StudioID FROM studio_gallery g INNER JOIN studios s ON g.StudioID = s.StudioID WHERE g.image_id = ? AND s.OwnerID = ? LIMIT 1");
        $sel->execute([$imageId, $ownerId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'message' => 'Not found or access denied']); exit; }

        $abs = __DIR__ . '/../../' . $row['file_path'];
        if (!empty($row['file_path']) && file_exists($abs)) { @unlink($abs); }

        $del = $pdo->prepare("DELETE FROM studio_gallery WHERE image_id = ?");
        $del->execute([$imageId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_caption') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $caption = trim($_POST['caption'] ?? '');
        if ($imageId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid image']); exit; }

        $chk = $pdo->prepare("SELECT g.StudioID FROM studio_gallery g INNER JOIN studios s ON g.StudioID = s.StudioID WHERE g.image_id = ? AND s.OwnerID = ? LIMIT 1");
        $chk->execute([$imageId, $ownerId]);
        if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit; }

        $upd = $pdo->prepare("UPDATE studio_gallery SET caption = ? WHERE image_id = ?");
        $upd->execute([$caption, $imageId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reorder') {
        $studioId = (int)($_POST['studio_id'] ?? 0);
        $order = $_POST['order'] ?? [];
        if ($studioId <= 0 || !ensureStudioOwned($pdo, $studioId, $ownerId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid studio']);
            exit;
        }
        if (!is_array($order)) { echo json_encode(['success' => false, 'message' => 'Invalid order']); exit; }

        $upd = $pdo->prepare("UPDATE studio_gallery SET sort_order = ? WHERE image_id = ? AND StudioID = ?");
        $sort = 0;
        foreach ($order as $imgId) {
            $sort++;
            $upd->execute([$sort, (int)$imgId, $studioId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>