<?php require_once '../../shared/config/path_config.php';

if (session_status() === PHP_SESSION_NONE) {
session_start();
}
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
header('Location: ../../');
exit();
}

$signupError = $_SESSION['signup_error'] ?? '';
$signupOld = $_SESSION['signup_old'] ?? ['name' => '', 'phone' => '', 'email' => ''];
unset($_SESSION['signup_error'], $_SESSION['signup_old']);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Register - MuSeek</title>
    <!-- Loading third party fonts -->
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet" type="text/css">

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
            background-color: rgba(0, 0, 0, 0.10);
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
            margin: 0 0 40px;
            display: block;
        }

        #branding img {
            padding-top: 5%;
            padding-left: 0;
            width: 300px;
            margin: 0 auto;
            display: block;
        }

        .contact-form {
            max-width: 500px;
            margin: 0 auto;
            padding: 40px;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 50px;
            text-align: center;
            position: relative;
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
            margin-bottom: 25px;
            /* Increased spacing for clarity */
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
            /* Ensure label is above input */
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            padding-right: 40px;
            /* Add padding to accommodate eye icon */
            font-size: 16px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #fff;
            border-radius: 4px;
            box-sizing: border-box;
            text-align: left;
            position: relative;
            z-index: 0;
            /* Ensure input is below label */
        }

        .form-group input::placeholder {
            color: transparent;
        }

        /* Floating label behavior */
        .form-group input:focus+label,
        .form-group input:not(:placeholder-shown)+label {
            top: -8px;
            /* Adjusted for better alignment */
            left: 10px;
            /* Align with input border */
            font-size: 13px;
            color: #fff;
            background: rgba(0, 0, 0, 0.9);
            border-radius: 4px;
            /* Background to prevent overlap with input border */
            padding: 0 5px;
            /* Small padding for better appearance */
        }

        .form-group .toggle-password {
            position: absolute;
            right: 15px;
            top: 22px;
            color: #ccc;
            cursor: pointer;
            font-size: 16px;
            z-index: 2;
            /* Ensure icon is above input and label */
        }

        /* Input helper note under fields */
        .form-group .input-note {
            display: block;
            margin-top: 6px;
            font-size: 18px;
            color: #ccc;
        }

        .contact-form input[type="submit"] {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }

        .contact-form input[type="submit"]:hover {
            background-color: #f40612;
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

        .contact-form .terms {
            text-align: left;
            margin-bottom: 15px;
            color: #ccc;
        }

        .contact-form .terms input[type="checkbox"] {
            margin-right: 10px;
        }

        .contact-form .error {
            color: #e87c03;
            margin-bottom: 15px;
            font-size: 14px;
            display: none;
        }

        .form-error {
            display: none;
            margin-bottom: 15px;
            padding: 10px 14px;
            border-radius: 8px;
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fecaca;
            font-size: 14px;
        }
        .form-error.show {
            display: block;
        }

        .password-checklist {
            list-style: none;
            margin: 12px 0 4px;
            padding: 0;
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
            border: 1px solid rgba(255, 255, 255, 0.4);
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
        .match-status.is-valid {
            color: #10b981;
        }
        .match-status.is-invalid {
            color: #f87171;
        }

        .field-error {
            display: none;
            margin-top: 6px;
            color: #f87171;
            font-size: 12px;
        }
        .field-error.show {
            display: block;
        }
        .input-invalid {
            border-color: #f87171 !important;
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
                    <h2>Create Account</h2>
                    <div class="form-error<?php echo $signupError ? ' show' : ''; ?>" data-form-error role="alert">
                        <?php echo htmlspecialchars($signupError); ?>
                    </div>
                    <form action="process_signin.php" method="POST" data-persist-key="client-signup" novalidate>
                        <div class="form-group">
                            <input type="text" name="name" id="name" placeholder=" " required maxlength="100" pattern="^[A-Za-zÀ-ÖØ-öø-ÿ'.\- ]{2,100}$" title="Letters, spaces, apostrophes, and dashes only." autocomplete="name" data-allow="alpha" value="<?php echo htmlspecialchars($signupOld['name'] ?? ''); ?>">
                            <label for="name">Name</label>
                        </div>
                        <div class="form-group">
                            <input type="text" name="phone" id="phone" placeholder=" " maxlength="17" required inputmode="numeric" pattern="^\+63\s[0-9]{3}\s[0-9]{3}\s[0-9]{4}$" title="+63 9xx xxx xxxx format only" value="<?php echo htmlspecialchars($signupOld['phone'] ?? ''); ?>">
                            <label for="phone">Phone Number</label>
                            <small class="input-note">Format: +63 9xx xxx xxxx</small>
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" id="email" placeholder=" " required maxlength="120" autocomplete="email" data-email-validate value="<?php echo htmlspecialchars($signupOld['email'] ?? ''); ?>">
                            <label for="email">Email Address</label>
                            <small class="field-error" data-email-error>Please enter a valid email address.</small>
                        </div>
                        <div class="form-group">
                            <input type="password" name="password" id="password" placeholder=" " required minlength="8" maxlength="72" data-password-field autocomplete="new-password">
                            <label for="password">Password</label>
                            <i class="fa fa-eye toggle-password" onclick="togglePassword('password')"></i>
                            <small class="input-note" style="display: block; margin-top: 8px; font-size: 13px; line-height: 1.4;">
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
                            <input type="password" name="confirm_password" id="confirm_password" placeholder=" "
                                required onpaste="return false" minlength="8" maxlength="72" data-password-confirm autocomplete="new-password">
                            <label for="confirm_password">Re-enter your password</label>
                            <i class="fa fa-eye toggle-password" onclick="togglePassword('confirm_password')"></i>
                            <div class="match-status" data-password-match></div>
                        </div>
                        <div class="terms">
                            <input type="checkbox" name="terms" id="terms" required>
                            <label for="terms">I agree all statements in <a href="terms_of_service_client.php" target="_blank" style="color: #e50914; text-decoration: underline;">Terms of Service</a></label>
                        </div>
                        <input type="submit" value="Sign Up">
                    </form>
                    <div class="additional-options">
                        <p>Have already an account? <a href="login.php">Login here</a></p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="loading-overlay" class="loading-overlay" aria-hidden="true">
        <div class="loading-content">
            <div class="spinner" aria-hidden="true"></div>
            <div class="loading-message">Please wait a little while we process your registration</div>
        </div>
    </div>

    <style>
        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .loading-overlay.show {
            display: flex;
        }

        .loading-content {
            text-align: center;
            color: #fff;
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid rgba(255, 255, 255, 0.25);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .loading-message {
            font-size: 16px;
        }
    </style>

    <script src="../js/form-persist.js"></script>
    <script src="../js/password-meter.js"></script>
    <script src="../js/input-validators.js"></script>
    <script>
        function togglePassword(fieldId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = passwordField.nextElementSibling.nextElementSibling; // Adjusted to skip the label
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
            const rules = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
            };
            const allMet = Object.values(rules).every(Boolean);
            return { valid: allMet, requirements: rules };
        }

        // Show loading overlay on form submit
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('.contact-form form');
            const overlay = document.getElementById('loading-overlay');
            const phoneInput = document.getElementById('phone');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const formError = document.querySelector('[data-form-error]');
            
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
            
            const showError = (message) => {
                if (formError) {
                    formError.textContent = message;
                    formError.classList.add('show');
                } else {
                    alert(message);
                }
            };

            if (form && overlay) {
                form.addEventListener('submit', function(e) {
                    if (formError) {
                        formError.classList.remove('show');
                        formError.textContent = '';
                    }

                    // Validate phone format
                    const phoneValue = phoneInput.value.replace(/\D/g, '');
                    if (phoneValue.length !== 12 || !phoneValue.startsWith('63')) {
                        e.preventDefault();
                        showError('Please enter a valid Philippine phone number in format: +63 9xx xxx xxxx');
                        overlay.classList.remove('show');
                        return false;
                    }
                    
                    // Validate email contains @
                    if (!emailInput.value.includes('@')) {
                        e.preventDefault();
                        showError('Please enter a valid email address with @');
                        overlay.classList.remove('show');
                        return false;
                    }
                    
                    // Validate password strength
                    const passwordValidation = validatePassword(passwordInput.value);
                    if (!passwordValidation.valid) {
                        e.preventDefault();
                        let missingRequirements = [];
                        if (!passwordValidation.requirements.length) missingRequirements.push('at least 8 characters');
                        if (!passwordValidation.requirements.uppercase) missingRequirements.push('uppercase letter');
                        if (!passwordValidation.requirements.lowercase) missingRequirements.push('lowercase letter');
                        if (!passwordValidation.requirements.number) missingRequirements.push('number');
                        if (!passwordValidation.requirements.special) missingRequirements.push('special character (!@#$%^&*)');
                        
                        showError('Password must contain: ' + missingRequirements.join(', '));
                        overlay.classList.remove('show');
                        return false;
                    }
                    
                    // Validate password confirmation
                    if (passwordInput.value !== confirmPasswordInput.value) {
                        e.preventDefault();
                        showError('Passwords do not match. Please re-enter your password.');
                        overlay.classList.remove('show');
                        return false;
                    }
                    
                    overlay.classList.add('show');
                    const submit = form.querySelector('input[type="submit"]');
                    if (submit) {
                        submit.disabled = true;
                        submit.value = 'Processing...';
                    }
                });
            }

            // Prevent paste/drop on confirm password field
            const confirmField = document.getElementById('confirm_password');
            if (confirmField) {
                confirmField.addEventListener('paste', function(e) {
                    e.preventDefault();
                });
                confirmField.addEventListener('drop', function(e) {
                    e.preventDefault();
                });
            }
        });
    </script>
</body>

</html>