<?php
require_once '../../shared/config/path_config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check for delete account success message
$delete_success = isset($_SESSION['delete_success']) ? $_SESSION['delete_success'] : '';
if (!empty($delete_success)) {
    unset($_SESSION['delete_success']);
}
$signup_success = isset($_SESSION['signup_success']) ? $_SESSION['signup_success'] : '';
if (!empty($signup_success)) {
    unset($_SESSION['signup_success']);
}
$rememberSelectorName = defined('REMEMBER_SELECTOR_COOKIE') ? REMEMBER_SELECTOR_COOKIE : 'museek_rsel';
$rememberValidatorName = defined('REMEMBER_VALIDATOR_COOKIE') ? REMEMBER_VALIDATOR_COOKIE : 'museek_rval';
$hasRememberCookie = isset($_COOKIE[$rememberSelectorName], $_COOKIE[$rememberValidatorName]);

if (isset($_GET['admin']) && $_GET['admin'] === '1') {
    header('Location: ../../admin/php/login.php');
    exit();
}
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
    header('Location: ../../');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Log In - MuSeek</title>
    <!-- Loading third party fonts -->
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet"
        type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet"
        type="text/css">

    <style>
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --overlay-bg: rgba(0,0,0,0.55);
            --card-bg: rgba(20,20,20,0.95);
            --border-color: #333;
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
        }
        body {
            background: url('../../shared/assets/images/dummy/slide-1.jpg') no-repeat center center fixed;
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
            background-color: rgba(0, 0, 0, 0.15);
            z-index: -1;
        }

        #site-content {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .fullwidth-block {
            text-align: center;
            padding: 20px 0;
            width: 100%;
            max-width: 600px;
        }

        #branding {
            margin: 0 auto 0;
            display: block;
            text-align: center;
        }

        #branding img {
            width: 300px;
            margin: auto;
            display: block;
        }

        .contact-form {
            max-width: 500px;
            margin: 0 auto;
            padding: 40px;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 50px;
            text-align: center;
        }

        .contact-form h2 {
            font-size: 32px;
            margin-top: 0;
            margin-bottom: 20px;
            margin-left: auto;
            margin-right: auto;
            color: #fff;
        }

        .form-group {
            position: relative;
            margin-bottom: 10px;
            text-align: left;
        }

        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group input[type="text"] {
            /* Ensure consistent styling for both types */
            width: 100%;
            padding: 15px 40px 15px 15px;
            /* Consistent padding to accommodate eye icon */
            font-size: 16px;
            line-height: 1;
            /* Prevent line-height changes */
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #fff;
            border-radius: 4px;
            box-sizing: border-box;
            text-align: left;
            font-family: inherit;
            /* Ensure consistent font rendering */
            -webkit-appearance: none;
            /* Remove browser-specific styling */
            -moz-appearance: none;
            appearance: none;
            transition: none;
            /* Disable transitions to prevent shifting */
        }

        .form-group input[type="email"]::placeholder,
        .form-group input[type="password"]::placeholder,
        .form-group input[type="text"]::placeholder {
            color: #ccc;
        }

        .form-group .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #ccc;
            cursor: pointer;
            font-size: 16px;
            z-index: 2;
        }

        .contact-form input[type="submit"] {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            background-color: var(--primary-color);
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }

        .contact-form input[type="submit"]:hover {
            background-color: var(--primary-hover);
        }

        .contact-form .additional-options {
            text-align: center;
            margin-top: 15px;
            color: #999;
        }

        .contact-form .additional-options a,
        .contact-form .additional-options p {
            color: #999;
            text-decoration: none;
            font-size: 14px;
        }

        .contact-form .additional-options a:hover {
            text-decoration: underline;
        }

        .contact-form .additional-options .signup-options a {
            margin: 0 5px;
        }

        .contact-form .additional-options .signup-options span {
            color: #999;
            font-size: 14px;
        }

        .contact-form .additional-options hr {
            border: 0;
            border-top: 1px solid #999;
            margin: 10px 0;
        }

        .contact-form .error {
            color: #e87c03;
            margin-bottom: 15px;
            font-size: 14px;
            display: none;
        }

        .remember-row {
            display: flex;
            align-items: center;
            margin: 12px 0 4px;
            color: #ccc;
            font-size: 14px;
        }
        .remember-control {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .remember-control input {
            width: 16px;
            height: 16px;
            accent-color: var(--primary-color);
        }

        /* Submit-loading overlay */
        .overlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: var(--overlay-bg); z-index: 9999; }
        .overlay.show { display: flex; }
        .loading-card { display:flex; align-items:center; gap:12px; background: var(--card-bg); border:1px solid var(--border-color); padding:18px 20px; border-radius:12px; box-shadow: 0 4px 16px rgba(0,0,0,0.4); }
        .spinner { width: 28px; height: 28px; border: 3px solid rgba(255,255,255,0.25); border-top-color: #fff; border-radius: 50%; animation: spin 0.9s linear infinite; }
        .loading-text { font-size: 1.05rem; color: var(--text-primary); }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>

<body class="header-collapse">
    <div id="site-content">
        <main class="main-content">
            <div class="fullwidth-block">
                <a href="../../" id="branding">
                    <img src="<?php echo getImagePath('images/logo4.png'); ?>" alt="Site Name">
                </a>
                <div class="contact-form">
                    <?php if (!empty($delete_success)): ?>
                        <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: left;">
                            <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($delete_success); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($signup_success)): ?>
                        <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: #60a5fa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: left;">
                            <i class="fa fa-info-circle"></i> <?php echo htmlspecialchars($signup_success); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form action="process_login.php" method="POST">
                        <div class="form-group">
                            <input type="email" name="email" placeholder="Email or mobile number" required>
                        </div>
                        <div class="form-group">
                            <input type="password" name="password" id="password" placeholder="Password" required>
                            <i class="fa fa-eye toggle-password" onclick="togglePassword('password')"></i>
                        </div>
                        <div class="remember-row">
                            <label class="remember-control">
                                <input type="checkbox" name="remember_me" value="1" <?php echo $hasRememberCookie ? 'checked' : ''; ?>>
                                <span>Remember me</span>
                            </label>
                        </div>
                        <input type="submit" value="Log In">
                    </form>
                    <div class="additional-options">
                        <p>New to MuSeek?</p>
                        <div class="signup-options">
                            <a href="signin.php">Register as Client</a>
                            <span>|</span>
                            <a href="owner_register.php">Register as Studio Owner</a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Loading overlay shown on submit -->
    <div class="overlay" id="loginSubmitOverlay">
        <div class="loading-card">
            <div class="spinner"></div>
            <div class="loading-text">Logging In</div>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = passwordField.nextElementSibling;
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

        // Show loading overlay on submit
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('.contact-form form');
            const overlay = document.getElementById('loginSubmitOverlay');
            if (form && overlay) {
                form.addEventListener('submit', function() {
                    overlay.classList.add('show');
                    const submit = form.querySelector('input[type="submit"]');
                    if (submit) { submit.disabled = true; submit.value = 'Logging In...'; }
                });
            }
        });
    </script>
</body>

</html>