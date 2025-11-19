<?php
session_start();
include '../../shared/config/db pdo.php';
require_once __DIR__ . '/validation_utils.php';

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];

// Function to get subscription limits for the owner
function getSubscriptionLimits($pdo, $owner_id) {
    $stmt = $pdo->prepare("
        SELECT 
            sp.max_services, 
            sp.max_instructors, 
            sp.max_studios,
            sp.plan_name,
            so.subscription_status
        FROM studio_owners so
        LEFT JOIN subscription_plans sp ON so.subscription_plan_id = sp.plan_id
        WHERE so.OwnerID = ?
    ");
    $stmt->execute([$owner_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Default limits if no subscription plan
    if (!$result || !$result['max_services']) {
        return [
            'max_services' => 20,
            'max_instructors' => 10,
            'max_studios' => 5,
            'plan_name' => 'Starter',
            'subscription_status' => 'active'
        ];
    }
    
    return $result;
}

// Function to get current counts
function getCurrentCounts($pdo, $owner_id) {
    // Count services
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE OwnerID = ?");
    $stmt->execute([$owner_id]);
    $service_count = (int)$stmt->fetchColumn();
    
    // Count instructors (all members - instructors + staff)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM instructors WHERE OwnerID = ?");
    $stmt->execute([$owner_id]);
    $instructor_count = (int)$stmt->fetchColumn();
    
    // Count by type
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM instructors WHERE OwnerID = ? AND member_type = 'instructor'");
    $stmt->execute([$owner_id]);
    $instructor_only_count = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM instructors WHERE OwnerID = ? AND member_type = 'staff'");
    $stmt->execute([$owner_id]);
    $staff_count = (int)$stmt->fetchColumn();
    
    // Count studios (only approved studios)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM studios 
        WHERE OwnerID = ? 
        AND approved_by_admin IS NOT NULL 
        AND approved_at IS NOT NULL
    ");
    $stmt->execute([$owner_id]);
    $studio_count = (int)$stmt->fetchColumn();
    
    return [
        'services' => $service_count,
        'instructors' => $instructor_count,
        'instructors_only' => $instructor_only_count,
        'staff' => $staff_count,
        'studios' => $studio_count
    ];
}

// Get subscription limits and current counts
$limits = getSubscriptionLimits($pdo, $owner_id);
$counts = getCurrentCounts($pdo, $owner_id);

// Handle AJAX requests for equipment management
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax'];
    
    try {
        if ($action === 'get_equipment') {
            $service_id = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
            $stmt = $pdo->prepare("
                SELECT e.* FROM equipment_addons e
                INNER JOIN services s ON e.service_id = s.ServiceID
                WHERE e.service_id = ? AND s.OwnerID = ?
                ORDER BY e.equipment_name
            ");
            $stmt->execute([$service_id, $owner_id]);
            $equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'equipment' => $equipment]);
            exit;
        }
        
        if ($action === 'add_equipment') {
            $service_id = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
            $name = trim($_POST['equipment_name'] ?? '');
            $description = trim($_POST['equipment_description'] ?? '');
            $price = (float)($_POST['rental_price'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 1);
            
            // Verify service ownership
            $checkStmt = $pdo->prepare("SELECT ServiceID FROM services WHERE ServiceID = ? AND OwnerID = ?");
            $checkStmt->execute([$service_id, $owner_id]);
            if (!$checkStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Equipment name is required']);
                exit;
            }
            
            // Handle image upload
            $imagePath = null;
            if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] === UPLOAD_ERR_OK) {
                $imageFile = $_FILES['equipment_image'];
                $ext = strtolower(pathinfo($imageFile['name'], PATHINFO_EXTENSION));
                $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (in_array($ext, $allowedExt) && $imageFile['size'] <= 5 * 1024 * 1024) {
                    $uploadDir = __DIR__ . '/../../uploads/equipment/';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }
                    
                    $filename = 'eq_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $fullPath = $uploadDir . $filename;
                    
                    if (@move_uploaded_file($imageFile['tmp_name'], $fullPath)) {
                        $imagePath = 'uploads/equipment/' . $filename;
                    }
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO equipment_addons (service_id, equipment_name, description, rental_price, quantity_available, equipment_image)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$service_id, $name, $description, $price, $quantity, $imagePath]);
            $equipment_id = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Equipment added',
                'equipment_id' => $equipment_id
            ]);
            exit;
        }
        
        if ($action === 'update_equipment') {
            $equipment_id = isset($_POST['equipment_id']) ? (int)$_POST['equipment_id'] : 0;
            $name = trim($_POST['equipment_name'] ?? '');
            $description = trim($_POST['equipment_description'] ?? '');
            $price = (float)($_POST['rental_price'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 1);
            
            // Verify ownership through service
            $checkStmt = $pdo->prepare("
                SELECT e.equipment_id, e.equipment_image FROM equipment_addons e
                INNER JOIN services s ON e.service_id = s.ServiceID
                WHERE e.equipment_id = ? AND s.OwnerID = ?
            ");
            $checkStmt->execute([$equipment_id, $owner_id]);
            $existing = $checkStmt->fetch();
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            // Handle image upload/update
            $imagePath = $existing['equipment_image'];
            if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] === UPLOAD_ERR_OK) {
                $imageFile = $_FILES['equipment_image'];
                $ext = strtolower(pathinfo($imageFile['name'], PATHINFO_EXTENSION));
                $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (in_array($ext, $allowedExt) && $imageFile['size'] <= 5 * 1024 * 1024) {
                    $uploadDir = __DIR__ . '/../../uploads/equipment/';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }
                    
                    $filename = 'eq_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $fullPath = $uploadDir . $filename;
                    
                    if (@move_uploaded_file($imageFile['tmp_name'], $fullPath)) {
                        // Delete old image
                        if ($imagePath && file_exists(__DIR__ . '/../../' . $imagePath)) {
                            @unlink(__DIR__ . '/../../' . $imagePath);
                        }
                        $imagePath = 'uploads/equipment/' . $filename;
                    }
                }
            }
            
            $stmt = $pdo->prepare("
                UPDATE equipment_addons 
                SET equipment_name = ?, description = ?, rental_price = ?, quantity_available = ?, equipment_image = ?
                WHERE equipment_id = ?
            ");
            $stmt->execute([$name, $description, $price, $quantity, $imagePath, $equipment_id]);
            
            echo json_encode(['success' => true, 'message' => 'Equipment updated']);
            exit;
        }
        
        if ($action === 'delete_equipment') {
            $equipment_id = isset($_POST['equipment_id']) ? (int)$_POST['equipment_id'] : 0;
            
            // Verify ownership through service
            $checkStmt = $pdo->prepare("
                SELECT e.equipment_id, e.equipment_image FROM equipment_addons e
                INNER JOIN services s ON e.service_id = s.ServiceID
                WHERE e.equipment_id = ? AND s.OwnerID = ?
            ");
            $checkStmt->execute([$equipment_id, $owner_id]);
            $equipment = $checkStmt->fetch();
            if (!$equipment) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            // Delete image file if exists
            if ($equipment['equipment_image'] && file_exists(__DIR__ . '/../../' . $equipment['equipment_image'])) {
                @unlink(__DIR__ . '/../../' . $equipment['equipment_image']);
            }
            
            $stmt = $pdo->prepare("DELETE FROM equipment_addons WHERE equipment_id = ?");
            $stmt->execute([$equipment_id]);
            
            echo json_encode(['success' => true, 'message' => 'Equipment deleted']);
            exit;
        }
        
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get studio ID from URL or fetch first studio
$studio_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($studio_id <= 0) {
    // Fetch the first studio owned by this user
    $stmt = $pdo->prepare("SELECT StudioID FROM studios WHERE OwnerID = ? LIMIT 1");
    $stmt->execute([$owner_id]);
    $studio = $stmt->fetch();
    
    if ($studio) {
        $studio_id = $studio['StudioID'];
    } else {
        header("Location: manage_studio.php");
        exit();
    }
}

// Verify studio ownership
$stmt = $pdo->prepare("SELECT * FROM studios WHERE StudioID = ? AND OwnerID = ?");
$stmt->execute([$studio_id, $owner_id]);
$studio = $stmt->fetch();

if (!$studio) {
    header("Location: manage_studio.php");
    exit();
}

// Initialize messages
$success_message = '';
$error_message = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_service'])) {
        // Check subscription limit
        if ($counts['services'] >= $limits['max_services']) {
            $error_message = "Service limit reached! Your {$limits['plan_name']} plan allows up to {$limits['max_services']} services. Please upgrade your plan to add more services.";
        } else {
            // Validate input
            $validation_rules = [
                'name' => ['type' => 'service_name', 'required' => true],
                'description' => ['type' => 'description', 'max_length' => 500],
                'price' => ['type' => 'price', 'required' => true]
            ];
            
            $validation_errors = ValidationUtils::validateForm($_POST, $validation_rules);
            
            if (empty($validation_errors)) {
                try {
                    $name = ValidationUtils::sanitizeInput($_POST['name']);
                    $description = ValidationUtils::sanitizeInput($_POST['description']);
                    $price = (float)$_POST['price'];
                    
                    // Insert service scoped to this owner
                    $stmt = $pdo->prepare("INSERT INTO services (ServiceType, Description, Price, OwnerID) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $description, $price, $owner_id]);
                    
                    $service_id = $pdo->lastInsertId();
                    
                    // Update counts
                    $counts = getCurrentCounts($pdo, $owner_id);
                
                // No automatic studio linkage; service is added to owner's catalog
                
                $success_message = "Service added successfully!";
                } catch (Exception $e) {
                    $error_message = "Error adding service: " . $e->getMessage();
                }
            } else {
                $error_message = ValidationUtils::formatErrors($validation_errors);
            }
        }
    } elseif (isset($_POST['update_service'])) {
        $validation_rules = [
            'name' => ['type' => 'service_name', 'required' => true],
            'description' => ['type' => 'description', 'max_length' => 500],
            'price' => ['type' => 'price', 'required' => true]
        ];
        
        $validation_errors = ValidationUtils::validateForm($_POST, $validation_rules);
        
        if (empty($validation_errors)) {
            try {
                $service_id = (int)$_POST['service_id'];
                $name = ValidationUtils::sanitizeInput($_POST['name']);
                $description = ValidationUtils::sanitizeInput($_POST['description']);
                $price = (float)$_POST['price'];
                
                $stmt = $pdo->prepare("UPDATE services SET ServiceType = ?, Description = ?, Price = ? WHERE ServiceID = ?");
                $stmt->execute([$name, $description, $price, $service_id]);
                
                $success_message = "Service updated successfully!";
            } catch (Exception $e) {
                $error_message = "Error updating service: " . $e->getMessage();
            }
        } else {
            $error_message = ValidationUtils::formatErrors($validation_errors);
        }
    } elseif (isset($_POST['delete_service'])) {
        try {
            $service_id = (int)$_POST['service_id'];
            
            // Check if service is used in any bookings
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE ServiceID = ?");
            $check_stmt->execute([$service_id]);
            $booking_count = $check_stmt->fetchColumn();
            
            if ($booking_count > 0) {
                $error_message = "Cannot delete service as it is used in existing bookings.";
            } else {
                // Remove from studio_services first
                $unlink_stmt = $pdo->prepare("DELETE FROM studio_services WHERE ServiceID = ? AND StudioID = ?");
                $unlink_stmt->execute([$service_id, $studio_id]);
                
                // Delete the service
                $delete_stmt = $pdo->prepare("DELETE FROM services WHERE ServiceID = ?");
                $delete_stmt->execute([$service_id]);
                
                $success_message = "Service deleted successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error deleting service: " . $e->getMessage();
        }
    }
}

// Fetch services for this owner
$services_stmt = $pdo->prepare("SELECT s.* FROM services s WHERE s.OwnerID = ? ORDER BY s.ServiceType");
$services_stmt->execute([$owner_id]);
$services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch instructors for this owner (with services and session counts)
$instructors_stmt = $pdo->prepare("
    SELECT i.InstructorID, i.member_type, i.Name, i.Role AS Specialty, i.Phone AS Contact_Num, i.Email,
           GROUP_CONCAT(DISTINCT srv.ServiceType SEPARATOR ', ') AS services,
           COUNT(DISTINCT b.BookingID) AS total_sessions
    FROM instructors i
    LEFT JOIN instructor_services ins ON i.InstructorID = ins.InstructorID
    LEFT JOIN services srv ON ins.ServiceID = srv.ServiceID
    LEFT JOIN booking_services bsrv ON ins.ServiceID = bsrv.ServiceID AND i.InstructorID = bsrv.InstructorID
    LEFT JOIN bookings b ON bsrv.BookingID = b.BookingID
    WHERE i.OwnerID = ? AND i.member_type = 'instructor'
    GROUP BY i.InstructorID
    ORDER BY i.Name
");
$instructors_stmt->execute([$owner_id]);
$instructors = $instructors_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch staff for this owner
$staff_stmt = $pdo->prepare("
    SELECT i.InstructorID, i.member_type, i.Name, i.Role AS Specialty, i.Phone AS Contact_Num, i.Email,
           GROUP_CONCAT(DISTINCT srv.ServiceType SEPARATOR ', ') AS services,
           COUNT(DISTINCT b.BookingID) AS total_sessions
    FROM instructors i
    LEFT JOIN instructor_services ins ON i.InstructorID = ins.InstructorID
    LEFT JOIN services srv ON ins.ServiceID = srv.ServiceID
    LEFT JOIN booking_services bsrv ON ins.ServiceID = bsrv.ServiceID AND i.InstructorID = bsrv.InstructorID
    LEFT JOIN bookings b ON bsrv.BookingID = b.BookingID
    WHERE i.OwnerID = ? AND i.member_type = 'staff'
    GROUP BY i.InstructorID
    ORDER BY i.Name
");
$staff_stmt->execute([$owner_id]);
$staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch owner-scoped services (for add/edit instructor forms)
$owner_services_stmt = $pdo->prepare(
    "SELECT ServiceID, ServiceType FROM services WHERE OwnerID = ? ORDER BY ServiceType"
);
$owner_services_stmt->execute([$owner_id]);
$owner_services = $owner_services_stmt->fetchAll(PDO::FETCH_ASSOC);
$noServicesMessage = count($owner_services) === 0 ? "No services available. Please add services for this owner first." : "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Offerings - MuSeek Studio Management</title>
    
    <!-- Tailwind (match bookings_netflix) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/style.css">
    
    <style>
        :root {
            --netflix-red: #e50914;
            --netflix-black: #141414;
            --netflix-dark-gray: #2f2f2f;
            --netflix-gray: #666666;
            --netflix-light-gray: #b3b3b3;
            --netflix-white: #ffffff;
            --success-green: #46d369;
            --warning-orange: #ffa500;
            --info-blue: #0071eb;
            /* Responsive stepper sizing (minimalist) */
            --stepper-width: clamp(22px, 4vw, 26px);
            --stepper-height: clamp(12px, 3.5vw, 10px);
            --stepper-gap: clamp(2px, 0.8vw, 4px);
        }

        body {
            font-family: 'Netflix Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--netflix-black);
            color: var(--netflix-white);
            margin: 0;
            padding: 0;
        }

        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            background: var(--netflix-black);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-netflix.collapsed + .main-content {
            margin-left: 70px;
        }

        .manage-services-container {
            padding: 40px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 40px;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--netflix-white);
            margin-bottom: 10px;
            background: linear-gradient(45deg, var(--netflix-white), var(--netflix-light-gray));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            font-size: 1.1rem;
            color: var(--netflix-light-gray);
            margin-bottom: 30px;
        }

        .form-container {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 40px;
            border: 1px solid #333;
            position: relative;
            overflow: hidden;
        }

        .form-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--netflix-red), #ff6b6b);
        }

        .form-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--netflix-white);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .form-title i {
            margin-right: 10px;
            color: var(--netflix-red);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            color: var(--netflix-white);
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #333;
            border-radius: 8px;
            background: var(--netflix-black);
            color: var(--netflix-white);
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .form-control::placeholder {
            color: var(--netflix-gray);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            color: var(--netflix-white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #d40813, #e50914);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.3);
        }

        .btn-secondary {
            background: var(--netflix-dark-gray);
            color: var(--netflix-white);
            border: 1px solid #333;
        }

        .btn-secondary:hover {
            background: var(--netflix-gray);
            border-color: var(--netflix-light-gray);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ff5252);
            color: var(--netflix-white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #ff5252, #f44336);
            transform: translateY(-1px);
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 12px;
        }

        .services-table {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #333;
        }

        .table-header {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            padding: 20px;
            color: var(--netflix-white);
        }

        .table-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .table-title i {
            margin-right: 10px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: var(--netflix-black);
            color: var(--netflix-white);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            border-bottom: 1px solid #333;
        }

        .table td {
            padding: 16px;
            border-bottom: 1px solid #333;
            color: var(--netflix-light-gray);
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: rgba(229, 9, 20, 0.05);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(70, 211, 105, 0.1);
            border: 1px solid var(--success-green);
            color: var(--success-green);
        }

        .alert-danger {
            background: rgba(255, 107, 107, 0.1);
            border: 1px solid #ff6b6b;
            color: #ff6b6b;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--netflix-light-gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: var(--netflix-gray);
        }

        .empty-state h3 {
            color: var(--netflix-white);
            margin-bottom: 10px;
            font-size: 1.5rem;
        }

        .empty-state p {
            margin-bottom: 20px;
            font-size: 1rem;
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
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

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .manage-services-container {
                padding: 20px;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .table {
                font-size: 12px;
            }
            
            .table th,
            .table td {
                padding: 12px 8px;
            }
        }
        /* Floating label inputs */
        .form-group.floating {
            position: relative;
        }
        .form-group.floating .form-control {
            width: 100%;
            padding: 16px 14px 12px;
            border: 1px solid #333;
            border-radius: 10px;
            background: #0f0f0f;
            color: var(--netflix-white);
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .form-group.floating .form-control:focus {
            outline: none;
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.22);
        }
        .form-group.floating .form-control::placeholder {
            color: transparent;
        }
        .form-group.floating .form-label {
            position: absolute;
            left: 14px;
            top: 12px;
            color: var(--netflix-light-gray);
            background: transparent;
            padding: 0;
            transform-origin: left top;
            pointer-events: none;
            transition: all 0.15s ease;
        }
        .form-group.floating .form-control:focus + .form-label,
        .form-group.floating .form-control:not(:placeholder-shown) + .form-label {
            transform: translateY(-14px) scale(0.92);
            color: var(--netflix-red);
        }
        /* Textarea support */
        .form-group.floating textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        .form-group.floating textarea.form-control:focus + .form-label,
        .form-group.floating textarea.form-control:not(:placeholder-shown) + .form-label {
            transform: translateY(-14px) scale(0.92);
            color: var(--netflix-red);
        }
        /* Number input spinner customization */
        /* Native number spinners enabled: removed webkit overrides */
        /* Firefox: use default appearance for number inputs */
        .form-group.floating input[type="number"] {
            padding-right: 14px;
        }
        .form-group.floating .stepper {
            display: none !important;
        }
        .form-group.floating .stepper button {
            width: var(--stepper-width);
            height: var(--stepper-height);
            background: transparent;
            border: none;
            color: var(--netflix-light-gray);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
        }
        .form-group.floating .stepper button i {
            font-size: 0.8rem;
        }
        .form-group.floating .stepper button:hover {
            color: var(--netflix-white);
        }
        .form-group.floating .stepper button:focus-visible {
            outline: 2px solid rgba(229, 9, 20, 0.4);
            border-radius: 4px;
        }
        @media (max-width: 600px) {
            .form-group.floating input[type="number"] {
                padding-right: 14px;
            }
        }
        /* Tab Bar (aligned with manage_studio modal style) */
        .tab-bar {
            display: flex;
            gap: 10px;
            border-bottom: 1px solid #222222;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .tab-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--netflix-dark-gray);
            color: var(--netflix-white);
            border: 1px solid #333;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .tab-button:hover {
            background: #3a3a3a;
            border-color: #444;
        }
        .tab-button.active {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            border-color: #ff6b6b;
        }
        .tab-panel {
            display: block;
        }
        .tab-panel.hidden {
            display: none;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-1">OFFERINGS</h1>
        </header>
        <div class="manage-services-container">            <!-- Tabs -->
            <div class="tab-bar" role="tablist" aria-label="Offerings tabs">
                <button class="tab-button active" role="tab" aria-selected="true" aria-controls="services-panel" data-target="services-panel">
                    <i class="fas fa-concierge-bell"></i>
                    <span>Services</span>
                </button>
                <button class="tab-button" role="tab" aria-selected="false" aria-controls="instructors-panel" data-target="instructors-panel">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>Instructors (<?php echo $counts['instructors_only']; ?>)</span>
                </button>
                <button class="tab-button" role="tab" aria-selected="false" aria-controls="staff-panel" data-target="staff-panel">
                    <i class="fas fa-user-tie"></i>
                    <span>Staff (<?php echo $counts['staff']; ?>)</span>
                </button>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success fade-in">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger fade-in">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Services Panel -->
            <section id="services-panel" class="tab-panel">
                <!-- Services Header -->
                <div class="page-header">
                    <h2 class="form-title"><i class="fas fa-concierge-bell"></i> Services</h2>
                    <p class="page-subtitle">Manage services offered by your studios.</p>
                    
                    <!-- Subscription Limit Display -->
                    <div class="mt-3 p-3 rounded-lg <?php echo $counts['services'] >= $limits['max_services'] ? 'bg-red-900/20 border border-red-600/30' : 'bg-blue-900/20 border border-blue-600/30'; ?>">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-layer-group <?php echo $counts['services'] >= $limits['max_services'] ? 'text-red-400' : 'text-blue-400'; ?>"></i>
                                <span class="text-sm font-medium">
                                    <span class="<?php echo $counts['services'] >= $limits['max_services'] ? 'text-red-300' : 'text-white'; ?>">
                                        <?php echo $counts['services']; ?> / <?php echo $limits['max_services']; ?> Services
                                    </span>
                                    <span class="text-gray-400 ml-2">(<?php echo $limits['plan_name']; ?>)</span>
                                </span>
                            </div>
                            <?php if ($counts['services'] >= $limits['max_services']): ?>
                                <span class="text-xs px-3 py-1 rounded-full bg-red-600 text-white font-semibold">
                                    <i class="fas fa-exclamation-circle mr-1"></i> Limit Reached
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                    <div class="relative">
                        <input type="text" id="search-services" placeholder="Search services..." class="bg-[#0a0a0a] border border-[#222222] rounded-md pl-10 pr-4 py-2 text-sm w-64">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                    <button id="addServiceBtn" class="<?php echo $counts['services'] >= $limits['max_services'] ? 'bg-gray-600 cursor-not-allowed opacity-50' : 'bg-red-600 hover:bg-red-700'; ?> text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2" <?php echo $counts['services'] >= $limits['max_services'] ? 'disabled title="Service limit reached. Upgrade your plan to add more."' : ''; ?>>
                        <i class="fas fa-plus"></i>
                        <span>Add Service</span>
                    </button>
                </div>

                <!-- Services Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if (count($services) === 0): ?>
                        <div class="col-span-3 text-center py-8 text-gray-400">
                            <p>No services found. Add your first service to get started.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($services as $service): ?>
                            <div class="service-card service-item" style="background-color:#0a0a0a;border:1px solid #222222;border-radius:0.5rem;overflow:hidden;">
                                <div class="p-4 flex items-start gap-4">
                                    <div class="avatar" style="width:2.5rem;height:2.5rem;border-radius:9999px;background-color:#374151;display:flex;align-items:center;justify-content:center;color:white;font-weight:600;font-size:0.875rem;">
                                        <?php echo substr(strtoupper($service['ServiceType']),0,2); ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h2 class="text-lg font-bold service-name"><?php echo htmlspecialchars($service['ServiceType']); ?></h2>
                                        <p class="text-sm text-gray-400 service-description">
                                            <?php 
                                            $description = htmlspecialchars($service['Description']);
                                            echo strlen($description) > 80 ? substr($description, 0, 80) . '...' : $description;
                                            ?>
                                        </p>
                                        <div class="mt-2">
                                            <span class="text-sm font-semibold" style="color: var(--success-green);">
                                                ₱<?php echo number_format($service['Price'], 2); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="p-3 bg-[#0f0f0f] border-t border-[#222222] flex justify-end gap-2">
                                    <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick='editService(<?php echo (int)$service["ServiceID"]; ?>, <?php echo json_encode($service["ServiceType"]); ?>, <?php echo json_encode($service["Description"]); ?>, <?php echo json_encode((float)$service["Price"]); ?>)'>
                                         Edit
                                     </button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this service?');">
                                        <input type="hidden" name="delete_service" value="1">
                                        <input type="hidden" name="service_id" value="<?php echo (int)$service['ServiceID']; ?>">
                                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-3 py-1.5 text-xs font-medium">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Instructors Section -->
            <section id="instructors-panel" class="tab-panel hidden">
            <div class="page-header" style="margin-top: 40px;">
                <h2 class="form-title"><i class="fas fa-user-friends"></i> Instructors</h2>
                <p class="page-subtitle">Manage instructors and their service specialties.</p>
                
                <!-- Subscription Limit Display -->
                <div class="mt-3 p-3 rounded-lg <?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'bg-red-900/20 border border-red-600/30' : 'bg-blue-900/20 border border-blue-600/30'; ?>">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-chalkboard-teacher <?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'text-red-400' : 'text-blue-400'; ?>"></i>
                            <span class="text-sm font-medium">
                                <span class="<?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'text-red-300' : 'text-white'; ?>">
                                    <?php echo $counts['instructors']; ?> / <?php echo $limits['max_instructors']; ?> Instructors
                                </span>
                                <span class="text-gray-400 ml-2">(<?php echo $limits['plan_name']; ?> Plan)</span>
                            </span>
                        </div>
                        <?php if ($counts['instructors'] >= $limits['max_instructors']): ?>
                            <span class="text-xs px-3 py-1 rounded-full bg-red-600 text-white font-semibold">
                                <i class="fas fa-exclamation-circle mr-1"></i> Limit Reached
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                <div class="relative">
                    <input type="text" id="search-instructors" placeholder="Search instructors..." class="bg-[#0a0a0a] border border-[#222222] rounded-md pl-10 pr-4 py-2 text-sm w-64">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                </div>
                <button id="addInstructorBtn" class="<?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'bg-gray-600 cursor-not-allowed opacity-50' : 'bg-red-600 hover:bg-red-700'; ?> text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2" <?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'disabled title="Instructor limit reached. Upgrade your plan to add more."' : ''; ?>>
                    <i class="fas fa-plus"></i>
                    <span>Add Instructor</span>
                </button>
            </div>

            <!-- Instructors Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (empty($instructors)): ?>
                    <div class="col-span-3 text-center py-8 text-gray-400">
                        <p>No instructors found. Add your first instructor to get started.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($instructors as $instructor): ?>
                        <div class="instructor-card instructor-item" style="background-color:#0a0a0a;border:1px solid #222222;border-radius:0.5rem;overflow:hidden;">
                            <div class="p-4 flex items-start gap-4">
                                <div class="avatar" style="width:2.5rem;height:2.5rem;border-radius:9999px;background-color:#374151;display:flex;align-items:center;justify-content:center;color:white;font-weight:600;font-size:0.875rem;">
                                    <?php echo substr(strtoupper($instructor['Name']),0,2); ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h2 class="text-lg font-bold instructor-name"><?php echo htmlspecialchars($instructor['Name']); ?></h2>
                                    <p class="text-sm text-gray-400 instructor-specialty"><?php echo htmlspecialchars($instructor['Specialty']); ?></p>
                                    <div class="mt-2 space-y-1">
                                        <p class="text-xs flex items-center gap-2">
                                            <i class="fas fa-phone text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($instructor['Contact_Num'] ?? 'N/A'); ?></span>
                                        </p>
                                        <p class="text-xs flex items-center gap-2">
                                            <i class="fas fa-envelope text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($instructor['Email'] ?? 'N/A'); ?></span>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="p-4 border-t border-[#222222]">
                                <div class="flex justify-between items-center mb-2">
                                    <h3 class="text-sm font-medium">Services</h3>
                                    <span class="text-xs text-gray-400"><?php echo $instructor['total_sessions'] ?? 0; ?> sessions</span>
                                </div>
                                <p class="text-sm text-gray-400"><?php echo htmlspecialchars($instructor['services'] ?? 'Not assigned'); ?></p>
                            </div>

                            <div class="p-3 bg-[#0f0f0f] border-t border-[#222222] flex justify-end gap-2">
                                <button class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="viewSchedule(<?php echo (int)$instructor['InstructorID']; ?>)">
                                    View Schedule
                                </button>
                                <button class="bg-blue-600 hover:bg-blue-700 text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="manageAvailability(<?php echo (int)$instructor['InstructorID']; ?>, '<?php echo addslashes($instructor['Name']); ?>', '<?php echo addslashes($instructor['blocked_dates'] ?? ''); ?>')">
                                    <i class="fas fa-calendar-times"></i> Availability
                                </button>
                                <button class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="editInstructor(<?php echo (int)$instructor['InstructorID']; ?>, '<?php echo addslashes($instructor['Name']); ?>', '<?php echo addslashes($instructor['Specialty']); ?>', '<?php echo addslashes($instructor['Contact_Num'] ?? ''); ?>', '<?php echo addslashes($instructor['Email'] ?? ''); ?>')">
                                    Edit
                                </button>
                                <button class="bg-red-600 hover:bg-red-700 text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="confirmRemoveInstructor(<?php echo (int)$instructor['InstructorID']; ?>, '<?php echo addslashes($instructor['Name']); ?>')">
                                    Remove
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            </section>

            <!-- Staff Section -->
            <section id="staff-panel" class="tab-panel hidden">
            <div class="page-header" style="margin-top: 40px;">
                <h2 class="form-title"><i class="fas fa-user-tie"></i> Staff Members</h2>
                <p class="page-subtitle">Manage staff members and their service assignments.</p>
                
                <!-- Subscription Limit Display -->
                <div class="mt-3 p-3 rounded-lg <?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'bg-red-900/20 border border-red-600/30' : 'bg-blue-900/20 border border-blue-600/30'; ?>">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-users <?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'text-red-400' : 'text-blue-400'; ?>"></i>
                            <span class="text-sm font-medium">
                                <span class="<?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'text-red-300' : 'text-white'; ?>">
                                    <?php echo $counts['instructors']; ?> / <?php echo $limits['max_instructors']; ?> Total Members
                                </span>
                                <span class="text-gray-400 ml-2">(Staff count towards instructor limit)</span>
                            </span>
                        </div>
                        <?php if ($counts['instructors'] >= $limits['max_instructors']): ?>
                            <span class="text-xs px-3 py-1 rounded-full bg-red-600 text-white font-semibold">
                                <i class="fas fa-exclamation-circle mr-1"></i> Limit Reached
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                <div class="relative">
                    <input type="text" id="search-staff" placeholder="Search staff..." class="bg-[#0a0a0a] border border-[#222222] rounded-md pl-10 pr-4 py-2 text-sm w-64">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                </div>
                <button id="addStaffBtn" class="<?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'bg-gray-600 cursor-not-allowed opacity-50' : 'bg-red-600 hover:bg-red-700'; ?> text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2" <?php echo $counts['instructors'] >= $limits['max_instructors'] ? 'disabled title="Member limit reached. Upgrade your plan to add more."' : ''; ?>>
                    <i class="fas fa-plus"></i>
                    <span>Add Staff Member</span>
                </button>
            </div>

            <!-- Staff Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (empty($staff)): ?>
                    <div class="col-span-3 text-center py-8 text-gray-400">
                        <p>No staff members found. Add your first staff member to get started.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($staff as $member): ?>
                        <div class="staff-card staff-item" style="background-color:#0a0a0a;border:1px solid #222222;border-radius:0.5rem;overflow:hidden;">
                            <div class="p-4 flex items-start gap-4">
                                <div class="avatar" style="width:2.5rem;height:2.5rem;border-radius:9999px;background-color:#374151;display:flex;align-items:center;justify-content:center;color:white;font-weight:600;font-size:0.875rem;">
                                    <?php echo substr(strtoupper($member['Name']),0,2); ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <h2 class="text-lg font-bold staff-name"><?php echo htmlspecialchars($member['Name']); ?></h2>
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-purple-900/30 text-purple-400">Staff</span>
                                    </div>
                                    <p class="text-sm text-gray-400 staff-specialty"><?php echo htmlspecialchars($member['Specialty']); ?></p>
                                    <div class="mt-2 space-y-1">
                                        <p class="text-xs flex items-center gap-2">
                                            <i class="fas fa-phone text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($member['Contact_Num'] ?? 'N/A'); ?></span>
                                        </p>
                                        <p class="text-xs flex items-center gap-2">
                                            <i class="fas fa-envelope text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($member['Email'] ?? 'N/A'); ?></span>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="p-4 border-t border-[#222222]">
                                <div class="flex justify-between items-center mb-2">
                                    <h3 class="text-sm font-medium">Services</h3>
                                    <span class="text-xs text-gray-400"><?php echo $member['total_sessions'] ?? 0; ?> sessions</span>
                                </div>
                                <p class="text-sm text-gray-400"><?php echo htmlspecialchars($member['services'] ?? 'Not assigned'); ?></p>
                            </div>

                            <div class="p-3 bg-[#0f0f0f] border-t border-[#222222] flex justify-end gap-2">
                                <button class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="viewSchedule(<?php echo (int)$member['InstructorID']; ?>)">
                                    View Schedule
                                </button>
                                <button class="bg-blue-600 hover:bg-blue-700 text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="manageAvailability(<?php echo (int)$member['InstructorID']; ?>, '<?php echo addslashes($member['Name']); ?>', '<?php echo addslashes($member['blocked_dates'] ?? ''); ?>')">
                                    <i class="fas fa-calendar-times"></i> Availability
                                </button>
                                <button class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="editInstructor(<?php echo (int)$member['InstructorID']; ?>, '<?php echo addslashes($member['Name']); ?>', '<?php echo addslashes($member['Specialty']); ?>', '<?php echo addslashes($member['Contact_Num'] ?? ''); ?>', '<?php echo addslashes($member['Email'] ?? ''); ?>', 'staff')">
                                    Edit
                                </button>
                                <button class="bg-red-600 hover:bg-red-700 text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="confirmRemoveInstructor(<?php echo (int)$member['InstructorID']; ?>, '<?php echo addslashes($member['Name']); ?>')">
                                    Remove
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            </section>
        </div>
    </main>

    <!-- Edit Service Modal (Hidden by default) -->
    <div id="editModal" class="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:50;align-items:center;justify-content:center;">
        <div class="modal-content p-6" style="background-color:#161616;border:1px solid #222222;border-radius:0.5rem;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Edit Service</h2>
                <button class="text-gray-400 hover:text-white" onclick="closeModal(document.getElementById('editModal'))">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Tabs for Service Details and Equipment -->
            <div class="mb-4 border-b border-[#333]">
                <div class="flex gap-2">
                    <button type="button" class="edit-tab-btn active px-4 py-2 text-sm font-medium border-b-2 border-red-600" data-tab="details">
                        <i class="fas fa-info-circle mr-1"></i> Service Details
                    </button>
                    <button type="button" class="edit-tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white" data-tab="equipment">
                        <i class="fas fa-toolbox mr-1"></i> Add-on Equipment
                    </button>
                </div>
            </div>

            <!-- Service Details Tab -->
            <div id="detailsTab" class="edit-tab-content">
                <form id="editForm" method="POST">
                    <input type="hidden" name="update_service" value="1">
                    <input type="hidden" name="service_id" id="edit_service_id">
                    <div class="space-y-4">
                        <!-- Floating label inputs -->
                        <div class="form-group floating">
                            <input type="text" id="edit_name" name="name" required maxlength="50" class="form-control" placeholder=" ">
                            <label for="edit_name" class="form-label">Service Name *</label>
                        </div>
                        <div class="form-group floating">
                            <input type="number" id="edit_price" name="price" required step="0.01" min="0" max="999999.99" class="form-control" placeholder=" ">
                            <label for="edit_price" class="form-label">Price (₱) *</label>
                        </div>
                        <div class="form-group floating">
                            <textarea id="edit_description" name="description" rows="3" maxlength="500" class="form-control" placeholder=" "></textarea>
                            <label for="edit_description" class="form-label">Description</label>
                        </div>
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal(document.getElementById('editModal'))">Cancel</button>
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                                <i class="fas fa-save mr-1"></i> Update Service
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Equipment Tab -->
            <div id="equipmentTab" class="edit-tab-content" style="display:none;">
                <!-- Existing Equipment List -->
                <div class="mb-4">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-sm font-semibold">Current Equipment</h3>
                    </div>
                    <div id="existingEquipmentList" class="space-y-2">
                        <!-- Populated via JavaScript -->
                    </div>
                </div>

                <!-- Add New Equipment Section -->
                <div class="border-t border-[#333] pt-4">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-sm font-semibold">Add Equipment</h3>
                        <button type="button" id="addEquipmentRowBtn" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <div id="newEquipmentRows" class="space-y-2">
                        <!-- Equipment rows added dynamically -->
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal(document.getElementById('editModal'))">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Service Modal -->
    <div id="addServiceModal" class="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:50;align-items:center;justify-content:center;">
        <div class="modal-content p-6" style="background-color:#161616;border:1px solid #222222;border-radius:0.5rem;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Add New Service</h2>
                <button class="text-gray-400 hover:text-white" onclick="closeAddServiceModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Tabs for Add Service -->
            <div class="mb-4 border-b border-[#333]">
                <div class="flex gap-2">
                    <button type="button" class="add-tab-btn active px-4 py-2 text-sm font-medium border-b-2 border-red-600" data-tab="add-details">
                        <i class="fas fa-info-circle mr-1"></i> Service Details
                    </button>
                    <button type="button" class="add-tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white" data-tab="add-equipment">
                        <i class="fas fa-toolbox mr-1"></i> Add Equipment
                    </button>
                </div>
            </div>

            <!-- Service Details Tab -->
            <div id="addDetailsTab" class="add-tab-content">
                <form id="addServiceForm" method="POST">
                    <input type="hidden" name="add_service" value="1">
                    <input type="hidden" id="new_service_id" value="">
                    <div class="space-y-4">
                        <!-- Floating label inputs -->
                        <div class="form-group floating">
                            <input type="text" id="svc_name" name="name" required maxlength="50" class="form-control" placeholder=" ">
                            <label for="svc_name" class="form-label">Service Name *</label>
                        </div>
                        <div class="form-group floating">
                            <input type="number" id="svc_price" name="price" required step="0.01" min="0" max="999999.99" class="form-control" placeholder=" ">
                            <label for="svc_price" class="form-label">Price (₱) *</label>
                        </div>
                        <div class="form-group floating">
                            <textarea id="svc_desc" name="description" rows="3" maxlength="500" class="form-control" placeholder=" "></textarea>
                            <label for="svc_desc" class="form-label">Description</label>
                        </div>
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeAddServiceModal()">Cancel</button>
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                                <i class="fas fa-save mr-1"></i> Save Service
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Equipment Tab -->
            <div id="addEquipmentTab" class="add-tab-content" style="display:none;">
                <div class="mb-4 p-3 bg-[#0f0f0f] border border-[#333] rounded-md text-sm text-gray-400">
                    <i class="fas fa-info-circle mr-2 text-blue-400"></i>
                    Save the service first to add equipment. Equipment will be available after the service is created.
                </div>
                
                <div id="addServiceEquipmentSection" style="display:none;">
                    <div class="mb-4">
                        <div class="flex justify-between items-center mb-3">
                            <h3 class="text-sm font-semibold">Equipment for this Service</h3>
                        </div>
                        <div id="addServiceExistingEquipment" class="space-y-2">
                            <!-- Populated after service creation -->
                        </div>
                    </div>

                    <div class="border-t border-[#333] pt-4">
                        <div class="flex justify-between items-center mb-3">
                            <h3 class="text-sm font-semibold">Add Equipment</h3>
                            <button type="button" id="addServiceEquipmentRowBtn" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div id="addServiceNewEquipmentRows" class="space-y-2">
                            <!-- Equipment rows added dynamically -->
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeAddServiceModal()">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs Script -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const buttons = document.querySelectorAll('.tab-bar .tab-button');
        const servicesPanel = document.getElementById('services-panel');
        const instructorsPanel = document.getElementById('instructors-panel');
        const staffPanel = document.getElementById('staff-panel');
        const panels = {
            'services-panel': servicesPanel,
            'instructors-panel': instructorsPanel,
            'staff-panel': staffPanel
        };

        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                // Toggle active button
                buttons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                // Toggle panels
                Object.values(panels).forEach(p => p.classList.add('hidden'));
                const target = btn.getAttribute('data-target');
                if (panels[target]) {
                    panels[target].classList.remove('hidden');
                }
            });
        });
    });
    </script>

    <!-- Add Instructor Modal -->
    <div id="addInstructorModal" class="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:50;align-items:center;justify-content:center;">
        <div class="modal-content p-6" style="background-color:#161616;border:1px solid #222222;border-radius:0.5rem;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Add New Instructor</h2>
                <button id="closeAddModalBtn" class="text-gray-400 hover:text-white" onclick="closeModal(document.getElementById('addInstructorModal'))">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form id="addInstructorForm" action="../php/add-instructor.php" method="post">
                <div class="space-y-4">
                    <div>
                        <label for="member_type" class="block text-sm font-medium text-gray-400 mb-1">Type *</label>
                        <select id="member_type" name="member_type" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <option value="instructor">Instructor</option>
                            <option value="staff">Staff</option>
                        </select>
                    </div>

                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-400 mb-1">Name</label>
                        <input type="text" id="name" name="name" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label for="specialty" class="block text-sm font-medium text-gray-400 mb-1">Role/Specialty</label>
                        <input type="text" id="specialty" name="specialty" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label for="contact" class="block text-sm font-medium text-gray-400 mb-1">Contact Number</label>
                        <input type="text" id="contact" name="contact" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-400 mb-1">Email</label>
                        <input type="email" id="email" name="email" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label for="services" class="block text-sm font-medium text-gray-400 mb-1">Assign Services</label>
                        <?php if ($noServicesMessage): ?>
                            <p class="text-sm text-red-400"><?php echo $noServicesMessage; ?></p>
                        <?php else: ?>
                            <select id="services" name="services[]" multiple required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm h-32">
                                <?php foreach ($owner_services as $service): ?>
                                    <option value="<?php echo (int)$service['ServiceID']; ?>"><?php echo htmlspecialchars($service['ServiceType']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Hold Ctrl/Cmd to select multiple services</p>
                        <?php endif; ?>
                    </div>

                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal(document.getElementById('addInstructorModal'))">
                            Cancel
                        </button>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium" <?php echo $noServicesMessage ? 'disabled' : ''; ?>>
                            Add Instructor
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Instructor Modal -->
    <div id="editInstructorModal" class="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:50;align-items:center;justify-content:center;">
        <div class="modal-content p-6" style="background-color:#161616;border:1px solid #222222;border-radius:0.5rem;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Edit Instructor</h2>
                <button class="text-gray-400 hover:text-white" onclick="closeModal(document.getElementById('editInstructorModal'))">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form id="editInstructorForm" action="../php/update-instructor.php" method="post">
                <input type="hidden" id="edit_instructor_id" name="instructor_id">
                <div class="space-y-4">
                    <div>
                        <label for="edit_member_type" class="block text-sm font-medium text-gray-400 mb-1">Type *</label>
                        <select id="edit_member_type" name="member_type" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                            <option value="instructor">Instructor</option>
                            <option value="staff">Staff</option>
                        </select>
                    </div>
                    <div>
                        <label for="edit_name" class="block text-sm font-medium text-gray-400 mb-1">Name</label>
                        <input type="text" id="edit_name" name="name" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="edit_specialty" class="block text-sm font-medium text-gray-400 mb-1">Role/Specialty</label>
                        <input type="text" id="edit_specialty" name="specialty" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="edit_contact" class="block text-sm font-medium text-gray-400 mb-1">Contact Number</label>
                        <input type="text" id="edit_contact" name="contact" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="edit_email" class="block text-sm font-medium text-gray-400 mb-1">Email</label>
                        <input type="email" id="edit_email" name="email" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="edit_services" class="block text-sm font-medium text-gray-400 mb-1">Assign Services</label>
                        <?php if ($noServicesMessage): ?>
                            <p class="text-sm text-red-400"><?php echo $noServicesMessage; ?></p>
                        <?php else: ?>
                            <select id="edit_services" name="services[]" multiple required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm h-32">
                                <?php foreach ($owner_services as $service): ?>
                                    <option value="<?php echo (int)$service['ServiceID']; ?>"><?php echo htmlspecialchars($service['ServiceType']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Hold Ctrl/Cmd to select multiple services</p>
                        <?php endif; ?>
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal(document.getElementById('editInstructorModal'))">
                            Cancel
                        </button>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium" <?php echo $noServicesMessage ? 'disabled' : ''; ?>>
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirm Remove Modal -->
    <div id="confirmRemoveModal" class="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:50;align-items:center;justify-content:center;">
        <div class="modal-content p-6" style="background-color:#161616;border:1px solid #222222;border-radius:0.5rem;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Confirm Removal</h2>
                <button class="text-gray-400 hover:text-white" onclick="closeModal(document.getElementById('confirmRemoveModal'))">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <p class="mb-6">Are you sure you want to remove <span id="instructorToRemove" class="font-medium"></span>? This action cannot be undone.</p>

            <form id="removeInstructorForm" action="../../../instructor/php/remove-instructor.php" method="post">
                <input type="hidden" id="remove_instructor_id" name="instructor_id">
                <div class="flex justify-end gap-3">
                    <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal(document.getElementById('confirmRemoveModal'))">
                        Cancel
                    </button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                        Remove Instructor
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Manage Availability Modal -->
    <div id="availabilityModal" class="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:50;align-items:center;justify-content:center;">
        <div class="modal-content p-6" style="background-color:#161616;border:1px solid #222222;border-radius:0.5rem;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Manage Availability - <span id="availInstructorName"></span></h2>
                <button class="text-gray-400 hover:text-white" onclick="closeModal(document.getElementById('availabilityModal'))">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="mb-4 p-3 bg-[#0a0a0a] border border-[#222222] rounded-md">
                <p class="text-sm text-gray-400 mb-2">
                    <i class="fas fa-info-circle"></i> By default, instructors/staff are available every day. 
                    Block specific dates when they're unavailable (vacation, day-off, etc.).
                </p>
            </div>

            <form id="availabilityForm">
                <input type="hidden" id="avail_instructor_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-300 mb-2">Block Dates</label>
                    <div class="flex gap-2">
                        <input type="date" id="blockDateInput" class="flex-1 bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm text-white">
                        <button type="button" onclick="addBlockedDate()" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                            <i class="fas fa-ban"></i> Block Date
                        </button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-300 mb-2">Currently Blocked Dates</label>
                    <div id="blockedDatesList" class="space-y-2 max-h-60 overflow-y-auto">
                        <!-- Will be populated dynamically -->
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeModal(document.getElementById('availabilityModal'))">
                        Cancel
                    </button>
                    <button type="button" onclick="saveAvailability()" class="bg-blue-600 hover:bg-blue-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>


    <script>
        let currentServiceId = null;

        function editService(serviceId, name, description, price) {
            const modal = document.getElementById('editModal');
            currentServiceId = serviceId;
            modal.querySelector('#edit_service_id').value = serviceId || '';
            modal.querySelector('#edit_name').value = (name ?? '').toString();
            modal.querySelector('#edit_description').value = (description ?? '').toString();
            const priceInput = modal.querySelector('#edit_price');
            priceInput.value = (typeof price === 'number' && !isNaN(price)) ? price : '';
            
            // Reset to details tab
            switchEditTab('details');
            
            // Load equipment for this service
            loadEquipment(serviceId);
            
            openModal(modal);
        }

        // Tab switching in edit modal
        function switchEditTab(tabName) {
            const tabs = document.querySelectorAll('.edit-tab-btn');
            const contents = document.querySelectorAll('.edit-tab-content');
            
            tabs.forEach(tab => {
                if (tab.dataset.tab === tabName) {
                    tab.classList.add('active', 'border-red-600');
                    tab.classList.remove('border-transparent', 'text-gray-400');
                } else {
                    tab.classList.remove('active', 'border-red-600');
                    tab.classList.add('border-transparent', 'text-gray-400');
                }
            });
            
            document.getElementById('detailsTab').style.display = tabName === 'details' ? 'block' : 'none';
            document.getElementById('equipmentTab').style.display = tabName === 'equipment' ? 'block' : 'none';
        }

        // Equipment management
        let equipmentRowCounter = 0;

        async function loadEquipment(serviceId) {
            try {
                const formData = new FormData();
                formData.append('ajax', 'get_equipment');
                formData.append('service_id', serviceId);
                
                const response = await fetch('manage_services.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    renderExistingEquipment(data.equipment);
                    // Clear new equipment rows
                    document.getElementById('newEquipmentRows').innerHTML = '';
                    equipmentRowCounter = 0;
                }
            } catch (error) {
                console.error('Error loading equipment:', error);
            }
        }

        function renderExistingEquipment(equipment) {
            const container = document.getElementById('existingEquipmentList');
            
            if (!equipment || equipment.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400">No equipment added yet.</p>';
                return;
            }
            
            container.innerHTML = equipment.map(item => {
                const imageSrc = item.equipment_image ? `../../${item.equipment_image}` : null;
                return `
                <div class="bg-[#0f0f0f] border border-[#222222] rounded-md p-3 hover:border-[#333] transition-colors">
                    <div class="flex justify-between items-start gap-3">
                        ${imageSrc ? `
                        <div class="flex-shrink-0">
                            <img src="${imageSrc}" alt="${escapeHtml(item.equipment_name)}" 
                                 class="w-16 h-16 object-cover rounded-md border border-[#333]"
                                 onerror="this.style.display='none'">
                        </div>
                        ` : ''}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="font-semibold text-sm">${escapeHtml(item.equipment_name)}</h4>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-green-900/30 text-green-400">
                                    ₱${parseFloat(item.rental_price).toFixed(2)}
                                </span>
                            </div>
                            ${item.description ? `<p class="text-xs text-gray-400 mb-1">${escapeHtml(item.description)}</p>` : ''}
                            <p class="text-xs text-gray-500">Quantity: ${item.quantity_available}</p>
                        </div>
                        <button type="button" 
                                onclick="deleteEquipment(${item.equipment_id})"
                                class="bg-red-600 hover:bg-red-700 text-white rounded-md px-2 py-1 text-xs font-medium flex-shrink-0">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
            `;
            }).join('');
        }

        function addEquipmentRow(containerSelector = '#newEquipmentRows') {
            equipmentRowCounter++;
            const rowId = equipmentRowCounter;
            
            const row = document.createElement('div');
            row.className = 'equipment-row bg-[#0f0f0f] border border-[#222222] rounded-md p-3';
            row.dataset.rowId = rowId;
            
            row.innerHTML = `
                <div class="grid grid-cols-12 gap-2 items-end">
                    <div class="col-span-4">
                        <label class="block text-xs font-medium text-gray-400 mb-1">Equipment Name *</label>
                        <input type="text" 
                               class="eq-name w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-2 py-1.5 text-xs focus:border-red-600 focus:ring-1 focus:ring-red-600" 
                               placeholder="e.g., Microphone">
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs font-medium text-gray-400 mb-1">Price (₱) *</label>
                        <input type="number" 
                               class="eq-price w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-2 py-1.5 text-xs focus:border-red-600 focus:ring-1 focus:ring-red-600" 
                               step="0.01" 
                               min="0" 
                               placeholder="0.00">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-gray-400 mb-1">Qty</label>
                        <input type="number" 
                               class="eq-quantity w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-2 py-1.5 text-xs focus:border-red-600 focus:ring-1 focus:ring-red-600" 
                               min="1" 
                               value="1">
                    </div>
                    <div class="col-span-3 flex gap-2">
                        <button type="button" 
                                onclick="saveEquipmentRow(${rowId})"
                                class="flex-1 bg-green-600 hover:bg-green-700 text-white rounded-md px-2 py-1.5 text-xs font-medium transition-colors">
                            <i class="fas fa-check"></i> Add
                        </button>
                        <button type="button" 
                                onclick="removeEquipmentRow(${rowId})"
                                class="bg-red-600 hover:bg-red-700 text-white rounded-md px-2 py-1.5 text-xs font-medium transition-colors">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
                <div class="mt-2">
                    <label class="block text-xs font-medium text-gray-400 mb-1">Description (optional)</label>
                    <textarea class="eq-description w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-2 py-1.5 text-xs focus:border-red-600 focus:ring-1 focus:ring-red-600" 
                              rows="2" 
                              placeholder="Brief description..."></textarea>
                </div>
                <div class="mt-2">
                    <label class="block text-xs font-medium text-gray-400 mb-1">
                        <i class="fas fa-image mr-1"></i> Equipment Image (optional)
                    </label>
                    <div class="flex items-center gap-2">
                        <label class="flex-1 cursor-pointer">
                            <div class="flex items-center gap-2 bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 hover:border-red-600 transition-colors">
                                <i class="fas fa-upload text-gray-400"></i>
                                <span class="eq-file-label text-xs text-gray-400">Choose image...</span>
                            </div>
                            <input type="file" 
                                   class="eq-image hidden" 
                                   accept="image/jpeg,image/jpg,image/png,image/webp"
                                   onchange="updateFileLabel(this, ${rowId})">
                        </label>
                        <div class="eq-preview w-12 h-12 bg-[#0a0a0a] border border-[#222222] rounded-md overflow-hidden flex items-center justify-center" style="display:none;">
                            <img class="eq-preview-img w-full h-full object-cover" src="" alt="Preview">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">JPG, PNG, WEBP (max 5MB)</p>
                </div>
            `;
            
            document.querySelector(containerSelector).appendChild(row);
        }

        function updateFileLabel(input, rowId) {
            const row = input.closest('.equipment-row');
            const label = row.querySelector('.eq-file-label');
            const preview = row.querySelector('.eq-preview');
            const previewImg = row.querySelector('.eq-preview-img');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                label.textContent = file.name;
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    preview.style.display = 'flex';
                };
                reader.readAsDataURL(file);
            } else {
                label.textContent = 'Choose image...';
                preview.style.display = 'none';
            }
        }

        function removeEquipmentRow(rowId) {
            const row = document.querySelector(`.equipment-row[data-row-id="${rowId}"]`);
            if (row) {
                row.remove();
            }
        }

        async function saveEquipmentRow(rowId) {
            const row = document.querySelector(`.equipment-row[data-row-id="${rowId}"]`);
            if (!row) return;
            
            const name = row.querySelector('.eq-name').value.trim();
            const price = parseFloat(row.querySelector('.eq-price').value) || 0;
            const quantity = parseInt(row.querySelector('.eq-quantity').value) || 1;
            const description = row.querySelector('.eq-description').value.trim();
            const imageInput = row.querySelector('.eq-image');
            
            if (!name) {
                alert('Equipment name is required');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('ajax', 'add_equipment');
                formData.append('service_id', currentServiceId);
                formData.append('equipment_name', name);
                formData.append('equipment_description', description);
                formData.append('rental_price', price);
                formData.append('quantity', quantity);
                
                // Add image if selected
                if (imageInput && imageInput.files && imageInput.files[0]) {
                    formData.append('equipment_image', imageInput.files[0]);
                }
                
                const response = await fetch('manage_services.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Remove the row
                    row.remove();
                    // Reload equipment list
                    await loadEquipment(currentServiceId);
                } else {
                    alert(data.message || 'Error adding equipment');
                }
            } catch (error) {
                console.error('Error saving equipment:', error);
                alert('Error adding equipment');
            }
        }

        async function deleteEquipment(equipmentId) {
            if (!confirm('Are you sure you want to delete this equipment?')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('ajax', 'delete_equipment');
                formData.append('equipment_id', equipmentId);
                
                const response = await fetch('manage_services.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    await loadEquipment(currentServiceId);
                } else {
                    alert(data.message || 'Error deleting equipment');
                }
            } catch (error) {
                console.error('Error deleting equipment:', error);
                alert('Error deleting equipment');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Tab switching for Add Service Modal
        function switchAddTab(tabName) {
            const tabs = document.querySelectorAll('.add-tab-btn');
            tabs.forEach(tab => {
                if (tab.dataset.tab === tabName) {
                    tab.classList.add('active', 'border-red-600');
                    tab.classList.remove('border-transparent', 'text-gray-400');
                } else {
                    tab.classList.remove('active', 'border-red-600');
                    tab.classList.add('border-transparent', 'text-gray-400');
                }
            });
            
            document.getElementById('addDetailsTab').style.display = tabName === 'add-details' ? 'block' : 'none';
            document.getElementById('addEquipmentTab').style.display = tabName === 'add-equipment' ? 'block' : 'none';
        }

        function closeAddServiceModal() {
            const modal = document.getElementById('addServiceModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Reset form
            document.getElementById('addServiceForm').reset();
            document.getElementById('new_service_id').value = '';
            document.getElementById('addServiceEquipmentSection').style.display = 'none';
            document.getElementById('addServiceNewEquipmentRows').innerHTML = '';
            
            // Switch back to details tab
            switchAddTab('add-details');
        }

        // Initialize edit modal tab switching
        document.addEventListener('DOMContentLoaded', function() {
            const tabBtns = document.querySelectorAll('.edit-tab-btn');
            tabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    switchEditTab(this.dataset.tab);
                });
            });

            // Add service modal tabs
            const addTabBtns = document.querySelectorAll('.add-tab-btn');
            addTabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    switchAddTab(this.dataset.tab);
                });
            });

            // Add equipment row button for edit modal
            const addEquipmentBtn = document.getElementById('addEquipmentRowBtn');
            if (addEquipmentBtn) {
                addEquipmentBtn.addEventListener('click', () => addEquipmentRow('#newEquipmentRows'));
            }

            // Add equipment row button for add service modal
            const addServiceEquipmentBtn = document.getElementById('addServiceEquipmentRowBtn');
            if (addServiceEquipmentBtn) {
                addServiceEquipmentBtn.addEventListener('click', () => addEquipmentRow('#addServiceNewEquipmentRows'));
            }

            // Intercept Add Service form submission to stay in modal
            const addServiceForm = document.getElementById('addServiceForm');
            if (addServiceForm) {
                addServiceForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    
                    try {
                        const response = await fetch('manage_services.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const text = await response.text();
                        
                        // Check if it's a redirect (starts with <!DOCTYPE or <script)
                        if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<script>')) {
                            // Success - extract service ID from URL or parse response
                            // For now, just close modal and reload page
                            alert('Service added successfully! Reload the page to add equipment.');
                            window.location.reload();
                        } else {
                            // Error or unexpected response
                            alert('Service added but unable to extract ID. Please reload the page.');
                            window.location.reload();
                        }
                    } catch (error) {
                        console.error('Error adding service:', error);
                        alert('Error adding service. Please try again.');
                    }
                });
            }
        });

        function closeEditModal() {
            closeModal(document.getElementById('editModal'));
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('editModal');
            if (modal && modal.style.display !== 'none' && event.target === modal) {
                closeEditModal();
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 500);
            });
        }, 5000);

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.fade-in');
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    element.style.transition = 'all 0.6s ease-out';
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });

        // Price stepper logic
        (function() {
            function getDecimals(step) {
                const s = String(step);
                const idx = s.indexOf('.');
                return idx >= 0 ? s.length - idx - 1 : 0;
            }
            function attachStepper(group) {
                const input = group.querySelector('input[type="number"]');
                const up = group.querySelector('.stepper .step-up');
                const down = group.querySelector('.stepper .step-down');
                if (!input || !up || !down) return;
                const step = parseFloat(input.step || '1');
                const decimals = getDecimals(step);
                const min = input.min ? parseFloat(input.min) : -Infinity;
                const max = input.max ? parseFloat(input.max) : Infinity;
                function setValue(v) {
                    if (v < min) v = min;
                    if (v > max) v = max;
                    input.value = Number(v).toFixed(decimals);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                up.addEventListener('click', () => {
                    const current = input.value === '' ? 0 : parseFloat(input.value);
                    setValue(current + step);
                });
                down.addEventListener('click', () => {
                    const current = input.value === '' ? 0 : parseFloat(input.value);
                    setValue(current - step);
                });
            }
            document.querySelectorAll('.form-group.floating').forEach(attachStepper);
        })();
    </script>
    <script>
        // Simple modal utilities
        function openModal(modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        function closeModal(modal) {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Instructors UI interactions
        (function() {
            const addBtn = document.getElementById('addInstructorBtn');
            const addModal = document.getElementById('addInstructorModal');
            const editModal = document.getElementById('editInstructorModal');
            const confirmModal = document.getElementById('confirmRemoveModal');
            const searchInput = document.getElementById('search-instructors');

            if (addBtn && addModal) {
                addBtn.addEventListener('click', () => openModal(addModal));
            }

            window.editInstructor = function(id, name, specialty, contact, email, memberType = 'instructor') {
                document.getElementById('edit_instructor_id').value = id;
                document.getElementById('edit_name').value = name || '';
                document.getElementById('edit_specialty').value = specialty || '';
                document.getElementById('edit_contact').value = contact || '';
                document.getElementById('edit_email').value = email || '';
                document.getElementById('edit_member_type').value = memberType || 'instructor';
                openModal(editModal);
            };

            window.confirmRemoveInstructor = function(id, name) {
                document.getElementById('remove_instructor_id').value = id;
                document.getElementById('instructorToRemove').textContent = name || '';
                openModal(confirmModal);
            };

            window.viewSchedule = function(id) {
                // Navigate to owner schedule page (studio-scoped)
                // Uses current studio id; instructor id not required by schedule.php
                window.location.href = 'schedule.php?view=daily&studio=<?php echo (int)$studio_id; ?>';
            };

            // Simple search filter
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                document.querySelectorAll('.instructor-item').forEach(card => {
                    const name = (card.querySelector('.instructor-name')?.textContent || '').toLowerCase();
                    const specialty = (card.querySelector('.instructor-specialty')?.textContent || '').toLowerCase();
                    card.style.display = (name.includes(term) || specialty.includes(term)) ? '' : 'none';
                });
            });
        }
        })();

        // Staff UI interactions
        (function() {
            const addStaffBtn = document.getElementById('addStaffBtn');
            const addModal = document.getElementById('addInstructorModal');
            const searchInput = document.getElementById('search-staff');

            if (addStaffBtn && addModal) {
                addStaffBtn.addEventListener('click', () => {
                    // Pre-select "Staff" in the member type dropdown
                    const memberTypeSelect = document.getElementById('member_type');
                    if (memberTypeSelect) {
                        memberTypeSelect.value = 'staff';
                    }
                    openModal(addModal);
                });
            }

            // Simple search filter for staff
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const term = this.value.toLowerCase();
                    document.querySelectorAll('.staff-item').forEach(card => {
                        const name = (card.querySelector('.staff-name')?.textContent || '').toLowerCase();
                        const specialty = (card.querySelector('.staff-specialty')?.textContent || '').toLowerCase();
                        card.style.display = (name.includes(term) || specialty.includes(term)) ? '' : 'none';
                    });
                });
            }
        })();

        // Services UI interactions (match instructors tab behavior)
        (function() {
            const addBtn = document.getElementById('addServiceBtn');
            const addModal = document.getElementById('addServiceModal');
            const searchInput = document.getElementById('search-services');

            if (addBtn && addModal) {
                addBtn.addEventListener('click', () => openModal(addModal));
            }

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const term = this.value.toLowerCase();
                    document.querySelectorAll('.service-item').forEach(card => {
                        const name = (card.querySelector('.service-name')?.textContent || '').toLowerCase();
                        const desc = (card.querySelector('.service-description')?.textContent || '').toLowerCase();
                        card.style.display = (name.includes(term) || desc.includes(term)) ? '' : 'none';
                    });
                });
            }
        })();

        // ============================================
        // Availability Management
        // ============================================
        let currentBlockedDates = [];
        
        window.manageAvailability = function(instructorId, instructorName, blockedDatesString) {
            document.getElementById('avail_instructor_id').value = instructorId;
            document.getElementById('availInstructorName').textContent = instructorName;
            
            // Parse blocked dates from comma-separated string
            currentBlockedDates = blockedDatesString ? blockedDatesString.split(',').filter(d => d.trim()) : [];
            renderBlockedDates();
            
            openModal(document.getElementById('availabilityModal'));
        };
        
        function addBlockedDate() {
            const dateInput = document.getElementById('blockDateInput');
            const selectedDate = dateInput.value;
            
            if (!selectedDate) {
                alert('Please select a date to block');
                return;
            }
            
            if (currentBlockedDates.includes(selectedDate)) {
                alert('This date is already blocked');
                return;
            }
            
            currentBlockedDates.push(selectedDate);
            currentBlockedDates.sort(); // Keep dates sorted
            renderBlockedDates();
            dateInput.value = ''; // Clear input
        }
        
        function removeBlockedDate(date) {
            currentBlockedDates = currentBlockedDates.filter(d => d !== date);
            renderBlockedDates();
        }
        
        function renderBlockedDates() {
            const container = document.getElementById('blockedDatesList');
            
            if (currentBlockedDates.length === 0) {
                container.innerHTML = '<p class="text-gray-400 text-sm text-center py-4">No blocked dates. Instructor is available every day.</p>';
                return;
            }
            
            let html = '';
            currentBlockedDates.forEach(date => {
                const dateObj = new Date(date + 'T00:00:00');
                const formatted = dateObj.toLocaleDateString('en-US', { 
                    weekday: 'short', 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric' 
                });
                
                html += `
                    <div class="flex justify-between items-center bg-[#0a0a0a] border border-[#222222] rounded-md p-3">
                        <span class="text-sm">${formatted}</span>
                        <button type="button" onclick="removeBlockedDate('${date}')" class="text-red-500 hover:text-red-400 text-sm">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        function saveAvailability() {
            const instructorId = document.getElementById('avail_instructor_id').value;
            const blockedDatesString = currentBlockedDates.join(',');
            
            fetch('../php/update-instructor-availability.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `instructor_id=${instructorId}&blocked_dates=${encodeURIComponent(blockedDatesString)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Availability updated successfully!');
                    closeModal(document.getElementById('availabilityModal'));
                    location.reload(); // Reload to show updated data
                } else {
                    alert(data.message || 'Failed to update availability');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating availability');
            });
        }
    </script>
</body>
</html>