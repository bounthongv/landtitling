<?php
// public/admin/reports_export.php — streams a report as CSV through the API.
// The API requires a Bearer token, which a plain <a href> cannot send, so the officer
// browser hits this page instead; it authenticates server-side with the session token
// and re-emits the CSV body with correct headers (this page outputs ONLY the CSV).
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }

$params = $_GET;
unset($params['format']);
// The picker, not the two date boxes, decides the interval, and the report API
// understands only from/to — so resolve it here exactly as the screen and the
// printed document do, or a CSV of quarter 2 could come out unfiltered.
$period = report_period($params);
if ($period['from'] !== '') { $params['from'] = $period['from']; } else { unset($params['from']); }
if ($period['to']   !== '') { $params['to']   = $period['to'];   } else { unset($params['to']); }
$params['format'] = 'csv';
$qs = http_build_query($params);

$resp = api_fetch_raw('GET', 'reports.php?' . $qs, $_SESSION['admin_token']);
if ($resp['code'] !== 200 || $resp['body'] === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo t('Report failed') . ' (HTTP ' . $resp['code'] . ')';
    exit;
}

$report = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['report'] ?? 'report'));
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="landfee-' . $report . '.csv"');
header('Content-Length: ' . strlen($resp['body']));
echo $resp['body'];
exit;