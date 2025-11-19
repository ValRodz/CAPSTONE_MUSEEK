<?php
session_start();
require_once __DIR__ . '/db.php';

// Initialize variables
$name = $phone = $email = $password = $confirm_password = "";
$error = "";

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = trim($_POST["name"]);
    $phone = trim($_POST["phone"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];

    // Validate input
    if (empty($name) || empty($phone) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        // Check if email already exists in clients table
        $stmt = $pdo->prepare("SELECT ClientID FROM clients WHERE Email = ?");
        $stmt->execute([$email]);
        $client = $stmt->fetch();
        
        if ($client) {
            $error = "Email already exists. Please use a different email.";
        } else {
            // Check if email exists in studio_owners table
            $stmt = $pdo->prepare("SELECT OwnerID FROM studio_owners WHERE Email = ?");
            $stmt->execute([$email]);
            $owner = $stmt->fetch();
            
            if ($owner) {
                $error = "Email already exists. Please use a different email.";
            } else {
                // Set verification status to 1 (Verified) as per your database
                $v_status = 1;
                
                // Insert new client
                $stmt = $pdo->prepare("INSERT INTO clients (Name, Email, Phone, Password, V_StatsID) VALUES (?, ?, ?, ?, ?)");
                
                if ($stmt->execute([$name, $email, $phone, $password, $v_status])) {
                    // Registration successful
                    $_SESSION["registration_success"] = true;
                    
                    // Redirect to login page
                    header("Location: login.php");
                    exit();
                } else {
                    $error = "Registration failed. Please try again later.";
                }
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
    <title>Register - MuSeek</title>
    <!-- Loading third party fonts -->
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet"
        type="text/css">
    <link href="fonts/font-awesome.min.css" rel="stylesheet" type="text/css">

    <style>
        body {
            background: url('dummy/slide-2.jpg') no-repeat center center fixed;
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
            top: 50%;
            transform: translateY(-50%);
            color: #ccc;
            cursor: pointer;
            font-size: 16px;
            z-index: 2;
            /* Ensure icon is above input and label */
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
            text-align: left;
        }
    </style>
</head>

<body class="header-collapse">
    <div id="site-content">
        <main class="main-content">
            <div class="fullwidth-block">
                <a id="branding">
                    <img src="images/logo4.png" alt="MuSeek">
                </a>
                <div class="contact-form">
                    <h2>Create Account</h2>
                    <?php if (!empty($error)): ?>
                        <div class="error"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                        <div class="form-group">
                            <input type="text" name="name" id="name" placeholder=" " value="<?php echo htmlspecialchars($name); ?>" required>
                            <label for="name">Name</label>
                        </div>
                        <div class="form-group">
                            <input type="text" name="phone" id="phone" placeholder=" " value="<?php echo htmlspecialchars($phone); ?>" required>
                            <label for="phone">Phone Number</label>
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" id="email" placeholder=" " value="<?php echo htmlspecialchars($email); ?>" required>
                            <label for="email">Email Address</label>
                        </div>
                        <div class="form-group">
                            <input type="password" name="password" id="password" placeholder=" " required>
                            <label for="password">Password</label>
                            <i class="fa fa-eye toggle-password" onclick="togglePassword('password')"></i>
                        </div>
                        <div class="form-group">
                            <input type="password" name="confirm_password" id="confirm_password" placeholder=" " required>
                            <label for="confirm_password">Re-enter your password</label>
                            <i class="fa fa-eye toggle-password" onclick="togglePassword('confirm_password')"></i>
                        </div>
                        <div class="terms">
                            <input type="checkbox" name="terms" id="terms" required>
                            <label for="terms">I agree all statements in Terms of Service</label>
                        </div>
                        <input type="submit" value="Sign Up">
                    </form>
                    <div class="additional-options">
                        <p>Have already an account? <a href="login.php">Login here</a></p>
                        <p>Are you a studio owner? <a href="owner_register.php">Register your studio</a></p>
                    </div>
                </div>
            </div>
        </main>
    </div>

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
    </script>
</body>

</html>