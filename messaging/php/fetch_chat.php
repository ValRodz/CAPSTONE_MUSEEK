<?php
session_start();
include '../../shared/config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Get the other party's ID
$owner_id = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : 0;
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if ($user_type === 'client') {
    $client_id = $user_id;
    if (!$owner_id) {
        echo json_encode(['success' => false, 'error' => 'Missing owner_id']);
        exit;
    }
} elseif ($user_type === 'owner') {
    $owner_id = $user_id;
    if (!$client_id) {
        // Graceful fallback: for owners without a selected client, use the most recent conversation
        $stmtLast = mysqli_prepare($conn, "SELECT ClientID FROM chatlog WHERE OwnerID = ? ORDER BY ChatID DESC LIMIT 1");
        mysqli_stmt_bind_param($stmtLast, 'i', $owner_id);
        if ($stmtLast) {
            mysqli_stmt_execute($stmtLast);
            $resLast = mysqli_stmt_get_result($stmtLast);
            $rowLast = $resLast ? mysqli_fetch_assoc($resLast) : null;
            mysqli_stmt_close($stmtLast);
            if ($rowLast && !empty($rowLast['ClientID'])) {
                $client_id = (int)$rowLast['ClientID'];
            } else {
                // No conversation found; return empty messages instead of an error
                echo json_encode(['success' => true, 'messages' => []]);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to select recent conversation']);
            exit;
        }
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid user type']);
    exit;
}

$query = "SELECT * FROM chatlog WHERE OwnerID = ? AND ClientID = ? ORDER BY Timestamp ASC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $owner_id, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$messages = [];
while ($row = mysqli_fetch_assoc($result)) {
    $messages[] = $row;
}
mysqli_stmt_close($stmt);

echo json_encode(['success' => true, 'messages' => $messages]);
