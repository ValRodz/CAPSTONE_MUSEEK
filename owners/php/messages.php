<?php
session_start();
// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

include '../../shared/config/db pdo.php';

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Get chat partner ID from URL
$partner_id = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : 0;
// Get studio ID from URL (optional)
$studio_id = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;

$show_chat = $partner_id > 0;
if ($partner_id <= 0) {
    $show_chat = false;
}

// Track the timestamp when owner last viewed this conversation (for unread tracking)
if ($show_chat) {
    $conversation_key = 'last_viewed_owner_' . $partner_id . '_' . $studio_id;
    $_SESSION[$conversation_key] = date('Y-m-d H:i:s');
}

// Get partner information
if ($user_type === 'owner') {
    $partner_query = "SELECT ClientID as ID, Name, Email FROM clients WHERE ClientID = ?";
    $user_query = "SELECT OwnerID as ID, Name, Email FROM studio_owners WHERE OwnerID = ?";
} else {
    $partner_query = "SELECT OwnerID as ID, Name, Email FROM studio_owners WHERE OwnerID = ?";
    $user_query = "SELECT ClientID as ID, Name, Email FROM clients WHERE ClientID = ?";
}

$partner_stmt = $pdo->prepare($partner_query);
$partner_stmt->execute([$partner_id]);
$partner = $partner_stmt->fetch();

$user_stmt = $pdo->prepare($user_query);
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

// If studio_id is not provided, try to infer the most recent StudioID
if ($show_chat && $studio_id === 0) {
    try {
        $lastStudioStmt = $pdo->prepare("SELECT StudioID FROM chatlog WHERE OwnerID = ? AND ClientID = ? ORDER BY ChatID DESC LIMIT 1");
        $lastStudioStmt->execute([$user_id, $partner_id]);
        $lastStudioRow = $lastStudioStmt->fetch(PDO::FETCH_ASSOC);
        if ($lastStudioRow && isset($lastStudioRow['StudioID'])) {
            $studio_id = (int)$lastStudioRow['StudioID'];
        }
    } catch (Exception $e) {
        // Ignore errors; keep studio_id as 0
    }
}

// Load studio info for header chip
$studio = null;
if ($studio_id > 0) {
    try {
        $studioStmt = $pdo->prepare("SELECT StudioID, StudioName FROM studios WHERE StudioID = ?");
        $studioStmt->execute([$studio_id]);
        $studio = $studioStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $studio = null;
    }
}

// Build conversation list for sidebar
$conversations = [];
try {
    if ($user_type === 'owner') {
        // Group conversations by ClientID + StudioID for owners
        $conv_sql = "SELECT 
                c.ClientID AS ID,
                c.Name,
                c.Email,
                cl.StudioID AS studio_id,
                s.StudioName AS studio_name,
                MAX(cl.Timestamp) AS last_time,
                SUBSTRING_INDEX(MAX(CONCAT(cl.Timestamp, '|', cl.Content)), '|', -1) AS last_message,
                SUBSTRING_INDEX(MAX(CONCAT(cl.Timestamp, '|', cl.Sender_Type)), '|', -1) AS last_sender
            FROM chatlog cl
            JOIN clients c ON c.ClientID = cl.ClientID
            LEFT JOIN studios s ON s.StudioID = cl.StudioID
            WHERE cl.OwnerID = ?
            GROUP BY c.ClientID, cl.StudioID
            ORDER BY last_time DESC";
    } else {
        $conv_sql = "SELECT o.OwnerID AS ID, o.Name, o.Email, MAX(cl.Timestamp) AS last_time, SUBSTRING_INDEX(MAX(CONCAT(cl.Timestamp, '|', cl.Content)), '|', -1) AS last_message, SUBSTRING_INDEX(MAX(CONCAT(cl.Timestamp, '|', cl.Sender_Type)), '|', -1) AS last_sender FROM chatlog cl JOIN studio_owners o ON o.OwnerID = cl.OwnerID WHERE cl.ClientID = ? GROUP BY o.OwnerID ORDER BY last_time DESC";
    }
    $conv_stmt = $pdo->prepare($conv_sql);
    $conv_stmt->execute([$user_id]);
    $conversations = $conv_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate unread count for each conversation (messages from Client not yet viewed)
    foreach ($conversations as &$conv) {
        $conv_partner_id = $conv['ID'];
        $conv_studio_id = isset($conv['studio_id']) ? $conv['studio_id'] : 0;
        $conversation_key = 'last_viewed_owner_' . $conv_partner_id . '_' . $conv_studio_id;
        $last_viewed = isset($_SESSION[$conversation_key]) ? $_SESSION[$conversation_key] : '1970-01-01 00:00:00';
        
        // Count messages from Client sent after last viewed time
        $unread_stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM chatlog 
            WHERE OwnerID = ? 
            AND ClientID = ? 
            AND (StudioID = ? OR (StudioID IS NULL AND ? = 0))
            AND Sender_Type = 'Client' 
            AND Timestamp > ?
        ");
        $unread_stmt->execute([$user_id, $conv_partner_id, $conv_studio_id, $conv_studio_id, $last_viewed]);
        $unread_result = $unread_stmt->fetch(PDO::FETCH_ASSOC);
        $conv['unread_count'] = $unread_result ? (int)$unread_result['unread_count'] : 0;
    }
    unset($conv); // Break reference
} catch (Exception $e) {
    $conversations = [];
}

