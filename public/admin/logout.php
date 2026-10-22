<?php
// public/admin/logout.php — log out and redirect to login
session_start();
session_destroy();
header('Location: login.php');
exit;
