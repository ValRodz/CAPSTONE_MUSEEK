<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/models/AuditLog.php';

$auditLog = new AuditLog();

// === FILTERS ===
$filters = [
    'entity_type' => $_GET['entity_type'] ?? '',
    'action' => $_GET['action'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'entity_id' => $_GET['entity_id'] ?? ''
];

// === PAGINATION ===
$limit = (int)($_GET['limit'] ?? 10);
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// === GET DATA WITH PAGINATION ===
$logs = $auditLog->getAll($filters, $limit, $offset);
$totalRows = $auditLog->getAllCount($filters);
$totalPages = max(1, ceil($totalRows / $limit));

$pageTitle = 'Audit Log';
include __DIR__ . '/views/components/header.php';
?>


        <div class="page-header">
            <h1>Audit Log</h1>
            <p>Track all administrative actions and system events</p>
        </div>

        <!-- FILTERS -->
        <div class="filters">
            <form method="GET" action="" style="display:flex;gap:16px;flex:1;flex-wrap:wrap;align-items:end;">
                <div class="filter-group">
                    <label class="filter-label">Entity Type</label>
                    <select name="entity_type" class="filter-select">
                        <option value="">All</option>
                        <option value="registration" <?= $filters['entity_type'] === 'registration' ? 'selected' : '' ?>>Registration</option>
                        <option value="studio" <?= $filters['entity_type'] === 'studio' ? 'selected' : '' ?>>Studio</option>
                        <option value="service" <?= $filters['entity_type'] === 'service' ? 'selected' : '' ?>>Service</option>
                        <option value="user" <?= $filters['entity_type'] === 'user' ? 'selected' : '' ?>>User</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Action</label>
                    <select name="action" class="filter-select">
                        <option value="">All</option>
                        <option value="APPROVED" <?= $filters['action'] === 'APPROVED' ? 'selected' : '' ?>>APPROVED</option>
                        <option value="REJECTED" <?= $filters['action'] === 'REJECTED' ? 'selected' : '' ?>>REJECTED</option>
                        <option value="UPDATED_STUDIO" <?= $filters['action'] === 'UPDATED_STUDIO' ? 'selected' : '' ?>>UPDATED_STUDIO</option>
                        <option value="SUSPENDED_STUDIO" <?= $filters['action'] === 'SUSPENDED_STUDIO' ? 'selected' : '' ?>>SUSPENDED_STUDIO</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Date From</label>
                    <input type="date" name="date_from" class="filter-input" value="<?= htmlspecialchars($filters['date_from']) ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label">Date To</label>
                    <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($filters['date_to']) ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label">Entity ID</label>
                    <input type="text" name="entity_id" class="filter-input" placeholder="Registration/Studio ID" value="<?= htmlspecialchars($filters['entity_id']) ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label">Rows</label>
                    <select name="limit" class="filter-select" onchange="this.form.submit()">
                        <option value="5" <?= $limit == 5 ? 'selected' : '' ?>>5</option>
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                        <option value="15" <?= $limit == 15 ? 'selected' : '' ?>>15</option>
                    </select>
                </div>

                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="../php/audit.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>

        <!-- TABLE CARD -->
        <div class="card">
            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <h3>No audit logs found</h3>
                    <p>No activities match your filter criteria</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="text-sm"><?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($log['admin_name'] ?? 'System') ?></strong><br>
                                        <span class="text-muted text-sm">#<?= $log['admin_id'] ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-warning"><?= htmlspecialchars($log['action']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($log['entity_type']): ?>
                                            <?= htmlspecialchars($log['entity_type']) ?> #<?= $log['entity_id'] ?>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-sm" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?= htmlspecialchars($log['description'] ?? 'N/A') ?>
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
                    $baseUrl = '../php/audit.php?' . $baseUrl;
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

                <div style="margin-top: 12px; padding: 12px; background: var(--light); border-radius: 6px; font-size: 13px; color: var(--text-muted);">
                    Showing <?= count($logs) ?> of <?= $totalRows ?> entries
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- SIDEBAR JS -->
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar.classList.contains('closed')) {
        sidebar.classList.remove('closed');
    } else {
        sidebar.classList.add('closed');
    }
}

// Hover open/close
let hoverTimeout;
document.getElementById('sidebar').addEventListener('mouseenter', () => {
    clearTimeout(hoverTimeout);
    document.getElementById('sidebar').classList.remove('closed');
});
document.getElementById('sidebar').addEventListener('mouseleave', () => {
    hoverTimeout = setTimeout(() => {
        document.getElementById('sidebar').classList.add('closed');
    }, 800);
});

// Open by default
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('sidebar').classList.remove('closed');
});
</script>

<?php include __DIR__ . '/views/components/footer.php'; ?>