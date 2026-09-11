<?php
/**
 * proxy.php — AIIR IAQ High-Performance Resilient API Proxy
 * ออกแบบใหม่รองรับทั้งเซิร์ฟเวอร์ VM ภายในองค์กร (ผ่าน Corporate Proxy) 
 * และเซิร์ฟเวอร์เน็ตนอก (Direct Connection) โดยอัตโนมัติ พร้อมระบบ Route Caching,
 * Adaptive Fallback, Anti-DoS Whitelist, และ Server Diagnostic Mode
 */

// ---- CORS & Output Headers ----
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---- Session & Error Suppression ----
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// ---- Database & Timezone Configuration ----
require_once __DIR__ . '/db.php';
date_default_timezone_set('Asia/Bangkok');

/**
 * Server-Authoritative Time Metadata Helper
 * Provides a single source of truth for timestamps across the entire organization.
 */
function getServerTimeMetadata(): array {
    $nowTs = time();
    return [
        'timestampSec' => $nowTs,
        'timestampMs'  => $nowTs * 1000,
        'iso'          => date('c', $nowTs),
        'formatted'    => date('d/m/Y H:i:s', $nowTs),
        'timezone'     => date_default_timezone_get(),
        'syncInterval' => 30, // Default refresh interval in seconds
    ];
}

// ---- Configurations ----
define('AIIR_BASE', 'https://emtrontech.com/AIIR/');
define('CACHE_TTL', 5);             // 5 seconds cache TTL for sensor telemetries
define('RATE_LIMIT_MAX', 300);       // Max 300 req/min (high limit to prevent false positives)
define('RATE_LIMIT_WINDOW', 60);    // Window size in seconds
define('CURL_TIMEOUT', 6);          // 6 seconds max timeout per cURL attempt
define('CURL_CONNECT_TIMEOUT', 3);  // 3 seconds connect timeout

// ---- Corporate Proxy Candidates ----
const CORPORATE_PROXIES = [
    '10.7.21.17:8080',
    'ssproxy.boonrawd.co.th:8080',
];

// ---- Route Caching Helper ----
function getRouteCacheFile(): string {
    return sys_get_temp_dir() . '/aiir_active_route.json';
}

function getCachedRoute(): ?string {
    $file = getRouteCacheFile();
    if (!file_exists($file)) return null;

    $content = @file_get_contents($file);
    if (!$content) return null;

    $data = @json_decode($content, true);
    if (!is_array($data) || empty($data['route']) || empty($data['time'])) return null;

    // Route cache valid for 5 minutes (300 seconds)
    if ((time() - $data['time']) < 300) {
        return $data['route'];
    }

    return null;
}

