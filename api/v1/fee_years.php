<?php
// API v1: fee years — GET list, POST create/close
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin', 'manager']);

// Fee years are admin-maintained (Basic Data is an admin screen).
if ($method !== 'GET' && ($me['role'] ?? '') !== 'admin') {
    json_error('Only admins can change fee years', 403, 'forbidden');
}

// ---- GET: list fee years with obligation counts ----
if ($method === 'GET') {
    $stmt = $pdo->query("SELECT y.*,
            COUNT(o.id) AS obligation_count,
            COALESCE(SUM(o.amount_due), 0) AS total_due,
            COALESCE(SUM(o.amount_paid), 0) AS total_paid,
            COUNT(*) FILTER (WHERE o.status IN ('UNPAID','PARTIAL')) AS unpaid_count
        FROM fee_years y
        LEFT JOIN fee_obligations o ON o.fee_year = y.year
        GROUP BY y.year, y.due_date, y.status, y.note, y.created_at
        ORDER BY y.year DESC");
    $years = $stmt->fetchAll();
    json_ok(['years' => $years, 'count' => count($years)]);
}

// ---- POST: create or update a fee year ----
if ($method === 'POST') {
    $body = read_json_body();
    $year = (int)($body['year'] ?? 0);
    if ($year < 2000 || $year > 2100) {
        json_error('year must be between 2000 and 2100', 400, 'invalid_year');
    }
    $dueDate = trim($body['due_date'] ?? '');
    $status  = trim($body['status'] ?? 'open');
    if (!in_array($status, ['draft', 'open', 'closed'], true)) {
        json_error('status must be draft, open or closed', 400, 'invalid_status');
    }
    if ($dueDate === '') {
        $dueDate = sprintf('%04d-12-31', $year);
    }

    $pdo->prepare("INSERT INTO fee_years (year, due_date, status)
        VALUES (:y, :d, :s)
        ON CONFLICT (year) DO UPDATE
          SET due_date = EXCLUDED.due_date, status = EXCLUDED.status")
        ->execute([':y' => $year, ':d' => $dueDate, ':s' => $status]);

    // Obligations carry their own due_date copy — keep unpaid ones in step with the year.
    $pdo->prepare("UPDATE fee_obligations SET due_date = :d, updated_at = now()
        WHERE fee_year = :y AND amount_paid = 0")
        ->execute([':d' => $dueDate, ':y' => $year]);

    json_ok(['year' => $year, 'due_date' => $dueDate, 'status' => $status]);
}

json_error('Method not allowed', 405, 'method_not_allowed');