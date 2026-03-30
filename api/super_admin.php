<?php
/* ============================================================
   HandyFix — Super Admin API  (api/super_admin.php)
   NO session required — this is the bootstrap tool.
   Protect this by IP or delete after initial setup.
   ============================================================ */

require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Allow all origins for the super admin tool
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

match (true) {
    $action === 'setup_db'    && $method === 'POST'   => setupDb(),
    $action === 'reset_admin' && $method === 'POST'   => resetAdmin(),
    $action === 'create_user' && $method === 'POST'   => createUser(),
    $action === 'list_users'  && $method === 'GET'    => listUsers(),
    $action === 'delete_user' && $method === 'DELETE' => deleteUser(),
    default => jsonResponse(['error' => 'Unknown action'], 400),
};

/* ─── Setup DB ─────────────────────────────────────────────── */
function setupDb(): void {
    $db  = getDB();
    $sql = "
    CREATE TABLE IF NOT EXISTS `users` (
      `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `name`       VARCHAR(120) NOT NULL,
      `email`      VARCHAR(180) NOT NULL UNIQUE,
      `password`   VARCHAR(255) NOT NULL,
      `role`       ENUM('admin','client','contractor') NOT NULL DEFAULT 'client',
      `phone`      VARCHAR(30)  DEFAULT NULL,
      `address`    VARCHAR(255) DEFAULT NULL,
      `avatar`     VARCHAR(255) DEFAULT NULL,
      `status`     ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `contractors` (
      `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `user_id`        INT UNSIGNED NOT NULL UNIQUE,
      `trade`          VARCHAR(80)  NOT NULL,
      `specialization` VARCHAR(255) DEFAULT NULL,
      `experience`     TINYINT UNSIGNED DEFAULT 0,
      `daily_rate`     DECIMAL(10,2) DEFAULT 0.00,
      `rating`         DECIMAL(3,2) DEFAULT 0.00,
      `total_reviews`  INT UNSIGNED DEFAULT 0,
      `jobs_completed` INT UNSIGNED DEFAULT 0,
      `availability`   ENUM('available','busy','off') NOT NULL DEFAULT 'available',
      `verified`       TINYINT(1) NOT NULL DEFAULT 0,
      `bio`            TEXT DEFAULT NULL,
      `skills`         VARCHAR(500) DEFAULT NULL,
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `bookings` (
      `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `booking_ref`    VARCHAR(20) NOT NULL UNIQUE,
      `client_id`      INT UNSIGNED NOT NULL,
      `contractor_id`  INT UNSIGNED DEFAULT NULL,
      `service`        VARCHAR(120) NOT NULL,
      `description`    TEXT DEFAULT NULL,
      `address`        VARCHAR(255) DEFAULT NULL,
      `scheduled_date` DATE DEFAULT NULL,
      `scheduled_time` TIME DEFAULT NULL,
      `amount`         DECIMAL(10,2) DEFAULT 0.00,
      `status`         ENUM('pending','scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
      `progress`       TINYINT UNSIGNED DEFAULT 0,
      `notes`          TEXT DEFAULT NULL,
      `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      FOREIGN KEY (`client_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`contractor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `messages` (
      `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `sender_id`   INT UNSIGNED NOT NULL,
      `receiver_id` INT UNSIGNED NOT NULL,
      `booking_id`  INT UNSIGNED DEFAULT NULL,
      `body`        TEXT NOT NULL,
      `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
      `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `reviews` (
      `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `booking_id`    INT UNSIGNED NOT NULL UNIQUE,
      `client_id`     INT UNSIGNED NOT NULL,
      `contractor_id` INT UNSIGNED NOT NULL,
      `rating`        TINYINT UNSIGNED NOT NULL,
      `comment`       TEXT DEFAULT NULL,
      `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`booking_id`)    REFERENCES `bookings`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`client_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`contractor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `activity_log` (
      `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `user_id`    INT UNSIGNED DEFAULT NULL,
      `action`     VARCHAR(120) NOT NULL,
      `detail`     VARCHAR(255) DEFAULT NULL,
      `icon`       VARCHAR(10)  DEFAULT '🔔',
      `color`      VARCHAR(30)  DEFAULT '#E6AF2E',
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `booking_sequence` (
      `id`    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `dummy` TINYINT DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // Split and run each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $created    = 0;
    foreach ($statements as $stmt) {
        if (!$stmt) continue;
        $db->exec($stmt);
        $created++;
    }

    // Ensure default admin exists
    $count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    if ($count === 0) {
        $hash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("INSERT IGNORE INTO users (name,email,password,role,status) VALUES (?,?,?,'admin','active')")
           ->execute(['Admin', 'admin@handyfix.com', $hash]);
    }

    jsonResponse(['success' => true, 'message' => "All tables created/verified. Default admin: admin@handyfix.com / Admin@123"]);
}

/* ─── Reset Default Admin ──────────────────────────────────── */
function resetAdmin(): void {
    $db   = getDB();
    $hash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $db->prepare("SELECT id FROM users WHERE role='admin' LIMIT 1");
    $stmt->execute();
    $existing = $stmt->fetch();

    if ($existing) {
        $db->prepare("UPDATE users SET password=?, status='active' WHERE id=?")
           ->execute([$hash, $existing['id']]);
    } else {
        $db->prepare("INSERT INTO users (name,email,password,role,status) VALUES ('Admin','admin@handyfix.com',?,'admin','active')")
           ->execute([$hash]);
    }
    jsonResponse(['success' => true]);
}

/* ─── Create User ──────────────────────────────────────────── */
function createUser(): void {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];

    $name  = trim($body['name']     ?? '');
    $email = trim($body['email']    ?? '');
    $pass  = trim($body['password'] ?? '');
    $phone = trim($body['phone']    ?? '');
    $role  = $body['role'] ?? 'admin';

    if (!$name || !$email || !$pass) {
        jsonResponse(['error' => 'Name, email, and password are required'], 422);
    }
    if (strlen($pass) < 8) {
        jsonResponse(['error' => 'Password must be at least 8 characters'], 422);
    }
    if (!in_array($role, ['admin', 'client', 'contractor'])) {
        jsonResponse(['error' => 'Invalid role'], 422);
    }

    $db = getDB();

    // Check duplicate
    $chk = $db->prepare("SELECT id FROM users WHERE email=?");
    $chk->execute([$email]);
    if ($chk->fetch()) jsonResponse(['error' => 'Email already registered'], 409);

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("INSERT INTO users (name,email,password,role,phone,status) VALUES (?,?,?,?,?,'active')")
       ->execute([$name, $email, $hash, $role, $phone ?: null]);

    $uid = (int)$db->lastInsertId();

    // Log activity
    try {
        $db->prepare("INSERT INTO activity_log (user_id,action,detail,icon,color) VALUES (?,?,?,?,?)")
           ->execute([$uid, 'New '.$role.' account created', $name.' ('.$email.')', '👤', '#E6AF2E']);
    } catch (\Exception $e) { /* activity_log might not exist yet */ }

    jsonResponse(['success' => true, 'user_id' => $uid]);
}

/* ─── List Users ───────────────────────────────────────────── */
function listUsers(): void {
    $db   = getDB();
    $rows = $db->query(
        "SELECT id, name, email, role, phone, status, created_at
         FROM users ORDER BY role ASC, created_at DESC LIMIT 100"
    )->fetchAll();
    jsonResponse(['users' => $rows]);
}

/* ─── Delete User ──────────────────────────────────────────── */
function deleteUser(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'id required'], 422);

    $db = getDB();

    // Protect: cannot delete if it's the only admin
    $role = $db->prepare("SELECT role FROM users WHERE id=?");
    $role->execute([$id]);
    $user = $role->fetch();

    if (!$user) jsonResponse(['error' => 'User not found'], 404);
    if ($user['role'] === 'admin') {
        $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        if ($adminCount <= 1) jsonResponse(['error' => 'Cannot delete the only admin account'], 403);
    }

    $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    jsonResponse(['success' => true]);
}