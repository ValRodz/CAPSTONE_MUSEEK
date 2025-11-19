<?php
include __DIR__ . '/../config/db.php';
include __DIR__ . '/../config/path_config.php';

$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']);

if ($is_authenticated) {
    $client_query = "SELECT Name, Email, Phone, ProfileImg FROM clients WHERE ClientID = ?";
    $stmt = mysqli_prepare($conn, $client_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $client_result = mysqli_stmt_get_result($stmt);
    $client = mysqli_fetch_assoc($client_result) ?: [
        'Name' => 'Unknown',
        'Email' => 'N/A',
        'Phone' => 'N/A',
        'ProfileImg' => ''
    ];
    mysqli_stmt_close($stmt);
} else {
    $client = [
        'Name' => 'Guest',
        'Email' => 'N/A',
        'Phone' => 'N/A'
    ];
}

// Add unread notifications count (owner/client-aware)
$notif_count = 0;
if ($is_authenticated) {
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner') {
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM notifications WHERE OwnerID = ? AND IsRead = 0 AND For_User = 'Owner'");
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) { $row = mysqli_fetch_assoc($res); $notif_count = isset($row['cnt']) ? (int)$row['cnt'] : 0; }
        mysqli_stmt_close($stmt);
    } else if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'client') {
        // Client notifications: only show notifications where For_User = 'Client'
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM notifications WHERE ClientID = ? AND IsRead = 0 AND For_User = 'Client'");
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) { $row = mysqli_fetch_assoc($res); $notif_count = isset($row['cnt']) ? (int)$row['cnt'] : 0; }
        mysqli_stmt_close($stmt);
    }
}

// Get the current page name
$current_page = basename($_SERVER['PHP_SELF']);

// Determine the base path based on the current file location
$current_dir = dirname($_SERVER['SCRIPT_NAME']);
$base_path = '';

// Calculate the correct base path to the museek root
if (
    strpos($current_dir, '/client/php') !== false || strpos($current_dir, '/booking/php') !== false ||
    strpos($current_dir, '/admin/php') !== false || strpos($current_dir, '/auth/php') !== false ||
    strpos($current_dir, '/payment/php') !== false || strpos($current_dir, '/messaging/php') !== false ||
    strpos($current_dir, '/instructor/php') !== false
) {
    $base_path = '../../';
} else {
    $base_path = '';
}
?>

