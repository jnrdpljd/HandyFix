<?php
/* ============================================================
   HandyFix — Contractor API  (api/contractor.php)
   All endpoints require active contractor session.

   GET  ?action=me            → current contractor profile
   GET  ?action=stats         → dashboard stats
   GET  ?action=bookings      → all bookings for this contractor
   GET  ?action=chart         → monthly booking chart data
   GET  ?action=activity      → recent activity log
   GET  ?action=upcoming      → next 5 scheduled bookings
   GET  ?action=services      → service breakdown (top services)
   PUT  ?action=status&id=N   → update booking status { status }
   PUT  ?action=progress&id=N → update booking progress { progress }
   PUT  ?action=profile       → update profile fields
   PUT  ?action=password      → change password
   PUT  ?action=availability  → update availability / rate
   GET  ?action=conversations → message conversations
   GET  ?action=thread&with=N → message thread with user N
   POST ?action=send          → send message { receiver_id, body }
   PUT  ?action=read&with=N   → mark messages read
   GET  ?action=calendar      → calendar events (bookings by month)
   ============================================================ */

require_once __DIR__ . '/../includes/db.php';

startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// Verify contractor session
$user = currentUser();
if (!$user) jsonResponse(['error' => 'Unauthorized'], 401);
if ($user['role'] !== 'contractor') jsonResponse(['error' => 'Forbidden — contractor only'], 403);

$myId = (int)$user['id'];
$db   = getDB();

$body = [];
$raw  = file_get_contents('php://input');
if ($raw) $body = json_decode($raw, true) ?? [];
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

match (true) {
    $action === 'me'            && $method === 'GET'  => getMe($db, $myId),
    $action === 'stats'         && $method === 'GET'  => getStats($db, $myId),
    $action === 'bookings'      && $method === 'GET'  => getBookings($db, $myId),
    $action === 'chart'         && $method === 'GET'  => getChart($db, $myId),
    $action === 'activity'      && $method === 'GET'  => getActivity($db, $myId),
    $action === 'upcoming'      && $method === 'GET'  => getUpcoming($db, $myId),
    $action === 'services'      && $method === 'GET'  => getServices($db, $myId),
    $action === 'status'        && $method === 'PUT'  => updateStatus($db, $myId, $id, $body),
    $action === 'progress'      && $method === 'PUT'  => updateProgress($db, $myId, $id, $body),
    $action === 'profile'       && $method === 'PUT'  => updateProfile($db, $myId, $body),
    $action === 'password'      && $method === 'PUT'  => changePassword($db, $myId, $body),
    $action === 'availability'  && $method === 'PUT'  => updateAvailability($db, $myId, $body),
    $action === 'conversations' && $method === 'GET'  => getConversations($db, $myId),
    $action === 'thread'        && $method === 'GET'  => getThread($db, $myId, (int)($_GET['with'] ?? 0)),
    $action === 'send'          && $method === 'POST' => sendMessage($db, $myId, $body),
    $action === 'read'          && $method === 'PUT'  => markRead($db, $myId, (int)($_GET['with'] ?? 0)),
    $action === 'calendar'      && $method === 'GET'  => getCalendar($db, $myId),
    default => jsonResponse(['error' => 'Unknown action'], 400),
};

/* ─── Me ───────────────────────────────────────────────────── */
function getMe(PDO $db, int $uid): void {
    $stmt = $db->prepare(
        "SELECT u.id, u.name, u.email, u.phone, u.address, u.avatar, u.status, u.created_at,
                c.trade, c.specialization, c.experience, c.daily_rate,
                c.rating, c.total_reviews, c.jobs_completed, c.availability, c.verified,
                c.bio, c.skills
         FROM users u LEFT JOIN contractors c ON c.user_id = u.id
         WHERE u.id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'User not found'], 404);
    $row['initials'] = initials($row['name']);
    jsonResponse($row);
}

