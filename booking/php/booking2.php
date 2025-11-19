<?php
session_start(); // Start the session
include '../../shared/config/db.php';
require_once '../../shared/config/path_config.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo "<script>
        alert('Please log in to continue.');
        window.location.href = '../../auth/php/login.html';
    </script>";
    exit;
}

// Get parameters from previous step
$studio_id = 0;
$services_data = [];
$equipment_data = [];
$from_confirm = isset($_GET['from_confirm']) ? (bool)$_GET['from_confirm'] : false;

// If coming from booking.php (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studio_id = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
    
    // Decode JSON data
    if (isset($_POST['services_data']) && !empty($_POST['services_data'])) {
        $services_data = json_decode($_POST['services_data'], true);
    }
    if (isset($_POST['equipment_data']) && !empty($_POST['equipment_data'])) {
        $equipment_data = json_decode($_POST['equipment_data'], true);
    }
    
    // Store in session for later steps
    if (!$from_confirm) {
        $_SESSION['booking_services'] = $services_data;
        $_SESSION['booking_equipment'] = $equipment_data;
        $_SESSION['booking_studio_id'] = $studio_id;
    }
}
// If coming from confirmation page (GET) or navigating back
else {
    $studio_id = $_SESSION['booking_studio_id'] ?? 0;
    $services_data = $_SESSION['booking_services'] ?? [];
    $equipment_data = $_SESSION['booking_equipment'] ?? [];
}

// Store current studio in session for tracking
$_SESSION['booking_studio_id'] = $studio_id;

// Debug logging
error_log("Booking2: Studio ID = $studio_id, from_confirm = " . ($from_confirm ? 'true' : 'false'));
error_log("Booking2: Current slots in session: " . count($_SESSION['selected_slots'] ?? []));
error_log("Booking2: Session slots data: " . json_encode($_SESSION['selected_slots'] ?? []));

// Only filter slots if user is switching to a DIFFERENT studio
// This preserves slots when adding more bookings to the same studio
if (!$from_confirm && $studio_id > 0) {
    $existing = $_SESSION['selected_slots'] ?? [];
    $lastStudio = $_SESSION['last_booking_studio_id'] ?? $studio_id;
    
    if ($lastStudio !== $studio_id && !empty($existing)) {
        // User switched studios - retain only slots for the NEW studio
        error_log("Booking2: Studio changed from $lastStudio to $studio_id, filtering slots");
        $_SESSION['selected_slots'] = array_values(array_filter($existing, function($slot) use ($studio_id) {
            return (int)($slot['studio_id'] ?? 0) === (int)$studio_id;
        }));
    }
}

// Remember the last studio user was booking
$_SESSION['last_booking_studio_id'] = $studio_id;

// Validate parameters
if ($studio_id <= 0 || empty($services_data)) {
    header("Location: ../../client/php/browse.php");
    exit;
}

// Fetch studio details
$studio_query = "SELECT StudioID, StudioName, Loc_Desc, StudioImg, Time_IN, Time_OUT FROM studios WHERE StudioID = ?";
$stmt = mysqli_prepare($conn, $studio_query);
mysqli_stmt_bind_param($stmt, "i", $studio_id);
mysqli_stmt_execute($stmt);
$studio_result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($studio_result)) {
    $row['StudioLetter'] = strtoupper(substr($row['StudioName'], 0, 1));
    if (!empty($row['StudioImg'])) {
        $row['StudioImgSrc'] = (strpos($row['StudioImg'], 'http') === 0 || strpos($row['StudioImg'], '/') === 0)
            ? $row['StudioImg']
            : getBasePath() . $row['StudioImg'];
    }
    $studio = $row;
} else {
    header("Location: browse.php");
    exit;
}
mysqli_stmt_close($stmt);

// Fetch full service details for all selected services
$services = [];
$total_service_price = 0;

foreach ($services_data as $service_item) {
    $service_id = (int)$service_item['service_id'];
    $instructor_id = isset($service_item['instructor_id']) ? (int)$service_item['instructor_id'] : 0;
    
    $service_query = "SELECT ServiceID, ServiceType, `Description`, Price FROM services WHERE ServiceID = ?";
    $stmt = mysqli_prepare($conn, $service_query);
    mysqli_stmt_bind_param($stmt, "i", $service_id);
    mysqli_stmt_execute($stmt);
    $service_result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($service_result)) {
        $service_data = $row;
        $service_data['instructor_id'] = $instructor_id;
        $service_data['instructor_name'] = null;
        
        // Fetch instructor details if selected
        if ($instructor_id > 0) {
            $instructor_query = "SELECT InstructorID, Name FROM instructors WHERE InstructorID = ?";
            $inst_stmt = mysqli_prepare($conn, $instructor_query);
            mysqli_stmt_bind_param($inst_stmt, "i", $instructor_id);
            mysqli_stmt_execute($inst_stmt);
            $instructor_result = mysqli_stmt_get_result($inst_stmt);
            
            if ($inst_row = mysqli_fetch_assoc($instructor_result)) {
                $service_data['instructor_name'] = $inst_row['Name'];
            }
            mysqli_stmt_close($inst_stmt);
        }
        
        $services[] = $service_data;
        $total_service_price += (float)$row['Price'];
    }
    mysqli_stmt_close($stmt);
}

// If no valid services found, redirect back
if (empty($services)) {
    header("Location: booking.php?studio_id=" . $studio_id);
    exit;
}

// Calculate total equipment price
$total_equipment_price = 0;
$equipment_items = [];

if (!empty($equipment_data)) {
    foreach ($equipment_data as $service_id => $equipments) {
        foreach ($equipments as $equipment_id => $quantity) {
            if ($quantity > 0) {
                $eq_query = "SELECT equipment_id, equipment_name, rental_price FROM equipment_addons WHERE equipment_id = ?";
                $eq_stmt = mysqli_prepare($conn, $eq_query);
                mysqli_stmt_bind_param($eq_stmt, "i", $equipment_id);
                mysqli_stmt_execute($eq_stmt);
                $eq_result = mysqli_stmt_get_result($eq_stmt);
                
                if ($eq_row = mysqli_fetch_assoc($eq_result)) {
                    $item_total = (float)$eq_row['rental_price'] * (int)$quantity;
                    $total_equipment_price += $item_total;
                    
                    $equipment_items[] = [
                        'service_id' => $service_id,
                        'equipment_id' => $equipment_id,
                        'equipment_name' => $eq_row['equipment_name'],
                        'rental_price' => $eq_row['rental_price'],
                        'quantity' => $quantity,
                        'total' => $item_total
                    ];
                }
                mysqli_stmt_close($eq_stmt);
            }
        }
    }
}

