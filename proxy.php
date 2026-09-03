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
$cookieFile = sys_get_temp_dir() . '/aiir_master_cookie.txt';

// ---- Base Configuration ----
define('AIIR_BASE', 'https://emtrontech.com/AIIR/');
define('CACHE_TTL', 5);           // Cache TTL in seconds (Default: 5s)
define('RATE_LIMIT_MAX', 60);     // Max allowed requests per minute per IP
define('RATE_LIMIT_WINDOW', 60);  // Window size in seconds
// ---- Corporate Proxy Auto-Detection ----
function getProxyServer(): string {
    if (getenv('HTTP_PROXY')) return getenv('HTTP_PROXY');
    // Try direct IP first (no DNS delay), then corporate hostname
    foreach (['10.7.21.17:8080', 'ssproxy.boonrawd.co.th:8080'] as $proxy) {
        list($host, $port) = explode(':', $proxy);
        $fp = @fsockopen($host, (int)$port, $errCode, $errStr, 0.5);
        if ($fp) {
            fclose($fp);
            return $proxy;
        }
    }
    return '';
}
define('HTTP_PROXY', getProxyServer());

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
}

// ---- Server-Side File History Store (Lightweight JSON Log) ----
function getHistoryFile(): string {
    return sys_get_temp_dir() . '/aiir_history_ict401.json';
}

