<?php
// public/admin/lang.php — switch the back-office UI language (lo = Lao default).
// Sets a long-lived cookie and redirects back to the calling admin page.
$lang = $_GET['lang'] ?? 'lo';
if (!in_array($lang, ['lo', 'en'], true)) { $lang = 'lo'; }

setcookie('lang', $lang, [
    'expires'  => time() + 31536000,
    'path'     => '/',
    'samesite' => 'Lax',
]);

$next = (string)($_GET['next'] ?? 'index.php');
if ($next === '' || $next[0] === '/' || str_contains($next, '..')
    || !preg_match('#^[A-Za-z0-9_.\-/?=&%\#]+$#', $next)) {
    $next = 'index.php';
}

header('Location: ' . $next);
exit;