// Fetch existing bookings for this studio
$bookings_query = "SELECT 
    s.ScheduleID,
    DATE_FORMAT(s.Sched_Date, '%Y-%m-%d') AS Sched_Date, 
    DATE_FORMAT(s.Time_Start, '%H:%i:00') AS Time_Start, 
    DATE_FORMAT(s.Time_End, '%H:%i:00') AS Time_End
FROM 
    schedules s
WHERE s.StudioID = ?";
$stmt = mysqli_prepare($conn, $bookings_query);
mysqli_stmt_bind_param($stmt, "i", $studio_id);
mysqli_stmt_execute($stmt);
$bookings_result = mysqli_stmt_get_result($stmt);

$bookings = [];
while ($row = mysqli_fetch_assoc($bookings_result)) {
    $bookings[] = $row;
}
mysqli_stmt_close($stmt);

// Send bookings data to the client-side
$bookings_json = json_encode($bookings);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Browse Studios - MuSeek</title>
    <!-- Loading third party fonts -->
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <!-- Loading main css file -->
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        #branding img {
            width: 180px;
            display: block; 
        }
    
        .section-title {
            margin-left: 20px;
        }
        /* Progress Bar Styles */
        .booking-progress {
            display: flex;
            justify-content: space-between;
            max-width: 800px;
            margin: 0 auto 40px;
            position: relative;
            z-index: 5;
        }

        .booking-progress::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: rgba(255, 255, 255, 0.3);
            z-index: -1;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: rgba(255, 255, 255, 0.6);
            width: 25%;
        }

        .progress-step.active {
            color: #fff;
        }

        .progress-step.completed {
            color: #e50914;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .progress-step.active .step-number {
            background: #e50914;
            border-color: #fff;
        }

        .progress-step.completed .step-number {
            background: #333;
            border-color: #e50914;
        }

        .step-label {
            font-size: 14px;
            text-align: center;
        }

        /* Studio Header Styles */
        .studio-header {
            display: flex;
            align-items: center;
            margin-bottom: 16px;
        }

        .studio-header img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 10px;
            margin-right: 20px;
            border: 2px solid #eee;
            background: #fff;
        }
        .studio-header .letter-avatar {
            width: 120px;
            height: 120px;
            border-radius: 10px;
            margin-right: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #eee;
            background: linear-gradient(135deg, #222 60%, #444 140%);
            color: #fff;
            font-size: 48px;
            font-weight: 700;
        }

        .studio-header h3 {
            color: white;
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .studio-location {
            color: #ccc;
            margin-top: 5px;
            font-size: 14px;
        }

        .studio-location i {
            margin-right: 5px;
        }

        /* Booking Section Styles */
        .fullwidth-block.booking-section {
            background: linear-gradient(135deg, #222 60%, #e50914 200%);
            padding: 40px 0 60px 0;
        }

        .booking-container {
            display: flex;
            gap: 48px;
            justify-content: center;
            align-items: flex-start;
            margin-top: 40px;
            flex-wrap: nowrap;
            max-width: 1400px;
            margin: 40px auto 0;
        }

        .booking-card {
            width: 56%;
            min-width: 320px;
            background: linear-gradient(180deg, rgba(25,25,25,0.95), rgba(15,15,15,0.9));
            backdrop-filter: blur(6px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 24px 26px;
            box-shadow: 0 10px 28px rgba(0,0,0,0.35);
            margin-bottom: 24px;
            box-sizing: border-box;
            max-height: 92vh;
            overflow-y: auto;
            transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
        }
        .booking-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 36px rgba(0,0,0,0.45);
            border-color: rgba(255, 255, 255, 0.12);
        }

        .booking-step-title {
            color: #fff;
            margin: 20px 0;
            font-size: 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 10px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .input-group {
            margin-bottom: 15px;
        }

        .time-slots {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .time-slots label {
            color: #fff;
            font-size: 14px;
        }

        .time-slots input[type="text"] {
            flex: 1;
            padding: 10px;
            background: #333;
            border: 1px solid #444;
            color: #ccc;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s ease;
            text-align: center;
        }

        .time-slots input[type="text"]:hover {
            background: #444;
            border-color: #e50914;
        }
        
        .time-slots input[type="text"]::placeholder {
            color: #666;
            font-style: italic;
        }

        label {
            color: #fff;
            font-size: 14px;
            display: block;
            margin-bottom: 5px;
        }

        input[type="text"] {
            width: 100%;
            padding: 10px;
            background: #333;
            border: 1px solid #444;
            color: #ccc;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .booking-instructions {
            margin: 20px 0 30px;
        }
        
        .instructions-box {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .instructions-title {
            color: #fff;
            margin: 0 0 8px 0;
            font-size: 14px;
            font-weight: 500;
        }
        
        .instructions-list {
            color: #ddd;
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .studio-image {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .studio-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .studio-info {
            flex: 1;
            padding-left: 20px;
        }
        
        .studio-header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .studio-header h3 {
            margin: 0 0 5px 0;
            color: #fff;
            font-size: 22px;
        }
        
        .studio-location {
            color: #aaa;
            margin: 0;
            font-size: 14px;
        }
        
        .booking-step-title {
            color: #fff;
            font-size: 18px;
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .button {
            padding: 10px 20px;
            font-size: 14px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            text-align: center;
            display: inline-block;
            text-decoration: none;
        }

        .button:hover {
            background-color: #f40612;
        }

        .button.secondary {
            background-color: #666;
        }

        .button.secondary:hover {
            background-color: #777;
        }

        #nextStepBtn {
            padding: 10px 20px;
            font-size: 14px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        #nextStepBtn:hover {
            background-color: #f40612;
        }

        #nextStepBtn:disabled {
            background-color: #888;
            cursor: not-allowed;
        }

        /* Calendar Styles */
        .calendar-container {
            height: auto;
            min-height: clamp(720px, 78vh, 900px);
            width: 44%;
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
            color: #333;
            overflow-y: visible;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Custom Calendar Styles */
        .calendar-view {
            height: clamp(600px, 64vh, 760px);
            max-width: 100%;
            margin: 0;
            border: 1px solid #ccc;
            border-radius: 5px;
            padding: 10px;
            background: #f8f8f8;
            overflow: visible;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .calendar-header h4 {
            margin: 0;
            font-size: 16px;
        }

        .calendar-header button {
            padding: 5px 10px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        .calendar-header button:hover {
            background-color: #f40612;
        }

        /* Month View Styles */
        .month-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .month-header button {
            background: none;
            border: none;
            color: #e50914;
            cursor: pointer;
            font-size: 14px;
        }

        .month-header button:hover {
            color: #f40612;
        }

        .month-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
        }

        .weekday-header {
            text-align: center;
            font-weight: 600;
            padding: 8px 0;
            font-size: 14px;
        }

        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            position: relative;
        }

        .calendar-day:hover {
            background-color: #f0f0f0;
        }

        .calendar-day.selected {
            background-color: #e50914;
            color: white;
        }

        .calendar-day.disabled {
            color: #ccc;
            cursor: not-allowed;
        }

        .calendar-day.has-events::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background-color: #e50914;
        }

        .calendar-day.empty {
            visibility: hidden;
        }

        /* Day View Styles */
        .day-header {
            margin-bottom: 15px;
            font-weight: 600;
            position: relative;
        }
        
        .selection-actions {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
        }
        
        .clear-btn {
            background: #e50914;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
        }
        
        .clear-btn:hover {
            background: #f40612;
        }

        .time-slots-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin: 15px 0;
            max-height: 350px;
            overflow-y: auto;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
        }
        
        .clear-btn:hover {
            background: #f40612;
        }

        .time-slots-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin: 15px 0;
            max-height: 350px;
            overflow-y: auto;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            scrollbar-width: thin;
            scrollbar-color: #ccc #f5f5f5;
            position: relative;
            box-sizing: border-box;
        }
        
        /* Custom scrollbar for Webkit browsers */
        .time-slots-container::-webkit-scrollbar {
            width: 8px;
        }
        
        .time-slots-container::-webkit-scrollbar-track {
            background: #f5f5f5;
            border-radius: 4px;
        }
        
        .time-slots-container::-webkit-scrollbar-thumb {
            background-color: #ccc;
            border-radius: 4px;
        }
        
        .time-slots-container::-webkit-scrollbar-thumb:hover {
            background-color: #aaa;
        }

        .time-slot {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 15px 10px;
            border-radius: 6px;
            border: 2px solid #e0e0e0;
            background-color: #ffffff;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 50px;
            text-align: center;
            font-size: 15px;
            font-weight: 500;
            color: #333;
        }

        .time-slot:hover:not(.disabled) {
            background-color: #e9ecef;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-color: #2196F3;
        }

        .time-slot.selected-start {
            border: 2px solid #4CAF50;
            background-color: rgba(76, 175, 80, 0.1);
            font-weight: 600;
            color: #2E7D32;
        }

        .time-slot.selected-end {
            border: 2px solid #2196F3;
            background-color: rgba(33, 150, 243, 0.1);
            font-weight: 600;
            color: #1565C0;
        }
        
        .time-slot.available-end {
            border: 2px dashed #2196F3;
            background-color: rgba(33, 150, 243, 0.05);
        }
        
        .time-slot.available-end:hover {
            background-color: rgba(33, 150, 243, 0.1);
        }

        .time-slot.disabled {
            background-color: #f8f9fa;
            color: #adb5bd;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .time-slot-time {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            width: 100%;
        }

        .time-slot-time i {
            font-size: 16px;
        }

        .time-slot-time span {
            font-size: 14px;
            white-space: nowrap;
        }

        /* Responsive Styles */
        @media (max-width: 768px) {
            .booking-container {
                flex-direction: column;
            }
            
            .booking-card, .calendar-container {
                width: 100%;
                margin-bottom: 20px;
            }

            /* Mobile overrides for calendar heights */
            .calendar-container { height: auto; }
            .calendar-view { height: clamp(420px, 60vh, 620px); }
            
            .booking-progress {
                padding: 0 15px;
            }
            
            .step-label {
                font-size: 12px;
            }
            
            .studio-header img {
                width: 80px;
                height: 80px;
            }
        }
    </style>
</head>
<body>
    <?php include '../../shared/components/navbar.php'; ?>

    <main class="main-content">
        <div class="fullwidth-block booking-section">
            <div class="booking-progress">
                <div class="progress-step completed">
                    <div class="step-number">1</div>
                    <div class="step-label">Select Service</div>
                </div>
                <div class="progress-step active">
                    <div class="step-number">2</div>
                    <div class="step-label">Choose Date & Time</div>
                </div>
                <div class="progress-step">
                    <div class="step-number">3</div>
                    <div class="step-label">Confirm Booking</div>
                </div>
                <div class="progress-step">
                    <div class="step-number">4</div>
                    <div class="step-label">Payment</div>
                </div>
            </div>

            <h2 class="section-title">Book Your Studio</h2>
            <div class="booking-container">
                <div class="booking-card">
                    <div class="studio-header">
                        <?php if (!empty($studio['StudioImgSrc'])): ?>
                            <img src="<?php echo $studio['StudioImgSrc']; ?>" alt="<?php echo htmlspecialchars($studio['StudioName']); ?>">
                        <?php else: ?>
                            <div class="letter-avatar"><?php echo htmlspecialchars($studio['StudioLetter']); ?></div>
                        <?php endif; ?>
                        <div>
                            <h3><?php echo htmlspecialchars($studio['StudioName']); ?></h3>
                            <p class="studio-location"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
                        </div>
                    </div>
                    
                    <div class="booking-instructions">
                        <h4 class="booking-step-title">Step 2: Choose Date & Time</h4>
                            <div class="instructions-box">
                                <p class="instructions-title">📅 <strong>How to book:</strong></p>
                                <ol class="instructions-list">
                                    <li>Click on an available date in the calendar</li>
                                    <li>Select your preferred time slot from the available options</li>
                                    <li>Available slots will be highlighted in blue</li>
                                    <li>Click "Continue to Confirmation" when done</li>
                                </ol>
                            </div>
                    </div>
                    
                    <form id="dateTimeForm" action="booking3.php" method="POST">
                        <input type="hidden" name="studio_id" value="<?php echo $studio_id; ?>">
                        <input type="hidden" name="services_data" value='<?php echo htmlspecialchars(json_encode($services_data), ENT_QUOTES); ?>'>
                        <input type="hidden" name="equipment_data" value='<?php echo htmlspecialchars(json_encode($equipment_data), ENT_QUOTES); ?>'>
                        
                        <!-- Display Selected Services and Instructors -->
                        <div style="background: rgba(229, 9, 20, 0.1); border: 1px solid rgba(229, 9, 20, 0.3); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                            <h4 style="color: #fff; margin: 0 0 12px; font-size: 16px; font-weight: 600;">
                                <i class="fas fa-check-circle" style="color: #e50914;"></i> Selected Services
                            </h4>
                            <?php foreach ($services as $service): ?>
                                <div style="background: rgba(0, 0, 0, 0.3); border-radius: 6px; padding: 10px; margin-bottom: 8px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <p style="color: #fff; margin: 0 0 4px; font-weight: 600;">
                                                <?php echo htmlspecialchars($service['ServiceType']); ?>
                                            </p>
                                            <?php if ($service['instructor_name']): ?>
                                                <p style="color: #ccc; margin: 0; font-size: 13px;">
                                                    <i class="fas fa-user-tie"></i> Instructor: <?php echo htmlspecialchars($service['instructor_name']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <span style="color: #e50914; font-weight: 600; font-size: 16px;">
                                            ₱<?php echo number_format($service['Price'], 2); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (!empty($equipment_items)): ?>
                                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255, 255, 255, 0.1);">
                                    <p style="color: #ccc; margin: 0 0 8px; font-size: 14px; font-weight: 600;">
                                        <i class="fas fa-tools"></i> Equipment Rentals
                                    </p>
                                    <?php foreach ($equipment_items as $equipment): ?>
                                        <p style="color: #999; margin: 0 0 4px; font-size: 13px;">
                                            • <?php echo htmlspecialchars($equipment['equipment_name']); ?> (x<?php echo $equipment['quantity']; ?>) - ₱<?php echo number_format($equipment['total'], 2); ?>
                                        </p>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255, 255, 255, 0.2); display: flex; justify-content: space-between; align-items: center;">
                                <strong style="color: #fff; font-size: 16px;">Total:</strong>
                                <strong style="color: #e50914; font-size: 18px;">
                                    ₱<?php echo number_format($total_service_price + $total_equipment_price, 2); ?>
                                </strong>
                            </div>
                        </div>
                        
                        <?php 
                        // Show user's pending bookings for this studio
                        $userSlots = array_filter($_SESSION['selected_slots'] ?? [], function($slot) use ($studio_id) {
                            return (int)($slot['studio_id'] ?? 0) === (int)$studio_id;
                        });
                        if (!empty($userSlots)):
                        ?>
                        <div style="background: rgba(255, 193, 7, 0.15); border: 1px solid rgba(255, 193, 7, 0.4); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                            <h4 style="color: #fff; margin: 0 0 10px; font-size: 14px; font-weight: 600;">
                                <i class="fas fa-info-circle" style="color: #ffc107;"></i> Your Pending Bookings (<?php echo count($userSlots); ?>)
                            </h4>
                            <?php foreach ($userSlots as $slot): 
                                $slotDate = new DateTime($slot['date']);
                                $slot_services = $slot['services'] ?? [];
                                $slot_equipment = $slot['equipment'] ?? [];
                            ?>
                                <div style="background: rgba(0, 0, 0, 0.3); border-radius: 6px; padding: 10px; margin-bottom: 8px; border-left: 3px solid #ffc107;">
                                    <div style="font-size: 13px; color: #fff; margin-bottom: 6px; font-weight: 600;">
                                        📅 <?php echo $slotDate->format('M j, Y'); ?> 
                                        🕐 <?php echo date('g:i A', strtotime($slot['start'])); ?> - <?php echo date('g:i A', strtotime($slot['end'])); ?>
                                    </div>
                                    
                                    <?php if (!empty($slot_services)): ?>
                                    <div style="font-size: 12px; color: #ddd; margin-top: 4px; padding-left: 16px;">
                                        <strong style="color: #ffc107;">Services:</strong>
                                        <?php 
                                        $service_names = [];
                                        foreach ($slot_services as $svc) {
                                            $svc_id = (int)$svc['service_id'];
                                            // Fetch service name
                                            $svc_query = "SELECT ServiceType FROM services WHERE ServiceID = ?";
                                            $svc_stmt = mysqli_prepare($conn, $svc_query);
                                            mysqli_stmt_bind_param($svc_stmt, "i", $svc_id);
                                            mysqli_stmt_execute($svc_stmt);
                                            $svc_result = mysqli_stmt_get_result($svc_stmt);
                                            if ($svc_row = mysqli_fetch_assoc($svc_result)) {
                                                $service_names[] = htmlspecialchars($svc_row['ServiceType']);
                                            }
                                            mysqli_stmt_close($svc_stmt);
                                        }
                                        echo implode(', ', $service_names);
                                        ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($slot_equipment)): 
                                        $eq_count = 0;
                                        foreach ($slot_equipment as $equipments) {
                                            foreach ($equipments as $qty) {
                                                if ($qty > 0) $eq_count++;
                                            }
                                        }
                                        if ($eq_count > 0):
                                    ?>
                                    <div style="font-size: 12px; color: #ddd; margin-top: 4px; padding-left: 16px;">
                                        <strong style="color: #ffc107;">Equipment:</strong> <?php echo $eq_count; ?> item<?php echo $eq_count > 1 ? 's' : ''; ?>
                                    </div>
                                    <?php endif; endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #ccc;">
                                ⚠️ These time slots are reserved and will appear as unavailable in the calendar. You cannot book overlapping times.
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <div class="input-group">
                                <label for="date">Selected Date:</label>
                                <input type="text" id="date" name="date" readonly required>
                            </div>
                            <div class="time-slots" id="timeSlots">
                                <label for="timeStart">Selected Time:</label>
                                <div style="display: flex; gap: 15px;">
                                    <input type="text" id="timeStartDisplay" readonly required placeholder="Start">
                                    <input type="hidden" id="timeStart" name="timeStart">
                                    <input type="text" id="timeEndDisplay" readonly required placeholder="End">
                                    <input type="hidden" id="timeEnd" name="timeEnd">
                                </div>
                            </div>
                            <div id="availabilityMessage" style="margin-top: 10px; color: #fff;"></div>
                        </div>
                        
                        <div class="form-actions">
                            <a href="booking.php?studio_id=<?php echo $studio_id; ?>" class="button secondary">Back</a>
                            <button type="submit" id="nextStepBtn" disabled>Continue to Confirmation</button>
                        </div>
                    </form>
                </div>
                
                <div class="calendar-container" id="calendar">
                    <div class="calendar-header">
                        <h4>Select Date and Time</h4>
                        <button id="changeDateBtn" style="display: none;" onclick="changeView('month')">Change Date</button>
                    </div>
                    <div id="calendarView" class="calendar-view">
                        <!-- Calendar will be rendered here -->
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include '../../shared/components/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Get DOM elements
    const calendarView = document.getElementById('calendarView');
    const dateInput = document.getElementById('date');
    const timeStartInput = document.getElementById('timeStart');
    const timeEndInput = document.getElementById('timeEnd');
    const timeStartDisplay = document.getElementById('timeStartDisplay');
    const timeEndDisplay = document.getElementById('timeEndDisplay');
    const changeDateBtn = document.getElementById('changeDateBtn');
    const nextStepBtn = document.getElementById('nextStepBtn');
    const availabilityMessage = document.getElementById('availabilityMessage');

    // Studio operating hours (from PHP)
    const timeIn = "<?php echo $studio['Time_IN']; ?>";
    const timeOut = "<?php echo $studio['Time_OUT']; ?>";

    // Parse existing bookings from PHP
    const existingBookings = <?php echo json_encode($bookings); ?>;

    // Current date and view state
    const currentDate = new Date();
    let selectedDate = new Date();
    let currentView = 'month';
    let selectedStartTime = null;
    let selectedEndTime = null;
    
    // Store the user's selected time slots from PHP session (filtered to current studio)
    const selectedSlots = <?php
        $rawSlots = isset($_SESSION['selected_slots']) && is_array($_SESSION['selected_slots']) ? $_SESSION['selected_slots'] : [];
        error_log("Booking2 JS: Raw slots count before filter: " . count($rawSlots));
        $filtered = array_values(array_filter($rawSlots, function($slot) use ($studio_id) {
            $slot_studio = isset($slot['studio_id']) ? (int)$slot['studio_id'] : 0;
            $matches = $slot_studio === (int)$studio_id;
            error_log("Booking2 JS: Slot studio $slot_studio vs current $studio_id = " . ($matches ? 'MATCH' : 'NO MATCH'));
            return $matches;
        }));
        error_log("Booking2 JS: Filtered slots count for studio $studio_id: " . count($filtered));
        echo json_encode($filtered);
    ?>;

    // Log booking data for debugging
    console.log('=== BOOKING2 DEBUG INFO ===');
    console.log('Studio ID:', <?php echo $studio_id; ?>);
    console.log('Total user\'s pending bookings for this studio:', selectedSlots.length);
    console.log('Pending bookings details:');
    selectedSlots.forEach((slot, index) => {
        const serviceCount = slot.services ? slot.services.length : 0;
        const equipmentCount = slot.equipment ? Object.keys(slot.equipment).length : 0;
        console.log(`  Booking ${index + 1}: ${slot.date} ${slot.start}-${slot.end}`);
        console.log(`    → ${serviceCount} service(s), ${equipmentCount} equipment type(s)`);
    });
    console.log('Existing bookings from database:', existingBookings);
    console.log('===========================');

    // Initialize calendar
    initializeCalendar();

    // Initialize calendar with the current month
    function initializeCalendar() {
        if (currentView === 'month') {
            renderMonthView(selectedDate);
        } else {
            renderDayView(selectedDate);
        }
    }

    // Change view between month and day
    window.changeView = function(view) {
        currentView = view;
        if (view === 'month') {
            changeDateBtn.style.display = 'none';
            dateInput.value = '';
            timeStartInput.value = '';
            timeEndInput.value = '';
            timeStartDisplay.value = '';
            timeEndDisplay.value = '';
            nextStepBtn.disabled = true;
            selectedStartTime = null;
            selectedEndTime = null;
            availabilityMessage.textContent = '';
        }
        initializeCalendar();
    };

    // Render month view
    function renderMonthView(date) {
        const year = date.getFullYear();
        const month = date.getMonth();
        
        const firstDayOfMonth = new Date(year, month, 1);
        const lastDayOfMonth = new Date(year, month + 1, 0);
        
        const daysInMonth = lastDayOfMonth.getDate();
        const firstDayOfWeek = firstDayOfMonth.getDay(); // 0 = Sunday, 1 = Monday, etc.
        
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        
        let html = `
            <div class="month-header">
                <button onclick="navigateMonth(-1)">< Prev</button>
                <h3>${monthNames[month]} ${year}</h3>
                <button onclick="navigateMonth(1)">Next ></button>
            </div>
            <div class="month-grid">
        `;
        
        // Add weekday headers
        const weekdays = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
        weekdays.forEach(day => {
            html += `<div class="weekday-header">${day}</div>`;
        });
        
        // Add empty cells for days before the first day of the month
        for (let i = 0; i < firstDayOfWeek; i++) {
            html += `<div class="calendar-day empty"></div>`;
        }
        
        // Add days of the month
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        // Helper function to check if current time is past studio closing time
        function isPastClosingTime() {
            const now = new Date();
            const [closingHour, closingMinute] = timeOut.split(':').map(Number);
            const closingTime = new Date(
                now.getFullYear(),
                now.getMonth(),
                now.getDate(),
                closingHour,
                closingMinute
            );
            return now >= closingTime;
        }
        
        // Helper function to check if a date is today
        function isToday(date) {
            const today = new Date();
            return date.getDate() === today.getDate() &&
                   date.getMonth() === today.getMonth() &&
                   date.getFullYear() === today.getFullYear();
        }
        
        for (let i = 1; i <= daysInMonth; i++) {
            const dayDate = new Date(year, month, i);
            const isPast = dayDate < today || (isToday(dayDate) && isPastClosingTime());
            const isSelected = dayDate.getDate() === selectedDate.getDate() && 
                              dayDate.getMonth() === selectedDate.getMonth() && 
                              dayDate.getFullYear() === selectedDate.getFullYear();
            
            // Check if this day has any bookings
            const hasEvents = checkDayHasBookings(dayDate);
            
            html += `
                <div class="calendar-day ${isPast ? 'disabled' : ''} ${isSelected ? 'selected' : ''} ${hasEvents ? 'has-events' : ''}"
                     onclick="${isPast ? '' : `selectDay(${i})`}">
                    ${i}
                </div>
            `;
        }
        
        html += `</div>`;
        calendarView.innerHTML = html;
    }

    // Check if a day has any bookings
    function checkDayHasBookings(date) {
        const dateString = formatDate(date);
        return existingBookings.some(booking => {
            return booking.Sched_Date === dateString;
        });
    }

    // Format date as YYYY-MM-DD
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // Format time as HH:MM
    function formatTime(date) {
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${hours}:${minutes}`;
    }

    // Format 24-hour 'HH:MM' to 12-hour 'h:mm AM/PM'
    function formatTimeDisplay(time24) {
        const [hStr, mStr] = time24.split(':');
        const h = parseInt(hStr, 10);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12 = h === 0 ? 12 : h > 12 ? h - 12 : h;
        return `${h12}:${mStr} ${ampm}`;
    }

    // Navigate to previous or next month
    window.navigateMonth = function(direction) {
        selectedDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth() + direction, 1);
        renderMonthView(selectedDate);
    };

    // Select a day and switch to day view
    window.selectDay = function(day) {
        selectedDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), day);
        dateInput.value = formatDate(selectedDate);
        currentView = 'day';
        changeDateBtn.style.display = 'inline-block';
        timeStartInput.value = '';
        timeEndInput.value = '';
        timeStartDisplay.value = '';
        timeEndDisplay.value = '';
        nextStepBtn.disabled = true;
        selectedStartTime = null;
        selectedEndTime = null;
        availabilityMessage.textContent = '';
        renderDayView(selectedDate);
    };

    // Render day view with time slots
    function renderDayView(date) {
        const dateString = formatDate(date);
        const dayOfWeek = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        
        let html = `
            <div class="day-header">
                <div>${dayOfWeek[date.getDay()]}, ${monthNames[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}</div>
                <div class="studio-hours">Studio Hours: ${formatTimeDisplay(timeIn)} - ${formatTimeDisplay(timeOut)}</div>
                ${selectedStartTime ? `
                    <div class="selection-actions">
                        <button onclick="clearSelection(event)" class="clear-btn">
                            <i class="fa fa-times"></i> Clear Selection
                        </button>
                    </div>
                ` : ''}
            </div>
            <div class="time-slots-container">
        `;
        
        // Generate time slots based on studio hours with 30-minute intervals
        const [startHour, startMinute] = timeIn.split(':').map(Number);
        const [endHour, endMinute] = timeOut.split(':').map(Number);
        
        const startTime = new Date(date);
        startTime.setHours(startHour, startMinute, 0, 0);
        
        const endTime = new Date(date);
        endTime.setHours(endHour, endMinute, 0, 0);
        
        let currentSlot = new Date(startTime);
        const now = new Date();
        const isToday = now.toDateString() === date.toDateString();
        
        // Generate time slots in 1-hour intervals (including closing time)
        while (currentSlot <= endTime) {
            const slotTime = formatTime(currentSlot);
            const slotDateTime = new Date(currentSlot);
            
            // For the last slot (closing time), only allow it as an end time
            const isClosingTime = currentSlot.getTime() === endTime.getTime();
            
            // Check if this slot overlaps with user's existing bookings
            const userBookedHere = checkUserBookedSlot(dateString, slotTime);
            
            // Determine if slot is available (user's bookings count as unavailable)
            const isAvailable = isClosingTime ? true : checkSlotAvailability(dateString, slotTime, formatTime(new Date(currentSlot.getTime() + 3600000)));
            const isPast = isToday && currentSlot < now;
            let isSelectable = isAvailable && !isPast && !userBookedHere;
            
            // For closing time, only allow selection if a start time is already selected
            if (isClosingTime && !selectedStartTime) {
                isSelectable = false;
            }
            
            // Determine if this slot is selected as start or end time
            let slotClass = '';
            if (slotTime === selectedStartTime) {
                slotClass = 'selected-start';
            } else if (slotTime === selectedEndTime) {
                slotClass = 'selected-end';
            } else if (!isSelectable || userBookedHere) {
                // User bookings are shown as disabled (same as other bookings)
                slotClass = 'disabled';
            } else if (selectedStartTime && !selectedEndTime) {
                const startDateTime = new Date(`${dateString} ${selectedStartTime}:00`);
                const currentDateTime = new Date(`${dateString} ${slotTime}:00`);
                if (currentDateTime > startDateTime) {
                    // Check if this time slot is available for the entire duration from start time
                    const isDurationAvailable = checkEndTimeValidity(dateString, selectedStartTime, slotTime);
                    if (isDurationAvailable) {
                        slotClass = 'available-end';
                        isSelectable = true; // Make sure it's selectable including closing time
                    }
                }
            }
            
            // Add tooltip for disabled slots
            let tooltip = '';
            if (userBookedHere) {
                tooltip = 'This time is already in your pending bookings';
            } else if (isClosingTime && !selectedStartTime) {
                tooltip = 'Studio closing time - select as end time only';
            } else if (!isAvailable) {
                tooltip = 'This time slot is already booked';
            } else if (isPast) {
                tooltip = 'This time slot has already passed';
            }
            
            html += `
                <div class="time-slot ${slotClass}" 
                     onclick="${isSelectable ? `selectTimeSlot('${slotTime}')` : ''}"
                     ${tooltip ? `title="${tooltip}"` : ''}>
                    <div class="time-slot-time">
                        <i class="fa fa-clock-o"></i>
                        <span>${formatTimeDisplay(slotTime)}</span>
                    </div>
                </div>
            `;
            
            // If this is the closing time, stop here
            if (isClosingTime) {
                break;
            }
            
            // Move to next hour
            currentSlot.setHours(currentSlot.getHours() + 1);
        }
        
        html += `</div>`;
        calendarView.innerHTML = html;
    }

    // Check if a specific time is part of user's existing bookings
    function checkUserBookedSlot(dateString, time) {
        if (typeof selectedSlots === 'undefined' || !Array.isArray(selectedSlots)) {
            return false;
        }
        
        const checkTime = new Date(`${dateString} ${time}:00`);
        
        for (const slot of selectedSlots) {
            if (slot.date !== dateString) continue;
            
            const slotStart = new Date(`${slot.date} ${slot.start}:00`);
            const slotEnd = new Date(`${slot.date} ${slot.end}:00`);
            
            // Check if this time falls within the user's booked range
            // Using >= and < to check if time is within [start, end)
            if (checkTime >= slotStart && checkTime < slotEnd) {
                console.log(`🚫 Time ${time} on ${dateString} is in your pending booking: ${slot.start}-${slot.end} (will show as disabled)`);
                return true;
            }
        }
        
        return false;
    }

    // Check if a time range overlaps with user's existing bookings
    function checkTimeRangeOverlapWithUserBookings(dateString, startTime, endTime) {
        if (typeof selectedSlots === 'undefined' || !Array.isArray(selectedSlots)) {
            return false;
        }
        
        const newStart = new Date(`${dateString} ${startTime}:00`);
        const newEnd = new Date(`${dateString} ${endTime}:00`);
        
        console.log(`🔍 Checking overlap for new booking: ${startTime}-${endTime} on ${dateString}`);
        
        for (const slot of selectedSlots) {
            if (slot.date !== dateString) continue;
            
            const slotStart = new Date(`${slot.date} ${slot.start}:00`);
            const slotEnd = new Date(`${slot.date} ${slot.end}:00`);
            
            console.log(`   Comparing with existing booking: ${slot.start}-${slot.end}`);
            
            // Check for any overlap: (newStart < slotEnd) AND (newEnd > slotStart)
            if (newStart < slotEnd && newEnd > slotStart) {
                console.log(`   ❌ OVERLAP DETECTED! Cannot book ${startTime}-${endTime} because it overlaps with ${slot.start}-${slot.end}`);
                return true;
            }
        }
        
        console.log(`   ✅ No overlap found. Time range is available.`);
        return false;
    }

    // Check slot availability (checks against database bookings only, not user's session bookings)
    function checkSlotAvailability(dateString, startTime, endTime) {
        const startDateTime = new Date(`${dateString} ${startTime}:00`);
        const endDateTime = new Date(`${dateString} ${endTime}:00`);
        
        // Check against existing bookings from database
        for (const booking of existingBookings) {
            const bookingStart = new Date(`${booking.Sched_Date} ${booking.Time_Start}`);
            const bookingEnd = new Date(`${booking.Sched_Date} ${booking.Time_End}`);
            
            // Check for overlap
            if (startDateTime < bookingEnd && endDateTime > bookingStart) {
                return false; // Slot is booked by someone else
            }
        }
        
        // Check against already selected slots in the session (user's own pending bookings)
        if (typeof selectedSlots !== 'undefined' && Array.isArray(selectedSlots)) {
            for (const slot of selectedSlots) {
                // Skip if the slot is for a different date
                if (slot.date !== dateString) continue;
                
                const slotStart = new Date(`${slot.date} ${slot.start}:00`);
                const slotEnd = new Date(`${slot.date} ${slot.end}:00`);
                
                // Check for any overlap with existing selected slots
                if (startDateTime < slotEnd && endDateTime > slotStart) {
                    return false; // Slot overlaps with user's existing booking
                }
            }
        }
        
        // Check if the slot falls within studio hours
        const studioStart = new Date(`${dateString} ${timeIn}`);
        const studioEnd = new Date(`${dateString} ${timeOut}`);
        if (startDateTime < studioStart || endDateTime > studioEnd) {
            return false; // Outside operating hours
        }
        
        return true; // Slot is available
    }

    // Clear time slot selection
    window.clearSelection = function(e) {
        if (e) e.stopPropagation();
        selectedStartTime = null;
        selectedEndTime = null;
        timeStartInput.value = '';
        timeEndInput.value = '';
        timeStartDisplay.value = '';
        timeEndDisplay.value = '';
        availabilityMessage.textContent = '';
        nextStepBtn.disabled = true;
        renderDayView(selectedDate);
    };

    // Select a time slot
    window.selectTimeSlot = function(time) {
        const dateString = formatDate(selectedDate);
        const selectedDateTime = new Date(`${dateString} ${time}:00`);
        // Normalize time formats to ensure proper comparison (add :00 if only HH:MM)
        const normalizedTimeIn = timeIn.length === 5 ? `${timeIn}:00` : timeIn;
        const normalizedTimeOut = timeOut.length === 5 ? `${timeOut}:00` : timeOut;
        const studioEnd = new Date(`${dateString} ${normalizedTimeOut}`);
        const studioStart = new Date(`${dateString} ${normalizedTimeIn}`);
        
        // Check if the selected time is within studio operating hours
        // For start time: must be before closing time
        // For end time: can be equal to closing time
        if (selectedDateTime < studioStart) {
            availabilityMessage.style.color = '#f00';
            availabilityMessage.textContent = 'Selected time is outside studio operating hours.';
            return;
        }
        
        // If selecting as start time, must be strictly before closing time
        if ((!selectedStartTime || (selectedStartTime && selectedEndTime)) && selectedDateTime >= studioEnd) {
            availabilityMessage.style.color = '#f00';
            availabilityMessage.textContent = 'Cannot start a booking at or after closing time.';
            return;
        }
        
        // If no start time selected or both times are selected, set as new start time
        if (!selectedStartTime || (selectedStartTime && selectedEndTime)) {
            selectedStartTime = time;
            selectedEndTime = null;
            timeStartInput.value = time;  // Hidden input: 24-hour format for server
            timeStartDisplay.value = formatTimeDisplay(time);  // Display input: 12-hour format for user
            timeEndInput.value = '';
            timeEndDisplay.value = '';
            availabilityMessage.textContent = 'Now select an end time';
            availabilityMessage.style.color = '#4CAF50';
            nextStepBtn.disabled = true;
        } 
        // If start time is selected but no end time, set as end time if valid
        else if (selectedStartTime && !selectedEndTime) {
            const startDateTime = new Date(`${dateString} ${selectedStartTime}:00`);
            
            // Validate end time is after start time
            if (selectedDateTime <= startDateTime) {
                availabilityMessage.style.color = '#f00';
                availabilityMessage.textContent = 'End time must be after start time.';
                return;
            }
            
            // Check if the selected time slot is available
            if (checkEndTimeValidity(dateString, selectedStartTime, time)) {
                selectedEndTime = time;
                timeEndInput.value = time;  // Hidden input: 24-hour format for server
                timeEndDisplay.value = formatTimeDisplay(time);  // Display input: 12-hour format for user
                // Trigger AJAX to check final availability
                checkAvailability();
            } else {
                // Check why it's not available
                const startDateTime = new Date(`${dateString} ${selectedStartTime}:00`);
                const endDateTime = new Date(`${dateString} ${time}:00`);
                
                // Check if the selected time is in the past
                if (endDateTime <= new Date()) {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = 'Cannot select a time in the past.';
                } 
                // Check if it's within operating hours (end time can be equal to closing time)
                else if (endDateTime < new Date(`${dateString} ${normalizedTimeIn}`) || 
                         endDateTime > new Date(`${dateString} ${normalizedTimeOut}`)) {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = 'Selected time is outside studio operating hours.';
                }
                // Check for minimum duration
                else if ((endDateTime - startDateTime) < (60 * 60 * 1000)) {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = 'Minimum booking duration is 1 hour.';
                }
                // Check if it overlaps with user's existing bookings
                else if (checkTimeRangeOverlapWithUserBookings(dateString, selectedStartTime, time)) {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = 'This time overlaps with your pending booking. Please select a different time.';
                }
                // Must be an overlap with database booking
                else {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = 'This time slot is not available due to an existing booking.';
                }
            }
        }
        
        // Re-render to update slot styles
        renderDayView(selectedDate);
    };

    // Check end time validity
    function checkEndTimeValidity(dateString, startTime, endTime) {
        const startDateTime = new Date(`${dateString} ${startTime}:00`);
        const endDateTime = new Date(`${dateString} ${endTime}:00`);
        
        // Ensure the end time is after the start time
        if (endDateTime <= startDateTime) {
            return false;
        }
        
        // Check minimum booking duration (e.g., at least 1 hour)
        const minDuration = 60 * 60 * 1000; // 1 hour in milliseconds
        if ((endDateTime - startDateTime) < minDuration) {
            return false;
        }
        
        // Check against existing bookings for overlaps
        for (const booking of existingBookings) {
            // Skip if booking is on a different date
            if (booking.Sched_Date !== dateString) continue;
            
            const bookingStart = new Date(`${booking.Sched_Date} ${booking.Time_Start}`);
            const bookingEnd = new Date(`${booking.Sched_Date} ${booking.Time_End}`);
            
            // Check for any overlap
            if (startDateTime < bookingEnd && endDateTime > bookingStart) {
                return false;
            }
        }
        
        // Check against already selected slots in the session
        if (typeof selectedSlots !== 'undefined' && Array.isArray(selectedSlots)) {
            for (const slot of selectedSlots) {
                // Skip if the slot is for a different date
                if (slot.date !== dateString) continue;
                
                const slotStart = new Date(`${slot.date} ${slot.start}:00`);
                const slotEnd = new Date(`${slot.date} ${slot.end}:00`);
                
                // Check for any overlap with existing selected slots
                if (startDateTime < slotEnd && endDateTime > slotStart) {
                    return false; // Slot overlaps with a selected slot
                }
            }
        }
        
        // Check if within studio hours
        // Normalize timeOut to ensure proper format (add :00 if only HH:MM)
        const normalizedTimeOut = timeOut.length === 5 ? `${timeOut}:00` : timeOut;
        const studioStart = new Date(`${dateString} ${timeIn}`);
        const studioEnd = new Date(`${dateString} ${normalizedTimeOut}`);
        
        // End time can be equal to closing time, but not after it
        if (startDateTime < studioStart || endDateTime > studioEnd) {
            return false;
        }
        
        return true;
    }

    // Check availability via AJAX
    function checkAvailability() {
        availabilityMessage.textContent = '';
        nextStepBtn.disabled = true;
        
        // First, check locally for any obvious issues before making the AJAX call
        const dateString = dateInput.value;
        const startTime = timeStartInput.value;
        const endTime = timeEndInput.value;
        
        if (!dateString || !startTime || !endTime) {
            availabilityMessage.style.color = '#f00';
            availabilityMessage.textContent = 'Please select both start and end times.';
            return;
        }
        
        // Check if the end time is after start time
        const startDateTime = new Date(`${dateString} ${startTime}:00`);
        const endDateTime = new Date(`${dateString} ${endTime}:00`);
        
        if (endDateTime <= startDateTime) {
            availabilityMessage.style.color = '#f00';
            availabilityMessage.textContent = 'End time must be after start time.';
            return;
        }
        
        // Check minimum booking duration (1 hour)
        const minDuration = 60 * 60 * 1000; // 1 hour in milliseconds
        if ((endDateTime - startDateTime) < minDuration) {
            availabilityMessage.style.color = '#f00';
            availabilityMessage.textContent = 'Minimum booking duration is 1 hour.';
            return;
        }

        // If local checks pass, proceed with server-side validation
        $.ajax({
            url: 'check_availability.php',
            method: 'POST',
            data: {
                studio_id: <?php echo $studio_id; ?>,
                date: dateString,
                timeStart: startTime,
                timeEnd: endTime
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    availabilityMessage.style.color = '#0f0';
                    availabilityMessage.textContent = response.message;
                    nextStepBtn.disabled = false;
                } else {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = response.message;
                    nextStepBtn.disabled = true;
                }
            },
            error: function() {
                availabilityMessage.style.color = '#f00';
                availabilityMessage.textContent = 'Error checking availability. Please try again.';
                nextStepBtn.disabled = true;
            }
        });
    }

    // Form validation
    document.getElementById('dateTimeForm').addEventListener('submit', function(e) {
        if (!dateInput.value || !timeStartInput.value || !timeEndInput.value || !timeStartDisplay.value || !timeEndDisplay.value) {
            e.preventDefault();
            alert('Please select a date and both start and end times to continue.');
        } else if (nextStepBtn.disabled) {
            e.preventDefault();
            alert('Please select an available time slot.');
        }
    });
});
    </script>
</body>
</html>
