<?php
session_start();
include '../../shared/config/db.php';
include '../../shared/config/path_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo "<script>
        alert('Please log in to continue.');
        window.location.href = '../../auth/php/login.php';
    </script>";
    exit;
}

// Simplified query to fetch all studios with coordinates
$studios_query = "SELECT s.StudioID, s.StudioName, s.Loc_Desc, s.StudioImg, s.Latitude, s.Longitude, s.OwnerID
                 FROM studios s
                 WHERE s.Latitude IS NOT NULL AND s.Longitude IS NOT NULL AND s.approved_by_admin IS NOT NULL
                 ORDER BY s.StudioName";
error_log("Executing query: $studios_query");
$studios_result = mysqli_query($conn, $studios_query);

if (!$studios_result) {
    error_log("Query failed: " . mysqli_error($conn));
    die("Query failed: " . mysqli_error($conn));
}

$row_count = mysqli_num_rows($studios_result);
error_log("Studios fetched: $row_count");

// If no studios with coordinates, try fetching all studios
if ($row_count == 0) {
    error_log("No studios with coordinates found, trying to fetch all studios");
    $studios_query = "SELECT s.StudioID, s.StudioName, s.Loc_Desc, s.StudioImg, s.Latitude, s.Longitude, s.OwnerID
                     FROM studios s
                     ORDER BY s.StudioName";
    $studios_result = mysqli_query($conn, $studios_query);
    $row_count = mysqli_num_rows($studios_result);
    error_log("All studios fetched: $row_count");
}

// Debug: Let's also check total studios in database
$total_studios_query = "SELECT COUNT(*) as total FROM studios";
$total_result = mysqli_query($conn, $total_studios_query);
$total_row = mysqli_fetch_assoc($total_result);
error_log("Total studios in database: " . $total_row['total']);

// Debug: Check studios with coordinates
$coord_studios_query = "SELECT COUNT(*) as with_coords FROM studios WHERE Latitude IS NOT NULL AND Longitude IS NOT NULL";
$coord_result = mysqli_query($conn, $coord_studios_query);
$coord_row = mysqli_fetch_assoc($coord_result);
error_log("Studios with coordinates: " . $coord_row['with_coords']);

$studios = [];
while ($row = mysqli_fetch_assoc($studios_result)) {
    error_log("Processing studio: " . $row['StudioName'] . " (ID: " . $row['StudioID'] . ") - Lat: " . $row['Latitude'] . ", Lng: " . $row['Longitude']);
    
        // Ensure numeric latitude/longitude for JSON and client-side processing
        $row['Latitude'] = ($row['Latitude'] !== null && $row['Latitude'] !== '') ? (float)$row['Latitude'] : null;
        $row['Longitude'] = ($row['Longitude'] !== null && $row['Longitude'] !== '') ? (float)$row['Longitude'] : null;

        if ($row['StudioImg']) {
            // Convert binary image to base64 and then remove raw blob to avoid JSON encoding issues
            $row['StudioImgBase64'] = 'data:image/jpeg;base64,' . base64_encode($row['StudioImg']);
            unset($row['StudioImg']);
        } else {
            $row['StudioImgBase64'] = 'images/default_studio.jpg';
        }

    // Get average rating for this studio's owner
    $owner_id = $row['OwnerID'];
    $rating_query = "SELECT AVG(f.Rating) as AverageRating
                     FROM feedback f
                     WHERE f.OwnerID = ?";
    $stmt = mysqli_prepare($conn, $rating_query);
    mysqli_stmt_bind_param($stmt, "i", $owner_id);
    mysqli_stmt_execute($stmt);
    $rating_result = mysqli_stmt_get_result($stmt);
    $rating_row = mysqli_fetch_assoc($rating_result);
    
    if ($rating_row['AverageRating'] !== null) {
        $row['AverageRating'] = number_format($rating_row['AverageRating'], 1);
    } else {
        $row['AverageRating'] = "Not rated";
    }
    mysqli_stmt_close($stmt);

    $studio_id = $row['StudioID'];
    $services_query = "SELECT se.ServiceType, se.Description, se.Price 
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
    $row['services'] = $services;

    $feedback_query = "SELECT f.Rating, f.Comment, c.Name
                     FROM feedback f
                     LEFT JOIN clients c ON f.ClientID = c.ClientID
                     WHERE f.OwnerID = ? AND f.Rating IS NOT NULL
                     ORDER BY f.FeedbackID DESC
                     LIMIT 5";
    $stmt = mysqli_prepare($conn, $feedback_query);
    mysqli_stmt_bind_param($stmt, "i", $owner_id);
    mysqli_stmt_execute($stmt);
    $feedback_result = mysqli_stmt_get_result($stmt);

    $feedback = [];
    while ($feedback_row = mysqli_fetch_assoc($feedback_result)) {
        $feedback[] = $feedback_row;
    }
    $row['feedback'] = $feedback;

    $studios[] = $row;
    mysqli_stmt_close($stmt);
}

