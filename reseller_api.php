<?php
/**
 * CrackMaster Reseller API
 * --------------------------------------------------------------
 * Actions : reset_key | key_info | products | balance
 * Auth    : X-API-Key header  (অথবা Authorization: Bearer KEY, অথবা api_key প্যারামিটার)
 * Limit   : প্রতি key তে দৈনিক reset limit অ্যাডমিন প্যানেল (API & Reset Limit) থেকে সেট হয়
 *
 * নোট: নাম reseller_api.php রাখা হয়েছে যাতে আপনার আগের api.php (চিট অ্যাপের লাইসেন্স API)
 *      কোনোভাবে overwrite না হয়।
 */
define('CM_NO_SESSION', true);
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function apiOut($httpCode, array $payload) {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}
function apiErr($httpCode, $code, $message) {
    apiOut($httpCode, ['status' => 'error', 'code' => $code, 'message' => $message]);
}

// ---------- Input (form / query / JSON body) ----------
$input = $_REQUEST;
$raw = file_get_contents('php://input');
if ($raw !== false && $raw !== '' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $json = json_decode($raw, true);
    if (is_array($json)) $input = array_merge($input, $json);
}

// ---------- API switch (অ্যাডমিন বন্ধ করে দিলে) ----------
if (getSetting($pdo, 'api_enabled', '1') !== '1') {
    apiErr(503, 'API_DISABLED', 'API access is currently disabled by the administrator.');
}

// ---------- Authentication ----------
$apiKey = '';
if (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = trim($_SERVER['HTTP_X_API_KEY']);
} elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $apiKey = trim($m[1]);
} elseif (!empty($input['api_key'])) {
    $apiKey = trim((string)$input['api_key']);
}

if ($apiKey === '' || strlen($apiKey) > 64) {
    apiErr(401, 'INVALID_API_KEY', 'Missing or invalid API key.');
}

try {
    $st = $pdo->prepare("SELECT * FROM users WHERE api_key = ? LIMIT 1");
    $st->execute([$apiKey]);
    $user = $st->fetch();
} catch (Throwable $e) {
    // api_key কলাম এখনো তৈরি হয়নি (কেউ ড্যাশবোর্ডে ঢোকেনি)
    apiErr(401, 'INVALID_API_KEY', 'Missing or invalid API key.');
}

if (!$user) {
    apiErr(401, 'INVALID_API_KEY', 'Missing or invalid API key.');
}
if (($user['status'] ?? 'active') === 'banned') {
    apiErr(403, 'ACCOUNT_BANNED', 'Your account has been suspended.');
}

$userId     = (int)$user['id'];
$resetLimit = getResetLimit($pdo);
$games      = gameCatalog();
$action     = strtolower(trim((string)($input['action'] ?? '')));

// license_key দিয়ে নিজের key খুঁজে বের করা (শুধু মালিক, ট্র্যাশ করা বাদ)
function apiFindLicense(PDO $pdo, $userId, $input) {
    $key = trim((string)($input['license_key'] ?? ''));
    if ($key === '' || strlen($key) > 100) {
        apiErr(400, 'MISSING_PARAMETER', 'Parameter "license_key" is required.');
    }
    $st = $pdo->prepare("SELECT * FROM licenses WHERE license_key = ? AND user_id = ? AND is_deleted = 0 LIMIT 1");
    $st->execute([$key, (int)$userId]);
    $l = $st->fetch();
    if (!$l) apiErr(404, 'KEY_NOT_FOUND', 'Key not found in your account.');
    return $l;
}

switch ($action) {

    case 'reset_key':
        $lic = apiFindLicense($pdo, $userId, $input);
        $res = resetLicenseHwid($pdo, $userId, (int)$lic['id'], $resetLimit);

        if ($res['ok']) {
            apiOut(200, [
                'status'  => 'success',
                'message' => $res['message'],
                'data'    => [
                    'license_key'       => $lic['license_key'],
                    'game'              => normalizeGame($lic['game_type'] ?? 'FreeFire'),
                    'resets_used_today' => $res['used'],
                    'resets_left_today' => $res['remaining'],   // null = unlimited
                    'daily_limit'       => $res['limit'],       // 0 = unlimited
                ],
            ]);
        }

        $http = ['DAILY_LIMIT_REACHED' => 429, 'NO_DEVICE_BOUND' => 409, 'KEY_NOT_FOUND' => 404][$res['code']] ?? 400;
        apiErr($http, $res['code'], $res['message']);
        break;

    case 'key_info':
        $lic  = apiFindLicense($pdo, $userId, $input);
        $used = resetsUsedToday($lic);
        if (!empty($lic['is_banned']))      $state = 'banned';
        elseif (!empty($lic['is_paused']))  $state = 'paused';
        else                                $state = $lic['status'];

        apiOut(200, [
            'status' => 'success',
            'data'   => [
                'license_key'       => $lic['license_key'],
                'game'              => normalizeGame($lic['game_type'] ?? 'FreeFire'),
                'duration'          => (int)$lic['duration_days'] . ' ' . ($lic['duration_type'] ?? 'days'),
                'state'             => $state,
                'device_bound'      => !empty($lic['device_id']),
                'expires_at'        => $lic['expires_at'] ?? null,
                'resets_used_today' => $used,
                'resets_left_today' => ($resetLimit > 0) ? max(0, $resetLimit - $used) : null,
                'daily_limit'       => $resetLimit,
            ],
        ]);
        break;

    case 'products':
        $out = [];
        foreach ($games as $gk => $g) $out[$gk] = ['game' => $gk, 'name' => $g['label'] . ' VIP Engine', 'plans' => []];
        foreach ($pdo->query("SELECT * FROM plans ORDER BY id ASC")->fetchAll() as $p) {
            $gk = normalizeGame($p['game_type'] ?? 'FreeFire');
            $out[$gk]['plans'][] = [
                'id'       => (int)$p['id'],
                'name'     => $p['name'],
                'duration' => (int)$p['duration_days'] . ' ' . ($p['duration_type'] ?? 'days'),
                'price'    => (float)$p['price'],
                'currency' => $p['currency'],
            ];
        }
        apiOut(200, ['status' => 'success', 'data' => array_values($out)]);
        break;

    case 'balance':
        apiOut(200, [
            'status' => 'success',
            'data'   => [
                'username'          => $user['username'],
                'balance'           => (float)$user['balance'],
                'daily_reset_limit' => $resetLimit,
            ],
        ]);
        break;

    default:
        apiErr(400, 'UNKNOWN_ACTION', 'Unknown or missing "action". Valid actions: reset_key, key_info, products, balance.');
}
