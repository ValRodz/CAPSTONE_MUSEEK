<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/models/AdminUser.php';

$error = '';
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $email_value = htmlspecialchars($email);
    
    // CSRF validation
    if (!validateCSRFToken($csrf_token)) {
        $error = 'Invalid request. Please try again.';
    }
    // Email validation
    elseif (empty($email)) {
        $error = 'Email is required.';
    }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    }
    elseif (strlen($email) > 255) {
        $error = 'Email is too long.';
    }
    // Password validation
    elseif (empty($password)) {
        $error = 'Password is required.';
    }
    elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    }
    elseif (strlen($password) > 255) {
        $error = 'Password is too long.';
    }
    // Authentication
    else {
        $adminUser = new AdminUser();
        $user = $adminUser->authenticate($email, $password);
        
        if ($user) {
            session_regenerate_id(true);
            
            $_SESSION['admin_id'] = $user['admin_id'];
            $_SESSION['admin_name'] = $user['full_name'];
            $_SESSION['admin_email'] = $user['email'];
            $_SESSION['admin_role'] = $user['role'];
            
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Inter', sans-serif;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }
        .login-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }
        .login-card h1 {
            margin: 0 0 8px 0;
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
        }
        .login-card > p {
            margin: 0 0 32px 0;
            color: #718096;
            font-size: 14px;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .alert-danger {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 14px;
            color: #2d3748;
        }
        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
            width: 100%;
        }
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #2d3748;
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-secondary:hover {
            background: #cbd5e0;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .btn-secondary i {
            margin-right: 6px;
        }
        .link {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        .link:hover {
            text-decoration: underline;
        }
        .text-sm {
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <h1>Admin Login</h1>
            <p>Sign in to access the admin panel</p>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" autocomplete="off">
                <?= csrfField() ?>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input 
                        type="email" 
                        name="email" 
                        class="form-input" 
                        required 
                        maxlength="255"
                        placeholder="admin@example.com"
                        value="<?= $email_value ?>"
                        autocomplete="email"
                    >
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input 
                        type="password" 
                        name="password" 
                        class="form-input" 
                        required 
                        minlength="8" 
                        maxlength="255"
                        placeholder="••••••••"
                        autocomplete="current-password"
                    >
                </div>
                
                <button type="submit" class="btn btn-primary">Sign in</button>
                
                <div style="margin-top: 16px; text-align: center;">
                    <a href="#" class="link text-sm">Forgot password?</a>
                </div>
            </form>
            
            <div style="margin-top: 24px; text-align: center;">
                <a href="../../index.php" class="btn btn-secondary" style="display: inline-block; text-decoration: none;">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>
            </div>
        </div>
    </div>
</body>
</html>
