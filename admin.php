<?php
require_once 'config.php';

// Safe Schema Auto-Migration (HTTP 500 Error Prevention)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `setting_key` VARCHAR(50) PRIMARY KEY,
        `setting_value` TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `deposits` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `method` VARCHAR(50) NOT NULL,
        `amount` DECIMAL(10, 2) NOT NULL,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'BDT',
        `trx_id` VARCHAR(100) NOT NULL UNIQUE,
        `sender_number` VARCHAR(100) DEFAULT NULL,
        `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // GitHub Gist Style Storage Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `gists` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `gist_uid` VARCHAR(64) NOT NULL UNIQUE,
        `title` VARCHAR(150) NOT NULL,
        `extension` VARCHAR(20) NOT NULL DEFAULT 'txt',
        `content` LONGTEXT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Safe Column Checks
    $safeCols = [
        'plans' => [
            'duration_type' => "ENUM('days', 'hours') NOT NULL DEFAULT 'days'",
            'game_type' => "VARCHAR(20) NOT NULL DEFAULT 'FreeFire'"
        ],
        'licenses' => [
            'is_paused' => "TINYINT(1) NOT NULL DEFAULT 0",
            'remaining_seconds' => "INT NOT NULL DEFAULT 0",
            'is_deleted' => "TINYINT(1) NOT NULL DEFAULT 0",
            'is_banned' => "TINYINT(1) NOT NULL DEFAULT 0",
            'duration_type' => "ENUM('days', 'hours') NOT NULL DEFAULT 'days'",
            'max_devices' => "INT NOT NULL DEFAULT 1",
            'game_type' => "VARCHAR(20) NOT NULL DEFAULT 'FreeFire'"
        ]
    ];

    foreach ($safeCols as $table => $cols) {
        $existing = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $colName => $colDef) {
            if (!in_array($colName, $existing)) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$colName}` {$colDef}");
            }
        }
    }

    try {
        $pdo->exec("ALTER TABLE `licenses` MODIFY COLUMN `device_id` TEXT NULL");
    } catch (Throwable $t) {}

    // Cross-login prevention: Ensure a user_key can only be bound to ONE game
    // This is enforced in API, but we add a unique index hint on game_type+license_key combo check
    try {
        $pdo->exec("ALTER TABLE `licenses` ADD INDEX `idx_game_key` (`game_type`, `license_key`)");
    } catch (Throwable $t) {}

} catch (Throwable $e) {}

// Shared schema (users.api_key, licenses.daily_reset_count/last_reset_date, reset_logs ...)
ensureSchema($pdo);

// GitHub-Style RAW File Streamer (Uploads & Gists)
if (isset($_GET['action']) && $_GET['action'] === 'raw_file' && !empty($_GET['file'])) {
    $targetFile = basename($_GET['file']);
    $uploadPath = __DIR__ . '/uploads/' . $targetFile;

    if (file_exists($uploadPath) && is_file($uploadPath)) {
        $mimeType = mime_content_type($uploadPath) ?: 'application/octet-stream';
        $forceDownload = isset($_GET['download']) && $_GET['download'] === '1';

        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($forceDownload ? 'application/octet-stream' : $mimeType));
        header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $targetFile . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($uploadPath));
        readfile($uploadPath);
        exit();
    } else {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "404 Not Found: Raw file does not exist on server.";
        exit();
    }
}

// GitHub Gist RAW Output Handler
if (isset($_GET['action']) && $_GET['action'] === 'raw_gist' && !empty($_GET['id'])) {
    $gistId = cleanInput($_GET['id']);
    $stmtGist = $pdo->prepare("SELECT * FROM gists WHERE gist_uid = ? LIMIT 1");
    $stmtGist->execute([$gistId]);
    $gistItem = $stmtGist->fetch();

    if ($gistItem) {
        $forceDownload = isset($_GET['download']) && $_GET['download'] === '1';
        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $gistItem['title']) . '.' . $gistItem['extension'];

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        echo $gistItem['content'];
        exit();
    } else {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "404 Not Found: Gist does not exist.";
        exit();
    }
}

$login_error = '';
$msg = '';
$error = '';

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['is_admin']);
    header("Location: admin.php");
    exit();
}

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_auth') {
    $in_user = cleanInput($_POST['admin_user'] ?? '');
    $in_pass = cleanInput($_POST['admin_pass'] ?? '');
    if ($in_user === ADMIN_USER && $in_pass === ADMIN_PASS) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        header("Location: admin.php");
        exit();
    } else {
        $login_error = "Invalid Administrator credentials!";
    }
}

