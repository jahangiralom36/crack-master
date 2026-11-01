<?php
// reseller_api.php এ সেশন লাগে না, তাই ওখানে CM_NO_SESSION define করা হয়
if (!defined('CM_NO_SESSION') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ডাটাবেজ কানেকশন সেটিংস
define('DB_HOST', 'localhost');
define('DB_USER', 'crackmas12_rakibs');
define('DB_PASS', 'crackmas12_rakibs');
define('DB_NAME', 'crackmas12_rakibs');

// অ্যাডমিন প্যানেল মাস্টার লগইন সেটিংস
define('ADMIN_USER', 'Adminrakibs');
define('ADMIN_PASS', 'rakibs123');

// টাইমজোন নির্ধারণ
date_default_timezone_set('Asia/Dhaka');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die(json_encode([
        "status"  => "error", 
        "message" => "Database Connection Failed: " . $e->getMessage()
    ]));
}

// ইনপুট স্যানিটাইজার
function cleanInput($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

// সেশন অথেনটিকেশন চেক
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// ------------------------------------------------------------------
// ইউজার লগআউট (আগের logout.php এখন dashboard.php ও index.php এর ভিতরে)
// অ্যাডমিন সেশন (is_admin) আলাদা থাকে, তাই ক্লায়েন্ট লগআউটে অ্যাডমিন লগআউট হয় না।
// ------------------------------------------------------------------
function doUserLogout() {
    unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['flash'], $_SESSION['cm_schema_v3']);
    if (empty($_SESSION['is_admin'])) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    } else {
        session_regenerate_id(true);
    }
}

// ------------------------------------------------------------------
// গেম ক্যাটালগ (২ ধরনের গেম: FreeFire ও PUBG) - অ্যাডমিন ও ড্যাশবোর্ড দুই জায়গায় একই
// ------------------------------------------------------------------
function gameCatalog() {
    return [
        'FreeFire' => ['label' => 'FreeFire', 'short' => 'FF',   'prefix' => 'FF',   'icon' => 'fa-fire',       'color' => '#ff6b6b', 'emoji' => '🔥'],
        'Pubg'     => ['label' => 'PUBG',     'short' => 'PUBG', 'prefix' => 'PUBG', 'icon' => 'fa-crosshairs', 'color' => '#fbbf24', 'emoji' => '🎯'],
    ];
}

function normalizeGame($g) {
    $cat = gameCatalog();
    return isset($cat[$g]) ? $g : 'FreeFire';
}

// ------------------------------------------------------------------
// Settings helper + Daily Reset Limit (অ্যাডমিন প্যানেল থেকে সেট হয়)
// 0 = Unlimited, ডিফল্ট = 3
// ------------------------------------------------------------------
function getSetting(PDO $pdo, $key, $default = null) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false) ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function getResetLimit(PDO $pdo) {
    return max(0, (int)getSetting($pdo, 'daily_reset_limit', '3'));
}

function resetsUsedToday(array $l, $today = null) {
    $today = $today ?: date('Y-m-d');
    return (($l['last_reset_date'] ?? null) === $today) ? (int)($l['daily_reset_count'] ?? 0) : 0;
}

