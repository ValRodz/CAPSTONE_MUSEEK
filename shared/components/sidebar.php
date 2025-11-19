<?php
// Get owner ID (in a real app, this would come from a session)
$ownerId = 3; // Mike Tambasen's ID

// Count unread notifications
$unreadCount = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE OwnerID = ? AND IsRead = 0");
$unreadCount->execute([$ownerId]);
$unreadCount = $unreadCount->fetch(PDO::FETCH_ASSOC)['count'];

// Get owner data
$owner = $pdo->prepare("
    SELECT Name, Email 
    FROM studio_owners 
    WHERE OwnerID = ?
");
$owner->execute([$ownerId]);
$owner = $owner->fetch(PDO::FETCH_ASSOC);

// Helper function to get customer initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Determine current page
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!-- Sidebar -->
<aside class="sidebar bg-[#161616] border-r border-[#222222]" id="sidebar">
    <div class="flex items-center justify-between p-4 border-b border-[#222222]">
        <div class="flex items-center gap-2">
            <i class="fas fa-music text-red-600"></i>
            <span class="font-semibold text-lg">MuSeek</span>
        </div>
        <button id="closeSidebar" class="text-gray-400 hover:text-white">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <nav class="p-2">
        <ul class="space-y-1">
            <li>
                <a href="dashboard.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'dashboard.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="bookings.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'bookings.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="far fa-calendar-alt"></i>
                    <span>Bookings</span>
                </a>
            </li>
            <li>
                <a href="schedule.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'schedule.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="far fa-clock"></i>
                    <span>Schedule</span>
                </a>
            </li>
            <li>
                <a href="payments.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'payments.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="fas fa-dollar-sign"></i>
                    <span>Payments</span>
                </a>
            </li>
            <li>
                <a href="instructors.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'instructors.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="fas fa-user-friends"></i>
                    <span>Instructors</span>
                </a>
            </li>
            <li>
                <a href="feedback.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'feedback.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="far fa-comment-alt"></i>
                    <span>Feedback</span>
                </a>
            </li>
            <li>
                <a href="notifications.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'notifications.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                    <?php if ($unreadCount > 0): ?>
                        <span class="ml-auto bg-red-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="push-notifications.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'push-notifications.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="fas fa-broadcast-tower"></i>
                    <span>Push Notifications</span>
                </a>
            </li>
            <li>
                <a href="reports.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'reports.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="far fa-file-alt"></i>
                    <span>Reports</span>
                </a>
            </li>
            <li>
                <a href="settings.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'settings.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </li>
            <li>
                <a href="studio-management.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'studio-management.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                    <i class="fas fa-music"></i>
                    <span>Studio Management</span>
                </a>
            </li>
            <li>
                <a href="studio-management.php" class="flex items-center gap-2 px-3 py-2 rounded-md <?php echo $currentPage === 'studio-management.php' ? 'bg-red-600' : 'hover:bg-[#222222]'; ?> text-white">
                <i class="fas fa-door"></i>
                <span>Log Out</span>
            </li>
        </ul>
    </nav>
    
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-[#222222]">
        <div class="flex items-center gap-3">
            <div class="avatar">
                <?php echo getInitials($owner['Name']); ?>
            </div>
            <div class="flex flex-col text-xs text-gray-400">
                <span class="font-semibold text-white text-[13px]"><?php echo htmlspecialchars($owner['Name']); ?></span>
                <span><?php echo htmlspecialchars($owner['Email']); ?></span>
            </div>
            <button class="ml-auto text-gray-400 hover:text-white">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    </div>
</aside>