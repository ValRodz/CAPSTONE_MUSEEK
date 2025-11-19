<?php
// chat.php
include '../../shared/config/db.php';
include '../../shared/config/db pdo.php';
include '../../shared/config/path_config.php';


session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/php/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Get the conversation partner ID
$partner_id = isset($_GET['partner_id']) ? intval($_GET['partner_id']) : 0;
$empty_state = false;

// If no partner is specified, auto-select the latest conversation
if ($partner_id === 0) {
    $stmt = $conn->prepare("SELECT ChatID, OwnerID, ClientID FROM chatlog WHERE ? IN (OwnerID, ClientID) ORDER BY ChatID DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        $partner_id = ($row['OwnerID'] == $user_id) ? (int)$row['ClientID'] : (int)$row['OwnerID'];
        header("Location: chat.php?partner_id=" . $partner_id);
        exit();
    } else {
        // No prior conversations: render empty chat state without owner selection
        $empty_state = true;
    }
}

// Verify that the partner exists
if (!$empty_state) {
    if ($user_type == 'client') {
        // If current user is client, partner is studio owner
        $stmt = $conn->prepare("SELECT OwnerID as id, Name as name, 'owner' as user_type FROM studio_owners WHERE OwnerID = ?");
    } else {
        // If current user is studio owner, partner is client
        $stmt = $conn->prepare("SELECT ClientID as id, Name as name, 'client' as user_type FROM clients WHERE ClientID = ?");
    }
    $stmt->bind_param("i", $partner_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Partner not found
        header('Location: chat_list.php');
        exit();
    }

    $partner = $result->fetch_assoc();
    // Studio-aware context
    $owner_id = ($user_type == 'client') ? $partner_id : $user_id;
    $client_id = ($user_type == 'client') ? $user_id : $partner_id;

    // Studio context removed from messaging; default to general conversation
    $studio_id = 0;
    $studio = null;

    $owner_name = '';
    if ($user_type === 'client') {
        $owner_name = $partner['name'];
    } else {
        $stmt = $conn->prepare("SELECT Name FROM studio_owners WHERE OwnerID = ?");
        $stmt->bind_param("i", $owner_id);
        $stmt->execute();
        $owner_res = $stmt->get_result();
        $owner_row = $owner_res ? $owner_res->fetch_assoc() : null;
        $owner_name = $owner_row ? $owner_row['Name'] : 'Studio Owner';
    }

    // No FAQ DB available in this environment. Use a simple system greeting behavior instead.
    $faqs = [];

    // Since there's no IsRead column in the chatlog table, we'll use an alternative approach
    // Track the timestamp when client last viewed this conversation
    $_SESSION['last_viewed_' . $partner_id] = date('Y-m-d H:i:s');

    // Fetch conversation history (no StudioID filtering)
    $stmt = $conn->prepare("SELECT * FROM chatlog
                            WHERE (OwnerID = ? AND ClientID = ?) OR (OwnerID = ? AND ClientID = ?)
                            ORDER BY Timestamp ASC");
    $stmt->bind_param("iiii", $owner_id, $client_id, $owner_id, $client_id);
    $stmt->execute();
    $messages_result = $stmt->get_result();
    $messages = [];
    while ($row = $messages_result->fetch_assoc()) {
        $messages[] = $row;
    }
} else {
    // Defaults for empty-state (no partner selected)
    $partner = ['id' => 0, 'name' => 'No owner selected', 'user_type' => ($user_type == 'client' ? 'owner' : 'client')];
    $owner_id = null;
    $client_id = $user_id;
    $studio_id = 0;
    $studio = null;
    $owner_name = 'Start a Conversation';
    $faqs = [];
    $messages = [];
}

// Per-request greeting behavior for clients: send a system greeting once per 24 hours
if (false) { // Disabled: no auto system message on GET; moved to first client message of the day
    $studioName = $studio && !empty($studio['StudioName']) ? $studio['StudioName'] : $owner_name;
    $greetingText = "Good Day! Welcome to " . $studioName . " How may I be of service today?";

    if ($studio_id) {
        $stmtG = $conn->prepare("SELECT COUNT(*) AS cnt FROM chatlog WHERE OwnerID = ? AND ClientID = ? AND StudioID = ? AND Sender_Type = 'System' AND Content = ? AND Timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $stmtG->bind_param("iiis", $owner_id, $client_id, $studio_id, $greetingText);
    } else {
        $stmtG = $conn->prepare("SELECT COUNT(*) AS cnt FROM chatlog WHERE OwnerID = ? AND ClientID = ? AND Sender_Type = 'System' AND Content = ? AND Timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $stmtG->bind_param("iis", $owner_id, $client_id, $greetingText);
    }
    $stmtG->execute();
    $resG = $stmtG->get_result();
    $rowG = $resG ? $resG->fetch_assoc() : null;
    $already = $rowG && ((int)$rowG['cnt'] > 0);

    if (!$already) {
        $sender_type_sys = 'System';
        if ($studio_id > 0) {
            $stmtIns = $conn->prepare("INSERT INTO chatlog (OwnerID, ClientID, StudioID, Content, Sender_Type) VALUES (?, ?, ?, ?, ?)");
            $stmtIns->bind_param("iiiss", $owner_id, $client_id, $studio_id, $greetingText, $sender_type_sys);
        } else {
            $stmtIns = $conn->prepare("INSERT INTO chatlog (OwnerID, ClientID, Content, Sender_Type) VALUES (?, ?, ?, ?)");
            $stmtIns->bind_param("iiss", $owner_id, $client_id, $greetingText, $sender_type_sys);
        }
        $stmtIns->execute();

        // mark session to avoid duplicate UI bubble
        $_SESSION['chat_session_sys_sent_' . $owner_id . '_' . $client_id] = true;

        header("Location: chat.php?partner_id=" . $partner_id);
        exit();
    }
}

// No FAQ selection handling — removed because the FAQ table does not exist in this environment.

// Process new message submission
if (!$empty_state && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message_text = trim($_POST['message']);
    $sender_type = ($user_type == 'client') ? 'Client' : 'Owner';

    if (!empty($message_text)) {
        // Detect if this is the client's first message in this conversation
        $isClientFirst = false;
        if ($sender_type === 'Client') {
            $stmtFirst = $conn->prepare("SELECT COUNT(*) AS cnt FROM chatlog WHERE OwnerID = ? AND ClientID = ? AND Sender_Type = 'Client' AND DATE(Timestamp) = CURDATE()");
            $stmtFirst->bind_param("ii", $owner_id, $client_id);
            $stmtFirst->execute();
            $resFirst = $stmtFirst->get_result();
            $rowFirst = $resFirst ? $resFirst->fetch_assoc() : null;
            $isClientFirst = ($rowFirst && (int)$rowFirst['cnt'] === 0);
        }

        // Insert the user's message
        $stmt = $conn->prepare("INSERT INTO chatlog (OwnerID, ClientID, Content, Sender_Type) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $owner_id, $client_id, $message_text, $sender_type);

        if ($stmt->execute()) {
            // Good Day greeting only on the client's first message of the day
            if ($isClientFirst) {
                $studioName = $studio && !empty($studio['StudioName']) ? $studio['StudioName'] : $owner_name;
                $sysText = "Good Day! Welcome to " . $studioName . " How may I be of service today?";

                $sender_type_sys = 'System';
                $stmtSys = $conn->prepare("INSERT INTO chatlog (OwnerID, ClientID, Content, Sender_Type) VALUES (?, ?, ?, ?)");
                $stmtSys->bind_param("iiss", $owner_id, $client_id, $sysText, $sender_type_sys);
                $stmtSys->execute();
            }

            // If client replies to the chatbot, send a follow-up wait message
            if ($sender_type === 'Client') {
                $stmtPrev = $conn->prepare("SELECT ChatID, Sender_Type, Content, Timestamp FROM chatlog WHERE OwnerID = ? AND ClientID = ? ORDER BY ChatID DESC LIMIT 2");
                $stmtPrev->bind_param("ii", $owner_id, $client_id);
                $stmtPrev->execute();
                $resPrev = $stmtPrev->get_result();
                $rowsPrev = [];
                while ($r = $resPrev->fetch_assoc()) { $rowsPrev[] = $r; }
                if (count($rowsPrev) >= 2) {
                    $prev = $rowsPrev[1];
                    if (strtolower(trim($prev['Sender_Type'])) === 'system' && stripos($prev['Content'], 'Good Day') !== false) {
                        // Guard: avoid duplicate wait messages after the same Good Day
                        $alreadyWait = false;
                        $stmtChk = $conn->prepare("SELECT COUNT(*) AS cnt FROM chatlog WHERE OwnerID = ? AND ClientID = ? AND Sender_Type = 'System' AND Content LIKE 'Please wait for a while,%' AND Timestamp > ?");
                        $stmtChk->bind_param("iis", $owner_id, $client_id, $prev['Timestamp']);
                        $stmtChk->execute();
                        $resChk = $stmtChk->get_result();
                        $rowChk = $resChk ? $resChk->fetch_assoc() : null;
                        $alreadyWait = $rowChk && ((int)$rowChk['cnt'] > 0);

                        if (!$alreadyWait) {
                            $waitText = 'Please wait for a while, the studio owner will message you shortly.';
                            $sender_type_sys2 = 'System';
                            $stmtWait = $conn->prepare("INSERT INTO chatlog (OwnerID, ClientID, Content, Sender_Type) VALUES (?, ?, ?, ?)");
                            $stmtWait->bind_param("iiss", $owner_id, $client_id, $waitText, $sender_type_sys2);
                            $stmtWait->execute();
                            
                            // Insert notification for owner that client is waiting
                            try {
                                // Get client name for the notification message
                                $clientStmt = $conn->prepare("SELECT Name FROM clients WHERE ClientID = ?");
                                $clientStmt->bind_param("i", $client_id);
                                $clientStmt->execute();
                                $clientResult = $clientStmt->get_result();
                                $clientData = $clientResult->fetch_assoc();
                                $clientName = $clientData ? $clientData['Name'] : 'A client';
                                
                                $notificationMessage = $clientName . ' is waiting for your response. Click to start chatting.';
                                $notifStmt = $pdo->prepare("
                                    INSERT INTO notifications (OwnerID, ClientID, Type, Message, RelatedID, For_User, Created_At, IsRead) 
                                    VALUES (?, ?, 'new_message', ?, ?, 'Owner', NOW(), 0)
                                ");
                                $notifStmt->execute([$owner_id, $client_id, $notificationMessage, $client_id]);
                            } catch (Exception $e) {
                                // Log error but don't break chat flow
                                error_log("Failed to create notification: " . $e->getMessage());
                            }
                        }
                    }
                }
            }

            // Refresh the page to show the new messages
            header("Location: chat.php?partner_id=$partner_id");
            exit();
        }
    }
}

// Get recent conversations for the sidebar (grouped by Owner)
if ($user_type == 'client') {
    // Client: list conversations by owner only
    $stmt = $conn->prepare("SELECT o.OwnerID as id, o.Name as name, 'owner' as user_type,
                        (SELECT Content FROM chatlog
                          WHERE OwnerID = o.OwnerID AND ClientID = ?
                          ORDER BY ChatID DESC LIMIT 1) as last_message,
                        (SELECT Timestamp FROM chatlog
                         WHERE OwnerID = o.OwnerID AND ClientID = ?
                         ORDER BY ChatID DESC LIMIT 1) as last_message_time
                        FROM chatlog cl
                        JOIN studio_owners o ON o.OwnerID = cl.OwnerID
                        WHERE cl.ClientID = ?
                        GROUP BY o.OwnerID
                        ORDER BY last_message_time DESC");
    $stmt->bind_param("iii", $user_id, $user_id, $user_id);
}
$stmt->execute();
$conversations_result = $stmt->get_result();
$conversations = [];
while ($row = $conversations_result->fetch_assoc()) {
    // Calculate unread count based on session tracking
    $conv_partner_id = $row['id'];
    $last_viewed = isset($_SESSION['last_viewed_' . $conv_partner_id]) ? $_SESSION['last_viewed_' . $conv_partner_id] : '1970-01-01 00:00:00';
    
    // Count messages from Owner (Sender_Type = 'Owner' or 'System') sent after last viewed time
    $unread_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM chatlog 
                                    WHERE OwnerID = ? AND ClientID = ? 
                                    AND Sender_Type IN ('Owner', 'System') 
                                    AND Timestamp > ?");
    $unread_stmt->bind_param("iis", $conv_partner_id, $user_id, $last_viewed);
    $unread_stmt->execute();
    $unread_result = $unread_stmt->get_result();
    $unread_row = $unread_result->fetch_assoc();
    $row['unread_count'] = $unread_row ? (int)$unread_row['unread_count'] : 0;
    
    $conversations[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat • <?php echo $empty_state ? 'Start a Conversation' : ($studio ? htmlspecialchars($studio['StudioName']) . ' • ' : '') . htmlspecialchars($partner['name']); ?></title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        /* Modern CSS Variables for consistent theming */
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --secondary-color: #3b82f6;
            --background-dark: #0f0f0f;
            --background-card: rgba(20, 20, 20, 0.95);
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --border-color: #333333;
            --shadow-medium: 0 4px 16px rgba(0, 0, 0, 0.4);
            --border-radius: 12px;
            --border-radius-small: 8px;
            --transition: all 0.3s ease;
        }

        body,
        main {
            background: linear-gradient(135deg, rgba(15, 15, 15, 0.9), rgba(30, 30, 30, 0.8)),
                url('../../shared/assets/images/dummy/slide-1.jpg') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary);
            margin: 0;
        }

        .chat-page-container {
            width: 100%;
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 16px;
            box-sizing: border-box;
        }

        .chat-container {
            display: flex;
            height: clamp(600px, 80vh, 1000px);
            border-radius: var(--border-radius);
            overflow: hidden;
            background-color: var(--background-card);
            box-shadow: var(--shadow-medium);
            margin: 0;
        }

        /* Responsive container sizing */
        @media (max-width: 1200px) {
            .chat-page-container { max-width: 95vw; margin: 16px auto; padding: 0 12px; }
            .chat-container { height: clamp(560px, 78vh, 900px); }
        }

        @media (max-width: 768px) {
            .chat-page-container { margin: 12px auto; padding: 0 10px; }
            .chat-container { height: auto; min-height: 70vh; }
        }

        .chat-sidebar {
            width: 300px;
            border-right: 1px solid var(--border-color);
            overflow-y: auto;
            background-color: rgba(25, 25, 25, 0.95);
        }

        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            background-color: rgba(30, 30, 30, 0.95);
        }
        .chat-header-content { display: flex; align-items: center; gap: 12px; }
        .chat-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), #ff3b3b); display: flex; align-items: center; justify-content: center; color: #fff; box-shadow: var(--shadow-medium); }
        .chat-title-group { display: flex; flex-direction: column; }
        .chat-title { font-size: 1.1rem; font-weight: 600; color: var(--text-primary); }
        .title-separator { color: var(--text-secondary); margin: 0 6px; }
        .studio-chip { display: inline-block; padding: 3px 8px; border-radius: 999px; background: rgba(229,9,20,0.15); border: 1px solid rgba(229,9,20,0.3); color: var(--text-primary); font-size: 0.85rem; }
        .chat-subtitle { font-size: 0.85rem; color: var(--text-secondary); margin-top: 3px; }
        .chat-messages {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            background-color: rgba(35, 35, 35, 0.95);
        }

        .chat-input {
            padding: 15px;
            border-top: 1px solid var(--border-color);
            background-color: rgba(30, 30, 30, 0.95);
        }

        .message {
            margin-bottom: 15px;
            max-width: 70%;
        }

        .message-sent {
            margin-left: auto;
            background-color: var(--secondary-color);
            border-radius: var(--border-radius-small) 0 var(--border-radius-small) var(--border-radius-small);
            padding: 12px;
            color: var(--text-primary);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.25);
        }

        .message-received {
            margin-right: auto;
            background-color: rgba(50, 50, 50, 0.95);
            border-radius: 0 var(--border-radius-small) var(--border-radius-small) var(--border-radius-small);
            padding: 12px;
            color: var(--text-primary);
            box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        }

        .message-received.message-owner {
            background: linear-gradient(135deg, rgba(229, 9, 20, 0.15), rgba(229, 9, 20, 0.08));
            border: 1px solid rgba(229, 9, 20, 0.3);
            box-shadow: 0 6px 20px rgba(229, 9, 20, 0.2);
        }

        .message-received.message-system {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.15), rgba(255, 193, 7, 0.08));
            border: 1px solid rgba(255, 193, 7, 0.3);
            box-shadow: 0 6px 20px rgba(255, 193, 7, 0.15);
            font-style: italic;
        }

        .message-content { line-height: 1.5; word-wrap: break-word; }
        .message-meta {
            font-size: 0.7em;
            color: var(--text-secondary);
            margin-top: 2px;
            text-align: right;
        }

        .conversation-item {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: var(--transition);
        }

        .conversation-item:hover {
            background-color: rgba(40, 40, 40, 0.95);
        }

        .conversation-item.active {
            background-color: rgba(45, 45, 45, 0.95);
            border-left: 3px solid var(--primary-color);
        }

        .conversation-name {
            font-weight: bold;
            margin-bottom: 5px;
            color: var(--text-primary);
        }

        .conversation-preview {
            font-size: 0.9em;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 5px;
        }

        .conversation-time {
            font-size: 0.8em;
            color: var(--text-secondary);
        }
        
        /* Form styling */
        .chat-form {
            display: flex;
            gap: 10px;
        }
        
        .chat-input input[type="text"] {
            flex: 1;
            padding: 12px;
            border-radius: var(--border-radius-small);
            border: 1px solid var(--border-color);
            background-color: rgba(45, 45, 45, 0.95);
            color: var(--text-primary);
        }
        
        .chat-input button {
            padding: 10px 20px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .chat-input button:hover {
            background-color: var(--primary-hover);
        }

        .unread-badge {
            background: linear-gradient(135deg, #ff5722, #e50914);
            color: white;
            border-radius: 50%;
            padding: 3px 8px;
            font-size: 0.75em;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(255, 87, 34, 0.5);
            animation: pulse-badge 2s infinite;
        }

        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); box-shadow: 0 2px 8px rgba(255, 87, 34, 0.5); }
            50% { transform: scale(1.1); box-shadow: 0 4px 12px rgba(255, 87, 34, 0.8); }
        }

        .conversation-item.has-unread {
            background-color: rgba(229, 9, 20, 0.05);
            border-left: 3px solid var(--primary-color);
        }

        .conversation-item.has-unread:hover {
            background-color: rgba(229, 9, 20, 0.1);
        }

        .message-form {
            display: flex;
            gap: 10px;
        }

        .message-input {
            flex: 1;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-small);
            background-color: rgba(45, 45, 45, 0.95);
            color: var(--text-primary);
            resize: none;
        }

        .send-button {
            padding: 10px 20px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            transition: var(--transition);
        }

        .send-button:hover {
            background-color: var(--primary-hover);
        }

        /* FAQ panel styling */
        .faq-panel { padding: 12px 15px; border-bottom: 1px solid var(--border-color); background-color: rgba(30, 30, 30, 0.95); }
        .faq-title { font-weight: 600; margin-bottom: 8px; color: var(--text-primary); display:flex; align-items:center; gap:8px; }
        .faq-list { display: flex; flex-wrap: wrap; gap: 8px; }
        .faq-item { margin: 0; }
        .faq-button { background: rgba(229,9,20,0.15); color: var(--text-primary); border: 1px solid rgba(229,9,20,0.3); border-radius: 999px; padding: 6px 10px; cursor: pointer; transition: var(--transition); }
        .faq-button:hover { background: rgba(229,9,20,0.25); }
        .chat-input .faq-panel { padding: 10px 12px; border-top: 1px solid var(--border-color); border-bottom: none; }
    </style>
