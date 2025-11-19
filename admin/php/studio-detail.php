<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/models/Studio.php';

$studioId = $_GET['id'] ?? 0;
$studioModel = new Studio();

$studio = $studioModel->getById($studioId);

if (!$studio) {
    header('Location: ../php/studios.php');
    exit;
}

$services = $studioModel->getServices($studioId);
$instructors = $studioModel->getInstructors($studioId);
$schedules = $studioModel->getSchedules($studioId, 10);
$bookings = $studioModel->getBookings($studioId, 20);
$documents = $studioModel->getDocuments($studioId);
$earnings = $studioModel->getStudioEarnings($studioId);
$availableServices = $studioModel->getAvailableServices();

$pageTitle = 'Studio Detail';
?>
<?php include __DIR__ . '/views/components/header.php'; ?>

<div class="flex-between mb-4">
    <div class="page-header" style="margin-bottom: 0;">
        <h1><?= htmlspecialchars($studio['StudioName']) ?></h1>
        <p>Owner: <?= htmlspecialchars($studio['owner_name'] ?? 'N/A') ?> (<?= htmlspecialchars($studio['owner_email'] ?? 'N/A') ?>)</p>
    </div>
    <div style="display: flex; gap: 8px; align-items: center;">
        <?php
        $isActive = $studio['is_active'] ?? 0;
        $isApproved = $studio['approved_by_admin'] ?? 0;

        if ($isApproved && $isActive) {
            $badgeClass = 'badge-success';
            $statusText = 'Active';
        } elseif ($isApproved && !$isActive) {
            $badgeClass = 'badge-warning';
            $statusText = 'Approved (Inactive)';
        } else {
            $badgeClass = 'badge-secondary';
            $statusText = 'Not Approved';
        }
        ?>
        <span class="badge <?= $badgeClass ?>"><?= $statusText ?></span>
        <?php if ($studio['is_featured'] ?? 0): ?>
            <span class="badge badge-info" style="margin-left: 8px;">⭐ Featured</span>
        <?php endif; ?>
        <button onclick="toggleStatus()" class="btn btn-sm <?= $isActive ? 'btn-secondary' : 'btn-success' ?>">
            <?= $isActive ? 'Deactivate' : 'Activate' ?>
        </button>
        <button onclick="toggleFeatured()" class="btn btn-sm <?= ($studio['is_featured'] ?? 0) ? 'btn-warning' : 'btn-info' ?>">
            <?= ($studio['is_featured'] ?? 0) ? 'Unfeature' : '⭐ Feature' ?>
        </button>
        <button onclick="openLocationModal()" class="btn btn-sm btn-primary">Edit Location</button>
    </div>
</div>

<div class="tabs" id="studioTabs">
    <button class="tab active" data-tab="overview">Overview</button>
    <button class="tab" data-tab="services">Services</button>
    <button class="tab" data-tab="instructors">Instructors</button>
    <button class="tab" data-tab="schedules">Schedules</button>
    <button class="tab" data-tab="bookings">Bookings</button>
    <button class="tab" data-tab="documents">Documents</button>
</div>

