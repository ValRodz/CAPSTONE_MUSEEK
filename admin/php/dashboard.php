<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Registration.php';
require_once __DIR__ . '/models/Studio.php';

$registration = new Registration();
$studioModel = new Studio();

// === STATS ===
$pendingCount = $registration->getPendingCount();
$db = Database::getInstance()->getConnection();

// Count registrations awaiting documents (pending with no documents uploaded)
$stmt = $db->query("
    SELECT COUNT(DISTINCT sr.registration_id) as count 
    FROM studio_registrations sr
    LEFT JOIN documents d ON sr.registration_id = d.registration_id
    WHERE sr.registration_status = 'pending' AND d.document_id IS NULL
");
$awaitingDocsCount = $stmt->fetch()['count'];

// Count approved/active studios
$stmt = $db->query("SELECT COUNT(*) as count FROM studios WHERE approved_by_admin = 1 AND is_active = 1");
$verifiedCount = $stmt->fetch()['count'];

// === RECENT DECISIONS + PAGINATION ===
$limit = 5;
$page = max(1, (int)($_GET['dec_page'] ?? 1));
$offset = ($page - 1) * $limit;
$recentDecisions = $registration->getRecentDecisions($limit, $offset);
$countStmt = $db->query("SELECT COUNT(*) FROM studio_registrations WHERE registration_status IN ('approved', 'rejected')");
$totalDecisions = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalDecisions / $limit));

