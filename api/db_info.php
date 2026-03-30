<?php
/* ============================================================
   HandyFix — DB Info API  (api/db_info.php)
   GET /api/db_info.php → table names + row counts
   ============================================================ */
require_once __DIR__ . '/../includes/db.php';
startSecureSession();
requireAdmin();
$db = getDB();

$tables = $db->query("SHOW TABLE STATUS FROM `".DB_NAME."`")->fetchAll();
$result = array_map(fn($t) => [
    'name'   => $t['Name'],
    'rows'   => (int)$t['Rows'],
    'engine' => $t['Engine'],
], $tables);

jsonResponse([
    'database' => DB_NAME,
    'host'     => DB_HOST,
    'tables'   => $result,
]);