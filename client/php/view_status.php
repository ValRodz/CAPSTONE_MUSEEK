<?php
session_start();
include '../../shared/config/db.php';
include '../../shared/config/path_config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    echo "<script>alert('Please log in as a client to continue.'); window.location.href='../../auth/php/login.php';</script>";
    exit;
}

$client_id = (int)$_SESSION['user_id'];
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$studio_id = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;
$owner_id  = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : 0;

$studio = [
    'StudioID' => null,
    'StudioName' => 'Studio',
    'Loc_Desc' => 'Location not specified',
    'Latitude' => null,
    'Longitude' => null,
    'OwnerID' => null,
];
$booking_status = 'Unknown';
$payment_status = null;

$booking_details = [
    'BookingID' => null,
    'Sched_Date' => null,
    'Time_Start' => null,
    'Time_End' => null,
    'services' => [],
    'equipment' => []
];

if ($booking_id > 0) {
    // Updated for new_museek.sql schema: fetch booking with services and equipment
    $sql = "SELECT b.BookingID, bs.Book_Stats, sc.Sched_Date, sc.Time_Start, sc.Time_End, b.StudioID,
                   s.StudioName, s.Loc_Desc, s.Latitude, s.Longitude, s.OwnerID, p.Pay_Stats
            FROM bookings b
            JOIN studios s ON b.StudioID = s.StudioID
            JOIN schedules sc ON sc.ScheduleID = b.ScheduleID
            JOIN book_stats bs ON bs.Book_StatsID = b.Book_StatsID
            LEFT JOIN payment p ON p.BookingID = b.BookingID
            WHERE b.BookingID = ? AND b.ClientID = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $booking_id, $client_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $studio['StudioID'] = (int)$row['StudioID'];
        $studio['StudioName'] = $row['StudioName'];
        $studio['Loc_Desc'] = $row['Loc_Desc'];
        $studio['Latitude'] = $row['Latitude'] !== null && $row['Latitude'] !== '' ? (float)$row['Latitude'] : null;
        $studio['Longitude'] = $row['Longitude'] !== null && $row['Longitude'] !== '' ? (float)$row['Longitude'] : null;
        $studio['OwnerID'] = (int)$row['OwnerID'];
        $booking_status = $row['Book_Stats'] ?? 'Unknown';
        $payment_status = $row['Pay_Stats'] ?? null;
        $studio_id = $studio_id ?: $studio['StudioID'];
        $owner_id  = $owner_id ?: $studio['OwnerID'];
        
        // Store booking details
        $booking_details['BookingID'] = (int)$row['BookingID'];
        $booking_details['Sched_Date'] = $row['Sched_Date'];
        $booking_details['Time_Start'] = $row['Time_Start'];
        $booking_details['Time_End'] = $row['Time_End'];
    }
    mysqli_stmt_close($stmt);
    
    // Fetch services for this booking
    if ($booking_details['BookingID']) {
        $services_sql = "SELECT bsv.ServiceID, s.ServiceType, i.Name as InstructorName
                        FROM booking_services bsv
                        JOIN services s ON bsv.ServiceID = s.ServiceID
                        LEFT JOIN instructors i ON bsv.InstructorID = i.InstructorID
                        WHERE bsv.BookingID = ?";
        $services_stmt = mysqli_prepare($conn, $services_sql);
        mysqli_stmt_bind_param($services_stmt, 'i', $booking_details['BookingID']);
        mysqli_stmt_execute($services_stmt);
        $services_res = mysqli_stmt_get_result($services_stmt);
        while ($srv = mysqli_fetch_assoc($services_res)) {
            $booking_details['services'][] = $srv;
        }
        mysqli_stmt_close($services_stmt);
        
        // Fetch equipment for this booking
        $equipment_sql = "SELECT ea.equipment_name, be.quantity
                         FROM booking_equipment be
                         JOIN equipment_addons ea ON be.equipment_id = ea.equipment_id
                         JOIN booking_services bsv ON be.booking_service_id = bsv.booking_service_id
                         WHERE bsv.BookingID = ?";
        $equipment_stmt = mysqli_prepare($conn, $equipment_sql);
        mysqli_stmt_bind_param($equipment_stmt, 'i', $booking_details['BookingID']);
        mysqli_stmt_execute($equipment_stmt);
        $equipment_res = mysqli_stmt_get_result($equipment_stmt);
        while ($eq = mysqli_fetch_assoc($equipment_res)) {
            $booking_details['equipment'][] = $eq;
        }
        mysqli_stmt_close($equipment_stmt);
    }
}