<header class="site-header">
    <div class="container">
        <a href="/" id="branding">
            <img src="<?php echo getImagePath('images/logo4.png'); ?>" alt="Site Name">
        </a>
        <nav class="main-navigation">
            <button type="button" class="toggle-menu"><i class="fas fa-bars"></i></button>
            <ul class="menu">
               <li class="menu-item <?php echo $current_page === 'index.php' ? 'current-menu-item' : ''; ?>"><a href="/">Home</a></li>
               <li class="menu-item <?php echo $current_page === 'aboutpage.php' ? 'current-menu-item' : ''; ?>"><a href="<?php echo $base_path; ?>client/php/aboutpage.php">About Us</a></li>
                <li class="menu-item <?php echo $current_page === 'prices.php' ? 'current-menu-item' : ''; ?>"><a href="<?php echo $base_path; ?>client/php/prices.php">Prices</a></li>
                <?php if ($is_authenticated): ?>
                    <li class="menu-item <?php echo $current_page === 'chat_list.php' ? 'current-menu-item' : ''; ?>"><a href="<?php echo $base_path; ?>messaging/php/chat_list.php">Messages</a></li>
                    <!-- Notifications bell with unread badge -->
                    <li class="menu-item notifications-item">
                        <a class="notifications-link" href="#" title="Notifications" id="notifBell">
                            <i class="fas fa-bell"></i>
                            <?php if ($notif_count > 0): ?><span class="notification-badge" id="notifBadge"><?php echo $notif_count; ?></span><?php endif; ?>
                        </a>
                        <div class="notifications-dropdown" id="notifDropdown">
                            <div class="nd-header">
                                <span class="nd-title">Notifications</span>
                                <button type="button" class="nd-action" id="markAllReadBtn">Mark all read</button>
                            </div>
                            <div class="nd-body">
                                <div class="nd-loading" id="notifLoading">Loading...</div>
                                <ul class="nd-list" id="notifList"></ul>
                                <div class="nd-empty" id="notifEmpty" style="display:none;">No notifications yet</div>
                            </div>
                            <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner'): ?>
                            <div class="nd-footer">
                                <a href="<?php echo $base_path; ?>owners/php/notifications_netflix.php" class="nd-view-all">View all</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </li>
                    <li class="menu-item profile-item">
                        <a class="profile-link"><i class="fas fa-user-circle"></i> My Account</a>
                        <div class="profile-dropdown">
                            <div class="profile-header">
                    <div class="profile-avatar" id="navbarAvatar">
                                    <?php
                                    $avatarRel = isset($client['ProfileImg']) ? $client['ProfileImg'] : '';
                                    if (!empty($avatarRel)) {
                                        $src = getImagePath($avatarRel);
                                        echo '<img src="'.htmlspecialchars($src).'" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
                                    } else {
                                        echo '<i class="fas fa-headphones-alt"></i>';
                                    }
                                    ?>
                                </div>
                                <div class="profile-name">
                                    <h4><?php echo htmlspecialchars($client['Name']); ?></h4>
                                    <span class="profile-status">MuSeek Client</span>
                                </div>
                            </div>
                            <div class="profile-actions">
                                <a href="<?php echo $base_path; ?>client/php/client_profile.php" class="profile-action">
                                    <i class="fas fa-id-card"></i> View Profile
                                </a>
                                <a href="<?php echo $base_path; ?>client/php/client_bookings.php" class="profile-action">
                                    <i class="fas fa-calendar"></i> My Bookings
                                </a>
                                <button class="profile-action logout-action" onclick="confirmLogout()">
                                    <i class="fas fa-sign-out-alt"></i> Log Out
                                </button>
                            </div>
                        </div>
                    </li>
                <?php else: ?>
                    <li class="menu-item <?php echo $current_page === 'login.html' ? 'current-menu-item' : ''; ?>"><a href="<?php echo $base_path; ?>auth/php/login.php">Log In</a></li>
                <?php endif; ?>
            </ul>
        </nav>
        <div class="mobile-menu"></div>
    </div>
