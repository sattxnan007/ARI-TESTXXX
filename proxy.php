<?php
/**
 * proxy.php — AIIR IAQ API Proxy
 * จัดการ Login, Session Cookie, และดึงข้อมูล Sensor
 * จาก emtrontech.com/AIIR/ แล้วส่งกลับเป็น JSON
 *
 * Mirrors logic จาก api.py (Python/Streamlit original)
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

// ---- Session สำหรับเก็บ Cookie jar ----
session_start();

// ---- Cookie jar path (เก็บ Session Cookie ของ AIIR) ----
$cookieFile = sys_get_temp_dir() . '/aiir_cookie_' . session_id() . '.txt';

// ---- Base URL ----
define('AIIR_BASE', 'https://emtrontech.com/AIIR/');

// ---- Action Router ----
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':      doLogin();    break;
    case 'getSiteData': getSiteData(); break;
    case 'getSpecData': getSpecData(); break;
    case 'logout':     doLogout();   break;
    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}

/* ============================================================
   ฟังก์ชัน cURL helper
   ============================================================ */
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

/* ============================================================
   LOGIN
   mirrors: api.py → login_to_aiir()
   ============================================================ */
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

    // Step 1: POST to userAuthen.php
    $postData = ['u' => $uHash, 'p' => $pHash, 'd' => '0'];
    $r = makeCurl(AIIR_BASE . 'userAuthen.php', $postData, [
        'Referer: ' . AIIR_BASE . 'login.php',
    ]);

    if (!empty($r['error'])) {
        echo json_encode(['ok' => false, 'error' => 'cURL error: ' . $r['error']]);
        return;
    }

    // Step 2: GET index.php เพื่อตรวจว่า Login สำเร็จ (session ต้องไม่ redirect ไป login.php)
    $check = makeCurl(AIIR_BASE . 'index.php', [], [], true);
    $loggedIn = stripos($check['finalUrl'], 'login.php') === false;

    if ($loggedIn) {
        $_SESSION['aiir_logged_in'] = true;
    }

    echo json_encode(['ok' => $loggedIn]);
}

/* ============================================================
   GET SITE DATA (All Sites)
   mirrors: api.py → fetch_sensor_data()
   ============================================================ */
function getSiteData(): void {
    // POST to getSiteData.php (session cookie ส่งอัตโนมัติผ่าน cookie jar)
    $r = makeCurl(AIIR_BASE . 'getSiteData.php', ['dummy' => '1'], [
        'Referer: ' . AIIR_BASE . 'index.php',
    ]);

    if (!empty($r['error'])) {
        echo json_encode(['ok' => false, 'error' => $r['error']]);
        return;
    }

    $text = trim($r['body'] ?? '');

    // ถ้า response มี <html> → session หมดอายุ
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

    // แปลงเป็น format ที่ JS ต้องการ
    $records = [];
    foreach ($raw as $item) {
        $d = $item['data'] ?? [];
        $records[] = [
            'Site'   => (string)($item['s']    ?? '?'),
            'Status' => (string)($item['stat'] ?? 'N/A'),
            'RSSI'   => (string)($item['rssi'] ?? '0'),
            'PM2.5'  => (float)($d['Rpm25Device'] ?? $d['pm25Device'] ?? 0),
            'PM10'   => (float)($d['Rpm10Device'] ?? $d['pm10Device'] ?? 0),
            'CO2'    => (float)($d['Rco2Device']  ?? $d['co2Device']  ?? 0),
            'Update' => (string)($d['updateSite'] ?? $d['lastUpdate'] ?? ''),
        ];
    }

    echo json_encode(['ok' => true, 'data' => $records]);
}

/* ============================================================
   GET SPEC DATA (Temp + Humidity for Site 4)
   mirrors: api.py → fetch_temp_humidity(site_id="4")
   ============================================================ */
