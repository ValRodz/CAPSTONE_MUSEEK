<?php
session_start();
include '../../shared/config/db.php';
require_once '../../shared/config/path_config.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo "<script>
        alert('Please log in to view this page.');
        window.location.href = '../../auth/php/login.php';
    </script>";
    exit;
}

// Check if booking_id is provided
if (!isset($_GET['booking_id'])) {
    header("Location: ../../");
    exit;
}

$booking_id = (int)$_GET['booking_id'];

// Fetch main booking details
$query = "SELECT b.*, s.StudioName, s.Loc_Desc, s.StudioImg, 
                 sch.Sched_Date, sch.Time_Start, sch.Time_End,
                 b.booking_date
          FROM bookings b
          JOIN studios s ON b.StudioID = s.StudioID
          JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
          WHERE b.BookingID = ? AND b.ClientID = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $booking_id, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    $row['StudioLetter'] = strtoupper(substr($row['StudioName'], 0, 1));
    if (!empty($row['StudioImg'])) {
        $row['StudioImgSrc'] = (strpos($row['StudioImg'], 'http') === 0 || strpos($row['StudioImg'], '/') === 0)
            ? $row['StudioImg']
            : getBasePath() . $row['StudioImg'];
    }
    $booking = $row;
    $booking_datetime = $row['booking_date'];
} else {
    header("Location: ../../");
    exit;
}
mysqli_stmt_close($stmt);

// Fetch related bookings created at the same time (grouped by booking_date)
$bookings = [];
$multi_query = "SELECT b.BookingID, sch.Sched_Date, sch.Time_Start, sch.Time_End
                FROM bookings b
                JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
                WHERE b.ClientID = ? AND b.booking_date = ?
                ORDER BY sch.Sched_Date, sch.Time_Start";

