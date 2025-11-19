<?php
/**
 * Comprehensive Validation Utilities for MuSeek Studio Management
 * Netflix-inspired UI with strict validation rules
 */

class ValidationUtils {
    
    /**
     * Validate name field (letters, spaces, hyphens, apostrophes only)
     */
    public static function validateName($name, $minLength = 2, $maxLength = 50) {
        $errors = [];
        
        if (empty($name)) {
            $errors[] = "Name is required.";
            return $errors;
        }
        
        // Check length
        if (strlen($name) < $minLength) {
            $errors[] = "Name must be at least {$minLength} characters long.";
        }
        
        if (strlen($name) > $maxLength) {
            $errors[] = "Name must not exceed {$maxLength} characters.";
        }
        
        // Check for valid characters (letters, spaces, hyphens, apostrophes)
        if (!preg_match('/^[a-zA-Z\s\-\']+$/', $name)) {
            $errors[] = "Name can only contain letters, spaces, hyphens, and apostrophes.";
        }
        
        // Check for consecutive special characters
        if (preg_match('/[\s\-\']{2,}/', $name)) {
            $errors[] = "Name cannot contain consecutive spaces, hyphens, or apostrophes.";
        }
        
        // Check for leading/trailing special characters
        if (preg_match('/^[\s\-\']|[\s\-\']$/', $name)) {
            $errors[] = "Name cannot start or end with spaces, hyphens, or apostrophes.";
        }
        
        return $errors;
    }
    
    /**
     * Validate email address
     */
    public static function validateEmail($email) {
        $errors = [];
        
        if (empty($email)) {
            $errors[] = "Email is required.";
            return $errors;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address.";
        }
        
        if (strlen($email) > 100) {
            $errors[] = "Email must not exceed 100 characters.";
        }
        
        return $errors;
    }
    
    /**
     * Validate phone number (Philippines format)
     */
    public static function validatePhone($phone) {
        $errors = [];
        
        if (empty($phone)) {
            $errors[] = "Phone number is required.";
            return $errors;
        }
        
        // Remove all non-digit characters
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        
        // Check if it's a valid Philippine mobile number
        if (!preg_match('/^(09|639)[0-9]{9}$/', $cleanPhone)) {
            $errors[] = "Please enter a valid Philippine mobile number (09xxxxxxxxx or +639xxxxxxxxx).";
        }
        
        return $errors;
    }
    
