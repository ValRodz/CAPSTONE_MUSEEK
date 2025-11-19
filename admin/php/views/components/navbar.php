<?php
$adminName = $_SESSION['admin_name'] ?? 'Admin';
// Get unread count from session (updates when notifications are marked as read)
$unreadCount = $_SESSION['unread_notifications'] ?? 0;
?>

<div class="admin-navbar">
    <!-- BRAND -->
    <div class="navbar-brand">
        <a href="../php/dashboard.php" class="navbar-logo">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
          
        </a>
    </div>

    <!-- RIGHT: NOTIFICATIONS + USER -->
    <div class="navbar-right">
        <!-- NOTIFICATIONS -->
        <div class="navbar-item">
            <button class="icon-btn" id="notifToggle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge badge-danger badge-sm" id="notifBadge"><?= $unreadCount ?></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-menu right" id="notifDropdown">
                <div class="dropdown-header">Notifications</div>
                <a href="#" class="dropdown-item">
                    <strong>New registration</strong><br>
                    <small class="text-muted">Studio X submitted</small>
                </a>
                <a href="#" class="dropdown-item">
                    <strong>Document uploaded</strong><br>
                    <small class="text-muted">Owner uploaded ID</small>
                </a>
                <div class="dropdown-footer">
                    <a href="../php/notifications.php">View all</a>
                </div>
            </div>
        </div>

        <!-- DARK MODE -->
        <div class="navbar-item">
            <button class="icon-btn" onclick="toggleDarkMode()" title="Toggle Dark Mode">
                <svg id="sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line>
                    <line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
                <svg id="moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
            </button>
        </div>

        <!-- USER DROPDOWN -->
        <div class="navbar-item">
            <div class="user-dropdown">
                <button class="user-toggle" id="userToggle">
                    <div class="user-avatar"><?= strtoupper(substr($adminName, 0, 1)) ?></div>
                    <span class="user-name"><?= htmlspecialchars($adminName) ?></span>
                    <svg class="dropdown-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
                <div class="dropdown-menu" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="user-avatar lg"><?= strtoupper(substr($adminName, 0, 1)) ?></div>
                        <div>
                            <div class="dropdown-title"><?= htmlspecialchars($adminName) ?></div>
                            <div class="dropdown-subtitle">Administrator</div>
                        </div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="../php/profile.php" class="dropdown-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        Profile
                    </a>
                    <a href="../php/change-password.php" class="dropdown-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"></path>
                        </svg>
                        Change Password
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../php/logout.php" class="dropdown-item text-danger">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                        Sign Out
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.admin-navbar { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; background: var(--card); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
.navbar-brand { display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--primary); text-decoration: none; }
.navbar-logo svg { color: var(--primary); }
.brand-text { font-size: 1.1rem; font-weight: 600; }
.navbar-right { display: flex; align-items: center; gap: 12px; }
.navbar-item { position: relative; }
.icon-btn { background: none; border: none; padding: 8px; border-radius: 8px; cursor: pointer; position: relative; }
.icon-btn:hover { background: var(--light); }
.badge-sm { position: absolute; top: 4px; right: 4px; font-size: 10px; padding: 2px 5px; min-width: 16px; height: 16px; }
.user-toggle { display: flex; align-items: center; gap: 8px; background: none; border: none; padding: 6px 10px; border-radius: 8px; cursor: pointer; font-size: 0.95rem; }
.user-toggle:hover { background: var(--light); }
.user-avatar { width: 32px; height: 32px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem; }
.user-name { max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dropdown-icon { transition: transform 0.2s; }
.user-dropdown.active .dropdown-icon { transform: rotate(180deg); }
.dropdown-menu { position: absolute; top: 100%; right: 0; min-width: 220px; background: var(--card); border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); opacity: 0; visibility: hidden; transform: translateY(-10px); transition: all 0.2s; margin-top: 8px; z-index: 1000; }
.user-dropdown.active .dropdown-menu, #notifToggle.active + #notifDropdown { opacity: 1; visibility: visible; transform: translateY(0); }
.dropdown-header { display: flex; align-items: center; gap: 12px; padding: 16px; border-bottom: 1px solid var(--border); }
.user-avatar.lg { width: 48px; height: 48px; font-size: 1.2rem; }
.dropdown-title { font-weight: 600; font-size: 0.95rem; }
.dropdown-subtitle { font-size: 0.8rem; color: var(--text-muted); }
.dropdown-item { display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text); text-decoration: none; font-size: 0.9rem; }
.dropdown-item:hover { background: var(--light); }
.dropdown-item svg { width: 18px; height: 18px; color: var(--text-muted); }
.dropdown-divider { height: 1px; background: var(--border); margin: 0; }
.dropdown-footer { padding: 8px 16px; text-align: center; }
.dropdown-footer a { color: var(--primary); font-size: 0.85rem; text-decoration: none; }
.text-danger { color: var(--danger) !important; }
@media (max-width: 768px) {
    .brand-text, .user-name { display: none; }
    .navbar-right { gap: 8px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // User dropdown
    const userToggle = document.getElementById('userToggle');
    const userDropdown = document.getElementById('userDropdown');
    userToggle.addEventListener('click', (e) => { e.stopPropagation(); document.querySelectorAll('.user-dropdown, .navbar-item').forEach(d => d.classList.remove('active')); document.querySelector('.user-dropdown').classList.toggle('active'); });

    // Notifications
    const notifToggle = document.getElementById('notifToggle');
    const notifDropdown = document.getElementById('notifDropdown');
    notifToggle.addEventListener('click', (e) => { e.stopPropagation(); document.querySelectorAll('.user-dropdown, .navbar-item').forEach(d => d.classList.remove('active')); notifToggle.classList.toggle('active'); });

    // Close all on click outside
    document.addEventListener('click', () => {
        document.querySelectorAll('.user-dropdown, .navbar-item').forEach(d => d.classList.remove('active'));
    });

    // Dark mode icon initialization
    const sun = document.getElementById('sun'), moon = document.getElementById('moon');
    const updateIcon = () => { 
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark'; 
        sun.style.display = isDark ? 'block' : 'none'; 
        moon.style.display = isDark ? 'none' : 'block'; 
    };
    updateIcon();
    
    // Sync theme across tabs
    window.addEventListener('storage', (e) => {
        if (e.key === 'theme') {
            document.documentElement.setAttribute('data-theme', e.newValue);
            updateIcon();
        }
    });
});

// Dark mode toggle function (globally accessible)
function toggleDarkMode() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    
    // Update icon immediately
    const sun = document.getElementById('sun');
    const moon = document.getElementById('moon');
    const isDark = newTheme === 'dark';
    sun.style.display = isDark ? 'block' : 'none';
    moon.style.display = isDark ? 'none' : 'block';
}
</script>
