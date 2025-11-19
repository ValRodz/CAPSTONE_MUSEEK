<?php
session_start();
require_once '../../shared/config/path_config.php';

$mode = $_POST['mode'] ?? $_GET['mode'] ?? 'registration';
$now  = time();

function fail_and_redirect($message, $redirect) {
    echo "<script>alert('" . addslashes($message) . "'); window.location.href='" . $redirect . "';</script>";
    exit;
}

if ($mode !== 'owner_registration') {
    $pending = $_SESSION['pending_registration'] ?? null;
    $email   = $pending['email'] ?? '';
    $error   = '';
    $expired = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$pending) {
            $error = 'No pending registration found. Please sign up again.';
        } else {
            $code = trim($_POST['verification_code'] ?? '');
            if ($code === '' || strlen($code) !== 6) {
                $error = 'Please enter the 6-digit code from your email.';
            } elseif ($now > (int)$pending['expires_at']) {
                unset($_SESSION['pending_registration']);
                $error = 'This code has expired. Please register again.';
                $expired = true;
            } elseif ($code !== (string)$pending['otp']) {
                $error = 'Incorrect code. Please try again.';
            } else {
                include '../../shared/config/db.php';

                $name  = $pending['name'];
                $phone = $pending['phone'];
                $passwordHash = $pending['password_hash'] ?? null;

                if (!$passwordHash) {
                    unset($_SESSION['pending_registration']);
                    fail_and_redirect('Verification data missing. Please register again.', 'signin.php');
                }

                $stmt = $conn->prepare("INSERT INTO clients (Phone, Email, Password, Name, V_StatsID) VALUES (?, ?, ?, ?, 2)");
                if (!$stmt) {
                    fail_and_redirect('Database error: unable to prepare statement.', 'signin.php');
                }
                $stmt->bind_param('ssss', $phone, $email, $passwordHash, $name);

                if (!$stmt->execute()) {
                    fail_and_redirect('Database error: unable to create account.', 'signin.php');
                }

                $newUserId = $conn->insert_id;
                $stmt->close();
                $conn->close();

                unset($_SESSION['pending_registration']);

                $_SESSION['user_id'] = $newUserId;
                $_SESSION['user_type'] = 'client';

                header('Location: ../../');
                exit;
            }
        }
    }

    $missingPending = !$pending;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Verify Registration - MuSeek</title>
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
            h1 { margin: 0 0 10px; font-size: 28px; }
            p.subtitle { font-size: 15px; color: #d1d5db; margin-bottom: 24px; }
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
            .otp-input::placeholder { color: rgba(255,255,255,0.4); }
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
            button:hover { background: #f40612; }
            .secondary {
                margin-top: 16px;
                font-size: 14px;
                color: #d1d5db;
            }
            .secondary a { color: #60a5fa; }
        </style>
    </head>
    <body>
        <div class="wrapper">
            <div class="card">
                <?php if ($missingPending): ?>
                    <h1>Nothing to verify</h1>
                    <p class="subtitle">We couldn’t find a pending registration. Please start again.</p>
                    <div class="secondary">
                        <a href="signin.php">Back to registration</a>
                    </div>
                <?php else: ?>
                    <h1>Enter verification code</h1>
                    <p class="subtitle">
                        We sent a 6-digit code to <strong><?php echo htmlspecialchars($email); ?></strong>.<br>
                        Codes expire in 60 minutes.
                    </p>
                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="mode" value="registration">
                        <input type="text" name="verification_code" class="otp-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="••••••" required>
                        <?php if (!empty($error)): ?>
                            <div class="error"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <?php if ($expired): ?>
                            <div class="secondary" style="color:#fca5a5;">Registration expired. Please <a href="signin.php">start again</a>.</div>
                        <?php else: ?>
                            <button type="submit">Verify &amp; Continue</button>
                        <?php endif; ?>
                    </form>
                    <div class="secondary">
                        Need to edit your info? <a href="signin.php">Register again</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Legacy owner registration flow (still uses tokenized links)
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($token === '') {
    fail_and_redirect('Invalid verification link.', 'login.php');
}

if (!isset($_SESSION['pending_owner_registration'])) {
    fail_and_redirect('No pending owner registration found.', 'owner_register.php');
}

$data = $_SESSION['pending_owner_registration'];
if (!isset($data['token'], $data['expires_at']) || $token !== $data['token']) {
    fail_and_redirect('Invalid or mismatched verification token.', 'owner_register.php');
}
if ($now > (int)$data['expires_at']) {
    unset($_SESSION['pending_owner_registration']);
    fail_and_redirect('Verification link expired. Please register again.', 'owner_register.php');
}

require_once __DIR__ . '/../../shared/config/db pdo.php';

$name        = $data['name'];
$phone       = $data['phone'];
$email       = $data['email'];
$password    = $data['password'];
$studio_name = $data['studio_name'];
$latitude    = $data['latitude'];
$longitude   = $data['longitude'];
$location    = $data['location'];
$time_in     = $data['time_in'];
$time_out    = $data['time_out'];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO studio_owners (Name, Email, Phone, Password, V_StatsID) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$name, $email, $phone, $password]);

    $owner_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO studios (OwnerID, StudioName, Latitude, Longitude, Loc_Desc, Time_IN, Time_OUT) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$owner_id, $studio_name, $latitude, $longitude, $location, $time_in, $time_out]);

    $pdo->commit();

    unset($_SESSION['pending_owner_registration']);

    $_SESSION['user_id'] = (int)$owner_id;
    $_SESSION['user_type'] = 'owner';

    echo "<script>alert('Your account has been registered. Welcome to Museek. Enjoy!'); window.location.href='/';</script>";
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail_and_redirect('Registration failed: ' . $e->getMessage(), 'owner_register.php');
}
