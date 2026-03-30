<?php
define('DB_HOST',    'localhost');
define('DB_NAME',    'handyfix');
define('DB_USER',    'root');      // your phpMyAdmin user
define('DB_PASS',    '');          // your phpMyAdmin password (blank for XAMPP default)
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn  = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (PDOException $e) {
        sendJson(['error' => 'Database connection failed. Check DB credentials in includes/db.php. '.$e->getMessage()], 500);
    }
    return $pdo;
}

/* ─── Universal JSON response ─────────────────────────────── */
function sendJson(mixed $data, int $code = 200): void {
    // Ensure no output before headers
    if (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Legacy alias used by older files
function jsonResponse(mixed $data, int $code = 200): void {
    sendJson($data, $code);
}

/* ─── Session ──────────────────────────────────────────────── */
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',   // Lax works better for same-origin XHR
        ]);
        session_start();
    }
}

function currentUser(): ?array {
    startSecureSession();
    return $_SESSION['hf_user'] ?? null;
}

function requireRole(string ...$roles): array {
    $u = currentUser();
    if (!$u) sendJson(['error' => 'Not authenticated'], 401);
    if ($roles && !in_array($u['role'], $roles)) sendJson(['error' => 'Forbidden'], 403);
    return $u;
}

function requireAdmin(): array   { return requireRole('admin'); }
function requireAuth(): array    { return requireRole(); }

/* ─── Read JSON body ───────────────────────────────────────── */
function readBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    return json_decode($raw, true) ?? [];
}

/* ─── Booking ref ──────────────────────────────────────────── */
function nextBookingRef(): string {
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `booking_sequence` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `dummy` TINYINT DEFAULT 1) ENGINE=InnoDB");
        $db->exec("INSERT INTO `booking_sequence` (dummy) VALUES (1)");
        return sprintf('BK-%03d', (int)$db->lastInsertId());
    } catch (Exception $e) {
        return 'BK-' . rand(100,999);
    }
}

/* ─── Initials ─────────────────────────────────────────────── */
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    return strtoupper(substr(implode('', array_map(fn($p) => $p[0] ?? '', $parts)), 0, 2));
}

/* ─── Handle OPTIONS ───────────────────────────────────────── */
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    exit;
}