// === MAP DATA ===
$stmt = $db->query("
    SELECT 
        StudioID as id, 
        StudioName as name, 
        Latitude as latitude, 
        Longitude as longitude, 
        Loc_Desc as location,
        is_active,
        approved_by_admin
    FROM studios 
    WHERE Latitude IS NOT NULL 
      AND Longitude IS NOT NULL 
      AND approved_by_admin = 1 
      AND is_active = 1
    LIMIT 20
");
$studiosWithCoords = $stmt->fetchAll();

$pageTitle = 'Dashboard';
include __DIR__ . '/views/components/header.php';
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <p>Quick overview of pending tasks and recent activity</p>
</div>

<!-- STATS GRID -->
<div class="stats-grid">
    <div class="stat-card">
        <h3>Pending Registrations</h3>
        <div class="stat-value"><?= $pendingCount ?></div>
        <a href="../php/approvals.php">Review now</a>
    </div>
    <div class="stat-card">
        <h3>Verified Studios</h3>
        <div class="stat-value"><?= $verifiedCount ?></div>
        <a href="../php/studios.php?status=approved">View all</a>
    </div>
    <div class="stat-card">
        <h3>Awaiting Documents</h3>
        <div class="stat-value"><?= $awaitingDocsCount ?></div>
        <a href="../php/documents.php?status=awaiting">View all</a>
    </div>
    <div class="stat-card">
        <h3>Recent Decisions</h3>
        <div class="stat-value"><?= $totalDecisions ?></div>
        <a href="../php/approvals.php">View all</a>
    </div>
</div>

<!-- ADDITIONAL SYSTEM STATS -->
<?php
// Total users count
$userStmt = $db->query("SELECT COUNT(*) FROM clients");
$totalUsers = $userStmt->fetchColumn();

// Total bookings count
$bookingStmt = $db->query("SELECT COUNT(*) FROM bookings");
$totalBookings = $bookingStmt->fetchColumn();

// Total earnings
try {
    $earningsStmt = $db->query("SELECT COALESCE(SUM(Amount), 0) as total FROM payment");
    $totalEarnings = $earningsStmt->fetch()['total'];
} catch (Exception $e) {
    $totalEarnings = 0;
}
?>

<div class="stats-grid">
    <div class="stat-card">
        <h3>Total Users</h3>
        <div class="stat-value"><?= number_format($totalUsers) ?></div>
        <a href="../php/users.php">View all</a>
    </div>
    <div class="stat-card">
        <h3>Total Bookings</h3>
        <div class="stat-value"><?= number_format($totalBookings) ?></div>
        <a href="../php/export-bookings.php">Export CSV</a>
    </div>
    <div class="stat-card">
        <h3>Total Earnings</h3>
        <div class="stat-value">₱<?= number_format($totalEarnings, 2) ?></div>
        <p style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">All-time platform revenue</p>
    </div>
    <div class="stat-card">
        <h3>System Health</h3>
        <div class="stat-value" style="color: var(--success);">✓</div>
        <a href="../admin/audit.php">View Logs</a>
    </div>
</div>

<!-- RECENT DECISIONS -->
<div class="card">
    <div class="card-header flex-between">
        <h2>Recent Decisions</h2>
        <a href="../php/approvals.php" class="btn btn-sm btn-primary">View All</a>
    </div>

    <?php if (empty($recentDecisions)): ?>
        <div class="empty-state">
            <h3>No recent decisions</h3>
            <p>Approved or rejected registrations will appear here</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>Studio</th><th>Owner</th><th>Status</th><th>Decided By</th><th>Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentDecisions as $decision): ?>
                    <tr>
                        <td><?= htmlspecialchars($decision['business_name']) ?></td>
                        <td><?= htmlspecialchars($decision['owner_name']) ?></td>
                        <td><span class="badge <?= $decision['registration_status'] === 'approved' ? 'badge-success' : 'badge-danger' ?>"><?= ucfirst($decision['registration_status']) ?></span></td>
                        <td><?= htmlspecialchars($decision['admin_name'] ?? 'System') ?></td>
                        <td class="text-muted text-sm"><?= date('M d, Y H:i', strtotime($decision['approved_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalDecisions > 5): ?>
        <div class="pagination">
            <?php $baseUrl = "?dec_page="; $prev = $page - 1; $next = $page + 1; ?>
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

<!-- MAP CARD -->
<div class="card">
    <div class="card-header flex-between">
        <h2>Approved Studios Map</h2>
        <a href="../php/map.php" class="btn btn-sm btn-primary">View Full Map</a>
    </div>
    <div id="dash-map" style="height:350px;border:1px solid #ddd;border-radius:8px;"></div>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
    /* Ensure the Leaflet popup "View" button text is visible and high-contrast */
    .leaflet-popup-content a.btn,
    .leaflet-popup-content a.btn-primary {
        color: #ffffff !important;
        background-color: #0d6efd !important;
        border: 1px solid #0d6efd !important;
        text-decoration: none;
        padding: 6px 10px;
        border-radius: 4px;
        display: inline-block;
        font-weight: 600;
        font-size: 13px;
        font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    }
    .leaflet-popup-content a.btn:hover,
    .leaflet-popup-content a.btn-primary:hover {
        background-color: #0b5ed7 !important;
        border-color: #0a58ca !important;
    }
    </style>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const studios = <?= json_encode($studiosWithCoords) ?>;
        const map = L.map('dash-map');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap'
        }).addTo(map);
        if (studios.length > 0) {
            const markers = studios.map(s => {
                const lat = parseFloat(s.latitude);
                const lng = parseFloat(s.longitude);
                const m = L.marker([lat, lng]);
                const status = (s.approved_by_admin ? (s.is_active ? 'Active' : 'Approved (Inactive)') : 'Not Approved');
                m.bindPopup(`<div><strong>${s.name}</strong><br/>${s.location ? `<span style=\"color:#666\">${s.location}</span><br/>` : ''}<small>${lat.toFixed(6)}, ${lng.toFixed(6)}</small><br/><a href=\"../admin/studio-detail.php?id=${s.id}\" class=\"btn btn-sm btn-primary\" style=\"margin-top:6px;display:inline-block;\">View</a></div>`);
                return m;
            });
            const group = L.featureGroup(markers).addTo(map);
            map.fitBounds(group.getBounds().pad(0.15));
        } else {
            map.setView([14.5995, 120.9842], 12);
        }
        setTimeout(() => map.invalidateSize(), 200);
    });
    </script>
</div>

<?php include __DIR__ . '/views/components/footer.php'; ?>