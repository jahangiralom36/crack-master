<?php
require_once 'config.php';

// -------------------------------------------------------------
// LOGOUT (আগের logout.php এখন এখানে merge করা হয়েছে)
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    doUserLogout();
    header("Location: index.php?logged_out=1");
    exit();
}

if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

ensureSchema($pdo);

$user_id = (int)$_SESSION['user_id'];
$msg = '';
$error = '';
$generated_keys = [];

// Flash message (POST/GET action এর পর redirect করা হয়, যাতে refresh দিলে আবার টাকা কাটা/রিসেট না হয়)
if (!empty($_SESSION['flash'])) {
    $msg            = $_SESSION['flash']['msg'] ?? '';
    $error          = $_SESSION['flash']['error'] ?? '';
    $generated_keys = $_SESSION['flash']['keys'] ?? [];
    unset($_SESSION['flash']);
}

$games = gameCatalog();

// Fetch Settings
$settings = [];
try {
    $s_rows = $pdo->query("SELECT * FROM settings")->fetchAll();
    foreach ($s_rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

// Fetch Current User Info
$u_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$u_stmt->execute([$user_id]);
$currentUser = $u_stmt->fetch();

// ইউজার ডিলিট বা ব্যান হয়ে গেলে সাথে সাথে লগআউট
if (!$currentUser || ($currentUser['status'] ?? 'active') === 'banned') {
    doUserLogout();
    header("Location: index.php");
    exit();
}

// Personal & Global Deposit Check (If disabled, hide button)
$global_deposit_enabled = ($settings['deposit_enabled'] ?? '1') === '1';
$user_can_deposit = ($currentUser['can_deposit'] ?? 1) == 1;
$deposit_enabled = $global_deposit_enabled && $user_can_deposit;

$isReseller = ($currentUser['role'] === 'reseller' || $currentUser['role'] === 'admin');

// Daily reset limit (অ্যাডমিন প্যানেল থেকে সেট হয়, 0 = Unlimited) ও API সুইচ
$resetLimit = getResetLimit($pdo);
$apiEnabled = ($settings['api_enabled'] ?? '1') === '1';
$today      = date('Y-m-d');

// POST/GET action শেষে কোন ট্যাবে ফিরবে
$redirectTab   = null;
$redirectExtra = [];

// -------------------------------------------------------------
// 1. GENERATE KEYS ACTION (Max 50) - FreeFire / PUBG
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_keys') {
    $redirectTab = 'generate';
    $plan_id  = (int)($_POST['plan_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 1);
    $game_sel = cleanInput($_POST['game_type'] ?? '');

    if ($quantity < 1 || $quantity > 50) {
        $error = "Quantity must be between 1 and 50!";
    } else {
        $p_stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
        $p_stmt->execute([$plan_id]);
        $plan = $p_stmt->fetch();

        if (!$plan) {
            $error = "Selected plan does not exist!";
        } elseif (!empty($game_sel) && normalizeGame($plan['game_type'] ?? 'FreeFire') !== $game_sel) {
            $error = "Selected plan does not belong to the selected game!";
        } else {
            $game       = normalizeGame($plan['game_type'] ?? 'FreeFire');
            $prefix     = $games[$game]['prefix'];
            $total_cost = $plan['price'] * $quantity;

            if ($currentUser['balance'] < $total_cost) {
                $error = "Insufficient balance! Required: {$total_cost} {$plan['currency']}";
            } else {
                try {
                    $pdo->beginTransaction();

                    // balance >= cost শর্ত দিয়ে atomic কাটা (একসাথে দুইবার সাবমিট করলেও মাইনাসে যাবে না)
                    $deduct = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
                    $deduct->execute([$total_cost, $user_id, $total_cost]);

                    if ($deduct->rowCount() < 1) {
                        $pdo->rollBack();
                        $error = "Insufficient balance! Required: {$total_cost} {$plan['currency']}";
                    } else {
                        $insertKey = $pdo->prepare("INSERT INTO licenses (user_id, license_key, duration_days, duration_type, status, game_type) VALUES (?, ?, ?, ?, 'unused', ?)");
                        $dur_type = $plan['duration_type'] ?? 'days';

                        for ($i = 0; $i < $quantity; $i++) {
                            $new_k = $prefix . "-" . strtoupper(bin2hex(random_bytes(3))) . "-" . strtoupper(bin2hex(random_bytes(3)));
                            $insertKey->execute([$user_id, $new_k, $plan['duration_days'], $dur_type, $game]);
                            $generated_keys[] = $new_k;
                        }

                        $t_stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'purchase', ?)");
                        $t_stmt->execute([$user_id, $total_cost, "Generated {$quantity}x {$plan['name']} ({$games[$game]['label']})"]);

                        $pdo->commit();
                        $currentUser['balance'] -= $total_cost;
                        $msg = "Generated {$quantity} {$games[$game]['label']} key(s) successfully!";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $generated_keys = [];
                    $error = "Order failed! Please try again.";
                }
            }
        }
    }
}

// -------------------------------------------------------------
// 2. HWID RESET ACTION (daily limit = Admin Panel setting)
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'reset' && isset($_GET['id'])) {
    $redirectTab = 'reset';
    if (!empty($_GET['search_key'])) $redirectExtra['search_key'] = (string)$_GET['search_key'];

    $res = resetLicenseHwid($pdo, $user_id, (int)$_GET['id'], $resetLimit);
    if ($res['ok']) {
        $remainTxt = ($res['remaining'] === null) ? "Unlimited resets" : "Remaining resets today: {$res['remaining']}/{$res['limit']}";
        $msg = "Device HWID reset successfully! Key can now bind to a new device. ({$remainTxt})";
    } else {
        $error = $res['message'];
    }
}

// -------------------------------------------------------------
// 3. MANUAL DEPOSIT SUBMISSION
// -------------------------------------------------------------
if ($deposit_enabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_deposit') {
    $redirectTab = 'deposit';
    $method   = cleanInput($_POST['method'] ?? 'bkash');
    $amount   = (float)($_POST['amount'] ?? 0);
    $trx_id   = cleanInput($_POST['trx_id'] ?? '');
    $sender   = cleanInput($_POST['sender'] ?? '');
    if (!in_array($method, ['bkash', 'nagad', 'rocket', 'binance'], true)) $method = 'bkash';
    $currency = ($method === 'binance') ? 'USDT' : 'BDT';

    if ($amount <= 0 || empty($trx_id)) {
        $error = "Please enter a valid amount and Transaction ID (TrxID)!";
    } else {
        try {
            $pdo->prepare("INSERT INTO deposits (user_id, method, amount, currency, trx_id, sender_number, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')")
                ->execute([$user_id, $method, $amount, $currency, $trx_id, $sender]);
            $msg = "Deposit request submitted! Awaiting administrator approval.";
        } catch (PDOException $e) {
            $error = "This Transaction ID (TrxID) has already been submitted!";
        }
    }
}

// -------------------------------------------------------------
// 3.5 API KEY (Generate / Regenerate / Revoke) - API Documents ট্যাব
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['generate_api_key', 'revoke_api_key'], true)) {
    $redirectTab = 'api';
    try {
        if ($_POST['action'] === 'generate_api_key') {
            $had    = !empty($currentUser['api_key']);
            $newKey = 'cm_' . bin2hex(random_bytes(20));
            $pdo->prepare("UPDATE users SET api_key = ? WHERE id = ?")->execute([$newKey, $user_id]);
            $currentUser['api_key'] = $newKey;
            $msg = $had ? "API key regenerated! Your old key no longer works." : "API key generated successfully!";
        } else {
            $pdo->prepare("UPDATE users SET api_key = NULL WHERE id = ?")->execute([$user_id]);
            $currentUser['api_key'] = null;
            $msg = "API key revoked! API access is now disabled for your account.";
        }
    } catch (Exception $e) {
        $error = "Could not update API key. Please try again!";
    }
}

