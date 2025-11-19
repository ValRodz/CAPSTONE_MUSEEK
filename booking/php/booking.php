<?php
session_start(); // Start the session
include '../../shared/config/db.php';
require_once '../../shared/config/path_config.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo "<script>
        alert('Please log in to continue.');
        window.location.href = '../../auth/php/login.php';
    </script>";
    exit;
}

// Fetch studio details based on StudioID from URL parameter
$studio_id = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;
$add_more = isset($_GET['add_more']) ? (bool)$_GET['add_more'] : false;
$studio = null;

// Debug logging
error_log("Booking.php: Loaded with studio_id=$studio_id, add_more=" . ($add_more ? 'true' : 'false'));
error_log("Booking.php: Current selected_slots count: " . count($_SESSION['selected_slots'] ?? []));

// Reset previous multi-booking state ONLY when starting a completely NEW studio booking
// Do NOT reset if user is adding more bookings to the same transaction (add_more=1)
if ($studio_id > 0 && !$add_more) {
    $last_studio = isset($_SESSION['booking_studio_id']) ? (int)$_SESSION['booking_studio_id'] : 0;
    
    // Only clear if switching to a different studio
    if ($last_studio !== $studio_id) {
        error_log("Booking.php: Switching studios from $last_studio to $studio_id - clearing session");
        unset($_SESSION['selected_slots']);
        unset($_SESSION['booking_studio_id']);
        unset($_SESSION['last_booking_studio_id']);
        unset($_SESSION['booking_services']);
        unset($_SESSION['booking_equipment']);
    } else {
        error_log("Booking.php: Same studio $studio_id - preserving session");
    }
} elseif ($add_more) {
    error_log("Booking.php: Adding more bookings (add_more=1) - preserving all session data");
}

