<?php
session_start(); // Start the session
include '../../shared/config/db.php';
require_once '../../shared/config/path_config.php';

// Check if we're coming from booking2.php or adding another slot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_another_slot'])) {
    $studio_id = $_SESSION['booking_studio_id'] ?? 0;
    header('Location: booking2.php?studio_id=' . $studio_id . '&from_confirm=1');
    exit;
}

// Get data from POST (booking2.php submission) or SESSION (navigation)
$studio_id = 0;
$services_data = [];
$equipment_data = [];
$date = '';
$time_start = '';
$time_end = '';

// If coming from booking2.php (POST with date/time selection)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['date']) && !empty($_POST['timeStart'])) {
    $studio_id = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
    $date = $_POST['date'];
    $time_start = $_POST['timeStart'];
    $time_end = $_POST['timeEnd'];
    
    // Decode services and equipment data
    if (isset($_POST['services_data']) && !empty($_POST['services_data'])) {
        $services_data = json_decode($_POST['services_data'], true);
    }
    if (isset($_POST['equipment_data']) && !empty($_POST['equipment_data'])) {
        $equipment_data = json_decode($_POST['equipment_data'], true);
    }
}
// Otherwise, get from session
else {
    $studio_id = $_SESSION['booking_studio_id'] ?? 0;
    $services_data = $_SESSION['booking_services'] ?? [];
    $equipment_data = $_SESSION['booking_equipment'] ?? [];
}

// Initialize session variables if they don't exist
if (!isset($_SESSION['selected_slots'])) {
    $_SESSION['selected_slots'] = [];
}

// Add current slot to selected slots if we have a complete time slot
if (!empty($date) && !empty($time_start) && !empty($time_end) && !empty($services_data)) {
    $new_slot = [
        'date' => $date,
        'start' => $time_start,
        'end' => $time_end,
        'studio_id' => $studio_id,
        'services' => $services_data, // Array of services with instructor_id
        'equipment' => $equipment_data // Array of equipment per service
    ];

    $service_count = count($services_data);
    $equipment_count = !empty($equipment_data) ? count($equipment_data) : 0;
    error_log("Booking3: Attempting to add slot - Date: {$new_slot['date']}, Time: {$new_slot['start']}-{$new_slot['end']}, Studio: {$studio_id}, Services: {$service_count}, Equipment types: {$equipment_count}");
    error_log("Booking3: Current slots before add: " . json_encode($_SESSION['selected_slots']));

    // Check if this EXACT slot already exists (same studio, date, start, and end times)
    $slot_exists = false;
    $existing_index = -1;
    
    foreach ($_SESSION['selected_slots'] as $index => $slot) {
        $match_studio = (int)($slot['studio_id'] ?? 0) === (int)$new_slot['studio_id'];
        $match_date = ($slot['date'] ?? '') === $new_slot['date'];
        $match_start = ($slot['start'] ?? '') === $new_slot['start'];
        $match_end = ($slot['end'] ?? '') === $new_slot['end'];
        
        error_log("Booking3: Comparing with slot $index - Studio match: " . ($match_studio ? 'YES' : 'NO') . ", Date match: " . ($match_date ? 'YES' : 'NO') . ", Start match: " . ($match_start ? 'YES' : 'NO') . ", End match: " . ($match_end ? 'YES' : 'NO'));
        
        if ($match_studio && $match_date && $match_start && $match_end) {
            $slot_exists = true;
            $existing_index = $index;
            error_log("Booking3: ⚠️  EXACT DUPLICATE FOUND at index $index - Date: {$slot['date']}, Time: {$slot['start']}-{$slot['end']}");
            break;
        }
    }

    if (!$slot_exists) {
        $_SESSION['selected_slots'][] = $new_slot;
        error_log("Booking3: ✅ Successfully added NEW slot #{" . (count($_SESSION['selected_slots']) - 1) . "} - Date: {$new_slot['date']}, Time: {$new_slot['start']}-{$new_slot['end']}. Total slots now: " . count($_SESSION['selected_slots']));
    } else {
        error_log("Booking3: ❌ Skipped adding slot (exact duplicate found at index $existing_index)");
    }
    
    error_log("Booking3: Final slots after processing: " . json_encode($_SESSION['selected_slots']));
}

// Validate we have at least the studio and services in session
if ($studio_id <= 0 || empty($services_data)) {
    // Try to get from session
    $studio_id = $_SESSION['booking_studio_id'] ?? 0;
    $services_data = $_SESSION['booking_services'] ?? [];
    $equipment_data = $_SESSION['booking_equipment'] ?? [];
}

