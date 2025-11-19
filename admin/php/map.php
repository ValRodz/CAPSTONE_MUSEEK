<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/models/Studio.php';

$studioModel = new Studio();

// === GET ALL STUDIOS (NO FILTERS, NO PAGINATION) ===
$studios = $studioModel->getAll([]); // Empty filters = all studios

// === SEPARATE STUDIOS ===
$studiosWithCoords = array_filter($studios, fn($s) => isset($s['Latitude']) && isset($s['Longitude']) && $s['Latitude'] && $s['Longitude']);
$studiosWithoutCoords = array_filter($studios, fn($s) => !isset($s['Latitude']) || !isset($s['Longitude']) || !$s['Latitude'] || !$s['Longitude']);

// Prepare data for map rendering
$mapStudios = array_values(array_map(function($s) {
    return [
        'id' => $s['StudioID'],
        'name' => $s['StudioName'],
        'lat' => (float)$s['Latitude'],
        'lng' => (float)$s['Longitude'],
        'loc' => $s['Loc_Desc'] ?? '',
        'is_active' => (int)($s['is_active'] ?? 0),
        'is_approved' => (int)($s['approved_by_admin'] ?? 0),
    ];
}, $studiosWithCoords));

$pageTitle = 'Map Management';
include __DIR__ . '/views/components/header.php';
?>



        <div class="page-header">
            <h1>Map Management</h1>
            <p>View and manage studio locations on the map</p>
        </div>

        <!-- Map popup button visibility fix -->
        <style>
        /* Ensure the View button inside Leaflet popups is high-contrast and readable */
        .leaflet-popup-content a.btn,
        .leaflet-popup-content a.btn-primary {
            color: #fff !important;
            background-color: #0d6efd !important; /* fallback primary */
            border: 1px solid #0d6efd !important;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 4px;
            display: inline-block;
            font-weight: 600;
        }
        .leaflet-popup-content a.btn:hover,
        .leaflet-popup-content a.btn-primary:hover {
            background-color: #0b5ed7 !important;
            border-color: #0a58ca !important;
        }
        </style>

        <!-- MAP CARD -->
        <div class="card">
            <div class="card-header flex-between">
                <h2>Map View</h2>
                <span class="text-muted">
                    <?= count($studiosWithCoords) ?> studio<?= count($studiosWithCoords) != 1 ? 's' : '' ?> with coordinates
                </span>
            </div>

            <div id="map" style="height: 500px; border: 1px solid #ddd; border-radius: 8px;"></div>
        </div>

        <!-- Leaflet CSS/JS -->
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const studios = <?= json_encode($mapStudios) ?>;

            const defaultCenter = [14.5995, 120.9842]; // Manila
            const map = L.map('map');

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(map);

            if (studios.length > 0) {
                const markers = studios.map(s => {
                    const m = L.marker([s.lat, s.lng]);
                    const status = s.is_approved ? (s.is_active ? 'Active' : 'Approved (Inactive)') : 'Not Approved';
                    const popup = `
                        <div>
                            <strong>${s.name}</strong><br/>
                            ${s.loc ? `<span style="color:#666">${s.loc}</span><br/>` : ''}
                            <small>${s.lat.toFixed(6)}, ${s.lng.toFixed(6)}</small><br/>
                            <span class="badge ${s.is_approved ? (s.is_active ? 'badge-success' : 'badge-warning') : 'badge-secondary'}" style="margin-top:4px; display:inline-block;">${status}</span><br/>
                            <a href="../php/studio-detail.php?id=${s.id}" class="btn btn-sm btn-primary" style="margin-top:6px; display:inline-block; color:#fff; background-color:#0d6efd; border:1px solid #0d6efd; text-decoration:none; padding:6px 10px; border-radius:4px;">View</a>
                        </div>`;
                    m.bindPopup(popup);
                    return m;
                });
                const group = L.featureGroup(markers).addTo(map);
                map.fitBounds(group.getBounds().pad(0.15));
            } else {
                map.setView(defaultCenter, 12);
            }

            setTimeout(() => map.invalidateSize(), 200);
        });
        </script>

        <!-- MISSING COORDINATES TABLE -->
        <?php if (!empty($studiosWithoutCoords)): ?>
        <div class="card">
            <div class="card-header">
                <h2>Studios Missing Coordinates (<?= count($studiosWithoutCoords) ?>)</h2>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Studio Name</th>
                            <th>Address</th>
                            <th>City</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studiosWithoutCoords as $studio): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($studio['StudioName']) ?></strong></td>
                                <td><?= htmlspecialchars($studio['Loc_Desc'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($studio['Loc_Desc'] ?? 'N/A') ?></td>
                                <td><span class="badge badge-danger">Missing Coordinates</span></td>
                                <td>
                                    <a href="../php/studio-detail.php?id=<?= $studio['StudioID'] ?>" class="btn btn-sm btn-primary">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- STUDIOS WITH COORDINATES TABLE -->
        <div class="card">
            <div class="card-header">
                <h2>All Studios with Coordinates (<?= count($studiosWithCoords) ?>)</h2>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Studio Name</th>
                            <th>Address</th>
                            <th>Coordinates</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($studiosWithCoords)): ?>
                            <tr>
                                <td colspan="5" class="text-center">No studios with coordinates found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($studiosWithCoords as $studio): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($studio['StudioName']) ?></strong></td>
                                    <td><?= htmlspecialchars($studio['Loc_Desc'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?= number_format($studio['Latitude'], 6) ?>, <?= number_format($studio['Longitude'], 6) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $isActive = $studio['is_active'] ?? 0;
                                        $isApproved = $studio['approved_by_admin'] ?? 0;
                                        
                                        if ($isApproved && $isActive) {
                                            $statusClass = 'success';
                                            $statusText = 'Active';
                                        } elseif ($isApproved && !$isActive) {
                                            $statusClass = 'warning';
                                            $statusText = 'Approved (Inactive)';
                                        } else {
                                            $statusClass = 'secondary';
                                            $statusText = 'Not Approved';
                                        }
                                        ?>
                                        <span class="badge badge-<?= $statusClass ?>">
                                            <?= $statusText ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="../php/studio-detail.php?id=<?= $studio['StudioID'] ?>" class="btn btn-sm btn-primary">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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