if ($studio_id > 0) {
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
    }
    mysqli_stmt_close($stmt);

    // Fetch services
    $services_query = "SELECT se.ServiceID, se.ServiceType, se.Description, se.Price
                      FROM studio_services ss
                      LEFT JOIN services se ON ss.ServiceID = se.ServiceID
                      WHERE ss.StudioID = ?";
    $stmt = mysqli_prepare($conn, $services_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $services_result = mysqli_stmt_get_result($stmt);

    $services = [];
    while ($service_row = mysqli_fetch_assoc($services_result)) {
        $services[$service_row['ServiceID']] = [
            'ServiceType' => $service_row['ServiceType'],
            'Description' => $service_row['Description'],
            'Price' => $service_row['Price'],
            'Instructors' => []
        ];
    }
    mysqli_stmt_close($stmt);

    // First, get the owner ID for this studio
    $owner_query = "SELECT so.OwnerID FROM studio_owners so JOIN studios st ON so.OwnerID = st.OwnerID WHERE st.StudioID = ?";
    $stmt = mysqli_prepare($conn, $owner_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $owner_result = mysqli_stmt_get_result($stmt);
    $owner_row = mysqli_fetch_assoc($owner_result);
    $owner_id = $owner_row ? $owner_row['OwnerID'] : 0;
    mysqli_stmt_close($stmt);

    // Determine if studio restricts instructors via studio_instructors mapping
    $restricted = false;
    $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM studio_instructors WHERE StudioID = ?");
    if ($cntStmt) {
        mysqli_stmt_bind_param($cntStmt, "i", $studio_id);
        mysqli_stmt_execute($cntStmt);
        $cntRes = mysqli_stmt_get_result($cntStmt);
        if ($cntRes && ($cntRow = mysqli_fetch_assoc($cntRes))) {
            $restricted = ((int)$cntRow['cnt']) > 0;
        }
        mysqli_stmt_close($cntStmt);
    }

    // Fetch instructors for this studio considering restriction and availability
    // Note: blocked_dates are checked on the frontend based on selected booking date
    if ($restricted) {
        $instructors_query = "
            SELECT DISTINCT i.InstructorID, i.Name AS InstructorName, s.ServiceID, i.Availability, i.blocked_dates
            FROM studio_instructors si
            JOIN instructors i ON i.InstructorID = si.InstructorID
            JOIN instructor_services ins ON ins.InstructorID = i.InstructorID
            JOIN services s ON s.ServiceID = ins.ServiceID
            JOIN studio_services ss ON ss.ServiceID = s.ServiceID AND ss.StudioID = si.StudioID
            WHERE si.StudioID = ? AND i.OwnerID = ?
        ";
        $stmt = mysqli_prepare($conn, $instructors_query);
        mysqli_stmt_bind_param($stmt, "ii", $studio_id, $owner_id);
    } else {
        $instructors_query = "
            SELECT DISTINCT i.InstructorID, i.Name AS InstructorName, s.ServiceID, i.Availability, i.blocked_dates
            FROM instructors i
            JOIN instructor_services ins ON ins.InstructorID = i.InstructorID
            JOIN services s ON s.ServiceID = ins.ServiceID
            JOIN studio_services ss ON ss.ServiceID = s.ServiceID
            WHERE ss.StudioID = ? AND i.OwnerID = ?
        ";
        $stmt = mysqli_prepare($conn, $instructors_query);
        mysqli_stmt_bind_param($stmt, "ii", $studio_id, $owner_id);
    }

    mysqli_stmt_execute($stmt);
    $instructors_result = mysqli_stmt_get_result($stmt);

    while ($instructor_row = mysqli_fetch_assoc($instructors_result)) {
        if (isset($services[$instructor_row['ServiceID']])) {
            $services[$instructor_row['ServiceID']]['Instructors'][] = [
                'InstructorID' => $instructor_row['InstructorID'],
                'InstructorName' => $instructor_row['InstructorName'],
                'blocked_dates' => $instructor_row['blocked_dates'] ?? ''
            ];
        }
    }
    mysqli_stmt_close($stmt);

    // Fetch equipment for each service
    foreach ($services as $service_id => $service_data) {
        $equipment_query = "SELECT equipment_id, equipment_name, description, rental_price, quantity_available, equipment_image
                           FROM equipment_addons
                           WHERE service_id = ? AND is_available = 1
                           ORDER BY equipment_name";
        $eq_stmt = mysqli_prepare($conn, $equipment_query);
        mysqli_stmt_bind_param($eq_stmt, "i", $service_id);
        mysqli_stmt_execute($eq_stmt);
        $eq_result = mysqli_stmt_get_result($eq_stmt);
        
        $services[$service_id]['Equipment'] = [];
        while ($eq_row = mysqli_fetch_assoc($eq_result)) {
            $services[$service_id]['Equipment'][] = [
                'equipment_id' => $eq_row['equipment_id'],
                'equipment_name' => $eq_row['equipment_name'],
                'description' => $eq_row['description'],
                'rental_price' => $eq_row['rental_price'],
                'quantity_available' => $eq_row['quantity_available'],
                'equipment_image' => $eq_row['equipment_image']
            ];
        }
        mysqli_stmt_close($eq_stmt);
    }
}
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
    /* Progress Bar Styles */
    #branding img {
        width: 180px;
        display: block; 
    }
    
    .section-title {
        margin-left: 20px;
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
    .booking-container {
        display: flex;
        gap: 40px;
        justify-content: center;
        align-items: flex-start;
        margin-top: 40px;
        flex-wrap: wrap;
    }
    .booking-card {
        width: 56%;
        min-width: 320px;
        background: black;
        outline-color: #888;
        border-radius: 12px;
        padding: 26px 28px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.10);
        margin-bottom: 24px;
    }
    .booking-info-card {
        width: 34%;
        min-width: 250px;
        margin-left: 0;
        margin-bottom: 24px;
    }
    .service-card {
        margin-bottom: 8px;
        min-height: 110px;
        align-items: flex-start;
    }
    .fullwidth-block.booking-section {
        background: linear-gradient(135deg, #222 60%, #e50914 200%);
        padding: 40px 0 60px 0;
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
    
    /* Service Selection Styles */
    .service-selection-title {
        color: #fff;
        margin: 20px 0;
        font-size: 18px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        padding-bottom: 10px;
    }
    
    .services-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .service-item {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        border: 2px solid rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
    }
    
    .service-item.selected {
        border-color: #e50914;
        background: rgba(229, 9, 20, 0.1);
    }
    
    .service-header {
        display: flex;
        align-items: center;
        padding: 15px;
        cursor: pointer;
        gap: 15px;
    }
    
    .service-checkbox {
        width: 22px;
        height: 22px;
        cursor: pointer;
        accent-color: #e50914;
    }
    
    .service-info {
        flex: 1;
    }
    
    .service-info h4 {
        color: #fff;
        margin: 0 0 5px;
        font-size: 16px;
        font-weight: 600;
    }
    
    .service-info p {
        color: #ccc;
        font-size: 14px;
        margin: 0;
    }
    
    .service-price {
        color: #e50914;
        font-weight: bold;
        font-size: 18px;
    }
    
    /* Instructor Section */
    .instructor-section {
        display: none;
        padding: 15px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(0, 0, 0, 0.2);
    }
    
    .instructor-section.show {
        display: block;
    }
    
    .instructor-section h5 {
        color: #fff;
        margin: 0 0 10px;
        font-size: 14px;
        font-weight: 600;
    }
    
    .instructor-select {
        width: 100%;
        padding: 10px 12px;
        border-radius: 6px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(0, 0, 0, 0.5);
        color: #fff;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .instructor-select:focus {
        outline: none;
        border-color: #e50914;
        box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
    }
    
    .instructor-select option {
        background: #222;
        color: #fff;
        padding: 10px;
    }
    
    .no-instructor-msg {
        color: #ff6b6b;
        font-size: 13px;
        font-style: italic;
        margin-top: 5px;
    }
    
    /* Equipment Section */
    .equipment-section {
        display: none;
        padding: 15px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(0, 0, 0, 0.3);
    }
    
    .equipment-section.show {
        display: block;
    }
    
    .equipment-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 12px;
        margin-top: 10px;
    }
    
    .equipment-item {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 6px;
        padding: 10px;
        display: flex;
        gap: 12px;
        align-items: flex-start;
    }
    
    .equipment-image {
        width: 60px;
        height: 60px;
        border-radius: 4px;
        object-fit: cover;
        background: rgba(255, 255, 255, 0.1);
        flex-shrink: 0;
    }
    
    .equipment-image.placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        color: #666;
        font-size: 24px;
    }
    
    .equipment-details {
        flex: 1;
        min-width: 0;
    }
    
    .equipment-name {
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        margin: 0 0 4px;
    }
    
    .equipment-desc {
        color: #999;
        font-size: 12px;
        margin: 0 0 6px;
        line-height: 1.3;
    }
    
    .equipment-price {
        color: #e50914;
        font-size: 13px;
        font-weight: 600;
    }
    
    .equipment-controls {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 8px;
    }
    
    .equipment-controls label {
        color: #ccc;
        font-size: 12px;
    }
    
    .qty-control {
        display: flex;
        align-items: center;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 6px;
        overflow: hidden;
        background: rgba(0, 0, 0, 0.5);
    }
    
    .qty-btn {
        width: 28px;
        height: 28px;
        border: none;
        background: rgba(255, 255, 255, 0.05);
        color: #fff;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    
    .qty-btn:hover {
        background: #e50914;
        color: #fff;
    }
    
    .qty-btn:active {
        transform: scale(0.95);
    }
    
    .qty-btn:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }
    
    .qty-btn:disabled:hover {
        background: rgba(255, 255, 255, 0.05);
    }
    
    .equipment-qty {
        width: 45px;
        padding: 4px 8px;
        border: none;
        background: transparent;
        color: #fff;
        text-align: center;
        font-size: 14px;
        font-weight: 600;
    }
    
    .equipment-qty::-webkit-inner-spin-button,
    .equipment-qty::-webkit-outer-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    
    .equipment-qty[type=number] {
        -moz-appearance: textfield;
    }
    
    .equipment-available {
        color: #666;
        font-size: 11px;
        margin-left: auto;
    }
    
    /* Clear All Button */
    .clear-all-btn {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.5);
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.3s;
        white-space: nowrap;
    }
    
    .clear-all-btn:hover {
        background: rgba(239, 68, 68, 0.3);
        border-color: #ef4444;
        transform: translateY(-1px);
    }
    
    .clear-all-btn:active {
        transform: translateY(0);
    }
    
    /* Instructor Selection Block */
    .instructor-selection-block {
        display: none;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 15px;
        margin-top: 20px;
    }
    
    .instructor-selection-block h4 {
        color: #fff;
        margin: 0 0 15px;
        font-size: 16px;
    }
    
    .instructor-selection-block select {
        width: 100%;
        padding: 8px;
        border-radius: 4px;
        border: 1px solid #666;
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
        cursor: pointer;
    }
    
    .instructor-selection-block select option {
        background: #222;
        color: #fff;
    }
    
    /* Info Card Styles */
    .booking-info-card {
        width: 40%;
        background: #fff;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        color: #333;
    }
    
    .booking-info-card h4 {
        margin-top: 0;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }
    
    .info-content {
        font-size: 14px;
    }
    
    .selected-info {
        background: #f8f8f8;
        border-radius: 5px;
        padding: 15px;
        margin: 15px 0;
    }
    
    .info-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
    }
    
    .info-label {
        font-weight: bold;
        color: #555;
    }
    
    .info-value {
        color: #e50914;
    }
    
    .booking-tips {
        margin-top: 20px;
    }
    
    .booking-tips h5 {
        margin-bottom: 10px;
    }
    
    .booking-tips ul {
        padding-left: 20px;
    }
    
    .booking-tips li {
        margin-bottom: 5px;
        color: #555;
    }
    
    /* Button Styles */
    #nextStepBtn {
        padding: 12px 24px;
        font-size: 16px;
        background-color: #e50914;
        border: none;
        color: #fff;
        border-radius: 4px;
        cursor: pointer;
        width: auto;
        display: block;
        margin: 20px auto 0;
        transition: all 0.3s ease;
    }
    
    #nextStepBtn:hover {
        background-color: #f40612;
    }
    
    #nextStepBtn:disabled {
        background-color: #888;
        cursor: not-allowed;
    }
    
    /* Responsive Styles */
    @media (max-width: 768px) {
        .booking-container {
            flex-direction: column;
        }
        
        .booking-card, .booking-info-card {
            width: 100%;
            margin-bottom: 20px;
        }
        
    .booking-progress {
        padding: 0 15px;
    }
        
    .step-label {
        font-size: 12px;
    }
}
@media (max-width: 768px) {
    .studio-header img {
        width: 100%;
        height: auto;
    }

    .booking-info-card {
        flex: 1 1 100%;
        margin-top: 20px;
    }
}
</style>
</head>

