<?php
// Quick DB debug helper for local testing
require_once __DIR__ . '/../../shared/config/db.php';

header('Content-Type: application/json');

$response = [
    'connection' => null,
    'errors' => [],
    'counts' => [],
    'sample' => [],
];

// Check connection
if ($conn->connect_error) {
    $response['connection'] = 'failed';
    $response['errors'][] = $conn->connect_error;
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}
$response['connection'] = 'ok';

// Total studios
$totalQ = "SELECT COUNT(*) AS total FROM studios";
if ($res = $conn->query($totalQ)) {
    $row = $res->fetch_assoc();
    $response['counts']['total_studios'] = (int)$row['total'];
    $res->free();
} else {
    $response['errors'][] = "total query failed: " . $conn->error;
}

// Studios with coords
$coordsQ = "SELECT COUNT(*) AS with_coords FROM studios WHERE Latitude IS NOT NULL AND Longitude IS NOT NULL";
if ($res = $conn->query($coordsQ)) {
    $row = $res->fetch_assoc();
    $response['counts']['with_coords'] = (int)$row['with_coords'];
    $res->free();
} else {
    $response['errors'][] = "coords query failed: " . $conn->error;
}

// Sample rows (limit 5)
$sampleQ = "SELECT StudioID, StudioName, Loc_Desc, Latitude, Longitude, OwnerID FROM studios LIMIT 5";
if ($res = $conn->query($sampleQ)) {
    while ($r = $res->fetch_assoc()) {
        // cast lat/lng to show raw values
        $r['Latitude_raw'] = $r['Latitude'];
        $r['Longitude_raw'] = $r['Longitude'];
        $response['sample'][] = $r;
    }
    $res->free();
} else {
    $response['errors'][] = "sample query failed: " . $conn->error;
}

echo json_encode($response, JSON_PRETTY_PRINT);

?>