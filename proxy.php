<?php
/**
 * proxy.php — AIIR IAQ API Proxy & Anti-DoS Cache System
 * จัดการ Login, Session Cookie, Server-Side Caching (5s TTL), และ Rate Limiting Anti-DoS
 * จาก emtrontech.com/AIIR/ แล้วส่งกลับเป็น JSON
 */

// ---- CORS & Headers ----
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---- Session & Cookie Settings ----
session_start();
$cookieFile = sys_get_temp_dir() . '/aiir_cookie_' . session_id() . '.txt';

// ---- Base Configuration ----
define('AIIR_BASE', 'https://emtrontech.com/AIIR/');
define('CACHE_TTL', 5);           // Cache TTL in seconds (Default: 5s)
define('RATE_LIMIT_MAX', 60);     // Max allowed requests per minute per IP
define('RATE_LIMIT_WINDOW', 60);  // Window size in seconds

// ---- Anti-DoS Rate Limiter ----
function checkRateLimit(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rateFile = sys_get_temp_dir() . '/aiir_rate_' . md5($ip) . '.json';
    $now = time();
    $data = ['start' => $now, 'count' => 0];

    if (file_exists($rateFile)) {
        $content = @file_get_contents($rateFile);
        $parsed = @json_decode($content, true);
        if (is_array($parsed) && isset($parsed['start'], $parsed['count'])) {
            if (($now - $parsed['start']) < RATE_LIMIT_WINDOW) {
                $data = $parsed;
            }
        }
    }

    $data['count']++;
    @file_put_contents($rateFile, json_encode($data), LOCK_EX);

    if ($data['count'] > RATE_LIMIT_MAX) {
        http_response_code(429);
        header('Retry-After: ' . (RATE_LIMIT_WINDOW - ($now - $data['start'])));
        echo json_encode([
            'ok'    => false,
            'error' => 'Rate limit exceeded. Anti-DoS protection active. Please wait a few seconds.',
        ]);
        exit;
    }
}

// ---- Cache Helpers ----
function getCacheFilePath(string $key): string {
    return sys_get_temp_dir() . '/aiir_cache_' . md5($key) . '.json';
}

function getFromCache(string $key): ?array {
    $file = getCacheFilePath($key);
    if (!file_exists($file)) return null;

    $content = @file_get_contents($file);
    if (!$content) return null;

    $data = @json_decode($content, true);
    if (!is_array($data) || !isset($data['timestamp'], $data['response'])) return null;

    $age = time() - $data['timestamp'];
    if ($age <= CACHE_TTL) {
        $res = $data['response'];
        $res['cached']   = true;
        $res['cacheAge'] = $age;
        return $res;
    }

    return null;
}

function saveToCache(string $key, array $response): void {
    if (empty($response['ok'])) return;
    $file = getCacheFilePath($key);
    $data = [
        'timestamp' => time(),
        'response'  => $response,
    ];
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function clearAllCache(): void {
    $pattern = sys_get_temp_dir() . '/aiir_cache_*.json';
    $files = glob($pattern);
    if (is_array($files)) {
        foreach ($files as $f) {
            if (file_exists($f)) @unlink($f);
        }
    }
}

// ---- cURL Helper Function ----
function makeCurl(string $url, array $postData = [], array $extraHeaders = [], bool $followRedirect = true): array {
    global $cookieFile;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => $followRedirect,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0 Safari/537.36',
    ]);

    $defaultHeaders = [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: th,en;q=0.9',
        'Referer: ' . AIIR_BASE . 'index.php',
        'X-Requested-With: XMLHttpRequest',
    ];

    $headers = array_merge($defaultHeaders, $extraHeaders);

    if (!empty($postData)) {
        $body = http_build_query($postData);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Origin: https://emtrontech.com';
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        'body'     => $body,
        'code'     => $httpCode,
        'finalUrl' => $finalUrl,
        'error'    => $error,
    ];
}

// ---- API Handlers ----
function doLogin(): void {
    $raw   = file_get_contents('php://input');
    $body  = json_decode($raw, true) ?: [];
    $user  = trim($body['user'] ?? $_POST['user'] ?? '');
    $pass  = $body['pass'] ?? $_POST['pass'] ?? '';

    if ($user === '' || $pass === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing credentials']);
        return;
    }

    $uHash = hash('sha256', $user);
    $pHash = hash('sha256', $pass);

    $postData = ['u' => $uHash, 'p' => $pHash, 'd' => '0'];
    $r = makeCurl(AIIR_BASE . 'userAuthen.php', $postData, [
        'Referer: ' . AIIR_BASE . 'login.php',
    ]);

    if (!empty($r['error'])) {
        echo json_encode(['ok' => false, 'error' => 'cURL error: ' . $r['error']]);
        return;
    }

    $check = makeCurl(AIIR_BASE . 'index.php', [], [], true);
    $loggedIn = stripos($check['finalUrl'], 'login.php') === false;

    if ($loggedIn) {
        $_SESSION['aiir_logged_in'] = true;
        clearAllCache();
    }

    echo json_encode(['ok' => $loggedIn]);
}