if (empty($_SESSION['is_admin'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
    <title>Admin Login - CrackMaster</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; touch-action:manipulation; }
        body { background:#070d1a; color:#cbd5e1; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:15px; }
        input, button { font-size:16px !important; outline:none; }
        .auth-card { width:100%; max-width:360px; background:#0e172a; border:1px solid #1e293b; border-radius:22px; padding:32px 24px; text-align:center; box-shadow:0 15px 35px rgba(0,0,0,0.6); }
        .auth-card i { font-size:40px; color:#38bdf8; margin-bottom:14px; }
        .auth-card h2 { font-size:20px; font-weight:800; color:#fff; margin-bottom:20px; letter-spacing:0.5px; }
        .form-group { margin-bottom:15px; text-align:left; }
        .form-group label { display:block; font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; margin-bottom:6px; letter-spacing:0.5px; }
        .form-group input { width:100%; background:#070d1a; border:1px solid #1e293b; border-radius:10px; padding:12px 14px; color:#fff; }
        .form-group input:focus { border-color:#38bdf8; }
        .btn-auth { width:100%; background:linear-gradient(135deg, #0284c7, #2563eb); border:none; padding:13px; border-radius:10px; color:#fff; font-weight:800; cursor:pointer; box-shadow:0 4px 15px rgba(2,132,199,0.3); }
        .alert { background:rgba(239,68,68,0.15); color:#fca5a5; padding:10px; border-radius:8px; font-size:12px; margin-bottom:15px; border:1px solid rgba(239,68,68,0.3); }
    </style>
</head>
<body>
    <div class="auth-card">
        <i class="fa-solid fa-user-shield"></i>
        <h2>CRACKMASTER ADMIN</h2>
        <?php if (!empty($login_error)): ?><div class="alert"><?= $login_error; ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="admin_auth">
            <div class="form-group">
                <label>Admin Username</label>
                <input type="text" name="admin_user" placeholder="Enter username" required autofocus>
            </div>
            <div class="form-group">
                <label>Admin Password</label>
                <input type="password" name="admin_pass" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn-auth">Sign In to Dashboard</button>
        </form>
    </div>
</body>
</html>
<?php exit(); endif;

// Flash message (action শেষে redirect হয়, যাতে refresh দিলে একই কাজ দ্বিতীয়বার না চলে)
if (!empty($_SESSION['admin_flash'])) {
    $msg   = $_SESSION['admin_flash']['msg'] ?? '';
    $error = $_SESSION['admin_flash']['error'] ?? '';
    unset($_SESSION['admin_flash']);
}

// Actions Handler (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_key_admin') {
        $k_type      = cleanInput($_POST['key_type'] ?? 'auto');
        $dur         = (int)($_POST['duration_value'] ?? 24);
        $unit        = cleanInput($_POST['duration_type'] ?? 'days');
        $custom      = cleanInput($_POST['custom_key'] ?? '');
        $max_devices = (int)($_POST['max_devices'] ?? 1);
        $game_type   = cleanInput($_POST['game_type'] ?? 'FreeFire');
        
        // Validate game type
        if (!in_array($game_type, ['FreeFire', 'Pubg'])) {
            $game_type = 'FreeFire';
        }
        
        $gamePrefix = ($game_type === 'FreeFire') ? 'FF' : 'PUBG';
        $final = ($k_type === 'custom' && !empty($custom)) ? $custom : $gamePrefix . "-" . strtoupper(bin2hex(random_bytes(3))) . "-" . strtoupper(bin2hex(random_bytes(3)));

        try {
            $pdo->prepare("INSERT INTO licenses (user_id, license_key, duration_days, duration_type, max_devices, status, game_type) VALUES (NULL, ?, ?, ?, ?, 'unused', ?)")
                ->execute([$final, $dur, $unit, $max_devices, $game_type]);
            $devText = ($max_devices === 0) ? "Unlimited" : $max_devices;
            $msg = "Generated <b>{$game_type}</b> Key: <b>{$final}</b> (Max Devices: <b>{$devText}</b>)";
        } catch (PDOException $e) { $error = "Key already exists!"; }
    }

    if ($_POST['action'] === 'add_balance') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $amt = (float)($_POST['amount'] ?? 0);
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$amt, $uid]);
        $msg = "Balance updated successfully by \${$amt}!";
    }

    if ($_POST['action'] === 'create_user') {
        $u = cleanInput($_POST['username'] ?? '');
        $e = cleanInput($_POST['email'] ?? '');
        $p = cleanInput($_POST['password'] ?? '');
        $b = (float)($_POST['balance'] ?? 0);
        $r = cleanInput($_POST['role'] ?? 'user');
        if (!empty($u) && !empty($e) && !empty($p)) {
            try {
                $h = password_hash($p, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO users (username, email, password, balance, role) VALUES (?, ?, ?, ?, ?)")->execute([$u, $e, $h, $b, $r]);
                $msg = "Created account '{$u}' [".strtoupper($r)."]!";
            } catch (PDOException $ex) { $error = "User or email already exists!"; }
        }
    }

    if ($_POST['action'] === 'save_settings') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach (['deposit_enabled', 'deposit_mode', 'firebase_url', 'min_deposit', 'max_deposit', 'bkash_number', 'nagad_number', 'rocket_number', 'binance_address'] as $k) {
            $v = cleanInput($_POST[$k] ?? '');
            $stmt->execute([$k, $v, $v]);
        }
        $msg = "Payment settings updated successfully!";
    }

    if ($_POST['action'] === 'save_api_settings') {
        $lim = max(0, min(1000, (int)($_POST['daily_reset_limit'] ?? 3)));
        $en  = (($_POST['api_enabled'] ?? '1') === '0') ? '0' : '1';
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute(['daily_reset_limit', (string)$lim, (string)$lim]);
        $stmt->execute(['api_enabled', $en, $en]);
        $msg = "API & Reset settings saved! Daily reset limit: <b>" . ($lim === 0 ? 'Unlimited' : $lim . ' per key/day') . "</b>, API: <b>" . ($en === '1' ? 'Enabled' : 'Disabled') . "</b>";
    }

    if ($_POST['action'] === 'add_plan') {
        $pn = cleanInput($_POST['plan_name'] ?? '');
        $pv = (int)($_POST['duration_value'] ?? 1);
        $pt = cleanInput($_POST['duration_type'] ?? 'days');
        $pr = (float)($_POST['price'] ?? 0);
        $pc = cleanInput($_POST['currency'] ?? 'BDT');
        $gt = cleanInput($_POST['game_type'] ?? 'FreeFire');
        
        if (!in_array($gt, ['FreeFire', 'Pubg'])) {
            $gt = 'FreeFire';
        }
        
        if (!empty($pn) && $pv > 0) {
            $pdo->prepare("INSERT INTO plans (name, duration_days, duration_type, price, currency, game_type) VALUES (?, ?, ?, ?, ?, ?)")->execute([$pn, $pv, $pt, $pr, $pc, $gt]);
            $msg = "<b>{$gt}</b> Plan '{$pn}' added successfully!";
        }
    }

    // GitHub-Style File Upload Handler (WITH ORIGINAL FILENAME PRESERVED)
    if ($_POST['action'] === 'upload_file') {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        if (isset($_FILES['uploaded_file']) && $_FILES['uploaded_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['uploaded_file']['tmp_name'];
            $fileNameOriginal = basename($_FILES['uploaded_file']['name']);
            $fileSize = $_FILES['uploaded_file']['size'];
            $fileExtension = strtolower(pathinfo($fileNameOriginal, PATHINFO_EXTENSION));

            $disallowed = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phar', 'phps', 'inc', 'shtml', 'htaccess', 'cgi', 'pl', 'sh', 'bat'];
            if (in_array($fileExtension, $disallowed)) {
                $error = "Security Block: Executable script files (.{$fileExtension}) are strictly forbidden!";
            } else {
                // PRESERVE ORIGINAL FILENAME - Only sanitize special characters, NO timestamp append
                $cleanBase = preg_replace("/[^a-zA-Z0-9_\-\.]/", "_", pathinfo($fileNameOriginal, PATHINFO_FILENAME));
                $newFileName = $cleanBase . '.' . $fileExtension;
                
                // If file already exists, add a counter suffix (NOT timestamp)
                $counter = 1;
                $finalFileName = $newFileName;
                while (file_exists($uploadDir . $finalFileName)) {
                    $finalFileName = $cleanBase . '_' . $counter . '.' . $fileExtension;
                    $counter++;
                }
                
                $destPath = $uploadDir . $finalFileName;

                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $rawUrl = "admin.php?action=raw_file&file=" . urlencode($finalFileName);
                    $msg = "File uploaded successfully! Raw URL: <a href='{$rawUrl}' target='_blank' style='color:#38bdf8; text-decoration:underline;'>{$finalFileName}</a>";
                } else {
                    $error = "Failed to store file. Please ensure /uploads/ directory has write permissions!";
                }
            }
        } else {
            $error = "Please choose a valid file to upload!";
        }
    }

    // GitHub Gist Creator Handler
    if ($_POST['action'] === 'create_gist') {
        $gistTitle = cleanInput($_POST['gist_title'] ?? 'untitled');
        $gistExt   = strtolower(cleanInput($_POST['gist_extension'] ?? 'txt'));
        $gistBody  = $_POST['gist_content'] ?? '';

        $disallowed = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'cgi'];
        if (in_array($gistExt, $disallowed)) {
            $gistExt = 'txt';
        }

        if (!empty($gistBody)) {
            $gistUid = bin2hex(random_bytes(10));
            try {
                $pdo->prepare("INSERT INTO gists (gist_uid, title, extension, content) VALUES (?, ?, ?, ?)")
                    ->execute([$gistUid, $gistTitle, $gistExt, $gistBody]);
                $rawLink = "admin.php?action=raw_gist&id=" . $gistUid;
                $msg = "Gist saved successfully! Raw URL: <a href='{$rawLink}' target='_blank' style='color:#38bdf8; text-decoration:underline;'>{$gistTitle}.{$gistExt}</a>";
            } catch (PDOException $ge) {
                $error = "Could not create Gist: " . $ge->getMessage();
            }
        } else {
            $error = "Gist content cannot be blank!";
        }
    }

    // Gist Edit Handler - RAW URL STAYS THE SAME (gist_uid unchanged)
    if ($_POST['action'] === 'edit_gist') {
        $gistId    = (int)($_POST['gist_id'] ?? 0);
        $gistTitle = cleanInput($_POST['gist_title'] ?? 'untitled');
        $gistExt   = strtolower(cleanInput($_POST['gist_extension'] ?? 'txt'));
        $gistBody  = $_POST['gist_content'] ?? '';

        $disallowed = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'cgi'];
        if (in_array($gistExt, $disallowed)) {
            $gistExt = 'txt';
        }

        if ($gistId > 0 && !empty($gistBody)) {
            try {
                // Update content but keep gist_uid same => RAW URL unchanged
                $pdo->prepare("UPDATE gists SET title = ?, extension = ?, content = ? WHERE id = ?")
                    ->execute([$gistTitle, $gistExt, $gistBody, $gistId]);
                
                $stmt = $pdo->prepare("SELECT gist_uid FROM gists WHERE id = ?");
                $stmt->execute([$gistId]);
                $row = $stmt->fetch();
                $rawLink = "admin.php?action=raw_gist&id=" . $row['gist_uid'];
                $msg = "Gist updated! Raw URL unchanged: <a href='{$rawLink}' target='_blank' style='color:#38bdf8; text-decoration:underline;'>{$gistTitle}.{$gistExt}</a>";
            } catch (PDOException $ge) {
                $error = "Could not update Gist: " . $ge->getMessage();
            }
        } else {
            $error = "Gist content cannot be blank!";
        }
    }

    // File Edit Handler - Overwrite existing file content
    if ($_POST['action'] === 'edit_file_content') {
        $fileName = basename(cleanInput($_POST['file_name'] ?? ''));
        $newContent = $_POST['file_content'] ?? '';
        $uploadDir = __DIR__ . '/uploads/';
        $filePath = $uploadDir . $fileName;

        if (!empty($fileName) && file_exists($filePath) && is_file($filePath)) {
            if (file_put_contents($filePath, $newContent) !== false) {
                $rawUrl = "admin.php?action=raw_file&file=" . urlencode($fileName);
                $msg = "File '<b>{$fileName}</b>' updated! Raw URL unchanged: <a href='{$rawUrl}' target='_blank' style='color:#38bdf8; text-decoration:underline;'>{$fileName}</a>";
            } else {
                $error = "Could not write to file. Check permissions!";
            }
        } else {
            $error = "File not found!";
        }
    }
}

// GET Handlers
if (isset($_GET['action'])) {
    $act = $_GET['action'];
    $id  = (int)($_GET['id'] ?? 0);
    $uid = (int)($_GET['uid'] ?? 0);

    if ($act === 'pause_key' && $id > 0) {
        $k = $pdo->query("SELECT * FROM licenses WHERE id = {$id}")->fetch();
        if ($k && $k['status'] === 'active' && !empty($k['expires_at'])) {
            $rem = max(0, strtotime($k['expires_at']) - time());
            $pdo->prepare("UPDATE licenses SET is_paused = 1, remaining_seconds = ? WHERE id = ?")->execute([$rem, $id]);
            $msg = "Key paused!";
        }
    } elseif ($act === 'resume_key' && $id > 0) {
        $k = $pdo->query("SELECT * FROM licenses WHERE id = {$id}")->fetch();
        if ($k && $k['is_paused'] == 1 && $k['remaining_seconds'] > 0) {
            $newExp = date('Y-m-d H:i:s', time() + $k['remaining_seconds']);
            $pdo->prepare("UPDATE licenses SET is_paused = 0, expires_at = ?, remaining_seconds = 0 WHERE id = ?")->execute([$newExp, $id]);
            $msg = "Key resumed!";
        }
    } elseif ($act === 'ban_key' && $id > 0) {
        $pdo->prepare("UPDATE licenses SET is_banned = 1 WHERE id = ?")->execute([$id]);
        $msg = "License key BANNED!";
    } elseif ($act === 'unban_key' && $id > 0) {
        $pdo->prepare("UPDATE licenses SET is_banned = 0 WHERE id = ?")->execute([$id]);
        $msg = "License key UNBANNED!";
    } elseif ($act === 'delete_key' && $id > 0) {
        $pdo->query("UPDATE licenses SET is_deleted = 1 WHERE id = {$id}");
        $msg = "Key moved to trash!";
    } elseif ($act === 'restore_key' && $id > 0) {
        $pdo->query("UPDATE licenses SET is_deleted = 0 WHERE id = {$id}");
        $msg = "Key restored!";
    } elseif ($act === 'pause_all_keys') {
        $pdo->exec("UPDATE licenses SET is_paused = 1, remaining_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) WHERE status = 'active' AND is_paused = 0 AND is_deleted = 0");
        $msg = "All active keys paused!";
    } elseif ($act === 'resume_all_keys') {
        $rows = $pdo->query("SELECT id, remaining_seconds FROM licenses WHERE is_paused = 1 AND is_deleted = 0")->fetchAll();
        foreach ($rows as $r) {
            $exp = date('Y-m-d H:i:s', time() + $r['remaining_seconds']);
            $pdo->prepare("UPDATE licenses SET is_paused = 0, expires_at = ?, remaining_seconds = 0 WHERE id = ?")->execute([$exp, $r['id']]);
        }
        $msg = "All keys resumed!";
    } elseif ($act === 'delete_all_keys') {
        $pdo->exec("UPDATE licenses SET is_deleted = 1 WHERE is_deleted = 0");
        $msg = "All keys trashed!";
    } elseif ($act === 'restore_all_keys') {
        $pdo->exec("UPDATE licenses SET is_deleted = 0 WHERE is_deleted = 1");
        $msg = "All keys restored!";
    } elseif ($act === 'ban_user_all_keys' && $uid > 0) {
        $pdo->prepare("UPDATE licenses SET is_banned = 1 WHERE user_id = ?")->execute([$uid]);
        $msg = "All keys of this user are now BANNED!";
    } elseif ($act === 'unban_user_all_keys' && $uid > 0) {
        $pdo->prepare("UPDATE licenses SET is_banned = 0 WHERE user_id = ?")->execute([$uid]);
        $msg = "All keys of this user are now UNBANNED!";
    } elseif ($act === 'change_role' && $id > 0 && isset($_GET['to'])) {
        $to = cleanInput($_GET['to']);
        if (in_array($to, ['user', 'reseller', 'sub_reseller'])) {
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$to, $id]);
            $msg = "Role updated to {$to}!";
        }
    } elseif (($act === 'ban' || $act === 'unban') && $id > 0) {
        $st = ($act === 'ban') ? 'banned' : 'active';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$st, $id]);
        $msg = "User status set to {$st}!";
    } elseif ($act === 'approve_deposit' && $id > 0) {
        $dep = $pdo->query("SELECT * FROM deposits WHERE id = {$id} AND status = 'pending'")->fetch();
        if ($dep) {
            $pdo->beginTransaction();
            $pdo->query("UPDATE deposits SET status = 'approved' WHERE id = {$id}");
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$dep['amount'], $dep['user_id']]);
            $pdo->commit();
            $msg = "Deposit approved!";
        }
    } elseif ($act === 'reject_deposit' && $id > 0) {
        $pdo->query("UPDATE deposits SET status = 'rejected' WHERE id = {$id} AND status = 'pending'");
        $msg = "Deposit rejected!";
    } elseif ($act === 'del_plan' && $id > 0) {
        $pdo->query("DELETE FROM plans WHERE id = {$id}");
        $msg = "Plan deleted!";
    } elseif ($act === 'delete_file' && !empty($_GET['file'])) {
        $delFile = basename($_GET['file']);
        $filePath = __DIR__ . '/uploads/' . $delFile;
        if (file_exists($filePath)) {
            @unlink($filePath);
            $msg = "File '{$delFile}' deleted permanently!";
        } else {
            $error = "File does not exist!";
        }
    } elseif ($act === 'revoke_api' && $id > 0) {
        $pdo->prepare("UPDATE users SET api_key = NULL WHERE id = ?")->execute([$id]);
        $msg = "User's API key revoked!";
    } elseif ($act === 'del_gist' && $id > 0) {
        $pdo->prepare("DELETE FROM gists WHERE id = ?")->execute([$id]);
        $msg = "Gist deleted successfully!";
    }
}

