<?php
/**
 * test_api.php — AIIR API Connection & Route Diagnostics Tool
 * หน้าทดสอบและวินิจฉัยเครือข่ายสำหรับระบบ AIIR IAQ (Site 4 ICT401)
 * สามารถทดสอบทั้งแบบเชื่อมต่อตรง (Direct) และผ่าน Corporate Proxy พร้อมวัด Latency
 */

header('Content-Type: text/html; charset=utf-8');

// ---- Server Diagnostic Checks ----
$curlEnabled   = extension_loaded('curl');
$tempWritable  = is_writable(sys_get_temp_dir());
$phpVer        = PHP_VERSION;
$serverIp      = $_SERVER['SERVER_ADDR'] ?? (gethostbyname(gethostname()) ?: '127.0.0.1');
$clientIp      = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Check corporate proxy env
$envProxy = getenv('HTTP_PROXY') ?: (getenv('http_proxy') ?: (getenv('ALL_PROXY') ?: 'None'));

// Function to test cURL
function testEndpoint(string $route = 'DIRECT'): array {
    $url = 'https://emtrontech.com/AIIR/getSpecSiteData.php';
    $postData = ['site' => '4', 'siteType' => '4', '_t' => time()];

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://emtrontech.com/AIIR/siteData.php?id=4&type=4&sName=ICT401',
            'Accept: application/json, text/javascript, */*; q=0.01',
        ],
    ];

    if ($route !== 'DIRECT') {
        $opts[CURLOPT_PROXY] = $route;
    }

    curl_setopt_array($ch, $opts);

    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $ms = round((microtime(true) - $t0) * 1000, 2);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $parsed = null;
    if ($code === 200 && !empty($raw)) {
        $json = @json_decode($raw, true);
        if (is_array($json)) {
            $parsed = isset($json['d']) && is_array($json['d']) ? $json['d'] : $json;
            $parsed['_rssi'] = $json['rssi'] ?? $parsed['rssi'] ?? 'N/A';
        }
    }

    return [
        'route'      => $route,
        'httpCode'   => $code,
        'durationMs' => $ms,
        'error'      => $err,
        'raw'        => $raw,
        'data'       => $parsed,
        'success'    => ($code === 200 && $parsed !== null),
    ];
}

// Perform Tests
$testDirect  = testEndpoint('DIRECT');
$testProxy1  = testEndpoint('10.7.21.17:8080');
$testProxy2  = testEndpoint('ssproxy.boonrawd.co.th:8080');

