<?php
// API v1: citizen_claim.php — attach a parcel to the signed-in citizen's account.
//
// The citizen looks a parcel up by province → district → village → map sheet →
// parcel number (parcel_fee.php). Looking does not link anything; this endpoint
// is the explicit "add to my parcels" step. It links the account through
// owners.citizen_user_id, the column my_parcels.php filters on — the parcel
// record itself is untouched.
//
// Guard rails:
//  - a parcel already linked to another citizen is refused (the district can
//    unlink it in the back-office first);
//  - when the owner record carries a phone number, it must be the citizen's own
//    number, so knowledge of the certificate alone does not take over an
//    account link;
//  - every claim and refusal is audited.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/fee.php';
require_once __DIR__ . '/../../lib/phone.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405, 'method_not_allowed');
}

$pdo = db_connect();
$me  = require_auth(['citizen']);
$meId = (int)$me['sub'];

$body = read_json_body();
$parcelId = (int)($body['parcel_id'] ?? 0);
if ($parcelId <= 0) {
    json_error('parcel_id is required', 400, 'missing_parcel');
}

$lst = $pdo->prepare("SELECT phone FROM users WHERE id = :uid");
$lst->execute([':uid' => $meId]);
$myPhone = (string)($lst->fetchColumn() ?: '');

$pdo->beginTransaction();
try {
    // Lock the parcel's owner links so two sign-ins cannot claim at once.
    $st = $pdo->prepare("SELECT o.id, o.full_name, o.phone, o.citizen_user_id, po.role, po.share_pct
        FROM parcel_owners po
        JOIN owners o ON o.id = po.owner_id
        WHERE po.parcel_id = :pid
        FOR UPDATE OF o");
    $st->execute([':pid' => $parcelId]);
    $owners = $st->fetchAll();

    if (!$owners) {
        $pdo->rollBack();
        json_error('This parcel has no owner recorded — contact the district land office.',
            409, 'no_owner');
    }

    foreach ($owners as $o) {
        if ((int)($o['citizen_user_id'] ?? 0) === $meId) {
            $pdo->commit();
            json_ok(['parcel_id' => $parcelId, 'owner_id' => (int)$o['id'], 'claimed' => true]);
        }
        if (!empty($o['citizen_user_id'])) {
            $pdo->rollBack();
            fee_audit($pdo, $meId, 'citizen_claim_refused', 'parcel', $parcelId,
                ['reason' => 'linked_to_another_citizen']);
            json_error('This parcel is linked to another account. Contact the district land office.',
                409, 'claimed_by_other');
        }
    }

    // Prefer the owner whose recorded phone is the citizen's own number. If the
    // owners carry phones but none matches, refuse rather than take the link.
    // A phone that cannot be normalised still counts as "a phone is on file":
    // it can never equal the citizen's number, so it must not silently fall
    // through to the no-phone fallback.
    $match = null;
    $hasPhone = false;
    foreach ($owners as $o) {
        if (trim((string)($o['phone'] ?? '')) === '') {
            continue;
        }
        $hasPhone = true;
        $op = citizen_normalise_phone((string)$o['phone']);
        if ($myPhone !== '' && $op !== null && $op === $myPhone) {
            $match = $o;
            break;
        }
    }
    if ($match === null && $hasPhone) {
        $pdo->rollBack();
        fee_audit($pdo, $meId, 'citizen_claim_refused', 'parcel', $parcelId,
            ['reason' => 'owner_phone_mismatch']);
        json_error('The phone number on this parcel does not match yours. Contact the district land office.',
            409, 'phone_mismatch');
    }
    if ($match === null) {
        // No phone recorded on any owner: fall back to the principal owner.
        usort($owners, function (array $a, array $b): int {
            $ra = ($a['role'] ?? '') === 'owner' ? 1 : 0;
            $rb = ($b['role'] ?? '') === 'owner' ? 1 : 0;
            if ($ra !== $rb) { return $rb - $ra; }
            return (float)($b['share_pct'] ?? 0) <=> (float)($a['share_pct'] ?? 0);
        });
        $match = $owners[0];
    }

    $ownerId = (int)$match['id'];
    $upd = $pdo->prepare("UPDATE owners SET citizen_user_id = :uid, updated_at = CURRENT_TIMESTAMP
        WHERE id = :oid AND citizen_user_id IS NULL");
    $upd->execute([':uid' => $meId, ':oid' => $ownerId]);
    if ($upd->rowCount() !== 1) {
        $pdo->rollBack();
        json_error('This parcel was just linked to another account. Contact the district land office.',
            409, 'claimed_by_other');
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}

fee_audit($pdo, $meId, 'citizen_claim', 'owner', $ownerId,
    ['parcel_id' => $parcelId, 'phone' => $myPhone]);

json_ok(['parcel_id' => $parcelId, 'owner_id' => $ownerId, 'claimed' => true], 201);
