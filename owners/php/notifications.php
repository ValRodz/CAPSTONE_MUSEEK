<?php
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/db.php';

$ownerId = $_SESSION['user_id'];

// Mark all notifications as read when visiting this page
if (isset($_GET['mark_all_read'])) {
    $markRead = $pdo->prepare("
        UPDATE notifications 
        SET IsRead = 1 
        WHERE OwnerID = ?
    ");
    $markRead->execute([$ownerId]);
    header("Location: notifications.php");
    exit();
}

// Mark a specific notification as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notificationId = $_GET['mark_read'];
    $markRead = $pdo->prepare("
        UPDATE notifications 
        SET IsRead = 1 
        WHERE NotificationID = ? AND OwnerID = ?
    ");
    $markRead->execute([$notificationId, $ownerId]);
    
    // If there's a redirect URL, go there
    if (isset($_GET['redirect'])) {
        header("Location: " . $_GET['redirect']);
        exit();
    }
    
    header("Location: notifications.php");
    exit();
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$typeFilter = isset($_GET['type']) ? $_GET['type'] : '';

// Build query
$query = "
    SELECT n.NotificationID, n.Type, n.Message, n.RelatedID, n.IsRead, n.Created_At,
           c.Name as client_name, c.Email as client_email
    FROM notifications n
    LEFT JOIN clients c ON n.ClientID = c.ClientID
    WHERE n.OwnerID = ?
";

$params = [$ownerId];

if ($filter === 'unread') {
    $query .= " AND n.IsRead = 0";
} elseif ($filter === 'read') {
    $query .= " AND n.IsRead = 1";
}

if (!empty($typeFilter)) {
    $query .= " AND n.Type = ?";
    $params[] = $typeFilter;
}

$query .= " ORDER BY n.Created_At DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts
$totalCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE OwnerID = ?");
$totalCount->execute([$ownerId]);
$totalNotifications = $totalCount->fetchColumn();

$unreadCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE OwnerID = ? AND IsRead = 0");
$unreadCount->execute([$ownerId]);
$unreadNotifications = $unreadCount->fetchColumn();

$readCount = $totalNotifications - $unreadNotifications;

// Get unique notification types
$typesStmt = $pdo->prepare("
    SELECT DISTINCT Type 
    FROM notifications 
    WHERE OwnerID = ? 
    ORDER BY Type
");
$typesStmt->execute([$ownerId]);
$notificationTypes = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

// Helper function to get notification icon
function getNotificationIcon($type) {
    switch (strtolower($type)) {
        case 'booking':
            return 'fas fa-calendar-check';
        case 'payment':
            return 'fas fa-credit-card';
        case 'message':
            return 'fas fa-comment';
        case 'system':
            return 'fas fa-cog';
        case 'alert':
            return 'fas fa-exclamation-triangle';
        default:
            return 'fas fa-bell';
    }
}

// Helper function to get notification color
function getNotificationColor($type) {
    switch (strtolower($type)) {
        case 'booking':
            return 'var(--info-blue)';
        case 'payment':
            return 'var(--success-green)';
        case 'message':
            return 'var(--netflix-red)';
        case 'system':
            return 'var(--netflix-gray)';
        case 'alert':
            return 'var(--warning-orange)';
        default:
            return 'var(--netflix-light-gray)';
    }
}

// Helper function to format time
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . 'm ago';
    if ($time < 86400) return floor($time/3600) . 'h ago';
    if ($time < 2592000) return floor($time/86400) . 'd ago';
    if ($time < 31536000) return floor($time/2592000) . 'mo ago';
    return floor($time/31536000) . 'y ago';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - MuSeek Studio</title>
    
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

        .notifications-container {
            padding: 40px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 40px;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--netflix-white);
            margin-bottom: 10px;
            background: linear-gradient(45deg, var(--netflix-white), var(--netflix-light-gray));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            font-size: 1.1rem;
            color: var(--netflix-light-gray);
            margin-bottom: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid #333;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--netflix-red), #ff6b6b);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .stat-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 20px;
        }

        .stat-icon.total {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
        }

        .stat-icon.unread {
            background: linear-gradient(135deg, var(--warning-orange), #ffa500);
        }

        .stat-icon.read {
            background: linear-gradient(135deg, var(--success-green), #46d369);
        }

        .stat-title {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            margin: 0;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--netflix-white);
            margin: 5px 0 0 0;
        }

        .filters-container {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #333;
        }

        .filters {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-label {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            font-weight: 500;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #333;
            border-radius: 6px;
            background: var(--netflix-black);
            color: var(--netflix-white);
            font-size: 14px;
            min-width: 150px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            color: var(--netflix-white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #d40813, #e50914);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.3);
        }

        .btn-secondary {
            background: var(--netflix-dark-gray);
            color: var(--netflix-white);
            border: 1px solid #333;
        }

        .btn-secondary:hover {
            background: var(--netflix-gray);
        }

        .notifications-list {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #333;
        }

        .list-header {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            padding: 20px;
            color: var(--netflix-white);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .list-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .list-title i {
            margin-right: 10px;
        }

        .notification-item {
            padding: 20px;
            border-bottom: 1px solid #333;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            transition: all 0.2s ease;
            position: relative;
        }

        .notification-item:hover {
            background: rgba(229, 9, 20, 0.05);
        }

        .notification-item.unread {
            background: rgba(229, 9, 20, 0.1);
            border-left: 4px solid var(--netflix-red);
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--netflix-white);
            flex-shrink: 0;
        }

        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            font-weight: 600;
            color: var(--netflix-white);
            margin: 0 0 5px 0;
            font-size: 1rem;
        }

        .notification-message {
            color: var(--netflix-light-gray);
            margin: 0 0 8px 0;
            line-height: 1.4;
        }

        .notification-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.8rem;
            color: var(--netflix-gray);
        }

        .notification-time {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-client {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .mark-read-btn {
            background: none;
            border: none;
            color: var(--netflix-light-gray);
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .mark-read-btn:hover {
            background: var(--netflix-red);
            color: var(--netflix-white);
        }

        .unread-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--netflix-red);
            flex-shrink: 0;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--netflix-light-gray);
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
            
            .notifications-container {
                padding: 20px;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .notification-item {
                padding: 15px;
            }
            
            .notification-actions {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <div class="main-content">
        <div class="notifications-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Notifications</h1>
                <p class="page-subtitle">Stay updated with your studio activities</p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon total">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div>
                            <p class="stat-title">Total Notifications</p>
                            <p class="stat-value"><?php echo $totalNotifications; ?></p>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon unread">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div>
                            <p class="stat-title">Unread</p>
                            <p class="stat-value"><?php echo $unreadNotifications; ?></p>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon read">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <p class="stat-title">Read</p>
                            <p class="stat-value"><?php echo $readCount; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-container fade-in">
                <form method="GET" class="filters">
                    <div class="filter-group">
                        <label class="filter-label">Filter</label>
                        <select name="filter" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Notifications</option>
                            <option value="unread" <?php echo $filter === 'unread' ? 'selected' : ''; ?>>Unread Only</option>
                            <option value="read" <?php echo $filter === 'read' ? 'selected' : ''; ?>>Read Only</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Type</label>
                        <select name="type" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <?php foreach ($notificationTypes as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" 
                                        <?php echo $typeFilter === $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($type)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">&nbsp;</label>
                        <a href="?mark_all_read=1" class="btn btn-primary">
                            <i class="fas fa-check-double"></i>
                            Mark All Read
                        </a>
                    </div>
                </form>
            </div>

            <!-- Notifications List -->
            <div class="notifications-list fade-in">
                <div class="list-header">
                    <h3 class="list-title">
                        <i class="fas fa-bell"></i>
                        Notifications (<?php echo count($notifications); ?>)
                    </h3>
                </div>

                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <h3>No Notifications</h3>
                        <p>You're all caught up! No notifications found for the selected filters.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo $notification['IsRead'] ? '' : 'unread'; ?>">
                            <div class="notification-icon" style="background: <?php echo getNotificationColor($notification['Type']); ?>">
                                <i class="<?php echo getNotificationIcon($notification['Type']); ?>"></i>
                            </div>
                            
                            <div class="notification-content">
                                <h4 class="notification-title">
                                    <?php echo htmlspecialchars(ucfirst($notification['Type'])); ?>
                                    <?php if (!$notification['IsRead']): ?>
                                        <span class="unread-indicator"></span>
                                    <?php endif; ?>
                                </h4>
                                
                                <p class="notification-message">
                                    <?php echo htmlspecialchars($notification['Message']); ?>
                                </p>
                                
                                <div class="notification-meta">
                                    <div class="notification-time">
                                        <i class="fas fa-clock"></i>
                                        <?php echo timeAgo($notification['Created_At']); ?>
                                    </div>
                                    
                                    <?php if ($notification['client_name']): ?>
                                        <div class="notification-client">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($notification['client_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="notification-actions">
                                <?php if (!$notification['IsRead']): ?>
                                    <button class="mark-read-btn" 
                                            onclick="markAsRead(<?php echo $notification['NotificationID']; ?>)"
                                            title="Mark as read">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function markAsRead(notificationId) {
            // Create a form and submit it
            const form = document.createElement('form');
            form.method = 'GET';
            form.action = 'notifications.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'mark_read';
            input.value = notificationId;
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }

        // Auto-refresh every 30 seconds
        setInterval(function() {
            // This would typically fetch new notifications via AJAX
            // For now, we'll just reload the page
            // window.location.reload();
        }, 30000);

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

