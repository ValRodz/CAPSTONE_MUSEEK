<?php
session_start();

// Check if the owner is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: ../../auth/php/login.html");
    exit();
}

$servername = "127.0.0.1";
$username = "root"; // Adjust as needed
$password = ""; // Adjust as needed
$dbname = "museek";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$ownerID = $_SESSION['user_id'];

// Fetch owner name and email for sidebar
$owner = ["Name" => "Owner", "Email" => "owner@email.com"];
$owner_sql = "SELECT Name, Email FROM studio_owners WHERE OwnerID = ? LIMIT 1";
if ($stmt_owner = $conn->prepare($owner_sql)) {
    $stmt_owner->bind_param("i", $ownerID);
    $stmt_owner->execute();
    $result_owner = $stmt_owner->get_result();
    if ($row_owner = $result_owner->fetch_assoc()) {
        $owner = $row_owner;
    }
    $stmt_owner->close();
}

// Helper function for initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Fetch messages for the logged-in owner
$sql = "SELECT c.ChatID, c.ClientID, c.Content, c.Timestamp, cl.`Name`
        FROM chatlog c
        JOIN clients cl ON c.ClientID = cl.ClientID
        WHERE OwnerID = ?
        ORDER BY Timestamp DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $ownerID);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = $row;
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MuSeek - Messages</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <style>
        :root {
            --primary-color: #e11d48;
            --primary-hover: #f43f5e;
            --sidebar-width: 280px;
            --header-height: 64px;
            --card-bg: #0f0f0f;
            --body-bg: #0a0a0a;
            --border-color: #222222;
            --text-primary: #ffffff;
            --text-secondary: #a1a1aa;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Inter", sans-serif;
            background-color: var(--body-bg);
            color: var(--text-primary);
            overflow: hidden;
            height: 100vh;
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--body-bg);
            border-right: 1px solid var(--border-color);
            z-index: 50;
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            font-size: 1.25rem;
        }
        .sidebar-logo i { color: var(--primary-color); }
        .sidebar-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.25rem;
        }
        .sidebar-close:hover { color: var(--text-primary); }
        .sidebar-content { flex: 1; overflow-y: auto; padding: 1rem 0; }
        .sidebar-menu { list-style: none; }
        .sidebar-menu-item { margin-bottom: 0.25rem; }
        .sidebar-menu-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--text-primary);
            text-decoration: none;
            border-radius: 0.375rem;
            margin: 0 0.5rem;
            transition: background-color 0.2s;
        }
        .sidebar-menu-link:hover { background-color: rgba(255,255,255,0.05); }
        .sidebar-menu-link.active { background-color: var(--primary-color); color: white; }
        .sidebar-menu-link i { width: 1.25rem; text-align: center; }
        .sidebar-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .user-avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
        }
        .user-info { flex: 1; min-width: 0; }
        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .user-email {
            font-size: 0.75rem;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .user-actions { display: flex; gap: 0.5rem; }
        .user-action-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1rem;
            padding: 0.25rem;
            border-radius: 0.25rem;
            transition: background-color 0.2s;
        }
        .user-action-btn:hover {
            background-color: rgba(255,255,255,0.05);
            color: var(--text-primary);
        }
        .main-content {
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .messages-header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--body-bg);
        }
        .messages-title {
            font-size: 1.25rem;
            font-weight: 600;
        }
        .messages-container {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }
        .message-card {
            background-color: #2d2d2d;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }
        /* --- Modal Chat Styles (copied from Home.php) --- */
        #ownerChatModalOverlay {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0; top: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }
        #ownerChatModalOverlay.active { display: flex; }
        #ownerChatModal {
            background: #222;
            color: #fff;
            border-radius: 12px;
            width: 350px;
            max-width: 95vw;
            box-shadow: 0 2px 16px rgba(0,0,0,0.4);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            animation: modalIn 0.2s;
        }
        @keyframes modalIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        #ownerChatModalHeader {
            background: #e50914;
            padding: 14px 16px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #ownerChatModalClose {
            cursor: pointer;
            font-size: 20px;
            color: #fff;
            background: none;
            border: none;
        }
        #ownerChatModalBody {
            flex: 1;
            padding: 14px 16px;
            overflow-y: auto;
            background: #181818;
        }
        #ownerChatModalInputArea {
            display: flex;
            border-top: 1px solid #333;
        }
        #ownerChatModalInput {
            flex: 1;
            padding: 10px;
            border: none;
            background: #222;
            color: #fff;
        }
        #ownerChatModalSend {
            background: #e50914;
            color: #fff;
            border: none;
            padding: 0 18px;
            cursor: pointer;
        }
        .client-chat-message { margin-bottom: 10px; }
        /* In owner modal: owner messages right, client left (opposite of Home.php) */
        .client-chat-message.owner { text-align: right; }
        .client-chat-message.client { text-align: left; }
        .client-chat-message .bubble { display: inline-block; padding: 8px 12px; border-radius: 16px; max-width: 80%; }
        .client-chat-message.owner .bubble { background: #e50914; color: #fff; }
        .client-chat-message.client .bubble { background: #2196f3; color: #fff; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-music"></i>
                <span>MuSeek</span>
            </div>
            <button class="sidebar-close" id="closeSidebar">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="sidebar-content">
            <ul class="sidebar-menu">
                <li class="sidebar-menu-item">
                    <a href="dashboard.php" class="sidebar-menu-link">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="bookings.php" class="sidebar-menu-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Bookings</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="schedule.php" class="sidebar-menu-link">
                        <i class="fas fa-clock"></i>
                        <span>Schedule</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="services.php" class="sidebar-menu-link">
                        <i class="fas fa-concierge-bell"></i>
                        <span>Services</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="payments.php" class="sidebar-menu-link">
                        <i class="fas fa-credit-card"></i>
                        <span>Payments</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="instructors.php" class="sidebar-menu-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Instructors</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="feedback.php" class="sidebar-menu-link">
                        <i class="fas fa-star"></i>
                        <span>Feedback</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="notifications.php" class="sidebar-menu-link">
                        <i class="fas fa-bell"></i>
                        <span>Notifications</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="owner_messages.php" class="sidebar-menu-link active">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="reports.php" class="sidebar-menu-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="settings.php" class="sidebar-menu-link">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="logout.php" class="sidebar-menu-link">
                        <i class="fas fa-door"></i>
                        <span>Log Out</span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="sidebar-footer">
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo getInitials($owner['Name']); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($owner['Name']); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($owner['Email']); ?></div>
                </div>
                <div class="user-actions">
                    <button class="user-action-btn" title="Profile">
                        <i class="fas fa-user"></i>
                    </button>
                    <button class="user-action-btn" title="Settings">
                        <i class="fas fa-cog"></i>
                    </button>
                </div>
            </div>
        </div>
    </aside>
    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <header class="messages-header">
            <button class="sidebar-close" id="toggleSidebar" style="margin-right:1rem;">
                <i class="fas fa-bars"></i>
            </button>
            <h2 class="messages-title">Messages from Clients</h2>
        </header>
        <div class="messages-container" id="messages-container">
            <!-- Messages will be dynamically loaded here -->
        </div>
    </main>
    <!-- Owner Chat Modal -->
    <div id="ownerChatModalOverlay" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);justify-content:center;align-items:center;">
        <div id="ownerChatModal" style="background:#222;color:#fff;border-radius:12px;width:350px;max-width:95vw;box-shadow:0 2px 16px rgba(0,0,0,0.4);display:flex;flex-direction:column;overflow:hidden;position:relative;animation:modalIn 0.2s;">
            <div id="ownerChatModalHeader" style="background:#e50914;padding:14px 16px;font-weight:bold;display:flex;justify-content:space-between;align-items:center;">
                <span id="ownerChatModalClientName">Chat</span>
                <button id="ownerChatModalClose" style="cursor:pointer;font-size:20px;color:#fff;background:none;border:none;">&times;</button>
            </div>
            <div id="ownerChatModalBody" style="flex:1;padding:14px 16px;overflow-y:auto;background:#181818;"><div style='color:#aaa;text-align:center;'>Loading...</div></div>
            <form id="ownerChatModalInputArea" style="display:flex;border-top:1px solid #333;">
                <input type="text" id="ownerChatModalInput" placeholder="Type your reply..." style="flex:1;padding:10px;border:none;background:#222;color:#fff;" autocomplete="off" />
                <button type="submit" id="ownerChatModalSend" style="background:#e50914;color:#fff;border:none;padding:0 18px;cursor:pointer;">Send</button>
            </form>
        </div>
    </div>
    <script>
    // Pass PHP messages to JavaScript
    const messages = <?php echo json_encode($messages); ?>;
    const ownerId = <?php echo json_encode($ownerID); ?>;
    // Render messages when the page loads
    document.addEventListener('DOMContentLoaded', function () {
        renderMessages();
    });
    function renderMessages() {
        const container = document.getElementById('messages-container');
        container.innerHTML = '';
        if (messages.length === 0) {
            container.innerHTML = '<p class="text-gray-400">No messages found.</p>';
            return;
        }
        // Group messages by client
        const grouped = {};
        messages.forEach(msg => {
            if (!grouped[msg.ClientID]) grouped[msg.ClientID] = [];
            grouped[msg.ClientID].push(msg);
        });
        Object.keys(grouped).forEach(clientId => {
            const lastMsg = grouped[clientId][0]; // Most recent
            const clientName = lastMsg.Name ? lastMsg.Name : clientId;
            const div = document.createElement('div');
            div.className = 'message-card';
            div.style.cursor = 'pointer';
            div.innerHTML = `<p><strong>From ${clientName}</strong></p>
                <p>${lastMsg.Content}</p>
                <p class='text-gray-400 text-sm'>${new Date(lastMsg.Timestamp).toLocaleString()}</p>`;
            div.onclick = () => openOwnerChatModal(clientId);
            container.appendChild(div);
        });
    }
    // Modal logic
    const ownerChatModalOverlay = document.getElementById('ownerChatModalOverlay');
    const ownerChatModal = document.getElementById('ownerChatModal');
    const ownerChatHeader = document.getElementById('ownerChatModalClientName');
    const ownerChatBody = document.getElementById('ownerChatModalBody');
    const ownerChatForm = document.getElementById('ownerChatModalInputArea');
    const ownerChatInput = document.getElementById('ownerChatModalInput');
    const ownerChatClose = document.getElementById('ownerChatModalClose');
    let selectedClientId = '';
    let ownerChatInterval = null;

    function startOwnerChatPolling() {
        if (ownerChatInterval) clearInterval(ownerChatInterval);
        ownerChatInterval = setInterval(fetchOwnerChat, 2000); // every 2 seconds
    }
    function stopOwnerChatPolling() {
        if (ownerChatInterval) clearInterval(ownerChatInterval);
    }

    function openOwnerChatModal(clientId) {
        selectedClientId = clientId;
        // Find the client name from the messages array
        let clientName = clientId;
        for (let i = 0; i < messages.length; i++) {
            if (messages[i].ClientID == clientId && messages[i].Name) {
                clientName = messages[i].Name;
                break;
            }
        }
        ownerChatHeader.textContent = 'Chat with: ' + clientName;
        ownerChatModalOverlay.style.display = 'flex';
        ownerChatForm.style.display = 'flex';
        fetchOwnerChat();
        startOwnerChatPolling();
    }
    function closeOwnerChatModal() {
        ownerChatModalOverlay.style.display = 'none';
        selectedClientId = '';
        stopOwnerChatPolling();
    }
    ownerChatClose.onclick = closeOwnerChatModal;
    ownerChatModalOverlay.onclick = function(e) {
        if (e.target === ownerChatModalOverlay) closeOwnerChatModal();
    };
    function fetchOwnerChat() {
        if (!selectedClientId) return;
        fetch(`fetch_chat.php?owner_id=${ownerId}&client_id=${selectedClientId}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) renderOwnerChatMessages(data.messages);
                else ownerChatBody.innerHTML = `<div style='color:#f99;text-align:center;'>${data.error}</div>`;
            });
    }
    function renderOwnerChatMessages(messages) {
        ownerChatBody.innerHTML = '';
        if (!messages.length) {
            ownerChatBody.innerHTML = "<div style='color:#aaa;text-align:center;'>No messages yet.</div>";
            return;
        }
        messages.forEach(msg => {
            // Use Sender_Type for alignment and color
            const who = (msg.Sender_Type && msg.Sender_Type.toLowerCase() === 'owner') ? 'owner' : 'client';
            const div = document.createElement('div');
            div.className = 'client-chat-message ' + who;
            div.innerHTML = `<span class='bubble'>${msg.Content.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span><div style='font-size:10px;color:#888;'>${msg.Timestamp}</div>`;
            ownerChatBody.appendChild(div);
        });
        ownerChatBody.scrollTop = ownerChatBody.scrollHeight;
    }
    ownerChatForm.onsubmit = function(e) {
        e.preventDefault();
        const content = ownerChatInput.value.trim();
        if (!content || !selectedClientId) return;
        ownerChatInput.value = '';
        fetch('send_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `content=${encodeURIComponent(content)}&owner_id=${ownerId}&client_id=${selectedClientId}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) fetchOwnerChat();
            else alert(data.error || 'Failed to send message');
        });
    };
    </script>
</body>
</html>