// Overall status
$bestRoute = null;
if ($testProxy1['success']) {
    $bestRoute = $testProxy1;
} elseif ($testDirect['success']) {
    $bestRoute = $testDirect;
} elseif ($testProxy2['success']) {
    $bestRoute = $testProxy2;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>🧪 AIIR API Diagnostics & Route Tester</title>
  <style>
    :root {
      --bg: #F8FAFC;
      --card-bg: #FFFFFF;
      --text: #0F172A;
      --muted: #64748B;
      --success: #10B981;
      --danger: #EF4444;
      --primary: #0D9488;
      --border: #E2E8F0;
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      margin: 0;
      padding: 24px;
      line-height: 1.5;
    }
    .container {
      max-width: 900px;
      margin: 0 auto;
    }
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
    }
    h1 { margin: 0; font-size: 1.5rem; color: var(--primary); display: flex; align-items: center; gap: 8px; }
    .btn-refresh {
      background: var(--primary);
      color: white;
      border: none;
      padding: 8px 18px;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      font-size: 0.9rem;
      transition: opacity 0.2s;
    }
    .btn-refresh:hover { opacity: 0.9; }
    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .card-title {
      font-weight: 700;
      font-size: 1.1rem;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
    }
    .badge-ok { background: #E6F4EA; color: #137333; }
    .badge-err { background: #FCE8E6; color: #C5221F; }
    .badge-warn { background: #FEF3C7; color: #92400E; }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
      gap: 12px;
    }
    .metric-box {
      background: #F1F5F9;
      padding: 12px;
      border-radius: 10px;
      text-align: center;
    }
    .metric-val {
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--primary);
    }
    .metric-lbl {
      font-size: 0.8rem;
      color: var(--muted);
      margin-top: 4px;
    }
    pre {
      background: #0F172A;
      color: #38BDF8;
      padding: 16px;
      border-radius: 8px;
      overflow-x: auto;
      font-size: 0.85rem;
    }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
    th { color: var(--muted); font-weight: 600; }
    .route-card {
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 14px;
      margin-bottom: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .route-active {
      border-color: #0D9488;
      background: #F0FDFA;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>🧪 AIIR API Connection & Route Tester</h1>
      <div style="display:flex; gap: 8px;">
        <a href="test_api.php" class="btn-refresh">🔄 ทดสอบใหม่ (Rerun)</a>
        <a href="index.html" class="btn-refresh" style="background:#0284C7;">📊 ไปหน้า Dashboard</a>
      </div>
    </div>

    <!-- Overview Status Card -->
    <div class="card">
      <div class="card-title">
        🌐 สถานะการเชื่อมต่อภาพรวม
        <?php if ($bestRoute): ?>
          <span class="status-badge badge-ok">✅ ใช้งานได้ปกติ (Route: <?= htmlspecialchars($bestRoute['route']) ?>)</span>
        <?php else: ?>
          <span class="status-badge badge-err">❌ เชื่อมต่อไม่ได้ทุกเส้นทาง</span>
        <?php endif; ?>
      </div>
      <p style="margin: 0; color: var(--muted); font-size: 0.95rem;">
        <?php if ($bestRoute): ?>
          ระบบสามารถเชื่อมต่อไปยังเซิร์ฟเวอร์ AIIR ได้สำเร็จ โดยใช้เส้นทาง <strong><?= htmlspecialchars($bestRoute['route']) ?></strong> (ใช้เวลาตอบสนอง <?= $bestRoute['durationMs'] ?> ms)
        <?php else: ?>
          ไม่สามารถเชื่อมต่อออกภายนอกได้ กรุณาตรวจสอบการตั้งค่าไฟร์วอลล์ หรือ Proxy บนระบบปฏิบัติการของเซิร์ฟเวอร์ VM
        <?php endif; ?>
      </p>
    </div>

    <!-- Route Test Comparison -->
    <div class="card">
      <div class="card-title">🚀 ผลการทดสอบเปรียบเทียบแต่ละเส้นทาง (Route Comparison)</div>
      
      <!-- 1. Proxy 10.7.21.17:8080 -->
      <div class="route-card <?= $testProxy1['success'] ? 'route-active' : '' ?>">
        <div>
          <div style="font-weight:700;">🏢 Corporate Proxy (10.7.21.17:8080)</div>
          <div style="font-size:0.85rem; color:var(--muted);">
            HTTP Code: <strong><?= $testProxy1['httpCode'] ?></strong> | Latency: <strong><?= $testProxy1['durationMs'] ?> ms</strong>
            <?php if ($testProxy1['error']): ?>
              | <span style="color:var(--danger);"><?= htmlspecialchars($testProxy1['error']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <?php if ($testProxy1['success']): ?>
            <span class="status-badge badge-ok">SUCCESS</span>
          <?php else: ?>
            <span class="status-badge badge-err">FAILED</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- 2. Proxy ssproxy.boonrawd.co.th:8080 -->
      <div class="route-card <?= $testProxy2['success'] && !$testProxy1['success'] ? 'route-active' : '' ?>">
        <div>
          <div style="font-weight:700;">🏢 Corporate Proxy Hostname (ssproxy.boonrawd.co.th:8080)</div>
          <div style="font-size:0.85rem; color:var(--muted);">
            HTTP Code: <strong><?= $testProxy2['httpCode'] ?></strong> | Latency: <strong><?= $testProxy2['durationMs'] ?> ms</strong>
            <?php if ($testProxy2['error']): ?>
              | <span style="color:var(--danger);"><?= htmlspecialchars($testProxy2['error']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <?php if ($testProxy2['success']): ?>
            <span class="status-badge badge-ok">SUCCESS</span>
          <?php else: ?>
            <span class="status-badge badge-err">FAILED</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- 3. Direct Connection -->
      <div class="route-card <?= $testDirect['success'] && !$testProxy1['success'] ? 'route-active' : '' ?>">
        <div>
          <div style="font-weight:700;">🌍 Direct Connection (เน็ตตรง ไม่ผ่าน Proxy)</div>
          <div style="font-size:0.85rem; color:var(--muted);">
            HTTP Code: <strong><?= $testDirect['httpCode'] ?></strong> | Latency: <strong><?= $testDirect['durationMs'] ?> ms</strong>
            <?php if ($testDirect['error']): ?>
              | <span style="color:var(--danger);"><?= htmlspecialchars($testDirect['error']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <?php if ($testDirect['success']): ?>
            <span class="status-badge badge-ok">SUCCESS</span>
          <?php else: ?>
            <span class="status-badge badge-err">BLOCKED / TIMEOUT</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Live Sensor Readings Preview -->
    <?php if ($bestRoute && !empty($bestRoute['data'])): 
      $d = $bestRoute['data'];
    ?>
    <div class="card">
      <div class="card-title">📊 ข้อมูลเซ็นเซอร์สด (Room SITE 4 ICT 401)</div>
      <div class="grid">
        <div class="metric-box">
          <div class="metric-val"><?= htmlspecialchars($d['pm25Device'] ?? $d['Rpm25Device'] ?? '0') ?></div>
          <div class="metric-lbl">PM2.5 (µg/m³)</div>
        </div>
        <div class="metric-box">
          <div class="metric-val"><?= htmlspecialchars($d['pm10Device'] ?? $d['Rpm10Device'] ?? '0') ?></div>
          <div class="metric-lbl">PM10 (µg/m³)</div>
        </div>
        <div class="metric-box">
          <div class="metric-val"><?= htmlspecialchars($d['co2Device'] ?? $d['Rco2Device'] ?? '0') ?></div>
          <div class="metric-lbl">CO2 (ppm)</div>
        </div>
        <div class="metric-box">
          <div class="metric-val"><?= htmlspecialchars($d['tempDevice'] ?? $d['RtempDevice'] ?? '0') ?>°C</div>
          <div class="metric-lbl">อุณหภูมิ</div>
        </div>
        <div class="metric-box">
          <div class="metric-val"><?= htmlspecialchars($d['RhumidDevice'] ?? $d['humidDevice'] ?? '0') ?>%</div>
          <div class="metric-lbl">ความชื้น</div>
        </div>
        <div class="metric-box">
          <div class="metric-val"><?= htmlspecialchars($d['RevocDevice'] ?? $d['evocDevice'] ?? '0') ?></div>
          <div class="metric-lbl">eVOC (ppb)</div>
        </div>
        <div class="metric-box">
          <div class="metric-val"><?= htmlspecialchars($d['_rssi'] ?? '0') ?></div>
          <div class="metric-lbl">RSSI (dBm)</div>
        </div>
      </div>
      <div style="font-size:0.85rem; color:var(--muted); margin-top:12px; text-align:right;">
        เวลาอัปเดตจากเซ็นเซอร์: <strong><?= htmlspecialchars($d['lastUpdate'] ?? '-') ?></strong>
      </div>
    </div>
    <?php endif; ?>

    <!-- System Diagnostic Details -->
    <div class="card">
      <div class="card-title">⚙️ สภาพแวดล้อมระบบและเครื่องเซิร์ฟเวอร์ (Server Environment)</div>
      <table>
        <tr>
          <th>Server IP</th>
          <td><?= htmlspecialchars($serverIp) ?></td>
        </tr>
        <tr>
          <th>Client IP</th>
          <td><?= htmlspecialchars($clientIp) ?></td>
        </tr>
        <tr>
          <th>PHP Version</th>
          <td><?= htmlspecialchars($phpVer) ?></td>
        </tr>
        <tr>
          <th>cURL Extension</th>
          <td><?= $curlEnabled ? '<span class="status-badge badge-ok">Enabled</span>' : '<span class="status-badge badge-err">Disabled</span>' ?></td>
        </tr>
        <tr>
          <th>Temp Directory Writable</th>
          <td><?= $tempWritable ? '<span class="status-badge badge-ok">Writable (' . htmlspecialchars(sys_get_temp_dir()) . ')</span>' : '<span class="status-badge badge-err">Not Writable</span>' ?></td>
        </tr>
        <tr>
          <th>Environment Proxy</th>
          <td><?= htmlspecialchars($envProxy) ?></td>
        </tr>
      </table>
    </div>

    <!-- Raw API Response Payload -->
    <div class="card">
      <div class="card-title">📦 Raw Payload จาก API (Active Route: <?= htmlspecialchars($bestRoute['route'] ?? 'N/A') ?>)</div>
      <pre><?= htmlspecialchars($bestRoute['raw'] ?? 'No data received') ?></pre>
    </div>
  </div>
</body>
</html>
