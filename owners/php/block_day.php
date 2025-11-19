<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include '../../shared/config/db pdo.php';
require_once __DIR__ . '/validation_utils.php';

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Get form data (support both naming conventions used by the UI)
$blockDate = $_POST['block_date'] ?? '';
$studioId = $_POST['block_studio_id'] ?? ($_POST['studio_id'] ?? '');
$blockReason = $_POST['block_reason'] ?? ($_POST['reason'] ?? '');
$otherReason = $_POST['other_reason'] ?? '';

// Validate form data
if (empty($blockDate) || empty($studioId) || empty($blockReason)) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

// If "other" reason is selected AND the field exists, validate it
if (strtolower($blockReason) === 'other' && isset($_POST['other_reason']) && empty($otherReason)) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Please specify the reason']);
    exit();
}

// Determine availability status ID based on reason (normalize strings and IDs)
$availabilityId = 4; // Default to Unavailable
$normalizedReason = strtolower(trim($blockReason));
if ($normalizedReason === '3' || $normalizedReason === 'maintenance') {
    $availabilityId = 3; // Maintenance
} elseif ($normalizedReason === '4' || $normalizedReason === 'unavailable' || $normalizedReason === 'holiday') {
    $availabilityId = 4; // Unavailable/Holiday
} elseif ($normalizedReason === 'other') {
    $availabilityId = 4; // Blocked as Unavailable
} elseif (is_numeric($normalizedReason)) {
    // If a numeric ID sneaks in, use it directly
    $availabilityId = (int)$normalizedReason;
}

// Begin transaction
$pdo->beginTransaction();

try {
    // Helper: insert block if one does not already exist for this studio/date
    $insertBlockForStudio = function($studioRowOrId) use ($pdo, $ownerId, $blockDate, $availabilityId) {
        // Accept either studio row (with Time_IN/Time_OUT) or numeric ID (then fetch row)
        if (is_array($studioRowOrId)) {
            $studioIdLocal = $studioRowOrId['StudioID'];
            $timeIn = $studioRowOrId['Time_IN'];
            $timeOut = $studioRowOrId['Time_OUT'];
        } else {
            $studioStmt = $pdo->prepare("SELECT StudioID, Time_IN, Time_OUT FROM studios WHERE StudioID = ? AND OwnerID = ?");
            $studioStmt->execute([$studioRowOrId, $ownerId]);
            $studio = $studioStmt->fetch(PDO::FETCH_ASSOC);
            if (!$studio) {
                throw new Exception('Invalid studio selected');
            }
            $studioIdLocal = $studio['StudioID'];
            $timeIn = $studio['Time_IN'];
            $timeOut = $studio['Time_OUT'];
        }

        // Prevent duplicate full-day blocks for the same date/studio
        $existsStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM schedules WHERE OwnerID = ? AND StudioID = ? AND Sched_Date = ? AND Avail_StatsID IN (3,4)");
        $existsStmt->execute([$ownerId, $studioIdLocal, $blockDate]);
        $exists = (int)$existsStmt->fetchColumn();
        if ($exists > 0) {
            return; // Already blocked; skip duplicate
        }

        // Create a schedule entry for the entire day
        $insertStmt = $pdo->prepare("\n            INSERT INTO schedules (OwnerID, StudioID, Sched_Date, Time_Start, Time_End, Avail_StatsID)\n            VALUES (?, ?, ?, ?, ?, ?)\n        ");
        $insertStmt->execute([
            $ownerId,
            $studioIdLocal,
            $blockDate,
            $timeIn,
            $timeOut,
            $availabilityId
        ]);
    };

    // If "all studios" is selected, get all studios for this owner
    if ($studioId === 'all') {
        $studioStmt = $pdo->prepare("SELECT StudioID, Time_IN, Time_OUT FROM studios WHERE OwnerID = ?");
        $studioStmt->execute([$ownerId]);
        $studios = $studioStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($studios as $studio) {
            $insertBlockForStudio($studio);
        }
    } else {
        // Block a specific studio
        $insertBlockForStudio($studioId);
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Respond appropriately based on request type (AJAX vs standard form submit)
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $successMessage = "Day blocked for " . date('M d, Y', strtotime($blockDate));
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $successMessage]);
        exit();
    } else {
        // Redirect back to schedule page for non-AJAX submissions
        header("Location: schedule.php?view=monthly&month=" . date('m', strtotime($blockDate)) . "&year=" . date('Y', strtotime($blockDate)) . "&date=" . urlencode($blockDate) . "&toast=" . urlencode($successMessage) . "&status=success");
        exit();
    }
} catch (Exception $e) {
    // Rollback transaction
    $pdo->rollBack();
    
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit();
}
