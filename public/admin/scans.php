<?php
// public/admin/scans.php — the scan → parcel workflow now lives in Parcel
// Entry's Scan tab. Keep this URL as a redirect so old bookmarks and links
// still land on the one confirm path.
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }

$qs = 'tab=scan';
if (!empty($_GET['scan_id'])) $qs .= '&scan_id=' . (int)$_GET['scan_id'];
header('Location: parcel_entry.php?' . $qs);
exit;
