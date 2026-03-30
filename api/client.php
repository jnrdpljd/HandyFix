<?php
/* ============================================================
   HandyFix — Client API  (api/client.php)
   ============================================================ */
ob_start();
require_once __DIR__ . '/../includes/db.php';
startSecureSession();

// FIX: Using the correct authentication function from db.php
$u = requireAuth(); 
if ($u['role'] !== 'client') {
    sendJson(['error' => 'Unauthorized Access'], 403);
    exit;
}

$myId   = (int)$u['id'];
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$body   = readBody();

match (true) {
    $action === 'me'           && $method === 'GET'    => getMe($db, $myId),
    $action === 'stats'        && $method === 'GET'    => getStats($db, $myId),
    $action === 'notifications'&& $method === 'GET'    => getNotifications($db, $myId),
    $action === 'bookings'     && $method === 'GET'    => getBookings($db, $myId),
    $action === 'book'         && $method === 'POST'   => createBooking($db, $myId, $body),
    $action === 'cancel'       && $method === 'PUT'    => cancelBooking($db, $myId, $id),
    $action === 'addresses'    && $method === 'GET'    => getAddresses($db, $myId),
    $action === 'add_address'  && $method === 'POST'   => addAddress($db, $myId, $body),
    $action === 'del_address'  && $method === 'DELETE' => delAddress($db, $myId, $id),
    $action === 'review'       && $method === 'POST'   => submitReview($db, $myId, $body),
    $action === 'contractors'  && $method === 'GET'    => getTopContractors($db),
    $action === 'support'      && $method === 'POST'   => sendSupport($db, $myId, $body),
    $action === 'profile'      && $method === 'PUT'    => updateProfile($db, $myId, $body),
    $action === 'password'     && $method === 'PUT'    => updatePassword($db, $myId, $body),
    default => sendJson(['error' => 'Unknown action: '.$action], 400),
};

/* ─── Me ────────────────────────────────────────────────────── */
function getMe(PDO $db, int $uid): void {
    $stmt = $db->prepare(
        "SELECT id, name, email, phone, address, avatar, status, created_at FROM users WHERE id = ?"
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row) sendJson(['error' => 'User not found'], 404);
    
    if (function_exists('initials')) {
        $row['initials'] = initials($row['name']);
    } else {
        $parts = explode(' ', trim($row['name']));
        $row['initials'] = strtoupper(substr($parts[0] ?? 'U', 0, 1) . substr($parts[1] ?? '', 0, 1));
    }
    
    sendJson($row);
}

/* ─── Stats ─────────────────────────────────────────────────── */
function getStats(PDO $db, int $uid): void {
    $stmt = $db->prepare(
        "SELECT
           COUNT(*)                                                                AS total,
           SUM(status = 'completed')                                               AS completed,
           SUM(status = 'pending')                                                 AS pending,
           SUM(status IN ('scheduled','in_progress'))                              AS active,
           SUM(status = 'cancelled')                                               AS cancelled,
           COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END), 0)  AS total_spent
         FROM bookings WHERE client_id = ?"
    );
    $stmt->execute([$uid]);
    $r = $stmt->fetch();
    sendJson([
        'total'       => (int)$r['total'],
        'completed'   => (int)$r['completed'],
        'pending'     => (int)$r['pending'],
        'active'      => (int)$r['active'],
        'cancelled'   => (int)$r['cancelled'],
        'total_spent' => (float)$r['total_spent'],
    ]);
}

/* ─── Notifications ──────────────────────────────────────────── */
function getNotifications(PDO $db, int $uid): void {
    try {
        $stmt = $db->prepare(
            "SELECT action, detail, icon, color, created_at 
             FROM activity_log 
             WHERE user_id = ? 
             ORDER BY created_at DESC LIMIT 15"
        );
        $stmt->execute([$uid]);
        sendJson($stmt->fetchAll());
    } catch (PDOException $e) {
        sendJson([]); // Return empty if no logs exist yet
    }
}