function saveCachedRoute(string $route): void {
    $file = getRouteCacheFile();
    $data = [
        'route' => $route, // 'DIRECT' or 'host:port'
        'time'  => time(),
    ];
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

// ---- Get Candidate Routes In Order of Probability ----
function getCandidateRoutes(): array {
    $routes = [];

    // 1. Environment variables if defined (case-insensitive check)
    foreach (['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy'] as $env) {
        $val = getenv($env) ?: ($_ENV[$env] ?? ($_SERVER[$env] ?? ''));
        if (!empty($val)) {
            // Strip protocol like http:// if present
            $cleaned = preg_replace('#^https?://#i', '', trim($val));
            if (!in_array($cleaned, $routes)) {
                $routes[] = $cleaned;
            }
        }
    }

    // 2. Detect if host is in an internal corporate subnet (10.x.x.x)
    $serverIp = $_SERVER['SERVER_ADDR'] ?? (gethostbyname(gethostname()) ?: '');
    $isCorporateNet = strpos($serverIp, '10.') === 0;

    if ($isCorporateNet) {
        // Prioritize internal proxies for 10.x.x.x corporate machines
        foreach (CORPORATE_PROXIES as $p) {
            if (!in_array($p, $routes)) $routes[] = $p;
        }
        if (!in_array('DIRECT', $routes)) $routes[] = 'DIRECT';
    } else {
        // Prioritize DIRECT for external / cloud machines
        if (!in_array('DIRECT', $routes)) $routes[] = 'DIRECT';
        foreach (CORPORATE_PROXIES as $p) {
            if (!in_array($p, $routes)) $routes[] = $p;
        }
    }

    return $routes;
}

// ---- Resilient cURL Request with Automatic Failover ----
function requestAIIR(string $endpoint, array $postData = [], array $extraHeaders = []): array {
    $url = (strpos($endpoint, 'http') === 0) ? $endpoint : (AIIR_BASE . ltrim($endpoint, '/'));
    $cachedRoute = getCachedRoute();
    $candidates  = getCandidateRoutes();

    // If we have a working cached route, try it first
    if (!empty($cachedRoute)) {
        array_unshift($candidates, $cachedRoute);
        $candidates = array_values(array_unique($candidates));
    }

    $lastResult = null;

    foreach ($candidates as $route) {
        $result = executeCurl($url, $route, $postData, $extraHeaders);
        $lastResult = $result;

        // Success condition: HTTP 200 and non-empty response
        if ($result['code'] === 200 && !empty($result['body'])) {
            // Check if response is valid JSON or HTML error
            $isJson = @json_decode($result['body'], true) !== null;
            if ($isJson) {
                // Save this working route to avoid probing next time
                saveCachedRoute($route);
                $result['usedRoute'] = $route;
                return $result;
            }
        }
    }

    // If all candidate routes failed, return the last result with diagnostic info
    if ($lastResult === null) {
        $lastResult = [
            'body'      => '',
            'code'      => 0,
            'error'     => 'No network routes available',
            'usedRoute' => 'NONE',
        ];
    }

    return $lastResult;
}

function executeCurl(string $url, string $route, array $postData = [], array $extraHeaders = []): array {
    $ch = curl_init();

    $headers = [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: th,en;q=0.9',
        'Cache-Control: no-cache, no-store, must-revalidate',
        'Pragma: no-cache',
        'Referer: https://emtrontech.com/AIIR/siteData.php?id=4&type=4&sName=ICT401',
        'X-Requested-With: XMLHttpRequest',
    ];

    $curlOpts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    ];

    // Configure Proxy
    if ($route !== 'DIRECT' && !empty($route)) {
        $curlOpts[CURLOPT_PROXY] = $route;
    }

    // Configure POST body
    if (!empty($postData)) {
        $curlOpts[CURLOPT_POST] = true;
        $curlOpts[CURLOPT_POSTFIELDS] = http_build_query($postData);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
        $headers[] = 'Origin: https://emtrontech.com';
    }

    if (!empty($extraHeaders)) {
        $headers = array_merge($headers, $extraHeaders);
    }

    $curlOpts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $curlOpts);

    $t0       = microtime(true);
    $body     = curl_exec($ch);
    $duration = round((microtime(true) - $t0) * 1000, 2);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        'body'       => $body,
        'code'       => $httpCode,
        'error'      => $error,
        'durationMs' => $duration,
        'usedRoute'  => $route,
    ];
}

// ---- Anti-DoS Rate Limiter with Local / Private Subnet Whitelist ----
function checkRateLimit(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Whitelist localhost and private subnets (Docker / LAN / VM loopback)
    $isPrivate = ($ip === '127.0.0.1' || $ip === '::1' ||
                  strpos($ip, '10.') === 0 ||
                  strpos($ip, '192.168.') === 0 ||
                  preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip));

    if ($isPrivate) {
        return; // Skip rate limiter for local / intranet calls
    }

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

// ---- Telemetry Caching Helpers ----
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
    if (empty($response['ok']) || !empty($response['fallback'])) return;
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
    $routeFile = getRouteCacheFile();
    if (file_exists($routeFile)) @unlink($routeFile);
}

// ---- Telemetry History Store ----
function getHistoryFile(): string {
    return sys_get_temp_dir() . '/aiir_history_ict401.json';
}

