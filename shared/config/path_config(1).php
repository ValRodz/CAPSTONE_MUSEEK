<?php
/**
 * Path Configuration for MuSeek Application
 * This file provides standardized path calculation for all pages
 */

// Prevent multiple inclusions
if (!function_exists('getBasePath')) {

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get the current script's directory
$current_script_dir = dirname($_SERVER['SCRIPT_NAME']);

// Define the base path to the museek root directory
function getBasePath() {
    $current_dir = dirname($_SERVER['SCRIPT_NAME']);
    
    // Check if we're in a subdirectory that needs ../../ to reach root
    $subdirectories = [
        '/client/php',
        '/booking/php', 
        '/admin/php',
        '/auth/php',
        '/payment/php',
        '/messaging/php',
        '/instructor/php'
    ];
    
    foreach ($subdirectories as $subdir) {
        if (strpos($current_dir, $subdir) !== false) {
            return '../../';
        }
    }
    
    // If we're in the root or shared directory
    return '';
}

// Set the base path
$base_path = getBasePath();

// Define common asset paths
$paths = [
    'base' => $base_path,
    'assets' => $base_path . 'shared/assets/',
    'css' => $base_path . 'shared/css/',
    'js' => $base_path . 'shared/js/',
    'images' => $base_path . 'shared/assets/images/',
    'fonts' => $base_path . 'shared/assets/fonts/',
    'components' => $base_path . 'shared/components/',
    'config' => $base_path . 'shared/config/',
    'home' => $base_path . 'Home.php',
    'dummy' => $base_path . 'shared/assets/images/dummy/'
];

// Helper function to get asset path
function getAssetPath($type, $filename = '') {
    global $paths;
    return isset($paths[$type]) ? $paths[$type] . $filename : $filename;
}

// Helper function to get image path
function getImagePath($filename) {
    return getAssetPath('images', $filename);
}

// Helper function to get CSS path
function getCSSPath($filename) {
    return getAssetPath('css', $filename);
}

// Helper function to get JS path
function getJSPath($filename) {
    return getAssetPath('js', $filename);
}

// Helper function to get fonts path
function getFontsPath($filename) {
    return getAssetPath('fonts', $filename);
}

// Helper function to get dummy images path
function getDummyPath($filename) {
    return getAssetPath('dummy', $filename);
}

require_once __DIR__ . '/../php/remember_me.php';

} // End of include guard
?>