/* ─── Bookings ──────────────────────────────────────────────── */
function getBookings(PDO $db, int $uid): void {
    $q      = '%' . ($_GET['q'] ?? '') . '%';
    $status = $_GET['status'] ?? '';

    $sql = "SELECT b.*,
                   u.name  AS contractor_name,
                   u.phone AS contractor_phone,
                   c.trade AS contractor_trade,
                   c.rating AS contractor_rating
            FROM bookings b
            LEFT JOIN users u       ON u.id = b.contractor_id
            LEFT JOIN contractors c ON c.user_id = b.contractor_id
            WHERE b.client_id = ?
              AND (b.service LIKE ? OR b.booking_ref LIKE ?)";
    $params = [$uid, $q, $q];

    if ($status) { $sql .= " AND b.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY b.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        // FIX: Only attempt to generate initials if a contractor actually exists!
        if (!empty($r['contractor_name'])) {
            $ini = function_exists('initials') ? initials($r['contractor_name']) : substr($r['contractor_name'], 0, 2);
            $r['contractor'] = [
                'name'     => $r['contractor_name'],
                'phone'    => $r['contractor_phone'],
                'trade'    => $r['contractor_trade'],
                'rating'   => $r['contractor_rating'],
                'initials' => strtoupper($ini),
            ];
        } else {
            $r['contractor'] = null;
        }
        
        // Clean up the raw flat data
        unset($r['contractor_name'], $r['contractor_phone'],
              $r['contractor_trade'], $r['contractor_rating']);
    }
    
    sendJson($rows);
}