<body>
<?php include '../../shared/components/navbar.php'; ?>

<main class="main-content">
    <div class="fullwidth-block booking-section">
        <div class="booking-progress">
            <div class="progress-step active">
                <div class="step-number">1</div>
                <div class="step-label">Select Service</div>
            </div>
            <div class="progress-step">
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
            <?php if ($studio): ?>
                <div class="booking-card">
                    <div class="studio-header">
                        <?php if (!empty($studio['StudioImgSrc'])): ?>
                            <img src="<?php echo $studio['StudioImgSrc']; ?>" alt="<?php echo htmlspecialchars($studio['StudioName']); ?>">
                        <?php else: ?>
                            <div class="letter-avatar"><?php echo htmlspecialchars($studio['StudioLetter']); ?></div>
                        <?php endif; ?>
                        <h3><?php echo htmlspecialchars($studio['StudioName']); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <div>
                            <h4 class="service-selection-title" style="margin: 0;">Step 1: Select Services</h4>
                            <p style="color: #ddd; margin: 5px 0 0 0; font-size: 14px;">
                                💡 Check multiple services to add them to your booking. Equipment rental options will appear below each selected service.
                            </p>
                        </div>
                        <button type="button" id="clearAllBtn" onclick="clearAllSelections()" class="clear-all-btn" style="display: none;">
                            <i class="fas fa-times-circle"></i> Clear All
                        </button>
                    </div>
                    
                    <div class="services-list">
                        <?php if (!empty($services)): ?>
                            <?php foreach ($services as $service_id => $service): ?>
                                <div class="service-item" id="service-item-<?php echo $service_id; ?>">
                                    <div class="service-header" onclick="toggleServiceCheckbox(<?php echo $service_id; ?>)">
                                        <input type="checkbox" 
                                               class="service-checkbox" 
                                               id="service-<?php echo $service_id; ?>" 
                                               value="<?php echo $service_id; ?>"
                                               data-price="<?php echo $service['Price']; ?>"
                                               data-name="<?php echo htmlspecialchars($service['ServiceType']); ?>"
                                               onchange="handleServiceChange(<?php echo $service_id; ?>)"
                                               onclick="event.stopPropagation()">
                                        <div class="service-info">
                                            <h4><?php echo htmlspecialchars($service['ServiceType']); ?></h4>
                                            <p><?php echo htmlspecialchars($service['Description']); ?></p>
                                        </div>
                                        <div class="service-price">₱<?php echo number_format($service['Price'], 2); ?></div>
                                    </div>
                                    
                                    <!-- Instructor Selection -->
                                    <div class="instructor-section" id="instructor-<?php echo $service_id; ?>">
                                        <h5><i class="fas fa-user-tie"></i> Select Instructor/Staff <?php if (!empty($service['Instructors'])): ?>*<?php endif; ?></h5>
                                        <?php if (!empty($service['Instructors'])): ?>
                                            <select class="instructor-select" 
                                                    id="instructor-select-<?php echo $service_id; ?>"
                                                    data-service-id="<?php echo $service_id; ?>"
                                                    onchange="validateInstructorSelection(<?php echo $service_id; ?>)"
                                                    required>
                                                <option value="">-- Choose Instructor/Staff --</option>
                                                <?php foreach ($service['Instructors'] as $instructor): ?>
                                                    <option value="<?php echo $instructor['InstructorID']; ?>"
                                                            data-blocked-dates="<?php echo htmlspecialchars($instructor['blocked_dates'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($instructor['InstructorName']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <p class="no-instructor-msg">
                                                <i class="fas fa-info-circle"></i> No instructor required for this service or no instructors available.
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Equipment Section -->
                                    <div class="equipment-section" id="equipment-<?php echo $service_id; ?>">
                                        <h5 style="color: #fff; margin: 0 0 10px; font-size: 14px;">
                                            <i class="fas fa-tools"></i> Available Equipment for Rent
                                        </h5>
                                        <?php if (!empty($service['Equipment'])): ?>
                                            <div class="equipment-list">
                                                <?php foreach ($service['Equipment'] as $equipment): ?>
                                                    <div class="equipment-item">
                                                        <?php if (!empty($equipment['equipment_image'])): ?>
                                                            <img src="../../<?php echo htmlspecialchars($equipment['equipment_image']); ?>" 
                                                                 alt="<?php echo htmlspecialchars($equipment['equipment_name']); ?>" 
                                                                 class="equipment-image"
                                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                            <div class="equipment-image placeholder" style="display:none;">
                                                                <i class="fas fa-box"></i>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="equipment-image placeholder">
                                                                <i class="fas fa-box"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="equipment-details">
                                                            <h6 class="equipment-name"><?php echo htmlspecialchars($equipment['equipment_name']); ?></h6>
                                                            <?php if (!empty($equipment['description'])): ?>
                                                                <p class="equipment-desc"><?php echo htmlspecialchars($equipment['description']); ?></p>
                                                            <?php endif; ?>
                                                            <div class="equipment-price">₱<?php echo number_format($equipment['rental_price'], 2); ?></div>
                                                            <div class="equipment-controls">
                                                                <label>Qty:</label>
                                                                <div class="qty-control">
                                                                    <button type="button" class="qty-btn" onclick="decrementQty('eq-<?php echo $equipment['equipment_id']; ?>')">
                                                                        <i class="fas fa-minus"></i>
                                                                    </button>
                                                                    <input type="number" 
                                                                           id="eq-<?php echo $equipment['equipment_id']; ?>"
                                                                           class="equipment-qty" 
                                                                           min="0" 
                                                                           max="<?php echo $equipment['quantity_available']; ?>"
                                                                           value="0"
                                                                           data-equipment-id="<?php echo $equipment['equipment_id']; ?>"
                                                                           data-service-id="<?php echo $service_id; ?>"
                                                                           data-price="<?php echo $equipment['rental_price']; ?>"
                                                                           data-name="<?php echo htmlspecialchars($equipment['equipment_name']); ?>"
                                                                           onchange="updateBookingSummary()"
                                                                           readonly>
                                                                    <button type="button" class="qty-btn" onclick="incrementQty('eq-<?php echo $equipment['equipment_id']; ?>')">
                                                                        <i class="fas fa-plus"></i>
                                                                    </button>
                                                                </div>
                                                                <span class="equipment-available">(<?php echo $equipment['quantity_available']; ?> available)</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p style="color: #999; font-size: 13px; font-style: italic;">No additional equipment available for this service.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="no-services" style="color: #ccc; text-align: center;">No services available for this studio.</p>
                        <?php endif; ?>
                    </div>
                    
                    <form id="serviceForm" action="booking2.php" method="POST">
                        <input type="hidden" name="studio_id" value="<?php echo $studio['StudioID']; ?>">
                        <input type="hidden" id="selected_services_data" name="services_data" value="">
                        <input type="hidden" id="selected_equipment_data" name="equipment_data" value="">
                        <button type="submit" id="nextStepBtn" disabled>Continue to Date & Time</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="booking-card">
                    <p style="color: #ccc; text-align: center; width: 100%;">Studio not found.</p>
                    <a href="../../client/php/browse.php" class="button">Browse Studios</a>
                </div>
            <?php endif; ?>
            
            <div class="booking-info-card">
                <h4>Booking Summary</h4>
                <div class="info-content">
                    <div class="selected-info" id="bookingSummary">
                        <p style="color: #999; font-style: italic;">No services selected yet</p>
                    </div>
                    <div style="border-top: 2px solid #e50914; padding-top: 15px; margin-top: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong style="font-size: 16px;">Total:</strong>
                            <strong id="totalPrice" style="font-size: 20px; color: #e50914;">₱0.00</strong>
                        </div>
                    </div>
                    <div class="booking-tips" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
                        <h5>Tips:</h5>
                        <ul>
                            <li>Select multiple services for your booking</li>
                            <li>Add equipment rentals as needed</li>
                            <li>Review your selection before proceeding</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../../shared/components/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// Store services data from PHP