// If no slots selected, redirect back
if (empty($_SESSION['selected_slots'])) {
    header("Location: booking.php?studio_id=$studio_id");
    exit;
}

// Calculate total price for all selected slots
$total_price = 0;
$total_hours = 0;
$all_services_used = []; // Track all unique services

foreach ($_SESSION['selected_slots'] as $slot) {
    if ((int)($slot['studio_id'] ?? 0) !== (int)$studio_id) { continue; }
    
    $start = new DateTime($slot['date'] . ' ' . $slot['start']);
    $end = new DateTime($slot['date'] . ' ' . $slot['end']);
    $interval = $start->diff($end);
    $hours = $interval->h + ($interval->days * 24);
    $total_hours += $hours;
    
    // Calculate service prices for this slot
    if (!empty($slot['services']) && is_array($slot['services'])) {
        foreach ($slot['services'] as $service_item) {
            $service_id = (int)$service_item['service_id'];
            
            // Track unique services
            if (!isset($all_services_used[$service_id])) {
                $all_services_used[$service_id] = $service_item;
            }
            
            // Fetch service price if not in the array
            if (!isset($service_item['price'])) {
                $price_query = "SELECT Price FROM services WHERE ServiceID = ?";
                $stmt = mysqli_prepare($conn, $price_query);
                mysqli_stmt_bind_param($stmt, "i", $service_id);
                mysqli_stmt_execute($stmt);
                $price_result = mysqli_stmt_get_result($stmt);
                if ($price_row = mysqli_fetch_assoc($price_result)) {
                    $service_item['price'] = (float)$price_row['Price'];
                }
                mysqli_stmt_close($stmt);
            }
            
            $total_price += $hours * (float)($service_item['price'] ?? 0);
        }
    }
    
    // Calculate equipment prices for this slot
    if (!empty($slot['equipment']) && is_array($slot['equipment'])) {
        foreach ($slot['equipment'] as $service_id => $equipments) {
            foreach ($equipments as $equipment_id => $quantity) {
                if ($quantity > 0) {
                    $eq_query = "SELECT rental_price FROM equipment_addons WHERE equipment_id = ?";
                    $eq_stmt = mysqli_prepare($conn, $eq_query);
                    mysqli_stmt_bind_param($eq_stmt, "i", $equipment_id);
                    mysqli_stmt_execute($eq_stmt);
                    $eq_result = mysqli_stmt_get_result($eq_stmt);
                    
                    if ($eq_row = mysqli_fetch_assoc($eq_result)) {
                        $total_price += $hours * (float)$eq_row['rental_price'] * (int)$quantity;
                    }
                    mysqli_stmt_close($eq_stmt);
                }
            }
        }
    }
}

// Fetch studio details including deposit percentage
$studio_query = "SELECT StudioID, StudioName, Loc_Desc, StudioImg, deposit_percentage FROM studios WHERE StudioID = ?";
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
    error_log("No studio found for StudioID: $studio_id");
    header("Location: Home.php?error=" . urlencode("Invalid studio ID. Please select a valid studio."));
    exit;
}
mysqli_stmt_close($stmt);

