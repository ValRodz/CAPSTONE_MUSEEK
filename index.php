<?php
session_start();
// Role-based redirects: owners and admins should land on their dashboards
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'owner') {
        header('Location: owners/php/dashboard.php');
        exit();
    }
    if ($_SESSION['user_type'] === 'admin') {
        header('Location: admin/php/dashboard.php');
        exit();
    }
}
include 'shared/config/db.php';
include 'shared/config/path_config.php';

// Normalize image source: handle absolute URLs, data URIs, and site-root paths
function resolveImgSrc($raw) {
    $val = is_string($raw) ? trim($raw) : '';
    if ($val === '') return '';
    if (preg_match('/^data:/i', $val)) return $val;
    if (preg_match('/^https?:\/\//i', $val)) return $val;
    return str_starts_with($val, '/') ? $val : ('/' . $val);
}

$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']);

if ($is_authenticated) {
    $client_query = "SELECT Name, Email, Phone FROM clients WHERE ClientID = ?";
    $stmt = mysqli_prepare($conn, $client_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $client_result = mysqli_stmt_get_result($stmt);
    $client = mysqli_fetch_assoc($client_result) ?: [
        'Name' => 'Unknown',
        'Email' => 'N/A',
        'ContactNumber' => 'N/A'
    ];
    mysqli_stmt_close($stmt);
    error_log("Client data: " . print_r($client, true));
} else {
    $client = [
        'Name' => 'Guest',
        'Email' => 'N/A',
        'ContactNumber' => 'N/A'
    ];
    error_log("Guest user accessing browse.php");
}

$studios_query = "SELECT StudioID, StudioName, Loc_Desc, StudioImg, OwnerID, Time_IN, Time_OUT FROM studios WHERE approved_by_admin IS NOT NULL";
error_log("Executing query: $studios_query");
$studios_result = mysqli_query($conn, $studios_query);

if (!$studios_result) {
    error_log("Query failed: " . mysqli_error($conn));
    die("Query failed: " . mysqli_error($conn));
}

$row_count = mysqli_num_rows($studios_result);
error_log("Studios fetched: $row_count");

$studios = [];
while ($row = mysqli_fetch_assoc($studios_result)) {
    // StudioImg is now stored as an image path in SQL
    if (!empty($row['StudioImg'])) {
        $row['StudioImgBase64'] = resolveImgSrc($row['StudioImg']);
    } else {
        // Use first letter of studio name as avatar when no image is provided
        $row['StudioImgBase64'] = '';
    }

    $studio_id = $row['StudioID'];
    $services_query = "SELECT se.ServiceType, se.Description, se.Price FROM studio_services ss LEFT JOIN services se ON ss.ServiceID = se.ServiceID WHERE ss.StudioID = ?";
    $stmt = mysqli_prepare($conn, $services_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $services_result = mysqli_stmt_get_result($stmt);

    $services_row_count = mysqli_num_rows($services_result);
    error_log("Services fetched for StudioID $studio_id: $services_row_count");

    $services = [];
    while ($service_row = mysqli_fetch_assoc($services_result)) {
        $services[] = $service_row;
    }
    $row['services'] = $services;

    // Compute min price among services
    $row['min_price'] = null;
    foreach ($services as $svc) {
        if (isset($svc['Price'])) {
            $p = floatval($svc['Price']);
            if ($row['min_price'] === null || $p < $row['min_price']) {
                $row['min_price'] = $p;
            }
        }
    }

    // Compute average rating for this studio
    $rating_query = "SELECT AVG(f.Rating) AS avg_rating FROM feedback f JOIN bookings b ON f.BookingID = b.BookingID WHERE b.StudioID = ?";
    $stmt_rating = mysqli_prepare($conn, $rating_query);
    mysqli_stmt_bind_param($stmt_rating, "i", $studio_id);
    mysqli_stmt_execute($stmt_rating);
    $rating_result = mysqli_stmt_get_result($stmt_rating);
    $rating_row = mysqli_fetch_assoc($rating_result);
    $row['avg_rating'] = $rating_row && $rating_row['avg_rating'] !== null ? floatval($rating_row['avg_rating']) : null;
    mysqli_stmt_close($stmt_rating);

    $studios[] = $row;
    mysqli_stmt_close($stmt);
}

mysqli_free_result($studios_result);

// Note: Do not inject test studios; respect approval-only visibility

// Include all approved studios, even those without services (they'll show "Coming Soon")

error_log("Studios before carousel: " . print_r($studios, true));
$studios_per_slide = 2;
$studio_slides = array_chunk($studios, $studios_per_slide);

// Build enriched studios data for Map tab (coordinates, services, ratings, feedback)
$map_studios_enriched = [];
// Try preferred query (only studios with coordinates and approved)
$map_query = "SELECT s.StudioID, s.StudioName, s.Loc_Desc, s.StudioImg, s.Latitude, s.Longitude, s.OwnerID, s.Time_IN, s.Time_OUT
              FROM studios s
              WHERE s.Latitude IS NOT NULL AND s.Longitude IS NOT NULL AND s.approved_by_admin IS NOT NULL
              ORDER BY s.StudioName";
$map_result = mysqli_query($conn, $map_query);
if (!$map_result) {
    // Column may not exist or query may fail; log and fallback to all studios
    error_log("index.php map query failed: " . mysqli_error($conn));
}
if (!$map_result || mysqli_num_rows($map_result) === 0) {
    // Fallback: include all studios even if coordinates missing
    $map_query = "SELECT s.StudioID, s.StudioName, s.Loc_Desc, s.StudioImg, s.Latitude, s.Longitude, s.OwnerID, s.Time_IN, s.Time_OUT
                  FROM studios s
                  ORDER BY s.StudioName";
    $map_result = mysqli_query($conn, $map_query);
}
if ($map_result) {
    while ($row = mysqli_fetch_assoc($map_result)) {
        // Normalize coordinates for JSON
        $row['Latitude'] = ($row['Latitude'] !== null && $row['Latitude'] !== '') ? (float)$row['Latitude'] : null;
        $row['Longitude'] = ($row['Longitude'] !== null && $row['Longitude'] !== '') ? (float)$row['Longitude'] : null;

        // StudioImg now stored as path; resolve to site-root path for rendering
        if (!empty($row['StudioImg'])) {
            $row['StudioImgBase64'] = resolveImgSrc($row['StudioImg']);
        } else {
            // Use first letter of studio name as avatar when no image is provided
            $row['StudioImgBase64'] = '';
        }

        // Average rating per owner
        $owner_id_local = (int)$row['OwnerID'];
        $rating_query = "SELECT AVG(f.Rating) as AverageRating
                         FROM feedback f
                         WHERE f.OwnerID = ?";
        $stmt = mysqli_prepare($conn, $rating_query);
        mysqli_stmt_bind_param($stmt, "i", $owner_id_local);
        mysqli_stmt_execute($stmt);
        $rating_result = mysqli_stmt_get_result($stmt);
        $rating_row = mysqli_fetch_assoc($rating_result);
        $row['AverageRating'] = ($rating_row && $rating_row['AverageRating'] !== null)
            ? number_format((float)$rating_row['AverageRating'], 1)
            : 'Not rated';
        mysqli_stmt_close($stmt);

        // Services for studio
        $studio_id_local = (int)$row['StudioID'];
        $services_query = "SELECT se.ServiceType, se.Description, se.Price 
                           FROM studio_services ss 
                           LEFT JOIN services se ON ss.ServiceID = se.ServiceID 
                           WHERE ss.StudioID = ?";
        $stmt = mysqli_prepare($conn, $services_query);
        mysqli_stmt_bind_param($stmt, "i", $studio_id_local);
        mysqli_stmt_execute($stmt);
        $services_result = mysqli_stmt_get_result($stmt);
        $services = [];
        while ($service_row = mysqli_fetch_assoc($services_result)) {
            $services[] = $service_row;
        }
        mysqli_stmt_close($stmt);
        $row['services'] = $services;

        // Recent feedback (by owner)
        $feedback_query = "SELECT f.Rating, f.Comment, c.Name
                           FROM feedback f
                           LEFT JOIN clients c ON f.ClientID = c.ClientID
                           WHERE f.OwnerID = ? AND f.Rating IS NOT NULL
                           ORDER BY f.FeedbackID DESC
                           LIMIT 5";
        $stmt = mysqli_prepare($conn, $feedback_query);
        mysqli_stmt_bind_param($stmt, "i", $owner_id_local);
        mysqli_stmt_execute($stmt);
        $feedback_result = mysqli_stmt_get_result($stmt);
        $feedback = [];
        while ($feedback_row = mysqli_fetch_assoc($feedback_result)) {
            $feedback[] = $feedback_row;
        }
        mysqli_stmt_close($stmt);
        $row['feedback'] = $feedback;

        $map_studios_enriched[] = $row;
    }
    mysqli_free_result($map_result);
}

$map_studios_json = json_encode(
    $map_studios_enriched,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
);
// Only fall back to empty array if json_encode itself failed
if ($map_studios_json === false || $map_studios_json === null) {
    error_log("index.php map_studios_json encoding error: " . json_last_error_msg());
    $map_studios_json = "[]";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Home - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <style>
        /* Netflix-style Global Styles */
        body {
            background: #141414;
            color: #fff;
            font-family: 'Source Sans Pro', Arial, sans-serif;
            margin: 0;
            padding: 0;
        }

        #branding img {
            width: 180px;
            display: block;
        }

        /* Hero Section with Search */
        .hero {
            position: relative;
            width: 100%;
            height: 40vh;
            background: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.08)), url('<?php echo getDummyPath('slide-1.jpg'); ?>');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0;
        }

        .hero-content {
            text-align: center;
            max-width: 800px;
            padding: 0 20px;
            z-index: 2;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.8);
            color: rgba(229, 9, 20, 0.9);
        }

        .hero-subtitle {
            font-size: 1.5rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.8);
        }

        /* Search Section */
        .search-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 30px;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-top: 2rem;
        }

        .search-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 20px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            color: #333333;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .form-group input,
        .form-group select {
            padding: 12px 16px;
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            background: #ffffff;
            color: #333333;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #e50914;
            box-shadow: 0 0 0 3px rgba(229, 9, 20, 0.1), 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .form-group input::placeholder {
            color: rgba(0, 0, 0, 0.5);
            font-weight: 400;
        }

        .search-btn {
            padding: 12px 24px;
            background: #e50914;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 8px rgba(229, 9, 20, 0.3);
            height: fit-content;
        }

        .search-btn:hover {
            background: #f40612;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.4);
        }

        .search-btn i {
            margin-right: 6px;
        }

        /* Studios Grid Section */
        .studios-section {
            background: #141414;
            padding: 60px 0;
            min-height: 100vh;
        }

        .section-title {
            color: #ffffff;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 40px;
            text-align: center;
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, #e50914, #f40612);
            border-radius: 2px;
        }

        .studios-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            padding: 0 20px;
        }

        .studio-card {
            background: linear-gradient(145deg, #1f1f1f 0%, #2a2a2a 100%);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            cursor: pointer;
        }

        .studio-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(229, 9, 20, 0.1) 0%, transparent 50%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .studio-card:hover::before {
            opacity: 1;
        }

        .studio-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(229, 9, 20, 0.3);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }

        .view-toggle {
            display: flex;
            gap: 10px;
        }

        .toggle-btn {
            padding: 8px 16px;
            background: #333;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .toggle-btn.active,
        .toggle-btn:hover {
            background: #e50914;
        }

        .studio-image {
            position: relative;
            height: 250px;
            overflow: hidden;
        }

        .studio-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }

        .studio-card:hover .studio-image img {
            transform: scale(1.05);
        }

        .studio-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
            display: flex;
            align-items: flex-end;
            padding: 20px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .studio-card:hover .studio-overlay {
            opacity: 1;
        }

        /* Favorites toggle button */
        .favorite-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(0, 0, 0, 0.5);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 3;
        }

        .favorite-btn.active {
            background: rgba(229, 9, 20, 0.9);
            border-color: rgba(229, 9, 20, 0.7);
        }

        .favorite-btn i {
            font-size: 18px;
        }

        .favorite-btn:hover {
            transform: scale(1.05);
        }

        .quick-actions {
            display: flex;
            gap: 10px;
        }

        .quick-btn {
            padding: 8px 12px;
            background: rgba(229, 9, 20, 0.9);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .quick-btn:hover {
            background: #e50914;
            transform: scale(1.05);
        }

        .studio-info {
            padding: 25px;
        }

        .studio-name {
            color: #ffffff;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .studio-location {
            color: #b3b3b3;
            font-size: 1rem;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .studio-location i {
            color: #e50914;
        }

        .studio-services {
            margin-bottom: 20px;
        }

        .services-title {
            font-size: 0.9rem;
            color: #ccc;
            margin: 0 0 10px 0;
            font-weight: 500;
        }

        .services-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .service-tag {
            background: rgba(229, 9, 20, 0.2);
            color: #ffffff;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            border: 1px solid rgba(229, 9, 20, 0.3);
            transition: all 0.3s ease;
        }

        .service-tag:hover {
            background: rgba(229, 9, 20, 0.3);
            border-color: rgba(229, 9, 20, 0.5);
        }

        .service-tag.featured {
            background: #e50914;
            border-color: #e50914;
        }

        .studio-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .action-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .action-btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e50914 0%, #b8070f 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(229, 9, 20, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #f40612 0%, #d1080e 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(229, 9, 20, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .btn-message {
            background: #0066cc;
            color: #fff;
            padding: 10px 14px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: auto;
            width: auto;
        }

        .btn-message:hover {
            background: #0052a3;
        }

        .btn-tertiary {
            background: rgba(229, 9, 20, 0.1);
            color: #e50914;
            border: 1px solid rgba(229, 9, 20, 0.3);
        }

        .btn-tertiary:hover {
            background: rgba(229, 9, 20, 0.2);
            border-color: rgba(229, 9, 20, 0.5);
            transform: translateY(-2px);
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

        /* Responsive Design */
        @media (max-width: 1200px) {
            .studios-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 24px;
            }

            .search-form {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .hero-title {
                font-size: 3.5rem;
            }
        }

        @media (max-width: 768px) {
            .hero {
                padding: 60px 0 40px;
            }

            .hero-title {
                font-size: 2.8rem;
            }

            .hero-subtitle {
                font-size: 1.2rem;
            }

            .search-section {
                padding: 30px 20px;
            }

            .studios-grid {
                grid-template-columns: 1fr;
                gap: 20px;
                padding: 0 15px;
            }

            .section-title {
                font-size: 2rem;
            }

            .studio-image {
                height: 200px;
            }

            .studio-actions {
                flex-direction: column;
                gap: 10px;
            }

            .action-btn {
                width: 100%;
            }
            
            .section-actions {
                flex-wrap: wrap;
                gap: 10px;
                justify-content: center;
            }
            
            .search-container input {
                width: 150px !important;
            }
            .search-container input:focus {
                width: 180px !important;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 2.2rem;
            }

            .hero-subtitle {
                font-size: 1rem;
            }

            .search-section {
                padding: 20px 15px;
            }

            .section-title {
                font-size: 1.8rem;
            }

            .studio-info {
                padding: 20px;
            }

            .studio-name {
                font-size: 1.3rem;
            }
        }

        /* Loading and Animation Styles */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
            color: #ffffff;
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* No Studios Message */
        .no-studios {
            text-align: center;
            padding: 60px 20px;
            color: #b3b3b3;
            grid-column: 1 / -1;
        }

        .no-studios i {
            font-size: 4rem;
            color: #e50914;
            margin-bottom: 20px;
        }

        .no-studios h3 {
            font-size: 1.8rem;
            color: #ffffff;
            margin-bottom: 10px;
        }

        .no-studios p {
            font-size: 1.1rem;
            opacity: 0.8;
        }

        /* Service Tags Enhanced */
        .services-title {
            color: #ffffff;
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .service-tag.featured {
            background: linear-gradient(135deg, #e50914 0%, #b8070f 100%);
            color: white;
            border-color: #e50914;
        }

        .service-tag small {
            display: block;
            font-size: 0.7rem;
            opacity: 0.8;
            margin-top: 2px;
        }

        /* Message Button Specific */
        .btn-message {
            background: rgba(0, 123, 255, 0.2);
            color: #007bff;
            border: 1px solid rgba(0, 123, 255, 0.3);
        }

        .btn-message:hover {
            background: rgba(0, 123, 255, 0.3);
            border-color: rgba(0, 123, 255, 0.5);
            transform: translateY(-2px);
        }

        /* Filter and Sort Styles */
        .content-layout {
            display: flex;
            align-items: flex-start;
            gap: 24px;
            flex-wrap: wrap;
        }
        .studios-section .container {
            max-width: 100%;
            width: 100%;
        }
        .studios-pane {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center; /* center the studios container */
            max-width: 1400px;
            margin: 0 auto;
        }
        .studios-pane .section-header {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto 40px;
        }
        .filters-title {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: #fff;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 12px;
        }
        .filter-group label {
            color: #ccc;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: #2a2a2a;
            color: #fff;
        }
        .filter-help {
            font-size: 0.75rem;
            color: #999;
            margin-top: 6px;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        @media (max-width: 992px) {
            .content-layout { flex-direction: column; }
            .studios-pane { align-items: stretch; }
        }

        /* Floating Filters Drawer scoped to studios section */
        .studios-section { position: relative; }
        .filters-drawer-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 1000;
            height: 100%;
            overflow-x: hidden; /* Prevent horizontal scroll when panel is offscreen */
        }
        .filters-drawer-overlay.active { opacity: 1; pointer-events: auto; }
        .filters-drawer-panel {
            position: absolute;
            top: 0;
            right: -420px;
            width: 400px;
            max-width: 92vw;
            height: 100%;
            background: #1f1f1f;
            color: #fff;
            border-left: 1px solid var(--border);
            box-shadow: -12px 0 32px rgba(0,0,0,0.4);
            padding: 20px;
            overflow-y: auto;
            overflow-x: hidden; /* Avoid horizontal scroll within the drawer */
            box-sizing: border-box; /* Include padding within width to avoid overflow */
            transition: right 0.25s ease;
        }
        .filters-drawer-overlay.active .filters-drawer-panel { right: 0; }
        .drawer-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .drawer-close { background: none; border: none; color: #fff; font-size: 20px; cursor: pointer; }
        .section-actions { display: flex; gap: 12px; align-items: center; }
        .filters-open-btn { border: 1px solid var(--border); }
        @media (max-width: 768px) {
            .filters-drawer-panel { width: 100%; right: -100%; }
        }

        /* Search Container Styles */
        .search-container {
            position: relative;
            display: inline-block;
        }
        
        .search-container input {
            padding: 8px 12px 8px 36px !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            border-radius: 6px !important;
            background: #2a2a2a !important;
            color: #fff !important;
            font-size: 0.9rem !important;
            width: 200px !important;
            transition: all 0.3s ease !important;
        }
        
        .search-container input:focus {
            outline: none !important;
            border-color: #e50914 !important;
            box-shadow: 0 0 0 3px rgba(229, 9, 20, 0.1) !important;
            width: 250px !important;
        }
        
        .search-container input::placeholder {
            color: #999 !important;
        }
        
        .search-container .fa-search {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 14px;
            pointer-events: none;
        }
        
        @media (max-width: 768px) {
            .search-container input {
                width: 150px !important;
            }
            .search-container input:focus {
                width: 180px !important;
            }
        }
        
        @media (max-width: 480px) {
            .search-container input {
                width: 120px !important;
                font-size: 0.8rem !important;
            }
            .search-container input:focus {
                width: 150px !important;
            }
        }

        /* Filters Floating Action Button (FAB) */
        .filters-fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: #e50914;
            color: #fff;
            border: none;
            box-shadow: 0 8px 24px rgba(0,0,0,0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 999; /* Below overlay (1000), above content */
        }
        .filters-fab:hover { background: #f40612; }
        .filters-fab i { font-size: 20px; }

        #clientChatModalOverlay {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
        }

        #clientChatModalOverlay.active {
            display: flex;
        }

        #clientChatModal {
            background: #222;
            color: #fff;
            border-radius: 12px;
            width: 350px;
            max-width: 95vw;
            box-shadow: 0 2px 16px rgba(0, 0, 0, 0.4);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            animation: modalIn 0.2s;
        }

        @keyframes modalIn {
            from {
                transform: scale(0.95);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        #clientChatModalHeader {
            background: #e50914;
            padding: 14px 16px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        #clientChatModalClose {
            cursor: pointer;
            font-size: 20px;
            color: #fff;
            background: none;
            border: none;
        }

        #clientChatModalBody {
            flex: 1;
            padding: 14px 16px;
            overflow-y: auto;
            background: #181818;
        }

        #clientChatModalInputArea {
            display: flex;
            border-top: 1px solid #333;
        }

        #clientChatModalInput {
            flex: 1;
            padding: 10px;
            border: none;
            background: #222;
            color: #fff;
        }

        #clientChatModalSend {
            background: #e50914;
            color: #fff;
            border: none;
            padding: 0 18px;
            cursor: pointer;
        }

        .client-chat-message {
            margin-bottom: 10px;
        }

        .client-chat-message.client {
            text-align: right;
        }

        .client-chat-message.owner {
            text-align: left;
        }

        .client-chat-message .bubble {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 16px;
            max-width: 80%;
        }

        .client-chat-message.client .bubble {
            background: #e50914;
            color: #fff;
        }

        .client-chat-message.owner .bubble {
            background: #2196f3;
            color: #fff;
        }

        /* Modern look refinements */
        :root {
            --accent: #e50914;
            --accent2: #f40612;
            --card: #1f1f1f;
            --text: #ffffff;
            --muted: #b3b3b3;
            --border: rgba(255, 255, 255, 0.12);
        }

        .hero-title {
            letter-spacing: 0.5px;
            background: linear-gradient(90deg, #ffffff 0%, #ffe4e4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
        }

        .search-section {
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
            border: 1px solid var(--border);
        }

        .studios-grid {
            perspective: 1000px;
        }

        .studio-card {
            background: linear-gradient(145deg, var(--card) 0%, #242424 100%) padding-box,
                        linear-gradient(90deg, rgba(229, 9, 20, 0.45), rgba(255, 255, 255, 0.12)) border-box;
            border: 1px solid transparent;
            animation: slideUp 360ms ease-out both;
        }

        .studio-card:hover {
            transform: translateY(-8px) scale(1.02) rotateX(0.5deg);
        }

        .favorite-btn.active::after {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 50%;
            background: radial-gradient(closest-side, rgba(229, 9, 20, 0.35), transparent 70%);
        }

        .toggle-btn {
            border: 1px solid var(--border);
        }
        .toggle-btn.active {
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.25);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive enhancements */
        *, *::before, *::after { box-sizing: border-box; }

        .container { padding: 0 24px; }
        @media (max-width: 768px) { .container { padding: 0 16px; } }
        @media (max-width: 480px) { .container { padding: 0 12px; } }

        .hero { min-height: 60vh; }
        @media (max-width: 992px) { .hero { height: 56vh; } }
        @media (max-width: 768px) { .hero { height: auto; padding: 40px 0; } }

        .hero-title { font-size: clamp(2rem, 5vw, 3.5rem); }
        .hero-subtitle { font-size: clamp(1rem, 2.5vw, 1.5rem); }

        .search-form { gap: 16px; }
        @media (max-width: 992px) { .search-form { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 768px) {
            .search-form { grid-template-columns: 1fr; }
            .search-btn { width: 100%; }
        }

        @media (max-width: 992px) {
            .studios-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 768px) {
            .studio-image { height: auto; aspect-ratio: 16/10; }
        }

        .action-btn { font-size: clamp(0.85rem, 1.8vw, 0.9rem); }
        .btn-message { width: auto; height: auto; }

        .section-title { font-size: clamp(1.8rem, 4.5vw, 2.5rem); }

        @media (max-width: 480px) {
            #clientChatModal { width: 100%; max-height: 85vh; border-radius: 0; }
            #clientChatModalHeader { position: sticky; top: 0; }
        }
        /* Map Tab Pane styles */
        .map-tab-pane { display: none; width: 100%; margin-top: 20px; }
        /* Inline Map layout */
        .map-container { display: grid; grid-template-columns: 360px 1fr; gap: 16px; align-items: stretch; }
        @media (max-width: 992px) { .map-container { grid-template-columns: 1fr; } }
        .map-sidebar { background: var(--card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; max-height: 680px; }
        .map-sidebar .sidebar-header { padding: 12px 14px; border-bottom: 1px solid var(--border); display: flex; gap: 8px; align-items: center; flex-shrink: 0; }
        .map-sidebar .sidebar-header input { flex: 1; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); background: #222; color: #fff; }
        .map-sidebar .sidebar-header button { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); background: #333; color: #fff; cursor: pointer; }
        .studios-list { list-style: none; margin: 0; padding: 0; overflow-y: auto; flex: 1; min-height: 0; }
        .studio-item { display: grid; grid-template-columns: 64px 1fr auto; gap: 12px; padding: 12px 14px; border-bottom: 1px solid var(--border); align-items: center; cursor: pointer; }
        .studio-item:hover { background: #202020; }
        .studio-item-image { width: 64px; height: 64px; border-radius: 8px; overflow: hidden; background: #2b2b2f; display: flex; align-items: center; justify-content: center; }
        .studio-item-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .studio-item .letter-avatar { width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; background: #2b2b2f; color: #fff; font-size: 1.4rem; font-weight: 700; border-radius: 8px; }
        .studio-item-name { font-weight: 700; }
        .studio-item-meta { color: var(--muted); font-size: 0.9rem; }
        #homeMap { width: 100%; height: 680px; border-radius: 12px; border: 1px solid var(--border); }
        .studio-details { margin-top: 16px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 14px; }
        .studio-details h3 { margin: 0 0 8px 0; }
        .studio-details .details-meta { color: var(--muted); margin-bottom: 8px; }
        .studio-details .services { display: flex; flex-wrap: wrap; gap: 8px; }
        .studio-details .service-pill { padding: 6px 10px; border-radius: 999px; background: #292929; border: 1px solid var(--border); font-size: 0.9rem; }
        .studio-details .actions { display: flex; gap: 8px; margin-top: 10px; }
        .studio-details .actions .action-btn { flex: none; }
        .view-mode-toggle { display: flex; gap: 8px; align-items: center; }
        .view-mode-toggle .toggle-btn { display: inline-flex; align-items: center; gap: 8px; }
    </style>
    <!--[if lt IE 9]>
    <![endif]-->

    <!-- Messaging Modal HTML -->
</head>

<body class="header-collapse">
    <div id="clientChatModalOverlay" class="chat-modal-overlay">
        <div id="clientChatModal" class="chat-modal">
            <div id="clientChatModalHeader" class="chat-modal-header">
                <span id="clientChatModalStudioName">Message</span>
                <button id="clientChatModalClose" class="chat-modal-close">&times;</button>
            </div>
            <div id="clientChatModalBody" class="chat-modal-body">
                <div style="color:#aaa; text-align:center;">Loading...</div>
            </div>
            <form id="clientChatModalInputArea" class="chat-modal-input-area">
                <input type="text" id="clientChatModalInput" placeholder="Type your message..." required />
                <button type="submit" id="clientChatModalSend">Send</button>
            </form>
        </div>
    </div>
    <div id="site-content">
        <?php include 'shared/components/navbar.php'; ?>
        <div class="hero">
            <div class="hero-content">
                <h1 class="hero-title">MuSeek</h1>
                <p class="hero-subtitle">Find and book the perfect recording studio for your next project</p>

                <!-- Filters available via floating drawer -->
            </div>
        </div>
        <main class="main-content">
            <div class="studios-section">
                <!-- Filters Drawer scoped within studios-section -->
                <div id="filtersDrawer" class="filters-drawer-overlay" aria-hidden="true">
                    <div class="filters-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="filtersDrawerTitle">
                        <div class="drawer-header">
                            <h3 class="filters-title" id="filtersDrawerTitle">Filters</h3>
                            <button id="closeFiltersDrawer" class="drawer-close" aria-label="Close Filters"><i class="fa fa-times"></i></button>
                        </div>
                        <form id="filtersForm">
                            <div class="filter-group" id="priceRangeGroup">
                                <label>Price Range (₱)
                                    <span id="priceRangeLabel" style="margin-left:6px;color:#cfcfcf;">₱<span id="priceMinLabel">0</span>–₱<span id="priceMaxLabel">5000</span></span>
                                </label>
                                <div class="dual-range">
                                    <div id="filtersRangeFill" class="filters-range-fill"></div>
                                    <input type="range" id="filterPriceMin" min="0" max="5000" step="50" value="0" />
                                    <input type="range" id="filterPriceMax" min="0" max="5000" step="50" value="5000" />
                                    <div id="filterPriceMinBubble" class="filters-price-bubble">₱<span id="filterPriceMinBubbleValue">0</span></div>
                                    <div id="filterPriceMaxBubble" class="filters-price-bubble">₱<span id="filterPriceMaxBubbleValue">5000</span></div>
                                </div>
                                <small class="filter-help">Drag thumbs to set minimum and maximum prices</small>
                            </div>
                            <div class="filter-group">
                                <label>Rating</label>
                                <div id="ratingStars" class="rating-stars" aria-label="Minimum rating">
                                    <i data-value="1" class="fa-regular fa-star"></i>
                                    <i data-value="2" class="fa-regular fa-star"></i>
                                    <i data-value="3" class="fa-regular fa-star"></i>
                                    <i data-value="4" class="fa-regular fa-star"></i>
                                    <i data-value="5" class="fa-regular fa-star"></i>
                                </div>
                                <input type="hidden" id="filterRating" value="">
                                <small class="filter-help">Click to set minimum rating</small>
                            </div>
                            <div class="filter-group">
                                <label for="filterStartTime">Start Time (hourly)</label>
                                <select id="filterStartTime">
                                    <option value="">Any</option>
                                    <option value="00:00">12:00 AM</option>
                                    <option value="01:00">1:00 AM</option>
                                    <option value="02:00">2:00 AM</option>
                                    <option value="03:00">3:00 AM</option>
                                    <option value="04:00">4:00 AM</option>
                                    <option value="05:00">5:00 AM</option>
                                    <option value="06:00">6:00 AM</option>
                                    <option value="07:00">7:00 AM</option>
                                    <option value="08:00">8:00 AM</option>
                                    <option value="09:00">9:00 AM</option>
                                    <option value="10:00">10:00 AM</option>
                                    <option value="11:00">11:00 AM</option>
                                    <option value="12:00">12:00 PM</option>
                                    <option value="13:00">1:00 PM</option>
                                    <option value="14:00">2:00 PM</option>
                                    <option value="15:00">3:00 PM</option>
                                    <option value="16:00">4:00 PM</option>
                                    <option value="17:00">5:00 PM</option>
                                    <option value="18:00">6:00 PM</option>
                                    <option value="19:00">7:00 PM</option>
                                    <option value="20:00">8:00 PM</option>
                                    <option value="21:00">9:00 PM</option>
                                    <option value="22:00">10:00 PM</option>
                                    <option value="23:00">11:00 PM</option>
                                </select>
                                <small class="filter-help">Filters studios open at the selected hour</small>
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="search-btn"><i class="fa fa-filter"></i> Apply</button>
                                <button type="button" id="clearFilters" class="toggle-btn">Clear</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="container">
                    <div class="content-layout">
                        <div class="studios-pane">
                    <div class="section-header">
                        <h2 class="section-title">Available Studios</h2>
                        <div class="section-actions">
                            <div class="view-toggle">
                                <button class="toggle-btn active" data-filter="all"><i class="fa fa-layer-group"></i> All</button>
                                <?php if ($is_authenticated): ?>
                                <button class="toggle-btn" data-filter="favorites"><i class="fa fa-heart"></i> Favorites</button>
                                <?php endif; ?>
                            </div>
                            <div class="view-mode-toggle" style="margin-left:8px;">
                                <button id="btnViewGrid" class="toggle-btn active" type="button"><i class="fa fa-th-large"></i> Grid</button>
                                <button id="btnViewMap" class="toggle-btn" type="button"><i class="fa fa-map"></i> Map</button>
                            </div>
                            <div class="search-container" style="margin-left:8px; position: relative;">
                                <input type="text" id="mainSearchInput" placeholder="Search studios..." aria-label="Search studios" style="padding: 8px 12px 8px 36px; border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 6px; background: #2a2a2a; color: #fff; font-size: 0.9rem; width: 200px; transition: all 0.3s ease;">
                                <i class="fa fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999; font-size: 14px;"></i>
                            </div>
                            <button id="openFiltersDrawer" class="toggle-btn filters-open-btn"><i class="fa fa-filter"></i> Filters</button>
                        </div>
                    </div>

                    <div class="studios-grid" id="studiosGrid">
                        <?php if (!empty($studios)): ?>
                            <?php foreach ($studios as $studio): ?>
                                <div class="studio-card" data-studio-id="<?php echo $studio['StudioID']; ?>" data-min-price="<?php echo isset($studio['min_price']) ? htmlspecialchars($studio['min_price']) : ''; ?>" data-rating="<?php echo isset($studio['avg_rating']) ? htmlspecialchars(number_format((float)$studio['avg_rating'], 2)) : ''; ?>" data-time-in="<?php echo htmlspecialchars($studio['Time_IN'] ?? ''); ?>" data-time-out="<?php echo htmlspecialchars($studio['Time_OUT'] ?? ''); ?>">
                    <div class="studio-image">
                        <?php if ($is_authenticated): ?>
                        <button class="favorite-btn" title="Add to Favorites" aria-label="Add to Favorites" aria-pressed="false"><i class="fa-regular fa-heart"></i></button>
                        <?php endif; ?>
                        <?php if (!empty($studio['StudioImgBase64'])): ?>
                            <img src="<?php echo $studio['StudioImgBase64']; ?>"
                                alt="<?php echo htmlspecialchars($studio['StudioName']); ?>"
                                loading="lazy">
                        <?php else: ?>
                            <div class="letter-avatar">
                                <?php echo htmlspecialchars(strtoupper(substr(trim($studio['StudioName']), 0, 1))); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="studio-info">
                                        <h3 class="studio-name"><?php echo htmlspecialchars($studio['StudioName']); ?></h3>
                                        <p class="studio-location">
                                            <i class="fa fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($studio['Loc_Desc']); ?>
                                        </p>

                                        <div class="studio-services">
                                            <h4 class="services-title">Services</h4>
                                            <div class="services-list">
                                                <?php if (!empty($studio['services'])): ?>
                                                    <?php
                                                    $service_count = count($studio['services']);
                                                    $display_services = array_slice($studio['services'], 0, 4);
                                                    foreach ($display_services as $index => $service):
                                                    ?>
                                                        <span class="service-tag <?php echo $index === 0 ? 'featured' : ''; ?>">
                                                            <?php echo htmlspecialchars($service['ServiceType']); ?>
                                                            <small>₱<?php echo number_format($service['Price'], 0); ?></small>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <?php if ($service_count > 4): ?>
                                                        <span class="service-tag">+<?php echo $service_count - 4; ?> more</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="service-tag">No services listed</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="studio-actions">
                                            <?php if (!empty($studio['services']) && isset($studio['min_price']) && $studio['min_price'] !== null): ?>
                                            <button class="action-btn btn-primary"
                                                onclick="handleBookClick(<?php echo $studio['StudioID']; ?>, <?php echo $is_authenticated ? 'true' : 'false'; ?>)">
                                                <i class="fa fa-calendar"></i> Book Now
                                            </button>
                                            <?php else: ?>
                                                <button class="action-btn btn-tertiary" disabled style="cursor: not-allowed; opacity: 0.7;">
                                                    <i class="fa fa-clock"></i> Coming Soon
                                                </button>
                                            <?php endif; ?>
                                            <button class="action-btn btn-secondary"
                                                onclick="window.location.href='client/php/profile.php?owner_id=<?php echo $studio['OwnerID']; ?>&studio_id=<?php echo $studio['StudioID']; ?>'">
                                                <i class="fa fa-eye"></i> View Profile
                                            </button>
                                            <button class="action-btn btn-message"
                                                onclick="openStudioChat(<?php echo $studio['StudioID']; ?>)">
                                                <i class="fa fa-comment"></i> Chat Studio
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-studios">
                                <i class="fa fa-music"></i>
                                <h3>No Studios Available</h3>
                                <p>We're working on adding more studios to our platform. Check back soon!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Map Tab Pane -->
                    <div class="map-tab-pane" id="mapTabPane" aria-hidden="true">
                        <div class="map-container">
                            <aside class="map-sidebar">
                                <div class="sidebar-header">
                                    <input type="text" id="mapSearchInput" placeholder="Search studios..." aria-label="Search studios">
                                    <button type="button" id="findNearbyBtn" title="Find nearby"><i class="fa fa-location-crosshairs"></i> Nearby</button>
                                </div>
                                <ul id="mapStudiosList" class="studios-list"></ul>
                            </aside>
                            <div id="homeMap" role="region" aria-label="Studio map"></div>
                        </div>
                        <div id="homeMapDetails" class="studio-details" style="display:none;"></div>
                    </div>
                    
                        </div> <!-- end studios-pane -->
                    </div> <!-- end content-layout -->
                </div> <!-- end container -->
                <!-- Floating Filters FAB -->
                <button id="filtersFab" class="filters-fab" aria-label="Open Filters" title="Filters"><i class="fa fa-filter"></i></button>
        </main>
        <?php include 'shared/components/footer.php'; ?>
    </div>
    <style>
        .studio-image { position: relative; }
        .studio-image .letter-avatar {
            width: 100%;
            min-height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #2b2b2f;
            color: #fff;
            font-size: 3rem;
            font-weight: 700;
            border-radius: 10px;
        }
        @media (max-width: 768px) {
            .studio-image .letter-avatar { min-height: 180px; font-size: 2.4rem; }
        }
        /* Red round map marker */
        .leaflet-marker-icon.studio-marker-icon { background: transparent; border: none; }
        .studio-marker {
            width: 20px;
            height: 20px;
            background: #e50914;
            border-radius: 50%;
            border: 2px solid #ffffff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s ease;
        }
        .studio-marker .pin-icon {
            color: #ffffff;
            font-size: 12px;
            line-height: 1;
        }
        .studio-marker-icon:hover .studio-marker { transform: scale(1.15); }

        /* Filters: dual-range slider and rating stars */
        .filters-drawer-panel .dual-range { position: relative; height: 36px; margin-top: 6px; }
        .filters-drawer-panel .dual-range input[type=range] {
            -webkit-appearance: none; appearance: none; position: absolute; left: 0; right: 0;
            top: 12px; height: 6px; background: #1e1e1e; border-radius: 6px; outline: none;
        }
        .filters-drawer-panel .dual-range input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none; appearance: none; width: 16px; height: 16px;
            border-radius: 50%; background: #e50914; border: 2px solid #ffffff; cursor: pointer;
            position: relative; z-index: 3;
        }
        .filters-drawer-panel .dual-range input[type=range]::-moz-range-thumb {
            width: 16px; height: 16px; border-radius: 50%; background: #e50914; border: 2px solid #ffffff; cursor: pointer;
        }
        .filters-drawer-panel #filterPriceMin { z-index: 3; }
        .filters-drawer-panel #filterPriceMax { z-index: 4; }
        .filters-drawer-panel .filters-range-fill {
            position: absolute; top: 14px; height: 2px; background: #e50914; border-radius: 2px; z-index: 2;
            left: 0; width: 0;
            transition: left 0.2s ease, width 0.2s ease; /* Smooth transitions */
        }
        .filters-drawer-panel .filters-price-bubble {
            position: absolute; top: -10px; transform: translateX(-50%);
            background: #0f0f0f; color: #ffffff; border: 1px solid #2a2a2a; border-radius: 8px;
            padding: 2px 6px; font-size: 12px; white-space: nowrap; z-index: 5;
            transition: left 0.2s ease; /* Smooth transitions */
        }
        /* Debug: Add visible borders to price slider elements for troubleshooting */
        .filters-drawer-panel .dual-range.debug .filters-range-fill {
            border: 1px solid yellow;
        }
        .filters-drawer-panel .dual-range.debug .filters-price-bubble {
            border: 1px solid cyan;
        }
        .filters-drawer-panel .rating-stars i {
            font-size: 18px; color: #bbb; cursor: pointer; margin-right: 4px;
        }
        .filters-drawer-panel .rating-stars i.text-yellow-400 { color: #f5c518; }

        /* Tooltip styling for studio names */
        .leaflet-tooltip.studio-tooltip {
            background: #1f1f1f;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 6px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.35);
            padding: 6px 8px;
            font-weight: 600;
        }
        .leaflet-tooltip-top.studio-tooltip::before { border-top-color: #1f1f1f; }
        /* Balanced action buttons inside Leaflet map popups */
        .leaflet-popup-content .map-popup-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 8px;
        }
        @media (max-width: 380px) {
            .leaflet-popup-content .map-popup-actions { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 300px) {
            .leaflet-popup-content .map-popup-actions { grid-template-columns: 1fr; }
        }
        .leaflet-popup-content .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 10px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            line-height: 1.2;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            width: 100%;
        }
        .leaflet-popup-content .action-btn i { font-size: 14px; }
        .leaflet-popup-content .btn-primary { background-color: #0d6efd; color: #fff; border-color: #0d6efd; }
        .leaflet-popup-content .btn-primary:hover { background-color: #0b5ed7; border-color: #0a58ca; }
        .leaflet-popup-content .btn-secondary { background-color: #6c757d; color: #fff; border-color: #6c757d; }
        .leaflet-popup-content .btn-secondary:hover { background-color: #5c636a; border-color: #565e64; }
        .leaflet-popup-content .btn-message { background-color: #17a2b8; color: #fff; border-color: #17a2b8; }
        .leaflet-popup-content .btn-message:hover { background-color: #128da0; border-color: #0f7686; }
        </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?php echo getJSPath('plugins.js'); ?>"></script>
    <script src="<?php echo getJSPath('app.js'); ?>"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCmZ8z0iq0SgO7rVtVbQkFZ1NW7m9v1rVxA0iQ0M=" crossorigin=""></script>
    <script>
        let studioCurrentSlide = 0;
        const studioSlides = document.querySelectorAll('#studio-carousel .slide');
        const totalStudioSlides = studioSlides.length;

        function moveSlide(direction) {
            if (totalStudioSlides <= 1) return;
            studioCurrentSlide += direction;
            if (studioCurrentSlide < 0) studioCurrentSlide = totalStudioSlides - 1;
            if (studioCurrentSlide >= totalStudioSlides) studioCurrentSlide = 0;
            updateStudioCarousel();
        }

        function updateStudioCarousel() {
            const studioCarousel = document.getElementById('studio-carousel');
            studioCarousel.style.transform = `translateX(-${studioCurrentSlide * 100}%)`;
        }

        if (totalStudioSlides > 1) {
            setInterval(() => moveSlide(1), 10000);
        }

        function handleBookClick(studioId, isAuthenticated) {
            if (!isAuthenticated) {
                alert("Please log in or register to book a studio.");
                window.location.href = "auth/php/login.php";
                return;
            }
            window.location.href = `booking/php/booking.php?studio_id=${studioId}`;
        }
    </script>
    <script>
        // Inline Map Tab implementation (Leaflet)
        let homeMap = null;
        let mapInitialized = false;
        let mapMarkers = [];
        let markersById = {};
        let studioDivIcon = null; // custom red round marker icon
        const studiosDataRaw = <?php echo $map_studios_json; ?>;

        // Ensure Leaflet is loaded by trying alternate CDNs if primary fails
        function ensureLeafletLoaded(callback) {
            if (window.L) { if (callback) callback(); return; }
            const sources = [
                { css: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css', js: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js' },
                { css: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css', js: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js' }
            ];
            function injectCssOnce(href) {
                if (!href) return;
                const exists = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).some(l => (l.href||'').includes('/leaflet'));
                if (exists) return;
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                link.crossOrigin = '';
                document.head.appendChild(link);
            }
            let i = 0;
            const tryNext = () => {
                if (window.L) { if (callback) callback(); return; }
                if (i >= sources.length) { if (callback) callback(); return; }
                const src = sources[i++];
                injectCssOnce(src.css);
                const s = document.createElement('script');
                s.src = src.js;
                s.async = true;
                s.crossOrigin = '';
                s.onload = () => { if (callback) callback(); };
                s.onerror = () => { tryNext(); };
                document.head.appendChild(s);
            };
            tryNext();
        }

        function getLetter(studioName) {
            if (!studioName || typeof studioName !== 'string') return '?';
            return studioName.trim().charAt(0).toUpperCase();
        }

        function clearMarkers() {
            mapMarkers.forEach(m => homeMap && homeMap.removeLayer(m));
            mapMarkers = [];
            markersById = {};
        }

        function buildPopupContent(studio) {
            const isAuth = <?php echo $is_authenticated ? 'true' : 'false'; ?>;
            const hasServices = studio.services && studio.services.length > 0;
            const bookBtn = hasServices 
                ? `<button class=\"action-btn btn-primary\" onclick=\"handleBookClick(${studio.StudioID}, ${isAuth})\"><i class=\"fa fa-calendar\"></i> Book Studio</button>`
                : `<button class=\"action-btn btn-tertiary\" disabled style=\"cursor: not-allowed; opacity: 0.7;\"><i class=\"fa fa-clock\"></i> Coming Soon</button>`;
            const viewBtn = `<button class=\"action-btn btn-secondary\" onclick=\"window.location.href='client/php/profile.php?owner_id=${studio.OwnerID}&studio_id=${studio.StudioID}'\"><i class=\"fa fa-eye\"></i> View Profile</button>`;
            const chatBtn = `<button class=\"action-btn btn-message\" onclick=\"openStudioChat(${studio.StudioID})\"><i class=\"fa fa-comment\"></i> Chat Studio</button>`;
            const meta = `${studio.Loc_Desc || ''}`;
            return `<div style=\"min-width:240px\">` +
                `<strong>${studio.StudioName || 'Studio'}</strong><br>` +
                `<div style=\"margin:6px 0;color:#aaa\">${meta}</div>` +
                `<div class=\"map-popup-actions\">${bookBtn}${viewBtn}${chatBtn}</div>` +
            `</div>`;
        }

        function locateAndOpenMarker(studio) {
            if (!homeMap || !studio) return;
            const key = String(studio.StudioID);
            const marker = markersById[key];
            if (marker) {
                const ll = marker.getLatLng();
                homeMap.setView([ll.lat, ll.lng], Math.max(homeMap.getZoom(), 14));
                marker.openPopup();
            } else if (studio.Latitude && studio.Longitude) {
                homeMap.setView([studio.Latitude, studio.Longitude], Math.max(homeMap.getZoom(), 14));
            }
        }

        function renderStudiosList(studios) {
            const listEl = document.getElementById('mapStudiosList');
            if (!listEl) return;
            listEl.innerHTML = '';
            if (!Array.isArray(studios) || studios.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'studio-item';
                empty.style.opacity = '0.8';
                empty.textContent = 'No studios to display.';
                listEl.appendChild(empty);
                return;
            }

            studios.forEach(studio => {
                const li = document.createElement('li');
                li.className = 'studio-item';
                li.dataset.studioId = studio.StudioID;

                const imageWrap = document.createElement('div');
                imageWrap.className = 'studio-item-image';
                if (studio.StudioImgBase64) {
                    const img = document.createElement('img');
                    img.src = studio.StudioImgBase64;
                    img.alt = studio.StudioName || 'Studio';
                    imageWrap.appendChild(img);
                } else {
                    const avatar = document.createElement('div');
                    avatar.className = 'letter-avatar';
                    avatar.textContent = getLetter(studio.StudioName);
                    imageWrap.appendChild(avatar);
                }

                const content = document.createElement('div');
                const name = document.createElement('div');
                name.className = 'studio-item-name';
                name.textContent = studio.StudioName || 'Untitled Studio';
                const meta = document.createElement('div');
                meta.className = 'studio-item-meta';
                const rating = studio.AverageRating && studio.AverageRating !== 'Not rated' ? `${studio.AverageRating}★` : 'No ratings';
                meta.textContent = `${rating} • ${studio.Loc_Desc || 'Location unavailable'}`;
                content.appendChild(name);
                content.appendChild(meta);

                const chevron = document.createElement('div');
                chevron.innerHTML = '<i class="fa fa-chevron-right" style="color:#777"></i>';

                li.appendChild(imageWrap);
                li.appendChild(content);
                li.appendChild(chevron);

                li.addEventListener('click', () => {
                    selectStudio(studio);
                    locateAndOpenMarker(studio);
                });
                listEl.appendChild(li);
            });
        }

        function selectStudio(studio) {
            if (!studio) return;
            const detailsEl = document.getElementById('homeMapDetails');
            if (!detailsEl) return;
            detailsEl.style.display = '';
            const ratingText = studio.AverageRating && studio.AverageRating !== 'Not rated' ? `${studio.AverageRating}★` : 'No ratings';
            const servicesHtml = (studio.services || []).map(s => `<span class="service-pill">${s.ServiceType || 'Service'} • ₱${(s.Price!=null? Number(s.Price).toFixed(0):'N/A')}</span>`).join('');
            const feedbackHtml = (studio.feedback || []).map(f => `<div class="feedback-item" style="margin:6px 0;">${(f.Name||'Client')}: ${f.Rating!=null? (Number(f.Rating).toFixed(1)+'★'):'N/A'} — ${f.Comment||''}</div>`).join('');
            const hasServices = studio.services && studio.services.length > 0;
            const bookBtnHtml = hasServices 
                ? `<button class="action-btn btn-primary" onclick="handleBookClick(${studio.StudioID}, ${<?php echo $is_authenticated ? 'true' : 'false'; ?>})"><i class="fa fa-calendar"></i> Book Now</button>`
                : `<button class="action-btn btn-tertiary" disabled style="cursor: not-allowed; opacity: 0.7;"><i class="fa fa-clock"></i> Coming Soon</button>`;

            detailsEl.innerHTML = `
                <h3>${studio.StudioName || 'Untitled Studio'}</h3>
                <div class="details-meta">${ratingText} • ${studio.Loc_Desc || 'Location unavailable'}</div>
                <div class="services">${servicesHtml || '<span class="service-pill">No services listed</span>'}</div>
                <div class="actions">
                    ${bookBtnHtml}
                    <button class="action-btn btn-secondary" onclick="window.location.href='client/php/profile.php?owner_id=${studio.OwnerID}&studio_id=${studio.StudioID}'"><i class="fa fa-eye"></i> View Profile</button>
                    <button class="action-btn btn-message" onclick="openStudioChat(${studio.StudioID})"><i class="fa fa-comment"></i> Chat Studio</button>
                </div>
                ${feedbackHtml ? `<div style=\"margin-top:10px;\"><strong>Recent feedback</strong>${feedbackHtml}</div>` : ''}
            `;

            // Pan/zoom to marker
            if (homeMap && studio.Latitude && studio.Longitude) {
                homeMap.setView([studio.Latitude, studio.Longitude], Math.max(homeMap.getZoom(), 14));
            }
        }

        function initHomeMap() {
            if (mapInitialized) return;
            const studiosData = Array.isArray(studiosDataRaw) ? studiosDataRaw : [];
            const withCoords = studiosData.filter(s => s.Latitude != null && s.Longitude != null);
            const defaultCenter = [10.2333, 123.0833];
            // Guard: Leaflet available?
            const mapEl = document.getElementById('homeMap');
            if (!window.L || !mapEl) {
                if (mapEl) {
                    mapEl.innerHTML = '<div style="padding:14px;color:#bbb;">Map library failed to load. Please check your internet connection and try again.</div>';
                    mapEl.style.display = '';
                }
                renderStudiosList(studiosData);
                // Do not mark initialized; we want to allow retry after loading Leaflet via fallback
                return;
            }

            homeMap = L.map('homeMap');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(homeMap);

            // Define modern red round marker icon for this map
            studioDivIcon = L.divIcon({
                className: 'studio-marker-icon',
                html: '<span class="studio-marker"><i class="fa fa-map-marker pin-icon"></i></span>',
                iconSize: [24, 24],
                iconAnchor: [12, 12],
                tooltipAnchor: [12, -12]
            });

            if (withCoords.length > 0) {
                const bounds = L.latLngBounds(withCoords.map(s => [s.Latitude, s.Longitude]));
                homeMap.fitBounds(bounds.pad(0.2));
            } else {
                // Default center (Cebu area) when no coordinates are available
                homeMap.setView(defaultCenter, 13);
            }

            // Fix: ensure map sizes correctly after being shown
            setTimeout(() => { if (homeMap && homeMap.invalidateSize) homeMap.invalidateSize(true); }, 50);

            // Add markers for all studios. Use default coordinates if missing.
            clearMarkers();
            studiosData.forEach(studio => {
                let lat = studio.Latitude;
                let lng = studio.Longitude;
                if (lat == null || lng == null || Number.isNaN(Number(lat)) || Number.isNaN(Number(lng))) {
                    lat = defaultCenter[0];
                    lng = defaultCenter[1];
                }
                const marker = studioDivIcon ? L.marker([lat, lng], { icon: studioDivIcon }) : L.marker([lat, lng]);
                marker.addTo(homeMap).bindPopup(buildPopupContent(studio));
                // Hover tooltip with studio name
                marker.bindTooltip(studio.StudioName || 'Studio', {
                    direction: 'top',
                    offset: [0, -12],
                    opacity: 0.95,
                    sticky: true,
                    className: 'studio-tooltip'
                });
                marker.on('click', () => selectStudio(studio));
                mapMarkers.push(marker);
                markersById[String(studio.StudioID)] = marker;
            });

            // Render list
            renderStudiosList(studiosData);

            // Apply initial map filters to markers and list
            if (typeof applyMapFilters === 'function') {
                applyMapFilters();
            }

            // Search
            const searchInput = document.getElementById('mapSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    // Reuse unified filter logic so markers and list stay in sync
                    if (typeof applyMapFilters === 'function') applyMapFilters();
                });
            }

            // Nearby
            const nearbyBtn = document.getElementById('findNearbyBtn');
            if (nearbyBtn && navigator.geolocation) {
                nearbyBtn.addEventListener('click', () => {
                    nearbyBtn.disabled = true;
                    navigator.geolocation.getCurrentPosition((pos) => {
                        const { latitude, longitude } = pos.coords;
                        const userLatLng = L.latLng(latitude, longitude);
                        const studiosSorted = withCoords
                            .map(s => ({ s, d: userLatLng.distanceTo(L.latLng(s.Latitude, s.Longitude)) }))
                            .sort((a, b) => a.d - b.d)
                            .map(x => x.s);
                        renderStudiosList(studiosSorted);
                        homeMap.setView([latitude, longitude], Math.max(homeMap.getZoom(), 13));
                        L.marker([latitude, longitude], { title: 'You' }).addTo(homeMap);
                        nearbyBtn.disabled = false;
                    }, () => { nearbyBtn.disabled = false; alert('Location permission denied.'); });
                });
            }

            mapInitialized = true;
        }

        // Map filtering based on the same inputs as grid
        function applyMapFilters() {
            const priceMinInput = document.getElementById('filterPriceMin');
            const priceMaxInput = document.getElementById('filterPriceMax');
            const ratingInput = document.getElementById('filterRating');
            const startTimeInput = document.getElementById('filterStartTime');
            const searchInput = document.getElementById('mapSearchInput');

            function timeToMinutes(t) {
                if (!t) return null;
                const parts = String(t).split(':');
                if (parts.length < 2) return null;
                const h = parseInt(parts[0], 10);
                const m = parseInt(parts[1], 10);
                if (Number.isNaN(h) || Number.isNaN(m)) return null;
                return (h * 60) + m;
            }

            const priceMin = priceMinInput && priceMinInput.value !== '' ? parseFloat(priceMinInput.value) : null;
            const priceMax = priceMaxInput && priceMaxInput.value !== '' ? parseFloat(priceMaxInput.value) : null;
            const ratingMin = ratingInput && ratingInput.value !== '' ? parseFloat(ratingInput.value) : null;
            const startMinutes = startTimeInput && startTimeInput.value ? timeToMinutes(startTimeInput.value) : null;
            const query = searchInput ? searchInput.value.trim().toLowerCase() : '';

            // Determine Favorites mode and favorites set
            const favToggleActive = document.querySelector('.view-toggle .toggle-btn.active');
            const currentFilterMode = favToggleActive && favToggleActive.dataset.filter ? favToggleActive.dataset.filter : 'all';
            const isAuthenticated = <?php echo json_encode($is_authenticated); ?>;
            let favoritesSet = new Set();
            
            // Only check favorites for authenticated users
            if (isAuthenticated) {
                const userId = <?php echo json_encode($_SESSION['user_id'] ?? 'guest'); ?>;
                const favoritesKey = `museek:favorites:${userId}`;
                try { favoritesSet = new Set(JSON.parse(localStorage.getItem(favoritesKey) || '[]').map(String)); } catch (e) { favoritesSet = new Set(); }
            }

            const studiosData = Array.isArray(studiosDataRaw) ? studiosDataRaw : [];
            const filtered = studiosData.filter(studio => {
                // Compute min price from services
                let minPrice = null;
                if (Array.isArray(studio.services)) {
                    studio.services.forEach(s => {
                        if (s && s.Price != null) {
                            const p = parseFloat(s.Price);
                            if (!Number.isNaN(p)) {
                                if (minPrice === null || p < minPrice) minPrice = p;
                            }
                        }
                    });
                }

                // Check if studio has services (minPrice will be null if no services)
                const hasServices = minPrice !== null;

                const avgRatingStr = studio.AverageRating;
                const avgRating = (avgRatingStr && avgRatingStr !== 'Not rated') ? parseFloat(avgRatingStr) : null;
                const timeIn = studio.Time_IN || '';
                const timeOut = studio.Time_OUT || '';
                const inMinutes = timeToMinutes(timeIn);
                const outMinutes = timeToMinutes(timeOut);

                // Price filtering: if studio has no services, skip price filter. If has services, apply filter
                const priceOk = !hasServices || (
                    (priceMin === null || minPrice >= priceMin) &&
                    (priceMax === null || minPrice <= priceMax)
                );

                const ratingOk = (ratingMin === null || (avgRating !== null && avgRating >= ratingMin));
                const timeOk = (
                    startMinutes === null || (
                        inMinutes !== null && outMinutes !== null &&
                        startMinutes >= inMinutes && startMinutes <= outMinutes
                    )
                );
                const matchesFav = (currentFilterMode === 'all') || (isAuthenticated && favoritesSet.has(String(studio.StudioID)));
                const matchesQuery = !query || (String(studio.StudioName || '').toLowerCase().includes(query));

                // Include all studios (with or without services)
                return priceOk && ratingOk && timeOk && matchesFav && matchesQuery;
            });

            // Update list
            renderStudiosList(filtered);

            // Update markers if map exists
            if (homeMap) {
                clearMarkers();
                const defaultCenter = [10.2333, 123.0833];
                filtered.forEach(studio => {
                    let lat = studio.Latitude;
                    let lng = studio.Longitude;
                    if (lat == null || lng == null || Number.isNaN(Number(lat)) || Number.isNaN(Number(lng))) {
                        lat = defaultCenter[0];
                        lng = defaultCenter[1];
                    }
                    const marker = studioDivIcon ? L.marker([lat, lng], { icon: studioDivIcon }) : L.marker([lat, lng]);
                    marker.addTo(homeMap).bindPopup(buildPopupContent(studio));
                    // Hover tooltip with studio name
                    marker.bindTooltip(studio.StudioName || 'Studio', {
                        direction: 'top',
                        offset: [0, -12],
                        opacity: 0.95,
                        sticky: true,
                        className: 'studio-tooltip'
                    });
                    marker.on('click', () => selectStudio(studio));
                    mapMarkers.push(marker);
                    markersById[String(studio.StudioID)] = marker;
                });
            }
        }
    </script>
    <script>
        // Filters Drawer Toggle
        (function() {
            const drawer = document.getElementById('filtersDrawer');
            const closeBtn = document.getElementById('closeFiltersDrawer');
            const openButtons = [
                document.getElementById('openFiltersDrawer'),
                document.getElementById('filtersFab')
            ].filter(Boolean);

            const openDrawer = () => {
                if (!drawer) return;
                drawer.classList.add('active');
                drawer.setAttribute('aria-hidden', 'false');
                
                // Reinitialize price slider when drawer opens to ensure proper positioning
                setTimeout(() => {
                    if (typeof updatePriceUI === 'function') {
                        console.log('Reinitializing price slider after drawer opened');
                        updatePriceUI();
                    }
                }, 100);
            };
            const closeDrawer = () => {
                if (!drawer) return;
                drawer.classList.remove('active');
                drawer.setAttribute('aria-hidden', 'true');
            };

            openButtons.forEach(btn => btn.addEventListener('click', openDrawer));
            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);

            if (drawer) {
                drawer.addEventListener('click', (e) => {
                    if (e.target === drawer) closeDrawer();
                });
            }
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && drawer && drawer.classList.contains('active')) closeDrawer();
            });
        })();
    </script>
    
    <script>
        const isAuthenticated = <?php echo json_encode($is_authenticated); ?>;
        const userType = <?php echo json_encode($_SESSION['user_type'] ?? null); ?>;
        const clientId = <?php echo json_encode($_SESSION['user_id'] ?? null); ?>;

        const studios = <?php
                        $studio_js_data = [];
                        foreach ($studio_slides as $slide) {
                            foreach ($slide as $studio) {
                                $studio_js_data[] = [
                                    'StudioID' => $studio['StudioID'],
                                    'StudioName' => $studio['StudioName'],
                                    'OwnerID' => isset($studio['OwnerID']) ? (int)$studio['OwnerID'] : 0
                                ];
                            }
                        }
                        echo json_encode($studio_js_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        ?>;

        let selectedStudioId = '';
        let selectedOwnerId = '';
        let selectedStudioName = '';

        const chatModalOverlay = document.getElementById('clientChatModalOverlay');
        const chatModal = document.getElementById('clientChatModal');
        const chatHeaderStudio = document.getElementById('clientChatModalStudioName');
        const closeBtn = document.getElementById('clientChatModalClose');
        const chatBody = document.getElementById('clientChatModalBody');
        const chatForm = document.getElementById('clientChatModalInputArea');
        const chatInput = document.getElementById('clientChatModalInput');

        let chatInterval = null;

        function startChatPolling() {
            if (chatInterval) clearInterval(chatInterval);
            chatInterval = setInterval(fetchChat, 2000); // every 2 seconds
        }

        function stopChatPolling() {
            if (chatInterval) clearInterval(chatInterval);
        }

        window.openStudioChat = function(studioId) {
            if (!isAuthenticated || userType !== 'client') {
                alert("Please log in as a client to message studios.");
                window.location.href = "auth/php/login.php";
                return;
            }

            const studio = studios.find(s => s.StudioID == studioId);
            if (!studio || !studio.OwnerID) {
                alert("Studio or owner not found.");
                return;
            }

            // Redirect to unified chat page with owner as partner and studio context
            window.location.href = `messaging/php/chat.php?partner_id=${encodeURIComponent(studio.OwnerID)}&studio_id=${encodeURIComponent(studio.StudioID)}`;
        };

        // Redirecting to chat.php; removed legacy modal activation code

        function fetchChat() {
            if (!selectedStudioId || !selectedOwnerId) return;
            fetch(`messaging/php/fetch_chat.php?owner_id=${selectedOwnerId}&client_id=${clientId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderMessages(data.messages);
                    } else {
                        chatBody.innerHTML = `<div style='color:#f99;text-align:center;'>${data.error}</div>`;
                    }
                });
        }

        function renderMessages(messages) {
            chatBody.innerHTML = '';
            if (!messages.length) {
                chatBody.innerHTML = "<div style='color:#aaa;text-align:center;'>No messages yet.</div>";
                return;
            }
            messages.forEach(msg => {
                const who = (msg.Sender_Type && msg.Sender_Type.toLowerCase() === 'client') ? 'client' : 'owner';
                const div = document.createElement('div');
                div.className = 'client-chat-message ' + who;
                div.innerHTML = `<span class='bubble'>${msg.Content.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span><div style='font-size:10px;color:#888;'>${msg.Timestamp}</div>`;
                chatBody.appendChild(div);
            });
            scrollChatToBottom();
        }

        function scrollChatToBottom() {
            chatBody.scrollTop = chatBody.scrollHeight;
        }

        chatForm.onsubmit = function(e) {
            e.preventDefault();
            const content = chatInput.value.trim();
            console.log("Submit clicked. Content:", content);
            console.log("Selected Studio ID:", selectedStudioId);
            console.log("Selected Owner ID:", selectedOwnerId);
            console.log("Client ID:", clientId);

            if (!content || !selectedStudioId || !selectedOwnerId) {
                console.warn("Missing data - not sending.");
                return;
            }

            chatInput.value = '';

            fetch('messaging/php/send_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `content=${encodeURIComponent(content)}&owner_id=${selectedOwnerId}&client_id=${clientId}`
                })
                .then(r => r.json())
                .then(data => {
                    console.log("Server response:", data);
                    if (data.success) {
                        fetchChat();
                    } else {
                        alert(data.error || 'Failed to send message');
                    }
                })
                .catch(error => {
                    console.error("Fetch failed:", error);
                    alert("Network or server error");
                });
        };

        if (closeBtn) {
            closeBtn.onclick = function() {
                chatModalOverlay.classList.remove('active');
                stopChatPolling();
            };
        }

        chatModalOverlay.addEventListener('click', function(e) {
            if (e.target === chatModalOverlay) {
                chatModalOverlay.classList.remove('active');
                stopChatPolling();
            }
        });

        setInterval(() => {
            if (chatModalOverlay.classList.contains('active') && selectedStudioId && selectedOwnerId) {
                fetchChat();
            }
        }, 10000);
    </script>
    <script>
        (function() {
            const cards = Array.from(document.querySelectorAll('.studio-card'));
            const isAuthenticated = <?php echo json_encode($is_authenticated); ?>;

            // Initialize favorites for authenticated users only
            let favorites = new Set();
            if (isAuthenticated) {
                const userId = <?php echo json_encode($_SESSION['user_id'] ?? 'guest'); ?>;
                const favoritesKey = `museek:favorites:${userId}`;
                try { 
                    favorites = new Set(JSON.parse(localStorage.getItem(favoritesKey) || '[]').map(String)); 
                } catch (e) { 
                    favorites = new Set(); 
                }
            }

            // Initialize favorite buttons (only for authenticated users)
            if (isAuthenticated) {
                cards.forEach(card => {
                    const id = String(card.dataset.studioId);
                    const favBtn = card.querySelector('.favorite-btn');
                    if (!favBtn) return;
                    const icon = favBtn.querySelector('i');
                    const setFavState = (isFav) => {
                        favBtn.classList.toggle('active', isFav);
                        favBtn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
                        if (isFav) {
                            icon.classList.remove('fa-regular');
                            icon.classList.add('fa-solid');
                        } else {
                            icon.classList.remove('fa-solid');
                            icon.classList.add('fa-regular');
                        }
                        favBtn.title = isFav ? 'Remove from Favorites' : 'Add to Favorites';
                    };
                    setFavState(favorites.has(id));
                    favBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        if (favorites.has(id)) {
                            favorites.delete(id);
                            setFavState(false);
                        } else {
                            favorites.add(id);
                            setFavState(true);
                        }
                        localStorage.setItem(favoritesKey, JSON.stringify(Array.from(favorites)));
                        applyFilters();
                    });
                });
            }

            // View toggle (All vs Favorites)
            let currentFilter = 'all';
            const viewToggleContainer = document.querySelector('.view-toggle');
            if (viewToggleContainer) {
                viewToggleContainer.addEventListener('click', (e) => {
                    const btn = e.target.closest('.toggle-btn');
                    if (!btn) return;
                    viewToggleContainer.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    currentFilter = btn.dataset.filter || 'all';
                    applyFilters();
                });
            }

            // Filters inputs
            const filtersForm = document.getElementById('filtersForm');
            const priceMinInput = document.getElementById('filterPriceMin');
            const priceMaxInput = document.getElementById('filterPriceMax');
            const ratingInput = document.getElementById('filterRating');
            const startTimeInput = document.getElementById('filterStartTime');
            const clearFiltersBtn = document.getElementById('clearFilters');

            // Price slider UI elements
            const rangeFill = document.getElementById('filtersRangeFill');
            const minBubble = document.getElementById('filterPriceMinBubble');
            const maxBubble = document.getElementById('filterPriceMaxBubble');
            const minBubbleValue = document.getElementById('filterPriceMinBubbleValue');
            const maxBubbleValue = document.getElementById('filterPriceMaxBubbleValue');
            const priceMinLabel = document.getElementById('priceMinLabel');
            const priceMaxLabel = document.getElementById('priceMaxLabel');

            // Debug: Check if all price slider elements exist
            console.log('Price slider elements check:', {
                priceMinInput: !!priceMinInput,
                priceMaxInput: !!priceMaxInput,
                rangeFill: !!rangeFill,
                minBubble: !!minBubble,
                maxBubble: !!maxBubble,
                minBubbleValue: !!minBubbleValue,
                maxBubbleValue: !!maxBubbleValue,
                priceMinLabel: !!priceMinLabel,
                priceMaxLabel: !!priceMaxLabel
            });

            function clamp(val, min, max) { return Math.min(max, Math.max(min, val)); }
            function updatePriceUI() {
                if (!priceMinInput || !priceMaxInput) {
                    console.log('updatePriceUI: Missing price inputs');
                    return;
                }
                
                const min = parseFloat(priceMinInput.min || '0');
                const max = parseFloat(priceMaxInput.max || '5000');
                const step = parseFloat(priceMinInput.step || '1');
                let minVal = parseFloat(priceMinInput.value || String(min));
                let maxVal = parseFloat(priceMaxInput.value || String(max));
                
                console.log('updatePriceUI called with:', {
                    minInputValue: priceMinInput.value,
                    maxInputValue: priceMaxInput.value,
                    min: min,
                    max: max,
                    step: step,
                    minVal: minVal,
                    maxVal: maxVal
                });
                
                if (maxVal <= minVal + step) { 
                    maxVal = minVal + step; 
                    priceMaxInput.value = String(maxVal); 
                }
                
                minVal = clamp(minVal, min, max);
                maxVal = clamp(maxVal, min, max);
                
                const pctMin = ((minVal - min) / (max - min)) * 100;
                const pctMax = ((maxVal - min) / (max - min)) * 100;
                
                console.log('Calculated positions:', {
                    pctMin: pctMin,
                    pctMax: pctMax,
                    fillWidth: (pctMax - pctMin)
                });
                
                if (rangeFill) { 
                    rangeFill.style.left = pctMin + '%'; 
                    rangeFill.style.width = (pctMax - pctMin) + '%'; 
                    console.log('Updated rangeFill:', rangeFill.style.left, rangeFill.style.width);
                } else {
                    console.log('rangeFill element not found!');
                }
                
                if (minBubble) {
                    minBubble.style.left = pctMin + '%';
                    console.log('Updated minBubble:', minBubble.style.left);
                } else {
                    console.log('minBubble element not found!');
                }
                
                if (maxBubble) {
                    maxBubble.style.left = pctMax + '%';
                    console.log('Updated maxBubble:', maxBubble.style.left);
                } else {
                    console.log('maxBubble element not found!');
                }
                
                if (minBubbleValue) minBubbleValue.textContent = String(Math.round(minVal));
                if (maxBubbleValue) maxBubbleValue.textContent = String(Math.round(maxVal));
                if (priceMinLabel) priceMinLabel.textContent = String(Math.round(minVal));
                if (priceMaxLabel) priceMaxLabel.textContent = String(Math.round(maxVal));
                
                console.log('updatePriceUI completed');
            }

            // Debug function to enable visual debugging of price slider
            function enablePriceSliderDebug() {
                const dualRange = document.querySelector('.dual-range');
                if (dualRange) {
                    dualRange.classList.add('debug');
                    console.log('Price slider debug mode enabled - visual borders added');
                }
            }

            // Global wrapper function for price slider debugging
            window.updatePriceSliderGlobal = function() {
                if (typeof updatePriceUI === 'function') {
                    updatePriceUI();
                    console.log('Price slider updated via global function');
                } else {
                    console.log('updatePriceUI function not available');
                }
            };

            if (priceMinInput) {
                console.log('Attaching event listeners to priceMinInput');
                const onMin = () => { 
                    console.log('priceMinInput event triggered');
                    updatePriceUI(); 
                    applyFilters(); 
                };
                priceMinInput.addEventListener('input', onMin);
                priceMinInput.addEventListener('change', onMin);
            } else {
                console.log('priceMinInput not found, cannot attach event listeners');
            }
            
            if (priceMaxInput) {
                console.log('Attaching event listeners to priceMaxInput');
                const onMax = () => { 
                    console.log('priceMaxInput event triggered');
                    updatePriceUI(); 
                    applyFilters(); 
                };
                priceMaxInput.addEventListener('input', onMax);
                priceMaxInput.addEventListener('change', onMax);
            } else {
                console.log('priceMaxInput not found, cannot attach event listeners');
            }

            // Rating stars handler
            const ratingStars = document.getElementById('ratingStars');
            function setStars(n) {
                if (!ratingStars) return;
                ratingStars.querySelectorAll('i').forEach(star => {
                    const val = parseInt(star.dataset.value, 10);
                    const active = val <= n;
                    star.classList.toggle('fa-solid', active);
                    star.classList.toggle('fa-regular', !active);
                    star.classList.toggle('text-yellow-400', active);
                });
            }
            if (ratingStars) {
                ratingStars.addEventListener('click', (e) => {
                    const el = e.target.closest('i[data-value]');
                    if (!el) return;
                    const val = parseInt(el.dataset.value, 10);
                    if (!Number.isNaN(val)) {
                        if (ratingInput) ratingInput.value = String(val);
                        setStars(val);
                        applyFilters();
                    }
                });
            }

            function timeToMinutes(t) {
                if (!t) return null;
                const parts = t.split(':');
                if (parts.length < 2) return null;
                const h = parseInt(parts[0], 10);
                const m = parseInt(parts[1], 10);
                if (Number.isNaN(h) || Number.isNaN(m)) return null;
                return (h * 60) + m;
            }

            function applyFilters() {
                const priceMin = priceMinInput && priceMinInput.value !== '' ? parseFloat(priceMinInput.value) : null;
                const priceMax = priceMaxInput && priceMaxInput.value !== '' ? parseFloat(priceMaxInput.value) : null;
                const ratingMin = ratingInput && ratingInput.value !== '' ? parseFloat(ratingInput.value) : null;
                const startMinutes = startTimeInput && startTimeInput.value ? timeToMinutes(startTimeInput.value) : null;
                const mainSearchQuery = document.getElementById('mainSearchInput') ? document.getElementById('mainSearchInput').value.trim().toLowerCase() : '';

                // Enhanced debug: Log current filter values and input states
                console.log('=== Applying Filters ===');
                console.log('Filter inputs:', {
                    priceMinInput: priceMinInput ? priceMinInput.value : 'null',
                    priceMaxInput: priceMaxInput ? priceMaxInput.value : 'null',
                    ratingInput: ratingInput ? ratingInput.value : 'null',
                    mainSearchInput: document.getElementById('mainSearchInput') ? document.getElementById('mainSearchInput').value : 'null'
                });
                console.log('Parsed filter values:', {
                    priceMin: priceMin,
                    priceMax: priceMax,
                    ratingMin: ratingMin,
                    searchQuery: mainSearchQuery
                });

                cards.forEach(card => {
                    const id = String(card.dataset.studioId);
                    const minPrice = card.dataset.minPrice !== '' ? parseFloat(card.dataset.minPrice) : null;
                    const avgRating = card.dataset.rating !== '' ? parseFloat(card.dataset.rating) : null;
                    const timeIn = card.dataset.timeIn || '';
                    const timeOut = card.dataset.timeOut || '';
                    const inMinutes = timeToMinutes(timeIn);
                    const outMinutes = timeToMinutes(timeOut);

                    // Check if studio has services (minPrice will be null if no services)
                    const hasServices = minPrice !== null;

                    // Price filtering: if studio has no services, skip price filter. If has services, apply filter
                    const priceOk = !hasServices || (
                        (priceMin === null || minPrice >= priceMin) &&
                        (priceMax === null || minPrice <= priceMax)
                    );

                    const ratingOk = (ratingMin === null || (avgRating !== null && avgRating >= ratingMin));
                    const timeOk = (
                        startMinutes === null || (
                            inMinutes !== null && outMinutes !== null &&
                            startMinutes >= inMinutes && startMinutes <= outMinutes
                        )
                    );
                    const matchesFav = currentFilter === 'all' || (isAuthenticated && favorites.has(id));
                    
                    // Search functionality - search by studio name
                    const studioName = card.querySelector('.studio-name') ? card.querySelector('.studio-name').textContent.toLowerCase() : '';
                    const matchesSearch = mainSearchQuery === '' || studioName.includes(mainSearchQuery);

                    // Show all studios (with or without services)
                    card.style.display = (priceOk && ratingOk && timeOk && matchesFav && matchesSearch) ? '' : 'none';
                });

                // Keep map in sync with filters
                if (typeof applyMapFilters === 'function') {
                    applyMapFilters();
                }

                // Summary debug: Show filtering results
                const visibleCards = Array.from(cards).filter(card => card.style.display !== 'none').length;
                const totalCards = cards.length;
                console.log(`Filtering complete: ${visibleCards}/${totalCards} studios visible`);
            }

            if (filtersForm) {
                filtersForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    applyFilters();
                });
            }
            // Real-time filtering: apply on input/change
            [priceMinInput, priceMaxInput].forEach(el => { if (el) el.addEventListener('input', () => { updatePriceUI(); applyFilters(); }); });
            if (ratingInput) ratingInput.addEventListener('change', applyFilters);
            if (startTimeInput) startTimeInput.addEventListener('change', applyFilters);
            
            // Main search input event listener
            const mainSearchInput = document.getElementById('mainSearchInput');
            if (mainSearchInput) {
                mainSearchInput.addEventListener('input', applyFilters);
            }
            if (clearFiltersBtn) {
                clearFiltersBtn.addEventListener('click', () => {
                    if (priceMinInput) priceMinInput.value = priceMinInput.min || '0';
                    if (priceMaxInput) priceMaxInput.value = priceMaxInput.max || '5000';
                    updatePriceUI();
                    if (ratingInput) ratingInput.value = '';
                    setStars(0);
                    if (startTimeInput) startTimeInput.value = '';
                    if (mainSearchInput) mainSearchInput.value = '';
                    applyFilters();
                });
            }

            // Ensure initial UI reflects current values immediately
            updatePriceUI();
            applyFilters();

            // Initial filter - wait for drawer to be potentially visible
            setTimeout(() => {
                console.log('Calling initial updatePriceUI...');
                updatePriceUI();
                setStars(parseInt((ratingInput && ratingInput.value) || '0', 10) || 0);
                
                // Debug: Log price filtering values
                console.log('Price filter initialized:', {
                    min: priceMinInput ? priceMinInput.value : 'null',
                    max: priceMaxInput ? priceMaxInput.value : 'null',
                    hasPriceInputs: !!(priceMinInput && priceMaxInput)
                });
                
                // Enable debug mode for troubleshooting
                enablePriceSliderDebug();
                
                // Add global debug function for users to call
                window.debugPriceSlider = function() {
                    console.log('=== PRICE SLIDER DEBUG ===');
                    console.log('Elements:', {
                        priceMinInput: !!document.getElementById('filterPriceMin'),
                        priceMaxInput: !!document.getElementById('filterPriceMax'),
                        rangeFill: !!document.getElementById('filtersRangeFill'),
                        minBubble: !!document.getElementById('filterPriceMinBubble'),
                        maxBubble: !!document.getElementById('filterPriceMaxBubble')
                    });
                    enablePriceSliderDebug();
                    window.updatePriceSliderGlobal();
                    console.log('Debug mode enabled. Check for visual borders on price slider elements.');
                };
                
                applyFilters();
            }, 500); // Delay initialization to ensure DOM is fully ready
        })();
    </script>
    <script>
        // Grid/Map view mode toggle
        (function() {
            const btnGrid = document.getElementById('btnViewGrid');
            const btnMap = document.getElementById('btnViewMap');
            const grid = document.getElementById('studiosGrid');
            const mapPane = document.getElementById('mapTabPane');

            function setActive(btn) {
                [btnGrid, btnMap].forEach(b => { if (b) b.classList.remove('active'); });
                if (btn) btn.classList.add('active');
            }

            function showGrid() {
                if (grid) grid.style.display = '';
                if (mapPane) { mapPane.style.display = 'none'; mapPane.setAttribute('aria-hidden', 'true'); }
                setActive(btnGrid);
            }
            function showMap() {
                if (grid) grid.style.display = 'none';
                if (mapPane) { mapPane.style.display = 'block'; mapPane.setAttribute('aria-hidden', 'false'); }
                setActive(btnMap);
                // Ensure Leaflet is available, then initialize map
                ensureLeafletLoaded(() => {
                    if (typeof initHomeMap === 'function' && !mapInitialized) {
                        initHomeMap();
                    }
                    // Ensure Leaflet recalculates size when shown
                    if (window.homeMap && typeof window.homeMap.invalidateSize === 'function') {
                        setTimeout(() => window.homeMap.invalidateSize(true), 50);
                    }
                });
            }

            if (btnGrid) btnGrid.addEventListener('click', showGrid);
            if (btnMap) btnMap.addEventListener('click', showMap);

            // Default to Grid view
            showGrid();
        })();
    </script>
