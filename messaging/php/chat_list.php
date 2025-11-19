<?php
// chat_list.php (smart redirect)
include '../../shared/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/php/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// If partner_id is provided, honor it directly
$partner_override = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : 0;
if ($partner_override > 0) {
    $redirect = 'chat.php?partner_id=' . $partner_override;
    header('Location: ' . $redirect);
    exit();
}

// Otherwise, check for the most recent conversation partner
$stmt = $conn->prepare("SELECT OwnerID, ClientID FROM chatlog
                        WHERE ? IN (OwnerID, ClientID)
                        ORDER BY ChatID DESC
                        LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $partner_id = ($row['OwnerID'] == $user_id) ? (int)$row['ClientID'] : (int)$row['OwnerID'];
    $redirect = 'chat.php?partner_id=' . $partner_id;
    header('Location: ' . $redirect);
} else {
    // If no conversations, go to the unified chat page to start a new one
    header('Location: chat.php');
}

exit();