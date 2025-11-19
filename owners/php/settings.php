<?php
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validation_utils.php';

$ownerId = $_SESSION['user_id'];

// Initialize messages
$success_message = '';
$error_message = '';

// Fetch owner data
$owner = $pdo->prepare("
    SELECT * 
    FROM studio_owners 
    WHERE OwnerID = ?
");
$owner->execute([$ownerId]);
$owner = $owner->fetch(PDO::FETCH_ASSOC);

if (!$owner) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Validate input
        $validation_rules = [
            'name' => ['type' => 'name', 'required' => true],
            'email' => ['type' => 'email', 'required' => true],
            'phone' => ['type' => 'phone', 'required' => true]
        ];
        
        $validation_errors = ValidationUtils::validateForm($_POST, $validation_rules);
        
        if (empty($validation_errors)) {
            try {
                $name = ValidationUtils::sanitizeInput($_POST['name']);
                $email = ValidationUtils::sanitizeInput($_POST['email']);
                $phone = ValidationUtils::sanitizeInput($_POST['phone']);
                
                $stmt = $pdo->prepare("UPDATE studio_owners SET Name = ?, Email = ?, Phone = ? WHERE OwnerID = ?");
                $stmt->execute([$name, $email, $phone, $ownerId]);
                
                $success_message = "Profile updated successfully!";
                // Refresh owner data
                $owner = $pdo->prepare("SELECT * FROM studio_owners WHERE OwnerID = ?");
                $owner->execute([$ownerId]);
                $owner = $owner->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $error_message = "Error updating profile: " . $e->getMessage();
            }
        } else {
            $error_message = ValidationUtils::formatErrors($validation_errors);
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        if (!password_verify($current_password, $owner['Password'])) {
            $error_message = "Current password is incorrect.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error_message = "New password must be at least 8 characters long.";
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE studio_owners SET Password = ? WHERE OwnerID = ?");
                $stmt->execute([$hashed_password, $ownerId]);
                
                $success_message = "Password changed successfully!";
            } catch (Exception $e) {
                $error_message = "Error changing password: " . $e->getMessage();
            }
        }
    }
}

// Get owner's studios count
$studiosCount = $pdo->prepare("SELECT COUNT(*) FROM studios WHERE OwnerID = ?");
$studiosCount->execute([$ownerId]);
$totalStudios = $studiosCount->fetchColumn();

