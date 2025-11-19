<?php
session_start();
require_once '../../shared/config/db pdo.php';
require_once '../../shared/config/path_config.php';
require_once '../../shared/config/mail_config.php';

// Initialize variables
$name = $phone = $email = $password = $confirm_password = "";
$studio_name = $latitude = $longitude = $location = $time_in = $time_out = "";
$subscription_plan = "";
$billing_cycle = ""; // monthly or yearly
$error = "";

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = trim($_POST["name"]);
    $phone = trim($_POST["phone"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];
    $studio_name = trim($_POST["studio_name"]);
    $latitude = trim($_POST["latitude"]);
    $longitude = trim($_POST["longitude"]);
    $location = trim($_POST["location"]);
    $time_in = $_POST["time_in"];
    $time_out = $_POST["time_out"];
    $subscription_plan = $_POST["subscription_plan"];
    $billing_cycle = $_POST["billing_cycle"];

    // Validate input
    if (
        empty($name) || empty($phone) || empty($email) || empty($password) || empty($confirm_password) ||
        empty($studio_name) || empty($latitude) || empty($longitude) || empty($location) ||
        empty($time_in) || empty($time_out) || empty($subscription_plan)
    ) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strpos($email, '@') === false) {
        $error = "Invalid email format. Email must contain @.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one number.";
    } elseif (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>\/?\\\\|`~]/', $password)) {
        $error = "Password must contain at least one special character (!@#$%^&* etc.).";
    } else {
        // Validate phone format: +63 9xx xxx xxxx
        $phone_digits = preg_replace('/\D/', '', $phone);
        
        // Must be exactly 12 digits and start with 63
        if (strlen($phone_digits) !== 12 || !preg_match('/^63[0-9]{10}$/', $phone_digits)) {
            $error = "Invalid phone number format. Please use the format: +63 9xx xxx xxxx";
        } else {
            // Reformat phone to ensure consistency: +63 9xx xxx xxxx
            $phone = '+' . substr($phone_digits, 0, 2) . ' ' . 
                     substr($phone_digits, 2, 3) . ' ' . 
                     substr($phone_digits, 5, 3) . ' ' . 
                     substr($phone_digits, 8, 4);
        }
    }
    
    if (empty($error)) {
        // Check if email already exists in studio_owners table
        $stmt = $pdo->prepare("SELECT OwnerID FROM studio_owners WHERE Email = ?");
        $stmt->execute([$email]);
        $owner = $stmt->fetch();

        if ($owner) {
            $error = "Email already exists. Please use a different email.";
        }
        
        if (empty($error)) {




            







        


        

        

        

        

        

        

        


            // Check if email exists in clients table
            $stmt = $pdo->prepare("SELECT ClientID FROM clients WHERE Email = ?");
            $stmt->execute([$email]);
            $client = $stmt->fetch();

            if ($client) {
                $error = "Email already exists. Please use a different email.";
            }
        }
        
        if (empty($error)) {
            try {
                    $pdo->beginTransaction();

                    // Validate and set subscription plan
                    $selectedPlanId = (int)$subscription_plan;
                    $planStmt = $pdo->prepare("SELECT plan_id FROM subscription_plans WHERE plan_id = ? AND is_active = 1 LIMIT 1");
                    $planStmt->execute([$selectedPlanId]);
                    $validPlan = $planStmt->fetchColumn();
                    if (!$validPlan) {
                        // Default to Free Plan if selected plan is invalid
                        $planStmt = $pdo->prepare("SELECT plan_id FROM subscription_plans WHERE plan_name = ? AND is_active = 1 LIMIT 1");
                        $planStmt->execute(['Free Plan']);
                        $fallback = $planStmt->fetchColumn();
                        if ($fallback) {
                            $selectedPlanId = (int)$fallback;
                        } else {
                            // Fallback to first active plan
                            $planStmt = $pdo->prepare("SELECT plan_id FROM subscription_plans WHERE is_active = 1 ORDER BY plan_id ASC LIMIT 1");
                            $planStmt->execute();
                            $fallback2 = $planStmt->fetchColumn();
                            $selectedPlanId = $fallback2 ? (int)$fallback2 : 1;
                        }
                    }

                    // Insert studio owner with Pending verification (V_StatsID = 1) and subscription plan
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $ownerStmt = $pdo->prepare("INSERT INTO studio_owners (Name, Email, Phone, Password, V_StatsID, subscription_plan_id) VALUES (?, ?, ?, ?, 1, ?)");
                    $ownerStmt->execute([$name, $email, $phone, $hashedPassword, $selectedPlanId]);
                    $ownerId = (int)$pdo->lastInsertId();

                    // Insert studio with approved_by_admin = NULL
                    $approvedByAdmin = null;
                    $studioStmt = $pdo->prepare("INSERT INTO studios (OwnerID, StudioName, Latitude, Longitude, Loc_Desc, Time_IN, Time_OUT, approved_by_admin) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $studioStmt->execute([$ownerId, $studio_name, $latitude, $longitude, $location, $time_in, $time_out, $approvedByAdmin]);
                    $studioId = (int)$pdo->lastInsertId();

                    // Insert registration row for admin workflow with studio_id reference
                    $insert = $pdo->prepare("INSERT INTO studio_registrations (
                        studio_id, business_name, owner_name, owner_email, owner_phone, business_address, plan_id, subscription_duration
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert->execute([
                        $studioId,
                        $studio_name,
                        $name,
                        $email,
                        $phone,
                        $location,
                        $selectedPlanId,
                        $billing_cycle === 'yearly' ? 'yearly' : 'monthly'
                    ]);

                    $pdo->commit();

                    echo "<script>
                        alert('User Account and Studio Registration complete! For updates, please check your email.');
                        window.location.href = 'login.php';
                    </script>";





            





                    exit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error = "Unable to complete registration. Please try again.";
                }
            }
        }
    }
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Studio Owner Registration - MuSeek</title>
    <!-- Loading third party fonts -->
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="../../shared/assets/fonts/font-awesome.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NnF4CewBfU2V7ZzGItDxGdtjMA1lGfLrC5fW0pPp8=" crossorigin="anonymous"/>

    <style>
        body {
            background: url('../../shared/assets/images/dummy/slide-2.jpg') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            font-family: 'Source Sans Pro', sans-serif;
            color: #fff;
            margin: 0;
            padding: 0;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: -1;
        }

        #site-content {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            padding: 40px 0;
        }

        .fullwidth-block {
            text-align: center;
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        #branding {
            margin: 0 0 30px;
            display: block;
        }

        #branding img {
            width: 250px;
            margin: 0 auto;
            display: block;
        }

        .contact-form {
            max-width: 700px;
            margin: 0 auto;
            padding: 40px;
            background: rgba(0, 0, 0, 0.85);
            border-radius: 20px;
            text-align: center;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .contact-form h2 {
            font-size: 32px;
            margin-top: 0;
            margin-bottom: 30px;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: space-between;
        }

        .form-section {
            flex: 1 1 45%;
            min-width: 280px;
            margin-bottom: 20px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .form-section h3 {
            text-align: left;
            margin-top: 0;
            margin-bottom: 20px;
            color: #e50914;
            font-size: 20px;
            border-bottom: 2px solid rgba(229, 9, 20, 0.5);
            padding-bottom: 10px;
        }

        .form-group {
            position: relative;
            margin-bottom: 25px;
            text-align: left;
        }

        .form-group label {
            position: absolute;
            top: 15px;
            left: 15px;
            font-size: 16px;
            color: #ccc;
            transition: all 0.3s ease;
            pointer-events: none;
            z-index: 1;
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            padding-right: 40px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            border-radius: 8px;
            box-sizing: border-box;
            text-align: left;
            position: relative;
            z-index: 0;
            transition: all 0.3s ease;
        }

        /* Make selects look like inputs */
        .form-group select {
            width: 100%;
            padding: 15px;
            padding-right: 40px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            border-radius: 8px;
            box-sizing: border-box;
            text-align: left;
            position: relative;
            z-index: 0;
            transition: all 0.3s ease;
            appearance: none;
        }

        .form-group input:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(229, 9, 20, 0.5);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.25);
        }

        .form-group input::placeholder {
            color: transparent;
        }

        .form-group input:focus+label,
        .form-group input:not(:placeholder-shown)+label {
            top: -10px;
            left: 10px;
            font-size: 12px;
            color: #e50914;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 4px;
            padding: 0 8px;
            font-weight: 600;
        }

        /* Always show label in compact mode for selects */
        .form-group select + label {
            top: -10px;
            left: 10px;
            font-size: 12px;
            color: #e50914;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 4px;
            padding: 0 8px;
            font-weight: 600;
        }

        .form-group .toggle-password {
            position: absolute;
            right: 15px;
            top: 22px;
            color: #ccc;
            cursor: pointer;
            font-size: 16px;
            z-index: 2;
            transition: color 0.3s ease;
        }

        .form-group .toggle-password:hover {
            color: #e50914;
        }

        .contact-form input[type="submit"] {
            width: 100%;
            padding: 16px;
            font-size: 18px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(229, 9, 20, 0.3);
        }

        .contact-form input[type="submit"]:hover {
            background-color: #f40612;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(229, 9, 20, 0.4);
        }

        .contact-form .additional-options {
            text-align: center;
            margin-top: 25px;
            color: #999;
        }

        .contact-form .additional-options a,
        .contact-form .additional-options p {
            color: #999;
            text-decoration: none;
            font-size: 15px;
            transition: color 0.3s ease;
        }

        .contact-form .additional-options a {
            color: #e50914;
            font-weight: 600;
        }

        .contact-form .additional-options a:hover {
            text-decoration: underline;
            color: #f40612;
        }

        .contact-form .terms {
            text-align: left;
            margin: 20px 0;
            color: #ccc;
            display: flex;
            align-items: center;
        }

        .contact-form .terms input[type="checkbox"] {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            accent-color: #e50914;
        }

        .password-checklist {
            list-style: none;
            padding: 0;
            margin: 10px 0 0;
            font-size: 13px;
            color: #ccc;
        }
        .password-checklist li {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        .status-icon {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.3);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: transparent;
        }
        .password-checklist li.is-valid .status-icon {
            background: #16a34a;
            border-color: #16a34a;
            color: #fff;
        }
        .password-checklist li.is-invalid .status-icon {
            background: #dc2626;
            border-color: #dc2626;
            color: #fff;
        }
        .match-status {
            font-size: 13px;
            margin-top: 6px;
        }
        .match-status.is-valid { color: #10b981; }
        .match-status.is-invalid { color: #f87171; }

        .field-error {
            display: none;
            margin-top: 6px;
            color: #f87171;
            font-size: 12px;
        }
        .field-error.show { display: block; }
        .input-invalid {
            border-color: #f87171 !important;
        }

        .contact-form .error {
            color: #e87c03;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: left;
            background: rgba(232, 124, 3, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            border-left: 4px solid #e87c03;
        }

        .form-divider {
            width: 100%;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 20px 0;
        }

        .form-note {
            font-size: 13px;
            color: #999;
            margin-top: 5px;
            text-align: left;
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header p {
            color: #ccc;
            font-size: 16px;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Time fields side by side */
        .time-fields-row {
            display: flex;
            gap: 20px;
            margin-top: 25px;
            margin-bottom: 25px;
        }

        .time-fields-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        @media (max-width: 768px) {
            .contact-form {
                padding: 30px 20px;
            }

            .form-section {
                flex: 1 1 100%;
            }

            .time-fields-row {
                flex-direction: column;
                gap: 25px;
            }

            .time-fields-row .form-group {
                margin-bottom: 0;
            }
        }

        /* Map styles */
        #map {
            width: 100%;
            height: 320px;
            min-height: 320px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 15px;
            position: relative;
        }
        /* Ensure Leaflet container uses the explicit map height instead of growing to parent's height.
           Keep the map a fixed, responsive height so it doesn't expand unexpectedly. */
        #map.leaflet-container {
            width: 100% !important;
            height: 320px !important; /* match #map default height */
            max-height: 420px; /* avoid excessive growth on large screens */
            box-sizing: border-box;
            background: #1a1a1a; /* solid dark background avoids checkerboard look when tiles load */
        }
        .leaflet-container {
            background: #1a1a1a; /* ensure consistent dark background for the map canvas */
        }

        /* Prevent global image/canvas scaling from breaking Leaflet tiles */
        .leaflet-container img,
        .leaflet-container canvas {
            max-width: none !important;
            max-height: none !important;
        }
        /* Reinforce tile size in case of CSS resets */
        .leaflet-container .leaflet-tile {
            width: 256px !important;
            height: 256px !important;
            position: absolute !important; /* essential for tile positioning */
            left: 0; top: 0;
        }

        /* Ensure Leaflet panes and layers are absolutely positioned */
        .leaflet-container .leaflet-pane,
        .leaflet-container .leaflet-marker-icon,
        .leaflet-container .leaflet-marker-shadow,
        .leaflet-container .leaflet-overlay-pane,
        .leaflet-container .leaflet-shadow-pane,
        .leaflet-container .leaflet-marker-pane,
        .leaflet-container .leaflet-popup-pane,
        .leaflet-container .leaflet-map-pane,
        .leaflet-container .leaflet-layer {
            position: absolute;
            left: 0;
            top: 0;
        }

        /* Basic z-index map for core panes (subset of official Leaflet CSS) */
        .leaflet-overlay-pane { z-index: 400; }
        .leaflet-shadow-pane  { z-index: 500; }
        .leaflet-marker-pane  { z-index: 600; }
        .leaflet-popup-pane   { z-index: 700; }
        .leaflet-top, .leaflet-bottom { position: absolute; z-index: 1000; }
        .leaflet-zoom-animated { transform-origin: 0 0; }

        /* Location button styles */
        .location-btn {
            background: linear-gradient(45deg, #e50914, #f40612);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 8px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .location-btn:hover {
            background: linear-gradient(45deg, #f40612, #e50914);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.3);
        }

        .location-btn:active {
            transform: translateY(0);
        }

        .location-btn i {
            font-size: 16px;
        }

        /* Neutralize parent text alignment so inline tiles don't center if CSS fails */
        #map { text-align: left; }

        /* Dark theme for controls */
        .contact-form, .form-group input, .form-group select {
            color-scheme: dark;
        }

        /* Dark dropdown options for selects */
        .form-group select option {
            background-color: #111;
            color: #fff;
        }
        .map-note {
            font-size: 13px;
            color: #bbb;
            margin-top: 6px;
            text-align: left;
        }

        /* Subscription Plans Grid Styles */
        .subscription-plans {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .plan-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            position: relative;
            transition: all 0.3s ease;
            text-align: center;
        }

        .plan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(229, 9, 20, 0.2);
            border-color: rgba(229, 9, 20, 0.3);
        }

        .plan-card.popular {
            border-color: #e50914;
            background: rgba(229, 9, 20, 0.1);
            transform: scale(1.05);
        }

        .plan-card.popular:hover {
            transform: scale(1.05) translateY(-5px);
        }

        .popular-badge {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: #e50914;
            color: white;
            padding: 5px 15px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .plan-header {
            margin-bottom: 20px;
        }

        .plan-header h4 {
            color: #fff;
            font-size: 24px;
            margin: 0 0 15px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .plan-price {
            font-size: 32px;
            font-weight: 700;
            color: #e50914;
            margin-bottom: 5px;
        }

        .plan-price-yearly {
            font-size: 32px;
            font-weight: 700;
            color: #e50914;
            margin-bottom: 5px;
        }

        .price-separator {
            color: #ccc;
            font-size: 24px;
            margin: 0 5px;
        }

        .billing-cycle {
            color: #ccc;
            font-size: 16px;
            font-weight: 400;
        }

        .plan-features {
            text-align: left;
            margin-bottom: 25px;
        }

        .plan-features p {
            margin: 10px 0;
            color: #ccc;
            font-size: 14px;
        }

        .plan-features p strong {
            color: #fff;
        }

        .plan-features .fa-check {
            color: #4CAF50;
            margin-right: 8px;
            width: 16px;
        }

        .plan-selection {
            text-align: center;
        }

        /* Custom Radio Button Styles */
        .radio-container {
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            user-select: none;
            padding: 10px 15px;
            border-radius: 8px;
            transition: background-color 0.3s ease;
        }

        .radio-container:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .radio-container input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .radio-checkmark {
            position: relative;
            height: 20px;
            width: 20px;
            background-color: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            margin-right: 10px;
            transition: all 0.3s ease;
        }

        .radio-container input[type="radio"]:checked ~ .radio-checkmark {
            background-color: #e50914;
            border-color: #e50914;
        }

        .radio-checkmark:after {
            content: "";
            position: absolute;
            display: none;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: white;
        }

        .radio-container input[type="radio"]:checked ~ .radio-checkmark:after {
            display: block;
        }

        .radio-label {
            color: #fff;
            font-size: 16px;
            font-weight: 500;
        }

        /* Billing Cycle Selector */
        .billing-cycle-selector {
            text-align: center;
            margin-top: 20px;
        }

        .billing-cycle-selector h4 {
            color: #fff;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .cycle-options {
            display: flex;
            justify-content: center;
            gap: 30px;
        }

        .discount-badge {
            background: #4CAF50;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 5px;
        }

        @media (max-width: 768px) {
            .subscription-plans {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .plan-card.popular {
                transform: none;
            }
            
            .plan-card.popular:hover {
                transform: translateY(-5px);
            }
            
            .cycle-options {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>

<body class="header-collapse">
    <div id="site-content">
        <main class="main-content">
            <div class="fullwidth-block">
                <a id="branding">
                    <img src="<?php echo getImagePath('images/logo4.png'); ?>" alt="MuSeek">
                </a>
                <div class="contact-form">
                    <div class="form-header">
                        <h2>Register Your Studio</h2>
                        <p>Join the MuSeek community and showcase your studio to potential clients. Fill out the form below to get started.</p>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="error"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" data-persist-key="owner-register" novalidate>
                        <div class="form-container">
                            <div class="form-section">
                                <h3><i class="fa fa-user"></i> Owner Information</h3>
                                <div class="form-group">
                                    <input type="text" name="name" id="name" placeholder=" " value="<?php echo htmlspecialchars($name); ?>" required maxlength="100" pattern="^[A-Za-zÀ-ÖØ-öø-ÿ'.\- ]{2,100}$" title="Letters, spaces, apostrophes, and dashes only." data-allow="alpha">
                                    <label for="name">Owner Name</label>
                                </div>

                                <div class="form-group">
                                    <input type="text" name="phone" id="phone" placeholder=" " maxlength="17" value="<?php echo htmlspecialchars($phone); ?>" required inputmode="numeric" pattern="^\+63\s[0-9]{3}\s[0-9]{3}\s[0-9]{4}$">
                                    <label for="phone">Phone Number</label>
                                    <small class="input-note" style="display: block; margin-top: 6px; font-size: 13px; color: #ccc;">Format: +63 9xx xxx xxxx</small>
                                </div>
                                <div class="form-group">
                                    <input type="email" name="email" id="email" placeholder=" " value="<?php echo htmlspecialchars($email); ?>" required maxlength="120" data-email-validate>
                                    <label for="email">Email Address</label>
                                    <small class="field-error" data-email-error>Please enter a valid email address.</small>
                                </div>
                                <div class="form-group">
                                    <input type="password" name="password" id="password" placeholder=" " required minlength="8" maxlength="72" data-password-field autocomplete="new-password">
                                    <label for="password">Password</label>
                                    <i class="fa fa-eye toggle-password" onclick="togglePassword('password')"></i>
                                    <small class="input-note" style="display: block; margin-top: 6px; font-size: 13px; color: #ccc; line-height: 1.4;">
                                        Must be at least 8 characters with uppercase, lowercase, number, and special character (!@#$%^&*)
                                    </small>
                                    <ul class="password-checklist" data-password-checklist>
                                        <li data-rule="length" class="is-invalid"><span class="status-icon">&#10003;</span>At least 8 characters</li>
                                        <li data-rule="uppercase" class="is-invalid"><span class="status-icon">&#10003;</span>One uppercase letter</li>
                                        <li data-rule="lowercase" class="is-invalid"><span class="status-icon">&#10003;</span>One lowercase letter</li>
                                        <li data-rule="number" class="is-invalid"><span class="status-icon">&#10003;</span>One number</li>
                                        <li data-rule="special" class="is-invalid"><span class="status-icon">&#10003;</span>One special character</li>
                                    </ul>
                                </div>
                                <div class="form-group">
                                    <input type="password" name="confirm_password" id="confirm_password" placeholder=" " required minlength="8" maxlength="72" data-password-confirm autocomplete="new-password">
                                    <label for="confirm_password">Re-enter your password</label>
                                    <i class="fa fa-eye toggle-password" onclick="togglePassword('confirm_password')"></i>
                                    <div class="match-status" data-password-match></div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3><i class="fa fa-music"></i> Studio Information</h3>
                                <div class="form-group">
                                    <input type="text" name="studio_name" id="studio_name" placeholder=" " value="<?php echo htmlspecialchars($studio_name); ?>" required maxlength="150" data-allow="alpha">
                                    <label for="studio_name">Studio Name</label>
                                </div>
                                <div class="form-group">
                                    <input type="text" name="location" id="location" placeholder=" " value="<?php echo htmlspecialchars($location); ?>" required maxlength="255">
                                    <label for="location">Location Description</label>
                                    <div class="form-note">E.g., "Main St., Bacolod City, Negros Occidental"</div>
                                    <button type="button" id="get-current-location" class="location-btn" onclick="getCurrentLocation()">
                                        <i class="fa fa-location-arrow"></i> Get Current Location
                                    </button>
                                </div>

                                <div id="map"></div>
                                <div class="map-note">Tap or drag the pin to set the studio location. Coordinates are auto-filled from the map.</div>

                                <!-- Hidden coordinate fields -->
                                <input type="hidden" name="latitude" id="latitude" value="<?php echo htmlspecialchars($latitude); ?>" required>
                                <input type="hidden" name="longitude" id="longitude" value="<?php echo htmlspecialchars($longitude); ?>" required>
                                
                                <div class="time-fields-row">
                                    <div class="form-group">
                                        <select name="time_in" id="time_in" required>
                                            <?php
                                            // Generate 12-hour labels with hour-only values
                                            for ($h = 0; $h < 24; $h++) {
                                                $value = sprintf('%02d:00', $h);
                                                $ampm = $h < 12 ? 'AM' : 'PM';
                                                $hour12 = $h % 12; if ($hour12 === 0) $hour12 = 12;
                                                $label = $hour12 . ':00 ' . $ampm;
                                                $selected = ($time_in === $value) ? ' selected' : '';
                                                echo "<option value=\"$value\"$selected>$label</option>";
                                            }
                                            ?>
                                        </select>
                                        <label for="time_in">Opening Time</label>
                                    </div>
                                    <div class="form-group">
                                        <select name="time_out" id="time_out" required>
                                            <?php
                                            for ($h = 0; $h < 24; $h++) {
                                                $value = sprintf('%02d:00', $h);
                                                $ampm = $h < 12 ? 'AM' : 'PM';
                                                $hour12 = $h % 12; if ($hour12 === 0) $hour12 = 12;
                                                $label = $hour12 . ':00 ' . $ampm;
                                                $selected = ($time_out === $value) ? ' selected' : '';
                                                echo "<option value=\"$value\"$selected>$label</option>";
                                            }
                                            ?>
                                        </select>
                                        <label for="time_out">Closing Time</label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3><i class="fa fa-credit-card"></i> Subscription Plan</h3>
                                <div class="subscription-plans">
                                    <?php
                                    // Fetch subscription plans from database
                                    try {
                                        $plansStmt = $pdo->query("SELECT plan_id, plan_name, monthly_price, yearly_price, max_studios, max_instructors, features FROM subscription_plans WHERE is_active = 1 ORDER BY plan_id ASC");
                                        $plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        if (count($plans) >= 2) {
                                            // Display first 2 plans in grid
                                            foreach ($plans as $index => $plan) {
                                                $isPopular = $index === 1; // Second plan is popular
                                                ?>
                                                <div class="plan-card <?php echo $isPopular ? 'popular' : ''; ?>">
                                                    <?php if ($isPopular): ?>
                                                        <div class="popular-badge">Most Popular</div>
                                                    <?php endif; ?>
                                                    <div class="plan-header">
                                                        <h4><?php echo htmlspecialchars($plan['plan_name']); ?></h4>
                                                        <?php if ($plan['monthly_price'] == 0 && $plan['yearly_price'] == 0): ?>
                                                            <div class="plan-price">
                                                                <span class="monthly-price" style="color: #4CAF50; font-size: 36px; font-weight: 800;">FREE</span>
                                                            </div>
                                                            <div class="plan-price-yearly" style="display: none;">
                                                                <span class="yearly-price" style="color: #4CAF50; font-size: 36px; font-weight: 800;">FREE</span>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="plan-price">
                                                                <span class="monthly-price">₱<?php echo number_format($plan['monthly_price'], 2); ?></span>
                                                                <span class="price-separator">/</span>
                                                                <span class="billing-cycle">month</span>
                                                            </div>
                                                            <div class="plan-price-yearly" style="display: none;">
                                                                <span class="yearly-price">₱<?php echo number_format($plan['yearly_price'], 2); ?></span>
                                                                <span class="price-separator">/</span>
                                                                <span class="billing-cycle">year</span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="plan-features">
                                                        <p><strong>Up to <?php echo $plan['max_studios']; ?> Studios</strong></p>
                                                        <p><strong>Up to <?php echo $plan['max_instructors']; ?> Instructors</strong></p>
                                                        <?php
                                                        $features = explode(',', $plan['features']);
                                                        foreach ($features as $feature) {
                                                            echo '<p><i class="fa fa-check"></i> ' . htmlspecialchars(trim($feature)) . '</p>';
                                                        }
                                                        ?>
                                                    </div>
                                                    <div class="plan-selection">
                                                        <label class="radio-container">
                                                            <input type="radio" 
                                                                   name="subscription_plan" 
                                                                   value="<?php echo $plan['plan_id']; ?>" 
                                                                   data-is-free="<?php echo ($plan['monthly_price'] == 0 && $plan['yearly_price'] == 0) ? '1' : '0'; ?>"
                                                                   <?php echo $index === 0 ? 'checked' : ''; ?> 
                                                                   required
                                                                   onchange="handlePlanSelection(this)">
                                                            <span class="radio-checkmark"></span>
                                                            <span class="radio-label">Select Plan</span>
                                                        </label>
                                                    </div>
                                                </div>
                                                <?php
                                            }
                                        }
                                    } catch (PDOException $e) {
                                        echo '<div class="error">Unable to load subscription plans. Please contact support.</div>';
                                    }
                                    ?>
                                </div>
                                
                                <div class="billing-cycle-selector" id="billing-cycle-selector">
                                    <h4>Billing Cycle</h4>
                                    <div class="cycle-options">
                                        <label class="radio-container">
                                            <input type="radio" name="billing_cycle" value="monthly" checked onchange="toggleBillingCycle('monthly')">
                                            <span class="radio-checkmark"></span>
                                            <span class="radio-label">Monthly</span>
                                        </label>
                                        <label class="radio-container">
                                            <input type="radio" name="billing_cycle" value="yearly" onchange="toggleBillingCycle('yearly')">
                                            <span class="radio-checkmark"></span>
                                            <span class="radio-label">Annual <span class="discount-badge">Save 20%</span></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="billing-cycle-selector" id="free-plan-note" style="display: none;">
                                    <div style="background: rgba(76, 175, 80, 0.1); border: 2px solid #4CAF50; border-radius: 10px; padding: 20px; text-align: center;">
                                        <i class="fa fa-check-circle" style="font-size: 48px; color: #4CAF50; margin-bottom: 10px;"></i>
                                        <h4 style="color: #4CAF50; margin: 10px 0;">No Payment Required!</h4>
                                        <p style="color: #ccc; margin: 10px 0;">The Free Plan requires no subscription fee. You can start using your studio immediately after approval.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-divider"></div>

                        <div class="terms">
                            <input type="checkbox" name="terms" id="terms" required>
                            <label for="terms">I agree to all statements in the <a href="terms_of_service_owner.php" target="_blank" style="color: #e50914; text-decoration: underline;">Terms of Service</a> and Privacy Policy</label>
                        </div>

                        <input type="submit" value="Register Studio">
                    </form>

                    <div class="additional-options">
                        <p>Already have an account? <a href="login.php">Login here</a></p>
                        <p>Looking to book a studio? <a href="signin.php">Register as client</a></p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../js/form-persist.js"></script>
    <script src="../js/password-meter.js"></script>
    <script src="../js/input-validators.js"></script>
    <script>
        function togglePassword(fieldId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = passwordField.nextElementSibling.nextElementSibling;
            if (passwordField.type === "password") {
                passwordField.type = "text";
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = "password";
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        function toggleBillingCycle(cycle) {
            const monthlyPrices = document.querySelectorAll('.plan-price');
            const yearlyPrices = document.querySelectorAll('.plan-price-yearly');
            
            if (cycle === 'yearly') {
                monthlyPrices.forEach(price => price.style.display = 'none');
                yearlyPrices.forEach(price => price.style.display = 'block');
            } else {
                monthlyPrices.forEach(price => price.style.display = 'block');
                yearlyPrices.forEach(price => price.style.display = 'none');
            }
        }

        function handlePlanSelection(radio) {
            const isFree = radio.getAttribute('data-is-free') === '1';
            const billingCycleSelector = document.getElementById('billing-cycle-selector');
            const freePlanNote = document.getElementById('free-plan-note');
            
            if (isFree) {
                // Hide billing cycle selector and show free plan note
                billingCycleSelector.style.display = 'none';
                freePlanNote.style.display = 'block';
                // Set billing cycle to monthly by default for free plan (doesn't matter since it's free)
                document.querySelector('input[name="billing_cycle"][value="monthly"]').checked = true;
            } else {
                // Show billing cycle selector and hide free plan note
                billingCycleSelector.style.display = 'block';
                freePlanNote.style.display = 'none';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check which plan is selected initially
            const selectedPlan = document.querySelector('input[name="subscription_plan"]:checked');
            if (selectedPlan) {
                handlePlanSelection(selectedPlan);
            }
        });

        // Format phone number as +63 9xx xxx xxxx
        function formatPhoneNumber(input) {
            // Remove all non-digit characters
            let value = input.value.replace(/\D/g, '');
            
            // Ensure it starts with 63 if user types it, or prepend it
            if (value.startsWith('0')) {
                value = '63' + value.substring(1);
            } else if (!value.startsWith('63')) {
                if (value.length > 0) {
                    value = '63' + value;
                }
            }
            
            // Limit to 12 digits (63 + 10 digits)
            value = value.substring(0, 12);
            
            // Format as +63 9xx xxx xxxx
            let formatted = '';
            if (value.length > 0) {
                formatted = '+' + value.substring(0, 2); // +63
                if (value.length > 2) {
                    formatted += ' ' + value.substring(2, 5); // 9xx
                }
                if (value.length > 5) {
                    formatted += ' ' + value.substring(5, 8); // xxx
                }
                if (value.length > 8) {
                    formatted += ' ' + value.substring(8, 12); // xxxx
                }
            }
            
            input.value = formatted;
        }

        // Validate email contains @
        function validateEmail(input) {
            const email = input.value;
            if (email && !email.includes('@')) {
                input.setCustomValidity('Email must contain @');
            } else {
                input.setCustomValidity('');
            }
        }

        // Validate strong password
        function validatePassword(password) {
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
            };
            
            const allMet = Object.values(requirements).every(Boolean);
            
            return {
                valid: allMet,
                requirements: requirements
            };
        }

        // Initialize phone and email validation
        document.addEventListener('DOMContentLoaded', function() {
            const phoneInput = document.getElementById('phone');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const form = document.querySelector('.contact-form form');
            
            // Phone number formatting
            if (phoneInput) {
                phoneInput.addEventListener('input', function() {
                    formatPhoneNumber(this);
                });
                
                // Auto-fill +63 on focus if empty
                phoneInput.addEventListener('focus', function() {
                    if (this.value === '') {
                        this.value = '+63 ';
                    }
                });
            }
            
            // Email validation
            if (emailInput) {
                emailInput.addEventListener('input', function() {
                    validateEmail(this);
                });
                emailInput.addEventListener('blur', function() {
                    validateEmail(this);
                });
            }
            
            // Form validation on submit
            if (form && phoneInput && emailInput) {
                form.addEventListener('submit', function(e) {
                    // Validate phone format
                    const phoneValue = phoneInput.value.replace(/\D/g, '');
                    if (phoneValue.length !== 12 || !phoneValue.startsWith('63')) {
                        e.preventDefault();
                        alert('Please enter a valid Philippine phone number in format: +63 9xx xxx xxxx');
                        return false;
                    }
                    
                    // Validate email contains @
                    if (!emailInput.value.includes('@')) {
                        e.preventDefault();
                        alert('Please enter a valid email address with @');
                        return false;
                    }
                    
                    // Validate password strength
                    if (passwordInput && confirmPasswordInput) {
                        const passwordValidation = validatePassword(passwordInput.value);
                        if (!passwordValidation.valid) {
                            e.preventDefault();
                            let missingRequirements = [];
                            if (!passwordValidation.requirements.length) missingRequirements.push('at least 8 characters');
                            if (!passwordValidation.requirements.uppercase) missingRequirements.push('uppercase letter');
                            if (!passwordValidation.requirements.lowercase) missingRequirements.push('lowercase letter');
                            if (!passwordValidation.requirements.number) missingRequirements.push('number');
                            if (!passwordValidation.requirements.special) missingRequirements.push('special character (!@#$%^&*)');
                            
                            alert('Password must contain: ' + missingRequirements.join(', '));
                            return false;
                        }
                        
                        // Validate password confirmation
                        if (passwordInput.value !== confirmPasswordInput.value) {
                            e.preventDefault();
                            alert('Passwords do not match. Please re-enter your password.');
                            return false;
                        }
                    }
                });
            }
        });
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-0H6z9EobZ0x1QJkLbtZK3H2Y6D7Wv5bG3Y99nwy9G7A=" crossorigin="anonymous"></script>
    <script>
        (function() {
            const latInput = document.getElementById('latitude');
            const lngInput = document.getElementById('longitude');
            const locInput = document.getElementById('location');
            const mapEl = document.getElementById('map');

            if (!mapEl) return;

            let map, marker;
            let reverseCtrl = null;
            let geoDebounce = null;
            let lastQuery = '';

            function setFields(lat, lng) {
                latInput.value = Number(lat).toFixed(6);
                lngInput.value = Number(lng).toFixed(6);
            }

            // Improved reverse geocoding with abort controller and fallback
            async function reverseGeocode(lat, lng) {
                try {
                    if (reverseCtrl) { 
                        try { reverseCtrl.abort(); } catch (_) {} 
                    }
                    reverseCtrl = new AbortController();
                    
                    const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}`;
                    const res = await fetch(url, { 
                        headers: { 'Accept': 'application/json', 'Accept-Language': 'en' },
                        signal: reverseCtrl.signal 
                    });
                    
                    if (!res.ok) throw new Error('Reverse geocode failed');
                    
                    const data = await res.json();
                    if (data && data.display_name) {
                        locInput.value = data.display_name;
                    } else {
                        // Fallback: use coordinates
                        locInput.value = `${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)}`;
                    }
                } catch (err) {
                    // On error, fill with coordinates so field is not left empty
                    if (err.name !== 'AbortError') {
                        locInput.value = `${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)}`;
                    }
                }
            }

            // Live forward geocoding with debouncing
            async function forwardGeocode(query) {
                if (!query || query.trim().length < 3) return;
                try {
                    const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(query)}`;
                    const res = await fetch(url, { 
                        headers: { 'Accept': 'application/json', 'Accept-Language': 'en' } 
                    });
                    
                    if (!res.ok) return;
                    
                    const results = await res.json();
                    if (Array.isArray(results) && results.length) {
                        const best = results[0];
                        const lat = parseFloat(best.lat);
                        const lng = parseFloat(best.lon);
                        ensureMarker(lat, lng, true);
                        map.setView([lat, lng], 14);
                    }
                } catch (_) {
                    // Best-effort; ignore errors
                }
            }

            function ensureMarker(lat, lng, fromSearch = false) {
                const la = Number(lat);
                const lo = Number(lng);
                
                if (!marker) {
                    marker = L.marker([la, lo], { draggable: true }).addTo(map);
                    
                    marker.on('dragend', function(ev) {
                        const pos = ev.target.getLatLng();
                        setFields(pos.lat, pos.lng);
                        reverseGeocode(pos.lat, pos.lng);
                    });
                    
                    marker.on('click', function(ev) {
                        const pos = ev.target.getLatLng();
                        setFields(pos.lat, pos.lng);
                        reverseGeocode(pos.lat, pos.lng);
                    });
                } else {
                    marker.setLatLng([la, lo]);
                }
                
                setFields(la, lo);
                
                if (fromSearch) {
                    map.setView([la, lo], 17);
                }
                
                if (!fromSearch) {
                    reverseGeocode(la, lo);
                }
            }

            function initMap(lat, lng) {
                // Ensure default marker icon URLs resolve from CDN
                const DefaultIcon = L.icon({
                    iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
                    iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
                    shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    popupAnchor: [1, -34],
                    shadowSize: [41, 41]
                });
                L.Marker.prototype.options.icon = DefaultIcon;

                map = L.map('map');
                const defaultZoom = 15;
                map.setView([lat, lng], defaultZoom);
                
                const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                });
                
                tiles.on('tileerror', function() {
                    try { tiles.redraw(); } catch (_) {}
                });
                
                tiles.addTo(map);

                // Ensure proper rendering with multiple invalidateSize calls
                map.whenReady(() => {
                    try { map.invalidateSize(); } catch (_) {}
                });
                setTimeout(() => { try { map.invalidateSize(); } catch(_) {} }, 100);
                setTimeout(() => { try { map.invalidateSize(); } catch(_) {} }, 600);

                ensureMarker(lat, lng, true);
                setFields(lat, lng);

                map.on('click', function(e) {
                    const { lat, lng } = e.latlng;
                    ensureMarker(lat, lng);
                    reverseGeocode(lat, lng);
                });

                // Live geocoding when typing in location field
                if (locInput) {
                    locInput.addEventListener('input', function() {
                        const q = String(locInput.value || '').trim();
                        if (!q || q.length < 3) return;
                        if (q === lastQuery) return;
                        lastQuery = q;
                        
                        if (geoDebounce) clearTimeout(geoDebounce);
                        geoDebounce = setTimeout(() => forwardGeocode(q), 500);
                    });
                }

                // Keep size synced with container
                try {
                    const ro = new ResizeObserver(() => { 
                        try { map.invalidateSize(); } catch(_) {} 
                    });
                    ro.observe(mapEl);
                } catch (_) {}

                // Also respond to window resizes
                window.addEventListener('resize', function() {
                    try { map.invalidateSize(); } catch(_) {}
                });
            }

            function bootstrap() {
                let lat = parseFloat(latInput.value);
                let lng = parseFloat(lngInput.value);

                const hasCoords = !isNaN(lat) && !isNaN(lng);

                if (hasCoords) {
                    initMap(lat, lng);
                    reverseGeocode(lat, lng);
                } else if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        pos => {
                            const { latitude, longitude } = pos.coords;
                            initMap(latitude, longitude);
                            reverseGeocode(latitude, longitude);
                        },
                        () => {
                            // Fallback: Bacolod City coordinates
                            initMap(10.630673, 122.9786412);
                            reverseGeocode(10.630673, 122.9786412);
                        },
                        { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
                    );
                } else {
                    // No geolocation support
                    initMap(10.630673, 122.9786412);
                    reverseGeocode(10.630673, 122.9786412);
                }
            }

            function getCurrentLocation() {
                if (!navigator.geolocation) {
                    alert('Geolocation is not supported by your browser.');
                    return;
                }

                const btn = document.getElementById('get-current-location');
                const originalText = btn ? btn.innerHTML : '';
                
                if (btn) {
                    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Getting Location...';
                    btn.disabled = true;
                }

                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        const latitude = pos.coords.latitude;
                        const longitude = pos.coords.longitude;
                        const accuracy = pos.coords.accuracy;
                        
                        setFields(latitude, longitude);
                        ensureMarker(latitude, longitude);
                        
                        const zoomLevel = accuracy < 50 ? 18 : (accuracy < 100 ? 16 : 15);
                        if (map && typeof map.setView === 'function') {
                            map.setView([latitude, longitude], zoomLevel);
                            setTimeout(() => { try { map.invalidateSize(); } catch(_) {} }, 100);
                        }
                        
                        reverseGeocode(latitude, longitude);
                        
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fa fa-check"></i> Location Found!';
                            setTimeout(function() { 
                                btn.innerHTML = originalText; 
                            }, 2000);
                        }
                    },
                    function(error) {
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                        }
                        
                        let errorMessage = 'Unable to get your location.';
                        if (error && typeof error.code !== 'undefined') {
                            if (error.code === error.PERMISSION_DENIED) {
                                errorMessage = 'Location access denied. Please enable location services and try again.';
                            } else if (error.code === error.POSITION_UNAVAILABLE) {
                                errorMessage = 'Location information unavailable. Please check your device settings.';
                            } else if (error.code === error.TIMEOUT) {
                                errorMessage = 'Location request timed out. Please try again.';
                            }
                        }
                        alert(errorMessage);
                    },
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                );
            }

            try { window.getCurrentLocation = getCurrentLocation; } catch (_) {}

            // Ensure Leaflet is loaded with multiple fallback CDNs
            function ensureLeafletThenBootstrap() {
                if (typeof L !== 'undefined') {
                    bootstrap();
                    return;
                }
                
                // Primary fallback: unpkg with newer version
                const fallback = document.createElement('script');
                fallback.src = 'https://unpkg.com/leaflet@1.10.2/dist/leaflet.js';
                fallback.crossOrigin = 'anonymous';
                fallback.onload = function() {
                    try { 
                        bootstrap(); 
                    } catch (e) { 
                        console.error('Bootstrap failed after Leaflet load', e); 
                    }
                };
                fallback.onerror = function() {
                    // Secondary fallback: jsDelivr CDN
                    const secondary = document.createElement('script');
                    secondary.src = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js';
                    secondary.crossOrigin = 'anonymous';
                    secondary.onload = function() {
                        try { bootstrap(); } catch (e) { console.error('Bootstrap failed', e); }
                    };
                    secondary.onerror = function() {
                        console.error('Failed to load Leaflet from all fallback CDNs');
                        alert('Unable to load map. Please refresh the page.');
                    };
                    document.head.appendChild(secondary);
                };
                document.head.appendChild(fallback);
            }
            
            ensureLeafletThenBootstrap();
        })();
    </script>
</body>

</html>
