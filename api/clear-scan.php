<?php
// API: clear current scan from session
session_start();
$_SESSION['current_scan'] = null;
json_output(['success' => true]);