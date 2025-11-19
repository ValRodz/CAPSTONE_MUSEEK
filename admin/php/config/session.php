<?php

session_start();

function requireLogin() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: ../php/login.php');
        exit;
    }
}

function isLoggedIn() {
    return isset($_SESSION['admin_id']);
}

function getAdminUser() {
    if (!isset($_SESSION['admin_id'])) {
        return null;
    }
    return [
        'id' => $_SESSION['admin_id'],
        'name' => $_SESSION['admin_name'],
        'email' => $_SESSION['admin_email'],
        'role' => $_SESSION['admin_role']
    ];
}

function logout() {
    session_destroy();
    header('Location: ../php/login.php');
    exit;
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}
