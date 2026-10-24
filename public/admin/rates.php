<?php
// public/admin/rates.php — legacy zone-rate editor.
// Zone rates now live in Basic Data → Zone rates (the zone × road-access matrix).
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
header('Location: basic_data.php?tab=zones');
exit;
