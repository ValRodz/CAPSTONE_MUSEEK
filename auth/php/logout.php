<?php
session_start();
require_once '../../shared/config/db.php';
require_once '../../shared/php/remember_me.php';
clearRememberMeCookie($conn ?? null);
session_unset();
session_destroy();
header("Location: ../../");
exit;
?>
