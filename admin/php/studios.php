<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/models/Studio.php';

$studio = new Studio();

// === FILTERS ===
$filters = [
    'status' => $_GET['status'] ?? '',
    'approved' => $_GET['approved'] ?? '',
    'location' => $_GET['location'] ?? '',
    'owner' => $_GET['owner'] ?? ''
];

// === PAGINATION ===
$limit = (int)($_GET['limit'] ?? 10);
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// === GET DATA WITH PAGINATION ===
$studios = $studio->getAll($filters, $limit, $offset);

// === COUNT TOTAL FOR PAGINATION ===
$totalRows = $studio->getAllCount($filters);
$totalPages = max(1, ceil($totalRows / $limit));

$pageTitle = 'Studios Directory';
include __DIR__ . '/views/components/header.php';
?>



        <div class="page-header">
            <h1>Studios Directory</h1>
            <p>Browse and manage all studios</p>
        </div>

        <!-- FILTERS -->
        <div class="filters">
            <form method="GET" action="" style="display:flex;gap:16px;flex:1;flex-wrap:wrap;align-items:end;">
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Approved</label>
                    <select name="approved" class="filter-select" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="yes" <?= $filters['approved'] === 'yes' ? 'selected' : '' ?>>Approved</option>
                        <option value="no" <?= $filters['approved'] === 'no' ? 'selected' : '' ?>>Not Approved</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Location</label>
                    <input type="text" name="location" class="filter-input" placeholder="Filter by location" value="<?= htmlspecialchars($filters['location']) ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label">Owner</label>
                    <input type="text" name="owner" class="filter-input" placeholder="Name or email" value="<?= htmlspecialchars($filters['owner']) ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label">Rows</label>
                    <select name="limit" class="filter-select" onchange="this.form.submit()">
                        <option value="5" <?= $limit == 5 ? 'selected' : '' ?>>5</option>
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</</option>
                        <option value="15" <?= $limit == 15 ? 'selected' : '' ?>>15</option>
                    </select>
                </div>

                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="../php/studios.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>

        <!-- TABLE CARD -->
        <div class="card">
            <?php if (empty($studios)): ?>
                <div class="empty-state">
                    <h3>No studios found</h3>
                    <p>No studios match your filter criteria</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Studio</th>
                                <th>Owner</th>
                                <th>Status</th>
                                <th>Last Owner Login</th>
                                <th>Services</th>
                                <th>Instructors</th>
                                <th>Weekly Schedules</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studios as $s): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($s['StudioName']) ?></strong>
                                        <?php if ($s['Loc_Desc']): ?>
                                            <br><span class="text-muted text-sm"><?= htmlspecialchars($s['Loc_Desc']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($s['owner_name'] ?? 'N/A') ?><br>
                                        <span class="text-muted text-sm"><?= htmlspecialchars($s['owner_email'] ?? 'N/A') ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $isActive = $s['is_active'] ?? 0;
                                        $isApproved = $s['approved_by_admin'] ?? 0;
                                        
                                        if ($isApproved && $isActive) {
                                            $badge = '<span class="badge badge-success">Active</span>';
                                        } elseif ($isApproved && !$isActive) {
                                            $badge = '<span class="badge badge-warning">Approved (Inactive)</span>';
                                        } elseif (!$isApproved) {
                                            $badge = '<span class="badge badge-secondary">Not Approved</span>';
                                        }
                                        echo $badge;
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($s['owner_last_login']): ?>
                                            <?php
                                            $lastLogin = strtotime($s['owner_last_login']);
                                            $daysSince = floor((time() - $lastLogin) / 86400);
                                            $loginDate = date('M d, Y', $lastLogin);
                                            
                                            if ($daysSince > 90) {
                                                echo '<span class="badge badge-danger" title="' . $daysSince . ' days ago">⚠️ ' . $loginDate . '</span>';
                                            } elseif ($daysSince > 30) {
                                                echo '<span class="badge badge-warning" title="' . $daysSince . ' days ago">' . $loginDate . '</span>';
                                            } else {
                                                echo '<span class="text-muted text-sm">' . $loginDate . '</span>';
                                            }
                                            ?>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $s['services_count'] ?></td>
                                    <td><?= $s['instructors_count'] ?></td>
                                    <td><?= $s['weekly_schedules'] ?></td>
                                    <td>
                                        <a href="../php/studio-detail.php?id=<?= $s['StudioID'] ?>" class="btn btn-sm btn-primary">View</a>
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
                    $baseUrl = '../php/studios.php?' . $baseUrl;
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