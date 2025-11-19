<?php
/**
 * Global path definitions for the MuSeek application
 * Include this file whenever you need to reference assets or other resources
 */

// Base paths
define('BASE_PATH', realpath(__DIR__ . '/../../..'));
define('MUSEEK_PATH', BASE_PATH . '/museek');

// Asset paths
define('ASSETS_PATH', MUSEEK_PATH . '/shared/assets');
define('IMAGES_PATH', ASSETS_PATH . '/images');

// URL paths (for browser access)
define('BASE_URL', '/Capstone_Backup');
define('MUSEEK_URL', BASE_URL . '/museek');
define('ASSETS_URL', MUSEEK_URL . '/shared/assets');
define('IMAGES_URL', ASSETS_URL . '/images');

/**
 * Helper function to get the absolute file path for an asset
 * @param string $relativePath - Path relative to the assets directory
 * @return string - Full server path to the asset
 */
function getAssetPath($relativePath) {
    return ASSETS_PATH . '/' . ltrim($relativePath, '/');
}

/**
 * Helper function to get the URL for an asset
 * @param string $relativePath - Path relative to the assets directory
 * @return string - Full URL to the asset
 */
function getAssetUrl($relativePath) {
    return ASSETS_URL . '/' . ltrim($relativePath, '/');
}

/**
 * Helper function to get the absolute file path for an image
 * @param string $imageName - Image filename
 * @return string - Full server path to the image
 */
function getImagePath($imageName) {
    return IMAGES_PATH . '/' . ltrim($imageName, '/');
}

/**
 * Helper function to get the URL for an image
 * @param string $imageName - Image filename
 * @return string - Full URL to the image
 */
function getImageUrl($imageName) {
    return IMAGES_URL . '/' . ltrim($imageName, '/');
}

/**
 * Helper function to get base64 encoded image data
 * @param string $imageName - Image filename
 * @return string - Base64 encoded image data
 */
function getBase64Image($imageName) {
    $imagePath = getImagePath($imageName);
    if (file_exists($imagePath)) {
        return base64_encode(file_get_contents($imagePath));
    }
    return '';
}
?>