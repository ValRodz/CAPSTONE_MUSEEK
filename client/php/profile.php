<?php
session_start();
include '../../shared/config/db.php';
include '../../shared/config/path_config.php';

$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']);

if ($is_authenticated) {
    $client_query = "SELECT ClientID, Name, Email, Phone FROM clients WHERE ClientID = ?";
    $stmt = mysqli_prepare($conn, $client_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $client_result = mysqli_stmt_get_result($stmt);
    $client = mysqli_fetch_assoc($client_result) ?: [
        'ClientID' => 0,
        'Name' => 'Unknown',
        'Email' => 'N/A',
        'Phone' => 'N/A'
    ];
    mysqli_stmt_close($stmt);
    error_log("Client data: " . print_r($client, true));
} else {
    $client = [
        'ClientID' => 0,
        'Name' => 'Guest',
        'Email' => 'N/A',
        'Phone' => 'N/A'
    ];
    error_log("Guest user accessing profile.php");
}

// Accept owner_id (preferred) and optionally studio_id (fallback)
$owner_id = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : 0;
$fallback_studio_id = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;

// If owner_id is not provided but studio_id is, derive owner_id from studio
if ($owner_id === 0 && $fallback_studio_id > 0) {
    $owner_lookup_q = "SELECT OwnerID FROM studios WHERE StudioID = ? AND approved_by_admin IS NOT NULL";
    $stmt = mysqli_prepare($conn, $owner_lookup_q);
    mysqli_stmt_bind_param($stmt, "i", $fallback_studio_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $owner_id = (int)$row['OwnerID'];
    }
    mysqli_stmt_close($stmt);
}

// Fetch owner details
$owner = null;
if ($owner_id > 0) {
    $owner_q = "SELECT OwnerID, Name, Email, Phone FROM studio_owners WHERE OwnerID = ?";
    $stmt = mysqli_prepare($conn, $owner_q);
    mysqli_stmt_bind_param($stmt, "i", $owner_id);
    mysqli_stmt_execute($stmt);
    $owner_res = mysqli_stmt_get_result($stmt);
    $owner = mysqli_fetch_assoc($owner_res);
    mysqli_stmt_close($stmt);
}

// Fetch all studios for the owner
$owner_studios = [];
if ($owner_id > 0) {
    $studios_q = "SELECT StudioID, StudioName, Loc_Desc, StudioImg, Latitude, Longitude, Time_IN, Time_OUT, OwnerID FROM studios WHERE OwnerID = ? AND approved_by_admin IS NOT NULL ORDER BY StudioID ASC";
    $stmt = mysqli_prepare($conn, $studios_q);
    mysqli_stmt_bind_param($stmt, "i", $owner_id);
    mysqli_stmt_execute($stmt);
    $studios_res = mysqli_stmt_get_result($stmt);
    while ($s = mysqli_fetch_assoc($studios_res)) {
        $owner_studios[] = $s;
    }
    mysqli_stmt_close($stmt);
}

// Choose a primary studio for hero/services (first studio if available, otherwise fallback)
$studio_id = 0;
if (!empty($owner_studios)) {
    $studio_id = (int)$owner_studios[0]['StudioID'];
} elseif ($fallback_studio_id > 0) {
    $studio_id = $fallback_studio_id;
}

// Load primary studio details
$studio_query = "
    SELECT s.StudioID, s.StudioName, s.Loc_Desc, s.StudioImg, s.Time_IN, s.Time_OUT, s.OwnerID, so.Name AS OwnerName, so.Email AS OwnerEmail, so.Phone AS OwnerPhone
    FROM studios s
    LEFT JOIN studio_owners so ON s.OwnerID = so.OwnerID
    WHERE s.StudioID = ? AND s.approved_by_admin IS NOT NULL";
$stmt = mysqli_prepare($conn, $studio_query);
mysqli_stmt_bind_param($stmt, "i", $studio_id);
mysqli_stmt_execute($stmt);
$studio_result = mysqli_stmt_get_result($stmt);
$studio = mysqli_fetch_assoc($studio_result);
mysqli_stmt_close($stmt);

if ($studio && !empty($studio['StudioImg'])) {
    $img = $studio['StudioImg'];
    if (preg_match('/^(https?:\\/\\/|\\/)/', $img)) {
        $studio['StudioImgBase64'] = $img;
    } else {
        $studio['StudioImgBase64'] = getImagePath($img);
    }
} else {
    $studio['StudioImgBase64'] = '';
}

if (!$studio) {
    $studio = [
        'StudioID' => $studio_id ?: 0,
        'StudioName' => 'Studio',
        'Loc_Desc' => $owner && !empty($owner['Name']) ? ('Studios by ' . $owner['Name']) : 'Owner Studios',
        'Time_IN' => '09:00:00',
        'Time_OUT' => '22:00:00',
        // No image: leave empty so UI can render a letter avatar
        'StudioImgBase64' => '',
        'OwnerID' => $owner_id ?: 0,
        'OwnerName' => $owner['Name'] ?? 'Owner',
        'OwnerEmail' => $owner['Email'] ?? 'N/A',
        'OwnerPhone' => $owner['Phone'] ?? 'N/A'
    ];
}

// Services for primary studio (optional)
if ($studio_id > 0) {
    $services_query = "
        SELECT se.ServiceType, se.Description, se.Price
        FROM studio_services ss
        LEFT JOIN services se ON ss.ServiceID = se.ServiceID
        WHERE ss.StudioID = ?";
    $stmt = mysqli_prepare($conn, $services_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $services_result = mysqli_stmt_get_result($stmt);

    $services = [];
    while ($service_row = mysqli_fetch_assoc($services_result)) {
        $services[] = $service_row;
    }
    $studio['services'] = $services;
    mysqli_stmt_close($stmt);
} else {
    $studio['services'] = [];
}

// Prepare enriched studios JSON (browse.php style) for owner-only studios
$studios_enriched = [];
foreach ($owner_studios as $row) {
    // Ensure numeric latitude/longitude for JSON and client-side processing
    $row['Latitude'] = ($row['Latitude'] !== null && $row['Latitude'] !== '') ? (float)$row['Latitude'] : null;
    $row['Longitude'] = ($row['Longitude'] !== null && $row['Longitude'] !== '') ? (float)$row['Longitude'] : null;

    if (!empty($row['StudioImg'])) {
        $img2 = $row['StudioImg'];
        if (preg_match('/^(https?:\\/\\/|\\/)/', $img2)) {
            $row['StudioImgBase64'] = $img2;
        } else {
            $row['StudioImgBase64'] = getImagePath($img2);
        }
    } else {
        $row['StudioImgBase64'] = '';
    }

    // Normalize studio id for queries
    $studio_id_local = (int)$row['StudioID'];

    // Average rating for this studio (via bookings)
    $rating_query = "SELECT AVG(f.Rating) as AverageRating FROM feedback f JOIN bookings b ON f.BookingID = b.BookingID WHERE b.StudioID = ?";
    $stmt = mysqli_prepare($conn, $rating_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id_local);
    mysqli_stmt_execute($stmt);
    $rating_result = mysqli_stmt_get_result($stmt);
    $rating_row = mysqli_fetch_assoc($rating_result);
    if ($rating_row && $rating_row['AverageRating'] !== null) {
        $row['AverageRating'] = number_format((float)$rating_row['AverageRating'], 1);
    } else {
        $row['AverageRating'] = "Not rated";
    }
    mysqli_stmt_close($stmt);

    // Services for this studio
    $services_query = "SELECT se.ServiceType, se.Description, se.Price FROM studio_services ss LEFT JOIN services se ON ss.ServiceID = se.ServiceID WHERE ss.StudioID = ?";
    $stmt = mysqli_prepare($conn, $services_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id_local);
    mysqli_stmt_execute($stmt);
    $services_result = mysqli_stmt_get_result($stmt);
    $services = [];
    while ($service_row = mysqli_fetch_assoc($services_result)) {
        $services[] = $service_row;
    }
    $row['services'] = $services;
    mysqli_stmt_close($stmt);

    // Recent feedback for this studio (via bookings)
    $feedback_query = "SELECT f.Rating, f.Comment, c.Name FROM feedback f JOIN bookings b ON f.BookingID = b.BookingID LEFT JOIN clients c ON f.ClientID = c.ClientID WHERE b.StudioID = ? AND f.Rating IS NOT NULL ORDER BY f.FeedbackID DESC LIMIT 5";
    $stmt = mysqli_prepare($conn, $feedback_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id_local);
    mysqli_stmt_execute($stmt);
    $feedback_result = mysqli_stmt_get_result($stmt);
    $feedback = [];
    while ($feedback_row = mysqli_fetch_assoc($feedback_result)) {
        $feedback[] = $feedback_row;
    }
    $row['feedback'] = $feedback;
    mysqli_stmt_close($stmt);

    // Studio gallery images from uploads directory
    $gallery = [];
    $gallery_dir = realpath(__DIR__ . "/../../uploads/studios/" . $studio_id_local);
    if ($gallery_dir && is_dir($gallery_dir)) {
        $files = glob($gallery_dir . "/*.{jpg,jpeg,png,gif}", GLOB_BRACE);
        if ($files) {
            foreach ($files as $file) {
                $gallery[] = '../../uploads/studios/' . $studio_id_local . '/' . basename($file);
            }
        }
    }
    $row['gallery'] = $gallery;

    $studios_enriched[] = $row;
}

$studios_json = json_encode($studios_enriched, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("profile.php studios_json encoding error: " . json_last_error_msg());
    $studios_json = "[]";
}
error_log("Owner data: " . print_r($owner, true));
error_log("Primary studio data: " . print_r($studio, true));
error_log("Owner studios enriched: " . print_r($studios_enriched, true));
// Gallery removed per requirement; no SQL fetch

// Handle chat submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_message'])) {
    $message = trim($_POST['chat_message']);
    $response = '';
    $is_user_message = true;

    // Simple chatbot logic based on keywords
    if (stripos($message, 'services') !== false) {
        if (!empty($services)) {
            $response = "Available services:\n";
            foreach ($services as $service) {
                $response .= "- {$service['ServiceType']}: {$service['Description']} (₱" . number_format($service['Price'], 2) . ")\n";
            }
        } else {
            $response = "No services available for this studio.";
        }
    } elseif (stripos($message, 'hours') !== false || stripos($message, 'time') !== false) {
        $response = "Studio hours: {$studio['Time_IN']} to {$studio['Time_OUT']}";
    } elseif (stripos($message, 'contact') !== false) {
        $response = "Contact the studio at {$studio['OwnerEmail']} or {$studio['OwnerPhone']}.";
    } else {
        $response = "Sorry, I didn't understand that. Try asking about 'services', 'hours', or 'contact'.";
    }

    // Log user message
    if ($message) {
        $chat_query = "INSERT INTO chatlog (ChatID, OwnerID, ClientID, Timestamp, Content) VALUES (?, ?, ?, NOW(), ?)";
        $chat_id = rand(1, 1000000); // Temporary unique ID; consider auto-increment
        $client_id = $is_authenticated ? $client['ClientID'] : NULL;
        $stmt = mysqli_prepare($conn, $chat_query);
        mysqli_stmt_bind_param($stmt, "iiis", $chat_id, $studio['OwnerID'], $client_id, $message);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // Log bot response
    if ($response) {
        $chat_id = rand(1, 1000000); // New unique ID for response
        $chat_query = "INSERT INTO chatlog (ChatID, OwnerID, ClientID, Timestamp, Content) VALUES (?, ?, ?, NOW(), ?)";
        $stmt = mysqli_prepare($conn, $chat_query);
        mysqli_stmt_bind_param($stmt, "iiis", $chat_id, $studio['OwnerID'], $client_id, $response);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode(['response' => $response]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title><?php echo htmlspecialchars($studio['StudioName']); ?> - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <!-- Leaflet for map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5rYsZQ2oE8=" crossorigin="" />
    <style>
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --bg-dark: #141414;
            --bg-card: rgba(30, 30, 30, 0.8);
            --bg-card-hover: rgba(40, 40, 40, 0.9);
            --text-primary: #ffffff;
            --text-secondary: #cccccc;
            --border-color: #333333;
            --shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
            --radius: 8px;
            --transition: all 0.3s ease;
        }

        #branding img {
            width: 180px;
            display: block;
        }

        .hero {
            position: relative;
            width: 100%;
            height: 60vh;
            background: linear-gradient(rgba(255, 255, 255, 0.06), rgba(0, 0, 0, 0.35)), url('<?php echo $studio['StudioImgBase64']; ?>') no-repeat center center;
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.15);
            z-index: 1;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 100px;
            background: linear-gradient(to top, var(--bg-dark), transparent);
        }

        .hero-content {
            text-align: center;
            color: #ffffff;
            z-index: 2;
            padding: 20px;
        }

        .hero-content h1 {
            font-size: 48px;
            margin: 0 0 15px;
            font-weight: 700;
            letter-spacing: 1px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
            color: #ffffff;
        }

        .hero-content p {
            font-size: 20px;
            margin: 0;
            color: #f7f7f7;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.25);
            opacity: 1;
        }

        .profile-section {
            padding: 80px 24px;
            background: #1a1a1a;
            color: #fff;
            min-height: 80vh;
        }

        .profile-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
            align-items: flex-start;
        }

        .profile-details {
            flex: 1 1 auto;
            min-height: 0;
            min-width: 420px;
            background: var(--bg-card);
            padding: 40px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 1px solid var(--border-color);
            position: sticky;
            top: 24px;
            max-height: calc(100vh - 140px);
            overflow-y: auto;
            z-index: 2;
        }

        .profile-details:hover {
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.3);
            background: var(--bg-card-hover);
        }

        .profile-details h2 {
            font-size: 34px;
            margin: 0 0 20px;
            color: var(--primary-color);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        .profile-details p {
            font-size: 18px;
            color: var(--text-secondary);
            margin: 0 0 14px;
            line-height: 1.9;
        }

        .services-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .services-list li {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 10px;
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }

        .services-list li:hover {
            background: rgba(50, 50, 50, 0.3);
            transform: translateX(5px);
        }

        .services-list li span {
            color: var(--text-secondary);
            font-weight: bold;
        }

        .contact-info {
            flex: 1 1 auto;
            min-height: 0;
            min-width: 300px;
            background: var(--bg-card);
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 1px solid var(--border-color);
            height: fit-content;
        }

        .contact-info:hover {
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.3);
            background: var(--bg-card-hover);
        }

        .contact-info h2 {
            font-size: 28px;
            margin: 0 0 20px;
            color: var(--primary-color);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        .contact-info p {
            font-size: 16px;
            color: var(--text-secondary);
            margin: 0 0 10px;
            display: flex;
            align-items: center;
        }

        .contact-info p i {
            margin-right: 15px;
            color: var(--primary-color);
            font-size: 18px;
            width: 20px;
            text-align: center;
        }

        .book-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 28px;
            background-color: var(--primary-color);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 0.5px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            margin-top: 25px;
            transition: var(--transition);
        }

        .book-button i {
            margin-right: 8px;
        }

        .book-button:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
        }

        /* Messenger Button */
        .messenger-button {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow);
            cursor: pointer;
            z-index: 100;
            transition: var(--transition);
        }

        .messenger-button i {
            font-size: 24px;
        }

        .messenger-button:hover {
            background-color: var(--primary-hover);
            transform: scale(1.1);
        }

       

        .messenger-popup {
            position: fixed;
            bottom: 100px;
            right: 30px;
            width: 320px;
            height: auto;
            max-height: calc(100vh - 40px);
            background-color: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            z-index: 99;
            display: none;
            flex-direction: column;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .messenger-header {
            padding: 15px;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .messenger-header h3 {
            margin: 0;
            font-size: 16px;
        }

        .messenger-close {
            cursor: pointer;
            font-size: 18px;
        }

        .messenger-body {
            flex: 1 1 auto;
            min-height: 0;
            padding: 15px;
            overflow-y: auto;
            background: #1a1a1a;
        }

        .messenger-footer {
            padding: 10px;
            background: #2a2a2a;
            display: flex;
        }

        .messenger-footer input {
            flex: 1 1 auto;
            min-height: 0;
            padding: 10px;
            border: none;
            border-radius: 4px 0 0 4px;
            background: #333;
            color: white;
        }

        .messenger-footer button {
            padding: 10px 15px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
        }

        /* Gallery Section */
        .gallery-section {
            padding: 40px 20px;
            background: #1a1a1a;
        }

        .gallery-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .gallery-heading {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 30px;
            font-size: 32px;
            font-weight: 600;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .gallery-item {
            position: relative;
            overflow: hidden;
            border-radius: var(--radius);
            height: 200px;
            cursor: pointer;
            transition: var(--transition);
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .gallery-item:hover img {
            transform: scale(1.05);
        }

        .gallery-item::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.7), transparent);
            opacity: 0;
            transition: var(--transition);
        }

        .gallery-item:hover::after {
            opacity: 1;
        }

        .profile-item {
            position: relative;
        }

        .profile-link {
            display: flex;
            align-items: center;
            color: #fff;
            text-decoration: none;
            transition: color 0.3s;
        }

        .profile-link i {
            margin-right: 5px;
            font-size: 18px;
        }

        .profile-link:hover {
            color: #e50914;
        }

        .profile-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: rgba(0, 0, 0, 0.9);
            border-radius: 5px;
            padding: 15px;
            min-width: 200px;
            z-index: 100;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
        }

        .profile-dropdown.show {
            display: block;
        }

        .profile-info p {
            margin: 0 0 10px;
            color: #ccc;
            font-size: 14px;
        }

        .profile-info p strong {
            color: #fff;
        }

        .logout-button {
            display: block;
            width: 100%;
            padding: 8px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            text-align: center;
        }

        .logout-button:hover {
            background-color: #f40612;
        }

        /* Chat Styles */
        .chat-button {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background-color: #e50914;
            color: #fff;
            border-radius: 50%;
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .chat-button i {
            font-size: 28px;
        }

        .chat-button:hover {
            background-color: #f40612;
        }

        /* Permanent tooltip label */
        .chat-tooltip {
            position: fixed;
            bottom: 112px;
            /* 80px button + 32px gap */
            right: 24px;
            background: rgba(0, 0, 0, 0.85);
            color: #fff;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 14px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
            z-index: 1000;
            pointer-events: none;
        }

        .chat-window {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 300px;
            height: auto;
            max-height: calc(100vh - 10px);
            background: #2a2a2a;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
            z-index: 1000;
            flex-direction: column;
        }

        .chat-window.show {
            display: flex;
        }

        .chat-header {
            background: #e50914;
            color: #fff;
            padding: 10px;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-header h3 {
            margin: 0;
            font-size: 16px;
        }

        .chat-close {
            cursor: pointer;
            font-size: 16px;
        }

        .chat-body {
            flex: 1 1 auto;
            min-height: 0;
            padding: 10px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
            background: #1a1a1a;
            color: #ccc;
        }

        .chat-message {
            margin-bottom: 10px;
        }

        .chat-message.user {
            text-align: right;
        }

        .chat-message.user span {
            background: #e50914;
            padding: 8px;
            border-radius: 8px;
            display: inline-block;
            max-width: 80%;
        }

        .chat-message.bot span {
            background: #333;
            padding: 8px;
            border-radius: 8px;
            display: inline-block;
            max-width: 80%;
        }

        .chat-footer {
            padding: 10px;
            background: #2a2a2a;
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        .chat-footer form {
            display: flex;
        }

        .chat-footer input {
            flex: 1;
            padding: 8px;
            border: none;
            border-radius: 4px 0 0 4px;
            background: #333;
            color: #fff;
        }

        .chat-footer button {
            padding: 8px 12px;
            background: #e50914;
            color: #fff;
            border: none;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
        }

        .chat-footer button:hover {
            background: #f40612;
        }

        @media (max-width: 768px) {
            .hero {
                height: 40vh;
            }

            .hero-content h1 {
                font-size: 24px;
            }

            .hero-content p {
                font-size: 14px;
            }

            .profile-section {
                padding: 20px 10px;
            }

            .profile-container {
                flex-direction: column;
                gap: 20px;
            }

            .profile-details h2,
            .contact-info h2 {
                font-size: 24px;
            }

            .profile-details p,
            .contact-info p {
                font-size: 14px;
            }

            .services-list li {
                font-size: 12px;
            }

            .book-button {
                padding: 8px 16px;
                font-size: 14px;
            }

            .profile-link i {
                font-size: 16px;
            }

            .profile-dropdown {
                min-width: 180px;
                padding: 10px;
            }

            .profile-info p {
                font-size: 12px;
            }

            .logout-button {
                padding: 6px;
                font-size: 12px;
            }

            .chat-window {
                width: 90%;
                height: 300px;
                right: 5%;
                bottom: 60px;
            }

            .chat-button {
                bottom: 10px;
                right: 10px;
            }
        }
    /* Browse-style map and sidebar */
    .map-container { display: grid; grid-template-columns: 380px 1fr; gap: 20px; align-items: start; }
    .map-sidebar { background: rgba(15,15,15,0.98); border: 1px solid #333; border-radius: 12px; padding: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.4); }
    .map-search { display: flex; gap: 10px; margin-bottom: 10px; }
    .map-search input { flex: 1; padding: 10px 12px; border-radius: 8px; border: 1px solid #444; background: #111; color: #fff; }
    .map-search button { padding: 10px 12px; border-radius: 8px; background: #e50914; color: #fff; border: none; cursor: pointer; }
    .map-search button:hover { background: #f40612; }
    .studios-list { max-height: 540px; overflow-y: auto; border-top: 1px solid #333; padding-top: 10px; }
    .studio-item { border: 1px solid #333; border-radius: 10px; padding: 10px; margin-bottom: 10px; background: rgba(20,20,20,0.95); cursor: pointer; }
    .studio-item.selected { outline: 2px solid #e50914; }
    /* Map markers */
    .custom-marker { 
        width: 36px; 
        height: 36px; 
        background: linear-gradient(135deg, #e50914, #f40612); 
        border-radius: 50%; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        color: white; 
        font-size: 16px; 
        font-weight: 600;
        box-shadow: 0 8px 24px rgba(0,0,0,0.4);
        border: 3px solid white;
        transition: all 0.2s ease;
    }
    .custom-marker.selected { 
        transform: scale(1.25); 
        z-index: 1000; 
        box-shadow: 0 12px 32px rgba(0,0,0,0.6);
    }
    .studio-item-content { display: flex; gap: 12px; align-items: center; }
    .studio-item-image img { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid #444; }
    .studio-item-image .letter-avatar { width: 80px; height: 80px; border-radius: 8px; background: #1a1a1a; color: #fff; display: none; align-items: center; justify-content: center; font-weight: 600; font-size: 24px; border: 1px solid #333; }
    .studio-item-info { flex: 1; }
    .studio-item-name { margin: 0 0 4px; font-size: 18px; color: #fff; }
    .studio-item-location, .studio-item-rating { font-size: 13px; color: #b3b3b3; display: flex; align-items: center; gap: 6px; }
    #map { height: 540px; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.4); border: 1px solid #333; }

    .studio-details { background: rgba(15,15,15,0.98); border-radius: 12px; padding: 20px; margin-top: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.4); border: 1px solid #333; display: none; }
    .studio-details.active { display: block; }
    .studio-details-header { display: flex; justify-content: space-between; align-items: center; }
    .studio-details-title { margin: 0; color: #fff; }
    .studio-details-description { color: #b3b3b3; }
    .studio-details-rating { color: #e50914; font-weight: 600; }
    .tabs { margin-top: 16px; }
    .tabs-list { display: flex; gap: 10px; margin-bottom: 12px; }
    .tab { background: #111; border: 1px solid #333; color: #fff; border-radius: 8px; padding: 8px 12px; cursor: pointer; }
    .tab.active { background: #e50914; border-color: #e50914; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .service-item { display: flex; justify-content: space-between; border: 1px solid #333; border-radius: 10px; padding: 10px; margin-bottom: 8px; background: rgba(20,20,20,0.95); }
    .service-price { color: #3b82f6; font-weight: 600; }
    .feedback-item { border: 1px solid #333; border-radius: 10px; padding: 10px; margin-bottom: 8px; background: rgba(20,20,20,0.95); }
    .feedback-header { display: flex; justify-content: space-between; }
    .feedback-author { color: #fff; }
    .feedback-rating { color: #e50914; }
    .location-info { display: flex; gap: 10px; align-items: flex-start; color: #b3b3b3; }
    .book-now { background: #e50914; color: #fff; border: none; padding: 10px 14px; border-radius: 8px; cursor: pointer; }
    .book-now.disabled { background: #444; color: #ccc; cursor: not-allowed; }

    .custom-marker-wrapper .custom-marker { background: #e50914; color: #fff; width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.4); }
    .user-marker-wrapper .user-marker { background: #3b82f6; color: #fff; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.4); }
    /* Thumb + letter avatar placeholders */
    .studio-thumb-wrap { width: 64px; height: 64px; margin-right: 12px; position: relative; flex-shrink: 0; }
    .studio-thumb { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; border: 1px solid #444; display: block; background: #111; }
    .letter-avatar { width: 64px; height: 64px; border-radius: 8px; background: #1a1a1a; color: #fff; display: none; align-items: center; justify-content: center; font-weight: 600; font-size: 24px; border: 1px solid #333; }
    .studio-details-header .left { display: flex; align-items: center; }
    /* Gallery */
    .studio-gallery { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
    .studio-gallery img { width: 96px; height: 72px; object-fit: cover; border-radius: 6px; border: 1px solid #444; background: #111; }
    .gallery-empty { color: #aaa; font-size: 0.95rem; }
    .gallery-title { margin-top: 12px; margin-bottom: 6px; color: #fff; font-size: 1rem; font-weight: 600; }
    /* --- Design refinements --- */
    .studio-item { transition: border-color 0.15s ease, background 0.15s ease; }
    .studio-item:hover { border-color: #444; }
    .studio-item.selected { outline: 2px solid #e50914; background: linear-gradient(180deg, rgba(229, 9, 20, 0.08), rgba(229, 9, 20, 0.02)); }

    .studio-details-header { gap: 16px; padding-bottom: 12px; border-bottom: 1px solid #333; margin-bottom: 12px; }
    .studio-details-header .left { gap: 12px; }
    .studio-details-title { font-size: 22px; line-height: 1.2; }
    .studio-details-description { margin-left: 12px; padding-left: 12px; border-left: 1px solid #333; }

    .tab { border-radius: 999px; padding: 8px 16px; transition: all 0.15s ease; }
    .tab:hover { border-color: #555; }
    .tab.active { box-shadow: 0 6px 20px rgba(229, 9, 20, 0.3); }

    .service-item { align-items: center; padding: 12px; margin-bottom: 10px; transition: border-color 0.15s ease, transform 0.15s ease; }
    .service-item:hover { border-color: #444; transform: translateY(-1px); }
    .service-price { font-weight: 700; background: rgba(59, 130, 246, 0.15); border: 1px solid #3b82f6; padding: 6px 10px; border-radius: 999px; }
    /* Leaflet popup styling to match dark theme */
    .leaflet-popup-content-wrapper { background: #111; color: #fff; border: 1px solid #333; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.4); }
    .leaflet-popup-tip { background: #111; }
    .studio-popup { min-width: 240px; }
    .studio-popup .popup-title { font-weight: 600; margin-bottom: 6px; color: #000000ff; }
    .studio-popup .popup-location { font-size: 13px; color: #fe0202ff; display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
    .studio-popup .popup-book { background: #e50914; color: #fff; border: none; padding: 8px 12px; border-radius: 8px; cursor: pointer; }
    .studio-popup .popup-book.disabled { background: #444; color: #ccc; cursor: not-allowed; }
    .studio-popup .popup-book:hover { background: #f40612; }
    </style>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
</head>

<body class="header-collapse">
    <div id="site-content">
        <header class="site-header">
            <?php include '../../shared/components/navbar.php'; ?>
        </header>
        <div class="hero">
            <div class="hero-content">
                <h1><?php echo htmlspecialchars($studio['StudioName']); ?></h1>
                <p><?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
            </div>
        </div>      
        <main class="main-content">
            <!-- Studios Map placed directly below hero -->
            <section class="gallery-section" style="padding-top:20px;">
                <div class="gallery-container">
                    <h2 class="gallery-heading">Studios Map</h2>
                    <div class="map-container">
                        <div class="map-sidebar">
                            <div class="map-search">
                                <input type="text" id="search-input" placeholder="Search studios...">
                                <button id="find-nearby-btn">
                                    <i class="fa fa-location-arrow"></i> Find Studios Near Me
                                </button>
                            </div>
                            <div class="studios-list" id="studios-list">
                                <div style="text-align: center; color: #aaa; padding: 20px;">
                                    Select a studio to view details
                                </div>
                            </div>
                        </div>
                        <div id="map" style="height:500px;"></div>
                    </div>
                    <div class="studio-details" id="studio-details"></div>
                </div>
            </section>

            <!-- Contact Information placed below map -->
            <section class="profile-section" style="padding-top:0;">
                <div class="profile-container">
                    <div class="contact-info" style="flex:1;">
                        <h2>Contact Information</h2>
                        <p><i class="fa fa-user"></i> <?php echo htmlspecialchars($studio['OwnerName'] ?? ($owner['Name'] ?? 'Owner')); ?></p>
                        <p><i class="fa fa-envelope"></i> <?php echo htmlspecialchars($studio['OwnerEmail'] ?? ($owner['Email'] ?? 'N/A')); ?></p>
                        <p><i class="fa fa-phone"></i> <?php echo htmlspecialchars($studio['OwnerPhone'] ?? ($owner['Phone'] ?? 'N/A')); ?></p>
                        <p><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
                        <!-- Hover Chat Widget -->
                        <div class="chat-hover" style="margin-top:12px; position:relative; display:inline-block;">
                            <button type="button" class="chat-button" title="Chat with Studio" aria-label="Chat with Studio"><i class="fa fa-comments"></i></button>
                            <div class="chat-window" id="chatWindow">
                                <div class="chat-header">
                                    <h3>Chat with Owner</h3>
                                    <div class="chat-close" id="chatClose">&times;</div>
                                </div>
                                <div class="chat-body" id="chatMessages"><div style="color:#aaa;text-align:center;">Loading chat...</div></div>
                                <div class="chat-footer">
                                    <form id="chatForm">
                                        <input type="text" id="chatInput" placeholder="Type your message..." required />
                                        <button type="submit">Send</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <!-- Removed duplicate Services Offered profile-section per requirement -->

            

        </main>
        <?php include '../../shared/components/footer.php'; ?>

        <!-- Hover chat widget implemented above in contact section -->

    </div>
    <script src="../../shared/assets/js/jquery-1.11.1.min.js"></script>
    <script src="../../shared/assets/js/plugins.js"></script>
    <script src="../../shared/assets/js/app.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        function confirmLogout() {
            if (window.confirm("Are you sure you want to log out?")) {
                window.location.href = "logout.php";
            }
        }

        function handleBookClick(studioId, isAuthenticated) {
            if (!isAuthenticated) {
                alert("Please log in or register to book a studio.");
                return;
            }
            window.location.href = `../../booking/php/booking.php?studio_id=${studioId}`;
        }

        // Chat UI disabled on this page; floating chat button navigates to messaging chat list.

        // Owner studios map & sidebar interactions (ported from browse.php)
        (function() {
            const studiosData = <?php echo $studios_json; ?>;
            let map;
            const markers = {};
            let userMarker = null;
            let userLocation = null;
            let selectedStudio = null;
            const defaultCenter = [10.2333, 123.0833];
            const studios = Array.isArray(studiosData) ? studiosData : [];
            const Lref = window.L;
            const initialStudioId = <?php echo (int)($fallback_studio_id ?: $studio_id); ?>; // prioritize URL studio_id, fallback to server-selected studio

            if (!Lref) {
                console.error('Leaflet not loaded');
                const mapDiv = document.getElementById('map');
                if (mapDiv) mapDiv.innerHTML = "<p style='color:red;text-align:center;'>Map failed to load.</p>";
                return;
            }

            function formatRatingHtml(ratingVal) {
                if (!ratingVal || ratingVal === 'Not rated') {
                    return "<span class='no-rating'>Not rated</span>";
                }
                const val = Number.parseFloat(ratingVal);
                if (!Number.isFinite(val) || val <= 0) {
                    return "<span class='no-rating'>Not rated</span>";
                }
                const full = Math.floor(val);
                const half = (val - full) >= 0.5 ? 1 : 0;
                const empty = Math.max(0, 5 - full - half);
                let html = '';
                for (let i = 0; i < full; i++) html += "<i class='fa fa-star'></i>";
                if (half) html += "<i class='fa fa-star-half-o'></i>";
                for (let i = 0; i < empty; i++) html += "<i class='fa fa-star-o'></i>";
                html += ` <span class='rating-number'>${val.toFixed(1)}</span>`;
                return html;
            }

            try {
                initMap();
                populateStudiosList(studios);
                document.getElementById('find-nearby-btn').addEventListener('click', getUserLocation);
                document.getElementById('search-input').addEventListener('input', handleSearch);
            } catch (e) {
                console.error('Initialization failed:', e);
                const mapDiv = document.getElementById('map');
                if (mapDiv) mapDiv.innerHTML = "<p style='color:red;text-align:center;'>Error loading map.</p>";
            }

            function initMap() {
                const mapDiv = document.getElementById('map');
                if (!mapDiv || !mapDiv.offsetHeight) return;
                let center = defaultCenter;
                const first = studios[0];
                if (first && first.Latitude && first.Longitude) {
                    center = [Number.parseFloat(first.Latitude), Number.parseFloat(first.Longitude)];
                }
                map = Lref.map('map').setView(center, 13);
                Lref.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                    maxZoom: 19,
                }).addTo(map);
                addStudioMarkers();
                setTimeout(() => map.invalidateSize(), 100);
            }

            function addStudioMarkers() {
                studios.forEach((studio) => {
                    let lat = studio.Latitude;
                    let lng = studio.Longitude;
                    if (!lat || !lng) {
                        lat = defaultCenter[0];
                        lng = defaultCenter[1];
                    }
                    const latNum = Number.parseFloat(lat);
                    const lngNum = Number.parseFloat(lng);
                    if (isNaN(latNum) || isNaN(lngNum)) return;
                    const studioIcon = Lref.divIcon({
                        className: 'custom-marker-wrapper',
                        html: `<div class="custom-marker"><i class="fa fa-map-marker"></i></div>`,
                        iconSize: [30, 30],
                        iconAnchor: [15, 30],
                    });
                    const marker = Lref.marker([latNum, lngNum], { icon: studioIcon }).addTo(map);
                    const hasServicesPopup = !!(studio.services && studio.services.length > 0);
                    const popupHtml = `
                        <div class='studio-popup'>
                            <div class='popup-title'>${studio.StudioName}</div>
                            <div class='popup-location'><i class='fa fa-map-marker'></i><span>${studio.Loc_Desc || 'Location not specified'}</span></div>
                            ${hasServicesPopup 
                                ? `<button class='popup-book' onclick=\"window.location.href='../../booking/php/booking.php?studio_id=${studio.StudioID}'\">Book Now</button>` 
                                : `<button class='popup-book disabled' disabled>Coming Soon</button>`}
                        </div>`;
                    marker.bindPopup(popupHtml, { closeButton: true, autoClose: true });
                    markers[studio.StudioID] = marker;
                    marker.on('click', () => selectStudio(studio));
                });
                let initialStudio = null;
                if (initialStudioId) {
                    initialStudio = studios.find(s => String(s.StudioID) === String(initialStudioId)) || null;
                }
                if (!initialStudio) {
                    initialStudio = studios[0] || null;
                }
                if (initialStudio) {
                    selectStudio(initialStudio);
                    let centerLat = defaultCenter[0];
                    let centerLng = defaultCenter[1];
                    if (initialStudio.Latitude && initialStudio.Longitude) {
                        centerLat = Number.parseFloat(initialStudio.Latitude);
                        centerLng = Number.parseFloat(initialStudio.Longitude);
                    }
                    map.setView([centerLat, centerLng], 13);
                }
            }

            function populateStudiosList(studiosList) {
                const listContainer = document.getElementById('studios-list');
                if (!listContainer) return;
                listContainer.innerHTML = '';
                if (studiosList.length === 0) {
                    listContainer.innerHTML = `<div style="text-align:center;color:#aaa;padding:20px;">No studios found.</div>`;
                    return;
                }
                studiosList.forEach((studio) => {
                    const studioElement = document.createElement('div');
                    studioElement.className = 'studio-item';
                    studioElement.setAttribute('data-studio-id', studio.StudioID);
                    const hasImg = !!studio.StudioImgBase64;
                    const imgSrc = hasImg ? studio.StudioImgBase64 : '';
                    const distanceHtml = '';
                    const initial = (studio.StudioName || 'S').charAt(0).toUpperCase();
                    const imgHtml = hasImg ? `<img src="${imgSrc}" alt="${studio.StudioName}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />` : `<img style="display:none;" alt="" />`;
                    const avatarHtml = `<div class="letter-avatar" style="${hasImg ? 'display:none;' : 'display:flex;'}">${initial}</div>`;
                    const ratingInner = formatRatingHtml(studio.AverageRating);
                    studioElement.innerHTML = `
                        <div class="studio-item-content">
                            <div class="studio-item-image">
                                ${imgHtml}
                                ${avatarHtml}
                            </div>
                            <div class="studio-item-info">
                                <h3 class="studio-item-name">${studio.StudioName}</h3>
                                <div class="studio-item-location"><i class="fa fa-map-marker"></i><span>${studio.Loc_Desc || 'Location not specified'}</span></div>
                                <div class="studio-item-rating">${ratingInner}</div>
                                ${distanceHtml}
                            </div>
                        </div>`;
                    studioElement.addEventListener('click', () => selectStudio(studio));
                    listContainer.appendChild(studioElement);
                });
            }

            function selectStudio(studio) {
                const prevSelected = selectedStudio;
                selectedStudio = studio;
                const studioItems = document.querySelectorAll('.studio-item');
                studioItems.forEach((item) => {
                    item.classList.remove('selected');
                    if (item.getAttribute('data-studio-id') == studio.StudioID) item.classList.add('selected');
                });
                // Toggle marker highlight class
                if (prevSelected && markers[prevSelected.StudioID] && markers[prevSelected.StudioID]._icon) {
                    const prevEl = markers[prevSelected.StudioID]._icon.querySelector('.custom-marker');
                    if (prevEl) prevEl.classList.remove('selected');
                }
                const marker = markers[studio.StudioID];
                if (marker && marker._icon) {
                    const el = marker._icon.querySelector('.custom-marker');
                    if (el) el.classList.add('selected');
                }
                if (marker && marker.openPopup) marker.openPopup();
                // Center map on selected studio
                const lat = studio.Latitude ? Number.parseFloat(studio.Latitude) : null;
                const lng = studio.Longitude ? Number.parseFloat(studio.Longitude) : null;
                if (lat && lng) {
                    map.setView([lat, lng], Math.max(map.getZoom() || 13, 13));
                }
                const detailsContainer = document.getElementById('studio-details');
                if (!detailsContainer) return;
                detailsContainer.classList.add('active');
                const hasServices = !!(studio.services && studio.services.length > 0);
                let servicesHtml = hasServices ? '' : '<p>Coming Soon</p>';
                if (hasServices) {
                    servicesHtml = studio.services.map((service) => `
                        <div class='service-item'>
                            <div class='service-info'>
                                <h4>${service.ServiceType}</h4>
                                <p>${service.Description}</p>
                            </div>
                            <div class='service-price'>₱${Number.parseFloat(service.Price).toLocaleString()}</div>
                        </div>`).join('');
                }
                let feedbackHtml = "<p class='no-feedback'>No reviews available yet.</p>";
                if (studio.feedback && studio.feedback.length > 0) {
                    feedbackHtml = studio.feedback.map((fb) => `
                        <div class='feedback-item'>
                            <div class='feedback-header'>
                                <div class='feedback-author'>${fb.Name || 'Anonymous'}</div>
                                <div class='feedback-rating'><i class='fa fa-star'></i> ${fb.Rating || 'N/A'}</div>
                            </div>
                            <div class='feedback-comment'>${fb.Comment || ''}</div>
                        </div>`).join('');
                }
                // If no image is provided, prefer showing a letter avatar over a default image
                const imgSrc = studio.StudioImgBase64 || '';
                const initial = (studio.StudioName || 'S').charAt(0).toUpperCase();
                const thumbHtml = imgSrc ? `<img class='studio-thumb' src='${imgSrc}' alt='${studio.StudioName}' onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">` : '';
                const avatarHtml = `<div class='letter-avatar' style='${imgSrc ? 'display:none;' : 'display:flex;'}'>${initial}</div>`;
                let galleryHtml = `<div class='gallery-empty'>No images yet.</div>`;
                if (studio.gallery && studio.gallery.length > 0) {
                    galleryHtml = `<div class='studio-gallery'>${studio.gallery.map((src) => `<img src='${src}' alt='${studio.StudioName}' onerror=\"this.onerror=null;this.src='../../shared/assets/images/default_studio.jpg';\">`).join('')}</div>`;
                }
                detailsContainer.innerHTML = `
                    <div class='studio-details-header'>
                        <div class='left'>
                            <div class='studio-thumb-wrap'>${thumbHtml}${avatarHtml}</div>
                            <h2 class='studio-details-title'>${studio.StudioName}</h2>
                            <p class='studio-details-description'>${studio.Loc_Desc || 'No description available.'}</p>
                        </div>
                        <div class='studio-details-rating'>${formatRatingHtml(studio.AverageRating)}</div>
                    </div>
                        <div class='tabs'>
                            <div class='tabs-list'>
                                <button class='tab active' data-tab='tab-services'>Services</button>
                                <button class='tab' data-tab='tab-reviews'>Reviews</button>
                                <button class='tab' data-tab='tab-gallery'>Gallery</button>
                            </div>
                            <div class='tab-content active' id='tab-services'>${servicesHtml}</div>
                            <div class='tab-content' id='tab-reviews'>${feedbackHtml}</div>
                            <div class='tab-content' id='tab-gallery'>${galleryHtml}</div>
                        </div>
                    <div class='studio-details-footer'>
                        ${hasServices 
                            ? `<button class='book-now' onclick=\"window.location.href='../../booking/php/booking.php?studio_id=${studio.StudioID}'\">Book Now</button>` 
                            : `<button class='book-now disabled' disabled>Coming Soon</button>`}
                    </div>`;

                const tabs = detailsContainer.querySelectorAll('.tab');
                const contents = detailsContainer.querySelectorAll('.tab-content');
                tabs.forEach((tab) => {
                    tab.addEventListener('click', () => {
                        tabs.forEach(t => t.classList.remove('active'));
                        contents.forEach(c => c.classList.remove('active'));
                        tab.classList.add('active');
                        const target = tab.getAttribute('data-tab');
                        const content = detailsContainer.querySelector(`#${target}`);
                        if (content) content.classList.add('active');
                    });
                });
            }

            function handleSearch() {
                const q = document.getElementById('search-input').value.toLowerCase().trim();
                if (!q) { populateStudiosList(studios); return; }
                const filtered = studios.filter((studio) =>
                    studio.StudioName.toLowerCase().includes(q) ||
                    (studio.Loc_Desc && studio.Loc_Desc.toLowerCase().includes(q)) ||
                    (studio.services && studio.services.some(s => s.ServiceType.toLowerCase().includes(q) || s.Description.toLowerCase().includes(q)))
                );
                populateStudiosList(filtered);
            }

            function getUserLocation() {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition((position) => {
                        userLocation = { lat: position.coords.latitude, lng: position.coords.longitude };
                        map.setView([userLocation.lat, userLocation.lng], 13);
                        if (userMarker) { userMarker.setLatLng([userLocation.lat, userLocation.lng]); }
                        else {
                            const userIcon = Lref.divIcon({
                                className: 'user-marker-wrapper',
                                html: `<div class='user-marker'><i class='fa fa-circle'></i></div>`,
                                iconSize: [30, 30], iconAnchor: [15, 15],
                            });
                            userMarker = Lref.marker([userLocation.lat, userLocation.lng], { icon: userIcon }).addTo(map);
                            userMarker.bindPopup('Your location').openPopup();
                        }
                    });
                }
            }
        })();

        // Hover Chat Widget logic
        (function() {
            const ownerId = <?php echo (int)$owner_id; ?>;
            const studioId = <?php echo (int)$studio_id; ?>;
            const isAuthenticated = <?php echo $is_authenticated ? 'true' : 'false'; ?>;
            const clientId = <?php echo (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'client') ? (int)$_SESSION['user_id'] : 0; ?>;
            const chatHover = document.querySelector('.chat-hover');
            const chatButton = document.querySelector('.chat-button');
            const chatWindow = document.getElementById('chatWindow');
            const chatClose = document.getElementById('chatClose');
            const chatMessages = document.getElementById('chatMessages');
            const chatForm = document.getElementById('chatForm');
            const chatInput = document.getElementById('chatInput');

            function updateChatMaxHeight() {
                if (!chatWindow) return;
                let anchor = document.querySelector('.gallery-section');
                if (!anchor) anchor = document.querySelector('.gallery-grid');
                if (!anchor) anchor = document.getElementById('tab-gallery');
                if (!anchor) anchor = document.querySelector('.studio-gallery');
                if (!anchor) { chatWindow.style.maxHeight = 'calc(100vh - 40px)'; return; }
                const rect = anchor.getBoundingClientRect();
                const bottomOffset = 20;
                const safetyMargin = 60;
                const viewportCap = Math.floor(window.innerHeight * 0.6);
                const available = Math.max(0, window.innerHeight - rect.top - bottomOffset - safetyMargin);
                const h = Math.max(200, Math.min(available, viewportCap));
                chatWindow.style.maxHeight = h + 'px';
            }

            function openChat() {
                if (!chatWindow) return;
                updateChatMaxHeight();
                chatWindow.classList.add('show');
                if (!isAuthenticated) {
                    chatMessages.innerHTML = "<div style='color:#f99;text-align:center;'>Please sign in to chat.</div>";
                    return;
                }
                fetchChat();
            }
            function closeChat() {
                if (chatWindow) chatWindow.classList.remove('show');
            }
            function fetchChat() {
                if (!ownerId || !chatMessages) return;
                const url = `../../messaging/php/fetch_chat.php?owner_id=${ownerId}${studioId ? `&studio_id=${studioId}` : ''}`;
                fetch(url)
                    .then(r => r.json())
                    .then(data => {
                        if (!data.success) {
                            chatMessages.innerHTML = `<div style='color:#f99;text-align:center;'>${data.error || 'Failed to load chat.'}</div>`;
                            return;
                        }
                        renderMessages(data.messages);
                    })
                    .catch(() => { chatMessages.innerHTML = "<div style='color:#f99;text-align:center;'>Failed to load chat.</div>"; });
            }
            function renderMessages(messages) {
                chatMessages.innerHTML = '';
                if (!messages || messages.length === 0) {
                    chatMessages.innerHTML = "<div style='color:#aaa;text-align:center;'>No messages yet.</div>";
                    return;
                }
                messages.forEach(m => {
                    const div = document.createElement('div');
                    const sender = (m.Sender_Type || '').toLowerCase();
                    div.className = 'chat-message ' + (sender === 'client' ? 'user' : 'bot');
                    div.innerHTML = `<span>${m.Content}</span>`;
                    chatMessages.appendChild(div);
                });
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
            if (chatButton) {
                chatButton.addEventListener('click', function() {
                    if (!chatWindow) return;
                    const isOpen = chatWindow.classList.contains('show');
                    if (isOpen) { closeChat(); } else { openChat(); }
                });
            }
            window.addEventListener('resize', updateChatMaxHeight);
            window.addEventListener('scroll', updateChatMaxHeight, { passive: true });
            if (chatClose) {
                chatClose.addEventListener('click', closeChat);
            }
            if (chatForm) {
                chatForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const content = chatInput.value.trim();
                    if (!content) return;
                    if (!isAuthenticated) { alert('Please sign in to chat.'); return; }
                    const form = new FormData();
                    form.append('owner_id', ownerId);
                    if (clientId) { form.append('client_id', clientId); }
                    form.append('content', content);
                    if (studioId) form.append('studio_id', studioId);
                    fetch('../../messaging/php/send_message.php', { method: 'POST', body: form })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) { chatInput.value = ''; fetchChat(); }
                            else { alert(data.error || 'Failed to send'); }
                        })
                        .catch(() => alert('Failed to send'));
                });
            }
        })();
    </script>
</body>

</html>