</head>

<body>
    <?php include '../../shared/components/navbar.php'; ?>
    <div class="chat-page-container">
        <main>
            <section class="chat-section">
                <div class="chat-container">
                    <div class="chat-sidebar">
                        <h3 style="padding: 15px; margin: 0; border-bottom: 1px solid var(--border-color); color: var(--text-primary);">Conversations</h3>
                        <?php if (count($conversations) > 0): ?>
                            <?php foreach ($conversations as $conversation): ?>
                                <?php
                                $conv_classes = [];
                                if ($conversation['id'] == $partner_id) {
                                    $conv_classes[] = 'active';
                                }
                                if (isset($conversation['unread_count']) && $conversation['unread_count'] > 0) {
                                    $conv_classes[] = 'has-unread';
                                }
                                ?>
                                <div class="conversation-item <?php echo implode(' ', $conv_classes); ?>" onclick="window.location.href='chat.php?partner_id=<?php echo $conversation['id']; ?>'">
                                    <div class="conversation-name">
                                        <?php echo htmlspecialchars($conversation['name']); ?>
                                        <span class="title-separator">•</span>
                                        <span class="studio-chip">General</span>
                                    </div>
                                    <div class="conversation-preview">
                                        <?php echo htmlspecialchars(substr($conversation['last_message'], 0, 50)) . (strlen($conversation['last_message']) > 50 ? '...' : ''); ?>
                                    </div>
                                    <div class="conversation-meta">
                                        <div class="conversation-time">
                                            <?php
                                            $time = strtotime($conversation['last_message_time']);
                                            $now = time();
                                            $diff = $now - $time;

                                            if ($diff < 60) {
                                                echo "Just now";
                                            } elseif ($diff < 3600) {
                                                echo floor($diff / 60) . "m ago";
                                            } elseif ($diff < 86400) {
                                                echo floor($diff / 3600) . "h ago";
                                            } else {
                                                echo date('M j', $time);
                                            }
                                            ?>
                                        </div>
                                        <?php if ($conversation['unread_count'] > 0): ?>
                                            <div class="unread-badge"><?php echo $conversation['unread_count']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 15px; color: #777;">No conversations yet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="chat-main">
                        <div class="chat-header">
                            <?php if ($empty_state): ?>
                                <div class="chat-header-content">
                                    <div class="chat-avatar">🎵</div>
                                    <div class="chat-title-group">
                                        <div class="chat-title">Start a Conversation</div>
                                        <div class="chat-subtitle">You don't have any conversations yet. Choose a studio to message the owner.</div>
                                        <div style="margin-top:8px; display:flex; gap:8px;">
                                            <a href="../../client/php/browse.php" class="send-button" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                                <i class="fa fa-map"></i> Browse Studios
                                            </a>
                                            <a href="../../Home.php" class="send-button" style="background:#555; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                                <i class="fa fa-home"></i> Home
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="chat-header-content">
                                    <div class="chat-avatar">🎵</div>
                                    <div class="chat-title-group">
                                        <div class="chat-title">
                                            <?php echo htmlspecialchars($owner_name); ?>
                                            <span class="title-separator">•</span>
                                            <span class="studio-chip"><?php echo $studio ? htmlspecialchars($studio['StudioName']) : 'General'; ?></span>
                                        </div>
                                        <div class="chat-subtitle">
                                            Chatting with <?php echo htmlspecialchars($partner['name']); ?> <?php echo $partner['user_type'] == 'client' ? '(Client)' : '(Studio Owner)'; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>



                        <div class="chat-messages" id="chat-messages">
                            <?php
                                // Per-session welcome notice for this conversation (UI-only)
                                $welcome_key = "chat_welcome_shown_{$owner_id}_{$client_id}";
                                $auto_sys_key = "chat_session_sys_sent_{$owner_id}_{$client_id}";
                                $show_welcome = false;
                                // Show bubble only if we haven't sent the per-session auto-reply
                                if (!isset($_SESSION[$auto_sys_key])) {
                                    if (!isset($_SESSION[$welcome_key])) {
                                        $_SESSION[$welcome_key] = true;
                                        $show_welcome = true;
                                    }
                                }
                            ?>
                            <?php if (count($messages) > 0): ?>
                                <?php foreach ($messages as $message): ?>
                                    <?php
                                    $message_classes = [];
                                    if ($message['Sender_Type'] == 'Client') {
                                        $message_classes[] = 'message-sent';
                                    } else {
                                        $message_classes[] = 'message-received';
                                        // Add specific class for Owner or System messages
                                        if ($message['Sender_Type'] == 'Owner') {
                                            $message_classes[] = 'message-owner';
                                        } elseif ($message['Sender_Type'] == 'System') {
                                            $message_classes[] = 'message-system';
                                        }
                                    }
                                    ?>
                                    <div class="message <?php echo implode(' ', $message_classes); ?>">
                                        <div class="message-content">
                                            <?php if ($message['Sender_Type'] == 'Owner'): ?>
                                                <div style="font-size: 0.75em; color: var(--primary-color); font-weight: 600; margin-bottom: 4px;">
                                                    <i class="fas fa-store"></i> Owner
                                                </div>
                                            <?php elseif ($message['Sender_Type'] == 'System'): ?>
                                                <div style="font-size: 0.75em; color: #ffc107; font-weight: 600; margin-bottom: 4px;">
                                                    <i class="fas fa-robot"></i> System
                                                </div>
                                            <?php endif; ?>
                                            <?php echo nl2br(htmlspecialchars($message['Content'])); ?>
                                        </div>
                                        <div class="message-meta">
                                            <?php echo date('Y-m-d H:i', strtotime($message['Timestamp'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; color: var(--text-secondary); margin-top: 20px;">
                                    You have no conversations yet.<br>
                                    Use Browse Studios to find a studio and start chatting.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="chat-input">
                            <?php if ($empty_state): ?>
                                <div style="color: var(--text-secondary);">
                                    Select a studio from Browse Studios to start messaging.
                                </div>
                            <?php else: ?>
                                <?php if ($user_type === 'client' && count($faqs) > 0): ?>
                                    <div class="chat-input-controls" style="display:flex; align-items:center; gap:8px; padding:6px 0;">
                                        <button type="button" id="toggle-faq" style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border:1px solid var(--border-color); border-radius:6px; background:rgba(30,30,30,0.9); color:var(--text-primary); cursor:pointer;">
                                            <i class="fas fa-robot"></i>
                                            <span>Quick Questions</span>
                                            <span id="toggle-faq-state" style="opacity:0.7; font-size:0.9em;">(On)</span>
                                        </button>
                                    </div>
                                    <div class="faq-panel" id="faq-panel" style="border-top: 1px solid var(--border-color); border-bottom: none; background-color: rgba(30,30,30,0.9);">
                                        <div class="faq-list">
                                            <?php foreach ($faqs as $faq): ?>
                                                <form method="post" class="faq-item">
                                                    <input type="hidden" name="ask_faq" value="1">
                                                    <input type="hidden" name="faq_id" value="<?php echo (int)$faq['id']; ?>">
                                                    <button type="submit" class="faq-button"><?php echo htmlspecialchars($faq['question']); ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                        <div style="color: var(--text-secondary); font-size: 0.9em; margin-top: 8px;">Tap a Quick Question to get instant answers.</div>
                                    </div>
                                <?php endif; ?>
                                <form method="post" class="chat-form">
                                    <input type="hidden" name="send_message" value="1">
                                    <input type="text" name="message" class="message-input" placeholder="Type a message..." required>
                                    <button type="submit" class="send-button">Send</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <?php include '../../shared/components/footer.php'; ?>
    <script>
        // Scroll to bottom of chat messages
        document.addEventListener('DOMContentLoaded', function() {
            var chatMessages = document.getElementById('chat-messages');
            if (chatMessages) { chatMessages.scrollTop = chatMessages.scrollHeight; }

            // Toggle Quick Questions panel visibility
            var toggleBtn = document.getElementById('toggle-faq');
            var toggleState = document.getElementById('toggle-faq-state');
            var faqPanel = document.getElementById('faq-panel');
            // Build a per-conversation key (owner, client)
            var faqKey = 'faqVisible_' + (<?php echo json_encode((string)$owner_id); ?>) + '_' + (<?php echo json_encode((string)$client_id); ?>);
            var initial = localStorage.getItem(faqKey);
            var visible = initial === null ? true : (initial === 'true');
            function render() {
                if (!faqPanel) return;
                faqPanel.style.display = visible ? '' : 'none';
                if (toggleState) { toggleState.textContent = visible ? '(On)' : '(Off)'; }
            }
            render();
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    visible = !visible;
                    localStorage.setItem(faqKey, visible ? 'true' : 'false');
                    render();
                });
            }

            // --- Real-time chat wiring ---
            var ownerId = <?php echo isset($owner_id) && $owner_id !== null ? (int)$owner_id : 0; ?>;
            var clientId = <?php echo isset($client_id) ? (int)$client_id : 0; ?>;
            var userType = '<?php echo isset($user_type) ? $user_type : ''; ?>';

            var messageContainer = document.getElementById('chat-messages');
            var formEl = document.querySelector('.chat-form');
            var inputEl = document.querySelector('.message-input');
            var sendBtn = document.querySelector('.send-button');

            var lastLatestId = 0;
            var isFetching = false;

            function escapeHTML(str) {
                return String(str).replace(/[&<>\"]/g, function(s) {
                    var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
                    return map[s] || s;
                });
            }

            function renderMessages(messages) {
                if (!messageContainer) return;
                var html = '';
                for (var i = 0; i < messages.length; i++) {
                    var m = messages[i];
                    var senderType = m.Sender_Type || '';
                    var klass = 'message-received';
                    var labelHTML = '';
                    
                    if (senderType.toLowerCase() === 'client') {
                        klass = 'message-sent';
                    } else {
                        if (senderType.toLowerCase() === 'owner') {
                            klass += ' message-owner';
                            labelHTML = '<div style="font-size: 0.75em; color: var(--primary-color); font-weight: 600; margin-bottom: 4px;"><i class="fas fa-store"></i> Owner</div>';
                        } else if (senderType.toLowerCase() === 'system') {
                            klass += ' message-system';
                            labelHTML = '<div style="font-size: 0.75em; color: #ffc107; font-weight: 600; margin-bottom: 4px;"><i class="fas fa-robot"></i> System</div>';
                        }
                    }
                    
                    var content = escapeHTML(m.Content || '');
                    var timeStr = m.Timestamp ? new Date(m.Timestamp).toLocaleString() : '';
                    html += '\n<div class="message ' + klass + '">\n' +
                            '  <div class="message-content">' + labelHTML + content.replace(/\n/g, '<br>') + '</div>\n' +
                            '  <div class="message-meta">' + timeStr + '</div>\n' +
                            '</div>\n';
                }
                var nearBottom = (messageContainer.scrollHeight - messageContainer.scrollTop - messageContainer.clientHeight) < 80;
                messageContainer.innerHTML = html || '<div style="text-align: center; color: var(--text-secondary); margin-top: 20px;">No messages yet.</div>';
                if (nearBottom) {
                    messageContainer.scrollTop = messageContainer.scrollHeight;
                }
            }

            function fetchMessages() {
                if (!messageContainer || !ownerId || !clientId) return;
                if (isFetching) return;
                isFetching = true;
                var url = 'fetch_chat.php?owner_id=' + ownerId + '&client_id=' + clientId;
                fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data || !data.success) return;
                        var msgs = Array.isArray(data.messages) ? data.messages : [];
                        var latestId = msgs.length ? (parseInt(msgs[msgs.length - 1].ChatID) || 0) : 0;
                        if (latestId !== lastLatestId) {
                            lastLatestId = latestId;
                            renderMessages(msgs);
                        }
                    })
                    .catch(function(err) { console.error('fetchMessages error:', err); })
                    .finally(function() { isFetching = false; });
            }

            // Initial fetch and start polling
            fetchMessages();
            var POLL_MS = 2500;
            setInterval(fetchMessages, POLL_MS);

            // AJAX send handler
            if (formEl && inputEl && sendBtn) {
                formEl.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var content = inputEl.value.trim();
                    if (!content) return;
                    sendBtn.disabled = true;
                    sendBtn.textContent = 'Sending...';

                    var senderType = (userType === 'client') ? 'Client' : 'Owner';
                    var body = new URLSearchParams({
                        content: content,
                        owner_id: ownerId,
                        client_id: clientId,
                        sender_type: senderType
                    });

                    fetch('send_message.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data && data.success) {
                            inputEl.value = '';
                            fetchMessages();
                        } else {
                            console.error('send_message error:', data && data.error);
                        }
                    })
                    .catch(function(err) { console.error('send_message fetch error:', err); })
                    .finally(function() {
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'Send';
                    });
                });

                // Enable/disable send button based on input
                inputEl.addEventListener('input', function() {
                    sendBtn.disabled = this.value.trim() === '';
                });
                sendBtn.disabled = !inputEl.value.trim();
            }
        });
    </script>
</body>

</html>