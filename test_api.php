<?php
/**
 * test_api.php — Simple API & Server Connection Tester for Emtrontech AIIR
 * ใช้สำหรับทดสอบการเชื่อมต่อ cURL, SSL Handshake และดึงข้อมูลสดจาก emtrontech.com บน Server
 */

header('Content-Type: text/html; charset=utf-8');

// ---- Server Diagnostic Checks ----
$curlEnabled  = extension_loaded('curl');
$tempWritable = is_writable(sys_get_temp_dir());
$opensslVer   = defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'N/A';
$phpVer       = PHP_VERSION;

// ---- Test cURL Fetch to AIIR API ----
$startTime = microtime(true);

$targetUrl = 'https://emtrontech.com/AIIR/getSpecSiteData.php';
$pageUrl   = 'https://emtrontech.com/AIIR/siteData.php?id=4&type=4&sName=ICT401';
$cookieFile = sys_get_temp_dir() . '/aiir_test_cookie.txt';

$httpCode = 0;
$curlErr  = '';
$rawBody  = '';
$parsedData = null;

$viaGateway = false;

function getProxyServer(): string {
    if (getenv('HTTP_PROXY')) return getenv('HTTP_PROXY');
    foreach (['ssproxy.boonrawd.co.th:8080', '10.7.21.17:8080'] as $proxy) {
        list($host, $port) = explode(':', $proxy);
        $fp = @fsockopen($host, (int)$port, $errCode, $errStr, 0.2);
        if ($fp) {
            fclose($fp);
            return $proxy;
        }
    }
    return '';
}