function saveHistoryRecord(array $data): void {
    if (empty($data['ok'])) return;
    $file = getHistoryFile();
    $maxRecords = 2000;

    // 1. Primary storage: SQLite / MySQL via IAQDatabase
    IAQDatabase::insertTelemetry($data);

    // 2. Legacy fallback file for backward compatibility
    $file = getHistoryFile();
    $maxRecords = 2000;

    $history = [];
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content) {
            $history = json_decode($content, true) ?: [];
        }
    }

    $timeStr = !empty($data['lastUpdate']) ? (string)$data['lastUpdate'] : date('d/m/Y H:i:s');
    $ts = time() * 1000;

    if (!empty($history)) {
        $last = end($history);
        if (isset($last['time']) && $last['time'] === $timeStr) {
            return;
        }
    }

    $entry = [
        'timestamp' => $ts,
        'label'     => date('H:i:s'),
        'time'      => $timeStr,
        'site'      => 'Site 4 - ICT401',
        'pm25'      => (float)($data['pm25'] ?? $data['PM2.5'] ?? 0),
        'pm10'      => (float)($data['pm10'] ?? $data['PM10'] ?? 0),
        'co2'       => (float)($data['co2']  ?? $data['CO2']  ?? 0),
        'temp'      => (float)($data['temp'] ?? 0),
        'humid'     => (float)($data['humid'] ?? 0),
        'evoc'      => (float)($data['evoc'] ?? 0),
        'rssi'      => (string)($data['rssi'] ?? $data['RSSI'] ?? '0'),
    ];

    $history[] = $entry;
    if (count($history) > $maxRecords) {
        $history = array_slice($history, -$maxRecords);
    }

    @file_put_contents($file, json_encode($history), LOCK_EX);
}

function getHistoryRecords(int $limit = 50): array {
    // 1. Try reading from Database
    $dbHistory = IAQDatabase::getRecentHistory($limit);
    if (!empty($dbHistory)) {
        return $dbHistory;
    }

    // 2. Fallback to legacy file
    $file = getHistoryFile();
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    return $content ? (json_decode($content, true) ?: []) : [];
}

// ---- 45-Minute Long-Term Cache Logger ----
function get45MinCacheFile(): string {
    return __DIR__ . '/cache_45m_ict401.json';
}

function save45MinCacheRecord(array $data): void {
    if (empty($data['ok'])) return;

    // 1. Primary storage: SQLite / MySQL via IAQDatabase
    IAQDatabase::insert45MinSnapshotIfNeeded($data);

    // 2. Legacy fallback file for backward compatibility
    $file = get45MinCacheFile();
    $intervalSec = 45 * 60; // 45 minutes = 2,700s
    $maxRecords = 1000;

    $history = [];
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content) {
            $history = json_decode($content, true) ?: [];
        }
    }

    $nowTs = time();

    if (!empty($history)) {
        $lastEntry = end($history);
        $lastTs = isset($lastEntry['timestamp_sec']) ? (int)$lastEntry['timestamp_sec'] : 0;
        if (($nowTs - $lastTs) < $intervalSec) {
            return;
        }
    }

    $pm25  = (float)($data['pm25'] ?? $data['PM2.5'] ?? 0);
    $pm10  = (float)($data['pm10'] ?? $data['PM10'] ?? 0);
    $co2   = (float)($data['co2']  ?? $data['CO2']  ?? 0);
    $temp  = (float)($data['temp'] ?? 0);
    $humid = (float)($data['humid'] ?? 0);
    $evoc  = (float)($data['evoc'] ?? 0);
    $rssi  = (string)($data['rssi'] ?? $data['RSSI'] ?? '0');

    // Calculate AI IAQ Score (0-100%)
    $pm25Pen = min(35, max(0, ($pm25 / 50) * 35));
    $co2Pen  = min(35, max(0, (($co2 - 400) / 1200) * 35));
    $evocPen = min(15, max(0, ($evoc / 50) * 15));
    $tempPen = ($temp < 20 || $temp > 28) ? min(10, max(0, abs($temp - 24) * 2)) : 0;
    $humPen  = ($humid < 40 || $humid > 65) ? min(15, max(0, abs($humid - 50) * 0.3)) : 0;
    $iaqScore = (int)max(10, round(100 - ($pm25Pen + $co2Pen + $evocPen + $tempPen + $humPen)));

    $entry = [
        'timestamp_sec' => $nowTs,
        'timestamp'     => $nowTs * 1000,
        'label'         => date('d/m H:i'),
        'time'          => !empty($data['lastUpdate']) ? (string)$data['lastUpdate'] : date('d/m/Y H:i:s'),
        'site'          => 'Site 4 - ICT401',
        'pm25'          => $pm25,
        'pm10'          => $pm10,
        'co2'           => $co2,
        'temp'          => $temp,
        'humid'         => $humid,
        'evoc'          => $evoc,
        'rssi'          => $rssi,
        'iaqScore'      => $iaqScore,
    ];

    $history[] = $entry;
    if (count($history) > $maxRecords) {
        $history = array_slice($history, -$maxRecords);
    }

    @file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function get45MinCacheRecords(int $limit = 500): array {
    // 1. Try reading from Database
    $dbSnapshots = IAQDatabase::get45MinSnapshots($limit);
    if (!empty($dbSnapshots)) {
        return $dbSnapshots;
    }

    // 2. Fallback to legacy file
    $file = get45MinCacheFile();
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    return $content ? (json_decode($content, true) ?: []) : [];
}

