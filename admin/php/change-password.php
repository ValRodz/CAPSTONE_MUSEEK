<?php
require_once __DIR__ . '/config/session.php';
requireLogin();
$pageTitle = 'Change Password';
include __DIR__ . '/views/components/header.php';

$error = $success = '';
if ($_POST) {
    $current = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($new !== $confirm) $error = 'New passwords do not match.';
    elseif (strlen($new) < 6) $error = 'Password must be at least 6 characters.';
    else {
        // Add real password change logic here
        $success = 'Password changed successfully!';
    }
}
?>

<div class="page-header">
    <h1>Change Password</h1>
</div>

<div class="card">
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current" class="form-input" required>
        </div>
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new" class="form-input" required>
        </div>
        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm" class="form-input" required>
        </div>
        <button type="submit" class="btn btn-primary">Update Password</button>
    </form>
</div>

<?php include __DIR__ . '/views/components/footer.php'; ?>