<?php
/* ============================================================
   HandyFix — Clients API  (api/clients.php)
   GET    /api/clients.php          → list
   GET    /api/clients.php?id=1     → single + booking history
   DELETE /api/clients.php?id=1     → delete
   PUT    /api/clients.php?id=1&action=suspend → suspend
   ============================================================ */

require_once __DIR__ . '/../includes/db.php';

startSecureSession();
requireAdmin();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

match (true) {
    $method === 'GET'  && !$id                      => listClients($db),
    $method === 'GET'  && $id                       => getClient($db, $id),
    $method === 'PUT'  && $id && $action==='suspend'=> suspendClient($db, $id),
    $method === 'DELETE' && $id                     => deleteClient($db, $id),
    default => jsonResponse(['error' => 'Bad request'], 400),
};

/* ─────────────────────────────────────────────────────────── */
function listClients(PDO $db): void {
    $q      = '%' . ($_GET['q']      ?? '') . '%';
    $status = $_GET['status'] ?? '';

    $sql    = "SELECT u.id, u.name, u.email, u.phone, u.address, u.status, u.created_at,
                      COUNT(DISTINCT b.id)          AS total_bookings,
                      COALESCE(SUM(b.amount), 0)    AS total_spent
               FROM users u
               LEFT JOIN bookings b ON b.client_id = u.id AND b.status = 'completed'
               WHERE u.role = 'client' AND (u.name LIKE ? OR u.email LIKE ?)";
    $params = [$q, $q];

    if ($status) { $sql .= " AND u.status = ?"; $params[] = $status; }

    $sql .= " GROUP BY u.id ORDER BY u.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        // FIX: Removed undefined initials() function, replaced with safe inline method
        $r['initials']      = strtoupper(substr($r['name'] ?? 'C', 0, 2));
        $r['total_bookings']= (int)$r['total_bookings'];
        $r['total_spent']   = (float)$r['total_spent'];
    }
    jsonResponse($rows);
}

function getClient(PDO $db, int $id): void {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'client' LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) jsonResponse(['error' => 'Not found'], 404);

    $stmt2 = $db->prepare(
        "SELECT b.*, u.name AS contractor_name
         FROM bookings b
         LEFT JOIN users u ON u.id = b.contractor_id
         WHERE b.client_id = ?
         ORDER BY b.created_at DESC LIMIT 20");
    $stmt2->execute([$id]);
    $user['bookings']   = $stmt2->fetchAll();
    
    // FIX: Removed undefined initials() function
    $user['initials']   = strtoupper(substr($user['name'] ?? 'C', 0, 2));

    $stats = $db->prepare(
        "SELECT COUNT(*) AS total, COALESCE(SUM(amount),0) AS spent FROM bookings WHERE client_id = ?");
    $stats->execute([$id]);
    $s = $stats->fetch();
    $user['total_bookings'] = (int)$s['total'];
    $user['total_spent']    = (float)$s['spent'];

    jsonResponse($user);
}

function suspendClient(PDO $db, int $id): void {
    // FIX: Added toggle logic so you can suspend AND reactivate
    $stmt = $db->prepare("SELECT status FROM users WHERE id = ? AND role = 'client'");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) jsonResponse(['error' => 'Not found'], 404);
    
    $newStatus = $user['status'] === 'suspended' ? 'active' : 'suspended';
    $db->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'client'")->execute([$newStatus, $id]);
    
    jsonResponse(['success' => true]);
}

function deleteClient(PDO $db, int $id): void {
    $db->prepare("DELETE FROM users WHERE id = ? AND role = 'client'")->execute([$id]);
    jsonResponse(['success' => true]);
}