// ---- Post/Redirect/Get : action শেষ হলে সঠিক সেকশনে redirect ----
$curAct = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($curAct !== '' && ($msg !== '' || $error !== '') && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action']))) {
    $sectionMap = [
        'key_manager'  => ['create_key_admin','pause_key','resume_key','ban_key','unban_key','delete_key','restore_key','pause_all_keys','resume_all_keys','delete_all_keys','restore_all_keys','ban_user_all_keys','unban_user_all_keys'],
        'user_manager' => ['add_balance','create_user','change_role','ban','unban'],
        'deposits'     => ['approve_deposit','reject_deposit'],
        'payment'      => ['save_settings'],
        'plans'        => ['add_plan','del_plan'],
        'file_manager' => ['upload_file','edit_file_content','delete_file'],
        'gist_system'  => ['create_gist','edit_gist','del_gist'],
        'api_settings' => ['save_api_settings','revoke_api'],
    ];
    $go = 'dashboard';
    foreach ($sectionMap as $sec => $acts) { if (in_array($curAct, $acts, true)) { $go = $sec; break; } }
    $_SESSION['admin_flash'] = ['msg' => $msg, 'error' => $error];
    header("Location: admin.php#" . $go);
    exit();
}

// Data Queries
$totalSoldKeys = (int)$pdo->query("SELECT COUNT(*) FROM licenses WHERE user_id IS NOT NULL AND is_deleted = 0")->fetchColumn();
$stockKeys     = (int)$pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'unused' AND is_deleted = 0")->fetchColumn();
$usedKeys      = (int)$pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'active' AND is_deleted = 0")->fetchColumn();
$totalDeposits = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM deposits WHERE status = 'approved'")->fetchColumn();
$totalBalance  = (float)$pdo->query("SELECT COALESCE(SUM(balance), 0) FROM users")->fetchColumn();
$totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$totalResellers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('reseller', 'sub_reseller')")->fetchColumn();

// Game-specific stats
$ffStockKeys = (int)$pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'unused' AND is_deleted = 0 AND game_type = 'FreeFire'")->fetchColumn();
$pubgStockKeys = (int)$pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'unused' AND is_deleted = 0 AND game_type = 'Pubg'")->fetchColumn();

$clients = $pdo->query("SELECT * FROM users WHERE role = 'user' ORDER BY id DESC")->fetchAll();
$resellers = $pdo->query("SELECT * FROM users WHERE role IN ('reseller', 'sub_reseller') ORDER BY id DESC")->fetchAll();
$allUsers = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();

$settings = [];
foreach ($pdo->query("SELECT * FROM settings")->fetchAll() as $s) { $settings[$s['setting_key']] = $s['setting_value']; }

$plans = $pdo->query("SELECT * FROM plans ORDER BY id DESC")->fetchAll();
$deposits = $pdo->query("SELECT d.*, u.username FROM deposits d LEFT JOIN users u ON d.user_id = u.id ORDER BY d.id DESC LIMIT 50")->fetchAll();
$pendingCount = count(array_filter($deposits, fn($d) => $d['status'] === 'pending'));

// Handle "View Profile"
$viewUser = null;
$viewUserKeys = [];
$viewUserDeposits = [];
$viewUserId = (int)($_GET['view_user'] ?? 0);

if ($viewUserId > 0) {
    $stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmtU->execute([$viewUserId]);
    $viewUser = $stmtU->fetch();

    if ($viewUser) {
        $stmtK = $pdo->prepare("SELECT * FROM licenses WHERE user_id = ? ORDER BY id DESC");
        $stmtK->execute([$viewUserId]);
        $viewUserKeys = $stmtK->fetchAll();

        $stmtD = $pdo->prepare("SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC");
        $stmtD->execute([$viewUserId]);
        $viewUserDeposits = $stmtD->fetchAll();
    }
}

// Edit Gist - Load data for edit form
$editGist = null;
$editGistId = (int)($_GET['edit_gist'] ?? 0);
if ($editGistId > 0) {
    $stmtEG = $pdo->prepare("SELECT * FROM gists WHERE id = ?");
    $stmtEG->execute([$editGistId]);
    $editGist = $stmtEG->fetch();
}

// Edit File - Load data for edit form
$editFile = null;
$editFileName = !empty($_GET['edit_file']) ? basename($_GET['edit_file']) : '';
if (!empty($editFileName)) {
    $editFilePath = __DIR__ . '/uploads/' . $editFileName;
    if (file_exists($editFilePath) && is_file($editFilePath)) {
        $ext = strtolower(pathinfo($editFileName, PATHINFO_EXTENSION));
        $textExts = ['txt','json','ini','lua','cpp','py','sh','js','css','html','xml','yml','yaml','md','csv','log','conf','cfg','htaccess'];
        if (in_array($ext, $textExts)) {
            $editFile = [
                'name' => $editFileName,
                'content' => file_get_contents($editFilePath)
            ];
        }
    }
}

