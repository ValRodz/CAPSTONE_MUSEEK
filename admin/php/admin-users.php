<?php
require_once __DIR__ . '/config/session.php';
requireLogin();
require_once __DIR__ . '/models/AdminUser.php';
require_once __DIR__ . '/models/AuditLog.php';

$adminModel = new AdminUser();
$auditLog = new AuditLog();
$currentAdminId = $_SESSION['admin_id'];

$success = '';
$error = '';

// Handle add/remove/toggle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrf_token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $name = trim($_POST['full_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'admin';
            
            if (empty($username) || empty($email) || empty($name) || empty($password)) {
                $error = 'All fields are required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email format';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters';
            } else {
                if ($adminModel->create($username, $email, $password, $name, $role)) {
                    $auditLog->log('Admin', $currentAdminId, 'ADMIN_CREATED', 'AdminUser', 0, "Created new admin: $email");
                    $success = 'Admin user added successfully';
                } else {
                    $error = 'Failed to add admin (email may already exist)';
                }
            }
        } elseif ($action === 'toggle_status') {
            $adminId = (int)($_POST['admin_id'] ?? 0);
            $currentStatus = (int)($_POST['current_status'] ?? 0);
            $newStatus = $currentStatus ? 0 : 1;
            
            if ($adminId == $currentAdminId) {
                $error = 'You cannot deactivate your own account';
            } elseif ($adminModel->updateStatus($adminId, $newStatus)) {
                $statusText = $newStatus ? 'activated' : 'deactivated';
                $auditLog->log('Admin', $currentAdminId, 'ADMIN_STATUS_CHANGED', 'AdminUser', $adminId, "Admin {$statusText}");
                $success = "Admin user {$statusText} successfully";
            } else {
                $error = 'Failed to update admin status';
            }
        }
    }
}

$admins = $adminModel->getAll();

$pageTitle = 'Admin Users';
include __DIR__ . '/views/components/header.php';
?>

<div class="page-header">
    <h1>Admin User Management</h1>
    <p>Manage admin accounts and permissions</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h2>Admin Users (<?= count($admins) ?>)</h2>
        <button onclick="openAddModal()" class="btn btn-primary">+ Add Admin</button>
    </div>
</div>

<div class="card">
    <?php if (empty($admins)): ?>
        <div class="empty-state">
            <h3>No admin users</h3>
            <p>Add your first admin user to get started</p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Last Login</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $admin): ?>
                    <tr>
                        <td><?= htmlspecialchars($admin['username']) ?></td>
                        <td><?= htmlspecialchars($admin['full_name']) ?></td>
                        <td><?= htmlspecialchars($admin['email']) ?></td>
                        <td><span class="badge badge-info"><?= ucfirst($admin['role']) ?></span></td>
                        <td class="text-sm">
                            <?= $admin['last_login'] ? date('M d, Y H:i', strtotime($admin['last_login'])) : 'Never' ?>
                        </td>
                        <td>
                            <span class="badge <?= $admin['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                                <?= $admin['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($admin['admin_id'] != $currentAdminId): ?>
                                <form method="POST" style="display: inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="admin_id" value="<?= $admin['admin_id'] ?>">
                                    <input type="hidden" name="current_status" value="<?= $admin['is_active'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $admin['is_active'] ? 'btn-warning' : 'btn-success' ?>" 
                                            onclick="return confirm('<?= $admin['is_active'] ? 'Deactivate' : 'Activate' ?> this admin?')">
                                        <?= $admin['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted text-sm">Current User</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <a href="../admin/dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<!-- Add Admin Modal -->
<div id="addModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: var(--card); padding: 24px; border-radius: 8px; max-width: 500px; width: 90%;">
        <h2>Add New Admin User</h2>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            
            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" class="form-input" placeholder="adminuser" required>
            </div>
            
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" class="form-input" placeholder="John Doe" required>
            </div>
            
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" class="form-input" placeholder="admin@example.com" required>
            </div>
            
            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" class="form-input" placeholder="Minimum 6 characters" required minlength="6">
            </div>
            
            <div class="form-group">
                <label>Role</label>
                <select name="role" class="form-select">
                    <option value="admin">Admin</option>
                    <option value="super_admin">Super Admin</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 8px; margin-top: 16px;">
                <button type="submit" class="btn btn-primary">Add Admin</button>
                <button type="button" onclick="closeAddModal()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addModal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddModal();
    }
});
</script>

<?php include __DIR__ . '/views/components/footer.php'; ?>