function getSpecData(): void {
    $siteId = $_GET['site'] ?? '4';

    // Step 1: GET siteData.php แบบ browser ปกติ (ห้ามส่ง X-Requested-With: XMLHttpRequest)
    // เพราะ AIIR server จะ setup PHP session state ก็ต่อเมื่อเป็น page load ปกติเท่านั้น
    $pageUrl = AIIR_BASE . 'siteData.php?id=' . $siteId . '&type=' . $siteId . '&sName=ICT401';
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
        CURLOPT_ENCODING       => '',   // รองรับ gzip/deflate อัตโนมัติ
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: th,en;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
            'Upgrade-Insecure-Requests: 1',
            'Referer: ' . AIIR_BASE . 'index.php',
            // *** ไม่ส่ง X-Requested-With *** เพราะต้องการให้ server คิดว่าเป็น page load
        ],
    ]);
    $r1body = curl_exec($ch1);
    curl_close($ch1);

    // รอให้ AIIR server บันทึก session state
    usleep(300000); // 300ms

    // Step 2: POST getSpecSiteData.php — ใช้ FOLLOWLOCATION + gzip
    $body   = 'id=' . $siteId . '&type=' . $siteId . '&sid=' . $siteId;
    $apiUrl = AIIR_BASE . 'getSpecSiteData.php';

    global $cookieFile;
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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . $pageUrl,
            'Accept: application/json, text/javascript, */*; q=0.01',
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

    // Session หมดอายุ (redirect ไป login.php)
    if (stripos($finalUrl, 'login.php') !== false) {
        echo json_encode(['ok' => false, 'error' => 'session_expired']);
        return;
    }

    if ($text2 === '') {
        echo json_encode([
            'ok'       => false,
            'error'    => 'Empty response — Login ผ่านหน้า Dashboard ก่อน แล้ว Refresh',
            'httpCode' => $httpCode,
            'finalUrl' => $finalUrl,
        ]);
        return;
    }

    // response เป็น HTML → session หมด
    if (stripos(substr($text2, 0, 60), '<html') !== false || stripos(substr($text2, 0, 60), '<!DOCTYPE') !== false) {
        echo json_encode(['ok' => false, 'error' => 'session_expired', 'code' => $httpCode]);
        return;
    }

    $j = @json_decode($text2, true);
    if (!is_array($j)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON', 'raw' => substr($text2, 0, 300)]);
        return;
    }

    // Response อาจเป็น {"d": {...}} หรือ {...} โดยตรง
    $d = isset($j['d']) && is_array($j['d']) ? $j['d'] : $j;

    $temp  = (float)($d['tempDevice']  ?? $d['RtempDevice']  ?? $d['temp']  ?? 0);
    $humid = (float)($d['RhumidDevice'] ?? $d['humidDevice'] ?? $d['humid'] ?? 0);
    $evoc  = (float)($d['RevocDevice'] ?? $d['evocDevice']  ?? $d['evoc']  ?? 0);

    // PM2.5, PM10, CO2 อยู่ใน d ด้วย — current value (pm25Device) ก่อน, rolling avg (Rpm25Device) เป็น fallback
    $pm25  = (float)($d['pm25Device']  ?? $d['Rpm25Device']  ?? 0);
    $pm10  = (float)($d['pm10Device']  ?? $d['Rpm10Device']  ?? 0);
    $co2   = (float)($d['co2Device']   ?? $d['Rco2Device']   ?? 0);

    // rssi อยู่ที่ top-level ของ response ไม่ใช่ใน d
    $rssi  = (string)($j['rssi'] ?? '');

    echo json_encode([
        'ok'    => true,
        'temp'  => $temp,
        'humid' => $humid,
        'evoc'  => $evoc,
        'pm25'  => $pm25,
        'pm10'  => $pm10,
        'co2'   => $co2,
        'rssi'  => $rssi,
        'raw'   => $d,
    ]);
}

/* ============================================================
   LOGOUT
   ============================================================ */
function doLogout(): void {
    global $cookieFile;
    if (file_exists($cookieFile)) @unlink($cookieFile);
    $_SESSION['aiir_logged_in'] = false;
    session_destroy();
    echo json_encode(['ok' => true]);
}
