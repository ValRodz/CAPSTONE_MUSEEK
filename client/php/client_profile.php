<?php
// Set session cookie parameters before starting the session
session_set_cookie_params([
    'lifetime' => 1440, // 24 minutes
    'path' => '/',
    'secure' => false, // Set to true if using HTTPS, false for localhost
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
include '../../shared/config/db.php';
include '../../shared/config/path_config.php';

// Ensures uploaded avatar is center-cropped and resized to 320x320.
// If GD is not available, falls back to saving the original upload.
function processAvatarToSquare($tmpPath, $destPath, $ext)
{
    $ext = strtolower($ext);
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }

    // If GD is missing or source creation functions are unavailable, store the file as-is.
    $gdAvailable = extension_loaded('gd');
    $jpegFn = function_exists('imagecreatefromjpeg');
    $pngFn  = function_exists('imagecreatefrompng');
    $webpFn = function_exists('imagecreatefromwebp');

    if (!$gdAvailable || (!$jpegFn && !$pngFn && !$webpFn)) {
        return is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $destPath) : copy($tmpPath, $destPath);
    }

    // Create source image based on extension with per-ext capability checks
    $src = null;
    switch ($ext) {
        case 'jpg':
            if ($jpegFn) {
                $src = @imagecreatefromjpeg($tmpPath);
            }
            break;
        case 'png':
            if ($pngFn) {
                $src = @imagecreatefrompng($tmpPath);
            }
            break;
        case 'webp':
            if ($webpFn) {
                $src = @imagecreatefromwebp($tmpPath);
            }
            break;
        default:
            // Unknown type; store original file
            return is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $destPath) : copy($tmpPath, $destPath);
    }

    // If we failed to create a source (corrupt image, unsupported), store original file
    if (!$src) {
        return is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $destPath) : copy($tmpPath, $destPath);
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Center-crop to square
    $side = min($srcW, $srcH);
    $srcX = (int)floor(($srcW - $side) / 2);
    $srcY = (int)floor(($srcH - $side) / 2);

    // Create destination canvas
    $dst = imagecreatetruecolor(320, 320);
    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
    }

    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, 320, 320, $side, $side);

    // Save based on extension; if saver not available, fallback to JPEG
    $ok = false;
    switch ($ext) {
        case 'jpg':
            $ok = imagejpeg($dst, $destPath, 90);
            break;
        case 'png':
            if (function_exists('imagepng')) {
                $ok = imagepng($dst, $destPath, 6);
            } else {
                $ok = imagejpeg($dst, $destPath, 90);
            }
            break;
        case 'webp':
            if (function_exists('imagewebp')) {
                $ok = imagewebp($dst, $destPath, 90);
            } else {
                $ok = imagejpeg($dst, $destPath, 90);
            }
            break;
    }

    imagedestroy($src);
    imagedestroy($dst);

    // If save failed for any reason, store original file
    if (!$ok) {
        return is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $destPath) : copy($tmpPath, $destPath);
    }

    return true;
}

// Check if user is authenticated and is a client
$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'client';

if (!$is_authenticated) {
    header('Location: ../../auth/php/login.php');
    exit();
}

// Handle password change messages
$password_message = '';
$password_status = '';

if (isset($_SESSION['password_message']) && isset($_SESSION['password_status'])) {
    $password_message = $_SESSION['password_message'];
    $password_status = $_SESSION['password_status'];

    // Clear the session variables after displaying
    unset($_SESSION['password_message']);
    unset($_SESSION['password_status']);
}

// Fetch client details
$client_query = "SELECT ClientID, Name, Email, Phone, ProfileImg FROM clients WHERE ClientID = ?";
$stmt = mysqli_prepare($conn, $client_query);
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$client_result = mysqli_stmt_get_result($stmt);
$client = mysqli_fetch_assoc($client_result) ?: [
    'ClientID' => 0,
    'Name' => 'Unknown',
    'Email' => 'N/A',
    'Phone' => 'N/A',
    'ProfileImg' => ''
];
mysqli_stmt_close($stmt);