    /**
     * Validate password
     */
    public static function validatePassword($password, $minLength = 8) {
        $errors = [];
        
        if (empty($password)) {
            $errors[] = "Password is required.";
            return $errors;
        }
        
        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long.";
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter.";
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter.";
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number.";
        }
        
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character.";
        }
        
        return $errors;
    }
    
    /**
     * Validate studio name
     */
    public static function validateStudioName($name) {
        $errors = [];
        
        if (empty($name)) {
            $errors[] = "Studio name is required.";
            return $errors;
        }
        
        if (strlen($name) < 3) {
            $errors[] = "Studio name must be at least 3 characters long.";
        }
        
        if (strlen($name) > 100) {
            $errors[] = "Studio name must not exceed 100 characters.";
        }
        
        // Allow letters, numbers, spaces, and common business characters
        if (!preg_match('/^[a-zA-Z0-9\s\-\'&.,()]+$/', $name)) {
            $errors[] = "Studio name contains invalid characters.";
        }
        
        return $errors;
    }
    
    /**
     * Validate location/address
     */
    public static function validateLocation($location) {
        $errors = [];
        
        if (empty($location)) {
            $errors[] = "Location is required.";
            return $errors;
        }
        
        if (strlen($location) < 5) {
            $errors[] = "Location must be at least 5 characters long.";
        }
        
        if (strlen($location) > 200) {
            $errors[] = "Location must not exceed 200 characters.";
        }
        
        return $errors;
    }
    
    /**
     * Validate service name
     */
    public static function validateServiceName($name) {
        $errors = [];
        
        if (empty($name)) {
            $errors[] = "Service name is required.";
            return $errors;
        }
        
        if (strlen($name) < 2) {
            $errors[] = "Service name must be at least 2 characters long.";
        }
        
        if (strlen($name) > 50) {
            $errors[] = "Service name must not exceed 50 characters.";
        }
        
        if (!preg_match('/^[a-zA-Z0-9\s\-\'&]+$/', $name)) {
            $errors[] = "Service name contains invalid characters.";
        }
        
        return $errors;
    }
    
    /**
     * Validate price
     */
    public static function validatePrice($price) {
        $errors = [];
        
        if (empty($price)) {
            $errors[] = "Price is required.";
            return $errors;
        }
        
        if (!is_numeric($price)) {
            $errors[] = "Price must be a valid number.";
            return $errors;
        }
        
        $price = (float)$price;
        
        if ($price < 0) {
            $errors[] = "Price cannot be negative.";
        }
        
        if ($price > 999999.99) {
            $errors[] = "Price cannot exceed ₱999,999.99.";
        }
        
        return $errors;
    }
    
    /**
     * Validate time format
     */
    public static function validateTime($time) {
        $errors = [];
        
        if (empty($time)) {
            $errors[] = "Time is required.";
            return $errors;
        }
        
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            $errors[] = "Please enter a valid time format (HH:MM).";
        }
        
        return $errors;
    }
    
    /**
     * Validate date
     */
    public static function validateDate($date) {
        $errors = [];
        
        if (empty($date)) {
            $errors[] = "Date is required.";
            return $errors;
        }
        
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        
        if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
            $errors[] = "Please enter a valid date format (YYYY-MM-DD).";
            return $errors;
        }
        
        // Check if date is not in the past
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        
        if ($dateObj < $today) {
            $errors[] = "Date cannot be in the past.";
        }
        
        return $errors;
    }
    
    /**
     * Validate description
     */
    public static function validateDescription($description, $maxLength = 500) {
        $errors = [];
        
        if (strlen($description) > $maxLength) {
            $errors[] = "Description must not exceed {$maxLength} characters.";
        }
        
        return $errors;
    }
    
    /**
     * Sanitize input
     */
    public static function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
        }
        // Trim whitespace and remove null bytes; do NOT HTML-escape here
        $value = trim((string)$input);
        $value = str_replace("\0", '', $value);
        // Optionally strip tags to avoid embedding HTML in DB
        $value = strip_tags($value);
        return $value;
    }
    
    /**
     * Format validation errors for display
     */
    public static function formatErrors($errors) {
        if (empty($errors)) {
            return '';
        }

        // Flatten nested error arrays into a single list of messages
        $messages = [];
        foreach ($errors as $field => $error) {
            if (is_array($error)) {
                foreach ($error as $msg) {
                    if (is_string($msg) && $msg !== '') {
                        $messages[] = $msg;
                    }
                }
            } elseif (is_string($error) && $error !== '') {
                $messages[] = $error;
            }
        }

        if (empty($messages)) {
            return '';
        }
        
        $html = '<div class="validation-errors">';
        $html .= '<ul>';
        foreach ($messages as $msg) {
            $html .= '<li>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ul>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Validate form data based on rules
     */
    public static function validateForm($data, $rules) {
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? '';
            
            // Required validation
            if (isset($rule['required']) && $rule['required'] && empty($value)) {
                $errors[$field] = [ucfirst($field) . ' is required.'];
                continue;
            }
            
            // Skip other validations if field is empty and not required
            if (empty($value) && !isset($rule['required'])) {
                continue;
            }
            
            // Apply specific validation based on type
            switch ($rule['type']) {
                case 'name':
                    $fieldErrors = self::validateName($value, $rule['min_length'] ?? 2, $rule['max_length'] ?? 50);
                    break;
                case 'email':
                    $fieldErrors = self::validateEmail($value);
                    break;
                case 'phone':
                    $fieldErrors = self::validatePhone($value);
                    break;
                case 'password':
                    $fieldErrors = self::validatePassword($value, $rule['min_length'] ?? 8);
                    break;
                case 'studio_name':
                    $fieldErrors = self::validateStudioName($value);
                    break;
                case 'location':
                    $fieldErrors = self::validateLocation($value);
                    break;
                case 'service_name':
                    $fieldErrors = self::validateServiceName($value);
                    break;
                case 'price':
                    $fieldErrors = self::validatePrice($value);
                    break;
                case 'time':
                    $fieldErrors = self::validateTime($value);
                    break;
                case 'date':
                    $fieldErrors = self::validateDate($value);
                    break;
                case 'description':
                    $fieldErrors = self::validateDescription($value, $rule['max_length'] ?? 500);
                    break;
                default:
                    $fieldErrors = [];
            }
            
            if (!empty($fieldErrors)) {
                $errors[$field] = $fieldErrors;
            }
        }
        
        return $errors;
    }
}

// CSS for validation errors
function getValidationCSS() {
    return '
    <style>
    .validation-errors {
        background: rgba(255, 107, 107, 0.1);
        border: 1px solid #ff6b6b;
        border-radius: 8px;
        padding: 12px 16px;
        margin: 10px 0;
        color: #ff6b6b;
    }
    
    .validation-errors ul {
        margin: 0;
        padding-left: 20px;
    }
    
    .validation-errors li {
        margin-bottom: 4px;
        font-size: 14px;
    }
    
    .form-control.error {
        border-color: #ff6b6b;
        box-shadow: 0 0 0 2px rgba(255, 107, 107, 0.2);
    }
    
    .form-control.success {
        border-color: #46d369;
        box-shadow: 0 0 0 2px rgba(70, 211, 105, 0.2);
    }
    
    .field-error {
        color: #ff6b6b;
        font-size: 12px;
        margin-top: 4px;
        display: flex;
        align-items: center;
    }
    
    .field-error i {
        margin-right: 4px;
    }
    
    .field-success {
        color: #46d369;
        font-size: 12px;
        margin-top: 4px;
        display: flex;
        align-items: center;
    }
    
    .field-success i {
        margin-right: 4px;
    }
    </style>';
}
?>
