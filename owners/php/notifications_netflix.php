<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: ../../auth/php/login.php");
    exit();
}

include '../../shared/config/db pdo.php';

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Mark all notifications as read when visiting this page
if (isset($_GET['mark_all_read'])) {
    $markRead = $pdo->prepare("UPDATE notifications SET IsRead = 1 WHERE OwnerID = ? AND For_User = 'Owner'");
    $markRead->execute([$ownerId]);
    header("Location: notifications_netflix.php");
    exit();
}

// Mark a specific notification as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notificationId = $_GET['mark_read'];
    $markRead = $pdo->prepare("
        UPDATE notifications 
        SET IsRead = 1 
        WHERE NotificationID = ? AND OwnerID = ? AND For_User = 'Owner'
    ");
    $markRead->execute([$notificationId, $ownerId]);

    // If there's a redirect URL, go there (decode and validate to avoid loops)
    if (isset($_GET['redirect'])) {
        $target = rawurldecode($_GET['redirect']);
        // Prevent self-redirect loops or invalid targets
        $current = basename(__FILE__);
        if ($target === '' || $target === '#' || stripos($target, $current) !== false) {
            header("Location: notifications_netflix.php");
        } else {
            header("Location: " . $target);
        }
        exit();
    }

    header("Location: notifications_netflix.php");
    exit();
}

