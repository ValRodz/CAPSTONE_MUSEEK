<?php
/**
 * Reusable URL helpers so outbound links aren't tied to localhost.
 */

if (!function_exists('getAppBaseUrl')) {
    /**
     * Determine the canonical base URL for the current request.
     * Priority:
     *  1. shared/config/app.php -> ['app_url' => 'https://domain.tld']
     *  2. APP_URL env variable (useful for production/staging)
     *  3. Derived from current HTTP request (scheme + host + optional port + project subdirectory)
     *
     * @return string Base URL without trailing slash.
     */
    function getAppBaseUrl(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $configPath = __DIR__ . '/../config/app.php';
        if (is_file($configPath)) {
            $config = include $configPath;
            if (is_array($config) && !empty($config['app_url'])) {
                $cached = rtrim($config['app_url'], '/');
                return $cached;
            }
        }

        $envUrl = getenv('APP_URL');
        if (!empty($envUrl)) {
            $cached = rtrim($envUrl, '/');
            return $cached;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $subPath = '';
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!empty($docRoot)) {
            $docRootReal = str_replace('\\', '/', realpath($docRoot));
            $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../..'));
            if ($docRootReal && $projectRoot && strncmp($projectRoot, $docRootReal, strlen($docRootReal)) === 0) {
                $subPath = trim(substr($projectRoot, strlen($docRootReal)), '/');
            }
        }

        $basePath = $subPath !== '' ? '/' . $subPath : '';

        $cached = $scheme . '://' . $host . $basePath;
        return $cached;
    }

    /**
     * Build an absolute URL from a relative path using the detected base URL.
     *
     * @param string $path Relative path such as /auth/php/verify_email.php
     * @return string Absolute URL.
     */
    function buildAbsoluteUrl(string $path): string
    {
        $normalizedPath = '/' . ltrim($path, '/');
        return getAppBaseUrl() . $normalizedPath;
    }
}
?>