/* ─── Stats ────────────────────────────────────────────────── */
function getStats(PDO $db, int $uid): void {
    $s = [];
    $s['total_bookings']  = (int)$db->prepare(
        "SELECT COUNT(*) FROM bookings WHERE contractor_id=?")->execute([$uid]) ? 0 : 0;

    // Use a single query for all counts
    $row = $db->prepare(
        "SELECT
           COUNT(*)                                             AS total,
           SUM(status='completed')                             AS completed,
           SUM(status IN ('scheduled','in_progress'))          AS active,
           SUM(status='cancelled')                             AS cancelled,
           COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) AS earnings
         FROM bookings WHERE contractor_id=?")->execute([$uid]) ? null : null;

    $q = $db->prepare(
        "SELECT
           COUNT(*)                                                             AS total,
           SUM(status='completed')                                              AS completed,
           SUM(status IN ('scheduled','in_progress'))                           AS active,
           SUM(status='cancelled')                                              AS cancelled,
           COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) AS earnings
         FROM bookings WHERE contractor_id=?");
    $q->execute([$uid]);
    $counts = $q->fetch();

    $rating = $db->prepare("SELECT COALESCE(AVG(rating),0), COUNT(*) FROM reviews WHERE contractor_id=?");
    $rating->execute([$uid]);
    [$avgRating, $totalReviews] = $rating->fetch(PDO::FETCH_NUM);

    jsonResponse([
        'total_bookings' => (int)$counts['total'],
        'completed'      => (int)$counts['completed'],
        'active'         => (int)$counts['active'],
        'cancelled'      => (int)$counts['cancelled'],
        'earnings'       => (float)$counts['earnings'],
        'rating'         => round((float)$avgRating, 1),
        'total_reviews'  => (int)$totalReviews,
    ]);
}

