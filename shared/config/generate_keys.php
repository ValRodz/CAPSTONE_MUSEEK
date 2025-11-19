<?php
require_once __DIR__ . '/vendor/autoload.php'; // Adjust path if needed

use Minishlink\WebPush\VAPID;

// Generate new VAPID key pair
$keys = VAPID::createVapidKeys();
echo '<pre>';
print_r($keys);
echo '</pre>';
?>
