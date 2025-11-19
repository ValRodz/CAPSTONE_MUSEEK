<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

$pageTitle = 'Notifications';
include __DIR__ . '/views/components/header.php';

$notifications = [
    ['type' => 'registration', 'title' => 'New Studio Registration', 'message' => 'Fitness First submitted registration', 'time' => '2 hours ago', 'read' => false],
    ['type' => 'document', 'title' => 'Document Uploaded', 'message' => 'Owner uploaded business permit', 'time' => '5 hours ago', 'read' => false],
    ['type' => 'decision', 'title' => 'Studio Approved', 'message' => 'Yoga Haven was approved', 'time' => '1 day ago', 'read' => true],
];

// Count unread notifications
$unreadCount = count(array_filter($notifications, fn($n) => !$n['read']));
$_SESSION['unread_notifications'] = $unreadCount;
?>

<div class="page-header">
    <h1>Notifications</h1>
    <p>Stay updated with system alerts</p>
</div>

<div class="card">
    <div class="card-header flex-between">
        <h2>All Notifications</h2>
        <a href="#" class="link" onclick="markAllAsRead(event)">Mark all as read</a>
    </div>
    <div class="notifications-list">
        <?php foreach ($notifications as $notif): ?>
        <div class="notification-item <?= $notif['read'] ? '' : 'unread' ?>">
            <div class="notification-icon">
                <?php if ($notif['type'] === 'registration'): ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                    </svg>
                <?php elseif ($notif['type'] === 'document'): ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <path d="M9 15h6"></path>
                    </svg>
                <?php else: ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                <?php endif; ?>
            </div>
            <div class="notification-content">
                <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                <div class="notification-message"><?= htmlspecialchars($notif['message']) ?></div>
                <div class="notification-time"><?= htmlspecialchars($notif['time']) ?></div>
            </div>
            <?php if (!$notif['read']): ?>
                <div class="notification-dot"></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.notification-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--card);
    border-bottom: 1px solid var(--border);
    position: relative;
}

.notification-item.unread {
    background: #f0f9ff;
    font-weight: 500;
}

.notification-icon {
    width: 40px;
    height: 40px;
    background: var(--light);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.notification-icon svg {
    color: var(--primary);
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 2px;
}

.notification-message {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 4px;
}

.notification-time {
    font-size: 12px;
    color: var(--text-muted);
}

.notification-dot {
    width: 8px;
    height: 8px;
    background: var(--primary);
    border-radius: 50%;
    position: absolute;
    top: 16px;
    right: 16px;
}

/* Dark mode for unread notifications */
[data-theme="dark"] .notification-item.unread {
    background: #1e3a5f;
}
</style>

<script>
function markAllAsRead(e) {
    e.preventDefault();
    
    // Remove unread class from all notifications
    document.querySelectorAll('.notification-item.unread').forEach(item => {
        item.classList.remove('unread');
    });
    
    // Remove all notification dots
    document.querySelectorAll('.notification-dot').forEach(dot => {
        dot.remove();
    });
    
    // Update session via AJAX
    fetch('../admin/api/mark-notifications-read.php', {
        method: 'POST'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Update the badge in navbar (if it exists)
            const badge = document.getElementById('notifBadge');
            if (badge) {
                badge.style.display = 'none';
            }
            
            // Also update badge in parent window if opened from navbar
            if (window.opener) {
                const parentBadge = window.opener.document.getElementById('notifBadge');
                if (parentBadge) parentBadge.style.display = 'none';
            }
        }
    });
    
    // Show success message
    alert('All notifications marked as read');
}
</script>

<?php include __DIR__ . '/views/components/footer.php'; ?>