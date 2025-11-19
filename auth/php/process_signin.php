<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header('Location: signin.php');
    exit;
}

include '../../shared/config/db.php';
require_once '../../shared/config/mail_config.php';

function respond_with_error(string $message, array $oldValues = []): void
{
    $_SESSION['signup_error'] = $message;
    $_SESSION['signup_old'] = $oldValues;
    header('Location: signin.php');
    exit;
}

$name  = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';
$confirm_pass = $_POST['confirm_password'] ?? '';
$terms = isset($_POST['terms']);

$sticky = [
    'name'  => $name,
    'phone' => $phone,
    'email' => $email,
];

if (!$terms) {
    respond_with_error('You must agree to the Terms of Service.', $sticky);
}

if ($name === '' || $phone === '' || $email === '' || $pass === '' || $confirm_pass === '') {
    respond_with_error('Please fill in all required fields.', $sticky);
}

if (mb_strlen($name) > 100 || !preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ'.\- ]+$/u", $name)) {
    respond_with_error('Please provide a valid full name (letters and basic punctuation only).', $sticky);
}

$phone_digits = preg_replace('/\D/', '', $phone);
if (!preg_match('/^63[0-9]{10}$/', $phone_digits)) {
    respond_with_error('Invalid phone number format. Please use +63 9xx xxx xxxx.', $sticky);
}
$formatted_phone = sprintf('+%s %s %s %s',
    substr($phone_digits, 0, 2),
    substr($phone_digits, 2, 3),
    substr($phone_digits, 5, 3),
    substr($phone_digits, 8, 4)
);
$sticky['phone'] = $formatted_phone;

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
    respond_with_error('A valid email address is required.', $sticky);
}
$email = strtolower($email);
$sticky['email'] = $email;

if (strlen($pass) < 8 ||
    !preg_match('/[A-Z]/', $pass) ||
    !preg_match('/[a-z]/', $pass) ||
    !preg_match('/[0-9]/', $pass) ||
    !preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>\/?\\\\|`~]/', $pass)
) {
    respond_with_error('Password must include uppercase, lowercase, number, special character, and have at least 8 characters.', $sticky);
}

if ($pass !== $confirm_pass) {
    respond_with_error('Passwords do not match.', $sticky);
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM clients WHERE Email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

if ($count > 0) {
    respond_with_error('This email is already registered.', $sticky);
}

$otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = time() + 3600; // 60 minutes
$passwordHash = password_hash($pass, PASSWORD_DEFAULT);

$_SESSION['pending_registration'] = [
    'name'          => $name,
    'phone'         => $formatted_phone,
    'email'         => $email,
    'password_hash' => $passwordHash,
    'otp'           => $otp,
    'expires_at'    => $expiresAt,
];

if (!sendVerificationEmail($email, $name, $otp, $expiresAt, 'registration')) {
    respond_with_error('Unable to send verification email. Please try again in a few minutes.', $sticky);
}

unset($_SESSION['signup_old'], $_SESSION['signup_error']);

header('Location: verify_email.php?mode=registration');
exit;
?>