</header>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const profileLink = document.querySelector('.profile-link');
        const profileDropdown = document.querySelector('.profile-dropdown');

        if (profileLink && profileDropdown) {
            // Toggle dropdown on profile link click
            profileLink.addEventListener('click', function(e) {
                profileDropdown.classList.toggle('show');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!profileLink.contains(e.target) && !profileDropdown.contains(e.target)) {
                    profileDropdown.classList.remove('show');
                }
            });
        }

        // Notifications dropdown logic
        const notifBell = document.getElementById('notifBell');
        const notifDropdown = document.getElementById('notifDropdown');
        const notifList = document.getElementById('notifList');
        const notifLoading = document.getElementById('notifLoading');
        const notifEmpty = document.getElementById('notifEmpty');
        const notifBadge = document.getElementById('notifBadge');
        const markAllReadBtn = document.getElementById('markAllReadBtn');
        let notifLoadedOnce = false;

        const basePath = '<?php echo $base_path; ?>';
        const userType = '<?php echo isset($_SESSION['user_type']) ? strtolower($_SESSION['user_type']) : ''; ?>';

        function formatTimeAgo(dateStr) {
            try {
                const ts = new Date(dateStr);
                const now = new Date();
                const diffMs = now - ts;
                const sec = Math.floor(diffMs / 1000);
                const min = Math.floor(sec / 60);
                const hr = Math.floor(min / 60);
                const day = Math.floor(hr / 24);
                if (day > 0) return `${day}d ago`;
                if (hr > 0) return `${hr}h ago`;
                if (min > 0) return `${min}m ago`;
                return `${sec}s ago`;
            } catch (e) {
                return '';
            }
        }

        function getRedirectUrl(item) {
            const type = (item.type || '').toLowerCase();
            const id = item.relatedId || item.id || null;
            if (type.includes('payment')) {
                if (userType === 'owner') {
                    // Include RelatedID to highlight the payment
                    if (id) return basePath + 'owners/php/payments.php?highlight=' + encodeURIComponent(id);
                    return basePath + 'owners/php/payments.php';
                }
                // Client: try to deep-link to payment for a booking if available
                if (id) return basePath + 'payment/php/payment.php?booking_id=' + encodeURIComponent(id);
                return basePath + 'client/php/client_bookings.php';
            }
            if (type.includes('message')) {
                // Unified chat redirect
                return basePath + (userType === 'owner' ? 'owners/php/messages.php' : 'messaging/php/chat_list.php');
            }
            if (type.includes('feedback')) {
                // Feedback notification - owner only
                if (userType === 'owner') {
                    if (id) return basePath + 'owners/php/feedback_owner.php?feedback_id=' + encodeURIComponent(id);
                    return basePath + 'owners/php/feedback_owner.php';
                }
                return basePath + 'client/php/client_bookings.php';
            }
            // Default to booking-related
            if (userType === 'owner') {
                // If booking id exists, go to booking confirmation; else bookings list with highlight
                if (id) return basePath + 'owners/php/booking_confirmation.php?booking_id=' + encodeURIComponent(id);
                return basePath + 'owners/php/bookings_netflix.php';
            }
            // Client: redirect to bookings page with highlight parameter
            if (id) return basePath + 'client/php/client_bookings.php?highlight=' + encodeURIComponent(id);
            return basePath + 'client/php/client_bookings.php';
        }

        function renderNotifications(items) {
            notifList.innerHTML = '';
            let unread = 0;
            items.forEach(item => {
                if (!item.isRead) unread++;
                const li = document.createElement('li');
                li.className = `nd-item ${item.isRead ? '' : 'unread'}`;
                li.dataset.id = item.id;
                li.dataset.type = item.type || '';
                li.dataset.relatedId = item.relatedId || '';
                const isPayment = (item.type || '').toLowerCase().includes('payment');
                const isMessage = (item.type || '').toLowerCase().includes('message');
                const isFeedback = (item.type || '').toLowerCase().includes('feedback');
                const iconClass = isPayment ? 'fa-credit-card' : (isMessage ? 'fa-comment' : (isFeedback ? 'fa-star' : 'fa-calendar'));
                const actionLabel = item.isRead ? 'View' : 'Mark read';
                li.innerHTML = `
                    <div class="nd-item-icon"><i class="fas ${iconClass}"></i></div>
                    <div class="nd-item-content">
                        <div class="nd-item-message">${item.message || ''}</div>
                        <div class="nd-item-meta">${formatTimeAgo(item.createdAt || '')}</div>
                    </div>
                    <button type="button" class="nd-item-action" data-id="${item.id}" data-type="${item.type || ''}" data-related-id="${item.relatedId || ''}">${actionLabel}</button>
                `;
                notifList.appendChild(li);
            });

            notifEmpty.style.display = items.length ? 'none' : 'block';
            if (notifBadge) {
                if (unread > 0) {
                    notifBadge.textContent = unread;
                    notifBadge.style.display = 'inline-block';
                } else {
                    notifBadge.style.display = 'none';
                }
            }
        }

        async function loadNotifications() {
            notifLoading.style.display = 'block';
            notifList.innerHTML = '';
            notifEmpty.style.display = 'none';
            try {
                const res = await fetch(basePath + 'shared/php/get_notifications.php?limit=10', { credentials: 'same-origin' });
                const data = await res.json();
                const items = (data && data.success) ? data.data : [];
                renderNotifications(items);
            } catch (e) {
                notifEmpty.style.display = 'block';
            } finally {
                notifLoading.style.display = 'none';
                notifLoadedOnce = true;
            }
        }

        function hideNotifDropdownOnOutsideClick(e) {
            if (!notifDropdown.contains(e.target) && !notifBell.contains(e.target)) {
                notifDropdown.classList.remove('show');
                document.removeEventListener('click', hideNotifDropdownOnOutsideClick);
            }
        }

        if (notifBell && notifDropdown) {
            notifBell.addEventListener('click', function(e) {
                e.preventDefault();
                const willShow = !notifDropdown.classList.contains('show');
                notifDropdown.classList.toggle('show');
                if (willShow && !notifLoadedOnce) {
                    loadNotifications();
                }
                if (willShow) {
                    setTimeout(() => document.addEventListener('click', hideNotifDropdownOnOutsideClick), 0);
                }
            });

            // Redirect when clicking an item (and mark it read)
            notifList.addEventListener('click', async function(e) {
                const li = e.target.closest('.nd-item');
                const btn = e.target.closest('.nd-item-action');
                if (!li && !btn) return;

                const id = parseInt((btn ? btn.dataset.id : li.dataset.id) || '0', 10);
                const type = (btn ? btn.dataset.type : li.dataset.type) || '';
                const relatedId = (btn ? btn.dataset.relatedId : li.dataset.relatedId) || '';

                // If clicking the button and label is Mark read, only mark
                if (btn && btn.textContent.trim().toLowerCase() === 'mark read') {
                    try {
                        const resp = await fetch(basePath + 'shared/php/mark_notification_read.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ notification_id: id })
                        });
                        const json = await resp.json();
                        if (json && json.success) loadNotifications();
                    } catch (err) {}
                    return;
                }

                // Otherwise, compute redirect and mark-as-read then go
                const url = getRedirectUrl({ id, type, relatedId });
                try {
                    await fetch(basePath + 'shared/php/mark_notification_read.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ notification_id: id })
                    });
                } catch (err) {}
                window.location.href = url;
            });

            // Mark all as read
            markAllReadBtn?.addEventListener('click', async function() {
                try {
                    const resp = await fetch(basePath + 'shared/php/mark_all_notifications_read.php', { method: 'POST' });
                    const json = await resp.json();
                    if (json && json.success) {
                        loadNotifications();
                    }
                } catch (err) {}
            });
        }

        // Logout function
        window.confirmLogout = function() {
            if (window.confirm("Are you sure you want to log out?")) {
                window.location.href = basePath + "auth/php/logout.php";
            }
        };
    });
