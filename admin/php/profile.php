<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

$pageTitle = 'My Profile';
include __DIR__ . '/views/components/header.php';

$adminName = $_SESSION['admin_name'] ?? 'Admin User';
$adminEmail = $_SESSION['admin_email'] ?? 'admin@example.com';
$adminRole = $_SESSION['admin_role'] ?? 'Administrator';
$joinedDate = $_SESSION['admin_created'] ?? '2024-01-01';

$activity = [
    ['action' => 'Approved Studio', 'target' => 'Fitness First', 'time' => '2 hours ago'],
    ['action' => 'Rejected Registration', 'target' => 'Yoga Haven', 'time' => '5 hours ago'],
    ['action' => 'Sent Document Link', 'target' => 'Dance Studio X', 'time' => '1 day ago'],
];
?>

<div class="page-header">
    <h1>My Profile</h1>
    <p>Manage your account and view recent activity</p>
</div>

<div class="card">
    <div class="flex gap-4 items-center mb-6">
        <div style="width:60px;height:60px;background:var(--primary);color:white;border-radius:50%;display:flex;align-items: items-center;justify-content:center;font-weight:600;font-size:1.5rem;">
            <?= strtoupper(substr($adminName, 0, 1)) ?>
        </div>
        <div>
            <h2 style="font-size:1.25rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($adminName) ?></h2>
            <p style="color:var(--text-muted);"><?= htmlspecialchars($adminEmail) ?></p>
            <div class="flex gap-2 mt-2">
                <span class="badge badge-success"><?= htmlspecialchars($adminRole) ?></span>
                <span style="font-size:0.875rem;color:var(--text-muted);">Joined <?= date('M Y', strtotime($joinedDate)) ?></span>
            </div>
        </div>
    </div>
    <div class="flex gap-3">
        <a href="../php/change-password.php" class="btn btn-primary">Change Password</a>
        <a href="../php/logout.php" class="btn btn-secondary">Sign Out</a>
    </div>
</div>

<div class="card">
    <div class="card-header flex-between">
        <h2>Recent Activity</h2>
        <div class="filters">
            <div class="filter-group">
                <input type="text" class="filter-input" placeholder="Search activity..." style="min-width:200px;">
            </div>
            <div class="filter-group">
                <select class="filter-select">
                    <option>All Actions</option>
                    <option>Approvals</option>
                    <option>Rejections</option>
                </select>
            </div>
        </div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Action</th><th>Target</th><th>Time</th></tr>
            </thead>
            <tbody>
                <?php foreach ($activity as $act): ?>
                <tr>
                    <td><?= htmlspecialchars($act['action']) ?></td>
                    <td><?= htmlspecialchars($act['target']) ?></td>
                    <td style="color:var(--text-muted);"><?= htmlspecialchars($act['time']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/views/components/footer.php'; ?>