if ((!$studio_id || !$owner_id) && $studio_id > 0) {
    $sql = "SELECT s.StudioID, s.StudioName, s.Loc_Desc, s.Latitude, s.Longitude, s.OwnerID FROM studios s WHERE s.StudioID = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $studio_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $studio['StudioID'] = (int)$row['StudioID'];
        $studio['StudioName'] = $row['StudioName'];
        $studio['Loc_Desc'] = $row['Loc_Desc'];
        $studio['Latitude'] = $row['Latitude'] !== null && $row['Latitude'] !== '' ? (float)$row['Latitude'] : null;
        $studio['Longitude'] = $row['Longitude'] !== null && $row['Longitude'] !== '' ? (float)$row['Longitude'] : null;
        $studio['OwnerID'] = (int)$row['OwnerID'];
        $owner_id = $owner_id ?: $studio['OwnerID'];
    }
    mysqli_stmt_close($stmt);
}

// Fallback coordinates if none are available
$lat = $studio['Latitude'] ?? 10.2333;
$lng = $studio['Longitude'] ?? 123.0833;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Status - <?php echo htmlspecialchars($studio['StudioName']); ?></title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --secondary-color: #3b82f6;
            --background-dark: #0f0f0f;
            --background-card: rgba(20, 20, 20, 0.95);
            --background-sidebar: rgba(15, 15, 15, 0.98);
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --text-muted: #888888;
            --border-color: #333333;
            --border-light: #444444;
            --shadow-light: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-medium: 0 4px 16px rgba(0, 0, 0, 0.4);
            --shadow-heavy: 0 8px 32px rgba(0, 0, 0, 0.6);
            --border-radius: 12px;
            --border-radius-small: 8px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        body, main {
            background: linear-gradient(135deg, rgba(15, 15, 15, 0.9), rgba(30, 30, 30, 0.8)), 
                        url('../../shared/assets/images/dummy/slide-1.jpg') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary);
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .main-content { background: var(--background-dark); min-height: calc(100vh - 200px); padding: 60px 0; margin-top: 8%; }
        .page-title { text-align:center; font-weight:700; font-size: clamp(28px, 5vw, 42px); margin-bottom: 16px; letter-spacing:-0.5px; background: linear-gradient(135deg, var(--primary-color), #ff6b6b); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .page-subtitle { text-align:center; color: var(--text-secondary); margin-bottom: 50px; font-size: 18px; max-width: 600px; margin-left:auto; margin-right:auto; }
        .map-container { display:grid; grid-template-columns: minmax(340px, 420px) 1fr; gap: 24px; max-width: 1400px; margin: 0 auto; padding: 0 24px; }
        .map-sidebar { background: var(--background-sidebar); border-radius: var(--border-radius); padding: 24px; height: auto; min-height: 560px; display:flex; flex-direction:column; gap:16px; box-shadow: var(--shadow-medium); backdrop-filter: blur(10px); border:1px solid var(--border-color); position: sticky; top: 24px; }
        #map { height: 640px; border-radius: var(--border-radius); overflow: hidden; box-shadow: var(--shadow-medium); border:1px solid var(--border-color); }
        .studio-details { margin-top: 4px; display:flex; flex-direction:column; gap:12px; }
        .detail-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius: 10px; border:1px solid var(--border-color); background: var(--background-card); color: var(--text-secondary); }
        .detail-item i { color: var(--primary-color); }
        .status-chip { display:inline-block; padding:6px 10px; border-radius:999px; border:1px solid var(--border-color); background: rgba(229,9,20,0.15); color: var(--text-primary); font-weight:600; }
        .chat-box { background: var(--background-card); border:1px solid var(--border-color); border-radius: var(--border-radius); display:flex; flex-direction:column; height: 300px; margin-top: 20px; box-shadow: var(--shadow-light); }
        .chat-messages { flex:1; overflow-y:auto; padding:12px; scrollbar-width: thin; scrollbar-color: var(--border-color) transparent; }
        .chat-input { display:flex; gap:8px; padding:10px; border-top:1px solid var(--border-color); }
        .chat-input input { flex:1; padding:10px; border-radius:6px; border:1px solid var(--border-color); background: var(--background-dark); color: var(--text-primary); }
        .chat-input button { padding:10px 14px; border-radius:6px; background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); color:#fff; border:none; cursor:pointer; font-weight:600; transition: var(--transition); }
        .chat-input button:hover { background: linear-gradient(135deg, var(--primary-hover), #ff1a1a); transform: translateY(-1px); }
        .msg { margin-bottom:10px; }
        .msg.client { text-align:right; }
        .msg .bubble { display:inline-block; padding:8px 12px; border-radius:16px; max-width:75%; }
        .msg.client .bubble { background:#e50914; color:#fff; }
        .msg.owner .bubble { background:#2196f3; color:#fff; }
        .route-info { margin-top:10px; color: var(--text-secondary); padding:10px 12px; border-radius: 10px; border:1px solid var(--border-color); background: var(--background-card); }
        .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px; }
        .back-link { color:#e50914; text-decoration:none; font-weight:600; }
        @media (max-width: 968px) { .map-container { grid-template-columns: 1fr; } #map { height: 400px; } .map-sidebar { height:auto; } }
        .route-controls { display:flex; gap:10px; margin-top:12px; }
        .btn-small { padding:8px 12px; border-radius:8px; background: var(--primary-color); color:#fff; border:none; cursor:pointer; font-weight:600; box-shadow: var(--shadow-light); }
        .btn-small.secondary { background:#2a2a2a; color:#fff; }
        .btn-small i { margin-right:6px; }
        /* marker styling */
        .custom-marker-wrapper, .user-marker-wrapper { transform: translateY(-8px); }
        .custom-marker { width: 28px; height: 28px; border-radius: 50%; background: var(--primary-color); color:#fff; display:flex; align-items:center; justify-content:center; box-shadow: var(--shadow-heavy); }
        .custom-marker i { font-size: 16px; }
        .custom-marker.selected { background: #ff4747; }
        .user-marker { width: 22px; height: 22px; border-radius: 50%; background: var(--secondary-color); color:#fff; display:flex; align-items:center; justify-content:center; box-shadow: var(--shadow-heavy); }
        .user-marker i { font-size: 12px; }
        /* chat overflow handling */
        .chat-box { max-width: 100%; overflow: hidden; }
        .chat-messages { overflow-x: hidden; }
        .msg .bubble { overflow-wrap: anywhere; word-break: break-word; white-space: pre-wrap; }
    </style>
</head>
<body class="header-collapse">
<div id="site-content">
    <?php include '../../shared/components/navbar.php'; ?>
    <main class="main-content">
        <h1 class="page-title">View Status</h1>
        <p class="page-subtitle">Studio details, status, chat, and route to the studio</p>
        <div class="map-container">
            <aside class="map-sidebar">
                <div class="toolbar">
                    <div>
                        <h2 style="margin:0;"><?php echo htmlspecialchars($studio['StudioName']); ?></h2>
                    </div>
                    <a class="back-link" href="client_bookings.php">← Back to Bookings</a>
                </div>
                <div class="studio-details">
                    <div class="detail-item"><i class="fa fa-map-marker"></i><span><?php echo htmlspecialchars($studio['Loc_Desc']); ?></span></div>
                    
                    <!-- Booking Details -->
                    <?php if ($booking_details['BookingID']): ?>
                        <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border-color);">
                            <h3 style="margin:0 0 12px 0; font-size:16px; color:var(--text-primary);">
                                <i class="fa fa-calendar-check" style="color:var(--primary-color);"></i> Booking Details
                            </h3>
                            
                            <!-- Services Availed -->
                            <?php if (!empty($booking_details['services'])): ?>
                                <div class="detail-item" style="flex-direction:column; align-items:flex-start;">
                                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                                        <i class="fa fa-music"></i>
                                        <strong>Services Availed:</strong>
                                    </div>
                                    <?php foreach ($booking_details['services'] as $service): ?>
                                        <div style="padding-left:26px; color:var(--text-secondary); font-size:14px;">
                                            • <?php echo htmlspecialchars($service['ServiceType']); ?>
                                            <?php if (!empty($service['InstructorName'])): ?>
                                                <span style="color:var(--text-muted);"> with <?php echo htmlspecialchars($service['InstructorName']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="detail-item">
                                    <i class="fa fa-music"></i>
                                    <span style="color:var(--text-muted);">No services selected</span>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Equipment Rented -->
                            <?php if (!empty($booking_details['equipment'])): ?>
                                <div class="detail-item" style="flex-direction:column; align-items:flex-start;">
                                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                                        <i class="fa fa-tools"></i>
                                        <strong>Equipment Rented:</strong>
                                    </div>
                                    <?php foreach ($booking_details['equipment'] as $eq): ?>
                                        <div style="padding-left:26px; color:var(--text-secondary); font-size:14px;">
                                            • <?php echo htmlspecialchars($eq['equipment_name']); ?> 
                                            <span style="color:var(--text-muted);">(Qty: <?php echo $eq['quantity']; ?>)</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="detail-item">
                                    <i class="fa fa-tools"></i>
                                    <span style="color:var(--text-muted);">No equipment rented</span>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Date & Time -->
                            <div class="detail-item">
                                <i class="fa fa-calendar"></i>
                                <span><?php echo date('M j, Y', strtotime($booking_details['Sched_Date'])); ?></span>
                            </div>
                            
                            <div class="detail-item">
                                <i class="fa fa-clock"></i>
                                <span>
                                    <?php echo date('g:i A', strtotime($booking_details['Time_Start'])); ?> - 
                                    <?php echo date('g:i A', strtotime($booking_details['Time_End'])); ?>
                                </span>
                            </div>
                            
                            <!-- Booking Status -->
                            <div class="detail-item" style="background: rgba(229, 9, 20, 0.1); border-color: var(--primary-color);">
                                <i class="fa fa-info-circle" style="color: var(--primary-color);"></i>
                                <span><strong>Booking Status:</strong> <?php echo htmlspecialchars($booking_status); ?></span>
                            </div>
                            
                            <!-- Payment Status -->
                            <?php if ($payment_status): ?>
                                <div class="detail-item" style="background: rgba(59, 130, 246, 0.1); border-color: var(--secondary-color);">
                                    <i class="fa fa-credit-card" style="color: var(--secondary-color);"></i>
                                    <span><strong>Payment Status:</strong> <?php echo htmlspecialchars($payment_status); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="route-info" id="routeInfo" style="margin-top:16px;">Enable location to view route and ETA.</div>
                    <div class="route-controls">
                        <button id="startLiveBtn" class="btn-small"><i class="fa fa-person-walking"></i> Live walking route</button>
                        <button id="refreshRouteBtn" class="btn-small secondary"><i class="fa fa-sync"></i> Refresh</button>
                    </div>
                </div>
                <div class="chat-box">
                    <div id="chatMessages" class="chat-messages"><div style="color:#aaa;text-align:center;">Loading chat...</div></div>
                    <form id="chatForm" class="chat-input">
                        <input type="text" id="chatInput" placeholder="Type your message..." required />
                        <button type="submit">Send</button>
                    </form>
                </div>
            </aside>
            <div id="map"></div>
        </div>
    </main>
    <?php include '../../shared/components/footer.php'; ?>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo getJSPath('plugins.js'); ?>"></script>
<script src="<?php echo getJSPath('app.js'); ?>"></script>
<script>
const studio = {
    id: <?php echo (int)($studio['StudioID'] ?? 0); ?>,
    name: <?php echo json_encode($studio['StudioName']); ?>,
    addr: <?php echo json_encode($studio['Loc_Desc']); ?>,
    lat: <?php echo json_encode($lat); ?>,
    lng: <?php echo json_encode($lng); ?>,
    ownerId: <?php echo (int)($owner_id ?: ($studio['OwnerID'] ?? 0)); ?>
};
const clientId = <?php echo (int)$client_id; ?>;

let map, userMarker, studioMarker, routeLine, watchId, liveActive = false;
const Lref = window.L;

function initMap() {
    if (!Lref) {
        console.error('Leaflet not loaded');
        document.getElementById('map').innerHTML = "<p style='color:red;text-align:center;'>Map failed to load.</p>";
        return;
    }
    map = Lref.map('map').setView([studio.lat, studio.lng], 14);
    Lref.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
    const studioIcon = Lref.divIcon({ className: 'custom-marker-wrapper', html: `<div class="custom-marker"><i class="fa fa-map-marker"></i></div>`, iconSize: [30,30], iconAnchor: [15,30] });
    studioMarker = Lref.marker([studio.lat, studio.lng], { icon: studioIcon }).addTo(map).bindPopup(`<strong>${studio.name}</strong><div style='font-size:13px;color:#666;'>${studio.addr || 'Location not specified'}</div>`);
    studioMarker.openPopup();
    setTimeout(() => map.invalidateSize(), 100);
}

function getUserLocation() {
    const info = document.getElementById('routeInfo');
    if (!navigator.geolocation) {
        info.textContent = 'Geolocation is not supported by your browser.';
        return;
    }
    const isSecure = (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1');
    if (!isSecure) { info.textContent = 'Tip: Geolocation needs HTTPS or localhost.'; } else { info.textContent = 'Locating…'; }
    navigator.geolocation.getCurrentPosition(pos => {
        const { latitude, longitude } = pos.coords;
        const userIcon = Lref.divIcon({ className: 'user-marker-wrapper', html: `<div class="user-marker"><i class="fa fa-location-arrow"></i></div>`, iconSize: [28,28], iconAnchor: [14,14] });
        if (userMarker) {
            userMarker.setLatLng([latitude, longitude]);
        } else {
            userMarker = Lref.marker([latitude, longitude], { icon: userIcon }).addTo(map).bindPopup('You are here');
        }
        userMarker.openPopup();
        drawRoute(latitude, longitude, studio.lat, studio.lng);
        // start live updates while walking (only when live is active)
        if (liveActive) {
            try {
                if (watchId) navigator.geolocation.clearWatch(watchId);
                watchId = navigator.geolocation.watchPosition(p => {
                    const { latitude: lat2, longitude: lng2 } = p.coords;
                    if (userMarker) { userMarker.setLatLng([lat2, lng2]); }
                    else { userMarker = Lref.marker([lat2, lng2], { icon: userIcon }).addTo(map).bindPopup('You are here'); }
                    drawRoute(lat2, lng2, studio.lat, studio.lng);
                }, err => {
                    console.warn('Live routing error:', err);
                }, { enableHighAccuracy: false, maximumAge: 5000, timeout: 60000 });
            } catch(e) {}
        }
    }, err => {
        let msg = 'Unable to retrieve location.';
        if (err && typeof err.code === 'number') {
            if (err.code === 1) msg = 'Location permission denied. Please allow access.';
            else if (err.code === 2) msg = 'Location unavailable. Enable GPS or check connection.';
            else if (err.code === 3) msg = 'Location request timed out. Tap Refresh.';
        } else if (err && err.message) {
            msg = err.message;
        }
        const isSecure2 = (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1');
        if (!isSecure2) msg += ' Geolocation needs HTTPS or localhost.';
        info.textContent = msg;
    }, { enableHighAccuracy: false, maximumAge: 5000, timeout: 30000 });
}

function drawRoute(userLat, userLng, studioLat, studioLng) {
    const info = document.getElementById('routeInfo');
    const url = `https://router.project-osrm.org/route/v1/walking/${userLng},${userLat};${studioLng},${studioLat}?overview=full&geometries=geojson`;
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.routes || !data.routes.length) { info.textContent = 'No route found.'; return; }
            const route = data.routes[0];
            const coords = route.geometry.coordinates.map(c => [c[1], c[0]]);
            if (routeLine) { map.removeLayer(routeLine); }
            routeLine = Lref.polyline(coords, { color: '#e50914', weight: 5, opacity: 0.8 }).addTo(map);
            const km = (route.distance / 1000).toFixed(1);
            const mins = Math.round(route.duration / 60);
            info.textContent = `Distance: ${km} km · ETA: ${mins} mins`;
            const bounds = Lref.latLngBounds([[userLat, userLng], [studioLat, studioLng]]);
            map.fitBounds(bounds, { padding: [40, 40] });
        })
        .catch(err => { info.textContent = 'Routing error: ' + (err && err.message ? err.message : 'Unknown error'); });
}
function refreshRoute() {
    const info = document.getElementById('routeInfo');
    if (userMarker) {
        const ll = userMarker.getLatLng();
        info.textContent = 'Updating route…';
        drawRoute(ll.lat, ll.lng, studio.lat, studio.lng);
    } else {
        getUserLocation();
    }
}

// Simple chat using existing messaging APIs
const chatMessages = document.getElementById('chatMessages');
const chatForm = document.getElementById('chatForm');
const chatInput = document.getElementById('chatInput');

function renderMessages(messages) {
    chatMessages.innerHTML = '';
    if (!messages.length) { chatMessages.innerHTML = "<div style='color:#aaa;text-align:center;'>No messages yet.</div>"; return; }
    messages.forEach(msg => {
        const who = (msg.Sender_Type && msg.Sender_Type.toLowerCase() === 'client') ? 'client' : 'owner';
        const div = document.createElement('div');
        div.className = 'msg ' + who;
        const safe = (msg.Content || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        div.innerHTML = `<span class='bubble'>${safe}</span>`;
        chatMessages.appendChild(div);
    });
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function fetchChat() {
    if (!studio.ownerId) { chatMessages.innerHTML = "<div style='color:#f99;text-align:center;'>Missing owner information.</div>"; return; }
    fetch(`../../messaging/php/fetch_chat.php?owner_id=${studio.ownerId}`)
        .then(r => r.json())
        .then(data => { if (data.success) renderMessages(data.messages); else chatMessages.innerHTML = `<div style='color:#f99;text-align:center;'>${data.error}</div>`; })
        .catch(() => chatMessages.innerHTML = "<div style='color:#f99;text-align:center;'>Failed to load chat.</div>");
}

chatForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const content = chatInput.value.trim();
    if (!content) return;
    chatInput.value = '';
    const body = `content=${encodeURIComponent(content)}&owner_id=${studio.ownerId}&client_id=${clientId}&studio_id=${studio.id}`;
    fetch('../../messaging/php/send_message.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => { if (data.success) fetchChat(); else alert(data.error || 'Failed to send'); })
        .catch(() => alert('Network error while sending'));
});

// Attach controls
const startBtn = document.getElementById('startLiveBtn');
if (startBtn) startBtn.onclick = () => { liveActive = true; getUserLocation(); };
const refreshBtn = document.getElementById('refreshRouteBtn');
if (refreshBtn) refreshBtn.onclick = () => refreshRoute();

// Initialize
initMap();
fetchChat();
getUserLocation();
</script>
</body>
</html>