// ------------------------------------------------------------------
// Safe auto-migration (সেশনে একবার চলে, HTTP 500 এড়াতে সব try/catch এর ভিতরে)
// ------------------------------------------------------------------
function ensureSchema(PDO $pdo) {
    if (!empty($_SESSION['cm_schema_v3'])) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key` VARCHAR(50) PRIMARY KEY,
            `setting_value` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `reset_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_key` VARCHAR(100) NOT NULL,
            `user_id` INT NOT NULL,
            `ip_address` VARCHAR(64) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    $wanted = [
        'users' => [
            'can_deposit' => "TINYINT(1) NOT NULL DEFAULT 1",
            'api_key'     => "VARCHAR(64) DEFAULT NULL",
        ],
        'plans' => [
            'duration_type' => "ENUM('days', 'hours') NOT NULL DEFAULT 'days'",
            'game_type'     => "VARCHAR(20) NOT NULL DEFAULT 'FreeFire'",
        ],
        'licenses' => [
            'duration_type'     => "ENUM('days', 'hours') NOT NULL DEFAULT 'days'",
            'game_type'         => "VARCHAR(20) NOT NULL DEFAULT 'FreeFire'",
            'is_deleted'        => "TINYINT(1) NOT NULL DEFAULT 0",
            'reset_count'       => "INT NOT NULL DEFAULT 0",
            'daily_reset_count' => "INT NOT NULL DEFAULT 0",
            'last_reset_date'   => "DATE DEFAULT NULL",
        ],
    ];

    foreach ($wanted as $table => $cols) {
        try {
            $existing = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cols as $name => $def) {
                if (!in_array($name, $existing, true)) {
                    try { $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$def}"); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}
    }

    try {
        $has = $pdo->query("SHOW INDEX FROM `users` WHERE Key_name = 'uniq_api_key'")->fetch();
        if (!$has) $pdo->exec("ALTER TABLE `users` ADD UNIQUE INDEX `uniq_api_key` (`api_key`)");
    } catch (Throwable $e) {}

    $_SESSION['cm_schema_v3'] = 1;
}

// ------------------------------------------------------------------
// HWID RESET (ড্যাশবোর্ড + API দুই জায়গায় একই লজিক)
// - শুধু key এর মালিক রিসেট করতে পারবে
// - প্রতিদিন প্রতি key তে সর্বোচ্চ $limit বার (0 = unlimited)
// - UPDATE টা atomic, তাই একসাথে অনেক রিকোয়েস্ট দিয়েও limit পার করা যাবে না
// ------------------------------------------------------------------
function resetLicenseHwid(PDO $pdo, $userId, $licenseId, $limit) {
    $today = date('Y-m-d');

    $st = $pdo->prepare("SELECT * FROM licenses WHERE id = ? AND user_id = ? AND is_deleted = 0 LIMIT 1");
    $st->execute([(int)$licenseId, (int)$userId]);
    $k = $st->fetch();

    if (!$k) {
        return ['ok' => false, 'code' => 'KEY_NOT_FOUND', 'message' => 'Key not found or unauthorized!'];
    }
    if (empty($k['device_id'])) {
        return ['ok' => false, 'code' => 'NO_DEVICE_BOUND', 'message' => 'This key has no device bound, nothing to reset.', 'license' => $k];
    }

    $used = resetsUsedToday($k, $today);
    if ($limit > 0 && $used >= $limit) {
        return ['ok' => false, 'code' => 'DAILY_LIMIT_REACHED', 'message' => "Daily reset limit reached! You can reset this key a maximum of {$limit} times per day.", 'used' => $used, 'limit' => $limit, 'remaining' => 0];
    }

    $upd = $pdo->prepare("UPDATE licenses
        SET device_id = NULL,
            reset_count = reset_count + 1,
            daily_reset_count = IF(last_reset_date = ?, daily_reset_count, 0) + 1,
            last_reset_date = ?
        WHERE id = ? AND user_id = ? AND device_id IS NOT NULL AND device_id <> ''
          AND (? = 0 OR last_reset_date IS NULL OR last_reset_date <> ? OR daily_reset_count < ?)");
    $upd->execute([$today, $today, (int)$licenseId, (int)$userId, (int)$limit, $today, (int)$limit]);

    if ($upd->rowCount() < 1) {
        return ['ok' => false, 'code' => 'DAILY_LIMIT_REACHED', 'message' => "Daily reset limit reached! You can reset this key a maximum of {$limit} times per day.", 'used' => $used, 'limit' => $limit, 'remaining' => 0];
    }

    try {
        $pdo->prepare("INSERT INTO reset_logs (license_key, user_id, ip_address) VALUES (?, ?, ?)")
            ->execute([$k['license_key'], (int)$userId, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    } catch (Throwable $e) {}

    $newUsed   = $used + 1;
    $remaining = ($limit > 0) ? max(0, $limit - $newUsed) : null;
    return ['ok' => true, 'code' => 'OK', 'message' => 'Device HWID reset successfully!', 'license_key' => $k['license_key'], 'used' => $newUsed, 'limit' => $limit, 'remaining' => $remaining];
}
