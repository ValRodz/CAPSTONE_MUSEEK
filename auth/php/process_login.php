<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header('Location: login.php');
    exit;
}

include '../../shared/config/db.php';
require_once '../../shared/config/mail_config.php';
require_once __DIR__ . '/utils/login_security.php';
require_once '../../shared/php/remember_me.php';

$email = trim($_POST['email'] ?? '');
$pass = $_POST['password'] ?? '';
$rememberMe = isset($_POST['remember_me']);

if ($email === '' || $pass === '') {
    echo "<script>
        alert('Please fill in all fields.');
        window.location.href = 'login.php';
    </script>";
    exit;
}

$sql = "SELECT ClientID, Email, Password, Name, V_StatsID FROM clients WHERE Email = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$client = $result->fetch_assoc();
$stmt->close();

$owner = null;
if (!$client) {
    $sql = "SELECT OwnerID, Email, Password, Name, last_login FROM studio_owners WHERE Email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $owner = $result->fetch_assoc();
    $stmt->close();
}

function startOtpFlow(array $user, string $email, bool $rememberMe): void
{
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = time() + (60 * 10);
    $_SESSION['pending_login'] = [
        'user_id'    => $user['id'],
        'user_type'  => $user['type'],
        'email'      => $email,
        'name'       => $user['name'],
        'otp'        => $otp,
        'expires_at' => $expiresAt,
        'remember_me'=> $rememberMe,
    ];

    if (!sendVerificationEmail($email, $user['name'] ?: $email, $otp, $expiresAt)) {
        unset($_SESSION['pending_login']);
        echo "<script>
            alert('Unable to send verification email. Please try again.');
            window.location.href = 'login.php';
        </script>";
        exit;
    }

    echo "<script>
        alert('Check your email for a verification code to complete login.');
        window.location.href = 'verify_code.php';
    </script>";
    exit;
}

if ($client && verifyAndUpgradePassword($conn, 'clients', 'ClientID', (int)$client['ClientID'], $pass, $client['Password'])) {
    if ((int)$client['V_StatsID'] === 3) {
        echo "<script>
            alert('Your account has been deactivated. Please contact support to reactivate it.');
            window.location.href = 'login.php';
        </script>";
        exit;
    }

    if (!needsNewVerification($conn, 'client', (int)$client['ClientID'])) {
        refreshTrustedLogin($conn, 'client', (int)$client['ClientID']);
        $_SESSION['user_id'] = (int)$client['ClientID'];
        $_SESSION['user_type'] = 'client';
        if ($rememberMe) {
            issueRememberMeToken($conn, 'client', (int)$client['ClientID']);
        } else {
            clearRememberMeCookie($conn);
        }
        echo "<script>
            alert('Login Successful! Welcome to Museek');
            window.location.href = '../../';
        </script>";
        exit;
    }

    startOtpFlow(
        ['id' => (int)$client['ClientID'], 'type' => 'client', 'name' => $client['Name'] ?? $email],
        $email,
        $rememberMe
    );
} elseif ($owner && verifyAndUpgradePassword($conn, 'studio_owners', 'OwnerID', (int)$owner['OwnerID'], $pass, $owner['Password'])) {
    if (!needsNewVerification($conn, 'owner', (int)$owner['OwnerID'])) {
        refreshTrustedLogin($conn, 'owner', (int)$owner['OwnerID']);
        $_SESSION['user_id'] = (int)$owner['OwnerID'];
        $_SESSION['user_type'] = 'owner';
        $updateStmt = $conn->prepare("UPDATE studio_owners SET last_login = NOW() WHERE OwnerID = ?");
        $updateStmt->bind_param('i', $owner['OwnerID']);
        $updateStmt->execute();
        $updateStmt->close();
        if ($rememberMe) {
            issueRememberMeToken($conn, 'owner', (int)$owner['OwnerID']);
        } else {
            clearRememberMeCookie($conn);
        }
        echo "<script>
            alert('Login Successful! Welcome to Museek');
            window.location.href = '../../owners/php/dashboard.php';
        </script>";
        exit;
    }

    startOtpFlow(
        ['id' => (int)$owner['OwnerID'], 'type' => 'owner', 'name' => $owner['Name'] ?? $email],
        $email,
        $rememberMe
    );
} else {
    echo "<script>
        alert('Invalid email or password. Please try again.');
        window.location.href = 'login.php';
    </script>";
    exit;
}
?>