if (!$partner || !$user) {
    $show_chat = false;
    $partner = null;
}

// Handle sending messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    if (!empty($message) && $show_chat) {
        try {
            // Always mark studio owner as sender on this page
            $sender_type = 'Owner';
            if ($studio_id > 0) {
                $insert_query = "INSERT INTO chatlog (OwnerID, ClientID, StudioID, Content, Sender_Type, Timestamp) VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($insert_query);
                $stmt->execute([$user_id, $partner_id, $studio_id, $message, $sender_type]);
            } else {
                $insert_query = "INSERT INTO chatlog (OwnerID, ClientID, Content, Sender_Type, Timestamp) VALUES (?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($insert_query);
                $stmt->execute([$user_id, $partner_id, $message, $sender_type]);
            }
        } catch (Exception $e) {
            // Optionally log $e->getMessage()
        }
    }
    // Redirect to prevent form resubmission and stay on this page
    $redirect = "messages.php?partner_id=" . $partner_id;
    if ($studio_id > 0) { $redirect .= "&studio_id=" . $studio_id; }
    header("Location: " . $redirect);
    exit();
}

// Fetch chat messages
$messages = [];
if ($show_chat) {
    if ($studio_id > 0) {
        $messages_query = "
            SELECT ChatID, OwnerID, ClientID, Content, Timestamp, Sender_Type
            FROM chatlog 
            WHERE ((OwnerID = ? AND ClientID = ?) OR (OwnerID = ? AND ClientID = ?))
              AND StudioID = ?
            ORDER BY Timestamp ASC
        ";
        $messages_stmt = $pdo->prepare($messages_query);
        $messages_stmt->execute([$user_id, $partner_id, $partner_id, $user_id, $studio_id]);
    } else {
        $messages_query = "
            SELECT ChatID, OwnerID, ClientID, Content, Timestamp, Sender_Type
            FROM chatlog 
            WHERE ((OwnerID = ? AND ClientID = ?) OR (OwnerID = ? AND ClientID = ?))
            ORDER BY Timestamp ASC
        ";
        $messages_stmt = $pdo->prepare($messages_query);
        $messages_stmt->execute([$user_id, $partner_id, $partner_id, $user_id]);
    }
    $messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Mark messages as read (if needed)
// This would typically update a read status in the database
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - MuSeek Studio</title>
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">
    
    <style>
        :root {
            --netflix-red: #e50914;
            --netflix-black: #141414;
            --netflix-dark-gray: #2f2f2f;
            --netflix-gray: #666666;
            --netflix-light-gray: #b3b3b3;
            --netflix-white: #ffffff;
            --success-green: #46d369;
            --warning-orange: #ffa500;
            --info-blue: #0071eb;
        }

        body {
            font-family: 'Netflix Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--netflix-black);
            color: var(--netflix-white);
            margin: 0;
            padding: 0;
        }

        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            background: var(--netflix-black);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-netflix.collapsed + .main-content {
            margin-left: 70px;
        }

        .chat-container {
            display: flex;
            height: 95svh;
            background: var(--netflix-black);
            margin: 10px;
            border-radius: 10px;
        }

        .chat-sidebar {
            width: 300px;
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-right: 1px solid #333;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            padding: 20px;
            border-bottom: 1px solid #333;
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
        }

        .chat-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--netflix-white);
            margin: 0 0 5px 0;
        }

        .chat-subtitle {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
            margin: 0;
        }

        .chat-search {
            padding: 20px;
            border-bottom: 1px solid #333;
        }

        .search-input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #333;
            border-radius: 8px;
            background: var(--netflix-black);
            color: var(--netflix-white);
            font-size: 14px;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .chat-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px 0;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
        }
        
        .chat-list::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
        }

        .chat-item {
            padding: 15px 20px;
            border-bottom: 1px solid #333;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-item:hover {
            background: rgba(229, 9, 20, 0.1);
        }

        .chat-item.active {
            background: rgba(229, 9, 20, 0.2);
            border-left: 3px solid var(--netflix-red);
        }

        .chat-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--netflix-white);
            font-weight: 600;
            font-size: 14px;
        }

        .chat-info {
            flex: 1;
            min-width: 0;
        }

        .chat-name {
            font-weight: 600;
            color: var(--netflix-white);
            margin: 0 0 4px 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-preview {
            font-size: 0.8rem;
            color: var(--netflix-light-gray);
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        .chat-time {
            font-size: 0.7rem;
            color: var(--netflix-gray);
        }

        .chat-badge {
            background: var(--netflix-red);
            color: var(--netflix-white);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .chat-main-header {
            padding: 20px;
            border-bottom: 1px solid #333;
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .back-btn {
            background: none;
            border: none;
            color: var(--netflix-light-gray);
            font-size: 18px;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .back-btn:hover {
            background: var(--netflix-red);
            color: var(--netflix-white);
        }

        .partner-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--netflix-white);
            font-weight: 600;
            font-size: 16px;
        }

        .partner-info h3 {
            margin: 0 0 4px 0;
            color: var(--netflix-white);
            font-size: 1.1rem;
        }

        .partner-info p {
            margin: 0;
            color: var(--netflix-light-gray);
            font-size: 0.9rem;
        }

        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--success-green);
            margin-left: auto;
        }

        .chat-messages {
            flex: 1;
            padding: 20px 30px;
            margin: 10px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
            background: rgba(20, 20, 20, 0.5);
            border-radius: 10px;
        }
        
        .chat-messages::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
        }

        .message {
            margin-bottom: 20px;
            max-width: 70%;
            padding: 0 15px;
        }

        .message.sent {
            align-self: flex-end;
            margin-left: auto;
        }

        .message.received {
            align-self: flex-start;
            margin-right: auto;
        }

        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--netflix-white);
            font-weight: 600;
            font-size: 12px;
            flex-shrink: 0;
            margin-bottom: 5px;
        }

        .message.sent .message-avatar {
            background: linear-gradient(135deg, var(--info-blue), #0071eb);
            margin-left: auto;
        }

        .message-content {
            background: rgba(50, 50, 50, 0.95);
            padding: 15px 20px;
            border-radius: 0 8px 8px 8px;
            position: relative;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            margin: 0 10px;
            border-left: 3px solid var(--netflix-gray);
        }

        .message.sent .message-content {
            background: var(--info-blue);
            color: var(--netflix-white);
            border-radius: 8px 0 8px 8px;
            border-left: none;
            border-right: 3px solid #0071eb;
        }

        .message-text {
            margin: 0;
            font-size: 14px;
            line-height: 1.4;
        }

        .message-time {
            font-size: 0.7rem;
            color: var(--netflix-gray);
            margin-top: 4px;
        }

        .message.sent .message-time {
            color: rgba(255, 255, 255, 0.7);
        }

        .chat-input-container {
            padding: 22px;
            margin: 15px 20px;
            border-top: 1px solid rgba(80, 80, 80, 0.5);
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.4), 0 0 20px rgba(0, 0, 0, 0.3);
            border-radius: 18px;
            position: relative;
            z-index: 10;
        }

        .chat-input-form {
            display: flex;
            gap: 15px;
            align-items: center;
            position: relative;
        }

        .chat-input {
            flex: 1;
            padding: 16px 24px;
            border: none;
            border-radius: 30px;
            background: rgba(15, 15, 15, 0.9);
            color: var(--netflix-white);
            font-size: 16px;
            resize: none;
            min-height: 20px;
            max-height: 100px;
            transition: all 0.3s ease;
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(80, 80, 80, 0.4);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }

        .chat-input:focus {
            outline: none;
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.3), inset 0 2px 8px rgba(0, 0, 0, 0.2);
            background: rgba(20, 20, 20, 0.95);
            transform: translateY(-1px);
        }

        .chat-input::placeholder {
            color: rgba(170, 170, 170, 0.6);
            font-style: normal;
            font-weight: 300;
        }

        .send-btn {
            background: linear-gradient(135deg, var(--netflix-red), #ff4b4b);
            border: none;
            border-radius: 50%;
            width: 52px;
            height: 52px;
            color: var(--netflix-white);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.5), 0 0 0 2px rgba(229, 9, 20, 0.2);
            position: relative;
            overflow: hidden;
        }

        .send-btn:hover {
            background: linear-gradient(135deg, #e50914, #ff3b3b);
            transform: translateY(-3px) scale(1.08);
            box-shadow: 0 6px 16px rgba(229, 9, 20, 0.6), 0 0 0 3px rgba(229, 9, 20, 0.3);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.5);
        }

        .send-btn:active {
            transform: translateY(0) scale(0.98);
        }
        
        /* Chat Input Form Styling */
        .chat-input-wrapper {
            padding: 20px;
            background: linear-gradient(135deg, rgba(30, 30, 30, 0.9) 0%, rgba(20, 20, 20, 0.95) 100%);
            border-top: 1px solid rgba(80, 80, 80, 0.3);
            border-radius: 0 0 15px 15px;
            box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.2);
        }

        .chat-form {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .message-input {
            flex: 1;
            padding: 16px 24px;
            border: none;
            border-radius: 30px;
            background: rgba(15, 15, 15, 0.8);
            color: var(--netflix-white);
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(80, 80, 80, 0.3);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }

        .message-input:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.3), inset 0 2px 8px rgba(0, 0, 0, 0.2);
            background: rgba(20, 20, 20, 0.9);
            transform: translateY(-1px);
        }

        .message-input::placeholder {
            color: rgba(170, 170, 170, 0.6);
            font-weight: 300;
        }

        .send-button {
            background: linear-gradient(135deg, var(--netflix-red), #ff4b4b);
            border: none;
            border-radius: 50%;
            width: 52px;
            height: 52px;
            color: var(--netflix-white);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.4), 0 0 0 2px rgba(229, 9, 20, 0.1);
            position: relative;
            overflow: hidden;
        }

        .send-button i {
            font-size: 18px;
            transform: translateX(-1px);
        }

        .send-button:hover {
            background: linear-gradient(135deg, #e50914, #ff3b3b);
            transform: translateY(-3px) scale(1.08);
            box-shadow: 0 6px 16px rgba(229, 9, 20, 0.5), 0 0 0 3px rgba(229, 9, 20, 0.2);
        }

        .send-button:active {
            transform: translateY(0) scale(0.95);
            box-shadow: 0 2px 8px rgba(229, 9, 20, 0.4);
            transition: all 0.1s ease;
        }

        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--netflix-light-gray);
            text-align: center;
            padding: 40px;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: var(--netflix-gray);
        }

        .empty-state h3 {
            color: var(--netflix-white);
            margin-bottom: 10px;
            font-size: 1.5rem;
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .chat-container {
                flex-direction: column;
                height: 100vh;
            }
            
            .chat-sidebar {
                width: 100%;
                height: 200px;
                border-right: none;
                border-bottom: 1px solid #333;
            }
            
            .chat-main {
                height: calc(100vh - 200px);
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <div class="main-content">
        <div class="chat-container">
            <!-- Chat Sidebar -->
            <div class="chat-sidebar">
                <div class="chat-header">
                    <h2 class="chat-title">Messages</h2>
                    <p class="chat-subtitle">Studio communications</p>
                </div>
                
                <div class="chat-search">
                    <input type="text" class="search-input" placeholder="Search conversations...">
                </div>
                
                <div class="chat-list">
                    <?php if (!empty($conversations)): ?>
                        <?php foreach ($conversations as $conv): ?>
                            <a class="chat-item <?php echo ($conv['ID'] == $partner_id && (int)($conv['studio_id'] ?? 0) === (int)$studio_id) ? 'active' : ''; ?>" data-studio-id="<?php echo (int)($conv['studio_id'] ?? 0); ?>" href="messages.php?partner_id=<?php echo (int)$conv['ID']; ?>&studio_id=<?php echo (int)($conv['studio_id'] ?? 0); ?>">
                                <div class="chat-avatar">
                                    <?php echo strtoupper(substr($conv['Name'], 0, 2)); ?>
                                </div>
                                <div class="chat-info">
                                    <h4 class="chat-name"><?php echo htmlspecialchars($conv['Name']); ?></h4>
                                    <?php 
                                        $prefix = (isset($conv['last_sender']) && strtolower($conv['last_sender']) === 'owner') ? 'You: ' : '';
                                        $preview = $prefix . ($conv['last_message'] ?? '');
                                    ?>
                                    <p class="chat-preview"><?php echo htmlspecialchars($preview); ?></p>
                                </div>
                                <div class="chat-meta">
                                    <span class="chat-time">
                                        <?php echo !empty($conv['last_time']) ? date('M j, g:i A', strtotime($conv['last_time'])) : ''; ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-comments"></i>
                            <h3>No conversations yet</h3>
                            <p>Start chatting with your clients to see them here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chat Main Area -->
            <div class="chat-main">
                <div class="chat-main-header">
                    <button class="back-btn" onclick="window.history.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <?php if ($show_chat && $partner): ?>
                        <div class="partner-avatar">
                            <?php echo strtoupper(substr($partner['Name'], 0, 2)); ?>
                        </div>
                        <div class="partner-info">
                            <h3>
                                <?php echo htmlspecialchars($partner['Name']); ?>
                                <?php if ($studio): ?>
                                    <span style="margin-left:8px; font-size:0.85rem; color: var(--netflix-light-gray);">• <?php echo htmlspecialchars($studio['StudioName']); ?></span>
                                <?php elseif ($studio_id > 0): ?>
                                    <span style="margin-left:8px; font-size:0.85rem; color: var(--netflix-light-gray);">• Studio #<?php echo (int)$studio_id; ?></span>
                                <?php endif; ?>
                            </h3>
                            <p><?php echo htmlspecialchars($partner['Email']); ?></p>
                        </div>
                        <div class="status-indicator"></div>
                    <?php else: ?>
                        <div class="partner-info">
                            <h3>Select a conversation</h3>
                            <p>Choose a client from the list to chat.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="chat-messages" id="chatMessages">
                    <?php if (!$show_chat): ?>
                        <div class="empty-state">
                            <i class="fas fa-comments"></i>
                            <h3>No conversation selected</h3>
                            <p>Select a client from the sidebar to view messages.</p>
                        </div>
                    <?php elseif (empty($messages)): ?>
                        <div class="empty-state">
                            <i class="fas fa-comments"></i>
                            <h3>No Messages Yet</h3>
                            <p>Start a conversation with <?php echo htmlspecialchars($partner['Name']); ?></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $message): ?>
                            <?php $senderType = isset($message['Sender_Type']) ? strtolower($message['Sender_Type']) : ''; ?>
                            <div class="message <?php echo ($senderType === 'owner' || $senderType === 'system') ? 'sent' : 'received'; ?>">
                                <div class="message-avatar">
                                    <?php 
                                    if ($senderType === 'owner') {
                                        echo strtoupper(substr($user['Name'], 0, 2));
                                    } elseif ($senderType === 'system') {
                                        echo 'SYS';
                                    } else {
                                        echo strtoupper(substr($partner['Name'], 0, 2));
                                    }
                                    ?>
                                </div>
                                <div class="message-content">
                                    <p class="message-text"><?php echo htmlspecialchars($message['Content']); ?></p>
                                    <div class="message-time">
                                        <?php echo date('g:i A', strtotime($message['Timestamp'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($show_chat): ?>
                <div class="chat-input-wrapper">
                    <form method="post" class="chat-form" id="chatForm">
                        <input type="text" name="message" class="message-input" id="messageInput" placeholder="Type a message..." required>
                        <button type="submit" class="send-button" id="sendBtn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <div class="chat-input-container">
                    <div class="empty-state">
                        <p>Select a conversation to start messaging.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-resize textarea
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            });
        }

        // Auto-scroll to bottom
        // Real-time message updates with AJAX polling
        let userHasScrolled = false;
        const chatMessages = document.getElementById('chatMessages');
        
        // Track when user scrolls manually
        chatMessages.addEventListener('scroll', function() {
            // If user scrolls up more than 100px from bottom, mark as scrolled
            userHasScrolled = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight > 100;
        });
        
        function scrollToBottom() {
            // Only auto-scroll if user hasn't manually scrolled up
            if (!userHasScrolled) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        }

        // Scroll to bottom on page load
        window.addEventListener('load', scrollToBottom);

        function fetchMessages() {
            if (!<?php echo $show_chat ? 'true' : 'false'; ?>) return;
            const activeItem = document.querySelector('.chat-item.active');
            const studioId = activeItem ? (parseInt(activeItem.getAttribute('data-studio-id')) || 0) : (<?php echo (int)$studio_id; ?>);
            fetch(`../../messaging/php/fetch_chat.php?owner_id=<?php echo $user_id; ?>&client_id=<?php echo $partner_id; ?>&studio_id=${studioId}`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.success) {
                        const messages = data.messages || [];
                        const currentMessageCount = document.querySelectorAll('.message').length;
                        
                        // Only update if there are new messages
                        if (messages.length > 0 && messages.length !== currentMessageCount) {
                            chatMessages.innerHTML = '';
                            messages.forEach(message => {
                                const senderType = (message.Sender_Type || '').toLowerCase();
                                const isRight = senderType === 'owner' || senderType === 'system';
                                const messageClass = isRight ? 'sent' : 'received';
                                const avatar = senderType === 'owner'
                                    ? '<?php echo strtoupper(substr($user['Name'] ?? '', 0, 2)); ?>'
                                    : (senderType === 'system' ? 'SYS' : '<?php echo strtoupper(substr($partner['Name'] ?? '', 0, 2)); ?>');
                                
                                const messageHtml = `
                                    <div class="message ${messageClass}">
                                        <div class="message-avatar">${avatar}</div>
                                        <div class="message-content">
                                            <p class="message-text">${message.Content}</p>
                                            <div class="message-time">${new Date(message.Timestamp).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</div>
                                        </div>
                                    </div>
                                `;
                                chatMessages.innerHTML += messageHtml;
                            });
                            scrollToBottom();
                        } else if (chatMessages.innerHTML === '') {
                            chatMessages.innerHTML = `
                                <div class="empty-state">
                                    <i class="fas fa-comments"></i>
                                    <h3>No Messages Yet</h3>
                                    <p>Start a conversation with <?php echo htmlspecialchars($partner['Name'] ?? ''); ?></p>
                                </div>
                            `;
                        }
                    }
                })
                .catch(error => console.error('Error fetching messages:', error));
        }
        
        // Poll for new messages every 500ms for real-time updates
        if (<?php echo $show_chat ? 'true' : 'false'; ?>) {
            fetchMessages(); // Initial fetch
            setInterval(fetchMessages, 500); // Poll every 500ms
        }
        
        // Always update the sidebar in real-time, regardless of chat selection
        updateSidebar(); // Initial sidebar update
        setInterval(updateSidebar, 2000); // Update sidebar every 2 seconds
        
        // Function to update the sidebar conversations
        function updateSidebar() {
            // Store reference to the chat list
            const chatList = document.querySelector('.chat-list');
            if (!chatList) return; // Safety check
            
            const currentContent = chatList.innerHTML;
            
            fetch('../../messaging/php/fetch_conversations.php?owner_id=<?php echo $user_id; ?>')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data && data.success) {
                        const conversations = data.conversations || [];
                        
                        if (conversations.length > 0) {
                            let html = '';
                            conversations.forEach(conv => {
                                const isActive = (conv.ID == <?php echo $partner_id ?: 0; ?> && (conv.studio_id || 0) == <?php echo (int)$studio_id; ?>) ? 'active' : '';
                                const unreadBadge = conv.unread_count > 0 ? 
                                    `<span class="unread-badge">${conv.unread_count}</span>` : '';
                                
                                // Format the timestamp nicely
                                const messageTime = conv.last_message_time ? new Date(conv.last_message_time) : new Date();
                                const now = new Date();
                                const diffMs = now - messageTime;
                                const diffMins = Math.floor(diffMs / 60000);
                                const diffHours = Math.floor(diffMins / 60);
                                const diffDays = Math.floor(diffHours / 24);
                                
                                let timeDisplay;
                                if (diffMins < 1) {
                                    timeDisplay = 'Just now';
                                } else if (diffMins < 60) {
                                    timeDisplay = `${diffMins}m ago`;
                                } else if (diffHours < 24) {
                                    timeDisplay = `${diffHours}h ago`;
                                } else if (diffDays < 7) {
                                    timeDisplay = `${diffDays}d ago`;
                                } else {
                                    timeDisplay = messageTime.toLocaleDateString();
                                }
                                
                                html += `
                                    <a class="chat-item ${isActive}" data-studio-id="${conv.studio_id || 0}" href="messages.php?partner_id=${conv.ID}&studio_id=${conv.studio_id || 0}">
                                        <div class="chat-avatar">
                                            ${conv.Name.substring(0, 2).toUpperCase()}
                                        </div>
                                        <div class="chat-info">
                                            <h4 class="chat-name">${conv.Name}</h4>
                                            <p class="chat-preview">${(conv.last_sender && conv.last_sender.toLowerCase() === 'owner' ? 'You: ' : '')}${conv.last_message || 'No messages yet'}</p>
                                        </div>
                                        <div class="chat-meta">
                                            <span class="chat-time">${timeDisplay}</span>
                                            ${unreadBadge}
                                        </div>
                                    </a>
                                `;
                            });
                            chatList.innerHTML = html;
                        } else {
                            chatList.innerHTML = `
                                <div class="empty-state">
                                    <p>You don't have any conversations yet.</p>
                                </div>
                            `;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error updating sidebar:', error);
                    // Keep the previous content if there's an error
                    chatList.innerHTML = currentContent;
                });
        }

        // Handle form submission with AJAX
        const chatForm = document.getElementById('chatForm');
        if (chatForm) {
            chatForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const message = messageInput.value.trim();
                if (message === '') {
                    return;
                }
                
                // Disable send button while sending
                const sendBtn = document.getElementById('sendBtn');
                sendBtn.disabled = true;
                sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                
                // Send message via AJAX
                fetch('../../messaging/php/send_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: (() => {
                        const activeItem = document.querySelector('.chat-item.active');
                        const studioId = activeItem ? parseInt(activeItem.getAttribute('data-studio-id')) || 0 : 0;
                        return `content=${encodeURIComponent(message)}&owner_id=<?php echo $user_id; ?>&client_id=<?php echo $partner_id; ?>&studio_id=${studioId}&sender_type=Owner`;
                    })()
                })
                .then(response => response.json())
                .then(data => {
                    if (data && data.success) {
                        // Clear input field
                        messageInput.value = '';
                        messageInput.style.height = 'auto';
                        
                        // Fetch latest messages immediately
                        fetchMessages();
                    } else {
                        console.error('Error sending message:', data.error || 'Unknown error');
                    }
                })
                .catch(error => console.error('Error sending message:', error))
                .finally(() => {
                    // Re-enable send button
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
                });
            });
        }

        // Reset send button if form doesn't submit
        if (messageInput) {
            messageInput.addEventListener('input', function() {
                const sendBtn = document.getElementById('sendBtn');
                if (this.value.trim() === '') {
                    if (sendBtn) sendBtn.disabled = true;
                } else {
                    if (sendBtn) {
                        sendBtn.disabled = false;
                        sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
                    }
                }
            });
            
            // Add Enter key functionality to send messages
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const sendBtn = document.getElementById('sendBtn');
                    if (this.value.trim() !== '' && !sendBtn.disabled) {
                        chatForm.dispatchEvent(new Event('submit'));
                    }
                }
            });
        }

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.fade-in');
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    element.style.transition = 'all 0.6s ease-out';
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
}

