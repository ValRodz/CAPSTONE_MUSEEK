<?php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<aside class="sidebar">
   <div class="sidebar-logo">
    <h2>Admin Panel</h2>
    <span class="subtitle">Studio Manager</span>
</div>
    <nav class="sidebar-nav">
        <a href="../php/dashboard.php" class="<?= $currentPage == 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="../php/approvals.php" class="<?= $currentPage == 'approvals' ? 'active' : '' ?>">Approvals Queue</a>
        <a href="../php/documents.php" class="<?= $currentPage == 'documents' ? 'active' : '' ?>">Documents</a>
        <a href="../php/studios.php" class="<?= $currentPage == 'studios' ? 'active' : '' ?>">Studios Directory</a>
        <a href="../php/map.php" class="<?= $currentPage == 'map' ? 'active' : '' ?>">Map</a>
        <a href="../php/audit.php" class="<?= $currentPage == 'audit' ? 'active' : '' ?>">Audit Log</a>
        <a href="../php/reports.php" class="<?= $currentPage == 'reports' ? 'active' : '' ?>">Reports</a>
        <a href="../php/admin-users.php" class="<?= $currentPage == 'admin-users' ? 'active' : '' ?>">Admin Users</a>
    </nav>
</aside>
