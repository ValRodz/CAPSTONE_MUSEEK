<?php
/**
 * Shared helpers for login security, OTP trust windows, and password upgrades.
 */

const LOGIN_TRUST_TABLE = 'login_trust_tokens';
const LOGIN_TRUST_HOURS = 6;

if (!function_exists('ensureLoginTrustTable')) {
    /**
     * Make sure the trust table exists. Safe to run multiple times.
     */
    function ensureLoginTrustTable(mysqli $conn): void
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_type ENUM("client","owner") NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                last_verified_at DATETIME NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                UNIQUE KEY uniq_user (user_type, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;',
            LOGIN_TRUST_TABLE
        );
        $conn->query($sql);
    }

    /**
     * Return last verified login timestamp or null.
     */
    function getLastTrustedLogin(mysqli $conn, string $userType, int $userId): ?string
    {
        ensureLoginTrustTable($conn);

        $sql = sprintf('SELECT last_verified_at FROM %s WHERE user_type = ? AND user_id = ? LIMIT 1', LOGIN_TRUST_TABLE);
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $userType, $userId);
        $stmt->execute();
        $stmt->bind_result($lastVerified);
        $stmt->fetch();
        $stmt->close();

        return $lastVerified ?: null;
    }

    /**
     * Upsert the last verified login timestamp.
     */
    function refreshTrustedLogin(mysqli $conn, string $userType, int $userId): void
    {
        ensureLoginTrustTable($conn);

        $sql = sprintf(
            'INSERT INTO %s (user_type, user_id, last_verified_at, ip_address, user_agent)
             VALUES (?, ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE last_verified_at = VALUES(last_verified_at),
                                     ip_address = VALUES(ip_address),
                                     user_agent = VALUES(user_agent)',
            LOGIN_TRUST_TABLE
        );
        $stmt = $conn->prepare($sql);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt->bind_param('siss', $userType, $userId, $ip, $agent);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Determine if another OTP verification is required.
     */
    function needsNewVerification(mysqli $conn, string $userType, int $userId): bool
    {
        $last = getLastTrustedLogin($conn, $userType, $userId);
        if (!$last) {
            return true;
        }

        $elapsedHours = (time() - strtotime($last)) / 3600;
        return $elapsedHours >= LOGIN_TRUST_HOURS;
    }

    /**
     * Determine if a password hash already exists.
     */
    function isPasswordHash(string $value): bool
    {
        return strncmp($value, '$2y$', 4) === 0 ||
               strncmp($value, '$2a$', 4) === 0 ||
               strncmp($value, '$argon2', 7) === 0;
    }

    /**
     * Compare password input against stored hash/plaintext and upgrade to hash if needed.
     *
     * @return bool True if password matches.
     */
    function verifyAndUpgradePassword(
        mysqli $conn,
        string $table,
        string $idField,
        int $id,
        string $inputPassword,
        string $storedPassword
    ): bool {
        if (isPasswordHash($storedPassword)) {
            return password_verify($inputPassword, $storedPassword);
        }

        // Legacy plain-text password comparison
        if ($inputPassword !== $storedPassword) {
            return false;
        }

        // Upgrade to hashed password for future logins
        $newHash = password_hash($inputPassword, PASSWORD_DEFAULT);
        $sql = sprintf('UPDATE %s SET Password = ? WHERE %s = ? LIMIT 1', $table, $idField);
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $newHash, $id);
        $stmt->execute();
        $stmt->close();

        return true;
    }
}
?>

