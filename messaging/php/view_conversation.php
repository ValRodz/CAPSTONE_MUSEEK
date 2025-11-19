<?php
session_start();
include '../../shared/config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    echo "<script>alert('Unauthorized access.'); window.location.href='login.html';</script>";
    exit;
}

$owner_id = $_SESSION['user_id'];
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if (!$client_id) {
    echo "<p>Invalid client ID.</p>";
    exit;
}

$query = "
    SELECT cl.Content, cl.Timestamp, cl.ClientID, cl.OwnerID, c.Name AS ClientName
    FROM chatlog cl
    JOIN clients c ON cl.ClientID = c.ClientID
    WHERE cl.OwnerID = ? AND cl.ClientID = ?
    ORDER BY cl.Timestamp ASC
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $owner_id, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$messages = [];
while ($row = mysqli_fetch_assoc($result)) {
    $messages[] = $row;
}
mysqli_stmt_close($stmt);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Conversation with Client</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f8f8f8; }
        .message { padding: 10px; margin-bottom: 10px; border-radius: 6px; max-width: 70%; }
        .owner { background: #e1f5fe; align-self: flex-end; }
        .client { background: #fff9c4; align-self: flex-start; }
        .timestamp { font-size: 11px; color: #777; margin-top: 4px; }
        .chat-container { display: flex; flex-direction: column; gap: 10px; }
        .back { margin-bottom: 20px; display: inline-block; }
    </style>
</head>
<body>
    <a class="back" href="owner_messages.php">← Back to Messages</a>
    <h2>Conversation with Client</h2>

    <div class="chat-container">
        <?php if (empty($messages)): ?>
            <p>No messages found with this client.</p>
        <?php else: ?>
            <?php foreach ($messages as $msg): ?>
                <div class="message <?php echo ($msg['ClientID'] == $client_id) ? 'client' : 'owner'; ?>">
                    <?php echo nl2br(htmlspecialchars($msg['Content'])); ?>
                    <div class="timestamp"><?php echo htmlspecialchars($msg['Timestamp']); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
