<?php
/* ============================================================
   HandyFix — Messages API  (api/messages.php)
   GET  /api/messages.php?action=conversations  → contact list
   GET  /api/messages.php?action=thread&with=userId → messages
   POST /api/messages.php                       → send { receiver_id, body, booking_id? }
   PUT  /api/messages.php?action=read&with=userId → mark read
   ============================================================ */

require_once __DIR__ . '/../includes/db.php';

startSecureSession();
$me = requireAdmin();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$myId   = (int)$me['id'];

$body = [];
$raw  = file_get_contents('php://input');
if ($raw) $body = json_decode($raw, true) ?? [];

match (true) {
    $method === 'GET'  && $action === 'conversations' => conversations($db, $myId),
    $method === 'GET'  && $action === 'thread'        => thread($db, $myId, (int)($_GET['with'] ?? 0)),
    $method === 'POST'                                => sendMsg($db, $myId, $body),
    $method === 'PUT'  && $action === 'read'          => markRead($db, $myId, (int)($_GET['with'] ?? 0)),
    default => jsonResponse(['error' => 'Bad request'], 400),
};

/* ─────────────────────────────────────────────────────────── */
function conversations(PDO $db, int $myId): void {
    // Get all distinct users that have messaged with admin
    $rows = $db->prepare(
        "SELECT DISTINCT
                CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END AS other_id,
                MAX(m.created_at) AS last_time
         FROM messages m
         WHERE m.sender_id = ? OR m.receiver_id = ?
         GROUP BY other_id
         ORDER BY last_time DESC");
    $rows->execute([$myId, $myId, $myId]);
    $others = $rows->fetchAll();

    $result = [];
    foreach ($others as $o) {
        $uid  = (int)$o['other_id'];
        $user = $db->prepare("SELECT id, name, role FROM users WHERE id = ?");
        $user->execute([$uid]);
        $u = $user->fetch();
        if (!$u) continue;

        // last message
        $lm = $db->prepare(
            "SELECT body, created_at FROM messages
             WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
             ORDER BY created_at DESC LIMIT 1");
        $lm->execute([$myId, $uid, $uid, $myId]);
        $last = $lm->fetch();

        // unread count
        $unread = $db->prepare(
            "SELECT COUNT(*) FROM messages
             WHERE sender_id=? AND receiver_id=? AND is_read=0");
        $unread->execute([$uid, $myId]);

        $result[] = [
            'user_id'   => $uid,
            'name'      => $u['name'],
            'role'      => $u['role'],
            'initials'  => initials($u['name']),
            'last_msg'  => $last['body'] ?? '',
            'last_time' => $last['created_at'] ?? '',
            'unread'    => (int)$unread->fetchColumn(),
        ];
    }
    jsonResponse($result);
}

function thread(PDO $db, int $myId, int $withId): void {
    if (!$withId) jsonResponse(['error' => 'with param required'], 422);

    // Mark as read
    $db->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?")
       ->execute([$withId, $myId]);

    $stmt = $db->prepare(
        "SELECT m.id, m.sender_id, m.body, m.created_at,
                u.name AS sender_name, u.role AS sender_role
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)
         ORDER BY m.created_at ASC");
    $stmt->execute([$myId, $withId, $withId, $myId]);
    $msgs = $stmt->fetchAll();

    foreach ($msgs as &$m) {
        $m['from']     = $m['sender_id'] == $myId ? 'me' : 'them';
        $m['initials'] = initials($m['sender_name']);
        $m['time']     = date('h:i A', strtotime($m['created_at']));
        $m['date']     = date('D, M j', strtotime($m['created_at']));
    }
    jsonResponse($msgs);
}

function sendMsg(PDO $db, int $myId, array $b): void {
    $receiverId = (int)($b['receiver_id'] ?? 0);
    $body       = trim($b['body'] ?? '');
    if (!$receiverId || !$body) jsonResponse(['error' => 'receiver_id and body required'], 422);

    $db->prepare(
        "INSERT INTO messages (sender_id, receiver_id, booking_id, body)
         VALUES (?, ?, ?, ?)"
    )->execute([$myId, $receiverId, $b['booking_id'] ?? null, $body]);

    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()]);
}

function markRead(PDO $db, int $myId, int $withId): void {
    $db->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?")
       ->execute([$withId, $myId]);
    jsonResponse(['success' => true]);
}