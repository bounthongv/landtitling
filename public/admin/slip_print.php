<?php
// public/admin/slip_print.php — server-side proxy for the printable slip/receipt.
// The JSON API authenticates via the Authorization header only (no cookie), so
// opening payments.php?id=X&slip=1 in a browser tab 401s. This page adds the
// session token server-side and echoes the rendered HTML for printing.
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$id = (int)($_GET['id'] ?? 0);
$receipt = isset($_GET['receipt']);
if (!$id) { http_response_code(400); exit('missing id'); }

$url = $api_base . '/payments.php?id=' . $id . ($receipt ? '&receipt=1' : '&slip=1');
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $_SESSION['admin_token']]);
$html = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($code);
header('Content-Type: text/html; charset=UTF-8');
echo $html !== false ? $html : 'print failed';