mysqli_free_result($studios_result);

if (empty($studios)) {
    error_log("No studios found in database");
    // Keep studios array empty - the frontend will handle this case
}

error_log("Studios before JSON: " . print_r($studios, true));
// Encode studios to JSON. Use PARTIAL_OUTPUT_ON_ERROR so non-fatal items won't make the whole payload empty.
$studios_json = json_encode($studios, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON encoding error: " . json_last_error_msg());
    $studios_json = "[]";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Browse Studios - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        /* Modern CSS Variables for consistent theming */
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

        /* Base Styles */
        #branding img { width: 180px; display: block; }
        
        body, main {
            background: linear-gradient(135deg, rgba(15, 15, 15, 0.9), rgba(30, 30, 30, 0.8)), 
                        url('../../shared/assets/images/dummy/slide-1.jpg') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary);
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }

        /* Main Content Layout */
        .main-content { 
            background: var(--background-dark); 
            min-height: calc(100vh - 200px); 
            padding: 60px 0; 
            margin-top: 8%; 
        }

        /* Typography */
        .page-title { 
            color: var(--text-primary); 
            text-align: center; 
            margin-bottom: 16px; 
            font-size: clamp(28px, 5vw, 42px); 
            font-weight: 700; 
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, var(--primary-color), #ff6b6b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle { 
            color: var(--text-secondary); 
            text-align: center; 
            margin-bottom: 50px; 
            font-size: 18px; 
            font-weight: 400;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Enhanced Grid Layout */
        .map-container { 
            display: grid; 
            grid-template-columns: 400px 1fr; 
            gap: 30px; 
            max-width: 1600px; 
            margin: 0 auto; 
            padding: 0 30px; 
        }

        /* Improved Sidebar */
        .map-sidebar { 
            background: var(--background-sidebar); 
            border-radius: var(--border-radius); 
            padding: 30px; 
            height: 700px; 
            display: flex; 
            flex-direction: column; 
            box-shadow: var(--shadow-medium);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
        }

        /* Enhanced Search */
        .map-search { margin-bottom: 25px; }
        
        .map-search input { 
            width: 100%; 
            padding: 16px 20px; 
            border-radius: var(--border-radius-small); 
            border: 2px solid var(--border-color); 
            background: var(--background-card); 
            color: var(--text-primary); 
            margin-bottom: 15px; 
            font-size: 16px;
            transition: var(--transition);
            box-sizing: border-box;
        }

        .map-search input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(229, 9, 20, 0.1);
        }

        .map-search button { 
            width: 100%; 
            padding: 16px 20px; 
            border-radius: var(--border-radius-small); 
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); 
            color: var(--text-primary); 
            border: none; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 12px; 
            font-weight: 600;
            font-size: 16px;
            transition: var(--transition);
            box-shadow: var(--shadow-light);
        }

        .map-search button:hover { 
            background: linear-gradient(135deg, var(--primary-hover), #ff1a1a); 
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Studios List */
        .studios-list { 
            flex: 1; 
            overflow-y: auto; 
            margin-top: 10px;
            scrollbar-width: thin;
            scrollbar-color: var(--border-color) transparent;
        }

        .studios-list::-webkit-scrollbar {
            width: 6px;
        }

        .studios-list::-webkit-scrollbar-track {
            background: transparent;
        }

        .studios-list::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }

        /* Enhanced Studio Items */
        .studio-item { 
            padding: 20px; 
            border-bottom: 1px solid var(--border-color); 
            cursor: pointer; 
            transition: var(--transition); 
            border-radius: var(--border-radius-small);
            margin-bottom: 8px;
        }

        .studio-item:hover { 
            background: var(--background-card); 
            transform: translateX(4px);
            box-shadow: var(--shadow-light);
        }

        .studio-item.selected { 
            background: var(--background-card); 
            border-left: 4px solid var(--primary-color);
            box-shadow: var(--shadow-light);
        }

        .studio-item-content { display: flex; gap: 16px; align-items: center; }

        .studio-item-image { 
            width: 70px; 
            height: 70px; 
            border-radius: var(--border-radius-small); 
            object-fit: cover; 
            box-shadow: var(--shadow-light);
            border: 2px solid var(--border-color);
        }

        .studio-item-info { flex: 1; }

        .studio-item-name { 
            font-weight: 600; 
            margin-bottom: 8px; 
            color: var(--text-primary); 
            font-size: 16px;
            line-height: 1.4;
        }

        .studio-item-location, .studio-item-rating, .studio-item-distance { 
            font-size: 13px; 
            color: var(--text-secondary); 
            display: flex; 
            align-items: center; 
            gap: 6px; 
            margin-bottom: 4px; 
        }

        .studio-item-rating i { color: var(--primary-color); }

        /* Enhanced Map */
        #map { 
            height: 700px; 
            border-radius: var(--border-radius); 
            overflow: hidden; 
            box-shadow: var(--shadow-medium);
            border: 1px solid var(--border-color);
        }

        /* Studio Details Panel */
        .studio-details { 
            background: var(--background-sidebar); 
            border-radius: var(--border-radius); 
            padding: 30px; 
            margin-top: 30px; 
            display: none; 
            max-width: 1600px; 
            margin-left: auto; 
            margin-right: auto; 
            box-shadow: var(--shadow-medium);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
        }

        .studio-details.active { display: block; animation: fadeInUp 0.3s ease-out; }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .studio-details-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start;
            margin-bottom: 20px; 
            flex-wrap: wrap;
            gap: 15px;
        }

        .studio-details-title { 
            font-size: 28px; 
            color: var(--text-primary); 
            margin-bottom: 8px; 
            font-weight: 700;
        }

        .studio-details-description { 
            color: var(--text-secondary); 
            margin-bottom: 20px; 
            line-height: 1.6;
            font-size: 16px;
        }

        .studio-details-rating { 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            color: var(--text-primary); 
            font-size: 18px; 
            font-weight: 600;
        }

        .studio-details-rating i { color: var(--primary-color); }

        /* Enhanced Tabs */
        .tabs { margin-top: 25px; }

        .tabs-list { 
            display: flex; 
            gap: 4px; 
            margin-bottom: 20px; 
            background: var(--background-card);
            padding: 4px;
            border-radius: var(--border-radius-small);
        }

        .tab-trigger { 
            padding: 12px 24px; 
            background: transparent; 
            color: var(--text-secondary); 
            border-radius: var(--border-radius-small); 
            cursor: pointer; 
            transition: var(--transition); 
            font-weight: 500;
            flex: 1;
            text-align: center;
        }

        .tab-trigger.active { 
            background: var(--primary-color); 
            color: var(--text-primary);
            box-shadow: var(--shadow-light);
        }

        .tab-trigger:hover:not(.active) {
            background: var(--border-color);
            color: var(--text-primary);
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Service Items */
        .service-item { 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            padding: 16px 20px; 
            border: 1px solid var(--border-color); 
            margin-bottom: 12px; 
            border-radius: var(--border-radius-small);
            background: var(--background-card);
            transition: var(--transition);
        }

        .service-item:hover {
            border-color: var(--border-light);
            box-shadow: var(--shadow-light);
        }

        .service-info h4 { 
            color: var(--text-primary); 
            margin-bottom: 6px; 
            font-size: 16px;
            font-weight: 600;
        }

        .service-info p { 
            color: var(--text-secondary); 
            font-size: 14px; 
            margin: 0;
        }

        .service-price { 
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); 
            padding: 8px 16px; 
            border-radius: 20px; 
            color: var(--text-primary); 
            font-weight: 600; 
            font-size: 14px;
            box-shadow: var(--shadow-light);
        }

        /* Feedback Items */
        .feedback-item { 
            padding: 20px; 
            border: 1px solid var(--border-color); 
            margin-bottom: 15px; 
            border-radius: var(--border-radius-small);
            background: var(--background-card);
        }

        .feedback-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            margin-bottom: 12px; 
        }

        .feedback-author { 
            font-weight: 600; 
            color: var(--text-primary); 
            font-size: 16px;
        }

        .feedback-rating { 
            display: flex; 
            align-items: center; 
            gap: 6px; 
            color: var(--primary-color); 
        }

        .feedback-comment { 
            color: var(--text-secondary); 
            font-size: 15px; 
            line-height: 1.6; 
        }

        .no-feedback { 
            color: var(--text-muted); 
            text-align: center; 
            padding: 40px 20px; 
            font-style: italic;
        }

        /* Footer Buttons */
        .studio-details-footer { 
            display: flex; 
            justify-content: space-between; 
            margin-top: 30px; 
            padding-top: 25px; 
            border-top: 1px solid var(--border-color); 
            gap: 15px;
        }

        .studio-details-footer button { 
            padding: 14px 28px; 
            border-radius: var(--border-radius-small); 
            cursor: pointer; 
            font-weight: 600; 
            font-size: 16px;
            transition: var(--transition);
            flex: 1;
        }

        .studio-details-footer .view-profile { 
            background: var(--background-card); 
            color: var(--text-primary); 
            border: 2px solid var(--border-color); 
        }

        .studio-details-footer .view-profile:hover {
            background: var(--border-color);
            border-color: var(--border-light);
        }

        .studio-details-footer .book-now { 
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); 
            color: var(--text-primary); 
            border: none; 
            box-shadow: var(--shadow-light);
        }

        .studio-details-footer .book-now:hover {
            background: linear-gradient(135deg, var(--primary-hover), #ff1a1a);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Enhanced Markers */
        .custom-marker { 
            width: 36px; 
            height: 36px; 
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: white; 
            font-size: 16px; 
            font-weight: 600;
            box-shadow: var(--shadow-medium);
            border: 3px solid white;
            transition: var(--transition);
        }

        .custom-marker.selected { 
            transform: scale(1.3); 
            z-index: 1000; 
            box-shadow: var(--shadow-heavy);
        }

        .user-marker { 
            width: 36px; 
            height: 36px; 
            background: linear-gradient(135deg, var(--secondary-color), #60a5fa); 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: white; 
            font-size: 16px; 
            font-weight: 600;
            box-shadow: var(--shadow-medium);
            border: 3px solid white;
            animation: pulse 2s infinite; 
        }

        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(59, 130, 246, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }

        /* Enhanced Toast */
        .toast { 
            position: fixed; 
            bottom: 30px; 
            right: 30px; 
            width: 350px; 
            background: var(--background-sidebar); 
            border-left: 4px solid var(--primary-color); 
            border-radius: var(--border-radius); 
            box-shadow: var(--shadow-heavy); 
            z-index: 1000; 
            overflow: hidden; 
            animation: slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
        }

        .toast-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 15px 20px; 
            background: var(--background-card); 
            color: var(--text-primary); 
            font-weight: 600;
        }

        .toast-body { 
            padding: 15px 20px; 
            color: var(--text-secondary); 
            line-height: 1.5;
        }

        .toast-close { 
            background: none; 
            border: none; 
            color: var(--text-muted); 
            font-size: 20px; 
            cursor: pointer; 
            transition: var(--transition);
            padding: 4px;
            border-radius: 4px;
        }

        .toast-close:hover {
            color: var(--text-primary);
            background: var(--border-color);
        }

        @keyframes slideIn {
            from { transform: translateX(100%) scale(0.9); opacity: 0; }
            to { transform: translateX(0) scale(1); opacity: 1; }
        }

        @keyframes fadeOut {
            from { opacity: 1; transform: scale(1); }
            to { opacity: 0; transform: scale(0.95); }
        }

        /* Enhanced Responsive Design */
        @media (max-width: 1200px) {
            .map-container { 
                grid-template-columns: 350px 1fr; 
                gap: 25px; 
                padding: 0 25px; 
            }
        }

        @media (max-width: 968px) {
            .map-container { 
                grid-template-columns: 1fr; 
                gap: 20px; 
                padding: 0 20px; 
            }
            
            .map-sidebar { 
                height: auto; 
                order: 2; 
                padding: 25px;
            }
            
            #map { 
                height: 400px; 
                order: 1; 
            }
            
            .studios-list { 
                max-height: 300px; 
            }

            .studio-details-footer {
                flex-direction: column;
            }

            .studio-details-footer button {
                width: 100%;
            }
        }

        @media (max-width: 640px) {
            .main-content { padding: 40px 0; }
            
            .map-container { padding: 0 15px; }
            
            .map-sidebar, .studio-details { 
                padding: 20px; 
                border-radius: var(--border-radius-small);
            }
            
            #map { height: 300px; }
            
            .studios-list { max-height: 250px; }

            .toast {
                width: calc(100vw - 30px);
                right: 15px;
                bottom: 15px;
            }

            .studio-details-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Loading States */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid var(--border-color);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
    <!--[if lt IE 9]>
    <script src="../../shared/assets/js/ie-support/html5.js"></script>
    <script src="../../shared/assets/js/ie-support/respond.js"></script>
    <![endif]-->
</head>
<body class="header-collapse">
    <div id="site-content">
    <?php include '../../shared/components/navbar.php'; ?>

        <main class="main-content">
            <div class="container">
                <h1 class="page-title">Browse Studios</h1>
                <p class="page-subtitle">Find and book the perfect recording studio for your next project</p>
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
                    <div id="map"></div>
                </div>
                <div class="studio-details" id="studio-details"></div>
            </div>
        </main>
        <?php include '../../shared/components/footer.php'; ?>

    </div>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?php echo getJSPath('plugins.js'); ?>"></script>
    <script src="<?php echo getJSPath('app.js'); ?>"></script>
    <script>
        const studiosData = <?php echo $studios_json; ?>;
        console.log("Studios data:", studiosData);

        document.addEventListener("DOMContentLoaded", () => {
            let map;
            const markers = {};
            let userMarker = null;
            let userLocation = null;
            let selectedStudio = null;
            const defaultCenter = [10.2333, 123.0833];
            const studios = studiosData || [];
            const L = window.L;

            if (!L) {
                console.error("Leaflet not loaded");
                document.getElementById("map").innerHTML = "<p style='color: red; text-align: center;'>Map failed to load.</p>";
                return;
            }

            console.log("Number of studios:", studios.length);
            const studiosWithCoordinates = studios.filter((studio) => studio.Latitude && studio.Longitude);
            console.log("Studios with coordinates:", studiosWithCoordinates.length);

            if (studiosWithCoordinates.length > 0) {
                console.log("First studio with coordinates:", studiosWithCoordinates[0]);
            }

            try {
                initMap();
                populateStudiosList(studios);
                document.getElementById("find-nearby-btn").addEventListener("click", getUserLocation);
                document.getElementById("search-input").addEventListener("input", handleSearch);
            } catch (error) {
                console.error("Initialization failed:", error);
                document.getElementById("map").innerHTML = "<p style='color: red; text-align: center;'>Error loading map.</p>";
            }

            function initMap() {
                const mapDiv = document.getElementById("map");
                if (!mapDiv.offsetHeight) {
                    console.error("Map div not visible");
                    mapDiv.innerHTML = "<p style='color: red;'>Map container is not visible.</p>";
                    return;
                }
                map = L.map("map").setView(defaultCenter, 13);
                L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                    maxZoom: 19,
                }).addTo(map);
                addStudioMarkers();
                setTimeout(() => map.invalidateSize(), 100);
            }

            function addStudioMarkers() {
                studios.forEach((studio) => {
                    console.log(`Studio ${studio.StudioID} - ${studio.StudioName}: Lat=${studio.Latitude}, Lng=${studio.Longitude}`);
                    
                    // Use default coordinates if studio doesn't have them
                    let lat = studio.Latitude;
                    let lng = studio.Longitude;
                    
                    if (!lat || !lng) {
                        // Use default coordinates for studios without location data
                        lat = 10.2333;
                        lng = 123.0833;
                        console.warn(`Studio ${studio.StudioID} (${studio.StudioName}) has no coordinates, using default`);
                    }
                    
                    const latNum = Number.parseFloat(lat);
                    const lngNum = Number.parseFloat(lng);
                    
                    if (isNaN(latNum) || isNaN(lngNum)) {
                        console.error(`Invalid coordinates for studio ${studio.StudioID}:`, lat, lng);
                        return;
                    }
                    
                    const studioIcon = L.divIcon({
                        className: "custom-marker-wrapper",
                        html: `<div class="custom-marker"><i class="fa fa-map-marker"></i></div>`,
                        iconSize: [30, 30],
                        iconAnchor: [15, 30],
                    });
                    const marker = L.marker([latNum, lngNum], {
                        icon: studioIcon,
                        studioId: studio.StudioID,
                    }).addTo(map);
                    // Popup shows studio name, address/location, coordinates and a Book Now button
                    marker.bindPopup(`
                        <div style="text-align: center; max-width:260px;">
                            <strong>${studio.StudioName}</strong>
                            <div style="margin:6px 0; font-size:13px; color:#666;">${studio.Loc_Desc || 'Location not specified'}</div>
                            <div style="font-size:13px; color:#444; margin-bottom:6px;">Rating: ${studio.AverageRating || "N/A"}</div>
                            <div style="margin-top:8px;">
                                <a href="../../booking/php/booking.php?studio_id=${studio.StudioID}" class="book-now" style="display:inline-block;padding:8px 12px;border-radius:6px;color:#fff;background:#e50914;text-decoration:none;font-weight:600;">Book Now</a>
                            </div>
                        </div>
                    `);
                    marker.on("click", () => selectStudio(studio));
                    markers[studio.StudioID] = marker;
                });
                
                // Set map view to first studio or default center
                if (studios.length > 0) {
                    const firstStudio = studios[0];
                    let centerLat = 10.2333;
                    let centerLng = 123.0833;
                    
                    if (firstStudio.Latitude && firstStudio.Longitude) {
                        centerLat = Number.parseFloat(firstStudio.Latitude);
                        centerLng = Number.parseFloat(firstStudio.Longitude);
                    }
                    
                    map.setView([centerLat, centerLng], 13);
                }
            }

            function populateStudiosList(studiosList) {
                const listContainer = document.getElementById("studios-list");
                listContainer.innerHTML = "";
                if (studiosList.length === 0) {
                    listContainer.innerHTML = `
                        <div style="text-align: center; color: #aaa; padding: 20px;">
                            <i class="fa fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>
                            <p>No studios found in the database.</p>
                            <p style="font-size: 14px;">Please add some studios to see them on the map.</p>
                        </div>
                    `;
                    return;
                }
                studiosList.forEach((studio) => {
                    const studioElement = document.createElement("div");
                    studioElement.className = "studio-item";
                    studioElement.setAttribute("data-studio-id", studio.StudioID);
                    if (selectedStudio && selectedStudio.StudioID === studio.StudioID) {
                        studioElement.classList.add("selected");
                    }
                    
                    // Calculate distance if user location is available and studio has coordinates
                    let distanceHtml = "";
                    if (userLocation && studio.Latitude && studio.Longitude) {
                        const lat = Number.parseFloat(studio.Latitude);
                        const lng = Number.parseFloat(studio.Longitude);
                        if (!isNaN(lat) && !isNaN(lng)) {
                            const distance = calculateDistance(userLocation.lat, userLocation.lng, lat, lng);
                            distanceHtml = `
                                <div class="studio-item-distance">
                                    <i class="fa fa-road"></i>
                                    <span>${distance.toFixed(1)} km away</span>
                                </div>
                            `;
                        }
                    }
                    
                    studioElement.innerHTML = `
                        <div class="studio-item-content">
                            <img src="${studio.StudioImgBase64}" alt="${studio.StudioName}" class="studio-item-image">
                            <div class="studio-item-info">
                                <h3 class="studio-item-name">${studio.StudioName}</h3>
                                <div class="studio-item-location">
                                    <i class="fa fa-map-marker"></i>
                                    <span>${studio.Loc_Desc || "Location not specified"}</span>
                                </div>
                                <div class="studio-item-rating">
                                    <i class="fa fa-star"></i>
                                    <span>${studio.AverageRating || "Not rated"}</span>
                                </div>
                                ${distanceHtml}
                            </div>
                        </div>
                    `;
                    studioElement.addEventListener("click", () => selectStudio(studio));
                    listContainer.appendChild(studioElement);
                });
            }

            function selectStudio(studio) {
                selectedStudio = studio;
                const studioItems = document.querySelectorAll(".studio-item");
                studioItems.forEach((item) => {
                    item.classList.remove("selected");
                    if (item.getAttribute("data-studio-id") == studio.StudioID) {
                        item.classList.add("selected");
                        item.scrollIntoView({ behavior: "smooth", block: "nearest" });
                    }
                });
                
                // Center map on studio if it has coordinates
                if (studio.Latitude && studio.Longitude) {
                    const lat = Number.parseFloat(studio.Latitude);
                    const lng = Number.parseFloat(studio.Longitude);
                    if (!isNaN(lat) && !isNaN(lng)) {
                        map.setView([lat, lng], 15);
                    }
                }
                
                // Update marker styling
                Object.values(markers).forEach((marker) => {
                    const markerElement = marker.getElement();
                    if (markerElement) {
                        markerElement.querySelector(".custom-marker").classList.remove("selected");
                    }
                });
                if (markers[studio.StudioID]) {
                    const markerElement = markers[studio.StudioID].getElement();
                    if (markerElement) {
                        markerElement.querySelector(".custom-marker").classList.add("selected");
                    }
                    markers[studio.StudioID].openPopup();
                }
                
                updateStudioDetails(studio);
            }

            function updateStudioDetails(studio) {
                const detailsContainer = document.getElementById("studio-details");
                detailsContainer.classList.add("active");
                let servicesHtml = "<p>No services available.</p>";
                if (studio.services && studio.services.length > 0) {
                    servicesHtml = studio.services
                        .map((service) => `
                            <div class="service-item">
                                <div class="service-info">
                                    <h4>${service.ServiceType}</h4>
                                    <p>${service.Description}</p>
                                </div>
                                <div class="service-price">₱${Number.parseFloat(service.Price).toLocaleString()}</div>
                            </div>
                        `)
                        .join("");
                }
                let feedbackHtml = "<p class='no-feedback'>No reviews available yet.</p>";
                if (studio.feedback && studio.feedback.length > 0) {
                    feedbackHtml = studio.feedback
                        .map((feedback) => `
                            <div class="feedback-item">
                                <div class="feedback-header">
                                    <div class="feedback-author">${feedback.Name || 'Anonymous'}</div>
                                    <div class="feedback-rating">
                                        ${Array(Number.parseInt(feedback.Rating)).fill('<i class="fa fa-star"></i>').join("")}
                                    </div>
                                </div>
                                <div class="feedback-comment">${feedback.Comment}</div>
                            </div>
                        `)
                        .join("");
                }
                let distanceHtml = "";
                if (userLocation && studio.Latitude && studio.Longitude) {
                    const lat = Number.parseFloat(studio.Latitude);
                    const lng = Number.parseFloat(studio.Longitude);
                    if (!isNaN(lat) && !isNaN(lng)) {
                        const distance = calculateDistance(userLocation.lat, userLocation.lng, lat, lng);
                        distanceHtml = `<p>${distance.toFixed(1)} km from your location</p>`;
                    }
                }
                
                // Handle location information
                let locationInfo = "";
                if (studio.Latitude && studio.Longitude) {
                    const lat = Number.parseFloat(studio.Latitude);
                    const lng = Number.parseFloat(studio.Longitude);
                    if (!isNaN(lat) && !isNaN(lng)) {
                        locationInfo = `
                            <p>Coordinates: ${lat.toFixed(4)}, ${lng.toFixed(4)}</p>
                            <p>${studio.Loc_Desc || "No location description available."}</p>
                            ${distanceHtml}
                        `;
                    } else {
                        locationInfo = `
                            <p>Location: ${studio.Loc_Desc || "No location description available."}</p>
                            <p><em>Coordinates not available</em></p>
                        `;
                    }
                } else {
                    locationInfo = `
                        <p>Location: ${studio.Loc_Desc || "No location description available."}</p>
                        <p><em>Coordinates not available</em></p>
                    `;
                }
                
                detailsContainer.innerHTML = `
                    <div class="studio-details-header">
                        <div>
                            <h2 class="studio-details-title">${studio.StudioName}</h2>
                            <p class="studio-details-description">${studio.Loc_Desc || "No description available."}</p>
                        </div>
                        <div class="studio-details-rating">
                            <i class="fa fa-star"></i>
                            <span>${studio.AverageRating || "N/A"}</span>
                        </div>
                    </div>
                    <div class="tabs">
                        <div class="tabs-list">
                            <div class="tab-trigger active" data-tab="services" onclick="activateTab('services')">Services</div>
                            <div class="tab-trigger" data-tab="reviews" onclick="activateTab('reviews')">Reviews</div>
                            <div class="tab-trigger" data-tab="gallery" onclick="activateTab('gallery')">Gallery</div>
                            <div class="tab-trigger" data-tab="location" onclick="activateTab('location')">Location</div>
                        </div>
                        <div class="tab-content active" id="tab-services">
                            ${servicesHtml}
                        </div>
                        <div class="tab-content" id="tab-reviews">
                            ${feedbackHtml}
                        </div>
                        <div class="tab-content" id="tab-gallery">
                            <div class="gallery">
                                ${studio.StudioImgBase64 ? `
                                    <div style="text-align:center;">
                                        <img src="${studio.StudioImgBase64}" alt="${studio.StudioName} image" style="max-width:100%;height:auto;border-radius:8px;border:1px solid #333;box-shadow:0 4px 12px rgba(0,0,0,0.3)" />
                                    </div>
                                ` : `
                                    <div style="text-align:center;color:#999; padding:30px;">No images available for this studio.</div>
                                `}
                            </div>
                        </div>
                        <div class="tab-content" id="tab-location">
                            <div class="location-info">
                                <i class="fa fa-map-marker"></i>
                                <div>
                                    ${locationInfo}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="studio-details-footer">
                        <button class="view-profile" onclick="window.location.href='profile.php?studio_id=${studio.StudioID}'">View Profile</button>
                        <button class="book-now" onclick="window.location.href='../../booking/php/booking.php?studio_id=${studio.StudioID}'">Book Now</button>
                    </div>
                `;
            }

            function handleSearch() {
                const searchQuery = document.getElementById("search-input").value.toLowerCase().trim();
                if (!searchQuery) {
                    populateStudiosList(studios);
                    return;
                }
                const filteredStudios = studios.filter(
                    (studio) =>
                        studio.StudioName.toLowerCase().includes(searchQuery) ||
                        (studio.Loc_Desc && studio.Loc_Desc.toLowerCase().includes(searchQuery)) ||
                        (studio.services &&
                            studio.services.some(
                                (service) =>
                                    service.ServiceType.toLowerCase().includes(searchQuery) ||
                                    service.Description.toLowerCase().includes(searchQuery),
                            )),
                );
                populateStudiosList(filteredStudios);
            }

            function getUserLocation() {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            userLocation = {
                                lat: position.coords.latitude,
                                lng: position.coords.longitude,
                            };
                            map.setView([userLocation.lat, userLocation.lng], 13);
                            if (userMarker) {
                                userMarker.setLatLng([userLocation.lat, userLocation.lng]);
                            } else {
                                const userIcon = L.divIcon({
                                    className: "user-marker-wrapper",
                                    html: `<div class="user-marker"><i class="fa fa-circle"></i></div>`,
                                    iconSize: [30, 30],
                                    iconAnchor: [15, 15],
                                });
                                userMarker = L.marker([userLocation.lat, userLocation.lng], {
                                    icon: userIcon,
                                }).addTo(map);
                                userMarker.bindPopup("Your location").openPopup();
                            }
                            const sortedStudios = [...studios].sort((a, b) => {
                                if (!a.Latitude || !a.Longitude) return 1;
                                if (!b.Latitude || !b.Longitude) return -1;
                                const distA = calculateDistance(
                                    userLocation.lat,
                                    userLocation.lng,
                                    Number.parseFloat(a.Latitude),
                                    Number.parseFloat(a.Longitude),
                                );
                                const distB = calculateDistance(
                                    userLocation.lat,
                                    userLocation.lng,
                                    Number.parseFloat(b.Latitude),
                                    Number.parseFloat(b.Longitude),
                                );
                                return distA - distB;
                            });
                            populateStudiosList(sortedStudios);
                            showToast("Location found", "Studios have been sorted by distance from your location");
                        },
                        (error) => {
                            showToast("Location error", "Unable to access your location: " + error.message, "error");
                        },
                    );
                } else {
                    showToast("Geolocation not supported", "Your browser does not support geolocation", "error");
                }
            }

            function calculateDistance(lat1, lon1, lat2, lon2) {
                if (!lat1 || !lon1 || !lat2 || !lon2) return Number.POSITIVE_INFINITY;
                const R = 6371;
                const dLat = deg2rad(lat2 - lat1);
                const dLon = deg2rad(lon2 - lon1);
                const a =
                    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                    Math.cos(deg2rad(lat1)) * Math.cos(deg2rad(lat2)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
                const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
                const d = R * c;
                return d;
            }

            function deg2rad(deg) {
                return deg * (Math.PI / 180);
            }

            function showToast(title, message, type = "success") {
                const toast = document.createElement("div");
                toast.className = `toast toast-${type}`;
                toast.innerHTML = `
                    <div class="toast-header">
                        <strong>${title}</strong>
                        <button type="button" class="toast-close">×</button>
                    </div>
                    <div class="toast-body">${message}</div>
                `;
                document.body.appendChild(toast);
                toast.querySelector(".toast-close").addEventListener("click", () => {
                    toast.style.animation = "fadeOut 0.3s ease-out forwards";
                    setTimeout(() => toast.remove(), 300);
                });
                setTimeout(() => {
                    if (document.body.contains(toast)) {
                        toast.style.animation = "fadeOut 0.3s ease-out forwards";
                        setTimeout(() => {
                            if (document.body.contains(toast)) toast.remove();
                        }, 300);
                    }
                }, 5000);
            }
        }, { once: true });

        function activateTab(tabId) {
            const tabTriggers = document.querySelectorAll(".tab-trigger");
            tabTriggers.forEach((tab) => {
                tab.classList.remove("active");
                if (tab.getAttribute("data-tab") === tabId) {
                    tab.classList.add("active");
                }
            });
            const tabContents = document.querySelectorAll(".tab-content");
            tabContents.forEach((content) => {
                content.classList.remove("active");
            });
            document.getElementById(`tab-${tabId}`).classList.add("active");
        }
    </script>
</body>
</html>
