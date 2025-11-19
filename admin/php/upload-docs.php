<?php
// Simple redirect shim to the actual upload page
$token = isset($_GET['token']) ? urlencode($_GET['token']) : '';
$dest = 'upload-documents.php' . ($token ? ('?token=' . $token) : '');
header('Location: ' . $dest);
exit;
