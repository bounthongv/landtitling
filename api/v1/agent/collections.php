<?php
// API v1: agent/collections.php — cash collections the agent has taken but not
// yet handed over to the district. This is the selection list for a handover.
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';

$pdo    = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me     = require_auth(['officer']);
$meId   = (int)$me['sub'];

$st = $pdo->prepare("SELECT is_agent, agent_village_id FROM users WHERE id = :id");
$st->execute([':id' => $meId]);
$agent = $st->fetch();
if (!$agent || !$agent['is_agent']) {
    json_error('Not authorized as agent', 403, 'not_agent');
}

if ($method !== 'GET') {
    json_error('Method not allowed', 405, 'method_not_allowed');
}

$st = $pdo->prepare("SELECT p.id, p.reference_no, p.amount, p.status, p.collected_at,
        p.payer_name, p.payer_phone, p.fee_year,
        par.id AS parcel_id, par.parcel_no, par.sub_parcel_no, par.map_ref_no,
        d.name_lo AS district_name, v.name_lo AS village_name
    FROM payments p
    JOIN parcels par ON par.id = p.parcel_id
    JOIN districts d ON d.id = par.district_id
    JOIN villages v ON v.id = par.village_id
    WHERE p.agent_id = :aid
      AND p.payment_method = 'cash_agent'
      AND p.handover_id IS NULL
      AND p.status IN ('ISSUED','SUBMITTED')
    ORDER BY p.collected_at ASC, p.id ASC
    LIMIT 500");
$st->execute([':aid' => $meId]);
$rows = $st->fetchAll();

$total = 0.0;
foreach ($rows as $r) {
    $total += (float)$r['amount'];
}

json_ok([
    'collections' => $rows,
    'count' => count($rows),
    'amount_total' => round($total, 2),
]);