$stmt = mysqli_prepare($conn, $multi_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "is", $_SESSION['user_id'], $booking_datetime);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $bid = $row['BookingID'];
        
        // Fetch services for this booking (matching new_museek.sql schema)
        // booking_services columns: booking_service_id, BookingID, ServiceID, InstructorID, service_price
        $services_query = "SELECT bs.booking_service_id, bs.ServiceID, bs.service_price, 
                                  s.ServiceType, i.Name as InstructorName
                           FROM booking_services bs
                           JOIN services s ON bs.ServiceID = s.ServiceID
                           LEFT JOIN instructors i ON bs.InstructorID = i.InstructorID
                           WHERE bs.BookingID = ?";
        $services_stmt = mysqli_prepare($conn, $services_query);
        mysqli_stmt_bind_param($services_stmt, "i", $bid);
        mysqli_stmt_execute($services_stmt);
        $services_result = mysqli_stmt_get_result($services_stmt);
        
        $row['services'] = [];
        while ($srv_row = mysqli_fetch_assoc($services_result)) {
            $row['services'][] = $srv_row;
        }
        mysqli_stmt_close($services_stmt);
        
        // Fetch equipment for this booking (matching new_museek.sql schema)
        // booking_equipment columns: booking_equipment_id, booking_service_id, equipment_id, quantity, rental_price
        // Note: Equipment is linked via booking_services, not directly to bookings
        $equipment_query = "SELECT be.quantity as Quantity, be.rental_price as Price, ea.equipment_name as Name
                            FROM booking_equipment be
                            JOIN equipment_addons ea ON be.equipment_id = ea.equipment_id
                            JOIN booking_services bs ON be.booking_service_id = bs.booking_service_id
                            WHERE bs.BookingID = ?";
        $equipment_stmt = mysqli_prepare($conn, $equipment_query);
        mysqli_stmt_bind_param($equipment_stmt, "i", $bid);
        mysqli_stmt_execute($equipment_stmt);
        $equipment_result = mysqli_stmt_get_result($equipment_stmt);
        
        $row['equipment'] = [];
        while ($eq_row = mysqli_fetch_assoc($equipment_result)) {
            $row['equipment'][] = $eq_row;
        }
        mysqli_stmt_close($equipment_stmt);
        
        $bookings[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Determine if this is a multi-booking automatically
$is_multi = count($bookings) > 1;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Booking Confirmation - MuSeek</title>
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

        .confirmation-container {
            max-width: 800px;
            margin: 0 auto;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .confirmation-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .confirmation-icon {
            font-size: 64px;
            color: #4CAF50;
            margin-bottom: 20px;
        }

        .confirmation-title {
            color: #fff;
            font-size: 32px;
            margin-bottom: 10px;
        }

        .confirmation-subtitle {
            color: #ccc;
            font-size: 18px;
            margin-bottom: 30px;
        }

        .booking-summary {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .booking-details {
            display: flex;
            margin-bottom: 20px;
        }

        .studio-image {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            object-fit: cover;
            margin-right: 20px;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .letter-avatar {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            margin-right: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.1);
            background: linear-gradient(135deg, #222 60%, #444 140%);
            color: #fff;
            font-size: 48px;
            font-weight: 700;
        }

        .booking-info {
            flex: 1;
        }

        .booking-info h3 {
            color: #fff;
            margin-top: 0;
            margin-bottom: 10px;
        }

        .booking-info p {
            color: #ccc;
            margin: 5px 0;
        }

        .time-slots {
            margin: 30px 0;
            padding: 25px;
            background: linear-gradient(135deg, #1a1a1a 0%, #0d0d0d 100%);
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            border: 1px solid #333;
        }

        .time-slots h4 {
            color: #fff;
            margin-bottom: 20px;
            font-size: 1.3em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .time-slots h4 i {
            color: #dc143c;
            font-size: 1.2em;
        }

        .slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .slot-card {
            background: linear-gradient(135deg, #1a1a1a, #0d0d0d);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            border: 1px solid #333;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .slot-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 20, 60, 0.3);
            border-color: #dc143c;
            background: linear-gradient(135deg, #2a1a1a, #1d0d0d);
        }

        .slot-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #dc143c, #ff1744);
        }

        .slot-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #333;
        }

        .slot-number {
            display: flex;
            align-items: center;
        }

        .slot-badge {
            background: linear-gradient(135deg, #dc143c, #ff1744);
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9em;
            box-shadow: 0 2px 8px rgba(220, 20, 60, 0.4);
        }

        .slot-date {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            font-weight: 500;
        }

        .slot-date i {
            color: #dc143c;
            font-size: 1.1em;
        }

        .slot-time {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 12px 15px;
            background: rgba(220, 20, 60, 0.15);
            border-radius: 8px;
            border-left: 4px solid #dc143c;
        }

        .slot-time i {
            color: #dc143c;
            font-size: 1.2em;
        }

        .time-range {
            font-weight: 600;
            color: #fff;
            font-size: 1.05em;
        }

        .slot-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .slot-instructor,
        .slot-service {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid #333;
            border-radius: 6px;
            color: #ccc;
            transition: all 0.2s ease;
        }

        .slot-instructor:hover,
        .slot-service:hover {
            background: rgba(220, 20, 60, 0.1);
            border-color: #dc143c;
            color: #fff;
        }

        .slot-instructor i {
            color: #dc143c;
            width: 16px;
        }

        .slot-service i {
            color: #ff1744;
            width: 16px;
        }

        .slot-instructor span,
        .slot-service span {
            font-weight: 500;
        }

        .time-slot,
        .instructor-service {
            display: flex;
            justify-content: space-between;
            padding: 12px 15px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid #333;
            border-radius: 6px;
            margin-bottom: 8px;
            color: #fff;
        }

        .time-slot:last-child,
        .instructor-service:last-child {
            margin-bottom: 0;
        }

        .next-steps {
            background: rgba(233, 30, 99, 0.1);
            border-left: 4px solid #e91e63;
            padding: 15px 20px;
            margin: 30px 0;
            border-radius: 0 4px 4px 0;
        }

        .next-steps h3 {
            color: #e91e63;
            margin-top: 0;
            margin-bottom: 10px;
        }

        .next-steps ol {
            padding-left: 20px;
            margin: 0;
            color: #ccc;
        }

        .next-steps li {
            margin-bottom: 8px;
        }

        .payment-section {
            background: rgba(76, 175, 80, 0.1);
            border-left: 4px solid #4CAF50;
            padding: 20px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .payment-section h3 {
            color: #4CAF50;
            margin-top: 0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .payment-content {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }

        .payment-text {
            flex: 1;
            color: #ccc;
        }

        .payment-text p {
            margin-bottom: 12px;
            line-height: 1.6;
        }

        .payment-amount {
            background: rgba(76, 175, 80, 0.15);
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .amount-label {
            color: #4CAF50;
            font-weight: 600;
        }

        .amount-value {
            color: #fff;
            font-size: 20px;
            font-weight: 700;
        }

        .qr-code-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            min-width: 200px;
        }

        .gcash-qr {
            width: 150px;
            height: 150px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .qr-instruction {
            color: #333;
            font-weight: 600;
            margin: 0;
            font-size: 14px;
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }

        .button {
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            text-align: center;
        }

        .button.primary {
            background: #e50914;
            color: #fff;
        }

        .button.primary:hover {
            background: #f40612;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.3);
        }

        .button.secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .button.secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .confirmation-container {
                padding: 20px;
                margin: 0 15px;
            }

            .booking-details {
                flex-direction: column;
            }

            .studio-image {
                width: 100%;
                height: 200px;
                margin-right: 0;
                margin-bottom: 15px;
            }

            .payment-content {
                flex-direction: column;
                gap: 20px;
            }

            .qr-code-container {
                align-self: center;
                min-width: auto;
                width: 100%;
                max-width: 250px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .button {
                width: 100%;
            }

            .slots-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .slot-card {
                padding: 15px;
            }

            .slot-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .slot-date {
                font-size: 0.9em;
            }

            .slot-time {
                padding: 10px 12px;
            }

            .time-range {
                font-size: 1em;
            }
        }
    </style>
</head>

<body>
    <?php include '../../shared/components/navbar.php'; ?>

    <main class="main-content">
        <div class="fullwidth-block" style="padding: 60px 20px;">
            <div class="confirmation-container">
                <div class="confirmation-header">
                    <div class="confirmation-icon">
                        <i class="fa fa-check-circle"></i>
                    </div>
                    <h1 class="confirmation-title">Booking Confirmed!</h1>
                    <p class="confirmation-subtitle">Your booking has been successfully placed.</p>
                </div>

                <div class="booking-summary">
                    <div class="booking-details">
                        <?php if (!empty($booking['StudioImgSrc'])): ?>
                            <img src="<?php echo $booking['StudioImgSrc']; ?>" alt="<?php echo htmlspecialchars($booking['StudioName']); ?>" class="studio-image">
                        <?php else: ?>
                            <div class="letter-avatar"><?php echo htmlspecialchars($booking['StudioLetter']); ?></div>
                        <?php endif; ?>
                        <div class="booking-info">
                            <h3><?php echo htmlspecialchars($booking['StudioName']); ?></h3>
                            <p><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($booking['Loc_Desc']); ?></p>
                            <p><i class="fa fa-credit-card"></i> Booking ID: #<?php echo $booking['BookingID']; ?></p>
                        </div>
                    </div>



                    <?php if (!$is_multi && !empty($bookings)): ?>
                        <?php
                            $single_booking = $bookings[0];
                            $single_price = 0;
                            $single_initial = 0;
                            $price_query = "SELECT Amount, Init_Amount FROM payment WHERE BookingID = ?";
                            $price_stmt = mysqli_prepare($conn, $price_query);
                            if ($price_stmt) {
                                mysqli_stmt_bind_param($price_stmt, "i", $single_booking['BookingID']);
                                mysqli_stmt_execute($price_stmt);
                                $price_result = mysqli_stmt_get_result($price_stmt);
                                $price_row = mysqli_fetch_assoc($price_result);
                                $single_price = $price_row ? (float)$price_row['Amount'] : 0.0;
                                $single_initial = $price_row ? (float)$price_row['Init_Amount'] : 0.0;
                                mysqli_stmt_close($price_stmt);
                            }
                        ?>
                        <div class="time-slots">
                            <h4><i class="fas fa-calendar-alt"></i> Booked Time Slot</h4>
                            <div class="slots-grid" style="display:block;">
                                <div class="slot-card">
                                    <div class="slot-header">
                                        <div class="slot-number">
                                            <span class="slot-badge">1</span>
                                        </div>
                                        <div class="slot-date">
                                            <i class="fas fa-calendar"></i>
                                            <span><?php echo date('M j, Y', strtotime($single_booking['Sched_Date'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="slot-time">
                                        <i class="fas fa-clock"></i>
                                        <span class="time-range">
                                            <?php echo date('g:i A', strtotime($single_booking['Time_Start'])); ?> - 
                                            <?php echo date('g:i A', strtotime($single_booking['Time_End'])); ?>
                                        </span>
                                    </div>
                                    <div class="slot-details">
                                        <?php if (!empty($single_booking['services'])): ?>
                                            <div style="margin-bottom:12px;">
                                                <div style="color:#fff; font-weight:600; margin-bottom:8px;"><i class="fas fa-music"></i> Services</div>
                                                <?php foreach ($single_booking['services'] as $service): ?>
                                                    <div class="slot-service">
                                                        <i class="fas fa-check-circle" style="color:#4CAF50;"></i>
                                                        <span><?php echo htmlspecialchars($service['ServiceType']); ?></span>
                                                        <?php if (!empty($service['InstructorName'])): ?>
                                                            <span style="font-size:12px; color:#999;"> with <?php echo htmlspecialchars($service['InstructorName']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($single_booking['equipment'])): ?>
                                            <div class="slot-addons" style="margin-top:8px;">
                                                <div style="color:#fff; font-weight:600;"><i class="fas fa-tools"></i> Equipment Rentals</div>
                                                <?php foreach ($single_booking['equipment'] as $addon): ?>
                                                    <div class="slot-addon-line" style="display:flex; justify-content:space-between;">
                                                        <span style="color:#fff;"><?php echo htmlspecialchars($addon['Name']); ?></span>
                                                        <span style="color:#fff;">× <?php echo (int)$addon['Quantity']; ?> | ₱<?php echo number_format($addon['Price'], 2); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="slot-price" style="margin-top:12px; padding-top:12px; border-top:1px solid #333;">
                                            <i class="fas fa-tag"></i>
                                            <span>Price: ₱<?php echo number_format($single_price, 2); ?></span>
                                        </div>
                                        <div class="slot-initial">
                                            <i class="fas fa-money-bill"></i>
                                            <span>Initial Payment: ₱<?php echo number_format($single_initial, 2); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($is_multi && count($bookings) > 1): ?>
                        <div class="time-slots">
                            <h4><i class="fas fa-calendar-alt"></i> All Booked Time Slots</h4>
                            <div class="slots-grid">
                                <?php 
                                $total_initial_amount = 0;
                                foreach ($bookings as $index => $slot): 
                                    // Fetch price from payment table
                                    $price_query = "SELECT Amount, Init_Amount FROM payment WHERE BookingID = ?";
                                    $price_stmt = mysqli_prepare($conn, $price_query);
                                    mysqli_stmt_bind_param($price_stmt, "i", $slot['BookingID']);
                                    mysqli_stmt_execute($price_stmt);
                                    $price_result = mysqli_stmt_get_result($price_stmt);
                                    $price_row = mysqli_fetch_assoc($price_result);
                                    
                                    $slot_price = $price_row ? $price_row['Amount'] : 0;
                                    $slot_initial = $price_row ? $price_row['Init_Amount'] : 0;
                                    $total_initial_amount += $slot_initial;
                                    mysqli_stmt_close($price_stmt);
                                ?>
                                    <div class="slot-card">
                                        <div class="slot-header">
                                            <div class="slot-number">
                                                <span class="slot-badge"><?php echo $index + 1; ?></span>
                                            </div>
                                            <div class="slot-date">
                                                <i class="fas fa-calendar"></i>
                                                <span><?php echo date('M j, Y', strtotime($slot['Sched_Date'])); ?></span>
                                            </div>
                                        </div>
                                        <div class="slot-time">
                                            <i class="fas fa-clock"></i>
                                            <span class="time-range">
                                                <?php echo date('g:i A', strtotime($slot['Time_Start'])); ?> - 
                                                <?php echo date('g:i A', strtotime($slot['Time_End'])); ?>
                                            </span>
                                        </div>
                                        <div class="slot-details">
                                            <?php if (!empty($slot['services'])): ?>
                                                <div style="margin-bottom:12px;">
                                                    <div style="color:#fff; font-weight:600; margin-bottom:8px;"><i class="fas fa-music"></i> Services</div>
                                                    <?php foreach ($slot['services'] as $service): ?>
                                                        <div class="slot-service">
                                                            <i class="fas fa-check-circle" style="color:#4CAF50;"></i>
                                                            <span><?php echo htmlspecialchars($service['ServiceType']); ?></span>
                                                            <?php if (!empty($service['InstructorName'])): ?>
                                                                <span style="font-size:12px; color:#999;"> with <?php echo htmlspecialchars($service['InstructorName']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($slot['equipment'])): ?>
                                                <div class="slot-addons" style="margin-top:8px;">
                                                    <div style="color:#fff; font-weight:600;"><i class="fas fa-tools"></i> Equipment Rentals</div>
                                                    <?php foreach ($slot['equipment'] as $addon): ?>
                                                        <div class="slot-addon-line" style="display:flex; justify-content:space-between;">
                                                            <span style="color:#fff;"><?php echo htmlspecialchars($addon['Name']); ?></span>
                                                            <span style="color:#fff;">× <?php echo (int)$addon['Quantity']; ?> | ₱<?php echo number_format($addon['Price'], 2); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="slot-price" style="margin-top:12px; padding-top:12px; border-top:1px solid #333;">
                                                <i class="fas fa-tag"></i>
                                                <span>Price: ₱<?php echo number_format($slot_price, 2); ?></span>
                                            </div>
                                            <div class="slot-initial">
                                                <i class="fas fa-money-bill"></i>
                                                <span>Initial Payment: ₱<?php echo number_format($slot_initial, 2); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="next-steps">
                    <h3>What's Next?</h3>
                    <ol>
                        <li>You'll receive a confirmation email with your booking details shortly.</li>
                        <li>Please arrive at least 15 minutes before your scheduled time.</li>
                        <li>Bring a valid ID for verification.</li>
                        <li>Check your email for any updates or changes to your booking.</li>
                    </ol>
                </div>

                <?php 
                // Calculate the payment amount to display
                $payment_amount = $is_multi && count($bookings) > 1 ? $total_initial_amount : $single_initial;
                
                // Check if payment has been submitted
                $payment_submitted = false;
                $payment_verified = false;
                $payment_reference = '';
                
                if ($is_multi && count($bookings) > 1) {
                    // Check first booking's payment status as representative
                    $payment_check = "SELECT p.Pay_Stats, g.Ref_Num 
                                     FROM payment p 
                                     LEFT JOIN g_cash g ON p.GCashID = g.GCashID 
                                     WHERE p.BookingID = ?";
                    $payment_stmt = mysqli_prepare($conn, $payment_check);
                    mysqli_stmt_bind_param($payment_stmt, "i", $bookings[0]['BookingID']);
                    mysqli_stmt_execute($payment_stmt);
                    $payment_result = mysqli_stmt_get_result($payment_stmt);
                    if ($payment_row = mysqli_fetch_assoc($payment_result)) {
                        $payment_submitted = !empty($payment_row['Ref_Num']);
                        $payment_verified = strtolower($payment_row['Pay_Stats']) === 'Completed';
                        $payment_reference = $payment_row['Ref_Num'] ?? '';
                    }
                    mysqli_stmt_close($payment_stmt);
                } else {
                    // Single booking
                    $payment_check = "SELECT p.Pay_Stats, g.Ref_Num 
                                     FROM payment p 
                                     LEFT JOIN g_cash g ON p.GCashID = g.GCashID 
                                     WHERE p.BookingID = ?";
                    $payment_stmt = mysqli_prepare($conn, $payment_check);
                    mysqli_stmt_bind_param($payment_stmt, "i", $booking['BookingID']);
                    mysqli_stmt_execute($payment_stmt);
                    $payment_result = mysqli_stmt_get_result($payment_stmt);
                    if ($payment_row = mysqli_fetch_assoc($payment_result)) {
                        $payment_submitted = !empty($payment_row['Ref_Num']);
                        $payment_verified = strtolower($payment_row['Pay_Stats']) === 'Completed';
                        $payment_reference = $payment_row['Ref_Num'] ?? '';
                    }
                    mysqli_stmt_close($payment_stmt);
                }
                ?>
                
                <div class="payment-section">
                    <h3><i class="fas fa-credit-card"></i> Payment Information</h3>
                    
                    <!-- Payment Status Badge -->
                    <div style="text-align: center; margin-bottom: 20px;">
                        <?php if ($payment_verified): ?>
                            <span style="display: inline-block; background: #4CAF50; color: white; padding: 10px 20px; border-radius: 20px; font-weight: 600;">
                                <i class="fas fa-check-circle"></i> Payment Verified
                            </span>
                        <?php elseif ($payment_submitted): ?>
                            <span style="display: inline-block; background: #ff9800; color: white; padding: 10px 20px; border-radius: 20px; font-weight: 600;">
                                <i class="fas fa-clock"></i> Payment Under Review
                            </span>
                        <?php else: ?>
                            <span style="display: inline-block; background: #2196F3; color: white; padding: 10px 20px; border-radius: 20px; font-weight: 600;">
                                <i class="fas fa-info-circle"></i> Payment Required
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($payment_verified): ?>
                        <!-- Payment Verified -->
                        <div style="background: rgba(76, 175, 80, 0.2); border: 2px solid #4CAF50; border-radius: 8px; padding: 20px; text-align: center;">
                            <i class="fas fa-check-circle" style="font-size: 48px; color: #4CAF50; margin-bottom: 15px;"></i>
                            <h4 style="color: #4CAF50; margin: 0 0 10px 0;">Payment Confirmed!</h4>
                            <p style="color: #ccc; margin: 0;">Your payment has been verified. See you at your session!</p>
                            <?php if ($payment_reference): ?>
                                <p style="color: #999; margin-top: 10px; font-size: 14px;">
                                    Reference: <strong><?php echo htmlspecialchars($payment_reference); ?></strong>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                    <?php elseif ($payment_submitted): ?>
                        <!-- Payment Submitted, Awaiting Verification -->
                        <div style="background: rgba(255, 152, 0, 0.2); border: 2px solid #ff9800; border-radius: 8px; padding: 20px; text-align: center;">
                            <i class="fas fa-clock" style="font-size: 48px; color: #ff9800; margin-bottom: 15px;"></i>
                            <h4 style="color: #ff9800; margin: 0 0 10px 0;">Payment Under Review</h4>
                            <p style="color: #ccc; margin: 0;">We've received your payment submission and it's being verified.</p>
                            <p style="color: #999; margin-top: 10px; font-size: 14px;">
                                Reference: <strong><?php echo htmlspecialchars($payment_reference); ?></strong>
                            </p>
                        </div>
                        
                    <?php else: ?>
                        <!-- Payment Form -->
                        <!-- GCash Payment Details -->
                        <div style="background: #fff; border: 3px solid #4CAF50; border-radius: 12px; padding: 30px; margin: 30px 0; text-align: center;">
                            <h4 style="color: #4CAF50; margin-bottom: 20px;">
                                <i class="fas fa-wallet"></i> Send Payment to GCash
                            </h4>
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                                <p style="margin: 0; font-size: 14px; color: #666;">Initial Amount to Pay:</p>
                                <h3 style="margin: 5px 0; color: #4CAF50; font-weight: bold; font-size: 32px;">
                                    ₱<?php echo number_format($payment_amount, 2); ?>
                                </h3>
                                <hr style="margin: 15px 0;">
                                <p style="margin: 0; font-size: 14px; color: #666;">GCash Number:</p>
                                <h2 style="margin: 10px 0; color: #2196F3; font-weight: bold; font-size: 36px;" id="gcashNumber">
                                    09508199489
                                </h2>
                                <button type="button" style="background: #2196F3; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 600; margin-top: 10px;" onclick="copyGCashNumber()" id="copyBtn">
                                    <i class="fas fa-clipboard"></i> Copy Number
                                </button>
                                <p style="margin: 10px 0 0 0; font-size: 13px; color: #666;">
                                    <i class="fas fa-music"></i> Museek Studio Booking
                                </p>
                            </div>
                            
                            <div style="margin-top: 20px;">
                                <button type="button" style="background: transparent; color: #ccc; border: 1px solid #ccc; padding: 8px 16px; border-radius: 4px; cursor: pointer;" onclick="document.getElementById('qrSection').style.display = document.getElementById('qrSection').style.display === 'none' ? 'block' : 'none'">
                                    <i class="fas fa-qr-code"></i> Or Use QR Code (Optional)
                                </button>
                            </div>
                        </div>
                        
                        <!-- QR Code Section (Collapsible) -->
                        <div id="qrSection" style="display: none; text-align: center; margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                            <img src="../../shared/assets/images/images/GCash.webp" alt="GCash QR Code" style="max-width: 250px; border: 2px solid #ddd; border-radius: 8px;">
                            <p style="margin-top: 10px; color: #666;">
                                <i class="fas fa-info-circle"></i> Scan this QR code with your GCash app
                            </p>
                        </div>
                        
                        <!-- Payment Submission Form -->
                        <div style="background: rgba(255, 255, 255, 0.05); border-radius: 10px; padding: 25px; margin-top: 20px;">
                            <h5 style="color: #fff; margin-bottom: 15px;"><i class="fas fa-file-upload"></i> After Payment, Submit Your Details:</h5>
                            <p style="color: #ccc; margin-bottom: 20px;">Once you've sent the payment via GCash, submit your transaction information below:</p>
                            
                            <form id="bookingPaymentForm" enctype="multipart/form-data">
                                <input type="hidden" name="booking_id" value="<?php echo $booking['BookingID']; ?>">
                                <input type="hidden" name="is_multi" value="<?php echo $is_multi ? '1' : '0'; ?>">
                                <?php if ($is_multi): ?>
                                    <input type="hidden" name="booking_datetime" value="<?php echo htmlspecialchars($booking_datetime); ?>">
                                <?php endif; ?>
                                
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; color: #ccc; margin-bottom: 5px; font-weight: 600;">
                                        <i class="fas fa-phone"></i> Your GCash Mobile Number <span style="color: #e50914;">*</span>
                                    </label>
                                    <input type="tel" id="sender_number" name="sender_number" 
                                           placeholder="09XX XXX XXXX" required pattern="[0-9]{11}"
                                           style="width: 100%; padding: 12px; background: rgba(0,0,0,0.3); border: 1px solid #333; border-radius: 4px; color: #fff; font-size: 16px;">
                                    <small style="color: #999; font-size: 13px;">Enter the 11-digit mobile number you used to send the payment</small>
                                </div>
                                
                                <div style="margin-bottom: 15px; background: rgba(255, 193, 7, 0.1); padding: 15px; border-radius: 8px; border: 2px solid #ffc107;">
                                    <label style="display: block; color: #ffc107; margin-bottom: 5px; font-weight: 700;">
                                        <i class="fas fa-hashtag"></i> GCash Reference Number <span style="color: #e50914;">*</span>
                                    </label>
                                    <input type="text" id="reference_number" name="reference_number" 
                                           placeholder="1234 5678 90123" required maxlength="17" inputmode="numeric"
                                           style="width: 100%; padding: 12px; background: rgba(0,0,0,0.5); border: 1px solid #ffc107; border-radius: 4px; color: #fff; font-size: 18px; font-weight: bold; letter-spacing: 1px;">
                                    <small style="color: #ffc107; font-size: 13px;">
                                        <i class="fas fa-exclamation-triangle"></i> <strong>IMPORTANT:</strong> This is the unique 13-digit number from your GCash receipt
                                    </small>
                                    <div id="refError" style="color: #e50914; margin-top: 5px; font-size: 13px; display: none;">
                                        <i class="fas fa-exclamation-circle"></i> Reference number must be exactly 13 digits
                                    </div>
                                </div>
                                
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; color: #ccc; margin-bottom: 5px; font-weight: 600;">
                                        <i class="fas fa-image"></i> Payment Screenshot <span style="color: #e50914;">*</span>
                                    </label>
                                    <input type="file" id="payment_proof" name="payment_proof" 
                                           accept="image/jpeg,image/png,image/jpg" required
                                           style="width: 100%; padding: 12px; background: rgba(0,0,0,0.3); border: 1px solid #333; border-radius: 4px; color: #fff;">
                                    <small style="color: #999; font-size: 13px;">Upload a clear screenshot of your GCash receipt (Max 5MB, JPG/PNG only)</small>
                                </div>
                                
                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; color: #ccc; margin-bottom: 5px; font-weight: 600;">
                                        <i class="fas fa-comment"></i> Additional Notes (Optional)
                                    </label>
                                    <textarea id="notes" name="notes" rows="2" 
                                              placeholder="Any additional information..."
                                              style="width: 100%; padding: 12px; background: rgba(0,0,0,0.3); border: 1px solid #333; border-radius: 4px; color: #fff; font-size: 14px; resize: vertical;"></textarea>
                                </div>
                                
                                <div id="alertArea" style="margin-bottom: 15px;"></div>
                                
                                <button type="submit" id="submitPaymentBtn" style="width: 100%; padding: 15px; background: linear-gradient(135deg, #4CAF50, #45a049); color: white; border: none; border-radius: 4px; font-size: 16px; font-weight: 700; cursor: pointer; transition: all 0.3s ease;">
                                    <i class="fas fa-paper-plane"></i> Submit Payment Proof
                                </button>
                            </form>
                        </div>
                        
                        <!-- Payment Instructions -->
                        <div style="background: rgba(33, 150, 243, 0.1); border-left: 4px solid #2196F3; padding: 15px 20px; margin-top: 20px; border-radius: 0 4px 4px 0;">
                            <h6 style="color: #2196F3; margin-top: 0;"><i class="fas fa-lightbulb"></i> How to Complete Payment:</h6>
                            <ol style="color: #ccc; padding-left: 20px; margin: 0;">
                                <li>Open your GCash app</li>
                                <li>Select "Send Money"</li>
                                <li>Enter the GCash number: <strong>09508199489</strong></li>
                                <li>Enter the amount: <strong>₱<?php echo number_format($payment_amount, 2); ?></strong></li>
                                <li>Complete the transaction</li>
                                <li>Take a screenshot of your receipt</li>
                                <li>Copy your <strong>13-digit Reference Number</strong> from the receipt</li>
                                <li>Fill out the form above and submit</li>
                            </ol>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="action-buttons">
                    <a href="../../client/php/client_bookings.php" class="button primary">View My Bookings</a>
                    <a href="../../" class="button secondary">Book Another Session</a>
                </div>
            </div>
        </div>
    </main>

    <?php include '../../shared/components/footer.php'; ?>
    <script>
    // Copy GCash Number functionality
    function copyGCashNumber() {
        const number = '09508199489';
        navigator.clipboard.writeText(number).then(function() {
            const btn = document.getElementById('copyBtn');
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Copied!';
            btn.style.background = '#4CAF50';
            setTimeout(function() {
                btn.innerHTML = original;
                btn.style.background = '#2196F3';
            }, 2000);
        }).catch(function(err) {
            alert('Failed to copy: ' + err);
        });
    }
    
    // Auto-format GCash Reference Number (13 digits: XXXX XXXX XXXXX)
    document.addEventListener('DOMContentLoaded', function() {
        const refInput = document.getElementById('reference_number');
        const refError = document.getElementById('refError');
        
        if (refInput) {
            refInput.addEventListener('input', function(e) {
                // Remove all non-numeric characters
                let value = e.target.value.replace(/\D/g, '');
                
                // Limit to 13 digits
                value = value.substring(0, 13);
                
                // Format as XXXX XXXX XXXXX
                let formatted = '';
                if (value.length > 0) {
                    formatted = value.substring(0, 4);
                }
                if (value.length > 4) {
                    formatted += ' ' + value.substring(4, 8);
                }
                if (value.length > 8) {
                    formatted += ' ' + value.substring(8, 13);
                }
                
                e.target.value = formatted;
                
                // Validation feedback
                const digitCount = value.length;
                if (digitCount > 0 && digitCount < 13) {
                    refError.style.display = 'block';
                } else if (digitCount === 13) {
                    refError.style.display = 'none';
                } else {
                    refError.style.display = 'none';
                }
            });
            
            // Prevent paste of non-numeric characters
            refInput.addEventListener('paste', function(e) {
                e.preventDefault();
                const pasteData = (e.clipboardData || window.clipboardData).getData('text');
                const numericOnly = pasteData.replace(/\D/g, '');
                
                // Trigger input event to format
                e.target.value = numericOnly;
                e.target.dispatchEvent(new Event('input'));
            });
        }
        
        // Booking Payment Form Submission
        const paymentForm = document.getElementById('bookingPaymentForm');
        if (paymentForm) {
            paymentForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const submitBtn = document.getElementById('submitPaymentBtn');
                const alertArea = document.getElementById('alertArea');
                const refInput = document.getElementById('reference_number');
                
                // Validate reference number has exactly 13 digits
                const refDigits = refInput.value.replace(/\D/g, '');
                if (refDigits.length !== 13) {
                    alertArea.innerHTML = '<div style="background: rgba(229, 9, 20, 0.2); border: 2px solid #e50914; border-radius: 4px; padding: 10px; color: #fff;"><i class="fas fa-exclamation-triangle"></i> Reference number must be exactly 13 digits</div>';
                    refInput.focus();
                    return;
                }
                
                // Create FormData and strip spaces from reference number
                const formData = new FormData(this);
                formData.set('reference_number', refDigits); // Send only digits to backend
                
                // Validate file size
                const fileInput = document.getElementById('payment_proof');
                if (fileInput.files[0] && fileInput.files[0].size > 5 * 1024 * 1024) {
                    alertArea.innerHTML = '<div style="background: rgba(229, 9, 20, 0.2); border: 2px solid #e50914; border-radius: 4px; padding: 10px; color: #fff;"><i class="fas fa-exclamation-triangle"></i> File size must be less than 5MB</div>';
                    return;
                }
                
                // Disable button and show loading
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span style="display: inline-block; width: 16px; height: 16px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite; margin-right: 8px;"></span>Submitting...';
                alertArea.innerHTML = '';
                
                // Add CSS animation for spinner
                if (!document.getElementById('spinnerStyle')) {
                    const style = document.createElement('style');
                    style.id = 'spinnerStyle';
                    style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
                    document.head.appendChild(style);
                }
                
                try {
                    const response = await fetch('process-booking-payment.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        alertArea.innerHTML = '<div style="background: rgba(76, 175, 80, 0.2); border: 2px solid #4CAF50; border-radius: 4px; padding: 10px; color: #fff;"><i class="fas fa-check-circle"></i> ' + data.message + '</div>';
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } else {
                        alertArea.innerHTML = '<div style="background: rgba(229, 9, 20, 0.2); border: 2px solid #e50914; border-radius: 4px; padding: 10px; color: #fff;"><i class="fas fa-exclamation-triangle"></i> ' + data.message + '</div>';
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Payment Proof';
                    }
                } catch (error) {
                    alertArea.innerHTML = '<div style="background: rgba(229, 9, 20, 0.2); border: 2px solid #e50914; border-radius: 4px; padding: 10px; color: #fff;"><i class="fas fa-exclamation-triangle"></i> Network error. Please try again.</div>';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Payment Proof';
                }
            });
        }
    });
    </script>
</body>

</html>