// ---- API Actions ----

/**
 * 1. getSpecData (Site 4 ICT 401 Live Telemetry)
 */
function handleGetSpecData(): void {
    $siteId   = $_GET['site'] ?? $_POST['site'] ?? '4';
    $siteType = $_GET['siteType'] ?? $_POST['siteType'] ?? $siteId;

    $cacheKey = "spec_data_{$siteId}_{$siteType}";
    $cached = getFromCache($cacheKey);
    if ($cached !== null) {
        header('X-Cache: HIT');
        header('X-Cache-Age: ' . ($cached['cacheAge'] ?? 0));
        echo json_encode($cached);
        return;
    }

    header('X-Cache: MISS');

    // Single direct request to getSpecSiteData.php (No login / cookies required!)
    $r = requestAIIR('getSpecSiteData.php', [
        'site'     => $siteId,
        'siteType' => $siteType,
        '_t'       => time(),
    ]);

    $rawText = trim($r['body'] ?? '');
    $j = @json_decode($rawText, true);

    if (is_array($j)) {
        $d = isset($j['d']) && is_array($j['d']) ? $j['d'] : $j;

        $temp       = (float)($d['tempDevice']  ?? $d['RtempDevice']  ?? $d['temp']  ?? 0);
        $humid      = (float)($d['RhumidDevice'] ?? $d['humidDevice'] ?? $d['humid'] ?? 0);
        $evoc       = (float)($d['RevocDevice']  ?? $d['evocDevice']  ?? $d['evoc']  ?? 0);
        $pm25       = (float)($d['pm25Device']   ?? $d['Rpm25Device'] ?? 0);
        $pm10       = (float)($d['pm10Device']   ?? $d['Rpm10Device'] ?? 0);
        $co2        = (float)($d['co2Device']   ?? $d['Rco2Device']   ?? 0);
        $rssi       = (string)($j['rssi'] ?? $d['rssi'] ?? '0');
        $lastUpdate = (string)($d['lastUpdate'] ?? $d['updateSite']  ?? date('d/m/Y H:i:s'));

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
            'serverTime' => getServerTimeMetadata(),
            'route'      => $r['usedRoute'] ?? 'UNKNOWN',
            'raw'        => $d,
            'history'    => getHistoryRecords(),
            'history45m' => get45MinCacheRecords(),
        ];

        saveToCache($cacheKey, $response);
        saveHistoryRecord($response);
        save45MinCacheRecord($response);

        // Update database sync status
        IAQDatabase::updateSyncStatus('site4_telemetry', 'OK', $r['usedRoute'] ?? 'UNKNOWN', $r['durationMs'] ?? 0);

        echo json_encode($response);
        return;
    }

    // Graceful Fallback: If network is temporarily disconnected, return latest snapshot so UI never breaks
    $records45 = get45MinCacheRecords();
    $lastRec   = !empty($records45) ? end($records45) : null;

    $temp       = (float)($lastRec['temp']  ?? 0);
    $humid      = (float)($lastRec['humid'] ?? 0);
    $evoc       = (float)($lastRec['evoc']  ?? 0);
    $pm25       = (float)($lastRec['pm25']  ?? 0);
    $pm10       = (float)($lastRec['pm10']  ?? 0);
    $co2        = (float)($lastRec['co2']   ?? 0);
    $rssi       = (string)($lastRec['rssi'] ?? '0');
    $lastUpdate = !empty($lastRec['time']) ? $lastRec['time'] : date('d/m/Y H:i:s');

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
        'serverTime' => getServerTimeMetadata(),
        'fallback'   => true,
        'curlError'  => $r['error'] ?: 'Invalid JSON response from remote',
        'route'      => $r['usedRoute'] ?? 'FAILED',
        'history'    => getHistoryRecords(),
        'history45m' => $records45,
    ];

    // Log fallback sync status
    IAQDatabase::updateSyncStatus('site4_telemetry', 'FALLBACK', $r['usedRoute'] ?? 'FAILED', $r['durationMs'] ?? 0, $r['error'] ?: 'Fallback snapshot used');

    echo json_encode($response);
}

