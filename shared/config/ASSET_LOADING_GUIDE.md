# MuSeek Asset Loading System Documentation

## Overview

The MuSeek application now uses a standardized asset loading system that automatically calculates the correct paths to CSS, JavaScript, images, and other assets regardless of the directory level of the current page.

## Key Components

### 1. Path Configuration File
**Location:** `shared/config/path_config.php`

This file contains:
- Automatic base path detection based on current directory
- Helper functions for different asset types
- Predefined path constants for common directories

### 2. Directory Structure Support

The system automatically detects if a page is located in:
- Root directory (e.g., `Home.php`)
- Subdirectories: `/client/php`, `/booking/php`, `/admin/php`, `/auth/php`, `/payment/php`, `/messaging/php`, `/instructor/php`

## Usage Guide

### Including the Path Configuration

Add this line to the top of any PHP file that needs asset loading:

```php
include 'path/to/shared/config/path_config.php';
```

**Examples:**
- For root files: `include 'shared/config/path_config.php';`
- For subdirectory files: `include '../../shared/config/path_config.php';`

### Available Helper Functions

#### 1. `getAssetPath($type, $filename)`
General function for any asset type.

```php
// CSS files
echo getAssetPath('css', 'style.css');

// JavaScript files  
echo getAssetPath('js', 'app.js');

// Images
echo getAssetPath('images', 'logo.png');

// Fonts
echo getAssetPath('fonts', 'font-awesome.min.css');
```

#### 2. Specific Helper Functions

```php
// CSS files
echo getCSSPath('style.css');

// JavaScript files
echo getJSPath('app.js');

// Images
echo getImagePath('logo.png');
```

#### 3. Path Constants

Access predefined paths using the `$paths` array:

```php
// Home page link
echo $paths['home'];

// Base path for relative links
echo $base_path;

// Asset directories
echo $paths['css'];
echo $paths['js'];
echo $paths['images'];
```

## Implementation Examples

### HTML Head Section

```php
<!DOCTYPE html>
<html>
<head>
    <title>Page Title</title>
    <link href="<?php echo getCSSPath('style.css'); ?>" rel="stylesheet">
    <link href="<?php echo getAssetPath('fonts', 'font-awesome.min.css'); ?>" rel="stylesheet">
</head>
```

### JavaScript Includes

```php
<script src="<?php echo getJSPath('jquery-1.11.1.min.js'); ?>"></script>
<script src="<?php echo getJSPath('plugins.js'); ?>"></script>
<script src="<?php echo getJSPath('app.js'); ?>"></script>
```

### Image References

```php
<!-- Logo in navigation -->
<img src="<?php echo getImagePath('images/logo4.png'); ?>" alt="Site Logo">

<!-- Background images -->
<div data-background="<?php echo getImagePath('dummy/slide-1.jpg'); ?>">

<!-- Studio images -->
<img src="<?php echo getImagePath('default_studio.jpg'); ?>" alt="Studio">
```

### Navigation Links

```php
<!-- Home page link -->
<a href="<?php echo $paths['home']; ?>">Home</a>

<!-- Other page links -->
<a href="<?php echo $base_path; ?>client/php/browse.php">Browse Studios</a>
<a href="<?php echo $base_path; ?>auth/php/login.html">Login</a>
```

## Migration from Old System

### Before (Manual Path Calculation)
```php
// Old inconsistent approach
<link href="../../shared/assets/css/style.css" rel="stylesheet">
<img src="<?php echo isset($base_path) ? $base_path : '../'; ?>shared/assets/images/logo.png">
```

### After (Standardized System)
```php
// New standardized approach
<link href="<?php echo getCSSPath('style.css'); ?>" rel="stylesheet">
<img src="<?php echo getImagePath('logo.png'); ?>">
```

## Benefits

1. **Consistency:** All pages use the same asset loading approach
2. **Maintainability:** Changes to directory structure require updates in one place
3. **Reliability:** Automatic path calculation prevents broken links
4. **Developer Experience:** Simple, intuitive helper functions
5. **Scalability:** Easy to add new asset types or directories

## Troubleshooting

### Common Issues

1. **Missing Assets:** Ensure the path configuration file is included
2. **Incorrect Paths:** Verify the asset exists in the expected directory
3. **Function Not Found:** Check that `path_config.php` is properly included

### Debugging

Use these techniques to debug path issues:

```php
// Display the calculated path
echo "CSS Path: " . getCSSPath('style.css') . "<br>";
echo "Base Path: " . $base_path . "<br>";
echo "Current Directory: " . dirname($_SERVER['SCRIPT_NAME']) . "<br>";
```

## File Structure

```
museek/
├── shared/
│   ├── config/
│   │   ├── path_config.php          # Main configuration file
│   │   └── ASSET_LOADING_GUIDE.md   # This documentation
│   ├── assets/
│   │   ├── css/                     # Stylesheets
│   │   ├── js/                      # JavaScript files
│   │   ├── images/                  # Images and graphics
│   │   └── fonts/                   # Font files
│   └── components/
│       ├── navbar.php               # Updated with new system
│       └── footer.php               # Updated with new system
├── Home.php                         # Root level page
└── [subdirectories]/
    └── php/
        └── *.php                    # Subdirectory pages
```

## Best Practices

1. Always include `path_config.php` at the top of PHP files
2. Use specific helper functions (`getCSSPath`, `getJSPath`, etc.) when possible
3. Use `$base_path` for internal page navigation
4. Test asset loading from different directory levels
5. Keep asset organization consistent within the `shared/assets/` structure

---

*Last updated: January 2025*
*Version: 1.0*