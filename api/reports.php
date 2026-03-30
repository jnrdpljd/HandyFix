<?php
/* ============================================================
   HandyFix — Reports API  (api/reports.php)
   GET /api/reports.php?action=kpis
   GET /api/reports.php?action=revenue_chart   &period=month|quarter|year
   GET /api/reports.php?action=bookings_chart  &period=…
   GET /api/reports.php?action=service_breakdown
   GET /api/reports.php?action=top_contractors
   GET /api/reports.php?action=transactions
   GET /api/reports.php?action=status_summary
   GET /api/reports.php?action=growth
   ============================================================ */

require_once __DIR__ . '/../includes/db.php';

startSecureSession();
requireAdmin();

$db     = getDB();
$action = $_GET['action'] ?? 'kpis';
$period = $_GET['period'] ?? 'month';   // month | quarter | year

// Period SQL helper
function periodWhere(string $period): string {
    return match ($period) {
        'quarter' => "AND b.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)",
        'year'    => "AND YEAR(b.created_at) = YEAR(NOW())",
        default   => "AND MONTH(b.created_at) = MONTH(NOW()) AND YEAR(b.created_at) = YEAR(NOW())",
    };
}

match ($action) {
    'kpis'              => kpis($db, $period),
    'revenue_chart'     => revenueChart($db),
    'bookings_chart'    => bookingsChart($db),
    'service_breakdown' => serviceBreakdown($db),
    'top_contractors'   => topContractors($db, $period),
    'transactions'      => transactions($db, $period),
    'status_summary'    => statusSummary($db, $period),
    'growth'            => growth($db),
    default             => jsonResponse(['error' => 'Unknown action'], 400),
};

/* ─────────────────────────────────────────────────────────── */
function kpis(PDO $db, string $period): void {
    $pw = periodWhere($period);
    $r  = [];

    $r['revenue'] = (float)$db->query(
        "SELECT COALESCE(SUM(amount),0) FROM bookings b WHERE status='completed' $pw")->fetchColumn();

    $r['total_bookings'] = (int)$db->query(
        "SELECT COUNT(*) FROM bookings b WHERE 1=1 $pw")->fetchColumn();

    $total  = (int)$db->query("SELECT COUNT(*) FROM bookings b WHERE 1=1 $pw")->fetchColumn();
    $done   = (int)$db->query("SELECT COUNT(*) FROM bookings b WHERE status='completed' $pw")->fetchColumn();
    $r['completion_rate'] = $total > 0 ? round($done / $total * 100, 1) : 0;

    $r['avg_rating']         = (float)$db->query("SELECT COALESCE(AVG(rating),0) FROM reviews")->fetchColumn();
    $r['active_contractors'] = (int)$db->query(
        "SELECT COUNT(*) FROM users u JOIN contractors c ON c.user_id=u.id WHERE u.status='active'")->fetchColumn();
    $r['total_clients']      = (int)$db->query(
        "SELECT COUNT(*) FROM users WHERE role='client' AND status='active'")->fetchColumn();

    jsonResponse($r);
}

function revenueChart(PDO $db): void {
    $rows = $db->query(
        "SELECT MONTH(created_at) AS mo, COALESCE(SUM(amount),0) AS total
         FROM bookings
         WHERE status='completed' AND YEAR(created_at)=YEAR(NOW())
         GROUP BY mo ORDER BY mo")->fetchAll();
    $monthly = array_fill(1, 12, 0);
    foreach ($rows as $r) $monthly[(int)$r['mo']] = (float)$r['total'];
    jsonResponse(array_values($monthly));
}

function bookingsChart(PDO $db): void {
    $rows = $db->query(
        "SELECT MONTH(created_at) AS mo, COUNT(*) AS total
         FROM bookings WHERE YEAR(created_at)=YEAR(NOW())
         GROUP BY mo ORDER BY mo")->fetchAll();
    $monthly = array_fill(1, 12, 0);
    foreach ($rows as $r) $monthly[(int)$r['mo']] = (int)$r['total'];
    jsonResponse(array_values($monthly));
}

function serviceBreakdown(PDO $db): void {
    $total = (int)$db->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
    if ($total === 0) { jsonResponse([]); return; }
    $rows = $db->query(
        "SELECT service, COUNT(*) AS cnt,
                ROUND(COUNT(*)*100/$total, 1) AS pct
         FROM bookings GROUP BY service ORDER BY cnt DESC LIMIT 8")->fetchAll();
    jsonResponse($rows);
}

function topContractors(PDO $db, string $period): void {
    $pw = periodWhere($period);
    $rows = $db->query(
        "SELECT u.name, c.trade,
                c.jobs_completed AS jobs,
                COALESCE(c.rating,0) AS rating,
                COALESCE(AVG(r.rating),0) AS avg_rating
         FROM users u
         JOIN contractors c ON c.user_id = u.id
         LEFT JOIN reviews r ON r.contractor_id = u.id
         WHERE u.role='contractor'
         GROUP BY u.id
         ORDER BY c.jobs_completed DESC, avg_rating DESC
         LIMIT 10")->fetchAll();

    $max = (int)($rows[0]['jobs'] ?? 1);
    foreach ($rows as &$r) {
        $r['pct'] = $max > 0 ? round((int)$r['jobs'] / $max * 100) : 0;
    }
    jsonResponse($rows);
}

function transactions(PDO $db, string $period): void {
    $pw = periodWhere($period);
    $stmt = $db->query(
        "SELECT b.booking_ref, uc.name AS client, b.service, b.amount, b.status
         FROM bookings b JOIN users uc ON uc.id=b.client_id
         WHERE 1=1 $pw
         ORDER BY b.created_at DESC LIMIT 20");
    jsonResponse($stmt->fetchAll());
}

function statusSummary(PDO $db, string $period): void {
    $pw    = periodWhere($period);
    $total = (int)$db->query("SELECT COUNT(*) FROM bookings b WHERE 1=1 $pw")->fetchColumn();
    if ($total === 0) { jsonResponse([]); return; }

    $rows = $db->query(
        "SELECT status, COUNT(*) AS cnt
         FROM bookings b WHERE 1=1 $pw
         GROUP BY status")->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'label' => ucfirst(str_replace('_',' ',$r['status'])),
            'count' => (int)$r['cnt'],
            'pct'   => round((int)$r['cnt'] / $total * 100, 1),
        ];
    }
    jsonResponse($result);
}

function growth(PDO $db): void {
    $rows = $db->query(
        "SELECT MONTH(created_at) AS mo,
                ROUND((COUNT(*) - LAG(COUNT(*)) OVER (ORDER BY MONTH(created_at))) /
                      NULLIF(LAG(COUNT(*)) OVER (ORDER BY MONTH(created_at)),0)*100, 1) AS growth_pct
         FROM bookings
         WHERE YEAR(created_at) = YEAR(NOW())
         GROUP BY mo ORDER BY mo LIMIT 6")->fetchAll();
    jsonResponse($rows);
}