/* ─── Bookings ─────────────────────────────────────────────── */
function getBookings(PDO $db, int $uid): void {
    $q      = '%' . ($_GET['q'] ?? '') . '%';
    $status = $_GET['status'] ?? '';

    $sql = "SELECT b.*, u.name AS client_name, u.phone AS client_phone, u.email AS client_email
            FROM bookings b JOIN users u ON u.id = b.client_id
            WHERE b.contractor_id = ? AND (b.service LIKE ? OR u.name LIKE ? OR b.booking_ref LIKE ?)";
    $params = [$uid, $q, $q, $q];

    if ($status) { $sql .= " AND b.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY b.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r['client_initials'] = initials($r['client_name'] ?? '');
    jsonResponse($rows);
}

/* ─── Chart ────────────────────────────────────────────────── */
function getChart(PDO $db, int $uid): void {
    $rows = $db->prepare(
        "SELECT MONTH(created_at) AS mo, COUNT(*) AS total
         FROM bookings WHERE contractor_id=? AND YEAR(created_at)=YEAR(NOW())
         GROUP BY mo ORDER BY mo")->execute([$uid]) ? [] : [];

    $stmt = $db->prepare(
        "SELECT MONTH(created_at) AS mo, COUNT(*) AS total
         FROM bookings WHERE contractor_id=? AND YEAR(created_at)=YEAR(NOW())
         GROUP BY mo ORDER BY mo");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();

    $monthly = array_fill(1, 12, 0);
    foreach ($rows as $r) $monthly[(int)$r['mo']] = (int)$r['total'];
    jsonResponse(array_values($monthly));
}

/* ─── Activity ─────────────────────────────────────────────── */
function getActivity(PDO $db, int $uid): void {
    $stmt = $db->prepare(
        "SELECT action, detail, icon, color,
                TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS mins_ago
         FROM activity_log
         WHERE user_id = ?
         ORDER BY created_at DESC LIMIT 15");
    $stmt->execute([$uid]);
    jsonResponse($stmt->fetchAll());
}

/* ─── Upcoming ─────────────────────────────────────────────── */
function getUpcoming(PDO $db, int $uid): void {
    $stmt = $db->prepare(
        "SELECT b.booking_ref, b.service, b.scheduled_date, b.scheduled_time, b.status, b.amount,
                u.name AS client_name
         FROM bookings b JOIN users u ON u.id = b.client_id
         WHERE b.contractor_id = ?
           AND b.status IN ('scheduled','in_progress')
           AND b.scheduled_date >= CURDATE()
         ORDER BY b.scheduled_date ASC, b.scheduled_time ASC
         LIMIT 5");
    $stmt->execute([$uid]);
    jsonResponse($stmt->fetchAll());
}

/* ─── Services ─────────────────────────────────────────────── */
function getServices(PDO $db, int $uid): void {
    $total = (int)$db->prepare(
        "SELECT COUNT(*) FROM bookings WHERE contractor_id=?")->execute([$uid]) ? 0 : 0;

    $t = $db->prepare("SELECT COUNT(*) FROM bookings WHERE contractor_id=?");
    $t->execute([$uid]);
    $total = (int)$t->fetchColumn();

    if ($total === 0) { jsonResponse([]); return; }

    $stmt = $db->prepare(
        "SELECT service, COUNT(*) AS cnt,
                ROUND(COUNT(*)*100/$total, 1) AS pct
         FROM bookings WHERE contractor_id=?
         GROUP BY service ORDER BY cnt DESC LIMIT 6");
    $stmt->execute([$uid]);
    jsonResponse($stmt->fetchAll());
}

/* ─── Update Status ────────────────────────────────────────── */
function updateStatus(PDO $db, int $uid, int $id, array $body): void {
    $status  = $body['status'] ?? '';
    $allowed = ['scheduled','in_progress','completed','cancelled'];
    if (!in_array($status, $allowed)) jsonResponse(['error' => 'Invalid status'], 422);

    // Verify ownership
    $chk = $db->prepare("SELECT id, booking_ref, service FROM bookings WHERE id=? AND contractor_id=?");
    $chk->execute([$id, $uid]);
    $bk = $chk->fetch();
    if (!$bk) jsonResponse(['error' => 'Booking not found'], 404);

    $progress = $status === 'completed' ? 100 : null;
    if ($progress !== null) {
        $db->prepare("UPDATE bookings SET status=?, progress=?, updated_at=NOW() WHERE id=?")
           ->execute([$status, $progress, $id]);
    } else {
        $db->prepare("UPDATE bookings SET status=?, updated_at=NOW() WHERE id=?")
           ->execute([$status, $id]);
    }

    // Log activity
    $icons  = ['completed'=>'✅','cancelled'=>'❌','in_progress'=>'🔄','scheduled'=>'📅'];
    $colors = ['completed'=>'#22c55e','cancelled'=>'#ef4444','in_progress'=>'var(--gold)','scheduled'=>'#818cf8'];
    $db->prepare("INSERT INTO activity_log (user_id,action,detail,icon,color) VALUES (?,?,?,?,?)")
       ->execute([$uid, "Booking {$bk['booking_ref']} ".ucfirst(str_replace('_',' ',$status)), $bk['service'], $icons[$status]??'🔔', $colors[$status]??'#E6AF2E']);

    // Update contractor jobs_completed count
    if ($status === 'completed') {
        $db->prepare("UPDATE contractors SET jobs_completed = (SELECT COUNT(*) FROM bookings WHERE contractor_id=? AND status='completed') WHERE user_id=?")
           ->execute([$uid, $uid]);
    }

    jsonResponse(['success' => true]);
}

/* ─── Update Progress ──────────────────────────────────────── */
function updateProgress(PDO $db, int $uid, int $id, array $body): void {
    $p = max(0, min(100, (int)($body['progress'] ?? 0)));
    $db->prepare("UPDATE bookings SET progress=?, updated_at=NOW() WHERE id=? AND contractor_id=?")
       ->execute([$p, $id, $uid]);
    jsonResponse(['success' => true]);
}

/* ─── Update Profile ───────────────────────────────────────── */
function updateProfile(PDO $db, int $uid, array $body): void {
    $db->prepare("UPDATE users SET name=?, phone=?, address=?, updated_at=NOW() WHERE id=?")
       ->execute([$body['name'] ?? '', $body['phone'] ?? null, $body['address'] ?? null, $uid]);
    $db->prepare("UPDATE contractors SET trade=?, specialization=?, experience=?, daily_rate=?, bio=?, skills=? WHERE user_id=?")
       ->execute([
           $body['trade'] ?? '',
           $body['specialization'] ?? null,
           (int)($body['experience'] ?? 0),
           (float)($body['daily_rate'] ?? 0),
           $body['bio'] ?? null,
           $body['skills'] ?? null,
           $uid,
       ]);
    jsonResponse(['success' => true]);
}

/* ─── Change Password ──────────────────────────────────────── */
function changePassword(PDO $db, int $uid, array $body): void {
    $current = $body['current_password'] ?? '';
    $new     = $body['new_password']     ?? '';
    if (!$current || !$new) jsonResponse(['error' => 'Both passwords required'], 422);
    if (strlen($new) < 8)  jsonResponse(['error' => 'Password too short'], 422);

    $row = $db->prepare("SELECT password FROM users WHERE id=?")->execute([$uid]) ? null : null;
    $stmt = $db->prepare("SELECT password FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password'])) {
        jsonResponse(['error' => 'Current password is incorrect'], 401);
    }

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $uid]);
    jsonResponse(['success' => true]);
}

/* ─── Update Availability ──────────────────────────────────── */
function updateAvailability(PDO $db, int $uid, array $body): void {
    $avail = $body['availability'] ?? 'available';
    $rate  = (float)($body['daily_rate'] ?? 0);
    $db->prepare("UPDATE contractors SET availability=?, daily_rate=? WHERE user_id=?")
       ->execute([$avail, $rate, $uid]);
    jsonResponse(['success' => true]);
}

/* ─── Conversations ────────────────────────────────────────── */
function getConversations(PDO $db, int $uid): void {
    $stmt = $db->prepare(
        "SELECT DISTINCT
            CASE WHEN sender_id=? THEN receiver_id ELSE sender_id END AS other_id,
            MAX(created_at) AS last_time
         FROM messages WHERE sender_id=? OR receiver_id=?
         GROUP BY other_id ORDER BY last_time DESC");
    $stmt->execute([$uid, $uid, $uid]);
    $others = $stmt->fetchAll();

    $result = [];
    foreach ($others as $o) {
        $oid  = (int)$o['other_id'];
        $u    = $db->prepare("SELECT id, name, role FROM users WHERE id=?");
        $u->execute([$oid]);
        $other = $u->fetch();
        if (!$other) continue;

        $lm = $db->prepare(
            "SELECT body, created_at FROM messages
             WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
             ORDER BY created_at DESC LIMIT 1");
        $lm->execute([$uid, $oid, $oid, $uid]);
        $last = $lm->fetch();

        $unread = $db->prepare("SELECT COUNT(*) FROM messages WHERE sender_id=? AND receiver_id=? AND is_read=0");
        $unread->execute([$oid, $uid]);

        $result[] = [
            'user_id'   => $oid,
            'name'      => $other['name'],
            'role'      => $other['role'],
            'initials'  => initials($other['name']),
            'last_msg'  => $last['body'] ?? '',
            'last_time' => $last['created_at'] ?? '',
            'unread'    => (int)$unread->fetchColumn(),
        ];
    }
    jsonResponse($result);
}

/* ─── Thread ───────────────────────────────────────────────── */
function getThread(PDO $db, int $uid, int $withId): void {
    if (!$withId) jsonResponse(['error' => 'with param required'], 422);
    $db->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?")
       ->execute([$withId, $uid]);

    $stmt = $db->prepare(
        "SELECT m.id, m.sender_id, m.body, m.is_read, m.created_at,
                u.name AS sender_name
         FROM messages m JOIN users u ON u.id=m.sender_id
         WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)
         ORDER BY m.created_at ASC");
    $stmt->execute([$uid, $withId, $withId, $uid]);
    $msgs = $stmt->fetchAll();

    foreach ($msgs as &$m) {
        $m['from']     = $m['sender_id'] == $uid ? 'me' : 'them';
        $m['initials'] = initials($m['sender_name']);
        $m['time']     = date('h:i A', strtotime($m['created_at']));
        $m['date']     = date('D, M j', strtotime($m['created_at']));
    }
    jsonResponse($msgs);
}

/* ─── Send Message ─────────────────────────────────────────── */
function sendMessage(PDO $db, int $uid, array $body): void {
    $rid  = (int)($body['receiver_id'] ?? 0);
    $text = trim($body['body'] ?? '');
    if (!$rid || !$text) jsonResponse(['error' => 'receiver_id and body required'], 422);

    $db->prepare("INSERT INTO messages (sender_id,receiver_id,body) VALUES (?,?,?)")
       ->execute([$uid, $rid, $text]);
    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()]);
}

/* ─── Mark Read ────────────────────────────────────────────── */
function markRead(PDO $db, int $uid, int $withId): void {
    $db->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?")
       ->execute([$withId, $uid]);
    jsonResponse(['success' => true]);
}

/* ─── Calendar ─────────────────────────────────────────────── */
function getCalendar(PDO $db, int $uid): void {
    $month = (int)($_GET['month'] ?? date('n'));
    $year  = (int)($_GET['year']  ?? date('Y'));

    $stmt = $db->prepare(
        "SELECT b.id, b.booking_ref, b.service, b.scheduled_date, b.scheduled_time,
                b.status, b.amount, b.address, b.progress,
                u.name AS client_name
         FROM bookings b JOIN users u ON u.id=b.client_id
         WHERE b.contractor_id=?
           AND MONTH(b.scheduled_date)=?
           AND YEAR(b.scheduled_date)=?
         ORDER BY b.scheduled_date ASC, b.scheduled_time ASC");
    $stmt->execute([$uid, $month, $year]);
    jsonResponse($stmt->fetchAll());
}