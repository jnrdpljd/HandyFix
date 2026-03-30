<?php
/* ============================================================
   HandyFix — Contractors API  (api/contractors.php)
   Tracks which admin performed each action.
   ============================================================ */
ob_start();
require_once __DIR__ . '/../includes/db.php';
if (!function_exists('logActivity')) {
    function logActivity($db, $userId, $action, $detail, $icon, $color) {
        // Silently ignore the log if the function is missing.
        // This prevents the "Call to undefined function" fatal crash!
    }
}
startSecureSession();

$admin  = requireAdmin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';
$body   = readBody();

match (true) {
    $method === 'GET'    && !$id                         => listContractors($db),
    $method === 'GET'    && $id                          => getContractor($db, $id),
    $method === 'POST'                                   => createContractor($db, $body, $admin),
    $method === 'PUT'    && $action === 'verify'         => verifyContractor($db, $id, $admin),
    $method === 'PUT'    && $action === 'availability'   => setAvailability($db, $id, $body),
    $method === 'PUT'    && $id                          => updateContractor($db, $id, $body, $admin),
    $method === 'DELETE' && $id                          => deleteContractor($db, $id, $admin),
    $method === 'GET'    && $action === 'history'        => getHistory($db, $id),
    default => sendJson(['error' => 'Bad request'], 400),
};

/* ─── List ──────────────────────────────────────────────────── */
function listContractors(PDO $db): void {
    $q      = '%' . ($_GET['q']     ?? '') . '%';
    $trade  = $_GET['trade']  ?? '';
    $avail  = $_GET['avail']  ?? '';
    $status = $_GET['status'] ?? '';

    $sql = "SELECT u.id, u.name, u.email, u.phone, u.address, u.avatar, u.status, u.created_at,
                   c.id AS cid, c.trade, c.specialization, c.experience, c.daily_rate,
                   c.rating, c.total_reviews, c.jobs_completed, c.availability, c.verified, c.skills
            FROM users u
            JOIN contractors c ON c.user_id = u.id
            WHERE u.role = 'contractor'
              AND (u.name LIKE ? OR u.email LIKE ? OR c.trade LIKE ?)";
    $params = [$q, $q, $q];

    if ($trade)  { $sql .= " AND c.trade = ?";         $params[] = $trade; }
    if ($avail)  { $sql .= " AND c.availability = ?";  $params[] = $avail; }
    if ($status) { $sql .= " AND u.status = ?";        $params[] = $status; }
    $sql .= " ORDER BY u.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r['initials'] = initials($r['name']);
    sendJson($rows);
}

/* ─── Single ────────────────────────────────────────────────── */
function getContractor(PDO $db, int $id): void {
    $stmt = $db->prepare(
        "SELECT u.*, c.*, c.id AS cid
         FROM users u JOIN contractors c ON c.user_id = u.id
         WHERE u.id = ? AND u.role = 'contractor' LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) sendJson(['error' => 'Not found'], 404);
    $row['initials'] = initials($row['name']);
    sendJson($row);
}

/* ─── Create ────────────────────────────────────────────────── */
function createContractor(PDO $db, array $b, array $admin): void {
    foreach (['name','email','password','trade'] as $f) {
        if (empty($b[$f])) sendJson(['error' => "Field '$f' is required"], 422);
    }

    $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
    $chk->execute([strtolower($b['email'])]);
    if ($chk->fetch()) sendJson(['error' => 'Email already registered'], 409);

    $hash = password_hash($b['password'], PASSWORD_BCRYPT, ['cost' => 12]);

    $db->beginTransaction();
    try {
        $db->prepare(
            "INSERT INTO users (name, email, password, role, phone, address, status)
             VALUES (?, ?, ?, 'contractor', ?, ?, 'active')"
        )->execute([
            $b['name'], strtolower($b['email']), $hash,
            $b['phone'] ?? null, $b['address'] ?? null,
        ]);
        $userId = (int)$db->lastInsertId();

        $db->prepare(
            "INSERT INTO contractors (user_id, trade, specialization, experience, daily_rate, availability, verified, bio, skills)
             VALUES (?, ?, ?, ?, 0, 'available', 0, ?, ?)"
        )->execute([
            $userId, $b['trade'], $b['specialization'] ?? null,
            (int)($b['experience'] ?? 0),
            $b['bio'] ?? null, $b['skills'] ?? null,
        ]);

        // Log: who added this contractor
        logActivity($db, $admin['id'],
            'Admin added contractor: '.$b['name'],
            'Trade: '.$b['trade'].' · Added by: '.$admin['name'],
            '🔧', '#22c55e');

        $db->commit();
        sendJson(['success' => true, 'user_id' => $userId]);
    } catch (Exception $e) {
        $db->rollBack();
        sendJson(['error' => $e->getMessage()], 500);
    }
}