const servicesData = <?php echo json_encode($services); ?>;

// Track selected services, instructors, and equipment
let selectedServices = new Set();
let selectedInstructors = {}; // { serviceId: instructorId }
let selectedEquipment = {}; // { serviceId: { equipmentId: quantity } }

// Quantity control functions
function incrementQty(inputId) {
    const input = document.getElementById(inputId);
    const max = parseInt(input.max);
    const current = parseInt(input.value) || 0;
    
    if (current < max) {
        input.value = current + 1;
        updateBookingSummary();
    }
}

function decrementQty(inputId) {
    const input = document.getElementById(inputId);
    const min = parseInt(input.min);
    const current = parseInt(input.value) || 0;
    
    if (current > min) {
        input.value = current - 1;
        updateBookingSummary();
    }
}

// Clear all selections
function clearAllSelections() {
    if (!confirm('Are you sure you want to clear all selected services and equipment?')) {
        return;
    }
    
    // Uncheck all service checkboxes and hide sections
    document.querySelectorAll('.service-checkbox:checked').forEach(checkbox => {
        checkbox.checked = false;
        const serviceId = checkbox.value;
        const serviceItem = document.getElementById('service-item-' + serviceId);
        const instructorSection = document.getElementById('instructor-' + serviceId);
        const equipmentSection = document.getElementById('equipment-' + serviceId);
        
        // Remove selected state
        serviceItem.classList.remove('selected');
        
        // Hide instructor section
        instructorSection.classList.remove('show');
        
        // Hide equipment section
        equipmentSection.classList.remove('show');
        
        // Reset instructor selection
        const instructorSelect = document.getElementById('instructor-select-' + serviceId);
        if (instructorSelect) {
            instructorSelect.value = '';
        }
        
        // Reset equipment quantities
        const equipmentInputs = equipmentSection.querySelectorAll('.equipment-qty');
        equipmentInputs.forEach(input => {
            input.value = 0;
        });
    });
    
    // Clear tracking variables
    selectedServices.clear();
    selectedInstructors = {};
    selectedEquipment = {};
    
    // Update UI
    updateBookingSummary();
}

