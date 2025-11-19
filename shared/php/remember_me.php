<?php
if (!defined('REMEMBER_SELECTOR_COOKIE')) {
    define('REMEMBER_SELECTOR_COOKIE', 'museek_rsel');
    define('REMEMBER_VALIDATOR_COOKIE', 'museek_rval');
    define('REMEMBER_COOKIE_TTL', 60 * 60 * 24 * 30); // 30 days
}

if (!function_exists('ensureRememberMeTable')) {
    function ensureRememberMeTable(mysqli $conn): void
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS remember_me_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                selector CHAR(16) NOT NULL UNIQUE,
                validator_hash CHAR(64) NOT NULL,
                user_type ENUM('client','owner') NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_type, user_id),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
        );
    }

    function rememberMeSetCookie(string $name, string $value, int $expires): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    function clearRememberMeCookie(?mysqli $conn = null): void
    {
        $selector = $_COOKIE[REMEMBER_SELECTOR_COOKIE] ?? null;
        if ($conn instanceof mysqli && $selector) {
            ensureRememberMeTable($conn);
            $stmt = $conn->prepare("DELETE FROM remember_me_tokens WHERE selector = ?");
            if ($stmt) {
                $stmt->bind_param('s', $selector);
                $stmt->execute();
                $stmt->close();
            }
        }

        rememberMeSetCookie(REMEMBER_SELECTOR_COOKIE, '', time() - 3600);
        rememberMeSetCookie(REMEMBER_VALIDATOR_COOKIE, '', time() - 3600);
        unset($_COOKIE[REMEMBER_SELECTOR_COOKIE], $_COOKIE[REMEMBER_VALIDATOR_COOKIE]);
    }

    function issueRememberMeToken(mysqli $conn, string $userType, int $userId): void
    {
        ensureRememberMeTable($conn);

        $selector  = bin2hex(random_bytes(8));
        $validator = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $validator);
        $expiresTs = time() + REMEMBER_COOKIE_TTL;
        $expiresAt = date('Y-m-d H:i:s', $expiresTs);

        $stmt = $conn->prepare("INSERT INTO remember_me_tokens (selector, validator_hash, user_type, user_id, expires_at) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('sssis', $selector, $hash, $userType, $userId, $expiresAt);
            $stmt->execute();
            $stmt->close();
        }

        rememberMeSetCookie(REMEMBER_SELECTOR_COOKIE, $selector, $expiresTs);
        rememberMeSetCookie(REMEMBER_VALIDATOR_COOKIE, $validator, $expiresTs);
    }

    function attemptRememberedLogin(): void
    {
        if (isset($_SESSION['user_id'], $_SESSION['user_type'])) {
            return;
        }

        $selector  = $_COOKIE[REMEMBER_SELECTOR_COOKIE] ?? null;
        $validator = $_COOKIE[REMEMBER_VALIDATOR_COOKIE] ?? null;
        if (!$selector || !$validator) {
            return;
        }

        $hasGlobalConn = isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli;
        if (!$hasGlobalConn) {
            require __DIR__ . '/../config/db.php';
        }
        /** @var mysqli $conn */
        $conn = $GLOBALS['conn'];
        if (!$conn instanceof mysqli || $conn->connect_errno) {
            return;
        }

        ensureRememberMeTable($conn);
        $stmt = $conn->prepare("SELECT validator_hash, user_type, user_id, expires_at FROM remember_me_tokens WHERE selector = ? LIMIT 1");
        if (!$stmt) {
            if (!$hasGlobalConn) {
                $conn->close();
                unset($GLOBALS['conn']);
            }
            return;
        }
        $stmt->bind_param('s', $selector);
        $stmt->execute();
        $result = $stmt->get_result();
        $token  = $result->fetch_assoc();
        $stmt->close();

        if (!$token) {
            clearRememberMeCookie($conn);
            if (!$hasGlobalConn) {
                $conn->close();
                unset($GLOBALS['conn']);
            }
            return;
        }

        if ($token['expires_at'] <= date('Y-m-d H:i:s')) {
            clearRememberMeCookie($conn);
            if (!$hasGlobalConn) {
                $conn->close();
                unset($GLOBALS['conn']);
            }
            return;
        }

        if (!hash_equals($token['validator_hash'], hash('sha256', $validator))) {
            clearRememberMeCookie($conn);
            if (!$hasGlobalConn) {
                $conn->close();
                unset($GLOBALS['conn']);
            }
            return;
        }

        $_SESSION['user_id']   = (int)$token['user_id'];
        $_SESSION['user_type'] = $token['user_type'];

        // Rotate token
        $del = $conn->prepare("DELETE FROM remember_me_tokens WHERE selector = ?");
        if ($del) {
            $del->bind_param('s', $selector);
            $del->execute();
            $del->close();
        }
        issueRememberMeToken($conn, $token['user_type'], (int)$token['user_id']);

        if (!$hasGlobalConn) {
            $conn->close();
            unset($GLOBALS['conn']);
        }
    }
}

attemptRememberedLogin();