// -------------------------------------------------------------
// 4. RESELLER CONTROLS OVER SUB-ACCOUNTS
// -------------------------------------------------------------
if ($isReseller) {
    // Create Sub-Account
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_sub_reseller') {
        $redirectTab = 'subreseller';
        $sub_user  = cleanInput($_POST['username'] ?? '');
        $sub_email = cleanInput($_POST['email'] ?? '');
        $sub_pass  = cleanInput($_POST['password'] ?? '');
        $sub_bal   = (float)($_POST['balance'] ?? 0.00);
        $sub_role  = cleanInput($_POST['role'] ?? 'sub_reseller');

        // সিকিউরিটি: শুধু এই দুই রোল দেওয়া যাবে (আগে POST বদলে admin রোল বানানো যেত)
        if (!in_array($sub_role, ['sub_reseller', 'user'], true)) $sub_role = 'sub_reseller';

        if (empty($sub_user) || empty($sub_email) || empty($sub_pass)) {
            $error = "All fields are required!";
        } elseif ($sub_bal < 0) {
            $error = "Initial balance cannot be negative!";
        } elseif ($sub_bal > $currentUser['balance']) {
            $error = "Insufficient balance to transfer!";
        } else {
            try {
                $pdo->beginTransaction();
                if ($sub_bal > 0) {
                    $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$sub_bal, $user_id]);
                    $currentUser['balance'] -= $sub_bal;
                }

                $hashed = password_hash($sub_pass, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO users (username, email, password, balance, role, created_by, can_deposit) VALUES (?, ?, ?, ?, ?, ?, 1)")
                    ->execute([$sub_user, $sub_email, $hashed, $sub_bal, $sub_role, $user_id]);

                if ($sub_bal > 0) {
                    $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'transfer', ?)")
                        ->execute([$user_id, $sub_bal, "Balance transfer to Sub-Account {$sub_user}"]);
                }

                $pdo->commit();
                $msg = "Sub-Account '{$sub_user}' created with {$sub_bal} balance!";
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Username or Email already registered!";
            }
        }
    }

    // Toggle Deposit Permission for Sub-Account
    if (isset($_GET['action']) && $_GET['action'] === 'toggle_sub_deposit' && isset($_GET['id'])) {
        $redirectTab = 'subreseller';
        $sub_id = (int)$_GET['id'];
        $chk = $pdo->prepare("SELECT can_deposit FROM users WHERE id = ? AND created_by = ?");
        $chk->execute([$sub_id, $user_id]);
        $sub = $chk->fetch();

        if ($sub) {
            $new_val = $sub['can_deposit'] == 1 ? 0 : 1;
            $pdo->prepare("UPDATE users SET can_deposit = ? WHERE id = ?")->execute([$new_val, $sub_id]);
            $msg = "Deposit access updated for sub-account!";
        }
    }

    // Ban / Unban Sub-Account
    if (isset($_GET['action']) && ($_GET['action'] === 'sub_ban' || $_GET['action'] === 'sub_unban') && isset($_GET['id'])) {
        $redirectTab = 'subreseller';
        $sub_id = (int)$_GET['id'];
        $status = ($_GET['action'] === 'sub_ban') ? 'banned' : 'active';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND created_by = ?")->execute([$status, $sub_id, $user_id]);
        $msg = "Sub-account status set to {$status}!";
    }

    // Delete Sub-Account with Balance Refund
    if (isset($_GET['action']) && $_GET['action'] === 'sub_delete' && isset($_GET['id'])) {
        $redirectTab = 'subreseller';
        $sub_id = (int)$_GET['id'];
        $chk = $pdo->prepare("SELECT username, balance FROM users WHERE id = ? AND created_by = ?");
        $chk->execute([$sub_id, $user_id]);
        $sub = $chk->fetch();

        if ($sub) {
            try {
                $pdo->beginTransaction();
                $refund = (float)$sub['balance'];

                // Refund remaining balance to reseller
                if ($refund > 0) {
                    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$refund, $user_id]);
                    $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'deposit', ?)")
                        ->execute([$user_id, $refund, "Refund from deleted sub-account ({$sub['username']})"]);
                    $currentUser['balance'] += $refund;
                }

                // Delete the sub-account
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$sub_id]);
                $pdo->commit();
                $msg = "Sub-account deleted! Refunded $" . number_format($refund, 2) . " back to your balance.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Deletion failed! Please try again.";
            }
        }
    }

    // Adjust Sub-Account Balance (+ / -)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_sub_balance') {
        $redirectTab = 'subreseller';
        $sub_id = (int)($_POST['sub_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);

        $chk = $pdo->prepare("SELECT username, balance FROM users WHERE id = ? AND created_by = ?");
        $chk->execute([$sub_id, $user_id]);
        $sub = $chk->fetch();

        if ($sub && $amount != 0) {
            try {
                if ($amount > 0) {
                    if ($currentUser['balance'] < $amount) {
                        $error = "Insufficient balance to add funds!";
                    } else {
                        $pdo->beginTransaction();
                        $d = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
                        $d->execute([$amount, $user_id, $amount]);
                        if ($d->rowCount() < 1) {
                            $pdo->rollBack();
                            $error = "Insufficient balance to add funds!";
                        } else {
                            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
                            $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'transfer', ?)")
                                ->execute([$user_id, $amount, "Added funds to {$sub['username']}"]);
                            $pdo->commit();
                            $currentUser['balance'] -= $amount;
                            $msg = "Added $" . number_format($amount, 2) . " to {$sub['username']}!";
                        }
                    }
                } else {
                    $deduct = abs($amount);
                    if ($sub['balance'] < $deduct) {
                        $error = "Sub-account does not have enough balance to deduct!";
                    } else {
                        $pdo->beginTransaction();
                        $d = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
                        $d->execute([$deduct, $sub_id, $deduct]);
                        if ($d->rowCount() < 1) {
                            $pdo->rollBack();
                            $error = "Sub-account does not have enough balance to deduct!";
                        } else {
                            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$deduct, $user_id]);
                            $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'deposit', ?)")
                                ->execute([$user_id, $deduct, "Deducted & refunded from {$sub['username']}"]);
                            $pdo->commit();
                            $currentUser['balance'] += $deduct;
                            $msg = "Deducted $" . number_format($deduct, 2) . " from {$sub['username']} and refunded to your account!";
                        }
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Balance update failed! Please try again.";
            }
        }
    }
}

// -------------------------------------------------------------
// Post/Redirect/Get: action শেষ হলে redirect (refresh করলে আবার action চলবে না)
// -------------------------------------------------------------
if ($redirectTab !== null && ($msg !== '' || $error !== '' || !empty($generated_keys))) {
    $_SESSION['flash'] = ['msg' => $msg, 'error' => $error, 'keys' => $generated_keys];
    $qs = 'tab=' . urlencode($redirectTab);
    if (!empty($redirectExtra)) $qs .= '&' . http_build_query($redirectExtra);
    header("Location: dashboard.php?" . $qs);
    exit();
}

// Queries (অ্যাডমিন ট্র্যাশ করা key ইউজারের কাছে দেখাবে না)
$plans = $pdo->query("SELECT * FROM plans ORDER BY id ASC")->fetchAll();

$k_stmt = $pdo->prepare("SELECT * FROM licenses WHERE user_id = ? AND is_deleted = 0 ORDER BY id DESC");
$k_stmt->execute([$user_id]);
$myLicenses = $k_stmt->fetchAll();

// Handle Key Search for HWID Reset
$search_key = cleanInput($_GET['search_key'] ?? '');
$searchedLicense = null;
$searchAttempted = false;

if (!empty($search_key)) {
    $searchAttempted = true;
    $srch = $pdo->prepare("SELECT * FROM licenses WHERE user_id = ? AND is_deleted = 0 AND license_key = ?");
    $srch->execute([$user_id, $search_key]);
    $searchedLicense = $srch->fetch();
    if (!$searchedLicense) {
        $srch_like = $pdo->prepare("SELECT * FROM licenses WHERE user_id = ? AND is_deleted = 0 AND license_key LIKE ?");
        $srch_like->execute([$user_id, '%' . $search_key . '%']);
        $searchedLicense = $srch_like->fetch();
    }
}

$t_stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50");
$t_stmt->execute([$user_id]);
$transactions = $t_stmt->fetchAll();

$dep_stmt = $pdo->prepare("SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 20");
$dep_stmt->execute([$user_id]);
$myDeposits = $dep_stmt->fetchAll();

$subResellers = [];
if ($isReseller) {
    $s_stmt = $pdo->prepare("SELECT * FROM users WHERE created_by = ? ORDER BY id DESC");
    $s_stmt->execute([$user_id]);
    $subResellers = $s_stmt->fetchAll();
}

$totalKeys = count($myLicenses);
$activeKeys = count(array_filter($myLicenses, fn($l) => $l['status'] === 'active'));
$unusedKeys = count(array_filter($myLicenses, fn($l) => $l['status'] === 'unused'));

// Game-wise stats (FreeFire / PUBG)
$gameStats = [];
foreach ($games as $gk => $g) $gameStats[$gk] = ['total' => 0, 'active' => 0, 'unused' => 0];
foreach ($myLicenses as $l) {
    $gk = normalizeGame($l['game_type'] ?? 'FreeFire');
    $gameStats[$gk]['total']++;
    if ($l['status'] === 'active') $gameStats[$gk]['active']++;
    if ($l['status'] === 'unused') $gameStats[$gk]['unused']++;
}

// Plans grouped by game + JS data
$plansByGame = [];
foreach ($games as $gk => $g) $plansByGame[$gk] = [];
$plansJs = [];
foreach ($plans as $p) {
    $gk = normalizeGame($p['game_type'] ?? 'FreeFire');
    $plansByGame[$gk][] = $p;
    $plansJs[] = [
        'id'    => (int)$p['id'],
        'game'  => $gk,
        'label' => strtoupper($p['duration_days'] . ' ' . ($p['duration_type'] ?? 'Days')) . ' - $' . number_format($p['price'], 2),
        'price' => (float)$p['price'],
    ];
}

// API Documents ট্যাবের জন্য base URL
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$dirPath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
$apiBase = $scheme . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . $dirPath . '/reseller_api.php';
$apiKeyShown = !empty($currentUser['api_key']) ? $currentUser['api_key'] : 'YOUR_API_KEY';

// Auto open requested tab (?tab=...) or reset tab on search
$validTabs = ['dashboard', 'generate', 'reset', 'products', 'api', 'deposit', 'subreseller', 'history', 'transactions'];
$reqTab = $_GET['tab'] ?? '';
$default_open_tab = in_array($reqTab, $validTabs, true) ? $reqTab : ($searchAttempted ? 'reset' : '');