// Toggle service checkbox when clicking on the header
function toggleServiceCheckbox(serviceId) {
    const checkbox = document.getElementById('service-' + serviceId);
    checkbox.checked = !checkbox.checked;
    handleServiceChange(serviceId);
}

// Handle service checkbox change
function handleServiceChange(serviceId) {
    const checkbox = document.getElementById('service-' + serviceId);
    const serviceItem = document.getElementById('service-item-' + serviceId);
    const instructorSection = document.getElementById('instructor-' + serviceId);
    const equipmentSection = document.getElementById('equipment-' + serviceId);
    
    if (checkbox.checked) {
        selectedServices.add(serviceId);
        serviceItem.classList.add('selected');
        instructorSection.classList.add('show');
        equipmentSection.classList.add('show');
    } else {
        selectedServices.delete(serviceId);
        serviceItem.classList.remove('selected');
        instructorSection.classList.remove('show');
        equipmentSection.classList.remove('show');
        
        // Reset instructor selection
        const instructorSelect = document.getElementById('instructor-select-' + serviceId);
        if (instructorSelect) {
            instructorSelect.value = '';
        }
        delete selectedInstructors[serviceId];
        
        // Reset equipment quantities for this service
        const equipmentInputs = equipmentSection.querySelectorAll('.equipment-qty');
        equipmentInputs.forEach(input => {
            input.value = 0;
        });
        delete selectedEquipment[serviceId];
    }
    
    updateBookingSummary();
}