// Get all notifications for this owner (only where For_User = 'Owner'), limited to 15 latest
$notifications = $pdo->prepare("
    SELECT n.*, 
           c.Name as ClientName
    FROM notifications n
    LEFT JOIN clients c ON n.ClientID = c.ClientID
    WHERE n.OwnerID = ? AND n.For_User = 'Owner'
    ORDER BY n.Created_At DESC
    LIMIT 15
");
$notifications->execute([$ownerId]);
$notifications = $notifications->fetchAll(PDO::FETCH_ASSOC);

// Count unread notifications (only for Owner)
$unreadNotifications = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE OwnerID = ? 
    AND IsRead = 0
    AND For_User = 'Owner'
");
$unreadNotifications->execute([$ownerId]);
$unreadNotifications = $unreadNotifications->fetchColumn();

// Get owner data
$owner = $pdo->prepare("
    SELECT Name, Email 
    FROM studio_owners 
    WHERE OwnerID = ?
");
$owner->execute([$ownerId]);
$owner = $owner->fetch(PDO::FETCH_ASSOC);

// Helper function to get customer initials
function getInitials($name)
{
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Helper function to format notification time
function formatNotificationTime($timestamp)
{
    $now = new DateTime();
    $notificationTime = new DateTime($timestamp);
    $interval = $now->diff($notificationTime);

    if ($interval->y > 0) {
        return $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
    } elseif ($interval->m > 0) {
        return $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
    } elseif ($interval->d > 0) {
        return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
    } elseif ($interval->h > 0) {
        return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
    } elseif ($interval->i > 0) {
        return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}

// Helper function to get notification icon
function getNotificationIcon($type)
{
    switch (strtolower($type)) {
        case 'booking':
        case 'booking_confirmation':
            return '<i class="far fa-calendar-alt text-blue-500"></i>';
        case 'payment':
        case 'payment_confirmation':
            return '<i class="fas fa-dollar-sign text-green-500"></i>';
        case 'booking_finished':
            return '<i class="fas fa-flag-checkered text-green-500"></i>';
        case 'booking_cancellation':
            return '<i class="fas fa-times-circle text-red-500"></i>';
        case 'feedback':
            return '<i class="far fa-comment-alt text-yellow-500"></i>';
        default:
            return '<i class="fas fa-bell text-red-500"></i>';
    }
}

// Helper function to get notification redirect URL based on type and RelatedID
function getNotificationRedirectUrl($type, $relatedId)
{
    // Deep-link by ID and let target pages highlight the item.
    $t = strtolower($type);
    switch ($t) {
        case 'booking':
        case 'booking_confirmation':
        case 'booking_finished':
        case 'booking_cancellation':
        case 'new_booking':
            // RelatedID is BookingID - go to bookings list
            if (!empty($relatedId)) {
                return "bookings_netflix.php?tab=all&booking_id=" . urlencode($relatedId);
            }
            return "bookings_netflix.php?tab=all";
        case 'payment':
        case 'payment_confirmation':
            // RelatedID is PaymentID - go to payments page
            if (!empty($relatedId)) {
                return "payments.php?payment_id=" . urlencode($relatedId);
            }
            return "payments.php";
        case 'feedback':
        case 'review':
            // RelatedID is FeedbackID - go to feedback page
            if (!empty($relatedId)) {
                return "feedback_owner.php?feedback_id=" . urlencode($relatedId);
            }
            return "feedback_owner.php";
        case 'message':
        case 'new_message':
            // RelatedID is ClientID - go to messages page
            if (!empty($relatedId)) {
                return "messages.php?partner_id=" . urlencode($relatedId);
            }
            return "messages.php";
        default:
            // Safe fallback
            return "bookings_netflix.php";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>MuSeek - Notifications</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        body {
            font-family: "Inter", sans-serif;
        }

        .sidebar {
            display: none;
            height: 100vh;
            width: 250px;
            position: fixed;
            z-index: 40;
            top: 0;
            left: 0;
            transition: transform 0.3s ease;
        }

        .sidebar.active {
            display: block;
        }

        .main-content {
            transition: margin-left 0.3s ease;
        }

        .avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 9999px;
            background-color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid #222222;
            transition: background-color 0.2s;
        }

        .notification-item:hover {
            background-color: #111111;
        }

        .notification-item.unread {
            background-color: rgba(220, 38, 38, 0.05);
        }

        .notification-item.unread:hover {
            background-color: rgba(220, 38, 38, 0.1);
        }

        .notification-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            bottom: 100%;
            margin-bottom: 5px;
            background-color: #0a0a0a;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 1;
            border-radius: 0.375rem;
            border: 1px solid #222222;
        }

        .dropdown-content a {
            color: white;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 0.875rem;
        }

        .dropdown-content a:hover {
            background-color: #222222;
        }

        .dropdown:hover .dropdown-content {
            display: block;
        }

        .toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 1rem;
            background-color: #0a0a0a;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 100;
            max-width: 24rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transform: translateX(150%);
            transition: transform 0.3s ease;
        }

        .toast.show {
            transform: translateX(0);
        }
    </style>
</head>

<body class="bg-[#161616] text-white">
<?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <!-- Main Content -->
    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center justify-between h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-6">Notifications</h1>
            <?php if ($unreadNotifications > 0): ?>
                <a href="notifications_netflix.php?mark_all_read=1" class="text-sm text-red-500 hover:text-red-400">
                    Mark all as read
                </a>
            <?php endif; ?>
        </header>

        <div class="p-6">
            <div class="bg-[#0a0a0a] rounded-lg border border-[#222222] overflow-hidden">
                <?php if (empty($notifications)): ?>
                    <div class="p-8 text-center">
                        <div class="flex justify-center mb-4">
                            <div class="w-16 h-16 rounded-full bg-[#222222] flex items-center justify-center">
                                <i class="fas fa-bell text-2xl text-gray-400"></i>
                            </div>
                        </div>
                        <h3 class="text-lg font-medium mb-2">No notifications yet</h3>
                        <p class="text-gray-400">You'll see notifications about bookings, payments, and other activities here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <?php
                        $isUnread = $notification['IsRead'] == 0;
                        // Use RelatedID directly from notification record
                        $redirectUrl = getNotificationRedirectUrl($notification['Type'], $notification['RelatedID'] ?? null);
                        ?>
                        <div class="notification-item <?php echo $isUnread ? 'unread' : ''; ?>">
                            <a href="notifications_netflix.php?mark_read=<?php echo $notification['NotificationID']; ?>&redirect=<?php echo urlencode($redirectUrl); ?>" class="flex items-start gap-4">
                                <div class="notification-icon bg-[#222222]">
                                    <?php echo getNotificationIcon($notification['Type']); ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between mb-1">
                                        <h3 class="font-medium <?php echo $isUnread ? 'text-white' : 'text-gray-300'; ?>">
                                            <?php echo htmlspecialchars($notification['Type']); ?>
                                        </h3>
                                        <span class="text-xs text-gray-400">
                                            <?php echo formatNotificationTime($notification['Created_At']); ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-400">
                                        <?php echo htmlspecialchars($notification['Message']); ?>
                                    </p>
                                    <?php if (!empty($notification['ClientName'])): ?>
                                        <div class="flex items-center gap-2 mt-2">
                                            <div class="avatar" style="width: 1.5rem; height: 1.5rem; font-size: 0.625rem;">
                                                <?php echo getInitials($notification['ClientName']); ?>
                                            </div>
                                            <span class="text-xs text-gray-500"><?php echo htmlspecialchars($notification['ClientName']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isUnread): ?>
                                    <div class="w-2 h-2 rounded-full bg-red-600 mt-2"></div>
                                <?php endif; ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Toast Notification -->
    <div id="toast" class="toast">
        <div class="notification-icon bg-[#222222]">
            <i class="fas fa-bell text-red-500"></i>
        </div>
        <div>
            <h4 class="font-medium text-sm">New Notification</h4>
            <p id="toastMessage" class="text-xs text-gray-400">You have a new notification</p>
        </div>
    </div>

    <script>
        // Sidebar interactions are handled by sidebar_netflix.php

        // Show toast notification (used for manual testing or if WebSocket is re-enabled)
        function showToast(message, type = 'default') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');

            // Set message
            toastMessage.textContent = message;

            // Set icon based on type
            let icon = 'fas fa-bell text-red-500';
            if (type === 'Booking') {
                icon = 'far fa-calendar-alt text-blue-500';
            } else if (type === 'Payment') {
                icon = 'fas fa-dollar-sign text-green-500';
            }

            toast.querySelector('.notification-icon i').className = icon;

            // Show toast
            toast.classList.add('show');

            // Hide after 5 seconds
            setTimeout(() => {
                toast.classList.remove('show');
            }, 5000);
        }

        /* 
         * WebSocket and Push Notification functionality is commented out due to missing dependencies.
         * To re-enable:
         * 1. Set up a WebSocket server on ws://localhost:8080 (e.g., using PHP Ratchet or Node.js).
         * 2. Create sw.js for Service Worker and save_subscription.php to store push subscriptions.
         * 3. Generate VAPID keys and replace 'YOUR_VAPID_PUBLIC_KEY' with the public key.
         */
        /*
        // Push Notification functionality
        let swRegistration = null;
        const applicationServerPublicKey = 'YOUR_VAPID_PUBLIC_KEY'; // Replace with your VAPID public key
        
        function initializeNotifications() {
            if ('serviceWorker' in navigator && 'PushManager' in window) {
                console.log('Service Worker and Push are supported');
                
                navigator.serviceWorker.register('sw.js')
                    .then(function(swReg) {
                        console.log('Service Worker is registered', swReg);
                        swRegistration = swReg;
                        checkNotificationPermission();
                    })
                    .catch(function(error) {
                        console.error('Service Worker Error', error);
                    });
            } else {
                console.warn('Push messaging is not supported');
            }
        }
        
        function checkNotificationPermission() {
            if (Notification.permission === 'granted') {
                console.log('Notification permission granted');
                subscribeUserToPush();
            } else if (Notification.permission !== 'denied') {
                requestNotificationPermission();
            }
        }
        
        function requestNotificationPermission() {
            Notification.requestPermission()
                .then(function(permission) {
                    if (permission === 'granted') {
                        console.log('Notification permission granted');
                        subscribeUserToPush();
                    } else {
                        console.log('Unable to get permission to notify');
                    }
                });
        }
        
        function subscribeUserToPush() {
            const applicationServerKey = urlB64ToUint8Array(applicationServerPublicKey);
            
            swRegistration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey
            })
            .then(function(subscription) {
                console.log('User is subscribed:', subscription);
                updateSubscriptionOnServer(subscription);
            })
            .catch(function(err) {
                console.log('Failed to subscribe the user: ', err);
            });
        }
        
        function updateSubscriptionOnServer(subscription) {
            const subscriptionJson = subscription.toJSON();
            
            fetch('save_subscription.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    endpoint: subscriptionJson.endpoint,
                    keys: {
                        p256dh: subscriptionJson.keys.p256dh,
                        auth: subscriptionJson.keys.auth,
                    },
                }),
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Bad status code from server.');
                }
                return response.json();
            })
            .then(function(responseData) {
                if (!(responseData && responseData.success)) {
                    throw new Error('Bad response from server.');
                }
                console.log('Subscription saved on server');
            })
            .catch(function(err) {
                console.log('Error saving subscription', err);
            });
        }
        
        function urlB64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding)
                .replace(/\-/g, '+')
                .replace(/_/g, '/');
                
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }
        
        // Initialize WebSocket for real-time notifications
        function initializeWebSocket() {
            if ('WebSocket' in window) {
                const socket = new WebSocket('ws://localhost:8080');
                
                socket.addEventListener('open', function(event) {
                    console.log('WebSocket connection established');
                    socket.send(JSON.stringify({
                        type: 'identify',
                        ownerId: '<?php echo $ownerId; ?>'
                    }));
                });
                
                socket.addEventListener('message', function(event) {
                    const data = JSON.parse(event.data);
                    console.log('Message from server:', data);
                    
                    if (data.type === 'notification') {
                        const badge = document.getElementById('notificationBadge');
                        if (badge) {
                            badge.textContent = parseInt(badge.textContent || '0') + 1;
                        } else {
                            const notificationLink = document.querySelector('a[href="notifications_netflix.php"]');
                            if (notificationLink) {
                                const newBadge = document.createElement('span');
                                newBadge.id = 'notificationBadge';
                                newBadge.className = 'ml-auto bg-white text-red-600 text-xs font-bold px-1.5 py-0.5 rounded-full';
                                newBadge.textContent = '1';
                                notificationLink.appendChild(newBadge);
                            }
                        }
                        showToast(data.message, data.notificationType);
                    }
                });
                
                socket.addEventListener('close', function(event) {
                    console.log('WebSocket connection closed');
                    setTimeout(initializeWebSocket, 5000);
                });
                
                socket.addEventListener('error', function(event) {
                    console.error('WebSocket error:', event);
                });
            } else {
                console.warn('WebSocket is not supported by your browser');
            }
        }
        */

        // Initialize notifications when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            // initializeNotifications();
            // initializeWebSocket();
        });
    </script>
</body>

</html>