if ($curlEnabled) {
    $detectedProxy = getProxyServer();
    // Helper function to build cURL options
    $buildOpts = function($url, $isPost = false, $postBody = '') use ($cookieFile, $pageUrl, $detectedProxy) {
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        ];

        if (!empty($detectedProxy)) {
            $opts[CURLOPT_PROXY] = $detectedProxy;
        }

        if ($isPost) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $postBody;
            $opts[CURLOPT_HTTPHEADER] = [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With: XMLHttpRequest',
                'Referer: ' . $pageUrl,
                'Origin: https://emtrontech.com',
            ];
        }

        return $opts;
    };

    // 1. Establish cookie session with siteData.php
    $ch1 = curl_init();
    curl_setopt_array($ch1, $buildOpts($pageUrl));
    curl_exec($ch1);
    curl_close($ch1);

    // 2. Fetch live spec data from getSpecSiteData.php
    $ch = curl_init();
    curl_setopt_array($ch, $buildOpts($targetUrl, true, 'site=4&siteType=4'));

    $rawBody  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($rawBody && empty($curlErr)) {
        $json = json_decode($rawBody, true);
        if (is_array($json)) {
            $parsedData = isset($json['d']) && is_array($json['d']) ? $json['d'] : $json;
            $parsedData['_rssi'] = $json['rssi'] ?? $parsedData['rssi'] ?? 'N/A';
        }
    }

    // 3. Fallback via Public Gateway Proxy if direct server connection is reset by WAF
    if (empty($parsedData)) {
        $gatewayUrl = 'https://api.allorigins.win/get?url=' . urlencode('https://emtrontech.com/AIIR/siteData.php?id=4&type=4&sName=ICT401');
        $chG = curl_init();
        curl_setopt_array($chG, [
            CURLOPT_URL            => $gatewayUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        $gwRaw = curl_exec($chG);
        $gwCode = curl_getinfo($chG, CURLINFO_HTTP_CODE);
        curl_close($chG);

        // allorigins /get returns JSON wrapper with 'contents' field
        $gwJson = @json_decode($gwRaw, true);
        $gwBody = (is_array($gwJson) && !empty($gwJson['contents'])) ? $gwJson['contents'] : $gwRaw;

        if ($gwBody && $gwCode == 200 && strlen($gwBody) > 100) {
            preg_match('/Temp[\s\S]{0,120}?(\d+\.?\d*)\s*°?C/i', $gwBody, $mTemp);
            preg_match('/Humid[\s\S]{0,120}?(\d+\.?\d*)\s*%/i', $gwBody, $mHumid);
            preg_match('/eVOC[\s\S]{0,120}?(\d+\.?\d*)\s*ppb/i', $gwBody, $mEvoc);
            preg_match('/PM2[\.\\s]?5[\s\S]{0,120}?(\d+\.?\d*)\s*/i', $gwBody, $mPm25);
            preg_match('/PM10[\s\S]{0,120}?(\d+\.?\d*)\s*/i', $gwBody, $mPm10);
            preg_match('/CO2[\s\S]{0,120}?(\d+\.?\d*)\s*ppm/i', $gwBody, $mCo2);
            preg_match('/rssi[\s:]*(\d+)/i', $gwBody, $mRssi);
            preg_match('/Last\s*update[\s:]*(\d{4}[\-\/]\d{2}[\-\/]\d{2}\s+\d{2}:\d{2}:\d{2})/i', $gwBody, $mUpd);

            if (!empty($mTemp[1]) || !empty($mCo2[1])) {
                $parsedData = [
                    'temp'  => $mTemp[1] ?? 0,
                    'humid' => $mHumid[1] ?? 0,
                    'evoc'  => $mEvoc[1] ?? 0,
                    'pm25'  => $mPm25[1] ?? 0,
                    'pm10'  => $mPm10[1] ?? 0,
                    'co2'   => $mCo2[1] ?? 0,
                    '_rssi' => $mRssi[1] ?? 'N/A',
                    'lastUpdate' => $mUpd[1] ?? date('Y-m-d H:i:s'),
                ];
                $viaGateway = true;
                $rawBody    = substr($gwBody, 0, 500);
            }
        }
    }
}

$durationMs = round((microtime(true) - $startTime) * 1000, 2);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>🧪 AIIR API Connection Tester (ICT401)</title>
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
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: var(--bg);
      color: var(--text);
      margin: 0;
      padding: 24px;
      line-height: 1.5;
    }
    .container {
      max-width: 800px;
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
      padding: 8px 16px;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      font-size: 0.9rem;
    }
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
      margin-bottom: 12px;
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
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
    }
    .metric-box {
      background: #F1F5F9;
      padding: 14px;
      border-radius: 10px;
      text-align: center;
    }
    .metric-val {
      font-size: 1.8rem;
      font-weight: 800;
      color: var(--primary);
    }
    .metric-lbl {
      font-size: 0.85rem;
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
    th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
    th { color: var(--muted); font-weight: 600; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>🌿 AIIR API Connection Tester</h1>
      <a href="test_api.php" class="btn-refresh">🔄 ทดสอบอีกครั้ง (Re-test)</a>
    </div>

    <!-- Status Overview -->
    <div class="card">
      <div class="card-title">
        สถานะการเชื่อมต่อ (Connection Result)
        <?php if ($parsedData): ?>
          <span class="status-badge badge-ok">✅ สำเร็จ 200 OK (<?php echo $durationMs; ?> ms)</span>
        <?php else: ?>
          <span class="status-badge badge-err">❌ ล้มเหลว (HTTP <?php echo $httpCode; ?>)</span>
        <?php endif; ?>
      </div>

      <?php if (!empty($curlErr)): ?>
        <div style="color:var(--danger); background:#FEE2E2; padding:12px; border-radius:8px; margin-top:12px;">
          🚨 <strong>cURL Error:</strong> <?php echo htmlspecialchars($curlErr); ?>
        </div>
      <?php endif; ?>

      <table>
        <tr><th>เป้าหมาย (Target API)</th><td><code>https://emtrontech.com/AIIR/getSpecSiteData.php</code></td></tr>
        <tr><th>ห้องเซ็นเซอร์</th><td>Site 4 - ICT401</td></tr>
        <tr><th>เวลาตอบกลับ (Latency)</th><td><?php echo $durationMs; ?> ms</td></tr>
        <tr><th>PHP Version</th><td><?php echo $phpVer; ?></td></tr>
        <tr><th>cURL Extension</th><td><?php echo $curlEnabled ? '✅ Enabled' : '❌ Disabled'; ?></td></tr>
        <tr><th>OpenSSL Version</th><td><?php echo htmlspecialchars($opensslVer); ?></td></tr>
        <tr><th>Temp Dir Writable</th><td><?php echo $tempWritable ? '✅ Writable (' . sys_get_temp_dir() . ')' : '❌ Permission Denied'; ?></td></tr>
      </table>
    </div>

    <!-- Live Telemetry Data -->
    <?php if ($parsedData): ?>
      <?php
        $temp  = (float)($parsedData['tempDevice']  ?? $parsedData['RtempDevice']  ?? $parsedData['temp']  ?? 0);
        $humid = (float)($parsedData['RhumidDevice'] ?? $parsedData['humidDevice'] ?? $parsedData['humid'] ?? 0);
        $evoc  = (float)($parsedData['RevocDevice']  ?? $parsedData['evocDevice']  ?? $parsedData['evoc']  ?? 0);
        $pm25  = (float)($parsedData['pm25Device']  ?? $parsedData['Rpm25Device']  ?? $parsedData['pm25']  ?? 0);
        $pm10  = (float)($parsedData['pm10Device']  ?? $parsedData['Rpm10Device']  ?? $parsedData['pm10']  ?? 0);
        $co2   = (float)($parsedData['co2Device']   ?? $parsedData['Rco2Device']   ?? $parsedData['co2']   ?? 0);
        $rssi  = (string)($parsedData['_rssi'] ?? '0');
        $upd   = (string)($parsedData['lastUpdate'] ?? $parsedData['updateSite'] ?? date('d/m/Y H:i:s'));
      ?>
      <div class="card">
        <div class="card-title">📡 ข้อมูลเซ็นเซอร์สดจากเว็บปลายทาง (Live Data)</div>
        <div style="font-size:0.85rem; color:var(--muted); margin-bottom:16px;">⏱️ อัปเดตล่าสุดจากอุปกรณ์: <strong><?php echo htmlspecialchars($upd); ?></strong></div>
        <div class="grid">
          <div class="metric-box">
            <div class="metric-val"><?php echo number_format($pm25, 1); ?></div>
            <div class="metric-lbl">PM2.5 (µg/m³)</div>
          </div>
          <div class="metric-box">
            <div class="metric-val"><?php echo number_format($pm10, 1); ?></div>
            <div class="metric-lbl">PM10 (µg/m³)</div>
          </div>
          <div class="metric-box">
            <div class="metric-val"><?php echo number_format($co2, 0); ?></div>
            <div class="metric-lbl">CO2 (ppm)</div>
          </div>
          <div class="metric-box">
            <div class="metric-val"><?php echo number_format($temp, 1); ?>°C</div>
            <div class="metric-lbl">อุณหภูมิ</div>
          </div>
          <div class="metric-box">
            <div class="metric-val"><?php echo number_format($humid, 1); ?>%</div>
            <div class="metric-lbl">ความชื้น</div>
          </div>
          <div class="metric-box">
            <div class="metric-val"><?php echo number_format($evoc, 0); ?></div>
            <div class="metric-lbl">eVOC (ppb)</div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Raw API Response -->
    <div class="card">
      <div class="card-title">📄 Raw Payload จาก emtrontech.com</div>
      <pre><?php echo htmlspecialchars($rawBody ?: 'No response payload'); ?></pre>
    </div>
  </div>
</body>
</html>