// Validate and store instructor selection
function validateInstructorSelection(serviceId) {
    const instructorSelect = document.getElementById('instructor-select-' + serviceId);
    if (instructorSelect && instructorSelect.value) {
        selectedInstructors[serviceId] = parseInt(instructorSelect.value);
    } else {
        delete selectedInstructors[serviceId];
    }
    updateBookingSummary();
}

// Update booking summary and total
function updateBookingSummary() {
    const summaryDiv = document.getElementById('bookingSummary');
    const totalPriceElement = document.getElementById('totalPrice');
    const clearAllBtn = document.getElementById('clearAllBtn');
    let total = 0;
    let summaryHTML = '';
    
    if (selectedServices.size === 0) {
        summaryDiv.innerHTML = '<p style="color: #999; font-style: italic;">No services selected yet</p>';
        totalPriceElement.textContent = '₱0.00';
        document.getElementById('nextStepBtn').disabled = true;
        clearAllBtn.style.display = 'none';
        return;
    }
    
    // Show Clear All button when services are selected
    clearAllBtn.style.display = 'block';
    
    // Build summary for each selected service
    selectedServices.forEach(serviceId => {
        const checkbox = document.getElementById('service-' + serviceId);
        const serviceName = checkbox.dataset.name;
        const servicePrice = parseFloat(checkbox.dataset.price);
        total += servicePrice;
        
        summaryHTML += `
            <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <strong style="color: #e50914;">${serviceName}</strong>
                    <span>₱${servicePrice.toFixed(2)}</span>
                </div>
        `;
        
        // Add equipment for this service
        const equipmentInputs = document.querySelectorAll(`.equipment-qty[data-service-id="${serviceId}"]`);
        let hasEquipment = false;
        
        equipmentInputs.forEach(input => {
            const qty = parseInt(input.value) || 0;
            if (qty > 0) {
                hasEquipment = true;
                const equipmentName = input.dataset.name;
                const equipmentPrice = parseFloat(input.dataset.price);
                const equipmentTotal = equipmentPrice * qty;
                total += equipmentTotal;
                
                summaryHTML += `
                    <div style="display: flex; justify-content: space-between; font-size: 13px; color: #666; margin-left: 10px; margin-top: 3px;">
                        <span>• ${equipmentName} (x${qty})</span>
                        <span>₱${equipmentTotal.toFixed(2)}</span>
                    </div>
                `;
                
                // Store equipment selection
                if (!selectedEquipment[serviceId]) {
                    selectedEquipment[serviceId] = {};
                }
                selectedEquipment[serviceId][input.dataset.equipmentId] = qty;
            }
        });
        
        summaryHTML += '</div>';
    });
    
    summaryDiv.innerHTML = summaryHTML;
    totalPriceElement.textContent = '₱' + total.toFixed(2);
    document.getElementById('nextStepBtn').disabled = false;
    
    // Update hidden form fields
    updateFormData();
}