<div class="tab-content active" id="overview">
    <div class="card" style="margin-bottom: 20px;">
        <h2>Studio Earnings</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 16px;">
            <div class="stat-card">
                <div class="stat-value"><?= $earnings['total_bookings'] ?? 0 ?></div>
                <div class="stat-label">Total Bookings</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">₱<?= number_format($earnings['total_earnings'] ?? 0, 2) ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Studio Information</h2>
        <table>
            <tr>
                <th style="width: 200px;">Name</th>
                <td><?= htmlspecialchars($studio['StudioName']) ?></td>
            </tr>
            <tr>
                <th>Location</th>
                <td><?= htmlspecialchars($studio['Loc_Desc'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <th>Coordinates</th>
                <td>
                    <?php if ($studio['Latitude'] && $studio['Longitude']): ?>
                        Lat: <?= number_format($studio['Latitude'], 6) ?>, Lng: <?= number_format($studio['Longitude'], 6) ?>
                    <?php else: ?>
                        Not set
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Owner Email</th>
                <td><?= htmlspecialchars($studio['owner_email'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <th>Owner Phone</th>
                <td><?= htmlspecialchars($studio['owner_phone'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <th>Hours</th>
                <td>
                    <?php if ($studio['Time_IN'] && $studio['Time_OUT']): ?>
                        <?= date('H:i', strtotime($studio['Time_IN'])) ?> - <?= date('H:i', strtotime($studio['Time_OUT'])) ?>
                    <?php else: ?>
                        Not set
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Status</th>
                <td>
                    <span class="badge <?= $studio['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                        <?= $studio['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Approved</th>
                <td>
                    <?php if ($studio['approved_by_admin']): ?>
                        <span class="badge badge-success">Yes</span>
                        <?php if ($studio['approved_at']): ?>
                            on <?= date('M d, Y H:i', strtotime($studio['approved_at'])) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge badge-warning">Pending</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
</div>

<div class="tab-content" id="services">
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2>Services (<?= count($services) ?>)</h2>
            <button onclick="openServiceModal()" class="btn btn-sm btn-primary">Add Service</button>
        </div>
        <?php if (empty($services)): ?>
            <div class="empty-state">
                <p>No services available</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Price</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><?= htmlspecialchars($service['ServiceType']) ?></td>
                            <td><?= htmlspecialchars($service['Description'] ?? 'N/A') ?></td>
                            <td>₱<?= number_format($service['Price'], 2) ?></td>
                            <td>
                                <button onclick="removeService(<?= $service['ServiceID'] ?>)" class="btn btn-sm btn-danger">Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="tab-content" id="instructors">
    <div class="card">
        <h2>Instructors (<?= count($instructors) ?>)</h2>
        <?php if (empty($instructors)): ?>
            <div class="empty-state">
                <p>No instructors</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Profession</th>
                        <th>Added</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($instructors as $instructor): ?>
                        <tr>
                            <td><?= htmlspecialchars($instructor['Name']) ?></td>
                            <td><?= htmlspecialchars($instructor['Email'] ?? 'N/A') ?></td>
                            <td class="text-sm"><?= htmlspecialchars($instructor['Phone'] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="tab-content" id="schedules">
    <div class="card">
        <h2>Upcoming Schedules</h2>
        <?php if (empty($schedules)): ?>
            <div class="empty-state">
                <p>No upcoming schedules</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Instructor</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr>
                            <td>Schedule #<?= $schedule['ScheduleID'] ?></td>
                            <td><?= htmlspecialchars($schedule['instructor_name'] ?? 'N/A') ?></td>
                            <td><?= $schedule['Sched_Date'] ? date('M d, Y', strtotime($schedule['Sched_Date'])) : 'N/A' ?> <?= $schedule['Time_Start'] ? date('H:i', strtotime($schedule['Time_Start'])) : '' ?></td>
                            <td><?= $schedule['Sched_Date'] ? date('M d, Y', strtotime($schedule['Sched_Date'])) : 'N/A' ?> <?= $schedule['Time_End'] ? date('H:i', strtotime($schedule['Time_End'])) : '' ?></td>
                            <td><span class="badge badge-secondary">Scheduled</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="tab-content" id="bookings">
    <div class="card">
        <h2>Recent Bookings (Last 20)</h2>
        <?php if (empty($bookings)): ?>
            <div class="empty-state">
                <p>No bookings yet</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>Client</th>
                        <th>Date/Time</th>
                        <th>Amount</th>
                        <th>Payment Status</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td>#<?= $booking['BookingID'] ?></td>
                            <td><?= htmlspecialchars($booking['client_name'] ?? 'Client #' . ($booking['ClientID'] ?? 'N/A')) ?></td>
                                        <td><?= $booking['booking_date'] ? date('M d, Y H:i', strtotime($booking['booking_date'])) : 'N/A' ?></td>
                            <td>₱<?= number_format($booking['Price'] ?? 0, 2) ?></td>
                            <td>
                                <?php
                                // Display payment status from payment table's Pay_Stats column
                                $paymentStatus = $booking['payment_status'] ?? null;

                                if ($paymentStatus) {
                                    // Map Pay_Stats values to badge colors
                                    switch (strtolower($paymentStatus)) {
                                        case 'completed':
                                        case 'paid':
                                            $paymentBadge = 'badge-success';
                                            $paymentText = 'Completed';
                                            break;
                                        case 'pending':
                                            $paymentBadge = 'badge-warning';
                                            $paymentText = 'Pending';
                                            break;
                                        case 'failed':
                                        case 'cancelled':
                                            $paymentBadge = 'badge-danger';
                                            $paymentText = ucfirst($paymentStatus);
                                            break;
                                        default:
                                            $paymentBadge = 'badge-secondary';
                                            $paymentText = ucfirst($paymentStatus);
                                    }
                                } else {
                                    $paymentBadge = 'badge-secondary';
                                    $paymentText = 'No Payment';
                                }
                                ?>
                                <span class="badge <?= $paymentBadge ?>"><?= $paymentText ?></span>
                            </td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($booking['booking_status'] ?? 'N/A') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="tab-content" id="documents">
    <div class="card">
        <h2>Documents (<?= count($documents) ?>)</h2>
        <?php if (empty($documents)): ?>
            <div class="empty-state">
                <p>No documents</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Type</th>
                        <th>Uploaded At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td>
                                <?php
                                    $docPathRaw = $doc['file_path'] ?? '';
                                    $docPath = '/' . ltrim($docPathRaw, '/');
                                    $docName = $doc['file_name'] ?? basename($docPathRaw);
                                    $docMime = $doc['mime_type'] ?? '';
                                    $docType = $doc['document_type'] ?? ($doc['file_type'] ?? 'N/A');
                                ?>
                                <a href="<?= htmlspecialchars($docPath) ?>" target="_blank" rel="noopener">
                                    <?= htmlspecialchars($docName, ENT_QUOTES) ?>
                                </a>
                            </td>
                            <td><span class="badge badge-secondary"><?= htmlspecialchars($docType) ?></span></td>
                            <td><?= isset($doc['uploaded_at']) ? date('M d, Y H:i', strtotime($doc['uploaded_at'])) : 'N/A' ?></td>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <button class="btn btn-sm btn-primary" onclick="openDocPreview('<?= htmlspecialchars($docPath, ENT_QUOTES) ?>','<?= htmlspecialchars($docMime, ENT_QUOTES) ?>','<?= htmlspecialchars($docName, ENT_QUOTES) ?>')">Preview</button>
                                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars($docPath) ?>" target="_blank" rel="noopener">Open</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <a href="../php/studios.php" class="btn btn-secondary">Back to Studios</a>
</div>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- MODALS -->
<!-- Location Modal -->
<div id="locationModal" class="modal">
    <div class="modal-content">
        <h2>Edit Studio Location</h2>
        <div class="form-group">
            <label>Search Location</label>
            <input type="text" id="locationSearch" class="form-input" placeholder="Search for a location...">
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Type a location and press Enter to search</p>
        </div>
        <div class="form-group">
            <label>Location Description</label>
            <input type="text" id="locDesc" class="form-input" value="<?= htmlspecialchars($studio['Loc_Desc'] ?? '') ?>" placeholder="e.g., Quezon City, Metro Manila" readonly style="background: #f5f5f5;">
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Automatically updated when you click on the map or search</p>
        </div>
        <div id="map" style="height: 400px; border-radius: 8px; margin: 16px 0;"></div>
        <p style="font-size: 13px; color: var(--text-muted);">Click on the map to set studio location or use the search field above</p>
        <input type="hidden" id="lat" value="<?= $studio['Latitude'] ?? '' ?>">
        <input type="hidden" id="lng" value="<?= $studio['Longitude'] ?? '' ?>">
        <div style="display: flex; gap: 8px; margin-top: 16px;">
            <button onclick="saveLocation()" class="btn btn-primary">Save Location</button>
            <button onclick="closeLocationModal()" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<!-- Service Modal -->
<div id="serviceModal" class="modal">
    <div class="modal-content">
        <h2>Add Service</h2>
        <div class="form-group">
            <label>Select Service</label>
            <select id="serviceSelect" class="form-select">
                <option value="">-- Choose a service --</option>
                <?php foreach ($availableServices as $svc): ?>
                    <option value="<?= $svc['ServiceID'] ?>"><?= htmlspecialchars($svc['ServiceType']) ?> - ₱<?= number_format($svc['Price'], 2) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display: flex; gap: 8px; margin-top: 16px;">
            <button onclick="addService()" class="btn btn-primary">Add Service</button>
            <button onclick="closeServiceModal()" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<!-- Document Preview Modal -->
<div id="docPreviewModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <h2 id="docPreviewTitle">Document Preview</h2>
        <div id="docPreviewBody" style="margin-top: 12px;"></div>
        <div style="display: flex; gap: 8px; margin-top: 12px;">
            <a id="docViewDownload" href="#" class="btn btn-primary" target="_blank" rel="noopener">Open in new tab</a>
            <button onclick="closeDocPreview()" class="btn btn-secondary">Close</button>
        </div>
    </div>
 </div>

<style>
    /* Tab Content - Hide by default, show when active */
    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    /* Tab Buttons */
    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        border-bottom: 2px solid var(--border, #ddd);
    }

    .tab {
        padding: 12px 20px;
        background: none;
        border: none;
        border-bottom: 3px solid transparent;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        color: var(--text-muted, #666);
        transition: all 0.2s;
    }

    .tab:hover {
        color: var(--text, #333);
        background: var(--hover, rgba(0, 0, 0, 0.05));
    }

    .tab.active {
        color: var(--primary, #0066cc);
        border-bottom-color: var(--primary, #0066cc);
    }

    /* Modals */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        align-items: center;
        justify-content: center;
    }

    .modal.active {
        display: flex;
    }

    .modal-content {
        background: var(--card);
        padding: 24px;
        border-radius: 8px;
        max-width: 600px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
    }

    .flex-between {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .mb-4 {
        margin-bottom: 16px;
    }
</style>

<script>
    const studioId = <?= $studioId ?>;
    let currentStatus = <?= $studio['is_active'] ?>;
    let map, marker;

    // Tab Switching - Inline execution
    (function initTabs() {
        console.log('=== TAB DEBUG START ===');
        console.log('DOM state:', document.readyState);

        const tabButtons = document.querySelectorAll('.tabs .tab');
        const tabContents = document.querySelectorAll('.tab-content');

        console.log('Tab buttons found:', tabButtons.length);
        console.log('Tab contents found:', tabContents.length);

        if (tabButtons.length === 0) {
            console.error('ERROR: No tab buttons found!');
            console.log('Tabs container:', document.querySelector('.tabs'));
            return;
        }

        tabButtons.forEach((button, index) => {
            console.log('Setting up tab', index, ':', button.getAttribute('data-tab'));

            button.onclick = function(e) {
                e.preventDefault();
                const targetTab = this.getAttribute('data-tab');
                console.log('>>> TAB CLICKED:', targetTab);

                // Remove active from all
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));

                // Add active
                this.classList.add('active');

                const targetContent = document.getElementById(targetTab);
                if (targetContent) {
                    targetContent.classList.add('active');
                    console.log('>>> Tab switched successfully to:', targetTab);
                } else {
                    console.error('>>> ERROR: Content not found for:', targetTab);
                }
            };
        });

        console.log('=== TAB INIT COMPLETE ===');
    })();

    // Toggle Status
    function toggleStatus() {
        if (!confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this studio?')) return;

        fetch('../php/api/studio-actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=toggle_status&studio_id=${studioId}&current_status=${currentStatus}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
    }

    // Toggle Featured
    function toggleFeatured() {
        const isFeatured = <?= ($studio['is_featured'] ?? 0) ?>;
        const action = isFeatured ? 'unfeature' : 'feature';

        if (!confirm('Are you sure you want to ' + action + ' this studio?')) return;

        fetch('../php/api/studio-actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=toggle_featured&studio_id=${studioId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
    }

    // Location Modal with Geocoding
    function openLocationModal() {
        document.getElementById('locationModal').classList.add('active');
        setTimeout(() => {
            if (!map) {
                const lat = parseFloat(document.getElementById('lat').value) || 14.5995; // Default Manila
                const lng = parseFloat(document.getElementById('lng').value) || 120.9842;

                map = L.map('map').setView([lat, lng], 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap'
                }).addTo(map);

                if (document.getElementById('lat').value && document.getElementById('lng').value) {
                    marker = L.marker([lat, lng]).addTo(map);
                }

                // Map click handler with reverse geocoding
                map.on('click', function(e) {
                    if (marker) map.removeLayer(marker);
                    marker = L.marker(e.latlng).addTo(map);
                    document.getElementById('lat').value = e.latlng.lat;
                    document.getElementById('lng').value = e.latlng.lng;
                    
                    // Reverse geocode to get location description
                    reverseGeocode(e.latlng.lat, e.latlng.lng);
                });

                // Search location functionality
                const searchInput = document.getElementById('locationSearch');
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const query = this.value.trim();
                        if (query) {
                            searchLocation(query);
                        }
                    }
                });
            }
            map.invalidateSize();
        }, 100);
    }

    // Forward Geocoding - Search location by name
    function searchLocation(query) {
        const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data && data.length > 0) {
                    const result = data[0];
                    const lat = parseFloat(result.lat);
                    const lng = parseFloat(result.lon);
                    
                    // Update map
                    map.setView([lat, lng], 15);
                    if (marker) map.removeLayer(marker);
                    marker = L.marker([lat, lng]).addTo(map);
                    
                    // Update hidden fields
                    document.getElementById('lat').value = lat;
                    document.getElementById('lng').value = lng;
                    
                    // Update location description
                    document.getElementById('locDesc').value = result.display_name;
                    
                    // Clear search input
                    document.getElementById('locationSearch').value = '';
                } else {
                    alert('Location not found. Please try a different search term.');
                }
            })
            .catch(error => {
                console.error('Geocoding error:', error);
                alert('Error searching location. Please try again.');
            });
    }

    // Reverse Geocoding - Get location name from coordinates
    function reverseGeocode(lat, lng) {
        const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data && data.display_name) {
                    document.getElementById('locDesc').value = data.display_name;
                } else {
                    document.getElementById('locDesc').value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                }
            })
            .catch(error => {
                console.error('Reverse geocoding error:', error);
                document.getElementById('locDesc').value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            });
    }

    function closeLocationModal() {
        document.getElementById('locationModal').classList.remove('active');
    }

    function saveLocation() {
        const lat = document.getElementById('lat').value;
        const lng = document.getElementById('lng').value;
        const locDesc = document.getElementById('locDesc').value;

        if (!lat || !lng) {
            alert('Please select a location on the map or search for a location');
            return;
        }

        if (!locDesc || locDesc.trim() === '') {
            alert('Location description is required. Please click on the map or search for a location.');
            return;
        }

        fetch('../php/api/studio-actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=update_location&studio_id=${studioId}&latitude=${lat}&longitude=${lng}&loc_desc=${encodeURIComponent(locDesc)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
    }

    // Document Preview
    function openDocPreview(url, mime, title) {
        try {
            const modal = document.getElementById('docPreviewModal');
            const body = document.getElementById('docPreviewBody');
            const dl = document.getElementById('docViewDownload');
            const titleEl = document.getElementById('docPreviewTitle');

            if (!modal || !body || !dl || !titleEl) return;

            titleEl.textContent = title || 'Document Preview';
            dl.href = url;

            body.innerHTML = '';

            const lowerUrl = (url || '').toLowerCase();
            const isImage = mime?.startsWith('image/') ||
                lowerUrl.endsWith('.png') || lowerUrl.endsWith('.jpg') || lowerUrl.endsWith('.jpeg') || lowerUrl.endsWith('.gif') || lowerUrl.endsWith('.webp');
            const isPdf = mime === 'application/pdf' || lowerUrl.endsWith('.pdf');

            if (isImage) {
                const img = document.createElement('img');
                img.src = url;
                img.alt = title || 'Document';
                img.style.maxWidth = '100%';
                img.style.borderRadius = '8px';
                body.appendChild(img);
            } else if (isPdf) {
                const iframe = document.createElement('iframe');
                iframe.src = url;
                iframe.style.width = '100%';
                iframe.style.height = '600px';
                iframe.style.border = 'none';
                body.appendChild(iframe);
            } else {
                const p = document.createElement('p');
                p.textContent = 'Preview not available for this file type. Use “Open in new tab”.';
                p.style.color = 'var(--text-muted, #666)';
                body.appendChild(p);
            }

            modal.classList.add('active');
        } catch (e) {
            console.error('Failed to open document preview:', e);
            alert('Unable to preview this document. Try opening in a new tab.');
        }
    }

    function closeDocPreview() {
        const modal = document.getElementById('docPreviewModal');
        if (modal) modal.classList.remove('active');
    }

    // Service Management
    function openServiceModal() {
        document.getElementById('serviceModal').classList.add('active');
    }

    function closeServiceModal() {
        document.getElementById('serviceModal').classList.remove('active');
    }

    function addService() {
        const serviceId = document.getElementById('serviceSelect').value;
        if (!serviceId) {
            alert('Please select a service');
            return;
        }

        fetch('../php/api/studio-actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=add_service&studio_id=${studioId}&service_id=${serviceId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
    }

    function removeService(serviceId) {
        if (!confirm('Are you sure you want to remove this service?')) return;

        fetch('../php/api/studio-actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=remove_service&studio_id=${studioId}&service_id=${serviceId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
    }
</script>

<?php include __DIR__ . '/views/components/footer.php'; ?>