// Get total bookings
$bookingsCount = $pdo->prepare("
    SELECT COUNT(*) FROM bookings b
    JOIN studios s ON b.StudioID = s.StudioID
    WHERE s.OwnerID = ?
");
$bookingsCount->execute([$ownerId]);
$totalBookings = $bookingsCount->fetchColumn();

// Get total revenue
$revenueStmt = $pdo->prepare("
    SELECT COALESCE(SUM(p.Amount), 0) FROM payment p
    JOIN bookings b ON p.BookingID = b.BookingID
    JOIN studios s ON b.StudioID = s.StudioID
    WHERE s.OwnerID = ? AND p.Pay_Stats = 'Completed'
");
$revenueStmt->execute([$ownerId]);
$totalRevenue = $revenueStmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - MuSeek Studio</title>
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">
    
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

        .settings-container {
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid #333;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--netflix-red), #ff6b6b);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .stat-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 20px;
        }

        .stat-icon.studios {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
        }

        .stat-icon.bookings {
            background: linear-gradient(135deg, var(--info-blue), #0071eb);
        }

        .stat-icon.revenue {
            background: linear-gradient(135deg, var(--success-green), #46d369);
        }

        .stat-title {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            margin: 0;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--netflix-white);
            margin: 5px 0 0 0;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }

        .settings-card {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 30px;
            border: 1px solid #333;
            position: relative;
            overflow: hidden;
        }

        .settings-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--netflix-red), #ff6b6b);
        }

        .card-header {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #333;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--netflix-white);
            margin: 0 0 5px 0;
            display: flex;
            align-items: center;
        }

        .card-title i {
            margin-right: 10px;
            color: var(--netflix-red);
        }

        .card-subtitle {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            margin: 0;
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
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ff5252);
            color: var(--netflix-white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #ff5252, #f44336);
            transform: translateY(-1px);
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

        .profile-section {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            border: 1px solid #333;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--netflix-white);
            font-size: 32px;
            font-weight: 600;
        }

        .profile-info h3 {
            color: var(--netflix-white);
            margin: 0 0 5px 0;
            font-size: 1.5rem;
        }

        .profile-info p {
            color: var(--netflix-light-gray);
            margin: 0;
        }

        .danger-zone {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.1), var(--netflix-dark-gray));
            border: 1px solid #ff6b6b;
            border-radius: 12px;
            padding: 25px;
            margin-top: 30px;
        }

        .danger-zone h3 {
            color: #ff6b6b;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
        }

        .danger-zone h3 i {
            margin-right: 10px;
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
            
            .settings-container {
                padding: 20px;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .settings-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-section {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <div class="main-content">
        <div class="settings-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Settings</h1>
                <p class="page-subtitle">Manage your account and studio preferences</p>
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

            <!-- Profile Section -->
            <div class="profile-section fade-in">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($owner['Name'], 0, 2)); ?>
                </div>
                <div class="profile-info">
                    <h3><?php echo htmlspecialchars($owner['Name']); ?></h3>
                    <p><?php echo htmlspecialchars($owner['Email']); ?></p>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon studios">
                            <i class="fas fa-building"></i>
                        </div>
                        <div>
                            <p class="stat-title">Total Studios</p>
                            <p class="stat-value"><?php echo $totalStudios; ?></p>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon bookings">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div>
                            <p class="stat-title">Total Bookings</p>
                            <p class="stat-value"><?php echo $totalBookings; ?></p>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon revenue">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div>
                            <p class="stat-title">Total Revenue</p>
                            <p class="stat-value">â‚±<?php echo number_format($totalRevenue, 2); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Settings Forms -->
            <div class="settings-grid fade-in">
                <!-- Profile Settings -->
                <div class="settings-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user"></i>
                            Profile Information
                        </h3>
                        <p class="card-subtitle">Update your personal information</p>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="form-group">
                            <label for="name" class="form-label">Full Name *</label>
                            <input type="text" 
                                   id="name" 
                                   name="name" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($owner['Name']); ?>"
                                   required
                                   maxlength="50">
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="form-label">Email Address *</label>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($owner['Email']); ?>"
                                   required
                                   maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="phone" class="form-label">Phone Number *</label>
                            <input type="tel" 
                                   id="phone" 
                                   name="phone" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($owner['Phone']); ?>"
                                   required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Update Profile
                        </button>
                    </form>
                </div>

                <!-- Password Settings -->
                <div class="settings-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-lock"></i>
                            Change Password
                        </h3>
                        <p class="card-subtitle">Update your account password</p>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="form-group">
                            <label for="current_password" class="form-label">Current Password *</label>
                            <input type="password" 
                                   id="current_password" 
                                   name="current_password" 
                                   class="form-control" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password" class="form-label">New Password *</label>
                            <input type="password" 
                                   id="new_password" 
                                   name="new_password" 
                                   class="form-control" 
                                   required
                                   minlength="8">
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirm New Password *</label>
                            <input type="password" 
                                   id="confirm_password" 
                                   name="confirm_password" 
                                   class="form-control" 
                                   required
                                   minlength="8">
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-key"></i>
                            Change Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="danger-zone fade-in">
                <h3>
                    <i class="fas fa-exclamation-triangle"></i>
                    Danger Zone
                </h3>
                <p style="color: var(--netflix-light-gray); margin-bottom: 20px;">
                    These actions are irreversible. Please proceed with caution.
                </p>
                
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <button class="btn btn-danger" onclick="confirmAction('Are you sure you want to delete your account? This action cannot be undone.', 'delete_account.php')">
                        <i class="fas fa-trash"></i>
                        Delete Account
                    </button>
                    
                    <button class="btn btn-secondary" onclick="confirmAction('Are you sure you want to export all your data?', 'export_data.php')">
                        <i class="fas fa-download"></i>
                        Export Data
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function confirmAction(message, url) {
            if (confirm(message)) {
                window.location.href = url;
            }
        }

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
    </script>
</body>
</html>