// Update hidden form fields with selected data
function updateFormData() {
    const servicesArray = Array.from(selectedServices).map(serviceId => {
        const checkbox = document.getElementById('service-' + serviceId);
        return {
            service_id: serviceId,
            service_name: checkbox.dataset.name,
            service_price: checkbox.dataset.price,
            instructor_id: selectedInstructors[serviceId] || null
        };
    });
    
    document.getElementById('selected_services_data').value = JSON.stringify(servicesArray);
    document.getElementById('selected_equipment_data').value = JSON.stringify(selectedEquipment);
}

// Form validation
document.getElementById('serviceForm').addEventListener('submit', function(e) {
    if (selectedServices.size === 0) {
        e.preventDefault();
        alert('Please select at least one service to continue.');
        return;
    }
    
    // Check if instructors are selected for services that require them
    for (let serviceId of selectedServices) {
        const instructorSelect = document.getElementById('instructor-select-' + serviceId);
        
        // If there's an instructor dropdown for this service and it's required
        if (instructorSelect && servicesData[serviceId].Instructors.length > 0) {
            if (!instructorSelect.value) {
                e.preventDefault();
                const serviceName = servicesData[serviceId].ServiceType;
                alert(`Please select an instructor for "${serviceName}" before continuing.`);
                instructorSelect.focus();
                return;
            }
        }
    }
});
</script>
</body>
</html>
