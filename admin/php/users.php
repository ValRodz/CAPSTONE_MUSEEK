<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/AuditLog.php';

$db = Database::getInstance()->getConnection();
$auditLog = new AuditLog();

$success = '';
$error = '';

// Handle ban/unban actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrf_token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $user_type = $_POST['user_type'] ?? '';
        $user_id = $_POST['user_id'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        $adminId = $_SESSION['admin_id'];
        
        if ($action === 'ban' && !empty($reason)) {
            if ($user_type === 'owner') {
                $stmt = $db->prepare("UPDATE studio_owners SET status = 'banned', banned_by = ?, banned_at = NOW(), ban_reason = ? WHERE OwnerID = ?");
                if ($stmt->execute([$adminId, $reason, $user_id])) {
                    $auditLog->log('Admin', $adminId, 'BANNED_USER', 'StudioOwner', $user_id, "Banned studio owner. Reason: $reason");
                    $success = 'User banned successfully!';
                } else {
                    $error = 'Failed to ban user.';
                }
            } elseif ($user_type === 'client') {
                $stmt = $db->prepare("UPDATE clients SET status = 'banned', banned_by = ?, banned_at = NOW(), ban_reason = ? WHERE ClientID = ?");
                if ($stmt->execute([$adminId, $reason, $user_id])) {
                    $auditLog->log('Admin', $adminId, 'BANNED_USER', 'Client', $user_id, "Banned client. Reason: $reason");
                    $success = 'User banned successfully!';
                } else {
                    $error = 'Failed to ban user.';
                }
            }
        } elseif ($action === 'unban') {
            if ($user_type === 'owner') {
                $stmt = $db->prepare("UPDATE studio_owners SET status = 'active', banned_by = NULL, banned_at = NULL, ban_reason = NULL WHERE OwnerID = ?");
                if ($stmt->execute([$user_id])) {
                    $auditLog->log('Admin', $adminId, 'UNBANNED_USER', 'StudioOwner', $user_id, "Unbanned studio owner");
                    $success = 'User unbanned successfully!';
                } else {
                    $error = 'Failed to unban user.';
                }
            } elseif ($user_type === 'client') {
                $stmt = $db->prepare("UPDATE clients SET status = 'active', banned_by = NULL, banned_at = NULL, ban_reason = NULL WHERE ClientID = ?");
                if ($stmt->execute([$user_id])) {
                    $auditLog->log('Admin', $adminId, 'UNBANNED_USER', 'Client', $user_id, "Unbanned client");
                    $success = 'User unbanned successfully!';
                } else {
                    $error = 'Failed to unban user.';
                }
            }
        } elseif ($action === 'ban' && empty($reason)) {
            $error = 'Ban reason is required.';
        }
    }
}