// Search
$search = cleanInput($_GET['q'] ?? '');
if (!empty($search)) {
    $stmt = $pdo->prepare("
        SELECT l.*, u.username, u.email AS user_email, u.status AS user_status, u.role AS user_role,
               (SELECT COUNT(*) FROM licenses WHERE user_id = l.user_id AND is_deleted = 0) AS user_total_keys
        FROM licenses l 
        LEFT JOIN users u ON l.user_id = u.id 
        WHERE l.license_key LIKE ? OR u.username LIKE ? 
        ORDER BY l.id DESC LIMIT 100
    ");
    $stmt->execute(["%{$search}%", "%{$search}%"]);
    $licenses = $stmt->fetchAll();
} else {
    $licenses = $pdo->query("
        SELECT l.*, u.username, u.email AS user_email, u.status AS user_status, u.role AS user_role,
               (SELECT COUNT(*) FROM licenses WHERE user_id = l.user_id AND is_deleted = 0) AS user_total_keys
        FROM licenses l 
        LEFT JOIN users u ON l.user_id = u.id 
        ORDER BY l.id DESC LIMIT 50
    ")->fetchAll();
}

// Uploaded Files
$uploadDirectory = __DIR__ . '/uploads/';
$uploadedFiles = [];
if (is_dir($uploadDirectory)) {
    $files = scandir($uploadDirectory);
    foreach ($files as $f) {
        if ($f !== '.' && $f !== '..' && is_file($uploadDirectory . $f)) {
            $uploadedFiles[] = [
                'name' => $f,
                'size' => filesize($uploadDirectory . $f),
                'time' => filemtime($uploadDirectory . $f)
            ];
        }
    }
    usort($uploadedFiles, fn($a, $b) => $b['time'] <=> $a['time']);
}

// API & Reset Limit section data
$curResetLimit = getResetLimit($pdo);
$apiEnabledNow = getSetting($pdo, 'api_enabled', '1') === '1';
$apiUsers = $pdo->query("SELECT id, username, role, status, api_key FROM users WHERE api_key IS NOT NULL AND api_key <> '' ORDER BY id DESC")->fetchAll();
$rt = $pdo->prepare("SELECT COALESCE(SUM(daily_reset_count), 0) FROM licenses WHERE last_reset_date = ?");
$rt->execute([date('Y-m-d')]);
$resetsToday = (int)$rt->fetchColumn();

// Gists List
$gistsList = $pdo->query("SELECT * FROM gists ORDER BY id DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
    <title>CrackMaster Console - Dual Game Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; touch-action:manipulation; }
        body { background:#070d1a; color:#cbd5e1; min-height:100vh; overflow-x:hidden; -webkit-text-size-adjust:100%; }
        input, select, textarea, button { font-size:14px; outline:none; }

        /* Top Bar */
        .topbar { background:#0b1329; border-bottom:1px solid #1e293b; padding:12px 18px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:90; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .btn-hamburger { background:#1e293b; border:1px solid #334155; color:#38bdf8; width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:18px; }
        .topbar h1 { font-size:16px; font-weight:800; color:#fff; }
        .topbar h1 span { color:#38bdf8; }
        .btn-exit { color:#f87171; text-decoration:none; font-size:12px; font-weight:700; padding:7px 14px; border-radius:8px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.2); }

        /* Sidebar Navigation */
        .sidebar-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); z-index:99; display:none; }
        .sidebar-overlay.active { display:block; }
        .sidebar { width:260px; background:#0b1329; border-right:1px solid #1e293b; display:flex; flex-direction:column; position:fixed; top:0; bottom:0; left:0; z-index:100; transition:all 0.3s; padding:16px; transform:translateX(-100%); }
        .sidebar.active { transform:translateX(0); }
        .sidebar-header { display:flex; align-items:center; justify-content:space-between; padding-bottom:16px; border-bottom:1px solid #1e293b; margin-bottom:12px; }
        .sidebar-brand { font-size:17px; font-weight:800; color:#fff; }
        .btn-close-sidebar { background:none; border:none; color:#94a3b8; font-size:22px; cursor:pointer; }
        
        .sidebar-menu { display:flex; flex-direction:column; gap:6px; flex:1; overflow-y:auto; }
        .side-nav-btn { display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-radius:10px; color:#94a3b8; font-size:13.5px; font-weight:700; text-decoration:none; border:none; background:none; width:100%; text-align:left; cursor:pointer; }
        .side-nav-btn .left { display:flex; align-items:center; gap:10px; }
        .side-nav-btn i { font-size:16px; width:18px; }
        .side-nav-btn:hover { background:#1e293b; color:#fff; }
        .side-nav-btn.active { background:#0284c7; color:#fff; }
        .badge-count { background:#ef4444; color:#fff; font-size:10px; font-weight:900; padding:2px 6px; border-radius:10px; }

        /* Tabs & Container */
        .container { padding:16px; max-width:1200px; margin:0 auto; }
        .main-view { display:none; }
        .main-view.active { display:block; }
        .sub-view { display:none; }
        .sub-view.active { display:block; }

        .sub-nav-scroll { display:flex; gap:10px; overflow-x:auto; white-space:nowrap; padding-bottom:14px; margin-bottom:16px; border-bottom:1px solid #1e293b; scrollbar-width:none; -webkit-overflow-scrolling:touch; }
        .sub-nav-scroll::-webkit-scrollbar { display:none; }
        .sub-tab-btn { display:inline-flex; align-items:center; gap:8px; background:#0e172a; border:1px solid #1e293b; color:#94a3b8; font-weight:700; font-size:12.5px; padding:9px 16px; border-radius:10px; cursor:pointer; text-decoration:none; }
        .sub-tab-btn i { font-size:13px; color:#38bdf8; }
        .sub-tab-btn.active { background:#1e293b; color:#fff; border-color:#38bdf8; }

        /* Dashboard Stat Cards */
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:18px; }
        .card { background:#0e172a; border:1px solid #1e293b; border-radius:14px; padding:18px; display:flex; justify-content:space-between; align-items:center; }
        .card .title { font-size:11px; font-weight:700; color:#94a3b8; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px; }
        .card .value { font-size:24px; font-weight:900; color:#fff; }
        .card-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; background:#1e293b; color:#38bdf8; }

        /* General Panel & Tables */
        .panel { background:#0e172a; border:1px solid #1e293b; border-radius:14px; padding:18px; margin-bottom:18px; }
        .panel-header { font-size:13.5px; font-weight:800; color:#fff; margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:12px; text-align:left; }
        th { background:#070d1a; padding:11px 12px; color:#94a3b8; font-weight:700; text-transform:uppercase; font-size:11px; border-bottom:1px solid #1e293b; white-space:nowrap; }
        td { padding:11px 12px; border-bottom:1px solid #1e293b; vertical-align:middle; }

        /* Form Controls */
        .form-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; align-items:end; }
        .form-group label { display:block; font-size:11px; font-weight:700; color:#94a3b8; margin-bottom:5px; text-transform:uppercase; }
        .form-group input, .form-group select, .form-group textarea { width:100%; background:#070d1a; border:1px solid #1e293b; border-radius:8px; padding:10px 12px; color:#fff; }
        .btn-primary { background:#0284c7; border:none; padding:11px; border-radius:8px; color:#fff; font-weight:800; cursor:pointer; width:100%; font-size:12.5px; transition:0.2s; }
        .btn-primary:hover { background:#0369a1; }

        /* Badges & Action Buttons */
        .badge { padding:4px 8px; border-radius:6px; font-size:10.5px; font-weight:800; text-transform:uppercase; display:inline-flex; align-items:center; gap:4px; }
        .badge-active { background:rgba(16,185,129,0.18); color:#34d399; border:1px solid rgba(16,185,129,0.3); }
        .badge-paused { background:rgba(234,179,8,0.18); color:#facc15; border:1px solid rgba(234,179,8,0.3); }
        .badge-banned, .badge-deleted { background:rgba(239,68,68,0.18); color:#f87171; border:1px solid rgba(239,68,68,0.3); }
        .badge-info { background:rgba(56,189,248,0.18); color:#38bdf8; border:1px solid rgba(56,189,248,0.3); }
        .badge-ff { background:rgba(239,68,68,0.2); color:#ff6b6b; border:1px solid rgba(239,68,68,0.4); }
        .badge-pubg { background:rgba(245,158,11,0.2); color:#fbbf24; border:1px solid rgba(245,158,11,0.4); }

        .btn-sm { padding:6px 11px; border-radius:7px; font-size:11px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; cursor:pointer; border:none; transition:0.15s; }
        .btn-green { background:rgba(16,185,129,0.15); color:#34d399; border:1px solid rgba(16,185,129,0.3); }
        .btn-green:hover { background:#10b981; color:#fff; }
        .btn-red { background:rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(239,68,68,0.3); }
        .btn-red:hover { background:#ef4444; color:#fff; }
        .btn-yellow { background:rgba(234,179,8,0.15); color:#facc15; border:1px solid rgba(234,179,8,0.3); }
        .btn-yellow:hover { background:#eab308; color:#000; }
        .btn-blue { background:rgba(56,189,248,0.15); color:#38bdf8; border:1px solid rgba(56,189,248,0.3); }
        .btn-blue:hover { background:#0284c7; color:#fff; }
        .btn-raw { background:rgba(168,85,247,0.15); color:#c084fc; border:1px solid rgba(168,85,247,0.3); }
        .btn-raw:hover { background:#a855f7; color:#fff; }

        .alert { padding:12px 16px; border-radius:10px; font-size:12px; margin-bottom:14px; font-weight:700; display:flex; justify-content:space-between; }
        .alert-ok { background:rgba(16,185,129,0.15); color:#6ee7b7; border:1px solid rgba(16,185,129,0.3); }
        .alert-bad { background:rgba(239,68,68,0.15); color:#fca5a5; border:1px solid rgba(239,68,68,0.3); }

        /* Modern Glass Card */
        .glass-card {
            background: linear-gradient(145deg, #0d1527 0%, #080f1d 100%);
            border: 1px solid #1e2d4a;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            position: relative;
            overflow: hidden;
        }
        .glass-card::before {
            content:'';
            position:absolute;
            top:0; left:0; right:0; height:2px;
            background:linear-gradient(90deg, #38bdf8, #818cf8, transparent);
        }

        /* Modern Upload Dropzone Box */
        .modern-dropzone {
            border: 2px dashed #2a3854;
            border-radius: 16px;
            background: radial-gradient(circle at center, #0e1933 0%, #070d1a 100%);
            padding: 30px 18px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
        }
        .modern-dropzone:hover, .modern-dropzone.dragover {
            border-color: #38bdf8;
            background: radial-gradient(circle at center, #132247 0%, #070d1a 100%);
            box-shadow: 0 0 25px rgba(56, 189, 248, 0.15);
        }
        .upload-icon-wrapper {
            width: 58px;
            height: 58px;
            margin: 0 auto 12px auto;
            border-radius: 16px;
            background: rgba(56, 189, 248, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #38bdf8;
            font-size: 24px;
            transition: transform 0.2s;
        }
        .modern-dropzone:hover .upload-icon-wrapper {
            transform: scale(1.08) translateY(-2px);
            color: #7dd3fc;
        }
        .format-pills {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }
        .format-pill {
            font-size: 10px;
            font-weight: 700;
            background: #131e33;
            color: #94a3b8;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid #1e293b;
        }
        .file-selected-badge {
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            padding: 7px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .table-custom-row { transition: background 0.15s; }
        .table-custom-row:hover { background: rgba(30, 41, 59, 0.45); }
        .file-thumb { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; border: 1px solid #293854; background: #091224; }
        .file-icon-box { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #111e38; border: 1px solid #243554; color: #38bdf8; font-size: 18px; }

        .empty-placeholder { text-align: center; padding: 36px 16px; color: #64748b; }
        .empty-placeholder i { font-size: 38px; color: #334155; margin-bottom: 10px; display: block; }
        .empty-placeholder p { font-size: 13px; font-weight: 600; }

        /* Notification Toast */
        #toastNotice {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #0f172a;
            border: 1px solid #38bdf8;
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            display: none;
            z-index: 9999;
            align-items: center;
            gap: 8px;
        }

        .code-editor-box {
            width:100%;
            height:220px;
            background:#040812;
            color:#38bdf8;
            font-family:'Consolas','Monaco',monospace;
            font-size:13px;
            line-height:1.5;
            padding:12px;
            border:1px solid #1e293b;
            border-radius:10px;
            resize:vertical;
        }

        /* Game Selector Cards */
        .game-selector { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
        .game-card {
            background:#070d1a; border:2px solid #1e293b; border-radius:14px; padding:16px;
            text-align:center; cursor:pointer; transition:0.2s;
        }
        .game-card:hover { border-color:#38bdf8; }
        .game-card.selected-ff { border-color:#ef4444; background:rgba(239,68,68,0.08); }
        .game-card.selected-pubg { border-color:#f59e0b; background:rgba(245,158,11,0.08); }
        .game-card img { width:48px; height:48px; object-fit:contain; margin-bottom:8px; }
        .game-card .game-name { font-size:13px; font-weight:800; color:#fff; }
        .game-card .game-desc { font-size:10px; color:#64748b; margin-top:3px; }

        /* Modal */
        .modal-overlay {
            display:none; position:fixed; top:0; left:0; right:0; bottom:0;
            background:rgba(0,0,0,0.8); backdrop-filter:blur(6px);
            z-index:9998; align-items:center; justify-content:center; padding:16px;
        }
        .modal-overlay.active { display:flex; }
        .modal-box {
            background:#0e172a; border:1px solid #1e293b; border-radius:16px;
            padding:24px; width:100%; max-width:700px; max-height:90vh; overflow-y:auto;
        }
        .modal-box h3 { font-size:16px; font-weight:800; color:#fff; margin-bottom:16px; }
    </style>
</head>
<body>

    <!-- Notification Toast -->
    <div id="toastNotice"><i class="fa-solid fa-circle-check" style="color:#38bdf8;"></i> <span id="toastMsg">URL copied!</span></div>

    <!-- Sidebar Overlay & Menu -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar(false)"></div>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">CRACK<span style="color:#38bdf8;">MASTER</span></div>
            <button class="btn-close-sidebar" onclick="toggleSidebar(false)">&times;</button>
        </div>
        <div class="sidebar-menu">
            <button class="side-nav-btn <?= empty($viewUser) ? 'active' : ''; ?>" onclick="showMainSection('dashboard', this)">
                <span class="left"><i class="fa-solid fa-chart-pie"></i> Dashboard</span>
            </button>
            <button class="side-nav-btn" onclick="showMainSection('key_manager', this)">
                <span class="left"><i class="fa-solid fa-bolt"></i> Key Manager</span>
            </button>
            <button class="side-nav-btn" onclick="showMainSection('user_manager', this)">
                <span class="left"><i class="fa-solid fa-users"></i> User Manager</span>
            </button>
            <button class="side-nav-btn" onclick="showMainSection('deposits', this)">
                <span class="left"><i class="fa-solid fa-money-bill-wave"></i> Deposits</span>
                <?php if ($pendingCount > 0): ?><span class="badge-count"><?= $pendingCount; ?></span><?php endif; ?>
            </button>
            <button class="side-nav-btn" onclick="showMainSection('file_manager', this)">
                <span class="left"><i class="fa-solid fa-cloud-arrow-up"></i> File & Raw Links</span>
            </button>
            <button class="side-nav-btn" onclick="showMainSection('gist_system', this)">
                <span class="left"><i class="fa-solid fa-code"></i> GitHub Gist</span>
            </button>
            <button class="side-nav-btn" onclick="showMainSection('payment', this)">
                <span class="left"><i class="fa-solid fa-credit-card"></i> Payment Setup</span>
            </button>
            <button class="side-nav-btn" onclick="showMainSection('plans', this)">
                <span class="left"><i class="fa-solid fa-tags"></i> Plans</span>
            </button>
            <button class="side-nav-btn" onclick="showMainSection('api_settings', this)">
                <span class="left"><i class="fa-solid fa-plug"></i> API & Reset Limit</span>
            </button>
            <a href="dashboard.php" class="side-nav-btn" style="color:#38bdf8; margin-top:10px;">
                <span class="left"><i class="fa-solid fa-arrow-left"></i> Client Panel</span>
            </a>
        </div>
    </div>

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="btn-hamburger" onclick="toggleSidebar(true)"><i class="fa-solid fa-bars"></i></button>
            <h1 id="pageHeading"><?= !empty($viewUser) ? 'User Profile: ' . htmlspecialchars($viewUser['username']) : 'Dashboard'; ?></h1>
        </div>
        <a href="admin.php?action=logout" class="btn-exit"><i class="fa-solid fa-power-off"></i> Logout</a>
    </div>

    <div class="container">
        <?php if (!empty($msg)): ?><div class="alert alert-ok"><?= $msg; ?><span onclick="this.parentElement.remove()" style="cursor:pointer;">&times;</span></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert alert-bad"><?= $error; ?><span onclick="this.parentElement.remove()" style="cursor:pointer;">&times;</span></div><?php endif; ?>

        <!-- ========================================== -->
        <!-- 0. USER PROFILE (View Profile) -->
        <!-- ========================================== -->
        <div id="main-user_profile" class="main-view <?= !empty($viewUser) ? 'active' : ''; ?>">
            <?php if (!empty($viewUser)): ?>
                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
                    <a href="admin.php#key_manager" class="btn-sm btn-blue"><i class="fa-solid fa-arrow-left"></i> Back</a>
                    <a href="admin.php?action=ban_user_all_keys&uid=<?= (int)$viewUser['id']; ?>&view_user=<?= (int)$viewUser['id']; ?>" class="btn-sm btn-red" onclick="return confirm('Ban ALL keys of this user?');"><i class="fa-solid fa-ban"></i> Ban All Keys</a>
                    <a href="admin.php?action=unban_user_all_keys&uid=<?= (int)$viewUser['id']; ?>&view_user=<?= (int)$viewUser['id']; ?>" class="btn-sm btn-green"><i class="fa-solid fa-unlock"></i> Unban All Keys</a>
                    <?php if (!empty($viewUser['api_key'])): ?>
                        <a href="admin.php?action=revoke_api&id=<?= (int)$viewUser['id']; ?>" class="btn-sm btn-yellow" onclick="return confirm('Revoke this user\'s API key?');"><i class="fa-solid fa-plug-circle-xmark"></i> Revoke API Key</a>
                    <?php endif; ?>
                </div>

                <div class="stats-grid">
                    <div class="card"><div><div class="title">Username</div><div class="value" style="font-size:18px;"><?= htmlspecialchars($viewUser['username']); ?></div></div><div class="card-icon"><i class="fa-solid fa-id-card"></i></div></div>
                    <div class="card"><div><div class="title">Role</div><div class="value" style="font-size:18px;"><?= strtoupper($viewUser['role']); ?></div></div><div class="card-icon"><i class="fa-solid fa-user-tag"></i></div></div>
                    <div class="card"><div><div class="title">Balance</div><div class="value" style="color:#10b981;">$<?= number_format($viewUser['balance'], 2); ?></div></div><div class="card-icon"><i class="fa-solid fa-wallet"></i></div></div>
                    <div class="card"><div><div class="title">Status</div><div class="value" style="font-size:18px;"><span class="badge <?= $viewUser['status'] === 'active' ? 'badge-active' : 'badge-banned'; ?>"><?= ucfirst($viewUser['status']); ?></span></div></div><div class="card-icon"><i class="fa-solid fa-shield-halved"></i></div></div>
                    <div class="card"><div><div class="title">Total Keys</div><div class="value"><?= count($viewUserKeys); ?></div></div><div class="card-icon"><i class="fa-solid fa-key"></i></div></div>
                    <div class="card"><div><div class="title">API Access</div><div class="value" style="font-size:16px;"><?= !empty($viewUser['api_key']) ? '<span class="badge badge-active">Key Created</span>' : '<span class="badge badge-paused">No Key</span>'; ?></div></div><div class="card-icon"><i class="fa-solid fa-plug"></i></div></div>
                </div>
                <div style="font-size:12px; color:#94a3b8; margin-bottom:14px;"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($viewUser['email']); ?></div>

                <div class="panel">
                    <div class="panel-header">Keys of this user</div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Game</th><th>Key</th><th>Status</th><th>Device</th><th>Resets Today</th><th>Expires</th></tr></thead>
                            <tbody>
                                <?php if (empty($viewUserKeys)): ?>
                                    <tr><td colspan="6" style="text-align:center; padding:18px;">No keys.</td></tr>
                                <?php else: foreach ($viewUserKeys as $vk): $vg = normalizeGame($vk['game_type'] ?? 'FreeFire'); $vu = resetsUsedToday($vk); ?>
                                    <tr>
                                        <td><span class="badge <?= $vg === 'Pubg' ? 'badge-pubg' : 'badge-ff'; ?>"><?= gameCatalog()[$vg]['short']; ?></span></td>
                                        <td><code style="color:#38bdf8; font-weight:800;"><?= htmlspecialchars($vk['license_key']); ?></code></td>
                                        <td>
                                            <?php if (!empty($vk['is_banned'])): ?><span class="badge badge-banned">Banned</span>
                                            <?php elseif (!empty($vk['is_deleted'])): ?><span class="badge badge-deleted">Trashed</span>
                                            <?php elseif (!empty($vk['is_paused'])): ?><span class="badge badge-paused">Paused</span>
                                            <?php else: ?><span class="badge <?= $vk['status'] === 'active' ? 'badge-active' : 'badge-paused'; ?>"><?= ucfirst($vk['status']); ?></span><?php endif; ?>
                                        </td>
                                        <td><?= !empty($vk['device_id']) ? '<span class="badge badge-info">Bound</span>' : '<span style="color:#64748b;">Free</span>'; ?></td>
                                        <td><?= $vu; ?> / <?= $curResetLimit > 0 ? $curResetLimit : '&infin;'; ?></td>
                                        <td><?= !empty($vk['expires_at']) ? date('d M Y, h:i A', strtotime($vk['expires_at'])) : 'Pending Login'; ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">Deposits of this user</div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Method</th><th>Amount</th><th>TrxID</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php if (empty($viewUserDeposits)): ?>
                                    <tr><td colspan="5" style="text-align:center; padding:18px;">No deposits.</td></tr>
                                <?php else: foreach ($viewUserDeposits as $vd): ?>
                                    <tr>
                                        <td><?= strtoupper($vd['method']); ?></td>
                                        <td><?= $vd['amount'] . ' ' . $vd['currency']; ?></td>
                                        <td><code><?= htmlspecialchars($vd['trx_id']); ?></code></td>
                                        <td><span class="badge <?= $vd['status'] === 'approved' ? 'badge-active' : ($vd['status'] === 'pending' ? 'badge-paused' : 'badge-banned'); ?>"><?= ucfirst($vd['status']); ?></span></td>
                                        <td style="color:#94a3b8;"><?= date('d M Y, h:i A', strtotime($vd['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- 1. DASHBOARD -->
        <!-- ========================================== -->
        <div id="main-dashboard" class="main-view <?= empty($viewUser) ? 'active' : ''; ?>">
            <div class="stats-grid">
                <div class="card">
                    <div><div class="title">Total Keys Sold</div><div class="value" style="color:#38bdf8;"><?= $totalSoldKeys; ?></div></div>
                    <div class="card-icon"><i class="fa-solid fa-cart-shopping"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">FF Stock Keys</div><div class="value" style="color:#ff6b6b;"><?= $ffStockKeys; ?></div></div>
                    <div class="card-icon" style="color:#ff6b6b;"><i class="fa-solid fa-fire"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">PUBG Stock Keys</div><div class="value" style="color:#fbbf24;"><?= $pubgStockKeys; ?></div></div>
                    <div class="card-icon" style="color:#fbbf24;"><i class="fa-solid fa-crosshairs"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">Active Keys</div><div class="value"><?= $usedKeys; ?></div></div>
                    <div class="card-icon" style="color:#10b981;"><i class="fa-solid fa-circle-play"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">Approved Deposits</div><div class="value">$<?= number_format($totalDeposits, 2); ?></div></div>
                    <div class="card-icon" style="color:#60a5fa;"><i class="fa-solid fa-receipt"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">Total User Balance</div><div class="value">$<?= number_format($totalBalance, 2); ?></div></div>
                    <div class="card-icon" style="color:#a855f7;"><i class="fa-solid fa-wallet"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">Registered Users</div><div class="value"><?= $totalUsers; ?></div></div>
                    <div class="card-icon"><i class="fa-solid fa-users"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">Resellers</div><div class="value"><?= $totalResellers; ?></div></div>
                    <div class="card-icon" style="color:#38bdf8;"><i class="fa-solid fa-user-tie"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">Stored Files</div><div class="value" style="color:#f472b6;"><?= count($uploadedFiles); ?></div></div>
                    <div class="card-icon" style="color:#f472b6;"><i class="fa-solid fa-folder-open"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">Total Gists</div><div class="value" style="color:#c084fc;"><?= count($gistsList); ?></div></div>
                    <div class="card-icon" style="color:#c084fc;"><i class="fa-solid fa-code"></i></div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- 2. KEY MANAGER -->
        <!-- ========================================== -->
        <div id="main-key_manager" class="main-view">
            <div class="sub-nav-scroll">
                <button type="button" class="sub-tab-btn active" onclick="showSubSection('km', 'licenses', this)"><i class="fa-solid fa-list-check"></i> All Licenses</button>
                <button type="button" class="sub-tab-btn" onclick="showSubSection('km', 'generate', this)"><i class="fa-solid fa-bolt"></i> Generate Key</button>
                <button type="button" class="sub-tab-btn" onclick="showSubSection('km', 'bulk', this)"><i class="fa-solid fa-sliders"></i> Bulk Controls</button>
            </div>

            <div id="km-sub-licenses" class="sub-view active">
                <div class="panel">
                    <form method="GET" style="display:flex; gap:8px; margin-bottom:14px;">
                        <input type="text" name="q" value="<?= htmlspecialchars($search); ?>" placeholder="Search by Key or Username..." style="flex:1; background:#070d1a; border:1px solid #1e293b; border-radius:8px; padding:9px 12px; color:#fff;">
                        <button type="submit" class="btn-primary" style="width:auto; padding:9px 18px;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                        <?php if (!empty($search)): ?><a href="admin.php#key_manager" class="btn-sm btn-blue" style="display:flex; align-items:center;">Clear</a><?php endif; ?>
                    </form>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Game</th>
                                    <th>Key</th>
                                    <th>Device Limit</th>
                                    <th>Taken By (User)</th>
                                    <th>Total Keys</th>
                                    <th>Key Status</th>
                                    <th>Expires / Frozen</th>
                                    <th>Key Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($licenses)): ?>
                                    <tr><td colspan="8" style="text-align:center; padding:18px;">No keys found.</td></tr>
                                <?php else: foreach ($licenses as $l): ?>
                                    <tr>
                                        <td>
                                            <?php if (($l['game_type'] ?? 'FreeFire') === 'Pubg'): ?>
                                                <span class="badge badge-pubg"><i class="fa-solid fa-crosshairs"></i> PUBG</span>
                                            <?php else: ?>
                                                <span class="badge badge-ff"><i class="fa-solid fa-fire"></i> FF</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code style="color:#38bdf8; font-weight:800;"><?= htmlspecialchars($l['license_key']); ?></code>
                                            <div style="font-size:10px; color:#94a3b8;"><?= $l['duration_days'] . ' ' . ($l['duration_type'] ?? 'days'); ?></div>
                                        </td>
                                        <td>
                                            <?php if ((int)($l['max_devices'] ?? 1) === 0): ?>
                                                <span class="badge badge-active"><i class="fa-solid fa-infinity"></i> Unlimited</span>
                                            <?php else: ?>
                                                <span class="badge badge-info"><?= (int)($l['max_devices'] ?? 1); ?> Device</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($l['user_id'])): ?>
                                                <a href="admin.php?view_user=<?= $l['user_id']; ?>" class="btn-sm btn-blue" style="font-weight:800; font-size:10px;">
                                                    <i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($l['username'] ?? 'User #'.$l['user_id']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="color:#64748b;">Admin Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= !empty($l['user_id']) ? '<span class="badge badge-info">'.(int)($l['user_total_keys'] ?? 1).'</span>' : '-'; ?></td>
                                        <td>
                                            <?php if ($l['is_banned'] == 1): ?><span class="badge badge-banned">Banned</span>
                                            <?php elseif ($l['is_deleted'] == 1): ?><span class="badge badge-deleted">Trashed</span>
                                            <?php elseif ($l['is_paused'] == 1): ?><span class="badge badge-paused">Paused</span>
                                            <?php elseif ($l['status'] === 'active'): ?><span class="badge badge-active">Active</span>
                                            <?php else: ?><span class="badge badge-paused"><?= ucfirst($l['status']); ?></span><?php endif; ?>
                                        </td>
                                        <td><?= $l['is_paused'] == 1 ? 'Frozen ('.sprintf('%02d:%02d:%02d', intdiv((int)$l['remaining_seconds'], 3600), intdiv((int)$l['remaining_seconds'] % 3600, 60), (int)$l['remaining_seconds'] % 60).')' : (!empty($l['expires_at']) ? date('d M Y, h:i A', strtotime($l['expires_at'])) : 'Pending Login'); ?></td>
                                        <td>
                                            <div style="display:flex; flex-wrap:wrap; gap:4px;">
                                                <?php if ($l['is_banned'] == 1): ?>
                                                    <a href="admin.php?action=unban_key&id=<?= $l['id']; ?>" class="btn-sm btn-green"><i class="fa-solid fa-unlock"></i> Unban</a>
                                                <?php else: ?>
                                                    <a href="admin.php?action=ban_key&id=<?= $l['id']; ?>" class="btn-sm btn-red" onclick="return confirm('Ban this key?');"><i class="fa-solid fa-ban"></i> Ban</a>
                                                <?php endif; ?>
                                                <?php if ($l['is_deleted'] != 1): ?>
                                                    <?php if ($l['is_paused'] == 1): ?>
                                                        <a href="admin.php?action=resume_key&id=<?= $l['id']; ?>" class="btn-sm btn-green"><i class="fa-solid fa-play"></i></a>
                                                    <?php elseif ($l['status'] === 'active'): ?>
                                                        <a href="admin.php?action=pause_key&id=<?= $l['id']; ?>" class="btn-sm btn-yellow"><i class="fa-solid fa-pause"></i></a>
                                                    <?php endif; ?>
                                                    <a href="admin.php?action=delete_key&id=<?= $l['id']; ?>" class="btn-sm btn-red" onclick="return confirm('Trash key?');"><i class="fa-solid fa-trash"></i></a>
                                                <?php else: ?>
                                                    <a href="admin.php?action=restore_key&id=<?= $l['id']; ?>" class="btn-sm btn-blue"><i class="fa-solid fa-rotate-left"></i> Restore</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="km-sub-generate" class="sub-view">
                <div class="panel" style="max-width:550px; margin:0 auto;">
                    <div class="panel-header">Generate License Key</div>
                    <form method="POST" class="form-grid">
                        <input type="hidden" name="action" value="create_key_admin">
                        
                        <div class="form-group" style="grid-column:1/-1;">
                            <label>Select Game</label>
                            <select name="game_type" required style="padding:12px; font-weight:700;">
                                <option value="FreeFire">🔥 FreeFire</option>
                                <option value="Pubg">🎯 PUBG</option>
                            </select>
                        </div>
                        
                        <div class="form-group"><label>Key Type</label><select name="key_type"><option value="auto">Auto Random</option><option value="custom">Custom Key</option></select></div>
                        <div class="form-group"><label>Custom Name</label><input type="text" name="custom_key" placeholder="Optional"></div>
                        <div class="form-group"><label>Duration</label><input type="number" name="duration_value" value="30" min="1" required></div>
                        <div class="form-group"><label>Unit</label><select name="duration_type"><option value="hours">Hours</option><option value="days" selected>Days</option></select></div>
                        <div class="form-group"><label>Max Devices (0 = Unlimited)</label><input type="number" name="max_devices" value="1" min="0" required></div>
                        <div class="form-group" style="grid-column:1/-1;"><button type="submit" class="btn-primary">Generate Key</button></div>
                    </form>
                </div>
            </div>

            <div id="km-sub-bulk" class="sub-view">
                <div class="panel">
                    <div class="panel-header">Master Controls (All Keys)</div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px;">
                        <a href="admin.php?action=pause_all_keys" class="btn-sm btn-yellow" style="padding:12px; text-align:center;" onclick="return confirm('Pause all active keys?');"><i class="fa-solid fa-pause"></i> Pause All Keys</a>
                        <a href="admin.php?action=resume_all_keys" class="btn-sm btn-green" style="padding:12px; text-align:center;" onclick="return confirm('Resume all keys?');"><i class="fa-solid fa-play"></i> Resume All Keys</a>
                        <a href="admin.php?action=delete_all_keys" class="btn-sm btn-red" style="padding:12px; text-align:center;" onclick="return confirm('Trash all keys?');"><i class="fa-solid fa-trash"></i> Delete All Keys</a>
                        <a href="admin.php?action=restore_all_keys" class="btn-sm btn-blue" style="padding:12px; text-align:center;" onclick="return confirm('Restore all?');"><i class="fa-solid fa-rotate-left"></i> Restore All Keys</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- 3. USER MANAGER -->
        <!-- ========================================== -->
        <div id="main-user_manager" class="main-view">
            <div class="sub-nav-scroll">
                <button type="button" class="sub-tab-btn active" onclick="showSubSection('um', 'clients', this)"><i class="fa-solid fa-user"></i> Clients (<?= count($clients); ?>)</button>
                <button type="button" class="sub-tab-btn" onclick="showSubSection('um', 'resellers', this)"><i class="fa-solid fa-user-tie"></i> Resellers (<?= count($resellers); ?>)</button>
                <button type="button" class="sub-tab-btn" onclick="showSubSection('um', 'balance', this)"><i class="fa-solid fa-wallet"></i> Add Balance</button>
                <button type="button" class="sub-tab-btn" onclick="showSubSection('um', 'create', this)"><i class="fa-solid fa-user-plus"></i> Create Account</button>
            </div>

            <div id="um-sub-clients" class="sub-view active">
                <div class="panel">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Username</th><th>Email</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($clients as $u): ?>
                                    <tr>
                                        <td><b><?= htmlspecialchars($u['username']); ?></b></td>
                                        <td><?= htmlspecialchars($u['email']); ?></td>
                                        <td style="color:#10b981; font-weight:700;">$<?= number_format($u['balance'], 2); ?></td>
                                        <td><span class="badge <?= $u['status'] === 'active' ? 'badge-active' : 'badge-banned'; ?>"><?= ucfirst($u['status']); ?></span></td>
                                        <td>
                                            <a href="admin.php?action=change_role&id=<?= $u['id']; ?>&to=reseller" class="btn-sm btn-blue">Make Reseller</a>
                                            <a href="admin.php?action=<?= $u['status'] === 'active' ? 'ban' : 'unban'; ?>&id=<?= $u['id']; ?>" class="btn-sm <?= $u['status'] === 'active' ? 'btn-red' : 'btn-green'; ?>"><?= $u['status'] === 'active' ? 'Ban' : 'Unban'; ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="um-sub-resellers" class="sub-view">
                <div class="panel">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Username</th><th>Role</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($resellers as $u): ?>
                                    <tr>
                                        <td><b><?= htmlspecialchars($u['username']); ?></b></td>
                                        <td><span class="badge badge-paused"><?= strtoupper($u['role']); ?></span></td>
                                        <td style="color:#10b981; font-weight:700;">$<?= number_format($u['balance'], 2); ?></td>
                                        <td><span class="badge <?= $u['status'] === 'active' ? 'badge-active' : 'badge-banned'; ?>"><?= ucfirst($u['status']); ?></span></td>
                                        <td>
                                            <a href="admin.php?action=change_role&id=<?= $u['id']; ?>&to=user" class="btn-sm btn-yellow">Demote</a>
                                            <a href="admin.php?action=<?= $u['status'] === 'active' ? 'ban' : 'unban'; ?>&id=<?= $u['id']; ?>" class="btn-sm <?= $u['status'] === 'active' ? 'btn-red' : 'btn-green'; ?>"><?= $u['status'] === 'active' ? 'Suspend' : 'Activate'; ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="um-sub-balance" class="sub-view">
                <div class="panel" style="max-width:480px; margin:0 auto;">
                    <div class="panel-header">Balance Adjustment</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_balance">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label>Select User</label>
                            <select name="user_id" required>
                                <?php foreach ($allUsers as $u): ?>
                                    <option value="<?= $u['id']; ?>"><?= htmlspecialchars($u['username']); ?> (Bal: $<?= $u['balance']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:14px;"><label>Amount (+ / -)</label><input type="number" step="0.01" name="amount" placeholder="50 or -10" required></div>
                        <button type="submit" class="btn-primary">Update Balance</button>
                    </form>
                </div>
            </div>

            <div id="um-sub-create" class="sub-view">
                <div class="panel" style="max-width:480px; margin:0 auto;">
                    <div class="panel-header">Create Account</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_user">
                        <div class="form-group" style="margin-bottom:10px;"><label>Username</label><input type="text" name="username" required></div>
                        <div class="form-group" style="margin-bottom:10px;"><label>Email</label><input type="email" name="email" required></div>
                        <div class="form-group" style="margin-bottom:10px;"><label>Password</label><input type="password" name="password" required></div>
                        <div class="form-group" style="margin-bottom:10px;"><label>Balance</label><input type="number" step="0.01" name="balance" value="0.00"></div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label>Role</label>
                            <select name="role"><option value="user">Client</option><option value="reseller">Reseller</option><option value="sub_reseller">Sub-Reseller</option></select>
                        </div>
                        <button type="submit" class="btn-primary">Create Account</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- 4. DEPOSITS -->
        <!-- ========================================== -->
        <div id="main-deposits" class="main-view">
            <div class="panel">
                <div class="panel-header">Deposit Verification</div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>User</th><th>Method</th><th>Amount</th><th>TrxID</th><th>Sender</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php if (empty($deposits)): ?><tr><td colspan="6" style="text-align:center; padding:18px;">No deposits found.</td></tr>
                            <?php else: foreach ($deposits as $d): ?>
                                <tr>
                                    <td><b><?= htmlspecialchars($d['username'] ?? 'ID: '.$d['user_id']); ?></b></td>
                                    <td><?= strtoupper($d['method']); ?></td>
                                    <td>$<?= $d['amount']; ?></td>
                                    <td><code><?= htmlspecialchars($d['trx_id']); ?></code></td>
                                    <td><?= htmlspecialchars($d['sender_number'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($d['status'] === 'pending'): ?>
                                            <a href="admin.php?action=approve_deposit&id=<?= $d['id']; ?>" class="btn-sm btn-green">Approve</a>
                                            <a href="admin.php?action=reject_deposit&id=<?= $d['id']; ?>" class="btn-sm btn-red">Reject</a>
                                        <?php else: ?><span class="badge <?= $d['status'] === 'approved' ? 'badge-active' : 'badge-banned'; ?>"><?= ucfirst($d['status']); ?></span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- 5. GITHUB-STYLE FILE UPLOADER & RAW DOWNLOADS                  -->
        <!-- ============================================================== -->
        <div id="main-file_manager" class="main-view">
            <div class="glass-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h2 style="font-size:16px; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px;">
                            <i class="fa-brands fa-github" style="color:#38bdf8;"></i> GitHub Raw File Cloud
                        </h2>
                        <span style="font-size:12px; color:#94a3b8;">Upload files with original names preserved + edit content directly</span>
                    </div>
                    <span class="badge badge-info"><i class="fa-solid fa-server"></i> <?= count($uploadedFiles); ?> Files Stored</span>
                </div>

                <!-- Modern Interactive Upload Area -->
                <form method="POST" enctype="multipart/form-data" id="uploadFormArea">
                    <input type="hidden" name="action" value="upload_file">
                    <input type="file" id="fileInputElem" name="uploaded_file" style="display:none;" onchange="handleFileSelection(this)" required>

                    <div class="modern-dropzone" onclick="document.getElementById('fileInputElem').click()" id="dropzoneContainer">
                        <div class="upload-icon-wrapper">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                        </div>
                        <h3 style="color:#fff; font-size:15px; font-weight:800; margin-bottom:4px;">Tap to Select or Drag File Here</h3>
                        <p style="color:#94a3b8; font-size:12px;">Original filename preserved &bull; Editable content &bull; Direct Raw URLs</p>

                        <div class="format-pills">
                            <span class="format-pill">ZIP</span>
                            <span class="format-pill">APK</span>
                            <span class="format-pill">JSON</span>
                            <span class="format-pill">TXT</span>
                            <span class="format-pill">PNG</span>
                            <span class="format-pill">JPG</span>
                            <span class="format-pill">LUA</span>
                        </div>

                        <div id="fileSelectedDisplay" style="display:none;"></div>
                    </div>

                    <div style="margin-top:14px; text-align:center;">
                        <button type="submit" class="btn-primary" style="max-width:320px; margin:0 auto; padding:12px 20px; display:inline-flex; align-items:center; justify-content:center; gap:8px; border-radius:10px;">
                            <i class="fa-solid fa-upload"></i> Upload & Generate Raw Link
                        </button>
                    </div>
                </form>
            </div>

            <!-- Uploaded Files Table Card -->
            <div class="panel" style="border-radius:16px;">
                <div class="panel-header">
                    <span><i class="fa-solid fa-folder-open" style="color:#f472b6;"></i> Stored Files (Original Names & Editable)</span>
                    <span style="font-size:11px; color:#94a3b8;">Auto sorted by latest</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:50px;">Preview</th>
                                <th>File Name</th>
                                <th>Size</th>
                                <th>Raw Links</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($uploadedFiles)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-placeholder">
                                            <i class="fa-solid fa-box-open"></i>
                                            <p>No files uploaded yet in the <code>uploads/</code> directory.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($uploadedFiles as $fl): 
                                $ext = strtolower(pathinfo($fl['name'], PATHINFO_EXTENSION));
                                $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
                                $isText = in_array($ext, ['txt','json','ini','lua','cpp','py','sh','js','css','html','xml','yml','yaml','md','csv','log','conf','cfg']);
                                
                                // Dynamic protocol & host calculation
                                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                                $currentHost = $scheme . $_SERVER['HTTP_HOST'];
                                $dirPath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                                
                                $rawRelativeUrl = "admin.php?action=raw_file&file=" . urlencode($fl['name']);
                                $rawAbsoluteUrl = $currentHost . ($dirPath ? $dirPath . '/' : '/') . $rawRelativeUrl;
                                $directDownloadUrl = $rawRelativeUrl . "&download=1";
                            ?>
                                <tr class="table-custom-row">
                                    <td>
                                        <?php if ($isImg): ?>
                                            <a href="<?= $rawRelativeUrl; ?>" target="_blank"><img src="uploads/<?= rawurlencode($fl['name']); ?>" class="file-thumb" alt="Preview"></a>
                                        <?php else: ?>
                                            <div class="file-icon-box">
                                                <i class="fa-solid <?= in_array($ext, ['zip', 'rar', '7z']) ? 'fa-file-zipper' : (in_array($ext, ['apk']) ? 'fa-android' : 'fa-file-lines'); ?>"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <b style="color:#fff; font-size:13px; display:block;"><?= htmlspecialchars($fl['name']); ?></b>
                                        <span style="font-size:10px; color:#64748b; text-transform:uppercase;"><?= $ext; ?> File &bull; <?= date('d M Y, h:i A', $fl['time']); ?></span>
                                    </td>
                                    <td><span class="badge badge-info"><?= round($fl['size'] / 1024, 2); ?> KB</span></td>
                                    <td>
                                        <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <button type="button" class="btn-sm btn-raw" onclick="copyToClipboard('<?= $rawAbsoluteUrl; ?>')">
                                                <i class="fa-solid fa-copy"></i> Copy Raw URL
                                            </button>
                                            <a href="<?= $rawRelativeUrl; ?>" target="_blank" class="btn-sm btn-blue" title="Open Raw">
                                                <i class="fa-solid fa-arrow-up-right-from-square"></i> Raw View
                                            </a>
                                        </div>
                                    </td>
                                    <td style="text-align:right;">
                                        <div style="display:inline-flex; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
                                            <?php if ($isText): ?>
                                                <a href="admin.php?edit_file=<?= urlencode($fl['name']); ?>#file_manager" class="btn-sm btn-yellow" title="Edit content"><i class="fa-solid fa-pen"></i> Edit</a>
                                            <?php endif; ?>
                                            <a href="<?= $directDownloadUrl; ?>" class="btn-sm btn-green" title="1-Click Direct Download">
                                                <i class="fa-solid fa-download"></i> Download
                                            </a>
                                            <a href="admin.php?action=delete_file&file=<?= urlencode($fl['name']); ?>" class="btn-sm btn-red" onclick="return confirm('Delete this file permanently?');" title="Delete"><i class="fa-solid fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- File Edit Modal (Shown if edit_file param present) -->
            <?php if ($editFile): ?>
            <div class="glass-card" id="fileEditPanel">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <h3 style="font-size:15px; font-weight:800; color:#fff;">
                        <i class="fa-solid fa-pen" style="color:#facc15;"></i> Editing: <?= htmlspecialchars($editFile['name']); ?>
                    </h3>
                    <a href="admin.php#file_manager" class="btn-sm btn-blue"><i class="fa-solid fa-xmark"></i> Close</a>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_file_content">
                    <input type="hidden" name="file_name" value="<?= htmlspecialchars($editFile['name']); ?>">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>File Content (Raw URL will stay SAME after save)</label>
                        <textarea name="file_content" class="code-editor-box" required><?= htmlspecialchars($editFile['content']); ?></textarea>
                    </div>
                    <button type="submit" class="btn-primary" style="padding:12px;"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================== -->
        <!-- 6. GITHUB GIST (TEXT / CODE SNIPPET / RAW SYSTEM)              -->
        <!-- ============================================================== -->
        <div id="main-gist_system" class="main-view">
            <div class="glass-card">
                <div style="margin-bottom:16px;">
                    <h2 style="font-size:16px; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px;">
                        <i class="fa-brands fa-github-alt" style="color:#c084fc;"></i> GitHub Gist Creator
                    </h2>
                    <p style="font-size:12px; color:#94a3b8;">Create, host, and fetch plain-text configs, scripts, or JSON strings with dedicated Raw URLs. Edits keep the same Raw URL.</p>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="create_gist">

                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:14px;">
                        <div class="form-group">
                            <label>Gist Title / Filename</label>
                            <input type="text" name="gist_title" placeholder="e.g. app_config or cheat_offsets" required>
                        </div>
                        <div class="form-group">
                            <label>File Extension</label>
                            <select name="gist_extension">
                                <option value="txt">.txt (Plain Text)</option>
                                <option value="json">.json (JSON Data)</option>
                                <option value="ini">.ini (Config)</option>
                                <option value="lua">.lua (Lua Script)</option>
                                <option value="cpp">.cpp (C++ Code)</option>
                                <option value="py">.py (Python)</option>
                                <option value="sh">.sh (Bash / Shell)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label>Gist Content (Code / Text)</label>
                        <textarea name="gist_content" class="code-editor-box" placeholder="// Paste your JSON config, script, or plain text code here..." required></textarea>
                    </div>

                    <button type="submit" class="btn-primary" style="padding:13px; font-weight:800; border-radius:10px;">
                        <i class="fa-solid fa-code-fork"></i> Publish & Create Raw Gist
                    </button>
                </form>
            </div>

            <!-- Gist Edit Form (Shown if edit_gist param present) -->
            <?php if ($editGist): ?>
            <div class="glass-card" id="gistEditPanel">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <h3 style="font-size:15px; font-weight:800; color:#fff;">
                        <i class="fa-solid fa-pen" style="color:#facc15;"></i> Editing Gist: <?= htmlspecialchars($editGist['title']); ?>
                    </h3>
                    <a href="admin.php#gist_system" class="btn-sm btn-blue"><i class="fa-solid fa-xmark"></i> Close</a>
                </div>
                <div style="background:rgba(56,189,248,0.08); border:1px solid rgba(56,189,248,0.25); border-radius:10px; padding:10px 14px; margin-bottom:14px; font-size:12px; color:#7dd3fc;">
                    <i class="fa-solid fa-lock"></i> Raw URL will remain the SAME after edit: <code><?= $editGist['gist_uid']; ?></code>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_gist">
                    <input type="hidden" name="gist_id" value="<?= $editGist['id']; ?>">
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:14px;">
                        <div class="form-group">
                            <label>Gist Title / Filename</label>
                            <input type="text" name="gist_title" value="<?= htmlspecialchars($editGist['title']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>File Extension</label>
                            <select name="gist_extension">
                                <?php foreach (['txt','json','ini','lua','cpp','py','sh'] as $extOpt): ?>
                                    <option value="<?= $extOpt; ?>" <?= $editGist['extension'] === $extOpt ? 'selected' : ''; ?>>.<?= $extOpt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>Gist Content</label>
                        <textarea name="gist_content" class="code-editor-box" required><?= htmlspecialchars($editGist['content']); ?></textarea>
                    </div>
                    <button type="submit" class="btn-primary" style="padding:12px;"><i class="fa-solid fa-floppy-disk"></i> Save Changes (Raw URL Unchanged)</button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Gists Table -->
            <div class="panel" style="border-radius:16px;">
                <div class="panel-header">
                    <span><i class="fa-solid fa-code" style="color:#c084fc;"></i> Active GitHub Gists (<?= count($gistsList); ?>)</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Format</th>
                                <th>Raw Endpoint</th>
                                <th>Created</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($gistsList)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-placeholder">
                                            <i class="fa-solid fa-code"></i>
                                            <p>No gists created yet. Create one using the form above.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($gistsList as $gst): 
                                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                                $currentHost = $scheme . $_SERVER['HTTP_HOST'];
                                $dirPath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

                                $rawGistRel = "admin.php?action=raw_gist&id=" . urlencode($gst['gist_uid']);
                                $rawGistAbs = $currentHost . ($dirPath ? $dirPath . '/' : '/') . $rawGistRel;
                                $gistDownload = $rawGistRel . "&download=1";
                            ?>
                                <tr class="table-custom-row">
                                    <td>
                                        <b style="color:#fff; font-size:13px;"><?= htmlspecialchars($gst['title']); ?></b>
                                        <span style="font-size:11px; color:#38bdf8; display:block;"><code><?= $gst['gist_uid']; ?></code></span>
                                    </td>
                                    <td><span class="badge badge-info"><?= strtoupper($gst['extension']); ?></span></td>
                                    <td>
                                        <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <button type="button" class="btn-sm btn-raw" onclick="copyToClipboard('<?= $rawGistAbs; ?>')">
                                                <i class="fa-solid fa-copy"></i> Copy Raw URL
                                            </button>
                                            <a href="<?= $rawGistRel; ?>" target="_blank" class="btn-sm btn-blue">
                                                <i class="fa-solid fa-arrow-up-right-from-square"></i> Raw
                                            </a>
                                        </div>
                                    </td>
                                    <td><span style="color:#94a3b8; font-size:11.5px;"><?= date('d M Y, h:i A', strtotime($gst['created_at'])); ?></span></td>
                                    <td style="text-align:right;">
                                        <div style="display:inline-flex; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
                                            <a href="admin.php?edit_gist=<?= $gst['id']; ?>#gist_system" class="btn-sm btn-yellow" title="Edit gist"><i class="fa-solid fa-pen"></i> Edit</a>
                                            <a href="<?= $gistDownload; ?>" class="btn-sm btn-green" title="1-Click Download"><i class="fa-solid fa-download"></i></a>
                                            <a href="admin.php?action=del_gist&id=<?= $gst['id']; ?>" class="btn-sm btn-red" onclick="return confirm('Delete this Gist?');"><i class="fa-solid fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- 7. PAYMENT SETUP -->
        <!-- ========================================== -->
        <div id="main-payment" class="main-view">
            <div class="panel" style="max-width:600px; margin:0 auto;">
                <div class="panel-header">Payment & Gateway Settings</div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_settings">
                    <div class="form-grid" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label>Deposit System</label>
                            <select name="deposit_enabled">
                                <option value="1" <?= ($settings['deposit_enabled'] ?? '1') === '1' ? 'selected' : ''; ?>>Active</option>
                                <option value="0" <?= ($settings['deposit_enabled'] ?? '1') === '0' ? 'selected' : ''; ?>>Disabled (Hide)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Processing Mode</label>
                            <select name="deposit_mode">
                                <option value="manual" <?= ($settings['deposit_mode'] ?? 'manual') === 'manual' ? 'selected' : ''; ?>>Manual</option>
                                <option value="auto" <?= ($settings['deposit_mode'] ?? '') === 'auto' ? 'selected' : ''; ?>>Auto-Pay</option>
                            </select>
                        </div>
                        <div class="form-group"><label>Min Deposit</label><input type="number" step="0.01" name="min_deposit" value="<?= $settings['min_deposit'] ?? '50'; ?>"></div>
                        <div class="form-group"><label>Max Deposit</label><input type="number" step="0.01" name="max_deposit" value="<?= $settings['max_deposit'] ?? '25000'; ?>"></div>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>Firebase URL (Auto-Pay)</label>
                        <input type="text" name="firebase_url" value="<?= htmlspecialchars($settings['firebase_url'] ?? ''); ?>" placeholder="https://DB-NAME.firebaseio.com">
                    </div>
                    <div class="form-grid" style="margin-bottom:14px;">
                        <div class="form-group"><label>bKash</label><input type="text" name="bkash_number" value="<?= htmlspecialchars($settings['bkash_number'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Nagad</label><input type="text" name="nagad_number" value="<?= htmlspecialchars($settings['nagad_number'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Rocket</label><input type="text" name="rocket_number" value="<?= htmlspecialchars($settings['rocket_number'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Binance (TRC20)</label><input type="text" name="binance_address" value="<?= htmlspecialchars($settings['binance_address'] ?? ''); ?>"></div>
                    </div>
                    <button type="submit" class="btn-primary">Save Payment Settings</button>
                </form>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- 8. PLANS -->
        <!-- ========================================== -->
        <div id="main-plans" class="main-view">
            <div class="panel">
                <div class="panel-header">Add New Game Plan</div>
                <form method="POST" class="form-grid" style="margin-bottom:18px;">
                    <input type="hidden" name="action" value="add_plan">
                    
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Game Type</label>
                        <select name="game_type" required style="padding:11px; font-weight:700;">
                            <option value="FreeFire">🔥 FreeFire Package</option>
                            <option value="Pubg">🎯 PUBG Package</option>
                        </select>
                    </div>
                    
                    <div class="form-group"><label>Name</label><input type="text" name="plan_name" placeholder="VIP 7 Days" required></div>
                    <div class="form-group"><label>Duration</label><input type="number" name="duration_value" value="7" min="1" required></div>
                    <div class="form-group"><label>Unit</label><select name="duration_type"><option value="hours">Hours</option><option value="days" selected>Days</option></select></div>
                    <div class="form-group"><label>Price</label><input type="number" step="0.01" name="price" value="2.50" required></div>
                    <div class="form-group"><label>Currency</label><select name="currency"><option value="USDT">USDT</option><option value="BDT">BDT</option></select></div>
                    <div class="form-group"><button type="submit" class="btn-primary">Add Plan</button></div>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Game</th><th>Name</th><th>Duration</th><th>Price</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($plans as $p): ?>
                                <tr>
                                    <td>
                                        <?php if (($p['game_type'] ?? 'FreeFire') === 'Pubg'): ?>
                                            <span class="badge badge-pubg"><i class="fa-solid fa-crosshairs"></i> PUBG</span>
                                        <?php else: ?>
                                            <span class="badge badge-ff"><i class="fa-solid fa-fire"></i> FF</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><b><?= htmlspecialchars($p['name']); ?></b></td>
                                    <td><?= $p['duration_days'] . ' ' . ucfirst($p['duration_type'] ?? 'days'); ?></td>
                                    <td>$<?= $p['price'] . ' ' . $p['currency']; ?></td>
                                    <td><a href="admin.php?action=del_plan&id=<?= $p['id']; ?>" class="btn-sm btn-red" onclick="return confirm('Delete plan?');">Delete</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- 9. API & RESET LIMIT -->
        <!-- ========================================== -->
        <div id="main-api_settings" class="main-view">
            <div class="stats-grid">
                <div class="card">
                    <div><div class="title">Daily Reset Limit</div><div class="value" style="color:#c084fc;"><?= $curResetLimit > 0 ? $curResetLimit : '&infin;'; ?></div></div>
                    <div class="card-icon" style="color:#c084fc;"><i class="fa-solid fa-rotate-left"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">Resets Today (All)</div><div class="value"><?= $resetsToday; ?></div></div>
                    <div class="card-icon" style="color:#38bdf8;"><i class="fa-solid fa-chart-line"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">Users With API Key</div><div class="value"><?= count($apiUsers); ?></div></div>
                    <div class="card-icon" style="color:#10b981;"><i class="fa-solid fa-plug"></i></div>
                </div>
                <div class="card">
                    <div><div class="title">API Status</div><div class="value" style="font-size:16px;"><?= $apiEnabledNow ? '<span class="badge badge-active">Enabled</span>' : '<span class="badge badge-banned">Disabled</span>'; ?></div></div>
                    <div class="card-icon"><i class="fa-solid fa-power-off"></i></div>
                </div>
            </div>

            <div class="panel" style="max-width:600px; margin:0 auto 18px auto;">
                <div class="panel-header">Reseller API &amp; Key Reset Settings</div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_api_settings">
                    <div class="form-grid" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label>Daily Reset Limit (per key / per day)</label>
                            <input type="number" name="daily_reset_limit" min="0" max="1000" value="<?= (int)$curResetLimit; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>API Access</label>
                            <select name="api_enabled">
                                <option value="1" <?= $apiEnabledNow ? 'selected' : ''; ?>>Enabled</option>
                                <option value="0" <?= !$apiEnabledNow ? 'selected' : ''; ?>>Disabled (all API requests blocked)</option>
                            </select>
                        </div>
                    </div>
                    <p style="font-size:11.5px; color:#94a3b8; margin-bottom:14px;"><i class="fa-solid fa-circle-info"></i> A user can reset each of their keys this many times per day. <b>0 = Unlimited.</b> The same limit applies to the client dashboard and the API. The counter resets at midnight (Asia/Dhaka).</p>
                    <button type="submit" class="btn-primary">Save API &amp; Reset Settings</button>
                </form>
            </div>

            <div class="panel">
                <div class="panel-header">Users with API access (<?= count($apiUsers); ?>)</div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>API Key</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php if (empty($apiUsers)): ?>
                                <tr><td colspan="5" style="text-align:center; padding:18px;">No user has created an API key yet.</td></tr>
                            <?php else: foreach ($apiUsers as $au): ?>
                                <tr>
                                    <td><a href="admin.php?view_user=<?= (int)$au['id']; ?>" class="btn-sm btn-blue"><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($au['username']); ?></a></td>
                                    <td><span class="badge badge-paused"><?= strtoupper($au['role']); ?></span></td>
                                    <td><span class="badge <?= $au['status'] === 'active' ? 'badge-active' : 'badge-banned'; ?>"><?= ucfirst($au['status']); ?></span></td>
                                    <td><code style="color:#94a3b8;"><?= htmlspecialchars(substr($au['api_key'], 0, 7) . '••••••••' . substr($au['api_key'], -4)); ?></code></td>
                                    <td><a href="admin.php?action=revoke_api&id=<?= (int)$au['id']; ?>" class="btn-sm btn-red" onclick="return confirm('Revoke this API key?');"><i class="fa-solid fa-ban"></i> Revoke</a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script>
        function toggleSidebar(forceOpen) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (forceOpen) {
                sidebar.classList.add('active');
                overlay.classList.add('active');
            } else {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        }

        function showMainSection(sectionId, btn) {
            document.querySelectorAll('.main-view').forEach(m => m.classList.remove('active'));
            document.querySelectorAll('.side-nav-btn').forEach(b => b.classList.remove('active'));

            const target = document.getElementById('main-' + sectionId);
            if (target) target.classList.add('active');

            if (btn) btn.classList.add('active');
            else {
                document.querySelectorAll('.side-nav-btn').forEach(b => {
                    if (b.getAttribute('onclick') && b.getAttribute('onclick').includes(sectionId)) b.classList.add('active');
                });
            }

            const titleMap = {
                'dashboard': 'Dashboard',
                'key_manager': 'Key Manager',
                'user_manager': 'User Manager',
                'deposits': 'Deposit Requests',
                'file_manager': 'GitHub Raw Cloud File Uploads',
                'gist_system': 'GitHub Gist Manager',
                'payment': 'Payment Setup',
                'plans': 'Manage Plans',
                'api_settings': 'API & Reset Limit',
                'user_profile': 'User Profile'
            };
            document.getElementById('pageHeading').innerText = titleMap[sectionId] || 'Admin Console';

            toggleSidebar(false);
            window.location.hash = sectionId;
            localStorage.setItem('cm_admin_main_tab', sectionId);
        }

        function showSubSection(prefix, subId, btn) {
            document.querySelectorAll('#main-' + (prefix === 'km' ? 'key_manager' : 'user_manager') + ' .sub-view').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('#main-' + (prefix === 'km' ? 'key_manager' : 'user_manager') + ' .sub-tab-btn').forEach(b => b.classList.remove('active'));

            const target = document.getElementById(prefix + '-sub-' + subId);
            if (target) target.classList.add('active');
            btn.classList.add('active');
        }

        function handleFileSelection(input) {
            const display = document.getElementById('fileSelectedDisplay');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const kb = (file.size / 1024).toFixed(1);
                display.style.display = 'inline-flex';
                display.className = 'file-selected-badge';
                display.innerHTML = `<i class="fa-solid fa-circle-check"></i> Selected: <b>${file.name}</b> (${kb} KB)`;
            } else {
                display.style.display = 'none';
                display.innerHTML = '';
            }
        }

        function copyToClipboard(txt) {
            navigator.clipboard.writeText(txt).then(() => {
                showToast("Copied Raw Link!");
            }).catch(() => {
                prompt("Copy Raw URL:", txt);
            });
        }

        function showToast(msg) {
            const t = document.getElementById('toastNotice');
            document.getElementById('toastMsg').innerText = msg;
            t.style.display = 'inline-flex';
            setTimeout(() => { t.style.display = 'none'; }, 2500);
        }

        // Drag & Drop Styling Support
        const dropArea = document.getElementById('dropzoneContainer');
        if (dropArea) {
            ['dragenter', 'dragover'].forEach(name => {
                dropArea.addEventListener(name, (e) => { e.preventDefault(); dropArea.classList.add('dragover'); }, false);
            });
            ['dragleave', 'drop'].forEach(name => {
                dropArea.addEventListener(name, (e) => { e.preventDefault(); dropArea.classList.remove('dragover'); }, false);
            });
            dropArea.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files.length) {
                    const input = document.getElementById('fileInputElem');
                    input.files = files;
                    handleFileSelection(input);
                }
            }, false);
        }

        document.addEventListener("DOMContentLoaded", () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('view_user')) return;
            if (urlParams.has('edit_file')) { showMainSection('file_manager'); return; }
            if (urlParams.has('edit_gist')) { showMainSection('gist_system'); return; }
            if (urlParams.has('q')) { showMainSection('key_manager'); return; }

            let saved = window.location.hash.replace('#', '') || localStorage.getItem('cm_admin_main_tab') || 'dashboard';
            if (document.getElementById('main-' + saved)) {
                showMainSection(saved);
            }
        });
    </script>
</body>
</html>