function getSiteData(): void {
    $cacheKey = 'getSiteData_all';

    $cached = getFromCache($cacheKey);
    if ($cached !== null) {
        header('X-Cache: HIT');
        echo json_encode($cached);
        return;
    }

    header('X-Cache: MISS');

    $r = makeCurl(AIIR_BASE . 'getSiteData.php', ['dummy' => '1'], [
        'Referer: ' . AIIR_BASE . 'index.php',
    ]);

    if (!empty($r['error'])) {
        echo json_encode(['ok' => false, 'error' => $r['error']]);
        return;
    }

    $text = trim($r['body'] ?? '');

    if (stripos(substr($text, 0, 100), '<html') !== false) {
        $_SESSION['aiir_logged_in'] = false;
        echo json_encode(['ok' => false, 'error' => 'session_expired']);
        return;
    }

    $raw = @json_decode($text, true);
    if (!is_array($raw)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON from API', 'raw' => substr($text, 0, 300)]);
        return;
    }

    $records = [];
    foreach ($raw as $item) {
        $d = $item['data'] ?? [];
        $records[] = [
            'Site'   => (string)($item['s']    ?? '?'),
            'Status' => (string)($item['stat'] ?? 'N/A'),
            'RSSI'   => (string)($item['rssi'] ?? '0'),
            'PM2.5'  => (float)($d['pm25Device'] ?? $d['Rpm25Device'] ?? 0),
            'PM10'   => (float)($d['pm10Device'] ?? $d['Rpm10Device'] ?? 0),
            'CO2'    => (float)($d['co2Device']  ?? $d['Rco2Device']  ?? 0),
            'Update' => (string)($d['updateSite'] ?? $d['lastUpdate'] ?? ''),
        ];
    }

    $response = ['ok' => true, 'data' => $records];
    saveToCache($cacheKey, $response);
    echo json_encode($response);
}

function getSpecData(): void {
    $siteId   = $_GET['site'] ?? $_POST['site'] ?? '4';
    $siteType = $_GET['siteType'] ?? $_POST['siteType'] ?? $siteId;

    $cacheKey = "getSpecData_{$siteId}_{$siteType}";

    $cached = getFromCache($cacheKey);
    if ($cached !== null) {
        header('X-Cache: HIT');
        echo json_encode($cached);
        return;
    }

    header('X-Cache: MISS');

    $pageUrl = AIIR_BASE . 'siteData.php?id=' . $siteId . '&type=' . $siteType . '&sName=ICT401';
    global $cookieFile;
    $ch1 = curl_init();
    curl_setopt_array($ch1, [
        CURLOPT_URL            => $pageUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: th,en;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
            'Upgrade-Insecure-Requests: 1',
            'Referer: ' . AIIR_BASE . 'index.php',
        ],
    ]);
    $r1body = curl_exec($ch1);
    curl_close($ch1);

    usleep(300000); // 300ms

    $body   = 'site=' . urlencode($siteId) . '&siteType=' . urlencode($siteType);
    $apiUrl = AIIR_BASE . 'getSpecSiteData.php';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . $pageUrl,
            'Accept: */*',
            'Accept-Language: th,en;q=0.9',
            'Origin: https://emtrontech.com',
        ],
    ]);
    $text2    = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $errMsg   = curl_error($ch);
    curl_close($ch);

    if (!empty($errMsg)) {
        echo json_encode(['ok' => false, 'error' => 'cURL error: ' . $errMsg]);
        return;
    }

    $text2 = trim($text2 ?? '');

    if (stripos($finalUrl, 'login.php') !== false) {
        echo json_encode(['ok' => false, 'error' => 'session_expired']);
        return;
    }

    if ($text2 === '') {
        echo json_encode([
            'ok'       => false,
            'error'    => 'Empty response from AIIR API',
            'httpCode' => $httpCode,
            'finalUrl' => $finalUrl,
        ]);
        return;
    }

    if (stripos(substr($text2, 0, 60), '<html') !== false || stripos(substr($text2, 0, 60), '<!DOCTYPE') !== false) {
        echo json_encode(['ok' => false, 'error' => 'session_expired', 'code' => $httpCode]);
        return;
    }

    $j = @json_decode($text2, true);
    if (!is_array($j)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON', 'raw' => substr($text2, 0, 300)]);
        return;
    }

    $d = isset($j['d']) && is_array($j['d']) ? $j['d'] : $j;

    $temp       = (float)($d['tempDevice']   ?? $d['RtempDevice']  ?? $d['temp']  ?? 0);
    $humid      = (float)($d['RhumidDevice']  ?? $d['humidDevice'] ?? $d['humid'] ?? 0);
    $evoc       = (float)($d['RevocDevice']   ?? $d['evocDevice']  ?? $d['evoc']  ?? 0);
    $pm25       = (float)($d['pm25Device']   ?? $d['Rpm25Device']  ?? 0);
    $pm10       = (float)($d['pm10Device']   ?? $d['Rpm10Device']  ?? 0);
    $co2        = (float)($d['co2Device']    ?? $d['Rco2Device']   ?? 0);
    $rssi       = (string)($j['rssi'] ?? '');
    $lastUpdate = (string)($d['lastUpdate']  ?? $d['updateSite']  ?? '');

    $response = [
        'ok'         => true,
        'temp'       => $temp,
        'humid'      => $humid,
        'evoc'       => $evoc,
        'pm25'       => $pm25,
        'pm10'       => $pm10,
        'co2'        => $co2,
        'rssi'       => $rssi,
        'lastUpdate' => $lastUpdate,
        'raw'        => $d,
    ];

    saveToCache($cacheKey, $response);
    echo json_encode($response);
}

function doLogout(): void {
    global $cookieFile;
    if (file_exists($cookieFile)) @unlink($cookieFile);
    clearAllCache();
    $_SESSION['aiir_logged_in'] = false;
    session_destroy();
    echo json_encode(['ok' => true]);
}

// ---- Anti-DoS Check & Action Router ----
checkRateLimit();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':       doLogin();       break;
    case 'getSiteData': getSiteData();   break;
    case 'getSpecData': getSpecData();   break;
    case 'logout':      doLogout();      break;
    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}
