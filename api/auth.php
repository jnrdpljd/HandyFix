<?php
/* ============================================================
   HandyFix — Auth API  (api/auth.php)
   Universal login — role determined by DB record, not by user.
   POST ?action=login    { email, password }
   POST ?action=logout
   GET  ?action=me
   POST ?action=register { name, email, password, phone }
   PUT  ?action=profile  { name, phone, address }   (any role)
   PUT  ?action=password { current_password, new_password }
   ============================================================ */
ob_start();
require_once __DIR__ . '/../includes/db.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = readBody();

match (true) {
    $action === 'login'    && $method === 'POST' => doLogin($body),
    $action === 'logout'   && $method === 'POST' => doLogout(),
    $action === 'me'       && $method === 'GET'  => doMe(),
    $action === 'register' && $method === 'POST' => doRegister($body),
    $action === 'profile'  && $method === 'PUT'  => doProfileUpdate($body),
    $action === 'password' && $method === 'PUT'  => doPasswordChange($body),
    default => sendJson(['error' => 'Unknown action: '.$action], 400),
};

/* ─── Login ────────────────────────────────────────────────── */
function doLogin(array $b): void {
    $email = strtolower(trim($b['email']    ?? ''));
    $pass  =            trim($b['password'] ?? '');
    if (!$email || !$pass) sendJson(['error' => 'Email and password are required'], 422);

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($pass, $user['password'])) {
        sendJson(['error' => 'Invalid email or password'], 401);
    }

    $sess = buildSession($user);
    $_SESSION['hf_user'] = $sess;

    sendJson([
        'success'  => true,
        'user'     => $sess,
        'role'     => $user['role'],
        'redirect' => redirectFor($user['role']),
    ]);
}

/* ─── Logout ───────────────────────────────────────────────── */
function doLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    sendJson(['success' => true]);
}

/* ─── Me ────────────────────────────────────────────────────── */
function doMe(): void {
    $u = currentUser();
    if (!$u) sendJson(['error' => 'Not authenticated'], 401);

    // Refresh from DB so profile changes are reflected
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$u['id']]);
    $row  = $stmt->fetch();
    if (!$row) sendJson(['error' => 'User not found'], 404);

    $sess = buildSession($row);
    $_SESSION['hf_user'] = $sess;
    sendJson(['user' => $sess]);
}

/* ─── Register (clients only) ──────────────────────────────── */
function doRegister(array $b): void {
    $name  = trim($b['name']     ?? '');
    $email = strtolower(trim($b['email']    ?? ''));
    $pass  = trim($b['password'] ?? '');
    $phone = trim($b['phone']    ?? '');

    if (!$name || !$email || !$pass)
        sendJson(['error' => 'Name, email and password are required'], 422);
    if (strlen($pass) < 8)
        sendJson(['error' => 'Password must be at least 8 characters'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        sendJson(['error' => 'Invalid email address'], 422);

    $db  = getDB();
    $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
    $chk->execute([$email]);
    if ($chk->fetch()) sendJson(['error' => 'This email is already registered'], 409);

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare(
        "INSERT INTO users (name, email, password, role, phone, status) VALUES (?,?,?,'client',?,'active')"
    )->execute([$name, $email, $hash, $phone ?: null]);

    $uid  = (int)$db->lastInsertId();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $row  = $stmt->fetch();

    $sess = buildSession($row);
    $_SESSION['hf_user'] = $sess;

    logActivity($db, $uid, 'New client registered', $name.' ('.$email.')', '👤', '#818cf8');

    sendJson(['success' => true, 'user' => $sess, 'redirect' => 'client_home.html']);
}

/* ─── Profile update (any authenticated user) ─────────────── */
function doProfileUpdate(array $b): void {
    $u = requireAuth();
    $db = getDB();

    $name    = trim($b['name']    ?? $u['name']);
    $phone   = trim($b['phone']   ?? '');
    $address = trim($b['address'] ?? '');

    if (!$name) sendJson(['error' => 'Name is required'], 422);

    $db->prepare("UPDATE users SET name=?, phone=?, address=?, updated_at=NOW() WHERE id=?")
       ->execute([$name, $phone ?: null, $address ?: null, $u['id']]);

    // Refresh session
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$u['id']]);
    $row  = $stmt->fetch();
    $_SESSION['hf_user'] = buildSession($row);

    sendJson(['success' => true, 'user' => $_SESSION['hf_user']]);
}

/* ─── Password change (any authenticated user) ─────────────── */
function doPasswordChange(array $b): void {
    $u   = requireAuth();
    $db  = getDB();
    $cur = $b['current_password'] ?? '';
    $new = $b['new_password']     ?? '';

    if (!$cur || !$new) sendJson(['error' => 'Both passwords are required'], 422);
    if (strlen($new) < 8) sendJson(['error' => 'Password must be at least 8 characters'], 422);

    $stmt = $db->prepare("SELECT password FROM users WHERE id=?");
    $stmt->execute([$u['id']]);
    $row  = $stmt->fetch();

    if (!$row || !password_verify($cur, $row['password']))
        sendJson(['error' => 'Current password is incorrect'], 401);

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=?")
       ->execute([$hash, $u['id']]);

    sendJson(['success' => true]);
}

/* ─── Helpers ───────────────────────────────────────────────── */
function buildSession(array $row): array {
    return [
        'id'       => (int)$row['id'],
        'name'     => $row['name'],
        'email'    => $row['email'],
        'role'     => $row['role'],
        'phone'    => $row['phone'] ?? null,
        'address'  => $row['address'] ?? null,
        'avatar'   => $row['avatar'] ?? null,
        'status'   => $row['status'],
        'initials' => initials($row['name']),
    ];
}

function redirectFor(string $role): string {
    return match($role) {
        'admin'      => 'admin_dashboard.html',
        'contractor' => 'contractor_dashboard.html',
        default      => 'client_home.html',
    };
}
