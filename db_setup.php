<?php
/**
 * db_setup.php — Enterprise Database Management & Migration Studio
 * AIR IAQ Smart Dashboard (ICT BRB)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

$message = null;
$messageType = 'info';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'init_schema') {
        try {
            IAQDatabase::initSchema();
            $message = 'สร้างโครงสร้างตาราง (Schema Migration) และข้อมูลเริ่มต้นสำเร็จเรียบร้อย!';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'เกิดข้อผิดพลาดในการสร้างตาราง: ' . $e->getMessage();
            $messageType = 'danger';
        }
    } elseif ($action === 'migrate_json') {
        try {
            IAQDatabase::autoMigrateLegacyData();
            $message = 'นำเข้าข้อมูลประวัติจากไฟล์ JSON แคชสำเร็จเรียบร้อย!';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'เกิดข้อผิดพลาดในการนำเข้า JSON: ' . $e->getMessage();
            $messageType = 'danger';
        }
    } elseif ($action === 'reset_data') {
        if (IAQDatabase::resetAllData()) {
            $message = 'ล้างข้อมูลตรวจวัด (Telemetry Data) ทั้งหมดเรียบร้อยแล้ว!';
            $messageType = 'warning';
        } else {
            $message = 'ไม่สามารถล้างข้อมูลได้';
            $messageType = 'danger';
        }
    }
}

$diag = IAQDatabase::getDiagnostics();
$activeDriver = $diag['activeDriver'];
$isMysqlActive = ($activeDriver === 'mysql');
$tables = $diag['tableCounts'] ?? [];

$sqlFilePath = __DIR__ . '/air_quality_db.sql';
$sqlFileExists = file_exists($sqlFilePath);
$sqlFileSize = $sqlFileExists ? round(filesize($sqlFilePath) / 1024, 1) : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Database Studio — AIR IAQ Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #0D9488;
      --primary-hover: #0F766E;
      --primary-subtle: #F0FDFA;
      --secondary: #0284C7;
      --tertiary: #C36D4B;
      --success: #10B981;
      --warning: #F59E0B;
      --danger: #EF4444;
      --bg-page: #F8FAFC;
      --bg-surface: #FFFFFF;
      --border: #E2E8F0;
      --text-main: #0F172A;
      --text-muted: #64748B;
      --radius-lg: 16px;
      --radius-md: 10px;
      --shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05), 0 2px 6px -1px rgba(0, 0, 0, 0.03);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', 'Noto Sans Thai', sans-serif;
      background: var(--bg-page);
      color: var(--text-main);
      line-height: 1.6;
      padding: 32px 20px;
    }

    .container {
      max-width: 1100px;
      margin: 0 auto;
    }

    /* Header */
    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 28px;
      padding-bottom: 20px;
      border-bottom: 1px solid var(--border);
    }
    .header-title {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .logo-badge {
      width: 46px;
      height: 46px;
      background: linear-gradient(135deg, #0D9488, #0284C7);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      color: #fff;
      box-shadow: 0 8px 16px rgba(13, 148, 136, 0.25);
    }
    .header h1 {
      font-size: 24px;
      font-weight: 800;
      letter-spacing: -0.02em;
    }
    .header p {
      font-size: 13px;
      color: var(--text-muted);
    }
    .nav-links {
      display: flex;
      gap: 12px;
    }
    .btn-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      background: var(--bg-surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      color: var(--text-main);
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      transition: all 0.2s;
    }
    .btn-link:hover {
      border-color: var(--primary);
      color: var(--primary);
    }

    /* Alert Banner */
    .alert-banner {
      padding: 14px 18px;
      border-radius: var(--radius-md);
      font-size: 14px;
      margin-bottom: 24px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .alert-banner.success { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
    .alert-banner.danger { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
    .alert-banner.warning { background: #FFFBEB; color: #92400E; border: 1px solid #FDE68A; }
    .alert-banner.info { background: #EFF6FF; color: #1E40AF; border: 1px solid #BFDBFE; }

    /* Grid Layout */
    .grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 24px;
    }
    @media (max-width: 768px) {
      .grid-2 { grid-template-columns: 1fr; }
    }

    /* Cards */
    .card {
      background: var(--bg-surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 24px;
      box-shadow: var(--shadow);
    }
    .card-title {
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    .badge-mysql { background: #E0F2FE; color: #0369A1; }
    .badge-sqlite { background: #FEF3C7; color: #B45309; }
    .badge-ok { background: #D1FAE5; color: #065F46; }
    .badge-err { background: #FEE2E2; color: #991B1B; }

    /* Info Table */
    .info-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .info-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 13px;
      padding-bottom: 8px;
      border-bottom: 1px dashed var(--border);
    }
    .info-item:last-child { border-bottom: none; }
    .info-label { color: var(--text-muted); }
    .info-val { font-weight: 600; font-family: monospace; }

    /* Tables Grid */
    .tables-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 14px;
      margin-top: 14px;
    }
    .table-box {
      background: #F8FAFC;
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      transition: all 0.2s;
    }
    .table-box:hover {
      border-color: var(--primary);
      background: #fff;
      transform: translateY(-2px);
    }
    .table-name {
      font-size: 14px;
      font-weight: 700;
      color: var(--text-main);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .table-desc {
      font-size: 12px;
      color: var(--text-muted);
    }
    .table-count {
      font-size: 18px;
      font-weight: 800;
      color: var(--primary);
      margin-top: 4px;
    }

    /* Actions Area */
    .actions-row {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 20px;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 20px;
      font-size: 14px;
      font-weight: 600;
      border-radius: var(--radius-md);
      cursor: pointer;
      border: none;
      transition: all 0.2s;
    }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-secondary { background: var(--secondary); color: #fff; }
    .btn-danger { background: #FEE2E2; color: var(--danger); border: 1px solid #FECACA; }
    .btn-danger:hover { background: var(--danger); color: #fff; }

    /* SQL Import Guide */
    .guide-box {
      background: #F1F5F9;
      border-left: 4px solid var(--secondary);
      border-radius: 0 var(--radius-md) var(--radius-md) 0;
      padding: 16px;
      font-size: 13px;
      margin-top: 18px;
    }
    .guide-box ol {
      margin-left: 20px;
      margin-top: 8px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    code {
      background: #E2E8F0;
      padding: 2px 6px;
      border-radius: 4px;
      font-family: monospace;
      font-size: 12px;
    }
  </style>
</head>
<body>
  <div class="container">
    <!-- Header -->
    <div class="header">
      <div class="header-title">
        <div class="logo-badge">🗄️</div>
        <div>
          <h1>Database Management Studio</h1>
          <p>ระบบจัดการและตรวจสอบฐานข้อมูลอัจฉริยะ (Target: air_quality_db)</p>
        </div>
      </div>
      <div class="nav-links">
        <a href="index.html" class="btn-link">📊 ไปยัง Dashboard</a>
        <a href="test_api.php" class="btn-link">⚡ Test API Diag</a>
        <a href="http://10.7.1.95/phpmyadmin/index.php?route=/database/structure&db=air_quality_db" target="_blank" class="btn-link" style="color: var(--secondary); border-color: var(--secondary);">🌐 เปิด phpMyAdmin</a>
      </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($message): ?>
      <div class="alert-banner <?= htmlspecialchars($messageType) ?>">
        <span><?= $messageType === 'success' ? '✅' : ($messageType === 'warning' ? '⚠️' : 'ℹ️') ?></span>
        <span><?= htmlspecialchars($message) ?></span>
      </div>
    <?php endif; ?>

    <!-- Connection Overview -->
    <div class="grid-2">
      <!-- Active Driver Card -->
      <div class="card">
        <div class="card-title">
          <span>สถานะการเชื่อมต่อฐานข้อมูล</span>
          <?php if ($isMysqlActive): ?>
            <span class="badge badge-mysql">🐬 MySQL (Enterprise)</span>
          <?php else: ?>
            <span class="badge badge-sqlite">📁 SQLite (Auto-Fallback)</span>
          <?php endif; ?>
        </div>

        <div class="info-list">
          <div class="info-item">
            <span class="info-label">Active Storage Driver</span>
            <span class="info-val" style="color: <?= $isMysqlActive ? 'var(--secondary)' : 'var(--warning)' ?>">
              <?= strtoupper($activeDriver) ?>
            </span>
          </div>
          <div class="info-item">
            <span class="info-label">MySQL Target Host</span>
            <span class="info-val"><?= htmlspecialchars($diag['mysqlHost']) ?>:<?= $diag['mysqlPort'] ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Target Database</span>
            <span class="info-val" style="color: var(--primary);"><?= htmlspecialchars($diag['mysqlDatabase']) ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">MySQL User</span>
            <span class="info-val"><?= htmlspecialchars($diag['mysqlUser']) ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Config File</span>
            <span class="info-val">db_config.php</span>
          </div>
          <div class="info-item">
            <span class="info-label">Server Timezone</span>
            <span class="info-val"><?= htmlspecialchars($diag['timezone']) ?> (<?= htmlspecialchars($diag['serverTime']) ?>)</span>
          </div>
        </div>

        <?php if (!empty($diag['connectionError'])): ?>
          <div style="margin-top: 14px; padding: 10px; background: #FEF2F2; border-radius: 8px; font-size: 12px; color: #991B1B;">
            <strong>⚠️ MySQL Notice:</strong> <?= htmlspecialchars($diag['connectionError']) ?><br>
            <span style="color: #64748B;">ระบบสลับมาใช้ SQLite ชั่วคราวอัตโนมัติ เพื่อให้ Dashboard ทำงานได้อย่างต่อเนื่องโดยไม่สะดุด</span>
          </div>
        <?php endif; ?>
      </div>

      <!-- SQL Schema File & Actions -->
      <div class="card">
        <div class="card-title">
          <span>ไฟล์ Schema สำหรับ phpMyAdmin</span>
          <span class="badge badge-ok">Ready (.sql)</span>
        </div>

        <div class="info-list">
          <div class="info-item">
            <span class="info-label">Schema File</span>
            <span class="info-val">air_quality_db.sql</span>
          </div>
          <div class="info-item">
            <span class="info-label">ขนาดไฟล์</span>
            <span class="info-val"><?= $sqlFileSize ?> KB</span>
          </div>
          <div class="info-item">
            <span class="info-label">โครงสร้างรองรับ</span>
            <span class="info-val">7 Tables + 2 Views + Seed Data</span>
          </div>
          <div class="info-item">
            <span class="info-label">Database Engine</span>
            <span class="info-val">InnoDB / utf8mb4_unicode_ci</span>
          </div>
        </div>

        <div class="guide-box">
          <strong>📌 วิธีนำเข้าสู่ phpMyAdmin (10.7.1.95):</strong>
          <ol>
            <li>เปิด <a href="http://10.7.1.95/phpmyadmin/index.php?route=/database/structure&db=air_quality_db" target="_blank" style="color: var(--secondary); font-weight: 600;">phpMyAdmin &raquo; air_quality_db</a></li>
            <li>คลิกแท็บ <strong>Import (นำเข้า)</strong> ด้านบน</li>
            <li>เลือกไฟล์ <code>c:\Users\tn_setthanan\Desktop\Copy\air_quality_db.sql</code></li>
            <li>กดปุ่ม <strong>Import (นำเข้า)</strong> ที่ด้านล่าง ทุกตารางและวิวจะถูกสร้างทันที!</li>
          </ol>
        </div>
      </div>
    </div>

    <!-- Schema Tables Explorer -->
    <div class="card" style="margin-bottom: 24px;">
      <div class="card-title">
        <span>ตารางในระบบฐานข้อมูล (Schema Explorer)</span>
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">ตารางทั้งหมด 7 ตาราง</span>
      </div>

      <div class="tables-grid">
        <div class="table-box">
          <div class="table-name">
            <span>📍 sites</span>
            <span class="badge <?= ($tables['sites'] ?? -1) >= 0 ? 'badge-ok' : 'badge-err' ?>"><?= ($tables['sites'] ?? -1) >= 0 ? 'OK' : 'MISSING' ?></span>
          </div>
          <div class="table-desc">ข้อมูลสถานที่/ห้อง ICT401 และเซ็นเซอร์</div>
          <div class="table-count"><?= max(0, $tables['sites'] ?? 0) ?> <span style="font-size: 12px; font-weight: normal; color: var(--text-muted);">sites</span></div>
        </div>

        <div class="table-box">
          <div class="table-name">
            <span>⚡ telemetry_records</span>
            <span class="badge <?= ($tables['telemetry_records'] ?? -1) >= 0 ? 'badge-ok' : 'badge-err' ?>"><?= ($tables['telemetry_records'] ?? -1) >= 0 ? 'OK' : 'MISSING' ?></span>
          </div>
          <div class="table-desc">ข้อมูลตรวจวัดสด Real-time 7 ตัววัด</div>
          <div class="table-count"><?= max(0, $tables['telemetry_records'] ?? 0) ?> <span style="font-size: 12px; font-weight: normal; color: var(--text-muted);">records</span></div>
        </div>

        <div class="table-box">
          <div class="table-name">
            <span>⏱️ telemetry_45m_snapshots</span>
            <span class="badge <?= ($tables['telemetry_45m_snapshots'] ?? -1) >= 0 ? 'badge-ok' : 'badge-err' ?>"><?= ($tables['telemetry_45m_snapshots'] ?? -1) >= 0 ? 'OK' : 'MISSING' ?></span>
          </div>
          <div class="table-desc">สแนปช็อตสรุปข้อมูลระยะยาว 45 นาที</div>
          <div class="table-count"><?= max(0, $tables['telemetry_45m_snapshots'] ?? 0) ?> <span style="font-size: 12px; font-weight: normal; color: var(--text-muted);">points</span></div>
        </div>

        <div class="table-box">
          <div class="table-name">
            <span>🚨 iaq_alerts</span>
            <span class="badge <?= ($tables['iaq_alerts'] ?? -1) >= 0 ? 'badge-ok' : 'badge-err' ?>"><?= ($tables['iaq_alerts'] ?? -1) >= 0 ? 'OK' : 'MISSING' ?></span>
          </div>
          <div class="table-desc">ประวัติแจ้งเตือนค่าเกินเกณฑ์มาตรฐาน</div>
          <div class="table-count"><?= max(0, $tables['iaq_alerts'] ?? 0) ?> <span style="font-size: 12px; font-weight: normal; color: var(--text-muted);">alerts</span></div>
        </div>

        <div class="table-box">
          <div class="table-name">
            <span>🔄 system_sync_status</span>
            <span class="badge <?= ($tables['system_sync_status'] ?? -1) >= 0 ? 'badge-ok' : 'badge-err' ?>"><?= ($tables['system_sync_status'] ?? -1) >= 0 ? 'OK' : 'MISSING' ?></span>
          </div>
          <div class="table-desc">สถานะการเชื่อมต่อ API Proxy และ Latency</div>
          <div class="table-count"><?= max(0, $tables['system_sync_status'] ?? 0) ?> <span style="font-size: 12px; font-weight: normal; color: var(--text-muted);">syncs</span></div>
        </div>

        <div class="table-box">
          <div class="table-name">
            <span>⚙️ system_settings</span>
            <span class="badge <?= ($tables['system_settings'] ?? -1) >= 0 ? 'badge-ok' : 'badge-err' ?>"><?= ($tables['system_settings'] ?? -1) >= 0 ? 'OK' : 'MISSING' ?></span>
          </div>
          <div class="table-desc">เกณฑ์ความปลอดภัยและค่าคอนฟิกระบบ</div>
          <div class="table-count"><?= max(0, $tables['system_settings'] ?? 0) ?> <span style="font-size: 12px; font-weight: normal; color: var(--text-muted);">settings</span></div>
        </div>

        <div class="table-box">
          <div class="table-name">
            <span>👥 users</span>
            <span class="badge <?= ($tables['users'] ?? -1) >= 0 ? 'badge-ok' : 'badge-err' ?>"><?= ($tables['users'] ?? -1) >= 0 ? 'OK' : 'MISSING' ?></span>
          </div>
          <div class="table-desc">บัญชีผู้ดูแลระบบและเจ้าหน้าที่ Operator</div>
          <div class="table-count"><?= max(0, $tables['users'] ?? 0) ?> <span style="font-size: 12px; font-weight: normal; color: var(--text-muted);">users</span></div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="actions-row">
        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="init_schema">
          <button type="submit" class="btn btn-primary">
            <span>🚀</span> อัปเดต / สร้างตารางอัตโนมัติ (Initialize Schema)
          </button>
        </form>

        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="migrate_json">
          <button type="submit" class="btn btn-secondary">
            <span>📥</span> นำเข้าข้อมูลประวัติเดิมจาก JSON
          </button>
        </form>

        <form method="POST" style="display:inline;" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการล้างข้อมูลตรวจวัดทั้งหมด? ข้อมูลจะถูกลบและเริ่มบันทึกใหม่');">
          <input type="hidden" name="action" value="reset_data">
          <button type="submit" class="btn btn-danger">
            <span>🗑️</span> ล้างข้อมูลตรวจวัด (Reset Data)
          </button>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
