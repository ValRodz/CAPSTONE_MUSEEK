<?php
// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    return;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Get user information
if ($user_type === 'owner') {
    $user_query = "SELECT Name, Email FROM studio_owners WHERE OwnerID = ?";
} else {
    $user_query = "SELECT Name, Email FROM clients WHERE ClientID = ?";
}

$user_stmt = $pdo->prepare($user_query);
$user_stmt->execute([$user_id]);
$user_info = $user_stmt->fetch();
// Determine branding: default to MuSeek, override with owner's studio if available
$brandName = 'MuSeek';
$brandImg = '../../shared/assets/images/default_studio.jpg';
if ($user_type === 'owner') {
    try {
        $studioStmt = $pdo->prepare("SELECT StudioName, StudioImg FROM studios WHERE OwnerID = ? ORDER BY StudioID ASC LIMIT 1");
        $studioStmt->execute([$user_id]);
        $studio = $studioStmt->fetch(PDO::FETCH_ASSOC);
        if ($studio) {
            if (!empty($studio['StudioName'])) {
                $brandName = $studio['StudioName'];
            }
            if (!empty($studio['StudioImg'])) {
                // StudioImg is stored as binary in DB; render as Base64 data URL
                $brandImg = 'data:image/jpeg;base64,' . base64_encode($studio['StudioImg']);
            }
        }
    } catch (Exception $e) {
        // Keep defaults on error
    }
}
?>

<!-- Netflix-inspired Collapsible Sidebar -->
<div class="sidebar-netflix" id="sidebar">
    <!-- Account header replaces the studio header; shows owner account -->
    <div class="account-header">
        <div class="user-profile" style="margin-bottom:0;">
            <div class="user-avatar">
                <i class="fas fa-user"></i>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user_info['Name']); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($user_info['Email']); ?></div>
            </div>
        </div>
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav-list">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" title="Dashboard">
                    <i class="fas fa-home nav-icon"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>

            <?php if ($user_type === 'owner'): ?>
                <li class="nav-item">
                    <a href="manage_studio.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_studio.php' ? 'active' : ''; ?>" title="Manage Studios">
                        <i class="fas fa-building nav-icon"></i>
                        <span class="nav-text">Studios</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="manage_services.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_services.php' ? 'active' : ''; ?>" title="Services">
                        <i class="fas fa-concierge-bell nav-icon"></i>
                        <span class="nav-text">Offerings</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="gallery.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'gallery.php' ? 'active' : ''; ?>" title="Gallery">
                        <i class="fas fa-images nav-icon"></i>
                        <span class="nav-text">Gallery</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="schedule.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'schedule.php' ? 'active' : ''; ?>" title="Schedule">
                        <i class="fas fa-calendar-alt nav-icon"></i>
                        <span class="nav-text">Schedule</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="bookings_netflix.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['bookings_netflix.php', 'bookings.php']) ? 'active' : ''; ?>" title="Bookings">
                        <i class="fas fa-calendar-check nav-icon"></i>
                        <span class="nav-text">Bookings</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="payments.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'payments.php' ? 'active' : ''; ?>" title="Payments">
                        <i class="fas fa-credit-card nav-icon"></i>
                        <span class="nav-text">Payments</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="reports_netflix.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports_netflix.php' ? 'active' : ''; ?>" title="Reports">
                        <i class="fas fa-chart-bar nav-icon"></i>
                        <span class="nav-text">Reports</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="feedback_owner.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'feedback_owner.php' ? 'active' : ''; ?>" title="Feedback">
                        <i class="fas fa-star nav-icon"></i>
                        <span class="nav-text">Feedback</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="messages.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>" title="Messages">
                        <i class="fas fa-comments nav-icon"></i>
                        <span class="nav-text">Messages</span>
                        <span class="notification-badge" id="messageBadge" style="display: none;">0</span>
                    </a>
                </li>

            <?php else: ?>
                <li class="nav-item">
                    <a href="home.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'home.php' ? 'active' : ''; ?>" title="Browse Studios">
                        <i class="fas fa-home nav-icon"></i>
                        <span class="nav-text">Browse Studios</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="my_bookings.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'my_bookings.php' ? 'active' : ''; ?>" title="My Bookings">
                        <i class="fas fa-calendar-check nav-icon"></i>
                        <span class="nav-text">My Bookings</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="messages.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>" title="Messages">
                        <i class="fas fa-comments nav-icon"></i>
                        <span class="nav-text">Messages</span>
                        <span class="notification-badge" id="messageBadge" style="display: none;">0</span>
                    </a>
                </li>
            <?php endif; ?>

            <li class="nav-item">
                <a href="notifications_netflix.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : ''; ?>" title="Notifications">
                    <i class="fas fa-bell nav-icon"></i>
                    <span class="nav-text">Notifications</span>
                    <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
                </a>
            </li>


        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="settings_netflix.php" class="logout-btn" title="Settings">
            <i class="fas fa-cog"></i>
            <span class="logout-text">Settings</span>
        </a>
        <a href="../../auth/php/logout.php" class="logout-btn" title="Logout">
            <i class="fas fa-sign-out-alt"></i>
            <span class="logout-text">Logout</span>
        </a>
    </div>