// Calculate initial payment using studio's deposit percentage
$deposit_percentage = isset($studio['deposit_percentage']) ? (float)$studio['deposit_percentage'] : 25.0;
$initial_payment = $total_price * ($deposit_percentage / 100);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Confirm Booking - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        /* Loading spinner animation */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .fa-spin {
            animation: spin 1s linear infinite;
        }
        
        /* Button states */
        .button.danger:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .button .button-loader {
            display: none;
        }
        
        .button.loading .button-text {
            display: none;
        }
        
        .button.loading .button-loader {
            display: inline-block;
        }
        
        /* Message styles */
        .message {
            margin-top: 10px;
            padding: 10px 15px;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .button-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #bd2130;
        }
        
        #branding img {
            width: 180px;
            display: block;
        }

        .section-title {
            margin-left: 20px;
        }

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

        .fullwidth-block.booking-section {
            background: linear-gradient(135deg, #222 60%, #e50914 200%);
            padding: 40px 0 60px 0;
        }

        .booking-container {
            display: flex;
            justify-content: center;
            margin-top: 40px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .booking-card {
            width: 60%;
            min-width: 320px;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 12px;
            padding: 26px 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.10);
            margin-bottom: 24px;
        }

        .booking-step-title {
            color: #fff;
            margin: 20px 0;
            font-size: 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 10px;
        }

        .booking-details {
            margin-bottom: 20px;
        }

        .booking-details p {
            color: #ccc;
            font-size: 16px;
            margin: 10px 0;
        }

        .booking-details strong {
            color: #fff;
            margin-right: 5px;
        }

        .error-message {
            color: #ff5555;
            background: rgba(255, 0, 0, 0.1);
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
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
            background-color: #4a6baf;
            border-color: #3a5a9f;
            transition: all 0.2s ease;
        }
        
        .button.secondary:hover {
            background-color: #3a5a9f;
            border-color: #2a4a8f;
            transform: translateY(-1px);
        }

        #confirmBtn:disabled {
            background-color: #888;
            cursor: not-allowed;
        }

        /* New styles for time slots */
        .selected-slots {
            margin: 15px 0;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .slot-item {
            display: flex;
            justify-content: space-between;
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            margin-bottom: 12px;
            border-radius: 6px;
            background-color: rgba(255, 255, 255, 0.03);
            transition: all 0.2s ease;
        }
        
        .slot-item:hover {
            background-color: rgba(255, 255, 255, 0.07);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .slot-details {
            flex-grow: 1;
        }
        
        .slot-actions {
            display: flex;
            align-items: center;
        }
        
        .slot-service {
            font-weight: 600;
            color: #fff;
            margin-bottom: 8px;
            font-size: 1.05em;
            padding-bottom: 6px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .slot-details ul {
            list-style-type: disc;
            color: #ccc;
        }
        
        .slot-details ul li {
            margin: 4px 0;
            line-height: 1.4;
        }
        
        .slot-details > div:first-child {
            color: #ddd;
            font-size: 14px;
        }
        
        .remove-slot {
            background: rgba(220, 53, 69, 0.8);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
            margin-left: 10px;
            transition: background 0.2s;
        }
        
        .remove-slot:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: #1a1a1a;
            padding: 25px;
            border-radius: 8px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            border: 1px solid #333;
        }

        .modal-title {
            color: #fff;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 20px;
        }

        .modal-message {
            color: #ddd;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .modal-btn {
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .modal-btn-cancel {
            background-color: #444;
            color: #fff;
        }

        .modal-btn-cancel:hover {
            background-color: #555;
        }

        .modal-btn-confirm {
            background-color: #e50914;
            color: white;
        }

        .modal-btn-confirm:hover {
            background-color: #f40612;
        }

        @media (max-width: 768px) {
            .booking-container {
                flex-direction: column;
            }

            .booking-card {
                width: 100%;
                margin-bottom: 20px;
            }

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
        @keyframes fadeOut {
    0% { opacity: 1; }
    70% { opacity: 1; }
    100% { opacity: 0; }
}
    </style>
</head>
<body>
<?php include '../../shared/components/navbar.php'; ?>

    <!-- Confirmation Modal -->
    <div id="confirmationModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Confirm Removal</h3>
            <p class="modal-message">Are you sure you want to remove this time slot?</p>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="cancelRemove">Cancel</button>
                <button type="button" class="modal-btn modal-btn-confirm" id="confirmRemove">Remove</button>
            </div>
        </div>
    </div>

    <main class="main-content">
        <div class="fullwidth-block booking-section">
            <div class="booking-progress">
                <div class="progress-step completed">
                    <div class="step-number">1</div>
                    <div class="step-label">Select Service</div>
                </div>
                <div class="progress-step completed">
                    <div class="step-number">2</div>
                    <div class="step-label">Choose Date & Time</div>
                </div>
                <div class="progress-step active">
                    <div class="step-number">3</div>
                    <div class="step-label">Confirm Booking</div>
                </div>
                <div class="progress-step">
                    <div class="step-number">4</div>
                    <div class="step-label">Payment</div>
                </div>
            </div>

            <h2 class="section-title">Confirm Your Booking</h2>
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

                    <h4 class="booking-step-title">Step 3: Confirm Booking Details</h4>

                    <?php if (isset($_SESSION['booking_error'])): ?>
                        <div class="error-message"><?php echo htmlspecialchars($_SESSION['booking_error']); ?></div>
                        <?php unset($_SESSION['booking_error']); ?>
                    <?php endif; ?>

                    <form id="confirmForm" action="booking4.php" method="post">
                        <input type="hidden" name="studio_id" value="<?php echo $studio_id; ?>">
                        <input type="hidden" name="total_price" value="<?php echo $total_price; ?>">
                        <input type="hidden" name="initial_payment" value="<?php echo $initial_payment; ?>">
                        <input type="hidden" name="from_confirm" value="1">
                        
                        <!-- Store selected slots data as JSON -->
                        <input type="hidden" name="selected_slots" value='<?php 
                            $slots_data = [];
                            foreach ($_SESSION['selected_slots'] as $slot) {
                                if ((int)($slot['studio_id'] ?? 0) !== (int)$studio_id) { continue; }
                                $slots_data[] = $slot;
                            }
                            echo htmlspecialchars(json_encode($slots_data), ENT_QUOTES, 'UTF-8');
                        ?>'>

                        <div class="booking-details">
                            <h3>Selected Time Slots</h3>
                            <div class="selected-slots" id="selectedSlotsList">
                                <?php 
                                foreach ($_SESSION['selected_slots'] as $index => $slot): 
                                    if ((int)($slot['studio_id'] ?? 0) !== (int)$studio_id) continue;
                                    
                                    $start = new DateTime($slot['date'] . ' ' . $slot['start']);
                                    $end = new DateTime($slot['date'] . ' ' . $slot['end']);
                                    $interval = $start->diff($end);
                                    $hours = $interval->h + ($interval->days * 24);
                                    
                                    $slot_services = $slot['services'] ?? [];
                                    $slot_equipment = $slot['equipment'] ?? [];
                                ?>
                                    <div class="slot-item" data-index="<?php echo $index; ?>">
                                        <div class="slot-details">
                                            <div style="margin-bottom: 10px;">
                                                <div><strong>Date:</strong> <?php echo $start->format('F j, Y'); ?></div>
                                                <div><strong>Time:</strong> <?php echo $start->format('g:i A') . ' - ' . $end->format('g:i A'); ?></div>
                                                <div><strong>Duration:</strong> <?php echo $hours; ?> hour<?php echo $hours != 1 ? 's' : ''; ?></div>
                                            </div>
                                            
                                            <?php if (!empty($slot_services)): ?>
                                            <div style="background: rgba(229, 9, 20, 0.1); padding: 10px; border-radius: 4px; margin-bottom: 8px;">
                                                <strong>Services:</strong>
                                                <ul style="margin:6px 0 0 0; padding-left:20px;">
                                                    <?php 
                                                    foreach ($slot_services as $service_item): 
                                                        $service_id = (int)$service_item['service_id'];
                                                        $instructor_id = (int)($service_item['instructor_id'] ?? 0);
                                                        
                                                        // Get service details
                                                        $service_query = "SELECT ServiceType, Price FROM services WHERE ServiceID = ?";
                                                        $stmt = mysqli_prepare($conn, $service_query);
                                                        mysqli_stmt_bind_param($stmt, "i", $service_id);
                                                        mysqli_stmt_execute($stmt);
                                                        $service_result = mysqli_stmt_get_result($stmt);
                                                        $service_name = 'Unknown Service';
                                                        $service_price = 0;
                                                        if ($service_row = mysqli_fetch_assoc($service_result)) {
                                                            $service_name = htmlspecialchars($service_row['ServiceType']);
                                                            $service_price = (float)$service_row['Price'];
                                                        }
                                                        mysqli_stmt_close($stmt);
                                                        
                                                        // Get instructor name
                                                        $instructor_name = '';
                                                        if ($instructor_id > 0) {
                                                            $instructor_query = "SELECT Name FROM instructors WHERE InstructorID = ?";
                                                            $inst_stmt = mysqli_prepare($conn, $instructor_query);
                                                            mysqli_stmt_bind_param($inst_stmt, "i", $instructor_id);
                                                            mysqli_stmt_execute($inst_stmt);
                                                            $instructor_result = mysqli_stmt_get_result($inst_stmt);
                                                            if ($instructor_row = mysqli_fetch_assoc($instructor_result)) {
                                                                $instructor_name = ' with ' . htmlspecialchars($instructor_row['Name']);
                                                            }
                                                            mysqli_stmt_close($inst_stmt);
                                                        }
                                                    ?>
                                                    <li>
                                                        <?php echo $service_name . $instructor_name; ?> 
                                                        (₱<?php echo number_format($service_price * $hours, 2); ?>)
                                                    </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($slot_equipment)): ?>
                                            <div style="background: rgba(74, 107, 175, 0.1); padding: 10px; border-radius: 4px;">
                                                <strong>Equipment Rentals:</strong>
                                                <ul style="margin:6px 0 0 0; padding-left:20px;">
                                                    <?php 
                                                    foreach ($slot_equipment as $service_id => $equipments):
                                                        foreach ($equipments as $equipment_id => $quantity):
                                                            if ($quantity > 0):
                                                                // Get equipment details
                                                                $eq_query = "SELECT equipment_name, rental_price FROM equipment_addons WHERE equipment_id = ?";
                                                                $eq_stmt = mysqli_prepare($conn, $eq_query);
                                                                mysqli_stmt_bind_param($eq_stmt, "i", $equipment_id);
                                                                mysqli_stmt_execute($eq_stmt);
                                                                $eq_result = mysqli_stmt_get_result($eq_stmt);
                                                                if ($eq_row = mysqli_fetch_assoc($eq_result)):
                                                                    $eq_name = htmlspecialchars($eq_row['equipment_name']);
                                                                    $eq_price = (float)$eq_row['rental_price'];
                                                                    $eq_total = $eq_price * $quantity * $hours;
                                                    ?>
                                                    <li>
                                                        <?php echo $eq_name; ?> × <?php echo $quantity; ?> 
                                                        (₱<?php echo number_format($eq_total, 2); ?>)
                                                    </li>
                                                    <?php 
                                                                endif;
                                                                mysqli_stmt_close($eq_stmt);
                                                            endif;
                                                        endforeach;
                                                    endforeach; 
                                                    ?>
                                                </ul>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="slot-actions">
                                            <button type="button" class="remove-slot" data-index="<?php echo $index; ?>">
                                                <i class="fas fa-times"></i> Remove
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-actions">
                                <div class="button-group">
                                    <a href="booking.php?studio_id=<?php echo $studio_id; ?>&add_more=1" class="button secondary">
                                        <i class="fas fa-plus"></i> Add Booking
                                    </a>
                                    <button type="button" id="clearAllBtn" class="button danger" 
                                        data-studio-id="<?php echo $studio_id; ?>">
                                        <span class="button-text">
                                            <i class="fas fa-trash-alt"></i> Clear All
                                        </span>
                                        <span class="button-loader" style="display: none;">
                                            <i class="fas fa-spinner fa-spin"></i> Clearing...
                                        </span>
                                    </button>
                                </div>
                                <div id="clearMessage" class="message" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px;"></div>
                            </div>
                        </div>

                        <div class="booking-details">
                            <h3>Pricing Summary</h3>
                            <p><strong>Total Time Slots:</strong> <?php echo count(array_filter($_SESSION['selected_slots'], function($slot) use ($studio_id) { return (int)($slot['studio_id'] ?? 0) === (int)$studio_id; })); ?></p>
                            <p><strong>Total Hours:</strong> <?php echo $total_hours; ?> hour<?php echo $total_hours != 1 ? 's' : ''; ?></p>
                            <p><strong>Total Amount:</strong> ₱<?php echo number_format($total_price, 2); ?></p>
                            <p><strong>Initial Deposit (<?php echo number_format($deposit_percentage, 1); ?>%):</strong> ₱<?php echo number_format($initial_payment, 2); ?></p>
                            <p><strong>Remaining Balance:</strong> ₱<?php echo number_format($total_price - $initial_payment, 2); ?></p>
                            <p style="color: #aaa; font-size: 13px; margin-top: 10px;">
                                <i class="fas fa-info-circle"></i> Includes all selected services and equipment rentals
                            </p>
                        </div>

                        <div class="form-actions">
                            <a href="booking2.php?studio_id=<?php echo $studio_id; ?>&from_confirm=1" class="button secondary">Back</a>
                            <button type="submit" class="button" id="confirmBtn" <?php echo empty($_SESSION['selected_slots']) ? 'disabled' : ''; ?>>
                                <span class="button-text"><i class="fas fa-check"></i> Confirm Booking</span>
                                <span class="button-loader"><i class="fas fa-spinner fa-spin"></i> Processing...</span>
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </main>

    <?php include '../../shared/components/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // DOM Elements
        const confirmForm = document.getElementById('confirmForm');
        const clearAllBtn = document.getElementById('clearAllBtn');
        const selectedSlotsList = document.getElementById('selectedSlotsList');
        const clearMessage = document.getElementById('clearMessage');
        
        // Handle Clear All button click
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', function() {
                if (!confirm('Are you sure you want to clear all bookings? This cannot be undone.')) {
                    return;
                }
                
                // Show loading state
                clearAllBtn.classList.add('loading');
                clearAllBtn.disabled = true;
                clearMessage.style.display = 'none';
                
                // Get data attributes
                const studioId = clearAllBtn.dataset.studioId || 0;
                
                // Send AJAX request
                fetch('clear_slots.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `studio_id=${encodeURIComponent(studioId)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message and redirect
                        alert('All bookings have been cleared. You will be redirected to select a new booking.');
                        window.location.href = 'booking.php?studio_id=' + studioId;
                    } else {
                        clearMessage.textContent = data.message || 'Failed to clear time slots';
                        clearMessage.className = 'message error';
                        clearMessage.style.display = 'block';
                        clearAllBtn.classList.remove('loading');
                        clearAllBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    clearMessage.textContent = 'An error occurred while clearing time slots';
                    clearMessage.className = 'message error';
                    clearMessage.style.display = 'block';
                    clearAllBtn.classList.remove('loading');
                    clearAllBtn.disabled = false;
                });
            });
        }
        
        // Helper function to show messages
        function showMessage(message, type = 'success') {
            if (!clearMessage) return;
            
            clearMessage.textContent = message;
            clearMessage.className = `message ${type}`;
            clearMessage.style.display = 'block';
            
            // Auto-hide after 5 seconds
            if (type === 'success') {
                setTimeout(() => {
                    clearMessage.style.display = 'none';
                }, 5000);
            }
        }
        const confirmBtn = document.getElementById('confirmBtn');
        
        // Handle remove slot button clicks
        selectedSlotsList.addEventListener('click', function(e) {
            const removeBtn = e.target.closest('.remove-slot');
            if (!removeBtn) return;
            
            e.preventDefault();
            const index = removeBtn.dataset.index;
            const slotItem = removeBtn.closest('.slot-item');
            
            if (confirm('Are you sure you want to remove this time slot?')) {
                // Visual feedback
                slotItem.style.opacity = '0.6';
                slotItem.style.pointerEvents = 'none';
                
                // Remove from session via AJAX
                fetch('remove_time_slot.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `index=${encodeURIComponent(index)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Fade out animation
                        slotItem.style.transition = 'opacity 0.3s';
                        slotItem.style.opacity = '0';
                        
                        // After animation, remove from DOM
                        setTimeout(() => {
                            slotItem.remove();
                            
                            // If no slots left, show alert and redirect to booking.php
                            if (document.querySelectorAll('.slot-item').length === 0) {
                                alert('All bookings have been removed. You will be redirected to select a new booking.');
                                window.location.href = 'booking.php?studio_id=<?php echo $studio_id; ?>';
                            } else if (typeof updateBookingSummary === 'function') {
                                updateBookingSummary();
                            }
                        }, 300);
                    } else {
                        // Reset UI on error
                        slotItem.style.opacity = '1';
                        slotItem.style.pointerEvents = 'auto';
                        alert('Failed to remove time slot: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    slotItem.style.opacity = '1';
                    slotItem.style.pointerEvents = 'auto';
                    alert('An error occurred while removing the time slot');
                });
            }
        });

        // Handle form submission
        confirmForm.addEventListener('submit', function(e) {
            if (!confirmBtn.disabled) {
                // Show loading state on confirm button
                confirmBtn.classList.add('loading');
                
                // Add hidden fields for each selected slot
                const slots = <?php echo json_encode($_SESSION['selected_slots']); ?>;
                slots.forEach((slot, index) => {
                    const slotInput = document.createElement('input');
                    slotInput.type = 'hidden';
                    slotInput.name = `slots[${index}][date]`;
                    slotInput.value = slot.date;
                    confirmForm.appendChild(slotInput);

                    const startInput = document.createElement('input');
                    startInput.type = 'hidden';
                    startInput.name = `slots[${index}][start]`;
                    startInput.value = slot.start;
                    confirmForm.appendChild(startInput);

                    const endInput = document.createElement('input');
                    endInput.type = 'hidden';
                    endInput.name = `slots[${index}][end]`;
                    endInput.value = slot.end;
                    confirmForm.appendChild(endInput);
                });
                
                // Disable button to prevent double-submit
                confirmBtn.disabled = true;
                
                return true;
            }
            e.preventDefault();
            return false;
        });
    });
    </script>
</body>
</html>