// Handle profile update
$update_message = '';
$update_status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);

    // Update client information (do not change email here)
    $update_query = "UPDATE clients SET Name = ?, Phone = ? WHERE ClientID = ?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, "ssi", $name, $phone, $_SESSION['user_id']);

    if (mysqli_stmt_execute($update_stmt)) {
        $update_message = "Profile updated successfully!";
        $update_status = "success";

        // Update client variable with new data
        $client['Name'] = $name;
        $client['Phone'] = $phone;
    } else {
        $update_message = "Error updating profile: " . mysqli_error($conn);
        $update_status = "error";
    }

    mysqli_stmt_close($update_stmt);
}

// Handle avatar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar'])) {
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $update_message = 'No file uploaded or an upload error occurred.';
        $update_status = 'error';
    } else {
        $file = $_FILES['avatar'];
        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
        $max_size = 2 * 1024 * 1024; // 2MB
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($ext, $allowed_ext) || !in_array($mime, $allowed_mime)) {
            $update_message = 'Invalid image type. Please upload JPG, PNG, or WebP.';
            $update_status = 'error';
        } elseif ($file['size'] > $max_size) {
            $update_message = 'Image exceeds 2MB size limit.';
            $update_status = 'error';
        } else {
            $uploadDir = __DIR__ . '/../../shared/assets/images/avatars/clients/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            $filename = 'client_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
            $destPath = $uploadDir . $filename;

            // Process the uploaded image: center-crop and resize to 320x320
            if (processAvatarToSquare($file['tmp_name'], $destPath, $ext)) {
                $relPath = 'avatars/clients/' . $filename; // relative to images path
                $update_avatar_query = "UPDATE clients SET ProfileImg = ? WHERE ClientID = ?";
                $avatar_stmt = mysqli_prepare($conn, $update_avatar_query);
                mysqli_stmt_bind_param($avatar_stmt, "si", $relPath, $_SESSION['user_id']);
                if (mysqli_stmt_execute($avatar_stmt)) {
                    $update_message = 'Profile picture updated successfully!';
                    $update_status = 'success';
                    $client['ProfileImg'] = $relPath;
                } else {
                    $update_message = 'Error saving profile picture: ' . mysqli_error($conn);
                    $update_status = 'error';
                }
                mysqli_stmt_close($avatar_stmt);
            } else {
                $update_message = 'Failed to process the uploaded image.';
                $update_status = 'error';
            }
        }
    }
}
// Fetch booking statistics
$booking_stats_query = "
    SELECT 
        COUNT(*) as total_bookings,
        SUM(CASE WHEN bs.Book_Stats = 'Confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
        SUM(CASE WHEN bs.Book_Stats = 'Pending' THEN 1 ELSE 0 END) as pending_bookings,
        SUM(CASE WHEN bs.Book_Stats = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
        SUM(CASE WHEN bs.Book_Stats = 'Finished' THEN 1 ELSE 0 END) as finished_bookings
    FROM bookings b
    JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
    WHERE b.ClientID = ?
";
$stats_stmt = mysqli_prepare($conn, $booking_stats_query);
mysqli_stmt_bind_param($stats_stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);
$booking_stats = mysqli_fetch_assoc($stats_result) ?: [
    'total_bookings' => 0,
    'confirmed_bookings' => 0,
    'pending_bookings' => 0,
    'cancelled_bookings' => 0,
    'finished_bookings' => 0
];
mysqli_stmt_close($stats_stmt);

// Fetch recent bookings
// Updated for new_museek.sql schema: services linked via booking_services table
$recent_bookings_query = "
    SELECT 
        b.BookingID, 
        s.StudioName, 
        GROUP_CONCAT(DISTINCT sv.ServiceType ORDER BY sv.ServiceType SEPARATOR ', ') AS ServiceType,
        sch.Sched_Date, 
        sch.Time_Start, 
        sch.Time_End, 
        bs.Book_Stats AS status
    FROM bookings b
    JOIN studios s ON b.StudioID = s.StudioID
    LEFT JOIN booking_services bsv ON b.BookingID = bsv.BookingID
    LEFT JOIN services sv ON bsv.ServiceID = sv.ServiceID
    JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
    JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
    WHERE b.ClientID = ?
    GROUP BY b.BookingID
    ORDER BY sch.Sched_Date DESC, sch.Time_Start DESC
    LIMIT 5
";
$recent_stmt = mysqli_prepare($conn, $recent_bookings_query);
mysqli_stmt_bind_param($recent_stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($recent_stmt);
$recent_result = mysqli_stmt_get_result($recent_stmt);
$recent_bookings = [];
while ($row = mysqli_fetch_assoc($recent_result)) {
    $recent_bookings[] = $row;
}
mysqli_stmt_close($recent_stmt);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>My Profile - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --secondary-color: #3b82f6;
            --background-dark: #0f0f0f;
            --background-card: rgba(20, 20, 20, 0.95);
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --border-color: #333333;
            --shadow-medium: 0 4px 16px rgba(0, 0, 0, 0.4);
            --border-radius: 12px;
            --border-radius-small: 8px;
            --accent-color: #8e44ad;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
        }

        body,
        main {
            background: linear-gradient(135deg, rgba(15, 15, 15, 0.9), rgba(30, 30, 30, 0.8)),
                url('../../shared/assets/images/dummy/slide-1.jpg') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary);
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        main {
            margin-top: 5%;
            min-height: 80vh;
        }

        .profile-section {
            padding: 60px 0;
            margin: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .profile-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 30px;
            box-sizing: border-box;
        }

        .profile-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .profile-header h1 {
            font-size: 48px;
            font-weight: 700;
            margin: 0;
            color: var(--text-primary);
            letter-spacing: 1px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .profile-header p {
            font-size: 18px;
            color: var(--text-secondary);
            margin: 15px 0 0;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            margin-top: 20px;
        }

        .profile-card {
            background: var(--background-card);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
            padding: 30px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            height: 100%;
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .profile-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
        }

        .profile-info {
            display: flex;
            flex-direction: column;
        }

        .profile-avatar {
            width: 320px;
            height: 320px;
            min-width: 320px;
            min-height: 320px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 72px;
            font-weight: 600;
            margin: 0;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
            border: 3px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
            align-self: flex-start;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .profile-details {
            text-align: left;
            /* align details to the left under the header row */
        }

        /* New: identity row to place avatar and name side by side */
        .profile-identity {
            display: flex;
            align-items: flex-start;
            /* align name to top of avatar */
            gap: 24px;
            margin-bottom: 20px;
        }

        .profile-name h2 {
            font-size: 48px;
            margin: 0;
            color: var(--text-primary);
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .profile-details h2 {
            font-size: 28px;
            margin: 0 0 10px;
            color: var(--text-primary);
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .profile-details p {
            font-size: 16px;
            color: var(--text-secondary);
            margin: 0 0 8px;
            letter-spacing: 0.3px;
        }

        .profile-details .client-id {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 25px;
            opacity: 0.8;
        }

        .edit-button {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius-small);
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            margin-top: 20px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.2);
        }

        .edit-button:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(229, 9, 20, 0.3);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 30px;
        }

        .stat-card {
            background: rgba(20, 20, 20, 0.95);
            border-radius: var(--border-radius-small);
            padding: 20px;
            text-align: center;
            transition: transform 0.2s, background-color 0.2s;
            border: 1px solid rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .stat-card:hover {
            background: rgba(30, 30, 30, 0.95);
            transform: translateY(-3px);
        }

        .stat-card h3 {
            font-size: 32px;
            margin: 0;
            color: var(--primary-color);
            font-weight: 700;
        }

        .stat-card p {
            font-size: 14px;
            color: var(--text-secondary);
            margin: 8px 0 0;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
        }

        .profile-content {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .section-title {
            font-size: 24px;
            margin: 0 0 20px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 10px;
            color: var(--primary-color);
        }

        .bookings-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            border-radius: var(--border-radius-small);
            overflow: hidden;
            box-shadow: var(--shadow-medium);
        }

        .bookings-table th {
            background: rgba(229, 9, 20, 0.8);
            color: var(--text-primary);
            font-weight: 600;
            text-align: left;
            padding: 15px;
            font-size: 14px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .bookings-table td {
            padding: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
            font-size: 15px;
            background: rgba(20, 20, 20, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .bookings-table tr:hover td {
            background: rgba(30, 30, 30, 0.95);
        }

        .status {
            font-weight: 600;
            text-transform: capitalize;
            padding: 4px 8px;
            border-radius: var(--border-radius-small);
            display: inline-block;
            font-size: 12px;
        }

        .status.confirmed {
            color: #28a745;
            background: rgba(40, 167, 69, 0.1);
        }

        .status.pending {
            color: #ffb400;
            background: rgba(255, 180, 0, 0.1);
        }

        .status.cancelled {
            color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
        }

        .status.finished {
            color: #6f42c1;
            background: rgba(111, 66, 193, 0.1);
        }

        .view-all {
            display: inline-block;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            margin-top: 15px;
            transition: color 0.2s;
        }

        .view-all:hover {
            color: var(--primary-hover);
        }

        .edit-form {
            display: none;
            margin-top: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-small);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            font-size: 16px;
        }

        .form-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .save-button {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius-small);
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .save-button:hover {
            background: var(--primary-hover);
        }

        .cancel-button {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-small);
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .cancel-button:hover {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
        }

        .alert {
            padding: 10px 15px;
            border-radius: var(--border-radius-small);
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .no-bookings {
            text-align: center;
            color: var(--text-secondary);
            padding: 20px;
        }

        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .profile-avatar {
                width: 200px;
                height: 200px;
                font-size: 64px;
            }
        }

        .password-button {
            background-color: var(--secondary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            font-weight: 600;
            margin-top: 10px;
            transition: background-color 0.3s;
            display: block;
            width: 100%;
        }

        .password-button:hover {
            background-color: #2563eb;
        }

        /* Password Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: var(--background-card);
            margin: 10% auto;
            padding: 30px;
            border-radius: var(--border-radius);
            max-width: 500px;
            box-shadow: var(--shadow-medium);
            position: relative;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 15px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 22px;
            color: var(--text-primary);
        }

        .close-modal {
            color: var(--text-secondary);
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }

        .close-modal:hover {
            color: var(--text-primary);
        }

        .password-form .form-group {
            margin-bottom: 20px;
        }

        .password-form label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-primary);
            font-weight: 500;
        }

        .password-form input {
            width: 100%;
            padding: 12px;
            border-radius: var(--border-radius-small);
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            font-size: 16px;
        }

        .password-form input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .password-form .form-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 30px;
        }

        .password-form .save-button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s;
        }

        .password-form .save-button:hover {
            background-color: var(--primary-hover);
        }

        .password-form .cancel-button {
            background-color: transparent;
            color: var(--text-secondary);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 12px 20px;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .password-form .cancel-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
        }

        .password-error {
            color: var(--danger-color);
            font-size: 14px;
            margin-top: 5px;
            display: none;
        }

        .password-input-container {
            position: relative;
            width: 100%;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-secondary);
            transition: color 0.2s;
        }

        .toggle-password:hover {
            color: var(--text-primary);
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .profile-header h1 {
                font-size: 36px;
            }

            /* Responsive: stack avatar and name on small screens */
            .profile-identity {
                flex-direction: column;
                align-items: center;
            }

            .profile-avatar {
                width: 160px;
                height: 160px;
                font-size: 54px;
            }

            .profile-name h2 {
                font-size: 28px;
                text-align: center;
            }
        }
    </style>
</head>

<body>
    <?php include '../../shared/components/navbar.php'; ?>

    <main>
        <section class="profile-section">
            <div class="profile-container">
                <div class="profile-header">
                    <h1>My Profile</h1>
                    <p>Manage your personal information and view your booking history</p>
                </div>

                <?php if (!empty($update_message)): ?>
                    <div class="alert alert-<?php echo $update_status; ?>">
                        <?php echo $update_message; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($password_message)): ?>
                    <div class="alert alert-<?php echo $password_status; ?>">
                        <?php echo $password_message; ?>
                    </div>
                <?php endif; ?>

                <div class="profile-grid">
                    <div class="profile-card profile-info">
                        <div class="profile-identity">
                            <div class="profile-avatar" id="clientProfileAvatar">
                                <?php
                                $avatarPath = !empty($client['ProfileImg']) ? getImagePath($client['ProfileImg']) : '';
                                if (!empty($avatarPath)) {
                                    echo '<img src="' . htmlspecialchars($avatarPath) . '" alt="Profile Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
                                } else {
                                    echo htmlspecialchars(substr($client['Name'], 0, 1));
                                }
                                ?>
                            </div>
                        </div>

                        <div class="profile-details">
                            <div class="profile-name">
                                <h2><?php echo htmlspecialchars($client['Name']); ?></h2>
                            </div>
                            <p><?php echo htmlspecialchars($client['Email']); ?></p>
                            <p><?php echo htmlspecialchars($client['Phone']); ?></p>

                            <button id="editProfileBtn" class="edit-button">Edit Profile</button>
                            <button id="changePasswordBtn" class="password-button">Change Password</button>

                            <form id="editProfileForm" class="edit-form" method="POST" action="">
                                <div class="form-group">
                                    <label for="name">Name</label>
                                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($client['Name']); ?>" required>
                                </div>


                                <div class="form-group">
                                    <label for="phone">Phone</label>
                                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($client['Phone']); ?>" required>
                                </div>

                                <div class="form-buttons">
                                    <button type="submit" name="update_profile" class="save-button">Save Changes</button>
                                    <button type="button" id="cancelEditBtn" class="cancel-button">Cancel</button>
                                </div>
                            </form>

                            <form id="avatarUploadForm" class="edit-form" method="POST" action="" enctype="multipart/form-data" style="margin-top:10px;">
                                <div class="form-group">
                                    <label for="avatar">Profile Picture</label>
                                    <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
                                    <small style="color: var(--text-secondary);">Max 2MB. JPG/PNG/WebP only.</small>
                                </div>
                                <div class="form-buttons">
                                    <button type="submit" name="upload_avatar" class="save-button">Upload</button>
                                </div>
                            </form>
                        </div>

                        <div class="stats-grid">
                            <div class="stat-card">
                                <h3><?php echo $booking_stats['total_bookings']; ?></h3>
                                <p>Total Bookings</p>
                            </div>
                            <div class="stat-card">
                                <h3><?php echo $booking_stats['confirmed_bookings']; ?></h3>
                                <p>Confirmed</p>
                            </div>
                            <div class="stat-card">
                                <h3><?php echo $booking_stats['finished_bookings']; ?></h3>
                                <p>Completed</p>
                            </div>
                            <div class="stat-card">
                                <h3><?php echo $booking_stats['cancelled_bookings']; ?></h3>
                                <p>Cancelled</p>
                            </div>
                        </div>
                    </div>

                    <div class="profile-content">
                        <div class="profile-card">
                            <h3 class="section-title">
                                <i class="fas fa-calendar-alt"></i>
                                Recent Bookings
                            </h3>

                            <?php if (empty($recent_bookings)): ?>
                                <div class="no-bookings">
                                    <p>You have no bookings yet. <a href="../php/browse.php" style="color: #e50914;">Browse studios</a> to book now!</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x: auto;">
                                    <table class="bookings-table">
                                        <thead>
                                            <tr>
                                                <th>Studio</th>
                                                <th>Service</th>
                                                <th>Date</th>
                                                <th>Time</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_bookings as $booking): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($booking['StudioName']); ?></td>
                                                    <td><?php echo htmlspecialchars($booking['ServiceType']); ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($booking['Sched_Date'])); ?></td>
                                                    <td><?php echo date('h:i A', strtotime($booking['Time_Start'])) . ' - ' . date('h:i A', strtotime($booking['Time_End'])); ?></td>
                                                    <td>
                                                        <span class="status <?php echo strtolower($booking['status']); ?>">
                                                            <?php echo $booking['status']; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <a href="client_bookings.php" class="view-all">View All Bookings <i class="fas fa-arrow-right"></i></a>
                            <?php endif; ?>
                        </div>

                        <div class="profile-card">
                            <h3 class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Account Information
                            </h3>

                            <p style="color: var(--text-secondary); margin-bottom: 20px;">
                                Your account was created on <strong>January 1, 2023</strong>. You can manage your profile information and view your booking history on this page.
                            </p>

                            <h4 style="color: var(--text-primary); margin: 20px 0 10px;">Account Actions</h4>

                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <a href="client_bookings.php" style="text-decoration: none;">
                                    <button class="edit-button">
                                        <i class="fas fa-calendar-alt"></i> My Bookings
                                    </button>
                                </a>

                                <a href="../php/browse.php" style="text-decoration: none;">
                                    <button class="edit-button">
                                        <i class="fas fa-search"></i> Browse Studios
                                    </button>
                                </a>

                                <a href="../../messaging/php/chat_list.php" style="text-decoration: none;">
                                    <button class="edit-button">
                                        <i class="fas fa-comments"></i> Messages
                                    </button>
                                </a>
                            </div>

                            <h4 style="color: var(--danger-color); margin: 30px 0 10px;">Danger Zone</h4>
                            <p style="color: var(--text-secondary); margin-bottom: 15px; font-size: 14px;">
                                Once you delete your account, it will be deactivated and you won't be able to log in anymore. This action can be reversed by contacting support.
                            </p>
                            <button id="deleteAccountBtn" style="background: var(--danger-color); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s;">
                                <i class="fas fa-trash-alt"></i> Delete Account
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php include '../../shared/components/footer.php'; ?>

    <!-- Password Change Modal -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change Password</h3>
                <button class="close-modal">&times;</button>
            </div>
            <form id="passwordForm" class="password-form" method="POST" action="change_password.php">
                <div class="form-group">
                    <label for="currentPassword">Current Password</label>
                    <input type="password" id="currentPassword" name="currentPassword" required>
                    <div id="currentPasswordError" class="password-error"></div>
                </div>
                <div class="form-group">
                    <label for="newPassword">New Password</label>
                    <div class="password-input-container">
                        <input type="password" id="newPassword" name="newPassword" required>
                        <i class="fas fa-eye-slash toggle-password" data-target="newPassword"></i>
                    </div>
                    <div id="newPasswordError" class="password-error"></div>
                </div>
                <div class="form-group">
                    <label for="confirmPassword">Confirm New Password</label>
                    <div class="password-input-container">
                        <input type="password" id="confirmPassword" name="confirmPassword" required>
                        <i class="fas fa-eye-slash toggle-password" data-target="confirmPassword"></i>
                    </div>
                    <div id="confirmPasswordError" class="password-error"></div>
                </div>
                <div class="form-buttons">
                    <button type="button" class="cancel-button">Cancel</button>
                    <button type="submit" class="save-button">Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div id="deleteAccountModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h3 style="color: var(--danger-color);">Delete Account</h3>
                <button class="close-modal" id="closeDeleteModal">&times;</button>
            </div>
            <div style="padding: 20px 0;">
                <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <p style="color: var(--danger-color); margin: 0; font-weight: 600;">
                        <i class="fas fa-exclamation-triangle"></i> Warning: This action will deactivate your account
                    </p>
                </div>
                
                <p style="color: var(--text-secondary); margin-bottom: 15px;">
                    Your account will be marked as inactive and you will no longer be able to log in. Your data will be preserved and can be reactivated by contacting support.
                </p>

                <p style="color: var(--text-secondary); margin-bottom: 20px;">
                    Please enter your password to confirm account deletion:
                </p>

                <form id="deleteAccountForm" method="POST" action="delete_account.php">
                    <div class="form-group">
                        <label for="deletePassword" style="color: var(--text-primary);">Password</label>
                        <div class="password-input-container">
                            <input type="password" id="deletePassword" name="password" required style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1); background: rgba(255, 255, 255, 0.05); color: var(--text-primary); font-size: 16px;">
                            <i class="fas fa-eye-slash toggle-password" data-target="deletePassword"></i>
                        </div>
                        <div id="deletePasswordError" class="password-error"></div>
                    </div>

                    <div class="form-buttons" style="margin-top: 25px;">
                        <button type="button" id="cancelDeleteBtn" class="cancel-button">Cancel</button>
                        <button type="submit" style="background: var(--danger-color); color: white; border: none; border-radius: 8px; padding: 12px 20px; font-size: 16px; cursor: pointer; font-weight: 600; transition: all 0.3s;">
                            <i class="fas fa-trash-alt"></i> Delete My Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const editProfileBtn = document.getElementById('editProfileBtn');
            const editProfileForm = document.getElementById('editProfileForm');
            const cancelEditBtn = document.getElementById('cancelEditBtn');
            const profileDetails = document.querySelector('.profile-details');
            const avatarUploadForm = document.getElementById('avatarUploadForm');

            editProfileBtn.addEventListener('click', function() {
                editProfileBtn.style.display = 'none';
                editProfileForm.style.display = 'block';
                if (avatarUploadForm) avatarUploadForm.style.display = 'block';
            });

            cancelEditBtn.addEventListener('click', function() {
                editProfileBtn.style.display = 'block';
                editProfileForm.style.display = 'none';
                if (avatarUploadForm) avatarUploadForm.style.display = 'none';
            });

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            if (alerts.length > 0) {
                setTimeout(function() {
                    alerts.forEach(alert => {
                        alert.style.opacity = '0';
                        alert.style.transition = 'opacity 0.5s';
                        setTimeout(() => alert.style.display = 'none', 500);
                    });
                }, 5000);
            }

            // Password Change Modal
            const passwordModal = document.getElementById('passwordModal');
            const changePasswordBtn = document.getElementById('changePasswordBtn');
            const closeModalBtn = document.querySelector('.close-modal');
            const cancelBtn = document.querySelector('.password-form .cancel-button');
            const passwordForm = document.getElementById('passwordForm');

            // Open modal
            if (changePasswordBtn && passwordModal) {
                changePasswordBtn.addEventListener('click', function() {
                    passwordModal.style.display = 'block';
                });
            }

            // Close modal functions
            function closeModal() {
                passwordModal.style.display = 'none';
                passwordForm.reset();
                // Clear error messages
                document.querySelectorAll('.password-error').forEach(error => {
                    error.style.display = 'none';
                    error.textContent = '';
                });
            }

            // Close with X button
            if (closeModalBtn) {
                closeModalBtn.addEventListener('click', closeModal);
            }

            // Close with Cancel button
            if (cancelBtn) {
                cancelBtn.addEventListener('click', closeModal);
            }

            // Close when clicking outside the modal
            window.addEventListener('click', function(event) {
                if (event.target === passwordModal) {
                    closeModal();
                }
            });

            // Toggle password visibility
            const togglePasswordBtns = document.querySelectorAll('.toggle-password');
            if (togglePasswordBtns) {
                togglePasswordBtns.forEach(btn => {
                    btn.addEventListener('click', function() {
                        const targetId = this.getAttribute('data-target');
                        const passwordInput = document.getElementById(targetId);

                        if (passwordInput.type === 'password') {
                            passwordInput.type = 'text';
                            this.classList.remove('fa-eye-slash');
                            this.classList.add('fa-eye');
                        } else {
                            passwordInput.type = 'password';
                            this.classList.remove('fa-eye');
                            this.classList.add('fa-eye-slash');
                        }
                    });
                });
            }

            // Password form validation
            if (passwordForm) {
                const currentPasswordInput = document.getElementById('currentPassword');
                const currentPasswordError = document.getElementById('currentPasswordError');

                // Real-time current password validation
                if (currentPasswordInput) {
                    // Add debounce to avoid too many requests
                    let timer;
                    currentPasswordInput.addEventListener('input', function() {
                        const password = this.value.trim();

                        // Clear previous timer
                        clearTimeout(timer);

                        // Don't validate empty password in real-time
                        if (password === '') {
                            currentPasswordError.style.display = 'none';
                            return;
                        }

                        // Set a timer to delay the validation
                        timer = setTimeout(function() {
                            // Create form data
                            const formData = new FormData();
                            formData.append('current_password', password);

                            // Send AJAX request
                            fetch('verify_password.php', {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.valid) {
                                        currentPasswordError.textContent = '✓ Password is correct';
                                        currentPasswordError.style.color = '#28a745';
                                        currentPasswordError.style.display = 'block';
                                    } else {
                                        currentPasswordError.textContent = '✗ ' + data.message;
                                        currentPasswordError.style.color = '#dc3545';
                                        currentPasswordError.style.display = 'block';
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                });
                        }, 500); // 500ms delay
                    });
                }

                passwordForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const currentPassword = document.getElementById('currentPassword').value;
                    const newPassword = document.getElementById('newPassword').value;
                    const confirmPassword = document.getElementById('confirmPassword').value;

                    const currentPasswordError = document.getElementById('currentPasswordError');
                    const newPasswordError = document.getElementById('newPasswordError');
                    const confirmPasswordError = document.getElementById('confirmPasswordError');

                    // Reset error messages
                    currentPasswordError.style.display = 'none';
                    newPasswordError.style.display = 'none';
                    confirmPasswordError.style.display = 'none';

                    let isValid = true;

                    // Validate current password (we'll check this on the server)
                    if (!currentPassword) {
                        currentPasswordError.textContent = 'Please enter your current password';
                        currentPasswordError.style.display = 'block';
                        isValid = false;
                    }

                    // Validate new password (minimum 8 characters)
                    if (newPassword.length < 8) {
                        newPasswordError.textContent = 'Password must be at least 8 characters';
                        newPasswordError.style.display = 'block';
                        isValid = false;
                    }

                    // Validate password confirmation
                    if (newPassword !== confirmPassword) {
                        confirmPasswordError.textContent = 'Passwords do not match';
                        confirmPasswordError.style.display = 'block';
                        isValid = false;
                    }

                    if (isValid) {
                        // Submit the form
                        this.submit();
                    }
                });
            }

            // Delete Account Modal
            const deleteAccountModal = document.getElementById('deleteAccountModal');
            const deleteAccountBtn = document.getElementById('deleteAccountBtn');
            const closeDeleteModal = document.getElementById('closeDeleteModal');
            const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
            const deleteAccountForm = document.getElementById('deleteAccountForm');

            // Open delete modal
            if (deleteAccountBtn && deleteAccountModal) {
                deleteAccountBtn.addEventListener('click', function() {
                    deleteAccountModal.style.display = 'block';
                });
            }

            // Close delete modal functions
            function closeDeleteAccountModal() {
                deleteAccountModal.style.display = 'none';
                deleteAccountForm.reset();
                document.getElementById('deletePasswordError').style.display = 'none';
            }

            // Close with X button
            if (closeDeleteModal) {
                closeDeleteModal.addEventListener('click', closeDeleteAccountModal);
            }

            // Close with Cancel button
            if (cancelDeleteBtn) {
                cancelDeleteBtn.addEventListener('click', closeDeleteAccountModal);
            }

            // Close when clicking outside the modal
            window.addEventListener('click', function(event) {
                if (event.target === deleteAccountModal) {
                    closeDeleteAccountModal();
                }
            });

            // Delete account form submission
            if (deleteAccountForm) {
                deleteAccountForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    if (confirm('Are you absolutely sure? This will deactivate your account and you will no longer be able to log in.')) {
                        this.submit();
                    }
                });
            }
        });
    </script>
</body>

</html>
        /* Explicit larger size for profile page avatar */
        #clientProfileAvatar {
            width: 360px;
            height: 360px;
            min-width: 360px;
            min-height: 360px;
            font-size: 84px;
        }