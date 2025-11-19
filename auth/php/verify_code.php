<?php
session_start();

if (!isset($_SESSION['pending_login'])) {
    header('Location: login.php');
    exit;
}

$pending = $_SESSION['pending_login'];
$error = $_SESSION['otp_error'] ?? '';
unset($_SESSION['otp_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['otp'] ?? '');

    if ($code === '' || strlen($code) !== 6) {
        $_SESSION['otp_error'] = 'Please enter the 6-digit code that was emailed to you.';
        header('Location: verify_code.php');
        exit;
    }

    if (time() > ($pending['expires_at'] ?? 0)) {
        unset($_SESSION['pending_login']);
        $_SESSION['signup_error'] = 'The verification code has expired. Please log in again to request a new code.';
        header('Location: login.php');
        exit;
    }

    if ($code !== ($pending['otp'] ?? '')) {
        $_SESSION['otp_error'] = 'Incorrect code. Please double-check and try again.';
        header('Location: verify_code.php');
        exit;
    }

    include '../../shared/config/db.php';
    require_once __DIR__ . '/utils/login_security.php';
    require_once '../../shared/php/remember_me.php';

    $userId = (int)$pending['user_id'];
    $userType = $pending['user_type'];

    refreshTrustedLogin($conn, $userType, $userId);

    if ($userType === 'owner') {
        $updateStmt = $conn->prepare("UPDATE studio_owners SET last_login = NOW() WHERE OwnerID = ?");
        $updateStmt->bind_param('i', $userId);
        $updateStmt->execute();
        $updateStmt->close();
    }

    $remember = !empty($pending['remember_me']);
    if ($remember) {
        issueRememberMeToken($conn, $userType, $userId);
    } else {
        clearRememberMeCookie($conn);
    }

    unset($_SESSION['pending_login']);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_type'] = $userType;

    if ($userType === 'owner') {
        header('Location: ../../owners/php/dashboard.php');
    } else {
        header('Location: ../../');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Login Code - MuSeek</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet">
    <style>
        body {
            background: url('../../shared/assets/images/dummy/slide-1.jpg') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Source Sans Pro', sans-serif;
            margin: 0;
            color: #fff;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: -1;
        }
        .wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: rgba(0,0,0,0.8);
            border-radius: 24px;
            padding: 32px;
            text-align: center;
            box-shadow: 0 25px 60px rgba(0,0,0,0.45);
        }
        h1 {
            margin: 0 0 10px;
            font-size: 28px;
        }
        p.subtitle {
            font-size: 15px;
            color: #d1d5db;
            margin-bottom: 24px;
        }
        .otp-input {
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 22px;
            letter-spacing: 8px;
            text-align: center;
        }
        .otp-input::placeholder {
            color: rgba(255,255,255,0.4);
        }
        .error {
            margin: 16px 0 0;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(248,113,113,0.2);
            border: 1px solid rgba(248,113,113,0.5);
            color: #fecaca;
            font-size: 14px;
        }
        button {
            width: 100%;
            margin-top: 20px;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: #e50914;
            color: #fff;
            font-size: 16px;
            cursor: pointer;
        }
        button:hover {
            background: #f40612;
        }
        .secondary {
            margin-top: 16px;
            font-size: 14px;
            color: #d1d5db;
        }
        .secondary a {
            color: #60a5fa;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <h1>Verify your code</h1>
            <p class="subtitle">
                Enter the 6-digit code we sent to <strong><?php echo htmlspecialchars($pending['email']); ?></strong>.
                Codes stay valid for 10 minutes.
            </p>
            <form method="POST" autocomplete="off">
                <input type="text" name="otp" class="otp-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autofocus placeholder="••••••" required>
                <?php if (!empty($error)): ?>
                    <div class="error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <button type="submit">Verify & Continue</button>
            </form>
            <div class="secondary">
                Wrong email? <a href="login.php">Go back to login</a>
            </div>
        </div>
    </div>
</body>
</html>