// Reset-limit label helper
$resetLimitLabel = ($resetLimit > 0) ? "{$resetLimit} times per day" : "unlimited times per day";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- STRICT ZOOM OFF -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
    <title>CRACKMASTER | Client Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; touch-action:manipulation; }
        html, body { background:#090615; color:#cbd5e1; min-height:100vh; overflow-x:hidden; -webkit-text-size-adjust:100%; overscroll-behavior:none; }

        @keyframes glowCard {
            0% { box-shadow: 0 0 20px rgba(168,85,247,0.15); }
            50% { box-shadow: 0 0 35px rgba(168,85,247,0.3); }
            100% { box-shadow: 0 0 20px rgba(168,85,247,0.15); }
        }
        @keyframes slideInUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }

        input, select, textarea, button { font-size:16px !important; outline:none; }

        /* 3-Line Sidebar & Dark Overlay */
        .sidebar-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(5px); z-index:99; display:none; }
        .sidebar-overlay.active { display:block; }

        .sidebar { width:275px; background:#0e0822; border-right:1px solid #1f1442; display:flex; flex-direction:column; position:fixed; top:0; bottom:0; left:0; z-index:100; transition:all 0.3s cubic-bezier(0.4,0,0.2,1); padding:16px; transform:translateX(-100%); }
        .sidebar.active { transform:translateX(0); }
        .sidebar-header { display:flex; align-items:center; justify-content:space-between; padding-bottom:18px; border-bottom:1px solid #1f1442; margin-bottom:12px; }
        .sidebar-brand { font-size:19px; font-weight:900; color:#fff; display:flex; align-items:center; gap:8px; }
        .sidebar-brand i { color:#a855f7; }
        .sidebar-brand span { color:#c084fc; }
        .btn-close-sidebar { background:#1a1038; border:1px solid #271656; color:#c084fc; width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:16px; }

        .sidebar-menu { display:flex; flex-direction:column; gap:6px; flex:1; overflow-y:auto; }
        .nav-btn { display:flex; align-items:center; gap:14px; padding:13px 16px; border-radius:12px; color:#cbd5e1; font-size:14px; font-weight:700; text-decoration:none; border:none; background:none; width:100%; text-align:left; cursor:pointer; transition:0.2s; }
        .nav-btn i { font-size:16px; width:20px; text-align:center; }
        .nav-btn:hover { color:#fff; background:rgba(168,85,247,0.12); }
        .nav-btn.active { background:linear-gradient(135deg, #9333ea, #a855f7); color:#fff; box-shadow:0 8px 25px rgba(168,85,247,0.45); }

        /* Main Container */
        .main-wrapper { flex:1; min-height:100vh; display:flex; flex-direction:column; width:100%; }
        .topbar { background:#0f0927; padding:15px 20px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #1f1442; position:sticky; top:0; z-index:90; box-shadow:0 4px 20px rgba(0,0,0,0.4); }
        .btn-hamburger { background:#1a1038; border:1px solid #2e1e5e; color:#c084fc; width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:18px; }
        .bal-badge { background:rgba(168,85,247,0.15); color:#d8b4fe; border:1px solid rgba(168,85,247,0.3); padding:8px 16px; border-radius:12px; font-size:13.5px; font-weight:800; display:flex; align-items:center; gap:8px; }

        .content { padding:20px; max-width:1150px; width:100%; margin:0 auto; }
        .tab-content { display:none; }
        .tab-content.active { display:block; animation:slideInUp 0.3s ease-in-out; }

        /* Sub-sections Pills in Sub-Reseller Tab */
        .sub-nav-pills { display:flex; gap:10px; overflow-x:auto; white-space:nowrap; padding-bottom:14px; margin-bottom:18px; border-bottom:1px solid #1f1442; scrollbar-width:none; }
        .sub-nav-pills::-webkit-scrollbar { display:none; }
        .sub-pill-btn { display:inline-flex; align-items:center; gap:8px; background:#0e0822; border:1px solid #271656; color:#94a3b8; font-weight:700; font-size:12.5px; padding:9px 16px; border-radius:10px; cursor:pointer; }
        .sub-pill-btn i { color:#c084fc; }
        .sub-pill-btn.active { background:#1a1038; color:#fff; border-color:#a855f7; box-shadow:0 0 15px rgba(168,85,247,0.3); }

        .sub-view { display:none; }
        .sub-view.active { display:block; }

        /* Alerts */
        .alert-box { padding:14px 18px; border-radius:14px; font-size:13px; margin-bottom:18px; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:10px; animation:slideInUp 0.3s ease; box-shadow:0 6px 20px rgba(0,0,0,0.4); }
        .alert-ok { background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.35); color:#6ee7b7; }
        .alert-bad { background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.35); color:#fca5a5; }
        .alert-close { background:none; border:none; color:inherit; font-size:16px; cursor:pointer; padding:2px 6px; }

        /* Stats Grid */
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:22px; }
        .stat-card { background:#0f0927; padding:22px; border-radius:18px; border:1px solid #21134a; display:flex; justify-content:space-between; align-items:center; box-shadow:0 8px 25px rgba(0,0,0,0.5); }
        .stat-card h4 { font-size:11px; text-transform:uppercase; color:#7e6d9e; font-weight:800; margin-bottom:6px; }
        .stat-card .val { font-size:26px; font-weight:900; color:#fff; }
        .stat-icon { font-size:22px; padding:12px; border-radius:14px; color:#c084fc; background:rgba(168,85,247,0.12); }

        .panel { background:#0f0927; padding:22px; border-radius:20px; border:1px solid #21134a; margin-bottom:20px; box-shadow:0 8px 25px rgba(0,0,0,0.4); }
        .panel h3 { font-size:16px; font-weight:800; color:#fff; margin-bottom:16px; display:flex; align-items:center; gap:10px; }
        .panel h3 i { color:#c084fc; }

        /* Key Generator Card */
        .gen-card { max-width:440px; margin:15px auto; background:#0f0927; border:1px solid #271656; border-radius:24px; padding:28px 24px; box-shadow:0 0 45px rgba(147,51,234,0.2); animation:glowCard 4s infinite ease-in-out; }
        .gen-header { text-align:center; margin-bottom:24px; }
        .gen-header h2 { font-size:19px; font-weight:900; color:#fff; letter-spacing:1px; display:flex; align-items:center; justify-content:center; gap:8px; text-transform:uppercase; }
        .gen-header h2 i { color:#c084fc; }
        .gen-header p { font-size:12px; color:#8b7ba8; margin-top:5px; }

        .field-label { display:flex; align-items:center; gap:8px; font-size:11.5px; font-weight:800; color:#a392c4; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; }
        .gen-select, .gen-input { width:100%; background:#090518; border:1px solid #271656; border-radius:14px; padding:13px 16px; color:#fff; font-size:14px !important; font-weight:700; outline:none; }
        .gen-select:focus, .gen-input:focus { border-color:#a855f7; box-shadow:0 0 15px rgba(168,85,247,0.3); }

        .qty-control { display:flex; align-items:center; gap:12px; margin-bottom:20px; }
        .btn-qty { width:52px; height:52px; border-radius:14px; border:none; background:linear-gradient(135deg, #a855f7, #9333ea); color:#fff; font-size:22px; font-weight:800; display:flex; align-items:center; justify-content:center; cursor:pointer; }
        .qty-input { flex:1; height:52px; background:#090518; border:1px solid #271656; border-radius:14px; color:#fff; font-size:18px !important; font-weight:800; text-align:center; }

        .btn-generate { width:100%; height:52px; border-radius:16px; border:none; background:linear-gradient(135deg, #a855f7, #7e22ce); color:#fff; font-size:13.5px; font-weight:900; letter-spacing:1px; text-transform:uppercase; cursor:pointer; box-shadow:0 0 30px rgba(168,85,247,0.55); display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn-generate:hover { box-shadow:0 0 45px rgba(168,85,247,0.8); }

        .table-responsive { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; text-align:left; font-size:12.5px; }
        th { background:#0a061a; padding:12px 14px; color:#7e6d9e; text-transform:uppercase; font-size:11px; border-bottom:1px solid #21134a; }
        td { padding:13px 14px; border-bottom:1px solid #190e38; vertical-align:middle; }
        .key-text { font-family:monospace; font-weight:700; color:#c084fc; background:rgba(168,85,247,0.1); padding:5px 9px; border-radius:7px; border:1px solid rgba(168,85,247,0.25); user-select:all; }

        .badge { padding:4px 9px; border-radius:6px; font-size:10px; font-weight:800; text-transform:uppercase; display:inline-block; }
        .badge-active { background:rgba(16,185,129,0.15); color:#34d399; border:1px solid rgba(16,185,129,0.3); }
        .badge-unused { background:rgba(234,179,8,0.15); color:#facc15; border:1px solid rgba(234,179,8,0.3); }
        .badge-expired, .badge-banned { background:rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(239,68,68,0.3); }

        .btn-sm { padding:6px 12px; border-radius:8px; font-size:11px; text-decoration:none; font-weight:800; display:inline-flex; align-items:center; gap:5px; border:none; cursor:pointer; }
        .btn-green { background:rgba(16,185,129,0.2); color:#34d399; }
        .btn-red { background:rgba(239,68,68,0.2); color:#f87171; }
        .btn-purple { background:rgba(168,85,247,0.2); color:#c084fc; border:1px solid rgba(168,85,247,0.3); }
        .btn-purple:hover { background:rgba(168,85,247,0.35); }

        .deposit-methods { display:grid; grid-template-columns:repeat(auto-fit, minmax(110px, 1fr)); gap:10px; margin-bottom:20px; }
        .method-btn { background:#090518; border:1px solid #271656; border-radius:14px; padding:12px 10px; color:#cbd5e1; font-weight:800; font-size:12px; cursor:pointer; text-align:center; }
        .method-btn.active { border-color:#a855f7; background:rgba(168,85,247,0.15); color:#fff; }

        .modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.88); backdrop-filter:blur(10px); display:flex; align-items:center; justify-content:center; z-index:200; padding:15px; }
        .modal-box { background:#0f0927; border:1px solid #a855f7; border-radius:24px; padding:26px; width:100%; max-width:440px; box-shadow:0 0 50px rgba(168,85,247,0.4); text-align:center; }
        .keys-textarea { width:100%; height:130px; background:#090518; border:1px solid #271656; border-radius:12px; padding:12px; color:#c084fc; font-family:monospace; font-size:13px !important; font-weight:700; resize:none; margin:15px 0; }
        .btn-copy-all { width:100%; background:linear-gradient(135deg, #a855f7, #7e22ce); border:none; padding:13px; border-radius:12px; color:#fff; font-weight:900; font-size:13px; cursor:pointer; text-transform:uppercase; }

        /* ---- NEW: Game badges, Products, API Docs ---- */
        .badge-ff { background:rgba(239,68,68,0.15); color:#ff8a8a; border:1px solid rgba(239,68,68,0.35); }
        .badge-pubg { background:rgba(245,158,11,0.15); color:#fbbf24; border:1px solid rgba(245,158,11,0.35); }
        .game-quick { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
        .btn-game { border:1px solid #271656; background:#090518; color:#fff; border-radius:14px; padding:13px 14px; font-weight:800; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn-game:hover { border-color:#a855f7; box-shadow:0 0 15px rgba(168,85,247,0.3); }

        .product-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin-bottom:20px; }
        .product-card { background:#0f0927; border:1px solid #21134a; border-top:3px solid var(--accent, #a855f7); border-radius:20px; padding:20px; box-shadow:0 8px 25px rgba(0,0,0,0.4); }
        .product-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:10px; }
        .product-head h4 { font-size:16px; font-weight:900; color:#fff; display:flex; align-items:center; gap:10px; }
        .product-head h4 i { color:var(--accent, #a855f7); }
        .mini-stats { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-bottom:14px; }
        .mini-stat { background:#090518; border:1px solid #1f1442; border-radius:12px; padding:10px 6px; text-align:center; }
        .mini-stat b { display:block; font-size:18px; color:#fff; }
        .mini-stat span { font-size:10px; color:#7e6d9e; text-transform:uppercase; font-weight:800; }
        .plan-row { display:flex; justify-content:space-between; align-items:center; padding:9px 12px; border-radius:10px; background:#090518; border:1px solid #1f1442; margin-bottom:7px; font-size:12.5px; }
        .plan-row b { color:#fff; }
        .plan-row .price { color:#34d399; font-weight:900; }
        .service-list { list-style:none; display:grid; gap:10px; }
        .service-list li { display:flex; gap:12px; align-items:flex-start; background:#090518; border:1px solid #1f1442; border-radius:14px; padding:13px 14px; font-size:13px; }
        .service-list li i { color:#34d399; margin-top:3px; }
        .service-list li.off i { color:#f87171; }
        .service-list li b { color:#fff; display:block; margin-bottom:2px; }
        .service-list li span { color:#8b7ba8; font-size:12px; }

        .api-key-box { display:flex; gap:8px; flex-wrap:wrap; align-items:center; background:#090518; border:1px solid #271656; border-radius:14px; padding:12px; }
        .api-key-box input { flex:1; min-width:200px; background:transparent; border:none; color:#c084fc; font-family:monospace; font-weight:700; font-size:13px !important; }
        .code-block { position:relative; background:#05030f; border:1px solid #21134a; border-radius:12px; padding:14px 14px 14px; margin:8px 0 16px; overflow-x:auto; }
        .code-block pre { padding-right:64px; color:#d8b4fe; font-family:Consolas,Monaco,monospace; font-size:12px; line-height:1.55; white-space:pre; }
        .code-block .copy-code { position:absolute; top:8px; right:8px; background:#1f1442; color:#c084fc; border:1px solid #382468; border-radius:8px; padding:4px 10px; font-size:11px !important; font-weight:800; cursor:pointer; }
        .doc-h { font-size:12px; font-weight:800; color:#c084fc; text-transform:uppercase; letter-spacing:0.5px; margin:18px 0 6px; display:flex; align-items:center; gap:8px; }
        .method-tag { display:inline-block; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:900; background:rgba(16,185,129,0.15); color:#34d399; border:1px solid rgba(16,185,129,0.3); }
        .info-note { background:rgba(168,85,247,0.08); border:1px solid rgba(168,85,247,0.25); border-radius:12px; padding:12px 14px; font-size:12.5px; color:#d8b4fe; margin-bottom:14px; }
    </style>
</head>
<body>

    <!-- 3-LINE SIDEBAR (WITH OVERLAY & CLOSE BUTTON) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar(false)"></div>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <i class="fa-solid fa-bolt"></i>
                CRACK<span>MASTER</span>
            </div>
            <button type="button" class="btn-close-sidebar" onclick="toggleSidebar(false)">&times;</button>
        </div>

        <div class="sidebar-menu">
            <button class="nav-btn active" onclick="switchTab('dashboard', this)">
                <i class="fa-solid fa-chart-pie"></i> Dashboard
            </button>
            <button class="nav-btn" onclick="switchTab('generate', this)">
                <i class="fa-solid fa-bolt"></i> Generate Keys
            </button>
            <button class="nav-btn" onclick="switchTab('reset', this)">
                <i class="fa-solid fa-rotate-left"></i> Reset Key
            </button>
            <button class="nav-btn" onclick="switchTab('products', this)">
                <i class="fa-solid fa-box-open"></i> Products Service
            </button>
            <button class="nav-btn" onclick="switchTab('api', this)">
                <i class="fa-solid fa-code"></i> API Documents
            </button>

            <!-- Conditional Deposit Button (Hidden if turned off for user) -->
            <?php if ($deposit_enabled): ?>
                <button class="nav-btn" onclick="switchTab('deposit', this)">
                    <i class="fa-solid fa-wallet"></i> Deposit Balance
                </button>
            <?php endif; ?>

            <?php if ($isReseller): ?>
                <button class="nav-btn" onclick="switchTab('subreseller', this)">
                    <i class="fa-solid fa-users-gear"></i> Sub Reseller
                </button>
            <?php endif; ?>
            <button class="nav-btn" onclick="switchTab('history', this)">
                <i class="fa-solid fa-clock-rotate-left"></i> Key History
            </button>
            <button class="nav-btn" onclick="switchTab('transactions', this)">
                <i class="fa-solid fa-money-bill-transfer"></i> Transactions
            </button>
        </div>
        <div style="padding-top:15px; border-top:1px solid #1f1442;">
            <a href="dashboard.php?action=logout" class="nav-btn" style="color:#f87171;"><i class="fa-solid fa-power-off"></i> Logout</a>
        </div>
    </div>


    <!-- MAIN BODY -->
    <div class="main-wrapper">
        <div class="topbar">
            <div style="display:flex; align-items:center; gap:14px;">
                <button class="btn-hamburger" onclick="toggleSidebar(true)"><i class="fa-solid fa-bars"></i></button>
                <h2 style="font-size:16px; font-weight:800; color:#fff;" id="topbarTitle">Dashboard</h2>
            </div>
            <div class="bal-badge">
                <i class="fa-solid fa-wallet"></i> $<?= number_format($currentUser['balance'], 2); ?>
            </div>
        </div>

        <div class="content">
            <?php if (!empty($msg)): ?>
                <div class="alert-box alert-ok" id="alertBox">
                    <span><i class="fa-solid fa-circle-check"></i> <?= $msg; ?></span>
                    <button class="alert-close" onclick="dismissAlert()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert-box alert-bad" id="alertBox">
                    <span><i class="fa-solid fa-circle-xmark"></i> <?= $error; ?></span>
                    <button class="alert-close" onclick="dismissAlert()">&times;</button>
                </div>
            <?php endif; ?>


            <!-- TAB 1: DASHBOARD -->
            <div id="tab-dashboard" class="tab-content active">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div><h4>Total Keys</h4><div class="val"><?= $totalKeys; ?></div></div>
                        <div class="stat-icon"><i class="fa-solid fa-key"></i></div>
                    </div>
                    <div class="stat-card">
                        <div><h4>Active Keys</h4><div class="val"><?= $activeKeys; ?></div></div>
                        <div class="stat-icon" style="color:#34d399; background:rgba(16,185,129,0.12);"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div class="stat-card">
                        <div><h4>Unused Keys</h4><div class="val"><?= $unusedKeys; ?></div></div>
                        <div class="stat-icon" style="color:#facc15; background:rgba(234,179,8,0.12);"><i class="fa-solid fa-hourglass-half"></i></div>
                    </div>
                    <?php foreach ($games as $gk => $g): ?>
                    <div class="stat-card">
                        <div><h4><?= $g['label']; ?> Keys</h4><div class="val" style="color:<?= $g['color']; ?>;"><?= $gameStats[$gk]['total']; ?></div></div>
                        <div class="stat-icon" style="color:<?= $g['color']; ?>;"><i class="fa-solid <?= $g['icon']; ?>"></i></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="panel">
                    <h3><i class="fa-solid fa-bolt"></i> Quick Key Generator</h3>
                    <p style="color:#8b7ba8; font-size:13px; margin-bottom:15px;">Choose a game and generate single or bulk license keys in seconds.</p>
                    <div class="game-quick">
                        <?php foreach ($games as $gk => $g): ?>
                            <button type="button" class="btn-game" onclick="openGenerator('<?= $gk; ?>')">
                                <i class="fa-solid <?= $g['icon']; ?>" style="color:<?= $g['color']; ?>;"></i> <?= $g['label']; ?> Keys
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- TAB 2: KEY GENERATOR (FreeFire / PUBG) -->
            <div id="tab-generate" class="tab-content">
                <div class="gen-card">
                    <div class="gen-header">
                        <h2><i class="fa-solid fa-bolt"></i> Key Generator</h2>
                        <p>Generate license keys for your customers</p>
                    </div>

                    <form method="POST" action="dashboard.php">
                        <input type="hidden" name="action" value="generate_keys">
                        <div style="margin-bottom:18px;">
                            <label class="field-label"><i class="fa-solid fa-box-archive"></i> Product Type:</label>
                            <select name="game_type" id="game_select" class="gen-select" onchange="onGameChange()">
                                <?php foreach ($games as $gk => $g): ?>
                                    <option value="<?= $gk; ?>"><?= $g['emoji'] . ' ' . $g['label']; ?> - CrackMaster VIP Engine</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="margin-bottom:18px;">
                            <label class="field-label"><i class="fa-solid fa-hourglass-half"></i> Validity:</label>
                            <select name="plan_id" id="plan_select" class="gen-select" onchange="updatePrice()" required>
                                <option value="">No plans available</option>
                            </select>
                        </div>

                        <div style="margin-bottom:10px;">
                            <label class="field-label"><i class="fa-solid fa-cubes-stacked"></i> Quantity:</label>
                            <div class="qty-control">
                                <button type="button" class="btn-qty" onclick="changeQty(-1)">-</button>
                                <input type="number" name="quantity" id="qty_box" class="qty-input" value="1" min="1" max="50" readonly>
                                <button type="button" class="btn-qty" onclick="changeQty(1)">+</button>
                            </div>
                        </div>

                        <button type="submit" class="btn-generate" id="btn_submit_gen" <?= empty($plans) ? 'disabled' : ''; ?>>
                            <i class="fa-solid fa-bolt"></i> GENERATE KEYS ($0.00)
                        </button>
                    </form>
                </div>
            </div>

            <!-- TAB 3: RESET KEY (SEARCH + DAILY LIMIT FROM ADMIN PANEL) -->
            <div id="tab-reset" class="tab-content">
                <div class="panel">
                    <h3><i class="fa-solid fa-rotate-left"></i> Device HWID Reset</h3>
                    <p style="color:#8b7ba8; font-size:12.5px; margin-bottom:15px;">Search and reset key to unbind from current phone. Each key can be reset <b><?= $resetLimitLabel; ?></b>. The same limit applies to the API.</p>

                    <!-- SEARCH KEY BOX -->
                    <div style="background:#090518; border:1px solid #271656; border-radius:16px; padding:18px; margin-bottom:22px;">
                        <label class="field-label"><i class="fa-solid fa-magnifying-glass"></i> Search License Key to Reset</label>
                        <form method="GET" action="dashboard.php" style="display:flex; gap:10px; flex-wrap:wrap;">
                            <input type="hidden" name="tab" value="reset">
                            <input type="text" name="search_key" class="gen-input" style="flex:1; min-width:220px;" placeholder="Paste license key here (e.g. FF-XXXXXX-XXXXXX)..." value="<?= htmlspecialchars($search_key); ?>" required>
                            <button type="submit" class="btn-generate" style="width:auto; padding:0 24px; height:48px;">
                                <i class="fa-solid fa-magnifying-glass"></i> Search Key
                            </button>
                            <?php if ($searchAttempted): ?>
                                <a href="dashboard.php?tab=reset" class="btn-sm btn-purple" style="height:48px; padding:0 16px; display:inline-flex; align-items:center; text-decoration:none; border-radius:12px;">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- SEARCH RESULT CARD (IF SEARCHED) -->
                    <?php if ($searchAttempted): ?>
                        <div style="margin-bottom:25px;">
                            <h4 style="font-size:12.5px; color:#c084fc; text-transform:uppercase; margin-bottom:10px;">
                                <i class="fa-solid fa-bullseye"></i> Search Result
                            </h4>
                            <?php if ($searchedLicense):
                                $d_resets  = resetsUsedToday($searchedLicense, $today);
                                $left_today = ($resetLimit > 0) ? max(0, $resetLimit - $d_resets) : null;
                                $canResetS  = ($left_today === null || $left_today > 0);
                                $sg = normalizeGame($searchedLicense['game_type'] ?? 'FreeFire');
                            ?>
                                <div style="background:#0e0822; border:1px solid #a855f7; border-radius:16px; padding:18px; box-shadow:0 0 25px rgba(168,85,247,0.25);">
                                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                                        <div>
                                            <div style="margin-bottom:6px;">
                                                <span class="badge <?= $sg === 'Pubg' ? 'badge-pubg' : 'badge-ff'; ?>"><?= $games[$sg]['label']; ?></span>
                                                <span class="key-text" style="font-size:15px;"><?= htmlspecialchars($searchedLicense['license_key']); ?></span>
                                            </div>
                                            <div style="font-size:12px; color:#8b7ba8; margin-bottom:4px;">
                                                Device HWID: <code style="color:#d8b4fe;"><?= !empty($searchedLicense['device_id']) ? htmlspecialchars($searchedLicense['device_id']) : 'No Device Bound (Unlocked)'; ?></code>
                                            </div>
                                            <div style="font-size:12px; font-weight:800; color:<?= $canResetS ? '#34d399' : '#f87171'; ?>;">
                                                Today's Resets Left: <?= $left_today === null ? 'Unlimited' : $left_today . ' / ' . $resetLimit; ?>
                                            </div>
                                        </div>
                                        <div>
                                            <?php if (empty($searchedLicense['device_id'])): ?>
                                                <span style="color:#facc15; font-weight:800; font-size:12px;"><i class="fa-solid fa-lock-open"></i> Nothing to reset</span>
                                            <?php elseif ($canResetS): ?>
                                                <a href="dashboard.php?tab=reset&action=reset&id=<?= $searchedLicense['id']; ?>&search_key=<?= urlencode($searchedLicense['license_key']); ?>" class="btn-generate" style="width:auto; height:44px; padding:0 20px; font-size:12px; text-decoration:none;" onclick="return confirm('Reset device lock for this key now?');">
                                                    <i class="fa-solid fa-rotate-left"></i> Reset Now
                                                </a>
                                            <?php else: ?>
                                                <button class="btn-sm btn-red" disabled style="padding:10px 16px; font-size:12px; opacity:0.8; cursor:not-allowed;">
                                                    <i class="fa-solid fa-ban"></i> Daily Limit Reached (<?= $resetLimit; ?>/<?= $resetLimit; ?>)
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="background:#090518; border:1px solid #ef4444; border-radius:14px; padding:16px; color:#fca5a5; font-size:13px; font-weight:700;">
                                    <i class="fa-solid fa-circle-exclamation"></i> No license key found matching "<?= htmlspecialchars($search_key); ?>" in your account.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- ALL BOUND KEYS LIST -->
                    <h4 style="font-size:12px; color:#7e6d9e; text-transform:uppercase; margin-bottom:12px;">All Device-Bound Keys</h4>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>Game</th><th>Key</th><th>Device HWID</th><th>Today's Limit</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php
                                $boundKeys = array_filter($myLicenses, fn($l) => !empty($l['device_id']));
                                if (empty($boundKeys)):
                                ?>
                                    <tr><td colspan="5" style="text-align:center; padding:25px; color:#8b7ba8;">No device-locked keys found.</td></tr>
                                <?php else: foreach ($boundKeys as $l):
                                    $cur_daily = resetsUsedToday($l, $today);
                                    $left_row  = ($resetLimit > 0) ? max(0, $resetLimit - $cur_daily) : null;
                                    $can_reset = ($left_row === null || $left_row > 0);
                                    $lg = normalizeGame($l['game_type'] ?? 'FreeFire');
                                ?>
                                    <tr>
                                        <td><span class="badge <?= $lg === 'Pubg' ? 'badge-pubg' : 'badge-ff'; ?>"><?= $games[$lg]['short']; ?></span></td>
                                        <td><span class="key-text"><?= htmlspecialchars($l['license_key']); ?></span></td>
                                        <td><code style="color:#8b7ba8;"><?= htmlspecialchars(substr($l['device_id'], 0, 18)); ?>...</code></td>
                                        <td>
                                            <span class="badge <?= $can_reset ? 'badge-active' : 'badge-banned'; ?>">
                                                <?= $left_row === null ? 'Unlimited' : $left_row . ' / ' . $resetLimit . ' Left'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($can_reset): ?>
                                                <a href="dashboard.php?tab=reset&action=reset&id=<?= $l['id']; ?>" class="btn-sm btn-purple" onclick="return confirm('Reset device lock for this key?');">
                                                    <i class="fa-solid fa-rotate-left"></i> Reset Now
                                                </a>
                                            <?php else: ?>
                                                <span style="color:#f87171; font-weight:800; font-size:11px;"><i class="fa-solid fa-ban"></i> Limit Reached</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB: PRODUCTS SERVICE -->
            <div id="tab-products" class="tab-content">
                <div class="panel">
                    <h3><i class="fa-solid fa-box-open"></i> Products Service</h3>
                    <p style="color:#8b7ba8; font-size:13px;">All available games, their plans &amp; prices, and your key stock for each product.</p>
                </div>

                <div class="product-grid">
                    <?php foreach ($games as $gk => $g): ?>
                    <div class="product-card" style="--accent:<?= $g['color']; ?>;">
                        <div class="product-head">
                            <h4><i class="fa-solid <?= $g['icon']; ?>"></i> <?= $g['label']; ?> VIP Engine</h4>
                            <span class="badge <?= $gk === 'Pubg' ? 'badge-pubg' : 'badge-ff'; ?>"><?= count($plansByGame[$gk]); ?> Plans</span>
                        </div>

                        <div class="mini-stats">
                            <div class="mini-stat"><b><?= $gameStats[$gk]['total']; ?></b><span>Total</span></div>
                            <div class="mini-stat"><b style="color:#34d399;"><?= $gameStats[$gk]['active']; ?></b><span>Active</span></div>
                            <div class="mini-stat"><b style="color:#facc15;"><?= $gameStats[$gk]['unused']; ?></b><span>Unused</span></div>
                        </div>

                        <?php if (empty($plansByGame[$gk])): ?>
                            <div class="plan-row" style="justify-content:center; color:#8b7ba8;">No plans available yet</div>
                        <?php else: foreach ($plansByGame[$gk] as $p): ?>
                            <div class="plan-row">
                                <span><b><?= htmlspecialchars($p['name']); ?></b> &bull; <?= (int)$p['duration_days'] . ' ' . ucfirst($p['duration_type'] ?? 'days'); ?></span>
                                <span class="price">$<?= number_format($p['price'], 2); ?></span>
                            </div>
                        <?php endforeach; endif; ?>

                        <button type="button" class="btn-generate" style="margin-top:12px; height:46px;" onclick="openGenerator('<?= $gk; ?>')" <?= empty($plansByGame[$gk]) ? 'disabled' : ''; ?>>
                            <i class="fa-solid fa-bolt"></i> Generate <?= $g['label']; ?> Keys
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="panel">
                    <h3><i class="fa-solid fa-layer-group"></i> Included Services</h3>
                    <ul class="service-list">
                        <li><i class="fa-solid fa-circle-check"></i><div><b>Instant Key Generation</b><span>Generate up to 50 keys at once for FreeFire and PUBG.</span></div></li>
                        <li><i class="fa-solid fa-circle-check"></i><div><b>Device HWID Reset</b><span>Reset a key <?= $resetLimitLabel; ?> (from the panel or the API).</span></div></li>
                        <li class="<?= $apiEnabled ? '' : 'off'; ?>"><i class="fa-solid <?= $apiEnabled ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i><div><b>Reseller API</b><span><?= $apiEnabled ? 'Reset keys, check key info and balance from your own bot/website. See API Documents.' : 'API access is currently disabled by the administrator.'; ?></span></div></li>
                        <?php if ($isReseller): ?>
                        <li><i class="fa-solid fa-circle-check"></i><div><b>Sub-Reseller System</b><span>Create sub-accounts, transfer balance and manage them.</span></div></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- TAB: API DOCUMENTS -->
            <div id="tab-api" class="tab-content">
                <div class="panel">
                    <h3><i class="fa-solid fa-code"></i> API Documents</h3>
                    <p style="color:#8b7ba8; font-size:13px; margin-bottom:14px;">Automate key resets from your own bot or website. Daily limit: <b style="color:#fff;"><?= $resetLimit > 0 ? $resetLimit . ' resets per key per day' : 'Unlimited'; ?></b> (shared with the panel).</p>

                    <?php if (!$apiEnabled): ?>
                        <div class="info-note" style="border-color:rgba(239,68,68,0.4); color:#fca5a5; background:rgba(239,68,68,0.08);"><i class="fa-solid fa-triangle-exclamation"></i> API access is currently disabled by the administrator. Requests will be rejected until it is enabled again.</div>
                    <?php endif; ?>

                    <div class="doc-h"><i class="fa-solid fa-key"></i> Your API Key</div>
                    <?php if (!empty($currentUser['api_key'])): ?>
                        <div class="api-key-box">
                            <input type="password" id="apiKeyField" value="<?= htmlspecialchars($currentUser['api_key']); ?>" readonly>
                            <button type="button" class="btn-sm btn-purple" onclick="toggleApiKey()"><i class="fa-solid fa-eye"></i> Show</button>
                            <button type="button" class="btn-sm btn-purple" onclick="copyText(document.getElementById('apiKeyField').value, 'API key copied!')"><i class="fa-solid fa-copy"></i> Copy</button>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
                            <form method="POST" action="dashboard.php" onsubmit="return confirm('Generate a new API key? Your old key will stop working immediately.');">
                                <input type="hidden" name="action" value="generate_api_key">
                                <button type="submit" class="btn-sm btn-purple"><i class="fa-solid fa-arrows-rotate"></i> Regenerate</button>
                            </form>
                            <form method="POST" action="dashboard.php" onsubmit="return confirm('Revoke API key? API access will be disabled for your account.');">
                                <input type="hidden" name="action" value="revoke_api_key">
                                <button type="submit" class="btn-sm btn-red"><i class="fa-solid fa-ban"></i> Revoke</button>
                            </form>
                        </div>
                        <p style="font-size:11.5px; color:#f87171; margin-top:8px;"><i class="fa-solid fa-triangle-exclamation"></i> Keep it secret. Anyone with this key can reset your keys.</p>
                    <?php else: ?>
                        <p style="color:#8b7ba8; font-size:13px; margin-bottom:10px;">You don't have an API key yet.</p>
                        <form method="POST" action="dashboard.php">
                            <input type="hidden" name="action" value="generate_api_key">
                            <button type="submit" class="btn-generate" style="max-width:260px; height:46px;"><i class="fa-solid fa-key"></i> Generate API Key</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="doc-h" style="margin-top:0;"><i class="fa-solid fa-link"></i> Base URL</div>
                    <div class="code-block"><pre><?= htmlspecialchars($apiBase); ?></pre><button type="button" class="copy-code" onclick="copyText('<?= htmlspecialchars($apiBase, ENT_QUOTES); ?>', 'URL copied!')">Copy</button></div>

                    <div class="doc-h"><i class="fa-solid fa-shield-halved"></i> Authentication</div>
                    <p style="font-size:12.5px; color:#cbd5e1; margin-bottom:8px;">Send your key in the <code style="color:#c084fc;">X-API-Key</code> header (recommended), or as <code style="color:#c084fc;">Authorization: Bearer KEY</code>, or as an <code style="color:#c084fc;">api_key</code> parameter. Both GET and POST work. Responses are always JSON.</p>

                    <div class="doc-h"><i class="fa-solid fa-list"></i> Endpoints</div>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>action</th><th>Parameters</th><th>Description</th></tr></thead>
                            <tbody>
                                <tr><td><span class="method-tag">reset_key</span></td><td><code>license_key</code></td><td>Reset the device HWID of one of your keys (counts toward the daily limit).</td></tr>
                                <tr><td><span class="method-tag">key_info</span></td><td><code>license_key</code></td><td>Status, game, expiry, device-bound and resets left today.</td></tr>
                                <tr><td><span class="method-tag">products</span></td><td>-</td><td>List games and plans with prices.</td></tr>
                                <tr><td><span class="method-tag">balance</span></td><td>-</td><td>Your current balance and daily reset limit.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="doc-h"><i class="fa-solid fa-terminal"></i> Example: cURL</div>
                    <div class="code-block"><pre id="ex-curl">curl -X POST "<?= htmlspecialchars($apiBase); ?>?action=reset_key" \
  -H "X-API-Key: <?= htmlspecialchars($apiKeyShown); ?>" \
  -d "license_key=FF-XXXXXX-XXXXXX"</pre><button type="button" class="copy-code" onclick="copyText(document.getElementById('ex-curl').innerText, 'Copied!')">Copy</button></div>

                    <div class="doc-h"><i class="fa-brands fa-python"></i> Example: Python</div>
                    <div class="code-block"><pre id="ex-py">import requests

r = requests.post(
    "<?= htmlspecialchars($apiBase); ?>",
    params={"action": "reset_key"},
    headers={"X-API-Key": "<?= htmlspecialchars($apiKeyShown); ?>"},
    data={"license_key": "FF-XXXXXX-XXXXXX"},
)
print(r.json())</pre><button type="button" class="copy-code" onclick="copyText(document.getElementById('ex-py').innerText, 'Copied!')">Copy</button></div>

                    <div class="doc-h"><i class="fa-brands fa-js"></i> Example: JavaScript (Node / server side)</div>
                    <div class="code-block"><pre id="ex-js">const res = await fetch("<?= htmlspecialchars($apiBase); ?>?action=reset_key", {
  method: "POST",
  headers: {
    "X-API-Key": "<?= htmlspecialchars($apiKeyShown); ?>",
    "Content-Type": "application/x-www-form-urlencoded"
  },
  body: new URLSearchParams({ license_key: "FF-XXXXXX-XXXXXX" })
});
console.log(await res.json());</pre><button type="button" class="copy-code" onclick="copyText(document.getElementById('ex-js').innerText, 'Copied!')">Copy</button></div>

                    <div class="doc-h"><i class="fa-solid fa-circle-check" style="color:#34d399;"></i> Success Response</div>
                    <div class="code-block"><pre>{
  "status": "success",
  "message": "Device HWID reset successfully!",
  "data": {
    "license_key": "FF-XXXXXX-XXXXXX",
    "game": "FreeFire",
    "resets_used_today": 1,
    "resets_left_today": <?= $resetLimit > 0 ? max(0, $resetLimit - 1) : 'null'; ?>,
    "daily_limit": <?= $resetLimit; ?>
  }
}</pre></div>

                    <div class="doc-h"><i class="fa-solid fa-circle-xmark" style="color:#f87171;"></i> Error Response</div>
                    <div class="code-block"><pre>{
  "status": "error",
  "code": "DAILY_LIMIT_REACHED",
  "message": "Daily reset limit reached! You can reset this key a maximum of <?= max(1, $resetLimit); ?> times per day."
}</pre></div>

                    <div class="doc-h"><i class="fa-solid fa-triangle-exclamation"></i> Error Codes</div>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>HTTP</th><th>code</th><th>Meaning</th></tr></thead>
                            <tbody>
                                <tr><td>400</td><td><code>MISSING_PARAMETER</code> / <code>UNKNOWN_ACTION</code></td><td>Required parameter or action is missing/invalid.</td></tr>
                                <tr><td>401</td><td><code>INVALID_API_KEY</code></td><td>API key missing or wrong.</td></tr>
                                <tr><td>403</td><td><code>ACCOUNT_BANNED</code></td><td>Your account is suspended.</td></tr>
                                <tr><td>404</td><td><code>KEY_NOT_FOUND</code></td><td>Key does not exist in your account.</td></tr>
                                <tr><td>409</td><td><code>NO_DEVICE_BOUND</code></td><td>Key has no device bound, nothing to reset (not counted).</td></tr>
                                <tr><td>429</td><td><code>DAILY_LIMIT_REACHED</code></td><td>Daily reset limit for this key is used up. Try again tomorrow.</td></tr>
                                <tr><td>503</td><td><code>API_DISABLED</code></td><td>API turned off by the administrator.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 4: DEPOSIT BALANCE -->
            <?php if ($deposit_enabled): ?>
            <div id="tab-deposit" class="tab-content">
                <div class="panel" style="max-width:540px; margin:0 auto;">
                    <h3><i class="fa-solid fa-wallet"></i> Deposit / Add Funds</h3>
                    
                    <div class="deposit-methods">
                        <button type="button" class="method-btn active" onclick="selectMethod('bkash', '<?= $settings['bkash_number'] ?? '01700000000'; ?>', 'bKash Personal')">bKash</button>
                        <button type="button" class="method-btn" onclick="selectMethod('nagad', '<?= $settings['nagad_number'] ?? '01800000000'; ?>', 'Nagad Personal')">Nagad</button>
                        <button type="button" class="method-btn" onclick="selectMethod('rocket', '<?= $settings['rocket_number'] ?? '01900000000'; ?>', 'Rocket Personal')">Rocket</button>
                        <button type="button" class="method-btn" onclick="selectMethod('binance', '<?= $settings['binance_address'] ?? 'TXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'; ?>', 'Binance USDT (TRC20)')">Binance</button>
                    </div>

                    <div style="background:#090518; border:1px solid #271656; border-radius:16px; padding:16px; margin-bottom:18px; text-align:center;">
                        <span id="methodName" style="color:#a855f7; font-weight:800; font-size:13px; text-transform:uppercase;">bKash Personal</span>
                        <div style="font-size:16px; font-weight:900; color:#fff; margin:6px 0; font-family:monospace;" id="payAddress">
                            <?= $settings['bkash_number'] ?? '01700000000'; ?>
                        </div>
                        <button type="button" onclick="copyAddress()" style="background:#1f1442; color:#c084fc; border:1px solid #382468; padding:5px 12px; border-radius:8px; font-size:11px; font-weight:800; cursor:pointer;">
                            <i class="fa-solid fa-copy"></i> Copy Address
                        </button>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="submit_deposit">
                        <input type="hidden" name="method" id="inputMethod" value="bkash">
                        
                        <div style="margin-bottom:14px;">
                            <label class="field-label">Deposit Amount (<span id="currLabel">BDT</span>):</label>
                            <input type="number" step="0.01" name="amount" class="gen-input" placeholder="e.g. 500" required>
                        </div>
                        <div style="margin-bottom:14px;">
                            <label class="field-label">Sender Wallet / Phone Number:</label>
                            <input type="text" name="sender" class="gen-input" placeholder="017xxxxxxxx / Binance Pay ID" required>
                        </div>
                        <div style="margin-bottom:18px;">
                            <label class="field-label">Transaction ID (TrxID / Hash):</label>
                            <input type="text" name="trx_id" class="gen-input" placeholder="e.g. 9J283KA71L" required>
                        </div>

                        <button type="submit" class="btn-generate">Submit Deposit Verification</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============================================================= -->
            <!-- TAB 5: SUB-RESELLER (WITH 2 SECTIONS: MANAGE & CREATE) -->
            <!-- ============================================================= -->
            <?php if ($isReseller): ?>
            <div id="tab-subreseller" class="tab-content">
                <div class="panel">
                    <!-- Top Sub-Section Pills -->
                    <div class="sub-nav-pills">
                        <button type="button" class="sub-pill-btn active" onclick="switchSubResellerSection('list', this)">
                            <i class="fa-solid fa-users"></i> Manage Sub-Accounts (<?= count($subResellers); ?>)
                        </button>
                        <button type="button" class="sub-pill-btn" onclick="switchSubResellerSection('create', this)">
                            <i class="fa-solid fa-user-plus"></i> Create Sub-Account
                        </button>
                    </div>

                    <!-- SUB-SECTION 1: MANAGE SUB-ACCOUNTS (LIST, DEPOSIT TOGGLE, BALANCE +/-, BAN, REFUND DELETE) -->
                    <div id="subview-subreseller-list" class="sub-view active">
                        <h3><i class="fa-solid fa-users"></i> Sub-Accounts Control & Monitoring</h3>
                        
                        <!-- Quick Balance +/- Form -->
                        <div style="background:#090518; border:1px solid #271656; border-radius:14px; padding:16px; margin-bottom:18px;">
                            <h4 style="font-size:12px; color:#c084fc; text-transform:uppercase; margin-bottom:10px;"><i class="fa-solid fa-wallet"></i> Adjust Sub-Account Balance (+ / -)</h4>
                            <form method="POST" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; align-items:end;">
                                <input type="hidden" name="action" value="adjust_sub_balance">
                                <div>
                                    <label class="field-label">Select Account</label>
                                    <select name="sub_id" class="gen-select" required>
                                        <?php foreach ($subResellers as $s): ?>
                                            <option value="<?= $s['id']; ?>"><?= htmlspecialchars($s['username']); ?> (Bal: $<?= $s['balance']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="field-label">Amount (+ to Add / - to Deduct)</label>
                                    <input type="number" step="0.01" name="amount" class="gen-input" placeholder="e.g. 50 or -20" required>
                                </div>
                                <div>
                                    <button type="submit" class="btn-generate" style="height:44px; font-size:12px;">Update Balance</button>
                                </div>
                            </form>
                        </div>

                        <!-- Sub-Accounts Table -->
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Balance</th>
                                        <th>Deposit Access</th>
                                        <th>Status</th>
                                        <th>Actions (Ban/Delete)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($subResellers)): ?>
                                        <tr><td colspan="6" style="text-align:center; padding:20px; color:#8b7ba8;">No sub-accounts created yet.</td></tr>
                                    <?php else: foreach ($subResellers as $s): ?>
                                        <tr>
                                            <td><b><?= htmlspecialchars($s['username']); ?></b></td>
                                            <td><span class="badge badge-unused"><?= strtoupper($s['role']); ?></span></td>
                                            <td style="color:#34d399; font-weight:800;">$<?= number_format($s['balance'], 2); ?></td>
                                            <td>
                                                <!-- Deposit ON / OFF Button -->
                                                <?php if (($s['can_deposit'] ?? 1) == 1): ?>
                                                    <a href="dashboard.php?action=toggle_sub_deposit&id=<?= $s['id']; ?>" class="btn-sm btn-green" title="Click to Disable Deposit">
                                                        <i class="fa-solid fa-toggle-on"></i> Enabled
                                                    </a>
                                                <?php else: ?>
                                                    <a href="dashboard.php?action=toggle_sub_deposit&id=<?= $s['id']; ?>" class="btn-sm btn-red" title="Click to Enable Deposit">
                                                        <i class="fa-solid fa-toggle-off"></i> Disabled
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($s['status'] === 'active'): ?>
                                                    <span class="badge badge-active">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-banned">Banned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <!-- Ban / Unban -->
                                                <?php if ($s['status'] === 'active'): ?>
                                                    <a href="dashboard.php?action=sub_ban&id=<?= $s['id']; ?>" class="btn-sm btn-red" onclick="return confirm('Suspend this account?');">Ban</a>
                                                <?php else: ?>
                                                    <a href="dashboard.php?action=sub_unban&id=<?= $s['id']; ?>" class="btn-sm btn-green" onclick="return confirm('Activate this account?');">Unban</a>
                                                <?php endif; ?>

                                                <!-- Delete with Automatic Balance Refund -->
                                                <a href="dashboard.php?action=sub_delete&id=<?= $s['id']; ?>" class="btn-sm btn-purple" onclick="return confirm('Delete this account? Any remaining balance ($<?= $s['balance']; ?>) will be refunded to your account!');" title="Delete & Refund Balance">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- SUB-SECTION 2: CREATE SUB-ACCOUNT -->
                    <div id="subview-subreseller-create" class="sub-view">
                        <h3><i class="fa-solid fa-user-plus"></i> Create Client or Sub-Reseller</h3>
                        <form method="POST" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
                            <input type="hidden" name="action" value="create_sub_reseller">
                            <div>
                                <label class="field-label">Username</label>
                                <input type="text" name="username" class="gen-input" required>
                            </div>
                            <div>
                                <label class="field-label">Email</label>
                                <input type="email" name="email" class="gen-input" required>
                            </div>
                            <div>
                                <label class="field-label">Password</label>
                                <input type="password" name="password" class="gen-input" required>
                            </div>
                            <div>
                                <label class="field-label">Initial Balance ($)</label>
                                <input type="number" step="0.01" name="balance" class="gen-input" value="0.00" required>
                            </div>
                            <div>
                                <label class="field-label">Role</label>
                                <select name="role" class="gen-select">
                                    <option value="sub_reseller">Sub-Reseller</option>
                                    <option value="user">Regular User (Client)</option>
                                </select>
                            </div>
                            <div>
                                <button type="submit" class="btn-generate" style="height:44px; font-size:12px;">Create Account</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- TAB 6: KEY HISTORY -->
            <div id="tab-history" class="tab-content">
                <div class="panel">
                    <h3><i class="fa-solid fa-clock-rotate-left"></i> Key History & Clock Status</h3>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>Game</th><th>Key</th><th>Duration</th><th>Activation Clock Status</th><th>Expiry Date</th></tr></thead>
                            <tbody>
                                <?php if (empty($myLicenses)): ?>
                                    <tr><td colspan="5" style="text-align:center; padding:25px; color:#8b7ba8;">No keys generated yet.</td></tr>
                                <?php else: foreach ($myLicenses as $l): $hg = normalizeGame($l['game_type'] ?? 'FreeFire'); ?>
                                    <tr>
                                        <td><span class="badge <?= $hg === 'Pubg' ? 'badge-pubg' : 'badge-ff'; ?>"><?= $games[$hg]['short']; ?></span></td>
                                        <td><span class="key-text"><?= htmlspecialchars($l['license_key']); ?></span></td>
                                        <td><?= $l['duration_days'] . ' ' . ucfirst($l['duration_type'] ?? 'days'); ?></td>
                                        <td>
                                            <?php if (empty($l['device_id'])): ?>
                                                <span class="badge badge-unused"><i class="fa-solid fa-pause"></i> CLOCK PAUSED (Starts on 1st Login)</span>
                                            <?php elseif ($l['status'] === 'expired'): ?>
                                                <span class="badge badge-expired"><i class="fa-solid fa-stopwatch"></i> EXPIRED</span>
                                            <?php else: ?>
                                                <span class="badge badge-active"><i class="fa-solid fa-play"></i> RUNNING (Active)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color:#8b7ba8; font-size:12px;">
                                            <?= $l['expires_at'] ? date('d M Y, h:i A', strtotime($l['expires_at'])) : '<span style="color:#facc15;">Pending First Login</span>'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 7: TRANSACTIONS -->
            <div id="tab-transactions" class="tab-content">
                <div class="panel">
                    <h3><i class="fa-solid fa-money-bill-transfer"></i> Transaction History</h3>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>Type</th><th>Amount</th><th>Description</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                    <tr><td colspan="4" style="text-align:center; padding:25px; color:#8b7ba8;">No transactions found.</td></tr>
                                <?php else: foreach ($transactions as $t): ?>
                                    <tr>
                                        <td><span class="badge <?= $t['type'] === 'purchase' ? 'badge-expired' : 'badge-active'; ?>"><?= strtoupper($t['type']); ?></span></td>
                                        <td style="font-weight:800; color:#fff;">$<?= number_format($t['amount'], 2); ?></td>
                                        <td><?= htmlspecialchars($t['description']); ?></td>
                                        <td style="color:#8b7ba8; font-size:11.5px;"><?= date('d M Y, h:i A', strtotime($t['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- POPUP MODAL: GENERATED KEYS (WITH "COPY ALL KEYS") -->
    <?php if (!empty($generated_keys)): ?>
    <div class="modal-overlay" id="keysModal">
        <div class="modal-box">
            <i class="fa-solid fa-circle-check" style="font-size:42px; color:#34d399; margin-bottom:12px;"></i>
            <h3 style="color:#fff; font-size:18px; font-weight:800;">Keys Generated Successfully!</h3>
            <p style="font-size:12px; color:#8b7ba8; margin-top:4px;">Generated <?= count($generated_keys); ?> License Keys</p>
            
            <textarea id="allKeysText" class="keys-textarea" readonly><?= implode("\n", $generated_keys); ?></textarea>
            
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn-copy-all" onclick="copyAllKeys()"><i class="fa-solid fa-copy"></i> Copy All Keys</button>
                <button type="button" class="btn-copy-all" style="background:#1f1442; color:#cbd5e1;" onclick="document.getElementById('keysModal').style.display='none'">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const PLANS = <?= json_encode($plansJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        function dismissAlert() {
            const a = document.getElementById('alertBox');
            if (a) {
                a.style.opacity = '0';
                a.style.transform = 'translateY(-10px)';
                setTimeout(() => a.remove(), 400);
            }
        }
        setTimeout(dismissAlert, 5000);

        // Sidebar Drawer with Close System
        function toggleSidebar(forceOpen) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (forceOpen === true) {
                sidebar.classList.add('active');
                overlay.classList.add('active');
            } else if (forceOpen === false) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            } else {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
            }
        }

        // Switch Main Tabs
        function switchTab(tabId, btn, initial) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(el => el.classList.remove('active'));

            const target = document.getElementById('tab-' + tabId);
            if (target) target.classList.add('active');

            if (!btn) {
                document.querySelectorAll('.nav-btn').forEach(b => {
                    if (b.getAttribute('onclick') && b.getAttribute('onclick').includes("'" + tabId + "'")) {
                        b.classList.add('active');
                    }
                });
            } else {
                btn.classList.add('active');
            }

            const titleMap = {
                'dashboard': 'Dashboard',
                'generate': 'Key Generator',
                'reset': 'Reset Key',
                'products': 'Products Service',
                'api': 'API Documents',
                'deposit': 'Deposit Balance',
                'subreseller': 'Sub Reseller Management',
                'history': 'Key History',
                'transactions': 'Transactions'
            };
            document.getElementById('topbarTitle').innerText = titleMap[tabId] || 'Dashboard';

            try {
                if (initial) {
                    history.replaceState(null, '', window.location.pathname + window.location.search + '#' + tabId);
                } else {
                    // ট্যাব বদলালে ?tab=...&search_key=... মুছে ফেলি, যাতে refresh দিলে পুরানো ট্যাবে ফিরে না যায়
                    history.replaceState(null, '', window.location.pathname + '#' + tabId);
                }
                localStorage.setItem('cm_active_tab', tabId);
            } catch (e) {}

            toggleSidebar(false);
            window.scrollTo(0, 0);
        }

        // Sub-Reseller Top-Pills Switcher (2 Sections: List & Create)
        function switchSubResellerSection(sec, btn) {
            document.querySelectorAll('#tab-subreseller .sub-view').forEach(v => v.classList.remove('active'));
            document.querySelectorAll('#tab-subreseller .sub-pill-btn').forEach(b => b.classList.remove('active'));

            btn.classList.add('active');
            const target = document.getElementById('subview-subreseller-' + sec);
            if (target) target.classList.add('active');
        }

        // ---- Game / Plan selector ----
        function populatePlans() {
            const game = document.getElementById('game_select').value;
            const select = document.getElementById('plan_select');
            const list = PLANS.filter(p => p.game === game);
            select.innerHTML = '';
            if (!list.length) {
                const o = document.createElement('option');
                o.value = '';
                o.textContent = 'No plans available for this game';
                select.appendChild(o);
            } else {
                list.forEach(p => {
                    const o = document.createElement('option');
                    o.value = p.id;
                    o.textContent = p.label;
                    o.dataset.price = p.price;
                    select.appendChild(o);
                });
            }
            document.getElementById('btn_submit_gen').disabled = !list.length;
            updatePrice();
        }

        function onGameChange() { populatePlans(); }

        function openGenerator(game) {
            const gs = document.getElementById('game_select');
            if (gs && game) { gs.value = game; populatePlans(); }
            switchTab('generate');
        }

        // Quantity +/-
        function changeQty(delta) {
            const box = document.getElementById('qty_box');
            let val = parseInt(box.value) || 1;
            val += delta;
            if (val < 1) val = 1;
            if (val > 50) val = 50;
            box.value = val;
            updatePrice();
        }

        function updatePrice() {
            const select = document.getElementById('plan_select');
            const btn = document.getElementById('btn_submit_gen');
            if (!select || !btn) return;
            const opt = select.selectedOptions.length ? select.selectedOptions[0] : null;
            const price = opt ? (parseFloat(opt.dataset.price) || 0) : 0;
            const qty = parseInt(document.getElementById('qty_box').value) || 1;
            btn.innerHTML = `<i class="fa-solid fa-bolt"></i> GENERATE KEYS ($${(price * qty).toFixed(2)})`;
        }

        // Clipboard (HTTPS না থাকলেও কাজ করে)
        function copyText(text, okMsg) {
            const done = () => alert(okMsg || 'Copied to clipboard!');
            const fallback = () => {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch (e) { prompt('Copy manually:', text); }
                document.body.removeChild(ta);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(fallback);
            } else {
                fallback();
            }
        }

        function copyAllKeys() {
            copyText(document.getElementById("allKeysText").value, "All keys copied to clipboard successfully!");
        }

        function toggleApiKey() {
            const f = document.getElementById('apiKeyField');
            if (!f) return;
            f.type = (f.type === 'password') ? 'text' : 'password';
        }

        function selectMethod(method, address, label) {
            document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('active'));
            if (window.event && window.event.target) window.event.target.classList.add('active');
            document.getElementById('inputMethod').value = method;
            document.getElementById('methodName').innerText = label;
            document.getElementById('payAddress').innerText = address;
            document.getElementById('currLabel').innerText = (method === 'binance') ? 'USDT' : 'BDT';
        }

        function copyAddress() {
            const address = document.getElementById('payAddress').innerText.trim();
            copyText(address, "Address copied to clipboard: " + address);
        }

        document.addEventListener("DOMContentLoaded", () => {
            populatePlans();
            let defaultTab = "<?= $default_open_tab; ?>";
            let savedTab = defaultTab || window.location.hash.replace('#', '');
            if (!savedTab) { try { savedTab = localStorage.getItem('cm_active_tab') || 'dashboard'; } catch (e) { savedTab = 'dashboard'; } }
            if (!document.getElementById('tab-' + savedTab)) savedTab = 'dashboard';
            switchTab(savedTab, null, true);
        });
    </script>
</body>
</html>