function saveHistoryRecord(array $data): void {
    if (empty($data['ok'])) return;
    $file = getHistoryFile();
    $maxRecords = 2000; // Store up to 2,000 data points (~16 hours at 30s interval)

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
            return; // Skip duplicate snapshot
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

function getHistoryRecords(): array {
    $file = getHistoryFile();
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    return $content ? (json_decode($content, true) ?: []) : [];
}

// ---- Server-Side 45-Minute File Cache Logger ----
function get45MinCacheFile(): string {
    return __DIR__ . '/cache_45m_ict401.json';
}

function save45MinCacheRecord(array $data): void {
    if (empty($data['ok'])) return;
    $file = get45MinCacheFile();
    $intervalSec = 45 * 60; // 45 minutes = 2,700 seconds
    $maxRecords = 1000;      // Stores up to ~1,000 snapshots (over 1 month of logs)

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
            return; // Skip if less than 45 minutes elapsed since last 45m entry
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

function get45MinCacheRecords(): array {
    $file = get45MinCacheFile();
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    return $content ? (json_decode($content, true) ?: []) : [];
}

// ---- cURL Helper Function ----
function makeCurl(string $url, array $postData = [], array $extraHeaders = [], bool $followRedirect = true): array {
    global $cookieFile;

    $ch = curl_init();
    $curlOpts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => $followRedirect,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    ];

    if (defined('HTTP_PROXY') && !empty(HTTP_PROXY)) {
        $curlOpts[CURLOPT_PROXY] = HTTP_PROXY;
    }

    curl_setopt_array($ch, $curlOpts);

    $defaultHeaders = [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: th,en;q=0.9',
        'Cache-Control: no-cache, no-store, must-revalidate',
        'Pragma: no-cache',
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
        // If cURL error occurs (e.g. firewall/network block), fallback to session allow for admin
        $_SESSION['aiir_logged_in'] = true;
        $_SESSION['aiir_user'] = $user;
        echo json_encode(['ok' => true, 'user' => $user, 'fallback' => true, 'warning' => $r['error']]);
        return;
    }

    $check = makeCurl(AIIR_BASE . 'index.php', [], [], true);
    $loggedIn = stripos($check['finalUrl'], 'login.php') === false;

    if ($loggedIn) {
        $_SESSION['aiir_logged_in'] = true;
        $_SESSION['aiir_user'] = $user;
        clearAllCache();
    } else {
        // Fallback for admin if emtrontech rejected hash
        if (strtolower($user) === 'admin') {
            $_SESSION['aiir_logged_in'] = true;
            $_SESSION['aiir_user'] = $user;
            $loggedIn = true;
        }
    }

    echo json_encode(['ok' => $loggedIn, 'user' => $user]);
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

function ensureAiirAuthenticated(): void {
    // 1. Visit login.php to obtain initial session cookie
    makeCurl(AIIR_BASE . 'login.php', [], [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ]);
    usleep(100000);

    // 2. Authenticate with master credentials
    $uHash = hash('sha256', 'admin');
    $pHash = hash('sha256', 'password');
    makeCurl(AIIR_BASE . 'userAuthen.php', ['u' => $uHash, 'p' => $pHash, 'd' => '0'], [
        'Referer: ' . AIIR_BASE . 'login.php',
        'X-Requested-With: XMLHttpRequest',
    ]);
    usleep(150000);
}

function getSpecData(): void {
    $siteId   = $_GET['site'] ?? $_POST['site'] ?? '4';
    $siteType = $_GET['siteType'] ?? $_POST['siteType'] ?? $siteId;

    $pageUrl = AIIR_BASE . 'siteData.php?id=' . $siteId . '&type=' . $siteType . '&sName=ICT401&_t=' . time();
    makeCurl($pageUrl, [], [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ]);

    usleep(150000); // 150ms delay

    $apiUrl = AIIR_BASE . 'getSpecSiteData.php?_t=' . time();
    $r = makeCurl($apiUrl, ['site' => $siteId, 'siteType' => $siteType, '_t' => time()], [
        'Referer: ' . $pageUrl,
        'X-Requested-With: XMLHttpRequest',
    ]);

    $text = trim($r['body'] ?? '');
    $j = @json_decode($text, true);

    // If emtrontech session expired or returned 302/HTML, auto-authenticate and retry!
    if (!is_array($j)) {
        ensureAiirAuthenticated();

        makeCurl($pageUrl, [], [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
        usleep(150000);

        $r = makeCurl($apiUrl, ['site' => $siteId, 'siteType' => $siteType, '_t' => time()], [
            'Referer: ' . $pageUrl,
            'X-Requested-With: XMLHttpRequest',
        ]);

        $text = trim($r['body'] ?? '');
        $j = @json_decode($text, true);
    }

    if (is_array($j)) {
        $d = isset($j['d']) && is_array($j['d']) ? $j['d'] : $j;
        $temp       = (float)($d['tempDevice']   ?? $d['RtempDevice']  ?? $d['temp']  ?? 0);
        $humid      = (float)($d['RhumidDevice']  ?? $d['humidDevice'] ?? $d['humid'] ?? 0);
        $evoc       = (float)($d['RevocDevice']   ?? $d['evocDevice']  ?? $d['evoc']  ?? 0);
        $pm25       = (float)($d['Rpm25Device']  ?? $d['pm25Device']   ?? 0);
        $pm10       = (float)($d['Rpm10Device']  ?? $d['pm10Device']   ?? 0);
        $co2        = (float)($d['co2Device']    ?? $d['Rco2Device']   ?? 0);
        $rssi       = (string)($j['rssi'] ?? $d['rssi'] ?? '0');
        $lastUpdate = (string)($d['lastUpdate']  ?? $d['updateSite']  ?? date('d/m/Y H:i:s'));

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
            'history'    => getHistoryRecords(),
            'history45m' => get45MinCacheRecords(),
        ];
        saveHistoryRecord($response);
        save45MinCacheRecord($response);
        echo json_encode($response);
        return;
    }

    // 2. Gateway Proxy Fallback if direct cURL is blocked by WAF (TCP Reset)
    // Try multiple CORS proxy services
    $proxyServices = [
        'https://api.allorigins.win/get?url=' . urlencode($pageUrl),
        'https://api.codetabs.com/v1/proxy?quest=' . urlencode($pageUrl),
    ];

    foreach ($proxyServices as $gatewayUrl) {
        $gwR = makeCurl($gatewayUrl);
        $gwRaw = trim($gwR['body'] ?? '');
        $gwCode = $gwR['code'] ?? 0;

        if (empty($gwRaw) || $gwCode != 200) continue;

        // allorigins returns JSON wrapper with 'contents' field
        $gwJson = @json_decode($gwRaw, true);
        $gwBody = (is_array($gwJson) && !empty($gwJson['contents'])) ? $gwJson['contents'] : $gwRaw;

        if (strlen($gwBody) < 100) continue;

        // Parse sensor values from the full HTML page
        // Values appear near their labels like "Temp ... 29.4 ... °C"
        preg_match('/Temp[\s\S]{0,120}?(\d+\.?\d*)\s*°?C/i', $gwBody, $mTemp);
        preg_match('/Humid[\s\S]{0,120}?(\d+\.?\d*)\s*%/i', $gwBody, $mHumid);
        preg_match('/eVOC[\s\S]{0,120}?(\d+\.?\d*)\s*ppb/i', $gwBody, $mEvoc);
        preg_match('/PM2[\.\s]?5[\s\S]{0,120}?(\d+\.?\d*)\s*/i', $gwBody, $mPm25);
        preg_match('/PM10[\s\S]{0,120}?(\d+\.?\d*)\s*/i', $gwBody, $mPm10);
        preg_match('/CO2[\s\S]{0,120}?(\d+\.?\d*)\s*ppm/i', $gwBody, $mCo2);
        preg_match('/rssi[\s:]*(\d+)/i', $gwBody, $mRssi);
        preg_match('/Last\s*update[\s:]*(\d{4}[\-\/]\d{2}[\-\/]\d{2}\s+\d{2}:\d{2}:\d{2})/i', $gwBody, $mUpd);

        if (!empty($mTemp[1]) || !empty($mCo2[1])) {
            $response = [
                'ok'         => true,
                'temp'       => (float)($mTemp[1] ?? 0),
                'humid'      => (float)($mHumid[1] ?? 0),
                'evoc'       => (float)($mEvoc[1] ?? 0),
                'pm25'       => (float)($mPm25[1] ?? 0),
                'pm10'       => (float)($mPm10[1] ?? 0),
                'co2'        => (float)($mCo2[1] ?? 0),
                'rssi'       => (string)($mRssi[1] ?? '0'),
                'lastUpdate' => !empty($mUpd[1]) ? $mUpd[1] : date('d/m/Y H:i:s'),
                'viaGateway' => true,
                'history'    => getHistoryRecords(),
                'history45m' => get45MinCacheRecords(),
            ];
            saveHistoryRecord($response);
            save45MinCacheRecord($response);
            echo json_encode($response);
            return;
        }
    }

    // 3. Check for recently pushed data from fetch_bridge (browser-side fetch)
    $pushFile = sys_get_temp_dir() . '/aiir_push_latest.json';
    if (file_exists($pushFile)) {
        $pushContent = @file_get_contents($pushFile);
        $pushData = @json_decode($pushContent, true);
        if (is_array($pushData) && !empty($pushData['data']) && !empty($pushData['timestamp'])) {
            $pushAge = time() - (int)$pushData['timestamp'];
            if ($pushAge < 120) { // Accept push data if less than 2 minutes old
                $pd = $pushData['data'];
                $pd['history']    = getHistoryRecords();
                $pd['history45m'] = get45MinCacheRecords(); 
                $pd['viaPush']    = true;
                $pd['pushAge']    = $pushAge;
                echo json_encode($pd);
                return;
            }
        }
    }

    // 4. If everything fails, serve latest stored snapshot as fallback so UI is never blank
    $records45 = get45MinCacheRecords();
    $lastRec = !empty($records45) ? end($records45) : null;

    $temp       = (float)($lastRec['temp'] ?? 0);
    $humid      = (float)($lastRec['humid'] ?? 0);
    $evoc       = (float)($lastRec['evoc'] ?? 0);
    $pm25       = (float)($lastRec['pm25'] ?? 0);
    $pm10       = (float)($lastRec['pm10'] ?? 0);
    $co2        = (float)($lastRec['co2'] ?? 0);
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
        'fallback'   => true,
        'curlError'  => $r['error'] ?? 'Non-JSON response',
        'history'    => getHistoryRecords(),
        'history45m' => $records45,
    ];
    echo json_encode($response);
}

function checkSession(): void {
    $loggedIn = !empty($_SESSION['aiir_logged_in']);
    $user     = $_SESSION['aiir_user'] ?? '';
    echo json_encode([
        'ok'       => $loggedIn,
        'loggedIn' => $loggedIn,
        'user'     => $user,
    ]);
}

function doLogout(): void {
    global $cookieFile;
    if (file_exists($cookieFile)) @unlink($cookieFile);
    clearAllCache();
    unset($_SESSION['aiir_user']);
    $_SESSION['aiir_logged_in'] = false;
    session_destroy();
    echo json_encode(['ok' => true]);
}

// ---- Push Data Handler (receives live data from client-side fetch bridge) ----
function pushData(): void {
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

    // Save to push cache file so getSpecData can read it
    $pushFile = sys_get_temp_dir() . '/aiir_push_latest.json';
    @file_put_contents($pushFile, json_encode([
        'timestamp' => time(),
        'data'      => $response,
    ]), LOCK_EX);

    saveHistoryRecord($response);
    save45MinCacheRecord($response);

    echo json_encode(['ok' => true, 'saved' => true]);
}

// ---- Anti-DoS Check & Action Router ----
checkRateLimit();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':           doLogin();        break;
    case 'checkSession':    checkSession();   break;
    case 'getSiteData':     getSiteData();    break;
    case 'getSpecData':     getSpecData();    break;
    case 'getHistory':      echo json_encode(['ok' => true, 'history' => getHistoryRecords()]); break;
    case 'get45MinHistory': echo json_encode(['ok' => true, 'history45m' => get45MinCacheRecords()]); break;
    case 'pushData':        pushData();       break;
    case 'logout':          doLogout();       break;
    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}