/**
 * 2. getSiteData (All Sites Overview)
 */
function handleGetSiteData(): void {
    $cacheKey = 'all_sites_overview';
    $cached = getFromCache($cacheKey);
    if ($cached !== null) {
        header('X-Cache: HIT');
        echo json_encode($cached);
        return;
    }

    header('X-Cache: MISS');

    $r = requestAIIR('getSiteData.php', ['dummy' => '1']);
    $rawText = trim($r['body'] ?? '');
    $raw = @json_decode($rawText, true);

    if (!is_array($raw)) {
        echo json_encode([
            'ok'    => false,
            'error' => $r['error'] ?: 'Failed to parse site data JSON',
        ]);
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

    $response = [
        'ok'         => true,
        'data'       => $records,
        'serverTime' => getServerTimeMetadata(),
    ];
    saveToCache($cacheKey, $response);
    echo json_encode($response);
}

/**
 * 3. Login & Session Management
 */
function handleLogin(): void {
    $raw   = file_get_contents('php://input');
    $body  = json_decode($raw, true) ?: [];
    $user  = trim($body['user'] ?? $_POST['user'] ?? '');
    $pass  = $body['pass'] ?? $_POST['pass'] ?? '';

    if ($user === '' || $pass === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing credentials']);
        return;
    }

    // Set local session
    $_SESSION['aiir_logged_in'] = true;
    $_SESSION['aiir_user'] = $user;
    clearAllCache();

    echo json_encode(['ok' => true, 'user' => $user]);
}

function handleCheckSession(): void {
    $loggedIn = !empty($_SESSION['aiir_logged_in']);
    $user     = $_SESSION['aiir_user'] ?? '';
    echo json_encode([
        'ok'       => $loggedIn,
        'loggedIn' => $loggedIn,
        'user'     => $user,
    ]);
}

function handleLogout(): void {
    clearAllCache();
    unset($_SESSION['aiir_user']);
    $_SESSION['aiir_logged_in'] = false;
    @session_destroy();
    echo json_encode(['ok' => true]);
}

/**
 * 4. Push Data from Client Bridge
 */
function handlePushData(): void {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['temp'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid push data']);
        return;
    }

    $response = [
        'ok'         => true,
        'temp'       => (float)($data['temp'] ?? 0),
        'humid'      => (float)($data['humid'] ?? 0),
        'evoc'       => (float)($data['evoc'] ?? 0),
        'pm25'       => (float)($data['pm25'] ?? 0),
        'pm10'       => (float)($data['pm10'] ?? 0),
        'co2'        => (float)($data['co2'] ?? 0),
        'rssi'       => (string)($data['rssi'] ?? '0'),
        'lastUpdate' => (string)($data['lastUpdate'] ?? date('d/m/Y H:i:s')),
        'pushed'     => true,
    ];

    saveHistoryRecord($response);
    save45MinCacheRecord($response);
    echo json_encode(['ok' => true, 'saved' => true]);
}

/**
 * 5. Diagnostic Test Mode (?action=diag)
 * วิเคราะห์และทดสอบการเชื่อมต่อทั้ง Direct และ Proxy ทุกตัวพร้อมรายงานผล
 */
function handleDiag(): void {
    $url = AIIR_BASE . 'getSpecSiteData.php';
    $postData = ['site' => '4', 'siteType' => '4'];

    $results = [];

    // Test DIRECT
    $resDirect = executeCurl($url, 'DIRECT', $postData);
    $results['DIRECT'] = [
        'route'      => 'DIRECT',
        'httpCode'   => $resDirect['code'],
        'durationMs' => $resDirect['durationMs'],
        'success'    => ($resDirect['code'] === 200 && !empty($resDirect['body'])),
        'error'      => $resDirect['error'] ?: null,
        'bodySample' => substr($resDirect['body'], 0, 120),
    ];

    // Test Known Corporate Proxies
    foreach (CORPORATE_PROXIES as $proxy) {
        $resProxy = executeCurl($url, $proxy, $postData);
        $results[$proxy] = [
            'route'      => $proxy,
            'httpCode'   => $resProxy['code'],
            'durationMs' => $resProxy['durationMs'],
            'success'    => ($resProxy['code'] === 200 && !empty($resProxy['body'])),
            'error'      => $resProxy['error'] ?: null,
            'bodySample' => substr($resProxy['body'], 0, 120),
        ];
    }

    $activeRoute = getCachedRoute();

    echo json_encode([
        'ok'            => true,
        'serverIp'      => $_SERVER['SERVER_ADDR'] ?? (gethostbyname(gethostname()) ?: 'N/A'),
        'clientIp'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'cachedRoute'   => $activeRoute ?: 'None (Will auto-select)',
        'candidateOrder'=> getCandidateRoutes(),
        'phpVersion'    => PHP_VERSION,
        'curlVersion'   => curl_version()['version'] ?? 'N/A',
        'tempWritable'  => is_writable(sys_get_temp_dir()),
        'cacheWritable' => is_writable(get45MinCacheFile()) || is_writable(__DIR__),
        'tests'         => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * 6. Clear all historical data and reset caches (?action=clearData)
 */
function handleClearData(): void {
    $dbOk = IAQDatabase::resetAllData();

    // Reset cache_45m_ict401.json to empty array
    $file45 = get45MinCacheFile();
    @file_put_contents($file45, json_encode([], JSON_PRETTY_PRINT));

    // Clear runtime telemetry cache and route cache
    clearAllCache();

    // Clear legacy history file if present
    $histFile = getHistoryFile();
    if (file_exists($histFile)) {
        @unlink($histFile);
    }

    echo json_encode([
        'ok'         => $dbOk,
        'message'    => 'เคลียร์ข้อมูลประวัติทั้งหมดสำเร็จ เริ่มบันทึกข้อมูลใหม่ตั้งแต่วันนี้ (' . date('Y-m-d') . ') อ้างอิงเวลาเซิร์ฟเวอร์',
        'serverTime' => getServerTimeMetadata(),
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Helper to resolve start and end timestamps from date / range / preset parameters
 */
function resolveTimeRangeParams(): array {
    $date   = trim($_GET['date']   ?? $_POST['date']   ?? '');
    $start  = trim($_GET['start']  ?? $_POST['start']  ?? '');
    $end    = trim($_GET['end']    ?? $_POST['end']    ?? '');
    $preset = trim($_GET['preset'] ?? $_POST['preset'] ?? '');

    $now = time();
    $todayStr = date('Y-m-d');
    $mode = 'range';
    $displayLabel = '';
    $selectedDate = '';

    if (!empty($date)) {
        // Single Day mode
        $mode = 'day';
        if ($date === 'today' || $date === $todayStr) {
            $selectedDate = $todayStr;
            $startTs = strtotime($todayStr . ' 00:00:00');
            $endTs   = $now;
            $displayLabel = 'วันนี้ (' . date('d/m/Y') . ')';
        } else if ($date === 'yesterday') {
            $selectedDate = date('Y-m-d', strtotime('-1 day'));
            $startTs = strtotime($selectedDate . ' 00:00:00');
            $endTs   = strtotime($selectedDate . ' 23:59:59');
            $displayLabel = 'เมื่อวาน (' . date('d/m/Y', $startTs) . ')';
        } else {
            $selectedDate = $date;
            $startTs = strtotime($date . ' 00:00:00');
            if ($date === $todayStr) {
                $endTs = $now;
                $displayLabel = 'วันนี้ (' . date('d/m/Y', $startTs) . ')';
            } else {
                $endTs = strtotime($date . ' 23:59:59');
                $displayLabel = 'วันที่ ' . date('d/m/Y', $startTs);
            }
        }
    } else if (!empty($preset)) {
        switch ($preset) {
            case 'today':
                $mode = 'day';
                $selectedDate = $todayStr;
                $startTs = strtotime($todayStr . ' 00:00:00');
                $endTs   = $now;
                $displayLabel = 'วันนี้ (' . date('d/m/Y') . ')';
                break;
            case 'yesterday':
                $mode = 'day';
                $selectedDate = date('Y-m-d', strtotime('-1 day'));
                $startTs = strtotime($selectedDate . ' 00:00:00');
                $endTs   = strtotime($selectedDate . ' 23:59:59');
                $displayLabel = 'เมื่อวาน (' . date('d/m/Y', $startTs) . ')';
                break;
            case '1h':
                $startTs = $now - 3600;
                $endTs   = $now;
                $displayLabel = '1 ชั่วโมงล่าสุด';
                break;
            case '6h':
                $startTs = $now - 21600;
                $endTs   = $now;
                $displayLabel = '6 ชั่วโมงล่าสุด';
                break;
            case '24h':
                $startTs = $now - 86400;
                $endTs   = $now;
                $displayLabel = '24 ชั่วโมงล่าสุด';
                break;
            case '7d':
                $startTs = strtotime('-7 days 00:00:00');
                $endTs   = $now;
                $displayLabel = '7 วันล่าสุด';
                break;
            case '30d':
                $startTs = strtotime('-30 days 00:00:00');
                $endTs   = $now;
                $displayLabel = '30 วันล่าสุด';
                break;
            case 'all':
                $startTs = null;
                $endTs   = $now;
                $displayLabel = 'ทั้งหมดในระบบ';
                break;
            default:
                $startTs = strtotime('-24 hours');
                $endTs   = $now;
                $displayLabel = '24 ชั่วโมงล่าสุด';
                break;
        }
    } else {
        // Custom Range (start and/or end)
        if (!empty($start)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                $startTs = strtotime($start . ' 00:00:00');
            } else if (is_numeric($start)) {
                $startTs = (int)$start;
            } else {
                $startTs = strtotime($start);
            }
        } else {
            $startTs = strtotime('today 00:00:00');
        }

        if (!empty($end)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                if ($end === $todayStr) {
                    $endTs = $now;
                } else {
                    $endTs = strtotime($end . ' 23:59:59');
                }
            } else if (is_numeric($end)) {
                $endTs = (int)$end;
            } else {
                $endTs = strtotime($end);
            }
        } else {
            $endTs = $now;
        }

        if ($start === $end && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $mode = 'day';
            $selectedDate = $start;
            $displayLabel = 'วันที่ ' . date('d/m/Y', $startTs) . ($start === $todayStr ? ' (วันนี้)' : '');
        } else {
            $mode = 'range';
            $displayLabel = date('d/m/Y', $startTs) . ' ถึง ' . date('d/m/Y', $endTs);
        }
    }

    return [
        'mode'         => $mode,
        'date'         => $selectedDate,
        'preset'       => $preset,
        'startTs'      => $startTs,
        'endTs'        => $endTs,
        'displayLabel' => $displayLabel,
    ];
}

/**
 * 7. Get History by Day, Range, or Preset (?action=getHistoryRange)
 */
function handleGetHistoryRange(): void {
    $siteId = $_GET['site'] ?? $_POST['site'] ?? '4';
    $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 2500;

    $params = resolveTimeRangeParams();
    $startTs = $params['startTs'];
    $endTs   = $params['endTs'];

    $records = IAQDatabase::getRecordsByRange($startTs, $endTs, $siteId, $limit);
    $stats   = IAQDatabase::getStatisticsForRange($startTs, $endTs, $siteId);

    echo json_encode([
        'ok'           => true,
        'site'         => $siteId,
        'mode'         => $params['mode'],
        'date'         => $params['date'],
        'displayLabel' => $params['displayLabel'],
        'range'        => [
            'preset'   => $params['preset'] ?: ($params['mode'] === 'day' ? 'day' : 'custom'),
            'startTs'  => $startTs,
            'endTs'    => $endTs,
            'startStr' => $startTs ? date('Y-m-d H:i:s', $startTs) : 'all',
            'endStr'   => date('Y-m-d H:i:s', $endTs),
        ],
        'count'        => count($records),
        'stats'        => $stats,
        'records'      => $records,
        'serverTime'   => getServerTimeMetadata(),
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 8. Export CSV for specific Day or Range (?action=exportCsv)
 */
function handleExportCsv(): void {
    $siteId = $_GET['site'] ?? $_POST['site'] ?? '4';
    $params = resolveTimeRangeParams();
    $startTs = $params['startTs'];
    $endTs   = $params['endTs'];

    $records = IAQDatabase::getRecordsByRange($startTs, $endTs, $siteId, 5000);

    if ($params['mode'] === 'day' && !empty($params['date'])) {
        $filename = sprintf('AIR_ICT401_%s.csv', str_replace('-', '', $params['date']));
    } else {
        $filename = sprintf('AIR_ICT401_%s_%s.csv',
            $startTs ? date('Ymd', $startTs) : 'all',
            date('Ymd', $endTs)
        );
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM for Microsoft Excel in Thai
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID',
        'เวลาเซ็นเซอร์ (Sensor Time)',
        'เวลาเซิร์ฟเวอร์ (Server Time)',
        'สถานที่/ห้อง (Site)',
        'PM2.5 (µg/m³)',
        'PM10 (µg/m³)',
        'CO2 (ppm)',
        'อุณหภูมิ (°C)',
        'ความชื้น (%RH)',
        'EVOC (ppb)',
        'RSSI (dBm)',
        'AI IAQ Score (0-100)',
    ]);

    foreach ($records as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['time'] ?? '',
            isset($r['timestamp_sec']) ? date('Y-m-d H:i:s', $r['timestamp_sec']) : ($r['server_time'] ?? ''),
            $r['site'] ?? 'Site 4 - ICT401',
            $r['pm25'] ?? 0,
            $r['pm10'] ?? 0,
            $r['co2'] ?? 0,
            $r['temp'] ?? 0,
            $r['humid'] ?? 0,
            $r['evoc'] ?? 0,
            $r['rssi'] ?? '0',
            $r['iaqScore'] ?? 0,
        ]);
    }

    fclose($out);
    exit;
}

// ---- Request Router ----
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Support CLI testing (e.g. php proxy.php action=diag or php proxy.php action=getHistoryRange preset=24h)
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    for ($i = 1; $i < count($argv); $i++) {
        if (strpos($argv[$i], '=') !== false) {
            parse_str($argv[$i], $cliArgs);
            $_GET = array_merge($_GET, $cliArgs);
        } else if (empty($action)) {
            $action = $argv[$i];
        }
    }
    if (empty($action) && isset($_GET['action'])) {
        $action = $_GET['action'];
    }
}

switch ($action) {
    case 'login':           handleLogin();        break;
    case 'checkSession':    handleCheckSession(); break;
    case 'logout':          handleLogout();       break;
    case 'getSpecData':     handleGetSpecData();  break;
    case 'getSiteData':     handleGetSiteData();  break;
    case 'getServerTime':
        echo json_encode(['ok' => true, 'serverTime' => getServerTimeMetadata()]);
        break;
    case 'getHistory':
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        echo json_encode([
            'ok'         => true,
            'history'    => getHistoryRecords($limit),
            'serverTime' => getServerTimeMetadata(),
        ]);
        break;
    case 'get45MinHistory':
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 500;
        echo json_encode([
            'ok'         => true,
            'history45m' => get45MinCacheRecords($limit),
            'serverTime' => getServerTimeMetadata(),
        ]);
        break;
    case 'getHistoryRange':
        handleGetHistoryRange();
        break;
    case 'exportCsv':
        handleExportCsv();
        break;
    case 'getStats':
        $range = $_GET['range'] ?? '24h';
        echo json_encode([
            'ok'         => true,
            'stats'      => IAQDatabase::getStatistics($range),
            'serverTime' => getServerTimeMetadata(),
        ]);
        break;
    case 'dbDiag':
        echo json_encode(IAQDatabase::getDiagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
    case 'clearData':       handleClearData();    break;
    case 'pushData':        handlePushData();     break;
    case 'diag':            handleDiag();         break;
    default:
        echo json_encode([
            'ok'    => false,
            'error' => 'Unknown action: ' . htmlspecialchars($action),
            'hint'  => 'Available actions: login, checkSession, logout, getSpecData, getSiteData, getServerTime, getHistory, get45MinHistory, getHistoryRange, exportCsv, getStats, dbDiag, pushData, diag',
        ]);
        break;
}