/* ─── Create Booking ─────────────────────────────────────────── */
function createBooking(PDO $db, int $uid, array $b): void {
    $service = trim($b['service']      ?? '');
    $address = trim($b['address']      ?? '');
    $desc    = trim($b['description']  ?? ''); 
    $date    = trim($b['scheduled_date'] ?? '');
    $time    = trim($b['scheduled_time'] ?? '');

    if (!$service) sendJson(['error' => 'Service type is required'], 422);
    if (!$address) sendJson(['error' => 'Service address is required'], 422);

    $ref = function_exists('nextBookingRef') ? nextBookingRef() : 'BK-' . strtoupper(substr(uniqid(), -6));

    try {
        $db->prepare(
            "INSERT INTO bookings
               (booking_ref, client_id, service, description, address,
                scheduled_date, scheduled_time, amount, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'pending')"
        )->execute([
            $ref, $uid, $service, 
            $desc ?: null, // Strict null handling
            $address,
            $date ?: null, 
            $time ?: null,
        ]);

        $bkId = (int)$db->lastInsertId();

        if (function_exists('logActivity')) {
            logActivity($db, $uid, 'New booking request', $service, '📥', '#E6AF2E');
        }

        sendJson(['success' => true, 'booking_ref' => $ref, 'booking_id' => $bkId]);
    } catch (PDOException $e) {
        sendJson(['error' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ─── Cancel Booking ─────────────────────────────────────────── */
function cancelBooking(PDO $db, int $uid, int $id): void {
    $stmt = $db->prepare(
        "SELECT id, booking_ref, service, status FROM bookings WHERE id = ? AND client_id = ? LIMIT 1"
    );
    $stmt->execute([$id, $uid]);
    $bk = $stmt->fetch();

    if (!$bk) sendJson(['error' => 'Booking not found'], 404);
    if (!in_array($bk['status'], ['pending', 'scheduled'])) {
        sendJson(['error' => 'Only pending or scheduled bookings can be cancelled'], 422);
    }

    $db->prepare("UPDATE bookings SET status='cancelled', updated_at=NOW() WHERE id=?")
       ->execute([$id]);

    if (function_exists('logActivity')) {
        logActivity($db, $uid, 'Booking cancelled', $bk['service'].' ('.$bk['booking_ref'].')', '❌', '#ef4444');
    }

    sendJson(['success' => true]);
}

/* ─── Addresses ─────────────────────────────────────────────── */
function getAddresses(PDO $db, int $uid): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `client_addresses` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `client_id`  INT UNSIGNED NOT NULL,
        `label`      VARCHAR(50)  NOT NULL DEFAULT 'Home',
        `address`    VARCHAR(255) NOT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`client_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $db->prepare(
        "SELECT id, label, address, created_at FROM client_addresses WHERE client_id = ? ORDER BY id ASC"
    );
    $stmt->execute([$uid]);
    sendJson($stmt->fetchAll());
}

function addAddress(PDO $db, int $uid, array $b): void {
    $label   = trim($b['label']   ?? 'Home');
    $address = trim($b['address'] ?? '');
    if (!$address) sendJson(['error' => 'Address is required'], 422);

    $db->exec("CREATE TABLE IF NOT EXISTS `client_addresses` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `client_id`  INT UNSIGNED NOT NULL,
        `label`      VARCHAR(50)  NOT NULL DEFAULT 'Home',
        `address`    VARCHAR(255) NOT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`client_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->prepare("INSERT INTO client_addresses (client_id, label, address) VALUES (?,?,?)")
       ->execute([$uid, $label, $address]);

    sendJson(['success' => true, 'id' => (int)$db->lastInsertId()]);
}

function delAddress(PDO $db, int $uid, int $id): void {
    if (!$id) sendJson(['error' => 'ID required'], 422);
    $db->prepare("DELETE FROM client_addresses WHERE id=? AND client_id=?")->execute([$id, $uid]);
    sendJson(['success' => true]);
}

/* ─── Review ────────────────────────────────────────────────── */
function submitReview(PDO $db, int $uid, array $b): void {
    $bookingId = (int)($b['booking_id'] ?? 0);
    $rating    = (int)($b['rating']    ?? 0);
    $comment   = trim($b['comment']    ?? '');

    if (!$bookingId || $rating < 1 || $rating > 5)
        sendJson(['error' => 'Invalid review data'], 422);

    $stmt = $db->prepare(
        "SELECT contractor_id FROM bookings WHERE id=? AND client_id=? AND status='completed' LIMIT 1"
    );
    $stmt->execute([$bookingId, $uid]);
    $bk = $stmt->fetch();
    if (!$bk || !$bk['contractor_id'])
        sendJson(['error' => 'Booking not found or not completed'], 404);

    $dup = $db->prepare("SELECT id FROM reviews WHERE booking_id=?");
    $dup->execute([$bookingId]);
    if ($dup->fetch()) sendJson(['error' => 'Review already submitted'], 409);

    $db->prepare("INSERT INTO reviews (booking_id, client_id, contractor_id, rating, comment) VALUES (?,?,?,?,?)")
       ->execute([$bookingId, $uid, $bk['contractor_id'], $rating, $comment]);

    $db->prepare(
        "UPDATE contractors
         SET rating = (SELECT AVG(rating) FROM reviews WHERE contractor_id=?),
             total_reviews = (SELECT COUNT(*) FROM reviews WHERE contractor_id=?)
         WHERE user_id=?"
    )->execute([$bk['contractor_id'], $bk['contractor_id'], $bk['contractor_id']]);

    sendJson(['success' => true]);
}

/* ─── Top Contractors ────────────────────────────────────────── */
function getTopContractors(PDO $db): void {
    $stmt = $db->query(
        "SELECT u.name, c.trade, c.rating, c.total_reviews, c.jobs_completed, c.availability
         FROM users u JOIN contractors c ON c.user_id = u.id
         WHERE u.status='active' AND c.verified=1
         ORDER BY c.rating DESC, c.jobs_completed DESC LIMIT 6"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['initials'] = function_exists('initials') ? initials($r['name']) : strtoupper(substr($r['name'],0,2));
    }
    sendJson($rows);
}

/* ─── Support ────────────────────────────────────────────────── */
function sendSupport(PDO $db, int $uid, array $b): void {
    $subject = trim($b['subject'] ?? 'General');
    $message = trim($b['message'] ?? '');
    if (!$message) sendJson(['error' => 'Message is required'], 422);

    if (function_exists('logActivity')) {
        logActivity($db, $uid, 'Support: '.$subject, mb_substr($message, 0, 80), '💬', '#818cf8');
    }
    sendJson(['success' => true]);
}

/* ─── Profile / Passwords ────────────────────────────────────── */
function updateProfile(PDO $db, int $uid, array $b): void {
    $name = trim($b['name'] ?? '');
    $phone = trim($b['phone'] ?? '');
    $address = trim($b['address'] ?? '');
    if (!$name) sendJson(['error' => 'Name is required'], 422);
    
    $db->prepare("UPDATE users SET name=?, phone=?, address=? WHERE id=?")
       ->execute([$name, $phone, $address, $uid]);
    sendJson(['success' => true]);
}

function updatePassword(PDO $db, int $uid, array $b): void {
    $new = $b['new_password'] ?? '';
    if (strlen($new) < 8) sendJson(['error' => 'Password must be at least 8 characters'], 422);
    
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $uid]);
    sendJson(['success' => true]);
}