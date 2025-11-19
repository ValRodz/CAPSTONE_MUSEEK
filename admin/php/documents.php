<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/models/Studio.php';
require_once __DIR__ . '/models/Registration.php';

// === FILTERS & PAGINATION ===
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$limit = (int)($_GET['limit'] ?? 10);  // Default 10
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// === BUILD MAIN QUERY ===
$query = "
    SELECT 
        sr.registration_id,
        sr.business_name as studio_name,
        sr.owner_name,
        sr.owner_email,
        sr.registration_status as reg_status,
        COUNT(d.document_id) as doc_count,
        MAX(d.uploaded_at) as last_upload
    FROM studio_registrations sr
    LEFT JOIN documents d ON sr.registration_id = d.registration_id
    WHERE 1=1
";

$params = [];

if ($status === 'awaiting') {
    $query .= " AND sr.registration_status = 'pending' AND d.document_id IS NULL";
} elseif ($status === 'uploaded') {
    $query .= " AND sr.registration_status = 'pending' AND d.document_id IS NOT NULL";
} elseif ($status === 'verified') {
    $query .= " AND sr.registration_status = 'approved'";
}

if (!empty($search)) {
    $query .= " AND (sr.business_name LIKE ? OR sr.owner_name LIKE ? OR sr.owner_email LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= "
    GROUP BY sr.registration_id
    ORDER BY 
        MAX(d.uploaded_at) IS NULL,
        MAX(d.uploaded_at) DESC,
        sr.registration_id DESC
    LIMIT ? OFFSET ?
";
$params[] = $limit;
$params[] = $offset;

// === EXECUTE MAIN QUERY ===
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare($query);
$stmt->execute($params);
$studios = $stmt->fetchAll();

// === COUNT TOTAL ROWS ===
$countQuery = "
    SELECT COUNT(*) FROM (
        SELECT sr.registration_id
        FROM studio_registrations sr
        LEFT JOIN documents d ON sr.registration_id = d.registration_id
        WHERE 1=1
";

$countParams = [];

if ($status === 'awaiting') {
    $countQuery .= " AND sr.registration_status = 'pending' AND d.document_id IS NULL";
} elseif ($status === 'uploaded') {
    $countQuery .= " AND sr.registration_status = 'pending' AND d.document_id IS NOT NULL";
} elseif ($status === 'verified') {
    $countQuery .= " AND sr.registration_status = 'approved'";
}

if (!empty($search)) {
    $countQuery .= " AND (sr.business_name LIKE ? OR sr.owner_name LIKE ? OR sr.owner_email LIKE ?)";
    $countParams[] = "%{$search}%";
    $countParams[] = "%{$search}%";
    $countParams[] = "%{$search}%";
}

$countQuery .= " GROUP BY sr.registration_id) AS total";

$countStmt = $db->prepare($countQuery);
$countStmt->execute($countParams);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

$pageTitle = 'Documents Verification';
include __DIR__ . '/views/components/header.php';
?>



        <div class="page-header">
            <div>
                <h1>Documents Verification</h1>
                <p>Review and verify uploaded documents from studio owners</p>
            </div>
        </div>

        <!-- FILTERS -->
        <div class="filters">
            <form method="GET" action="" style="display:flex;gap:16px;flex:1;flex-wrap:wrap;align-items:end;">
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Studios</option>
                        <option value="awaiting" <?= $status === 'awaiting' ? 'selected' : '' ?>>Awaiting Documents</option>
                        <option value="uploaded" <?= $status === 'uploaded' ? 'selected' : '' ?>>Documents Uploaded</option>
                        <option value="verified" <?= $status === 'verified' ? 'selected' : '' ?>>Verified</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Search</label>
                    <input type="text" name="search" class="filter-input" placeholder="Studio, owner..." value="<?= htmlspecialchars($search) ?>">
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
                    <a href="../admin/documents.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Studio Name</th>
                            <th>Owner Name</th>
                            <th>Documents</th>
                            <th>Last Upload</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($studios)): ?>
                            <tr><td colspan="6" class="text-center">No studios found</td></tr>
                        <?php else: foreach ($studios as $studio): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($studio['studio_name']) ?></strong></td>
                                <td><?= htmlspecialchars($studio['owner_name']) ?></td>
                                <td>
                                    <span class="badge <?= $studio['doc_count'] > 0 ? 'badge-success' : 'badge-secondary' ?>">
                                        <?= $studio['doc_count'] ?> file<?= $studio['doc_count'] != 1 ? 's' : '' ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $studio['last_upload']
                                        ? date('M d, Y H:i', strtotime($studio['last_upload']))
                                        : '<span class="text-muted">Never</span>' ?>
                                </td>
                                <td>
                                    <?php
                                    if ($studio['reg_status'] === 'approved') echo '<span class="badge badge-success">Verified</span>';
                                    elseif ($studio['reg_status'] === 'pending' && $studio['doc_count'] > 0) echo '<span class="badge badge-info">Review Needed</span>';
                                    elseif ($studio['reg_status'] === 'pending' && $studio['doc_count'] == 0) echo '<span class="badge badge-warning">Awaiting Upload</span>';
                                    else echo '<span class="badge badge-secondary">' . ucfirst($studio['reg_status']) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <a href="../php/approval-detail.php?id=<?= $studio['registration_id'] ?>" class="btn btn-sm btn-primary">View</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <?php if ($totalRows > 15): ?>
            <div class="pagination">
                <?php
                $prev = $page - 1;
                $next = $page + 1;
                $baseUrl = "?status=" . urlencode($status) . "&search=" . urlencode($search) . "&limit=$limit&page=";
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
        </div>
    </div>
</div>

<!-- SIDEBAR JS -->
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const hamburger = document.getElementById('hamburger');

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