</script>
<style>
    /* Ensure header overlays map and other content */
    .site-header {
        position: relative;
        z-index: 10000;
        background: rgba(0, 0, 0, 0.65);
    }

    .profile-item {
        position: relative;
        z-index: 10001;
    }

    .profile-link {
        display: flex;
        align-items: center;
        color: #fff;
        text-decoration: none;
        transition: color 0.3s;
        cursor: pointer;
        padding: 10px;
    }

    .profile-link i {
        margin-right: 5px;
        font-size: 18px;
    }

    .profile-link:hover {
        color: #e50914;
    }

    /* Notifications bell */
    .notifications-item { position: relative; }
    .notifications-link {
        position: relative;
        display: inline-flex;
        align-items: center;
        color: #fff;
        text-decoration: none;
        transition: color 0.3s;
        padding: 10px;
    }
    .notifications-link i { font-size: 18px; }
    .notifications-link:hover { color: #e50914; }
    .notification-badge {
        position: absolute;
        top: 6px;
        right: 6px;
        background: #e50914;
        color: #fff;
        border-radius: 10px;
        padding: 0 6px;
        font-size: 12px;
        line-height: 18px;
        min-width: 18px;
        text-align: center;
        border: 1px solid rgba(255,255,255,0.2);
    }
    /* Dropdown panel */
    .notifications-dropdown {
        visibility: hidden;
        opacity: 0;
        position: absolute;
        top: 100%;
        right: 0;
        width: 360px;
        max-height: 480px;
        overflow: hidden;
        background: rgba(0,0,0,0.95);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        transition: opacity 0.2s ease, visibility 0.2s ease;
        z-index: 10002;
    }
    .notifications-dropdown.show { visibility: visible; opacity: 1; }
    .nd-header { display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border-bottom:1px solid rgba(255,255,255,0.08); }
    .nd-title { color:#fff; font-weight:600; }
    .nd-action { background:none; border:none; color:#e50914; cursor:pointer; font-size:12px; }
    .nd-body { padding: 8px 0; max-height: 380px; overflow-y:auto; }
    .nd-loading { color:#bbb; padding:12px; }
    .nd-list { list-style:none; margin:0; padding:0; }
    .nd-item { display:flex; align-items:flex-start; gap:10px; padding:10px 12px; border-bottom:1px solid rgba(255,255,255,0.06); cursor:pointer; }
    .nd-item.unread { background: rgba(229,9,20,0.08); }
    .nd-item-icon { width:32px; height:32px; border-radius:50%; background:#1f1f1f; display:flex; align-items:center; justify-content:center; color:#fff; }
    .nd-item-content { flex:1; min-width:0; }
    .nd-item-message { color:#fff; font-size:14px; }
    .nd-item-meta { color:#aaa; font-size:12px; margin-top:3px; }
    .nd-item-action { background:none; border:1px solid rgba(255,255,255,0.2); color:#fff; font-size:12px; padding:6px 8px; border-radius:6px; cursor:pointer; }
    .nd-footer { padding:8px 12px; border-top:1px solid rgba(255,255,255,0.08); text-align:right; }
    .nd-view-all { color:#e50914; text-decoration:none; font-size:12px; }

    .profile-dropdown {
        visibility: hidden;
        opacity: 0;
        position: absolute;
        top: 100%;
        right: 0;
        background: rgba(0, 0, 0, 0.9);
        border-radius: 5px;
        padding: 10px;
        width: 220px;
        z-index: 10002;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
        transition: visibility 0.3s, opacity 0.3s;
        font-size: 13px;
    }

    .profile-dropdown.show {
        visibility: visible;
        opacity: 1;
    }

    .profile-header {
        display: flex;
        align-items: center;
        padding-bottom: 10px;
        margin-bottom: 10px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .profile-avatar {
        border-radius: 50%;
        background: linear-gradient(135deg, #e50914, #8b0000);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 8px;
        overflow: hidden;
    }

    .profile-avatar i {
        color: white;
        line-height: 1;
    }

    /* Navbar-specific avatar size */
    #navbarAvatar {
        width: 22px;
        height: 22px;
        min-width: 22px;
        min-height: 22px;
    }

    #navbarAvatar i { font-size: 12px; }

    .profile-name h4 {
        margin: 0 0 3px;
        color: #fff;
        font-size: 14px;
    }

    .profile-status {
        color: #e50914;
        font-size: 11px;
        font-weight: 500;
    }

    .profile-actions {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .profile-action {
        display: flex;
        align-items: center;
        padding: 6px 8px;
        color: #fff;
        text-decoration: none;
        border-radius: 4px;
        transition: background-color 0.2s;
        font-size: 13px;
        line-height: 1.3;
    }

    .profile-action i {
        margin-right: 8px;
        width: 16px;
        text-align: center;
    }

    .profile-action:hover {
        background-color: rgba(255, 255, 255, 0.1);
        color: #e50914;
    }

    .logout-action {
        background: none;
        border: none;
        cursor: pointer;
        text-align: left;
        font-family: inherit;
        margin-top: 4px;
    }

    @media (max-width: 768px) {
        .profile-link i {
            font-size: 16px;
        }

        .profile-link {
            padding: 8px;
        }

        .profile-dropdown {
            width: 180px;
            padding: 8px;
            right: 0;
        }

        .profile-info p {
            font-size: 12px;
        }

        .logout-button {
            padding: 6px;
            font-size: 12px;
        }

        .main-navigation .menu {
            position: relative;
        }
    }
</style>