// Filters
$filters = [
    'user_type' => $_GET['user_type'] ?? 'all',
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Pagination
$limit = (int)($_GET['limit'] ?? 10);
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Build query
$sql = "";
$countSql = "";
$params = [];

if ($filters['user_type'] === 'owner' || $filters['user_type'] === 'all') {
    $sql = "SELECT 
        OwnerID as id,
        'owner' as user_type,
        Name as name,
        Email as email,
        Phone as phone,
        status,
        banned_at,
        banned_by,
        ban_reason,
        created_at
    FROM studio_owners 
    WHERE 1=1";
    
    $countSql = "SELECT COUNT(*) FROM studio_owners WHERE 1=1";
    
    if (!empty($filters['status'])) {
        $sql .= " AND status = ?";
        $countSql .= " AND status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['search'])) {
        $sql .= " AND (Name LIKE ? OR Email LIKE ?)";
        $countSql .= " AND (Name LIKE ? OR Email LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
}

if ($filters['user_type'] === 'client') {
    $sql = "SELECT 
        ClientID as id,
        'client' as user_type,
        Name as name,
        Email as email,
        Phone as phone,
        status,
        banned_at,
        banned_by,
        ban_reason,
        created_at
    FROM clients 
    WHERE 1=1";
    
    $countSql = "SELECT COUNT(*) FROM clients WHERE 1=1";
    
    if (!empty($filters['status'])) {
        $sql .= " AND status = ?";
        $countSql .= " AND status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['search'])) {
        $sql .= " AND (Name LIKE ? OR Email LIKE ?)";
        $countSql .= " AND (Name LIKE ? OR Email LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
}

if ($filters['user_type'] === 'all') {
    // Combine both owners and clients
    $sql2 = " UNION ALL SELECT 
        ClientID as id,
        'client' as user_type,
        Name as name,
        Email as email,
        Phone as phone,
        status,
        banned_at,
        banned_by,
        ban_reason,
        created_at
    FROM clients 
    WHERE 1=1";
    
    if (!empty($filters['status'])) {
        $sql2 .= " AND status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['search'])) {
        $sql2 .= " AND (Name LIKE ? OR Email LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
    
    $sql .= $sql2;
}

$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total
$countParams = array_slice($params, 0, -2); // Remove limit and offset
$stmtCount = $db->prepare($countSql);
$stmtCount->execute($countParams);
$totalRows = $stmtCount->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

$pageTitle = 'User Management';
include __DIR__ . '/views/components/header.php';
?>

<div class="page-header">
    <h1>User Management</h1>
    <p>Manage studio owners and clients - ban/unban users</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<!-- FILTERS -->
<div class="filters">
    <form method="GET" style="display:flex;gap:16px;flex:1;flex-wrap:wrap;align-items:end;">
        <div class="filter-group">
            <label class="filter-label">User Type</label>
            <select name="user_type" class="filter-select" onchange="this.form.submit()">
                <option value="all" <?= $filters['user_type'] === 'all' ? 'selected' : '' ?>>All Users</option>
                <option value="owner" <?= $filters['user_type'] === 'owner' ? 'selected' : '' ?>>Studio Owners</option>
                <option value="client" <?= $filters['user_type'] === 'client' ? 'selected' : '' ?>>Clients</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label class="filter-label">Status</label>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="banned" <?= $filters['status'] === 'banned' ? 'selected' : '' ?>>Banned</option>
                <option value="suspended" <?= $filters['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label class="filter-label">Search</label>
            <input type="text" name="search" class="filter-input" placeholder="Name or email..." value="<?= htmlspecialchars($filters['search']) ?>">
        </div>
        
        <div class="filter-group">
            <label class="filter-label">Rows</label>
            <select name="limit" class="filter-select" onchange="this.form.submit()">
                <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
            </select>
        </div>
        
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="../admin/users.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>
</div>

<!-- USERS TABLE -->
<div class="card">
    <?php if (empty($users)): ?>
        <div class="empty-state">
            <h3>No users found</h3>
            <p>No users match your filter criteria</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>User Type</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <span class="badge <?= $user['user_type'] === 'owner' ? 'badge-info' : 'badge-secondary' ?>">
                                <?= ucfirst($user['user_type']) ?>
                            </span>
                        </td>
                        <td><strong><?= htmlspecialchars($user['name']) ?></strong></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars($user['phone']) ?></td>
                        <td>
                            <?php
                            $statusBadges = ['active'=>'badge-success','banned'=>'badge-danger','suspended'=>'badge-warning'];
                            echo '<span class="badge ' . ($statusBadges[$user['status']] ?? 'badge-secondary') . '">' . ucfirst($user['status']) . '</span>';
                            ?>
                        </td>
                        <td class="text-sm"><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                        <td>
                            <?php if ($user['status'] === 'active'): ?>
                                <button onclick="showBanModal(<?= $user['id'] ?>, '<?= $user['user_type'] ?>', '<?= htmlspecialchars($user['name']) ?>')" class="btn btn-sm btn-danger">Ban</button>
                            <?php elseif ($user['status'] === 'banned'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Unban this user?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="unban">
                                    <input type="hidden" name="user_type" value="<?= $user['user_type'] ?>">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Unban</button>
                                </form>
                                <button onclick="showBanDetails(<?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['ban_reason'] ?? '')) ?>', '<?= $user['banned_at'] ? date('M d, Y H:i', strtotime($user['banned_at'])) : '' ?>')" class="btn btn-sm btn-secondary">Details</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if ($totalRows > $limit): ?>
        <div class="pagination">
            <?php
            $prev = $page - 1;
            $next = $page + 1;
            $baseUrl = http_build_query(array_merge($_GET, ['page' => ''])) . '&page=';
            $baseUrl = preg_replace('/&page=\d+/', '', $baseUrl);
            $baseUrl = '../admin/users.php?' . $baseUrl;
            ?>
            <a href="<?= $baseUrl . max(1, $prev) ?>" class="<?= $page == 1 ? 'disabled' : '' ?>">Previous</a>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 1 && $i <= $page + 1)): ?>
                    <a href="<?= $baseUrl . $i ?>" class="<?= $i == $page ? 'current' : '' ?>"><?= $i ?></a>
                <?php elseif ($i == $page - 2 || $i == $page + 2): ?>
                    <span>...</span>
                <?php endif; ?>
            <?php endfor; ?>
            <a href="<?= $baseUrl . min($totalPages, $next) ?>" class="<?= $page == $totalPages ? 'disabled' : '' ?>">Next</a>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- BAN MODAL -->
<div id="banModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:30px;border-radius:8px;max-width:500px;width:90%;">
        <h2 style="margin-top:0;">Ban User</h2>
        <p id="banUserName" style="color:#666;"></p>
        
        <form method="POST" onsubmit="return validateBanForm();">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ban">
            <input type="hidden" name="user_type" id="banUserType">
            <input type="hidden" name="user_id" id="banUserId">
            
            <div class="form-group">
                <label class="form-label">Reason for Ban *</label>
                <textarea name="reason" id="banReason" class="form-textarea" rows="4" placeholder="Enter reason for banning this user..." required></textarea>
            </div>
            
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" onclick="closeBanModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-danger">Ban User</button>
            </div>
        </form>
    </div>
</div>

<!-- BAN DETAILS MODAL -->
<div id="banDetailsModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:30px;border-radius:8px;max-width:500px;width:90%;">
        <h2 style="margin-top:0;">Ban Details</h2>
        <div style="margin:20px 0;">
            <p style="margin:10px 0;"><strong>Banned At:</strong> <span id="detailsBannedAt"></span></p>
            <p style="margin:10px 0;"><strong>Reason:</strong></p>
            <div style="background:#f5f5f5;padding:15px;border-radius:4px;"><span id="detailsBanReason"></span></div>
        </div>
        <div style="text-align:right;margin-top:20px;">
            <button onclick="closeBanDetailsModal()" class="btn btn-primary">Close</button>
        </div>
    </div>
</div>

<script>
function showBanModal(userId, userType, userName) {
    document.getElementById('banUserId').value = userId;
    document.getElementById('banUserType').value = userType;
    document.getElementById('banUserName').textContent = 'You are about to ban: ' + userName;
    document.getElementById('banReason').value = '';
    document.getElementById('banModal').style.display = 'block';
}

function closeBanModal() {
    document.getElementById('banModal').style.display = 'none';
}

function validateBanForm() {
    const reason = document.getElementById('banReason').value.trim();
    if (reason === '') {
        alert('Please provide a reason for banning this user.');
        return false;
    }
    return confirm('Are you sure you want to ban this user?');
}

function showBanDetails(userId, reason, bannedAt) {
    document.getElementById('detailsBanReason').textContent = reason || 'No reason provided';
    document.getElementById('detailsBannedAt').textContent = bannedAt || 'Unknown';
    document.getElementById('banDetailsModal').style.display = 'block';
}

function closeBanDetailsModal() {
    document.getElementById('banDetailsModal').style.display = 'none';
}

// Close modals on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeBanModal();
        closeBanDetailsModal();
    }
});
</script>

<?php include __DIR__ . '/views/components/footer.php'; ?>
