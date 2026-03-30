<?php
/* ============================================================
   HandyFix — Dashboard API  (api/dashboard.php)
   GET /api/dashboard.php?action=stats
   GET /api/dashboard.php?action=pending
   GET /api/dashboard.php?action=activity
   GET /api/dashboard.php?action=chart_bookings
   GET /api/dashboard.php?action=service_breakdown
   ============================================================ */

require_once __DIR__ . '/../includes/db.php';

startSecureSession();
requireAdmin();

$action = $_GET['action'] ?? 'stats';
$db     = getDB();

match ($action) {
    'stats'             => stats($db),
    'pending'           => pending($db),
    'activity'          => activity($db),
    'chart_bookings'    => chartBookings($db),
    'service_breakdown' => serviceBreakdown($db),
    default             => jsonResponse(['error' => 'Unknown action'], 400),
};

/* ─────────────────────────────────────────────────────────── */
function stats(PDO $db): void {
    $r = [];

    $r['pending_requests'] = (int)$db->query(
        "SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();

    $r['active_contractors'] = (int)$db->query(
        "SELECT COUNT(*) FROM users u
         JOIN contractors c ON c.user_id = u.id
         WHERE u.role = 'contractor' AND u.status = 'active'")->fetchColumn();

    $r['total_clients'] = (int)$db->query(
        "SELECT COUNT(*) FROM users WHERE role = 'client' AND status = 'active'")->fetchColumn();

    $r['jobs_completed'] = (int)$db->query(
        "SELECT COUNT(*) FROM bookings WHERE status = 'completed'")->fetchColumn();

    $rev = $db->query(
        "SELECT COALESCE(SUM(amount),0) FROM bookings WHERE status = 'completed'")->fetchColumn();
    $r['total_revenue'] = (float)$rev;

    $r['platform_rating'] = (float)($db->query(
        "SELECT COALESCE(AVG(rating),0) FROM reviews")->fetchColumn() ?? 0);

    jsonResponse($r);
}

function pending(PDO $db): void {
    $rows = $db->query(
        "SELECT b.booking_ref, b.service, b.scheduled_date,
                u.name AS client_name
         FROM bookings b
         JOIN users u ON u.id = b.client_id
         WHERE b.status = 'pending'
         ORDER BY b.created_at DESC
         LIMIT 10")->fetchAll();
    jsonResponse($rows);
}

function activity(PDO $db): void {
    $rows = $db->query(
        "SELECT a.action, a.detail, a.icon, a.color,
                TIMESTAMPDIFF(MINUTE, a.created_at, NOW()) AS mins_ago,
                a.created_at
         FROM activity_log a
         ORDER BY a.created_at DESC
         LIMIT 20")->fetchAll();
    jsonResponse($rows);
}

function chartBookings(PDO $db): void {
    $rows = $db->query(
        "SELECT MONTH(created_at) AS month, COUNT(*) AS total
         FROM bookings
         WHERE YEAR(created_at) = YEAR(NOW())
         GROUP BY MONTH(created_at)
         ORDER BY month")->fetchAll();

    $monthly = array_fill(1, 12, 0);
    foreach ($rows as $r) $monthly[(int)$r['month']] = (int)$r['total'];
    jsonResponse(array_values($monthly));
}

function serviceBreakdown(PDO $db): void {
    $rows = $db->query(
        "SELECT service,
                COUNT(*) AS total,
                ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM bookings), 1) AS pct
         FROM bookings
         GROUP BY service
         ORDER BY total DESC
         LIMIT 8")->fetchAll();
    jsonResponse($rows);
}