</div>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Floating Drawer Toggle (mobile) -->
<button class="mobile-drawer-toggle" id="mobileDrawerToggle" aria-controls="sidebar" aria-label="Open navigation" aria-expanded="false">
    <i class="fas fa-bars"></i>
</button>

<style>
    /* Netflix-inspired Sidebar Styles */
    :root {
        --netflix-red: #e50914;
        --netflix-black: #141414;
        --netflix-dark-gray: #2f2f2f;
        --netflix-gray: #666666;
        --netflix-light-gray: #b3b3b3;
        --netflix-white: #ffffff;
        --sidebar-width: 280px;
        --sidebar-collapsed-width: 70px;
        --transition-speed: 0.3s;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Netflix Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background-color: var(--netflix-black);
        color: var(--netflix-white);
        overflow-x: hidden;
    }

    /* Utility: hide elements when `hidden` class is applied */
    .hidden {
        display: none !important;
    }

    .sidebar-netflix {
        position: fixed;
        top: 0;
        left: 0;
        width: var(--sidebar-width);
        height: 100vh;
        background: linear-gradient(180deg, var(--netflix-black) 0%, #1a1a1a 100%);
        border-right: 1px solid var(--netflix-dark-gray);
        z-index: 1000;
        transition: all var(--transition-speed) cubic-bezier(0.4, 0, 0.2, 1);
        overflow-y: auto;
        overflow-x: hidden;
    }

    .sidebar-netflix.collapsed {
        width: var(--sidebar-collapsed-width);
    }

    .sidebar-netflix.collapsed .logo-text,
    .sidebar-netflix.collapsed .nav-text,
    .sidebar-netflix.collapsed .user-info,
    .sidebar-netflix.collapsed .logout-text {
        opacity: 0;
        transform: translateX(-20px);
        pointer-events: none;
    }

    .sidebar-netflix.collapsed .nav-link {
        justify-content: center;
        padding: 12px 8px;
        position: relative;
        margin-right: 0;
    }

    .sidebar-netflix.collapsed .nav-link:hover {
        transform: none;
    }

    .sidebar-netflix.collapsed .nav-link .nav-icon {
        margin-right: 0;
        margin-left: 0;
    }

    .sidebar-netflix.collapsed .sidebar-footer {
        padding: 20px 15px;
    }

    .sidebar-netflix.collapsed .user-profile {
        justify-content: center;
    }

    .sidebar-netflix.collapsed .user-avatar {
        margin-right: 0;
    }

    .sidebar-netflix.collapsed .logout-btn {
        justify-content: center;
        padding: 10px;
        width: 48px;
        height: 48px;
        padding: 0;
        margin: 0 auto;
    }

    /* Peeking icons effect when collapsed */
    .sidebar-netflix.collapsed .nav-icon {
        transform: scale(1.1);
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
        opacity: 1 !important;
        visibility: visible !important;
        display: block !important;
    }

    .sidebar-netflix.collapsed .nav-link:hover .nav-icon {
        transform: scale(1.2);
        color: var(--netflix-red);
    }

    .sidebar-header {
        padding: 20px;
        border-bottom: 1px solid var(--netflix-dark-gray);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    /* Account header (owner profile at top) */
    .account-header {
        padding: 14px 20px;
        border-bottom: 1px solid var(--netflix-dark-gray);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .account-header .user-profile {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* Keep avatar visible; hide name/email until hover */
    .account-header .user-info {
        opacity: 0;
        max-width: 0;
        overflow: hidden;
        transition: all var(--transition-speed) ease;
        white-space: nowrap;
    }

    .account-header:hover .user-info {
        opacity: 1;
        max-width: 200px;
    }

    /* Also reveal name/email when hovering anywhere over the sidebar */
    .sidebar-netflix:hover .account-header .user-info {
        opacity: 1;
        max-width: 200px;
    }

    .logo-container {
        display: flex;
        align-items: center;
        transition: all var(--transition-speed) ease;
    }

    .logo {
        width: 40px;
        height: 40px;
        margin-right: 12px;
        border-radius: 4px;
    }

    .logo-text {
        font-size: 16px;
        font-weight: 500;
        color: var(--netflix-red);
        transition: all var(--transition-speed) ease;
    }

    .sidebar-toggle {
        display: none;
    }

    @media (max-width: 768px) {
        .sidebar-toggle {
            display: inline-block;
            background: none;
            border: none;
            color: var(--netflix-white);
            font-size: 18px;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .sidebar-toggle:hover {
            background: var(--netflix-dark-gray);
        }
    }

    .sidebar-nav {
        padding: 20px 0;
        flex: 1;
    }

    .nav-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .nav-item {
        margin-bottom: 4px;
    }

    .nav-link {
        display: flex;
        align-items: center;
        padding: 15px 20px;
        color: var(--netflix-light-gray);
        text-decoration: none;
        transition: all 0.2s ease;
        position: relative;
        border-radius: 0 25px 25px 0;
        margin-right: 20px;
    }

    .nav-link:hover {
        color: var(--netflix-white);
        background: var(--netflix-dark-gray);
        transform: translateX(5px);
    }

    .nav-link.active {
        color: var(--netflix-white);
        background: var(--netflix-red);
        font-weight: 600;
    }

    .nav-link.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background: var(--netflix-white);
        border-radius: 0 2px 2px 0;
    }

    .nav-icon {
        font-size: 18px;
        width: 24px;
        margin-right: 12px;
        text-align: center;
        transition: all var(--transition-speed) ease;
    }

    .nav-text {
        font-size: 14px;
        font-weight: 500;
        transition: all var(--transition-speed) ease;
        opacity: 0;
        max-width: 0;
        overflow: hidden;
        white-space: nowrap;
    }

    .nav-link:hover .nav-text,
    .sidebar-netflix:hover .nav-text {
        opacity: 1;
        max-width: 200px;
        transform: none;
    }

    .sidebar-netflix.mobile-pinned .nav-text {
        opacity: 1;
        max-width: 200px;
        transform: none;
    }

    .header-drawer-btn {
        margin-right: 12px;
        background: var(--netflix-dark-gray);
        color: var(--netflix-white);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 8px;
        width: 40px;
        height: 40px;
        display: none;
        align-items: center;
        justify-content: center;
    }
    .header-drawer-btn:active { transform: scale(0.98); }

    .notification-badge {
        background: var(--netflix-red);
        color: var(--netflix-white);
        font-size: 10px;
        font-weight: 600;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: auto;
        min-width: 18px;
        text-align: center;
        transition: all var(--transition-speed) ease;
    }

    .sidebar-footer {
        padding: 20px;
        border-top: 1px solid var(--netflix-dark-gray);
        margin-top: auto;
        position: sticky;
        bottom: 0;
        background: var(--netflix-black);
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .user-profile {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
        transition: all var(--transition-speed) ease;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        background: var(--netflix-red);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
        font-size: 16px;
        color: var(--netflix-white);
    }

    .user-info {
        flex: 1;
        transition: all var(--transition-speed) ease;
    }

    .user-name {
        font-size: 14px;
        font-weight: 600;
        color: var(--netflix-white);
        margin-bottom: 2px;
    }

    .user-email {
        font-size: 12px;
        color: var(--netflix-light-gray);
    }

    .logout-btn {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        color: var(--netflix-light-gray);
        text-decoration: none;
        border-radius: 6px;
        transition: all 0.2s ease;
        width: 100%;
    }

    .logout-btn:hover {
        color: var(--netflix-white);
        background: var(--netflix-dark-gray);
    }

    .logout-btn i {
        margin-right: 12px;
        font-size: 16px;
    }

.sidebar-netflix.collapsed .logout-btn i {
    margin-right: 0;
    font-size: 18px;
}

    .logout-text {
        font-size: 14px;
        font-weight: 500;
        transition: all var(--transition-speed) ease;
    }

    .sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
        opacity: 0;
        visibility: hidden;
        transition: all var(--transition-speed) ease;
    }

    .sidebar-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    /* Floating Drawer Button */
    .mobile-drawer-toggle {
        position: fixed;
        top: calc(var(--header-height, 0px) + 12px);
        left: 12px;
        width: 40px;
        height: 40px;
        display: none;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: var(--netflix-dark-gray);
        color: var(--netflix-white);
        border: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
        z-index: 2100;
        cursor: pointer;
    }

    .mobile-drawer-toggle:hover {
        background: #3a3a3a;
    }

    .mobile-drawer-toggle.hidden {
        display: none !important;
    }

    @media (max-width: 768px) {
        .mobile-drawer-toggle {
            display: none !important;
        }
    }

    /* Main Content Area */
    .main-content {
        margin-left: var(--sidebar-collapsed-width);
        min-height: 100vh;
        background: var(--netflix-black);
        transition: all var(--transition-speed) cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Keep content margin pinned regardless of expansion */
    .sidebar-netflix.collapsed+.main-content,
    .sidebar-netflix+.main-content {
        margin-left: var(--sidebar-collapsed-width);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .sidebar-netflix {
            transform: translateX(-100%);
            width: var(--sidebar-width);
        }

        .sidebar-netflix.mobile-open {
            transform: translateX(0);
        }

        .main-content {
            margin-left: 0;
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .sidebar-netflix:hover .nav-text,
        .nav-link:hover .nav-text {
            opacity: 0;
            max-width: 0;
        }

        .sidebar-netflix.mobile-open .nav-text,
        .sidebar-netflix.mobile-pinned .nav-text {
            opacity: 1;
            max-width: 200px;
        }

        .header-drawer-btn { display: inline-flex; }
    }

    /* Smooth Animations */
    .sidebar-netflix * {
        transition: all var(--transition-speed) cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Scrollbar Styling */
    .sidebar-netflix::-webkit-scrollbar {
        width: 4px;
    }

    .sidebar-netflix::-webkit-scrollbar-track {
        background: var(--netflix-black);
    }

    .sidebar-netflix::-webkit-scrollbar-thumb {
        background: var(--netflix-dark-gray);
        border-radius: 2px;
    }

    .sidebar-netflix::-webkit-scrollbar-thumb:hover {
        background: var(--netflix-gray);
    }

    /* Hover Effects */
    .nav-link::after {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 0;
        height: 0;
        background: var(--netflix-red);
        border-radius: 0 2px 2px 0;
        transition: all 0.3s ease;
    }

    .nav-link:hover::after {
        width: 4px;
        height: 20px;
    }

    /* Focus States */
    .nav-link:focus {
        outline: 2px solid var(--netflix-red);
        outline-offset: 2px;
    }

    /* Tooltip for collapsed state */
    .sidebar-netflix.collapsed .nav-link:hover::before {
        content: attr(title);
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        background: var(--netflix-dark-gray);
        color: var(--netflix-white);
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 12px;
        white-space: nowrap;
        z-index: 1001;
        margin-left: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }

    .sidebar-netflix.collapsed .nav-link:hover::after {
        content: '';
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        border: 5px solid transparent;
        border-right-color: var(--netflix-dark-gray);
        margin-left: 5px;
        z-index: 1001;
    }

    /* Loading Animation */
    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.7;
        }
    }

    .loading {
        animation: pulse 1.5s ease-in-out infinite;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainContent = document.querySelector('.main-content');
        const ownerId = <?php echo json_encode($user_id); ?>;
        const mobileDrawerToggle = document.getElementById('mobileDrawerToggle');

        let isCollapsed = false;
        const mobileBreakpoint = 768; // adjust as needed
        let isMobile = window.innerWidth <= mobileBreakpoint;
        let isPinnedMobile = ((localStorage.getItem('sidebarMobilePinned') !== null) ?
            localStorage.getItem('sidebarMobilePinned') === '1' :
            isMobile);
        let lastToggleClickTime = 0;
        let hoverBound = false;

        function applyPinnedState(pinned) {
            isPinnedMobile = !!pinned;
            try {
                localStorage.setItem('sidebarMobilePinned', isPinnedMobile ? '1' : '0');
            } catch (e) {}
            if (isMobile) {
                if (isPinnedMobile) {
                    sidebar.classList.add('mobile-open');
                    sidebar.classList.add('mobile-pinned');
                    sidebarOverlay.classList.remove('active');
                    if (mainContent) {
                        mainContent.classList.add('mobile-pinned');
                        mainContent.classList.remove('mobile-push');
                        if (sidebar.classList.contains('collapsed')) {
                            mainContent.classList.add('collapsed');
                        } else {
                            mainContent.classList.remove('collapsed');
                        }
                    }
                    document.body.classList.remove('mobile-sidebar-open');
                } else {
                    sidebar.classList.remove('mobile-pinned');
                    if (mainContent) {
                        mainContent.classList.remove('mobile-pinned');
                        mainContent.classList.remove('collapsed');
                    }
                }
            }
        }

        function updateDrawerAria() {
            if (!mobileDrawerToggle) return;
            const expanded = isPinnedMobile ? !sidebar.classList.contains('collapsed') : sidebar.classList.contains('mobile-open');
            mobileDrawerToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }

        function syncDrawerVisibility() {
            if (!mobileDrawerToggle) return;
            if (!isMobile) {
                mobileDrawerToggle.classList.add('hidden');
                return;
            }
            const hidePinnedExpanded = isPinnedMobile && !sidebar.classList.contains('collapsed');
            mobileDrawerToggle.classList.toggle('hidden', hidePinnedExpanded);
        }

        // Initialize pinned state on load
        applyPinnedState(isPinnedMobile);
        updateDrawerAria();
        syncDrawerVisibility();

        // Toggle sidebar
        function toggleSidebar() {
            if (isMobile) {
                sidebar.classList.toggle('mobile-open');
                sidebarOverlay.classList.toggle('active');
            } else {
                isCollapsed = !isCollapsed;
                sidebar.classList.toggle('collapsed');
            }
        }

        // Auto-collapse on desktop hover
        function handleMouseEnter() {
            if (!isMobile && isCollapsed) {
                sidebar.classList.remove('collapsed');
            }
        }

        function handleMouseLeave() {
            if (!isMobile && isCollapsed) {
                sidebar.classList.add('collapsed');
            }
        }

        // Event listeners
        function handleToggleClick(e) {
            const now = Date.now();
            if (now - lastToggleClickTime < 300) {
                e.preventDefault();
                applyPinnedState(!isPinnedMobile);
                lastToggleClickTime = 0;
                return;
            }
            lastToggleClickTime = now;

            if (isMobile) {
                if (isPinnedMobile) {
                    const collapsedNow = sidebar.classList.toggle('collapsed');
                    if (mainContent) {
                        if (collapsedNow) mainContent.classList.add('collapsed');
                        else mainContent.classList.remove('collapsed');
                    }
                } else {
                    const isOpen = sidebar.classList.contains('mobile-open');
                    if (isOpen) {
                        sidebar.classList.remove('mobile-open');
                        sidebarOverlay.classList.remove('active');
                        if (mainContent) mainContent.classList.remove('mobile-push');
                    } else {
                        sidebar.classList.add('mobile-open');
                        sidebarOverlay.classList.add('active');
                        if (mainContent) mainContent.classList.add('mobile-push');
                    }
                }
            } else {
                toggleSidebar();
            }
        }
        sidebarToggle.removeEventListener && sidebarToggle.removeEventListener('click', toggleSidebar);
        sidebarToggle.addEventListener('click', handleToggleClick);
        if (mobileDrawerToggle) {
            mobileDrawerToggle.addEventListener('click', function(e) {
                handleToggleClick(e);
                updateDrawerAria();
                syncDrawerVisibility();
            });
            mobileDrawerToggle.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleToggleClick(e);
                    updateDrawerAria();
                    syncDrawerVisibility();
                }
            });
        }
        const headerEl = document.querySelector('.main-content header');
        function ensureHeaderDrawerButton() {
            const existing = document.getElementById('headerDrawerBtn');
            if (isMobile) {
                if (!existing && headerEl) {
                    const btn = document.createElement('button');
                    btn.id = 'headerDrawerBtn';
                    btn.className = 'header-drawer-btn';
                    btn.innerHTML = '<i class="fas fa-bars"></i>';
                    headerEl.insertBefore(btn, headerEl.firstChild);
                    btn.addEventListener('click', function(e) {
                        handleToggleClick(e);
                        updateDrawerAria();
                        syncDrawerVisibility();
                    });
                }
            } else if (existing) {
                existing.remove();
            }
        }
        ensureHeaderDrawerButton();
        sidebarOverlay.addEventListener('click', function() {
            if (isMobile && !isPinnedMobile) {
                sidebar.classList.remove('mobile-open');
                sidebarOverlay.classList.remove('active');
                if (mainContent) mainContent.classList.remove('mobile-push');
            }
            updateDrawerAria();
            syncDrawerVisibility();
        });

        if (!isMobile && !hoverBound) {
            sidebar.addEventListener('mouseenter', handleMouseEnter);
            sidebar.addEventListener('mouseleave', handleMouseLeave);
            hoverBound = true;
        }

        // Handle window resize
        window.addEventListener('resize', () => {
            const wasMobile = isMobile;
            isMobile = window.innerWidth <= mobileBreakpoint;


            if (wasMobile !== isMobile) {
                if (isMobile) {
                    // Switching to mobile mode
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('collapsed');
                    sidebar.classList.add('mobile-open');
                    mainContent.classList.add('mobile-push');
                    // Unbind hover handlers in mobile
                    if (hoverBound) {
                        sidebar.removeEventListener('mouseenter', handleMouseEnter);
                        sidebar.removeEventListener('mouseleave', handleMouseLeave);
                        hoverBound = false;
                    }
                } else {
                    // Switching to desktop mode
                    sidebar.classList.remove('mobile-open');
                    mainContent.classList.remove('mobile-push');
                    sidebar.classList.add('collapsed');
                    isCollapsed = true;
                    // Bind hover handlers on desktop
                    if (!hoverBound) {
                        sidebar.addEventListener('mouseenter', handleMouseEnter);
                        sidebar.addEventListener('mouseleave', handleMouseLeave);
                        hoverBound = true;
                    }
                }

                updateDrawerAria();
                syncDrawerVisibility();
                ensureHeaderDrawerButton();
            } else {
                // No breakpoint change; still ensure the drawer visibility/aria is correct
                updateDrawerAria();
                syncDrawerVisibility();
                ensureHeaderDrawerButton();
            }
        });

        // Start collapsed on page load (desktop only)
        if (!isMobile) {
            sidebar.classList.add('collapsed');
            isCollapsed = true;
        }

        // Update notification badges
        function updateNotificationBadges() {
            // Fetch unread notifications count
            fetch(`get_unread_count.php?owner_id=${ownerId}`)
                .then(response => response.json())
                .then(data => {
                    const notificationBadge = document.getElementById('notificationBadge');
                    if (data.count > 0) {
                        notificationBadge.textContent = data.count;
                        notificationBadge.style.display = 'inline-block';
                    } else {
                        notificationBadge.style.display = 'none';
                    }
                })
                .catch(error => console.log('Error fetching notification count:', error));

            // Fetch unread messages count
            fetch(`get_unread_messages.php?owner_id=${ownerId}`)
                .then(response => response.json())
                .then(data => {
                    const messageBadge = document.getElementById('messageBadge');
                    if (data.count > 0) {
                        messageBadge.textContent = data.count;
                        messageBadge.style.display = 'inline-block';
                    } else {
                        messageBadge.style.display = 'none';
                    }
                })
                .catch(error => console.log('Error fetching message count:', error));
        }

        // Update badges on load and every 30 seconds
        updateNotificationBadges();
        setInterval(updateNotificationBadges, 30000);

        // Add smooth transitions to all elements
        const style = document.createElement('style');
        style.textContent = `
        * {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }
    `;
        document.head.appendChild(style);

        // Mobile-only styling for pinned and push modes including collapsed widths
        const mobileStyle = document.createElement('style');
        mobileStyle.textContent = `
        @media (max-width: 768px) {
          .main-content.mobile-push { transform: translateX(var(--sidebar-width)); }
          .main-content.mobile-push.collapsed { transform: translateX(var(--sidebar-collapsed-width)); }
          .main-content.mobile-pinned { margin-left: var(--sidebar-width); transform: none; }
          .main-content.mobile-pinned.collapsed { margin-left: var(--sidebar-collapsed-width); }

          .sidebar-netflix.mobile-pinned {
            transform: none !important;
            left: 0;
            z-index: 2000;
          }
          .sidebar-netflix.mobile-pinned.collapsed {
            width: var(--sidebar-collapsed-width);
            min-width: var(--sidebar-collapsed-width);
          }
        }
        `;
        document.head.appendChild(mobileStyle);

        // Keyboard: ESC closes push mode on mobile
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isMobile && !isPinnedMobile) {
                sidebar.classList.remove('mobile-open');
                sidebarOverlay.classList.remove('active');
                if (mainContent) mainContent.classList.remove('mobile-push');
                document.body.classList.remove('mobile-sidebar-open');
                updateDrawerAria();
                syncDrawerVisibility();
            }
        });

        // Touch gestures: enable swipe interactions both in push mode and when pinned
        let touchStartX = 0;
        let touchStartY = 0;
        let swipeStartedInSidebar = false;
        document.addEventListener('touchstart', function(e) {
            const t = e.touches[0];
            touchStartX = t.clientX;
            touchStartY = t.clientY;
            swipeStartedInSidebar = sidebar.contains(e.target);
        }, {
            passive: true
        });

        document.addEventListener('touchend', function(e) {
            if (!isMobile) return;
            const t = e.changedTouches[0];
            const dx = t.clientX - touchStartX;
            const dy = Math.abs(t.clientY - touchStartY);
            const threshold = 50;
            const atLeftEdge = touchStartX < 20;

            if (dy > 80) return; // Ignore vertical gestures

            if (isPinnedMobile) {
                const isCollapsed = sidebar.classList.contains('collapsed');
                if (isCollapsed && atLeftEdge && dx > threshold) {
                    // Expand pinned collapsed
                    sidebar.classList.remove('collapsed');
                    if (mainContent) mainContent.classList.remove('collapsed');
                } else if (!isCollapsed && swipeStartedInSidebar && dx < -threshold) {
                    // Collapse pinned expanded
                    sidebar.classList.add('collapsed');
                    if (mainContent) mainContent.classList.add('collapsed');
                }
                return;
            }

            const sidebarOpen = sidebar.classList.contains('mobile-open');
            if (!sidebarOpen && atLeftEdge && dx > threshold) {
                sidebar.classList.add('mobile-open');
                sidebarOverlay.classList.add('active');
                if (mainContent) mainContent.classList.add('mobile-push');
                document.body.classList.add('mobile-sidebar-open');
            } else if (sidebarOpen && swipeStartedInSidebar && dx < -threshold) {
                sidebar.classList.remove('mobile-open');
                sidebarOverlay.classList.remove('active');
                if (mainContent) mainContent.classList.remove('mobile-push');
                document.body.classList.remove('mobile-sidebar-open');
            }
        }, {
            passive: true
        });

        // Sync body scroll lock with overlay state
        const overlayObserver = new MutationObserver(() => {
            const active = sidebarOverlay.classList.contains('active');
            if (active) document.body.classList.add('mobile-sidebar-open');
            else document.body.classList.remove('mobile-sidebar-open');
            updateDrawerAria();
            syncDrawerVisibility();
        });
        overlayObserver.observe(sidebarOverlay, {
            attributes: true,
            attributeFilter: ['class']
        });

        // Keep sidebar visible when pinned even if classes change elsewhere
        const pinnedObserver = new MutationObserver(() => {
            if (isMobile && isPinnedMobile && !sidebar.classList.contains('mobile-open')) {
                sidebar.classList.add('mobile-open');
            }
            updateDrawerAria();
            syncDrawerVisibility();
        });
        pinnedObserver.observe(sidebar, {
            attributes: true,
            attributeFilter: ['class']
        });
    });
</script>