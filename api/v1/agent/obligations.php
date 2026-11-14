<?php
// API v1: agent/obligations.php — list payable obligations in the agent's district
// (the agent's assigned village is their home/label only; collections may cover
// any parcel in the district — docs/19 §"District scope").
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/fee.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer']); // agents are officer role + is_agent flag
$meId = (int)$me['sub'];

// Verify caller is an agent
$st = $pdo->prepare("SELECT is_agent, agent_village_id, agent_district_id, agent_role FROM users WHERE id = :id");
$st->execute([':id' => $meId]);
$agent = $st->fetch();
if (!$agent || !$agent['is_agent']) {
    json_error('Not authorized as agent', 403, 'not_agent');
}
$agentVillageId  = (int)$agent['agent_village_id'];
$agentDistrictId = (int)$agent['agent_district_id'];
$agentRole       = $agent['agent_role'] ?? 'agent';

// The collection scope is the DISTRICT: any parcel in it, any village.
// The village is the agent's home label only. When the account predates the
// district field, derive it from the village.
if ($agentDistrictId <= 0 && $agentVillageId > 0) {
    $st = $pdo->prepare("SELECT district_id FROM villages WHERE id = :vid");
    $st->execute([':vid' => $agentVillageId]);
    $agentDistrictId = (int)$st->fetchColumn();
}
if ($agentDistrictId <= 0) {
    json_error('Agent account has no district assigned', 409, 'agent_unscoped');
}

if ($method === 'GET') {
    $feeYear = isset($_GET['fee_year']) ? (int)$_GET['fee_year'] : (int)date('Y');
    $status  = (string)($_GET['status'] ?? 'UNPAID');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $limit   = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset  = ($page - 1) * $limit;

    // District-wide: every payable obligation in the agent's district.
    $where  = ['o.fee_year = :fy', 'p.district_id = :did'];
    $params = [':fy' => $feeYear, ':did' => $agentDistrictId];

    // An explicit status wins; otherwise default to what can still be collected.
    $payable = ['UNPAID', 'PARTIAL', 'PAID', 'OVERPAID', 'WAIVED', 'REFUNDED', 'DISPUTED'];
    if ($status !== '' && in_array($status, $payable, true)) {
        $where[] = 'o.status = :st';
        $params[':st'] = $status;
    } else {
        $where[] = "o.status IN ('UNPAID','PARTIAL')";
    }

    if (!empty($_GET['overdue'])) {
        $where[] = "o.status IN ('UNPAID','PARTIAL') AND o.due_date < CURRENT_DATE";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $pen      = fee_penalty_sql('o');

    // One representative owner per parcel — a co-owned parcel must not repeat
    // the same obligation once per owner row.
    $ownerJoin = "LEFT JOIN LATERAL (
        SELECT ow.full_name, ow.id_number, ow.phone
          FROM parcel_owners po
          JOIN owners ow ON ow.id = po.owner_id
         WHERE po.parcel_id = p.id AND po.role = 'owner'
         ORDER BY ow.id
         LIMIT 1
    ) own ON TRUE";

    $countStmt = $pdo->prepare("SELECT COUNT(*)
        FROM fee_obligations o
        JOIN parcels p ON p.id = o.parcel_id
        $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT o.*, p.parcel_no, p.sub_parcel_no, p.area_sqm,
            p.zone_code, p.road_category, p.title_no, p.map_ref_no,
            d.name_lo AS district_name, v.name_lo AS village_name,
            own.full_name AS owner_name, own.id_number AS owner_id_number, own.phone AS owner_phone,
            CASE WHEN o.due_date IS NOT NULL THEN (CURRENT_DATE - o.due_date) ELSE NULL END AS days_overdue,
            $pen AS penalty_live,
            (o.amount_due + $pen - o.amount_paid) AS outstanding
        FROM fee_obligations o
        JOIN parcels p ON p.id = o.parcel_id
        JOIN districts d ON d.id = p.district_id
        JOIN villages v ON v.id = p.village_id
        $ownerJoin
        $whereSql
        ORDER BY CASE WHEN o.status = 'UNPAID' THEN 0 ELSE 1 END,
                 o.due_date ASC NULLS LAST, p.parcel_no ASC
        LIMIT :lim OFFSET :off");
    $params[':lim'] = $limit;
    $params[':off'] = $offset;
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // The stored penalty is only authoritative once settled; derive it while owed.
    foreach ($rows as &$row) {
        $row['penalty'] = (float)$row['penalty_live'];
        unset($row['penalty_live']);
    }
    unset($row);

    json_ok([
        'obligations' => $rows,
        'count' => count($rows),
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'agent' => [
            'village_id' => $agentVillageId,
            'district_id' => $agentDistrictId,
            'role' => $agentRole,
        ],
    ]);
}

json_error('Method not allowed', 405, 'method_not_allowed');