/* ─── Update ────────────────────────────────────────────────── */
function updateContractor(PDO $db, int $id, array $b, array $admin): void {
    // Get name before update for log
    $before = $db->prepare("SELECT name FROM users WHERE id=?");
    $before->execute([$id]);
    $old = $before->fetch();

    $db->prepare(
        "UPDATE users SET name=?, email=?, phone=?, address=?, status=?, updated_at=NOW()
         WHERE id=? AND role='contractor'"
    )->execute([
        $b['name'], strtolower($b['email']),
        $b['phone'] ?? null, $b['address'] ?? null,
        $b['status'] ?? 'active', $id,
    ]);

    $db->prepare(
        "UPDATE contractors SET trade=?, specialization=?, experience=?, bio=?, skills=?
         WHERE user_id=?"
    )->execute([
        $b['trade'], $b['specialization'] ?? null,
        (int)($b['experience'] ?? 0),
        $b['bio'] ?? null, $b['skills'] ?? null, $id,
    ]);

    logActivity($db, $admin['id'],
        'Admin edited contractor: '.($old['name'] ?? $id),
        'Updated by: '.$admin['name'],
        '✏️', '#f59e0b');

    sendJson(['success' => true]);
}

/* ─── Delete ────────────────────────────────────────────────── */
function deleteContractor(PDO $db, int $id, array $admin): void {
    $nm = $db->prepare("SELECT name FROM users WHERE id=?");
    $nm->execute([$id]);
    $row = $nm->fetch();

    $db->prepare("DELETE FROM users WHERE id = ? AND role = 'contractor'")->execute([$id]);

    logActivity($db, $admin['id'],
        'Admin deleted contractor: '.($row['name'] ?? $id),
        'Deleted by: '.$admin['name'],
        '🗑️', '#ef4444');

    sendJson(['success' => true]);
}

/* ─── Verify ────────────────────────────────────────────────── */
function verifyContractor(PDO $db, int $id, array $admin): void {
    $nm = $db->prepare("SELECT u.name FROM users u WHERE u.id=?");
    $nm->execute([$id]);
    $row = $nm->fetch();

    $db->prepare("UPDATE contractors SET verified = 1 WHERE user_id = ?")->execute([$id]);

    logActivity($db, $admin['id'],
        'Admin verified contractor: '.($row['name'] ?? $id),
        'Verified by: '.$admin['name'],
        '✔️', '#22c55e');

    sendJson(['success' => true]);
}

/* ─── Availability ──────────────────────────────────────────── */
function setAvailability(PDO $db, int $id, array $b): void {
    $avail = $b['availability'] ?? 'available';
    $db->prepare("UPDATE contractors SET availability = ? WHERE user_id = ?")->execute([$avail, $id]);
    sendJson(['success' => true]);
}

/* ─── History for a specific contractor ─────────────────────── */
function getHistory(PDO $db, int $id): void {
    // Get user name first
    $nm = $db->prepare("SELECT name FROM users WHERE id=?");
    $nm->execute([$id]);
    $row = $nm->fetch();
    $name = $row['name'] ?? '';

    $stmt = $db->prepare(
        "SELECT al.action, al.detail, al.icon, al.color,
                al.created_at, u.name AS performed_by
         FROM activity_log al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action LIKE ?
         ORDER BY al.created_at DESC LIMIT 30"
    );
    $stmt->execute(['%'.$name.'%']);
    sendJson($stmt->fetchAll());
}