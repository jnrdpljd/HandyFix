<?php
/* ============================================================
   HandyFix — Bookings API  (api/bookings.php)
   GET    /api/bookings.php                     → list (with filters)
   GET    /api/bookings.php?id=1                → single
   POST   /api/bookings.php                     → create
   PUT    /api/bookings.php?id=1&action=assign  { contractor_id }
   PUT    /api/bookings.php?id=1&action=status  { status }
   PUT    /api/bookings.php?id=1&action=progress{ progress }
   DELETE /api/bookings.php?id=1                → delete
   ============================================================ */

require_once __DIR__ . '/../includes/db.php';

startSecureSession();
requireAdmin();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

$body = [];
$raw  = file_get_contents('php://input');
if ($raw) $body = json_decode($raw, true) ?? [];

match (true) {
    $method === 'GET'    && !$id                          => listBookings($db),
    $method === 'GET'    && $id                           => getBooking($db, $id),
    $method === 'POST'                                    => createBooking($db, $body),
    $method === 'PUT'    && $id && $action === 'assign'   => assignContractor($db, $id, $body),
    $method === 'PUT'    && $id && $action === 'status'   => updateStatus($db, $id, $body),
    $method === 'PUT'    && $id && $action === 'progress' => updateProgress($db, $id, $body),
    $method === 'DELETE' && $id                           => deleteBooking($db, $id),
    default => jsonResponse(['error' => 'Bad request'], 400),
};

/* ─────────────────────────────────────────────────────────── */
function listBookings(PDO $db): void {
    $q       = '%' . ($_GET['q']       ?? '') . '%';
    $status  = $_GET['status']  ?? '';
    $service = $_GET['service'] ?? '';

    $sql = "SELECT b.*,
                   uc.name AS client_name,
                   ux.name AS contractor_name
            FROM bookings b
            JOIN users uc ON uc.id = b.client_id
            LEFT JOIN users ux ON ux.id = b.contractor_id
            WHERE (uc.name LIKE ? OR b.service LIKE ? OR b.booking_ref LIKE ?)";
    $params = [$q, $q, $q];

    if ($status)  { $sql .= " AND b.status = ?";                 $params[] = $status; }
    if ($service) { $sql .= " AND b.service LIKE ?";             $params[] = "%$service%"; }

    $sql .= " ORDER BY b.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['client_initials']     = initials($r['client_name'] ?? '');
        $r['contractor_initials'] = initials($r['contractor_name'] ?? '');
    }
    jsonResponse($rows);
}

function getBooking(PDO $db, int $id): void {
    $stmt = $db->prepare(
        "SELECT b.*,
                uc.name AS client_name, uc.email AS client_email, uc.phone AS client_phone,
                ux.name AS contractor_name
         FROM bookings b
         JOIN users uc ON uc.id = b.client_id
         LEFT JOIN users ux ON ux.id = b.contractor_id
         WHERE b.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Not found'], 404);
    $row['client_initials']     = initials($row['client_name'] ?? '');
    $row['contractor_initials'] = initials($row['contractor_name'] ?? '');
    jsonResponse($row);
}

function createBooking(PDO $db, array $b): void {
    $required = ['client_id', 'service'];
    foreach ($required as $f) {
        if (empty($b[$f])) jsonResponse(['error' => "Field '$f' required"], 422);
    }
    $ref = nextBookingRef();
    $db->prepare(
        "INSERT INTO bookings
           (booking_ref, client_id, contractor_id, service, description, address, scheduled_date, scheduled_time, amount, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
    )->execute([
        $ref,
        (int)$b['client_id'],
        !empty($b['contractor_id']) ? (int)$b['contractor_id'] : null,
        $b['service'],
        $b['description'] ?? null,
        $b['address']     ?? null,
        $b['scheduled_date'] ?? null,
        $b['scheduled_time'] ?? null,
        (float)($b['amount'] ?? 0),
    ]);

    logActivity($db, null, 'New booking request received', $b['service'], '📥', 'var(--gold)');
    jsonResponse(['success' => true, 'booking_ref' => $ref]);
}

function assignContractor(PDO $db, int $id, array $b): void {
    $cid = (int)($b['contractor_id'] ?? 0);
    if (!$cid) jsonResponse(['error' => 'contractor_id required'], 422);

    $db->prepare(
        "UPDATE bookings SET contractor_id = ?, status = 'scheduled', updated_at = NOW()
         WHERE id = ?"
    )->execute([$cid, $id]);

    // fetch names for activity log
    $bk = $db->prepare("SELECT booking_ref, service FROM bookings WHERE id = ?");
    $bk->execute([$id]);
    $booking = $bk->fetch();

    $cn = $db->prepare("SELECT name FROM users WHERE id = ?");
    $cn->execute([$cid]);
    $cname = $cn->fetchColumn();

    logActivity($db, null,
        "Admin assigned {$booking['booking_ref']}",
        "$cname → {$booking['service']}",
        '📌', 'var(--gold)');

    jsonResponse(['success' => true]);
}

function updateStatus(PDO $db, int $id, array $b): void {
    $status = $b['status'] ?? '';
    $allowed = ['pending','scheduled','in_progress','completed','cancelled'];
    if (!in_array($status, $allowed)) jsonResponse(['error' => 'Invalid status'], 422);

    $progress = $status === 'completed' ? 100 : ($status === 'cancelled' ? 0 : null);
    if ($progress !== null) {
        $db->prepare("UPDATE bookings SET status=?, progress=?, updated_at=NOW() WHERE id=?")
           ->execute([$status, $progress, $id]);
    } else {
        $db->prepare("UPDATE bookings SET status=?, updated_at=NOW() WHERE id=?")
           ->execute([$status, $id]);
    }

    $bk = $db->prepare("SELECT booking_ref, service FROM bookings WHERE id = ?");
    $bk->execute([$id]);
    $booking = $bk->fetch();

    $icons = ['completed'=>'✅','cancelled'=>'❌','in_progress'=>'🔄','scheduled'=>'📅'];
    $colors= ['completed'=>'#22c55e','cancelled'=>'#ef4444','in_progress'=>'var(--gold)','scheduled'=>'#818cf8'];
    logActivity($db, null,
        "Booking {$booking['booking_ref']} ".ucfirst(str_replace('_',' ',$status)),
        $booking['service'],
        $icons[$status] ?? '🔔', $colors[$status] ?? 'var(--gold)');

    jsonResponse(['success' => true]);
}

function updateProgress(PDO $db, int $id, array $b): void {
    $p = max(0, min(100, (int)($b['progress'] ?? 0)));
    $db->prepare("UPDATE bookings SET progress=?, updated_at=NOW() WHERE id=?")->execute([$p, $id]);
    jsonResponse(['success' => true]);
}

function deleteBooking(PDO $db, int $id): void {
    $db->prepare("DELETE FROM bookings WHERE id=?")->execute([$id]);
    jsonResponse(['success' => true]);
}

function logActivity(PDO $db, ?int $uid, string $action, ?string $detail, string $icon, string $color): void {
    $db->prepare(
        "INSERT INTO activity_log (user_id, action, detail, icon, color) VALUES (?,?,?,?,?)"
    )->execute([$uid, $action, $detail, $icon, $color]);
}