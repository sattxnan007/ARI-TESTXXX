<?php
/**
 * db.php — Enterprise Database Management Layer for AIR IAQ Dashboard
 * 
 * Provides robust PDO-based persistence supporting MySQL/MariaDB (Target: air_quality_db)
 * with transparent Zero-Config SQLite fallback.
 * Includes schema migrations, indexes, views, alerts logging, dynamic settings, and diagnostic tools.
 */

declare(strict_types=1);

require_once __DIR__ . '/db_config.php';

class IAQDatabase {
    private static ?PDO $pdo = null;
    private static bool $initialized = false;
    private static string $activeDriver = 'sqlite';
    private static ?string $lastConnectionError = null;

    /**
     * Get or initialize PDO connection (with MySQL-first, SQLite-fallback resilience)
     */
    public static function getConnection(): ?PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        self::$lastConnectionError = null;
        $targetDriver = strtolower(DB_TYPE);

        // 1. Try MySQL / MariaDB connection first if configured
        if ($targetDriver === 'mysql') {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    DB_MYSQL_HOST,
                    DB_MYSQL_PORT,
                    DB_MYSQL_NAME
                );

                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 3, // 3s connect timeout
                ];

                self::$pdo = new PDO($dsn, DB_MYSQL_USER, DB_MYSQL_PASS, $options);
                self::$activeDriver = 'mysql';

                if (!self::$initialized) {
                    self::initSchema();
                    self::$initialized = true;
                }

                return self::$pdo;
            } catch (Throwable $e) {
                self::$lastConnectionError = $e->getMessage();
                error_log('[IAQDatabase MySQL Error] ' . $e->getMessage() . ' — Falling back to SQLite.');
                self::$pdo = null;
            }
        }

        // 2. Fallback to SQLite (Ensures 100% continuous uptime)
        try {
            if (!is_dir(DB_SQLITE_DIR)) {
                @mkdir(DB_SQLITE_DIR, 0777, true);
            }

            $dsn = 'sqlite:' . DB_SQLITE_FILE;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ];

            self::$pdo = new PDO($dsn, null, null, $options);
            self::$activeDriver = 'sqlite';

            // High-concurrency pragmas for SQLite
            self::$pdo->exec('PRAGMA journal_mode = WAL;');
            self::$pdo->exec('PRAGMA synchronous = NORMAL;');
            self::$pdo->exec('PRAGMA busy_timeout = 5000;');

            if (!self::$initialized) {
                self::initSchema();
                self::$initialized = true;
            }

            return self::$pdo;
        } catch (Throwable $e) {
            self::$lastConnectionError = $e->getMessage();
            error_log('[IAQDatabase SQLite Error] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the active database driver ('mysql' or 'sqlite')
     */
    public static function getActiveDriver(): string {
        return self::$activeDriver;
    }

    /**
     * Get the last connection error message if any
     */
    public static function getLastConnectionError(): ?string {
        return self::$lastConnectionError;
    }

    /**
     * Initialize all Tables, Views, Indexes, and Default Seeds
     */
    public static function initSchema(): void {
        $db = self::$pdo;
        if (!$db) return;

        $isMysql = (self::$activeDriver === 'mysql');
        $autoInc = $isMysql ? 'BIGINT UNSIGNED AUTO_INCREMENT' : 'INTEGER';
        $intInc  = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT' : 'INTEGER';
        $engine  = $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        try {
            // 1. Sites Table
            $sqlSites = "
            CREATE TABLE IF NOT EXISTS sites (
                site_id VARCHAR(32) PRIMARY KEY,
                site_code VARCHAR(50) NOT NULL UNIQUE,
                site_name VARCHAR(100) NOT NULL,
                building VARCHAR(100) NOT NULL DEFAULT 'ICT BRB',
                floor VARCHAR(20) NOT NULL DEFAULT '4',
                room VARCHAR(50) NOT NULL DEFAULT '401',
                description VARCHAR(255) DEFAULT 'ห้องตรวจวัดคุณภาพอากาศชั้น 4 อาคาร ICT BRB',
                sensor_model VARCHAR(100) DEFAULT 'Emtrontech AIIR v2',
                mac_address VARCHAR(32) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) {$engine};";
            $db->exec($sqlSites);

            // 2. Telemetry Records Table
            $sqlTelemetry = "
            CREATE TABLE IF NOT EXISTS telemetry_records (
                id {$autoInc} PRIMARY KEY,
                site_id VARCHAR(32) NOT NULL DEFAULT '4',
                site_name VARCHAR(100) NOT NULL DEFAULT 'Site 4 - ICT401',
                pm25 REAL NOT NULL DEFAULT 0.0,
                pm10 REAL NOT NULL DEFAULT 0.0,
                co2 REAL NOT NULL DEFAULT 0.0,
                temp REAL NOT NULL DEFAULT 0.0,
                humid REAL NOT NULL DEFAULT 0.0,
                evoc REAL NOT NULL DEFAULT 0.0,
                rssi VARCHAR(16) NOT NULL DEFAULT '0',
                iaq_score INTEGER NOT NULL DEFAULT 0,
                sensor_time VARCHAR(50) DEFAULT NULL,
                server_timestamp BIGINT NOT NULL,
                server_time DATETIME NOT NULL,
                route_used VARCHAR(64) DEFAULT 'UNKNOWN',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) {$engine};";
            $db->exec($sqlTelemetry);

            // 3. 45-Minute Long-term Snapshots Table
            $sqlSnapshots = "
            CREATE TABLE IF NOT EXISTS telemetry_45m_snapshots (
                id {$autoInc} PRIMARY KEY,
                site_id VARCHAR(32) NOT NULL DEFAULT '4',
                site_name VARCHAR(100) NOT NULL DEFAULT 'Site 4 - ICT401',
                pm25 REAL NOT NULL DEFAULT 0.0,
                pm10 REAL NOT NULL DEFAULT 0.0,
                co2 REAL NOT NULL DEFAULT 0.0,
                temp REAL NOT NULL DEFAULT 0.0,
                humid REAL NOT NULL DEFAULT 0.0,
                evoc REAL NOT NULL DEFAULT 0.0,
                rssi VARCHAR(16) NOT NULL DEFAULT '0',
                iaq_score INTEGER NOT NULL DEFAULT 0,
                sensor_time VARCHAR(50) DEFAULT NULL,
                server_timestamp BIGINT NOT NULL,
                server_time DATETIME NOT NULL,
                label VARCHAR(32) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) {$engine};";
            $db->exec($sqlSnapshots);

            // 4. Incident & Exceedance Alerts Table
            $sqlAlerts = "
            CREATE TABLE IF NOT EXISTS iaq_alerts (
                id {$autoInc} PRIMARY KEY,
                site_id VARCHAR(32) NOT NULL DEFAULT '4',
                metric_name VARCHAR(20) NOT NULL,
                metric_value REAL NOT NULL DEFAULT 0.0,
                threshold_value REAL NOT NULL DEFAULT 0.0,
                severity VARCHAR(20) NOT NULL DEFAULT 'WARNING',
                status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
                message VARCHAR(255) NOT NULL,
                triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) {$engine};";
            $db->exec($sqlAlerts);

            // 5. System Sync & Heartbeat Status Table
            $sqlSync = "
            CREATE TABLE IF NOT EXISTS system_sync_status (
                id {$intInc} PRIMARY KEY,
                sync_key VARCHAR(64) UNIQUE,
                last_sync_timestamp BIGINT NOT NULL,
                last_sync_time DATETIME NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'OK',
                route_used VARCHAR(64) DEFAULT 'UNKNOWN',
                response_ms REAL DEFAULT 0.0,
                message TEXT DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) {$engine};";
            $db->exec($sqlSync);

            // 6. System Settings & Safety Thresholds Table
            $sqlSettings = "
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                setting_group VARCHAR(32) NOT NULL DEFAULT 'GENERAL',
                description VARCHAR(255) DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) {$engine};";
            $db->exec($sqlSettings);

            // 7. Users Table
            $sqlUsers = "
            CREATE TABLE IF NOT EXISTS users (
                id {$intInc} PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'OPERATOR',
                status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
                last_login DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) {$engine};";
            $db->exec($sqlUsers);

            // Indexes for fast querying
            if ($isMysql) {
                // Check and create MySQL indexes safely
                self::createMysqlIndexIfNotExists($db, 'telemetry_records', 'idx_telem_site_ts', 'site_id, server_timestamp DESC');
                self::createMysqlIndexIfNotExists($db, 'telemetry_records', 'idx_telem_server_time', 'server_time');
                self::createMysqlIndexIfNotExists($db, 'telemetry_45m_snapshots', 'idx_snap_site_ts', 'site_id, server_timestamp DESC');
                self::createMysqlIndexIfNotExists($db, 'iaq_alerts', 'idx_alert_status', 'status, triggered_at DESC');
            } else {
                // SQLite index creation
                $db->exec("CREATE INDEX IF NOT EXISTS idx_telem_site_ts ON telemetry_records (site_id, server_timestamp DESC);");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_telem_server_time ON telemetry_records (server_time);");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_snap_site_ts ON telemetry_45m_snapshots (site_id, server_timestamp DESC);");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_alert_status ON iaq_alerts (status, triggered_at DESC);");
            }

            // Views (Create or Replace)
            if ($isMysql) {
                $db->exec("CREATE OR REPLACE VIEW v_latest_telemetry AS
                    SELECT t.*, s.site_code, s.building, s.floor, s.room
                    FROM telemetry_records t
                    INNER JOIN sites s ON t.site_id = s.site_id
                    INNER JOIN (
                        SELECT site_id, MAX(id) AS max_id 
                        FROM telemetry_records 
                        GROUP BY site_id
                    ) latest ON t.id = latest.max_id;");
            }

            // Seed default site
            $stmt = $db->prepare("SELECT COUNT(*) FROM sites WHERE site_id = '4'");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() === 0) {
                $db->exec("INSERT INTO sites (site_id, site_code, site_name, building, floor, room, description, sensor_model, status)
                    VALUES ('4', 'ICT401', 'Site 4 - ICT401', 'ICT BRB', '4', '401', 'ห้องควบคุมและติดตามคุณภาพอากาศ ICT BRB ชั้น 4', 'Emtrontech AIIR v2', 'ACTIVE');");
            }

            // Seed default settings
            self::seedDefaultSettings();

            // Auto-migrate legacy JSON files if tables are empty
            self::autoMigrateLegacyData();
        } catch (Throwable $e) {
            error_log('[IAQDatabase initSchema Error] ' . $e->getMessage());
        }
    }

    /**
     * Helper to create index in MySQL safely if not existing
     */
    private static function createMysqlIndexIfNotExists(PDO $db, string $table, string $indexName, string $cols): void {
        try {
            $stmt = $db->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = :idx");
            $stmt->execute([':idx' => $indexName]);
            if ($stmt->fetch() === false) {
                $db->exec("CREATE INDEX `{$indexName}` ON `{$table}` ({$cols});");
            }
        } catch (Throwable $e) {
            // Non-fatal
        }
    }

    /**
     * Seed Default System Settings
     */
    public static function seedDefaultSettings(): void {
        $db = self::$pdo;
        if (!$db) return;

        $defaults = [
            'PM25_WARN_THRESHOLD'        => ['37.5', 'THRESHOLDS', 'เกณฑ์เตือนค่า PM2.5 (µg/m³)'],
            'PM25_DANGER_THRESHOLD'      => ['75.0', 'THRESHOLDS', 'เกณฑ์อันตรายค่า PM2.5 (µg/m³)'],
            'PM10_WARN_THRESHOLD'        => ['50.0', 'THRESHOLDS', 'เกณฑ์เตือนค่า PM10 (µg/m³)'],
            'CO2_WARN_THRESHOLD'         => ['1000.0', 'THRESHOLDS', 'เกณฑ์เตือนก๊าซคาร์บอนไดออกไซด์ (ppm)'],
            'CO2_DANGER_THRESHOLD'       => ['1500.0', 'THRESHOLDS', 'เกณฑ์อันตรายก๊าซคาร์บอนไดออกไซด์ (ppm)'],
            'TEMP_MIN_COMFORT'           => ['22.0', 'COMFORT', 'อุณหภูมิต่ำสุดที่รู้สึกสบาย (°C)'],
            'TEMP_MAX_COMFORT'           => ['26.0', 'COMFORT', 'อุณหภูมิสูงสุดที่รู้สึกสบาย (°C)'],
            'HUMID_MIN_COMFORT'          => ['40.0', 'COMFORT', 'ความชื้นสัมพัทธ์ต่ำสุด (%RH)'],
            'HUMID_MAX_COMFORT'          => ['65.0', 'COMFORT', 'ความชื้นสัมพัทธ์สูงสุด (%RH)'],
            'EVOC_WARN_THRESHOLD'        => ['50.0', 'THRESHOLDS', 'เกณฑ์เตือนสารระเหยง่าย (ppb)'],
            'AUTO_REFRESH_INTERVAL_SEC'  => ['30', 'SYSTEM', 'รอบเวลาดึงข้อมูลอัตโนมัติ (วินาที)'],
            'SNAPSHOT_INTERVAL_SEC'      => ['2700', 'SYSTEM', 'รอบบันทึกสแนปช็อตระยะยาว 45 นาที (วินาที)'],
        ];

        try {
            $isMysql = (self::$activeDriver === 'mysql');
            $sql = $isMysql
                ? "INSERT INTO system_settings (setting_key, setting_value, setting_group, description)
                   VALUES (:k, :v, :g, :d) ON DUPLICATE KEY UPDATE setting_group = VALUES(setting_group)"
                : "INSERT OR IGNORE INTO system_settings (setting_key, setting_value, setting_group, description)
                   VALUES (:k, :v, :g, :d)";

            $stmt = $db->prepare($sql);
            foreach ($defaults as $k => [$v, $g, $d]) {
                $stmt->execute([':k' => $k, ':v' => $v, ':g' => $g, ':d' => $d]);
            }
        } catch (Throwable $e) {
            // Silently ignore
        }
    }

    /**
     * Reset all telemetry, snapshot, and sync data (fresh start)
     */
    public static function resetAllData(): bool {
        $db = self::getConnection();
        if (!$db) return false;

        try {
            $db->exec("DELETE FROM telemetry_records;");
            $db->exec("DELETE FROM telemetry_45m_snapshots;");
            $db->exec("DELETE FROM system_sync_status;");
            $db->exec("DELETE FROM iaq_alerts;");
            if (self::$activeDriver === 'sqlite') {
                $db->exec("VACUUM;");
            }
            return true;
        } catch (Throwable $e) {
            error_log('[IAQDatabase resetAllData Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Auto-migrate legacy JSON files into MySQL / SQLite (only records from today onwards)
     */
    public static function autoMigrateLegacyData(): void {
        $db = self::$pdo;
        if (!$db) return;

        $todayStartTs = strtotime('today 00:00:00');

        try {
            // Check if 45m table is empty
            $stmt = $db->query("SELECT COUNT(*) as cnt FROM telemetry_45m_snapshots");
            $row = $stmt->fetch();
            $snapCount = (int)($row['cnt'] ?? 0);

            $file45 = __DIR__ . DIRECTORY_SEPARATOR . 'cache_45m_ict401.json';
            if ($snapCount === 0 && file_exists($file45)) {
                $raw = @file_get_contents($file45);
                $records = @json_decode($raw, true);
                if (is_array($records) && !empty($records)) {
                    $insertSql = "INSERT INTO telemetry_45m_snapshots
                        (site_id, site_name, pm25, pm10, co2, temp, humid, evoc, rssi, iaq_score, sensor_time, server_timestamp, server_time, label)
                        VALUES (:site_id, :site_name, :pm25, :pm10, :co2, :temp, :humid, :evoc, :rssi, :iaq_score, :sensor_time, :server_timestamp, :server_time, :label)";
                    $ins = $db->prepare($insertSql);

                    $db->beginTransaction();
                    $inserted = 0;
                    foreach ($records as $r) {
                        $ts = isset($r['timestamp_sec']) ? (int)$r['timestamp_sec'] : (int)(($r['timestamp'] ?? time()*1000) / 1000);
                        if ($ts < $todayStartTs) {
                            continue;
                        }
                        $serverTime = date('Y-m-d H:i:s', $ts);
                        $ins->execute([
                            ':site_id'          => '4',
                            ':site_name'        => $r['site'] ?? 'Site 4 - ICT401',
                            ':pm25'             => (float)($r['pm25'] ?? 0),
                            ':pm10'             => (float)($r['pm10'] ?? 0),
                            ':co2'              => (float)($r['co2'] ?? 0),
                            ':temp'             => (float)($r['temp'] ?? 0),
                            ':humid'            => (float)($r['humid'] ?? 0),
                            ':evoc'             => (float)($r['evoc'] ?? 0),
                            ':rssi'             => (string)($r['rssi'] ?? '0'),
                            ':iaq_score'        => (int)($r['iaqScore'] ?? 0),
                            ':sensor_time'      => (string)($r['time'] ?? $serverTime),
                            ':server_timestamp' => $ts,
                            ':server_time'      => $serverTime,
                            ':label'            => (string)($r['label'] ?? date('d/m H:i', $ts)),
                        ]);
                        $inserted++;
                    }
                    $db->commit();
                }
            }

            // Check if telemetry_records table is empty
            $stmt = $db->query("SELECT COUNT(*) as cnt FROM telemetry_records");
            $row = $stmt->fetch();
            $telemCount = (int)($row['cnt'] ?? 0);

            $fileHistory = __DIR__ . DIRECTORY_SEPARATOR . 'aiir_history_ict401.json';
            if ($telemCount === 0 && file_exists($fileHistory)) {
                $raw = @file_get_contents($fileHistory);
                $records = @json_decode($raw, true);
                if (is_array($records) && !empty($records)) {
                    $insertSql = "INSERT INTO telemetry_records
                        (site_id, site_name, pm25, pm10, co2, temp, humid, evoc, rssi, iaq_score, sensor_time, server_timestamp, server_time, route_used)
                        VALUES (:site_id, :site_name, :pm25, :pm10, :co2, :temp, :humid, :evoc, :rssi, :iaq_score, :sensor_time, :server_timestamp, :server_time, :route_used)";
                    $ins = $db->prepare($insertSql);

                    $db->beginTransaction();
                    $inserted = 0;
                    foreach ($records as $r) {
                        $ts = isset($r['timestamp_sec']) ? (int)$r['timestamp_sec'] : (int)(($r['timestamp'] ?? time()*1000) / 1000);
                        if ($ts < $todayStartTs) {
                            continue;
                        }
                        $serverTime = date('Y-m-d H:i:s', $ts);
                        $ins->execute([
                            ':site_id'          => '4',
                            ':site_name'        => 'Site 4 - ICT401',
                            ':pm25'             => (float)($r['pm25'] ?? 0),
                            ':pm10'             => (float)($r['pm10'] ?? 0),
                            ':co2'              => (float)($r['co2'] ?? 0),
                            ':temp'             => (float)($r['temp'] ?? 0),
                            ':humid'            => (float)($r['humid'] ?? 0),
                            ':evoc'             => (float)($r['evoc'] ?? 0),
                            ':rssi'             => (string)($r['rssi'] ?? '0'),
                            ':iaq_score'        => (int)($r['iaqScore'] ?? 0),
                            ':sensor_time'      => (string)($r['time'] ?? $serverTime),
                            ':server_timestamp' => $ts,
                            ':server_time'      => $serverTime,
                            ':route_used'       => 'MIGRATED',
                        ]);
                        $inserted++;
                    }
                    $db->commit();
                }
            }
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[IAQDatabase Migration Notice] ' . $e->getMessage());
        }
    }

    /**
     * Insert live telemetry reading into active database
     */
    public static function insertTelemetry(array $data, ?int $serverTs = null): bool {
        $db = self::getConnection();
        if (!$db) return false;

        $ts = $serverTs ?: time();
        $serverTime = date('Y-m-d H:i:s', $ts);

        $siteId = (string)($data['siteId'] ?? $data['site'] ?? '4');
        $siteName = (string)($data['siteName'] ?? 'Site 4 - ICT401');
        $pm25  = (float)($data['pm25'] ?? $data['PM2.5'] ?? 0);
        $pm10  = (float)($data['pm10'] ?? $data['PM10'] ?? 0);
        $co2   = (float)($data['co2']  ?? $data['CO2']  ?? 0);
        $temp  = (float)($data['temp'] ?? 0);
        $humid = (float)($data['humid'] ?? 0);
        $evoc  = (float)($data['evoc'] ?? 0);
        $rssi  = (string)($data['rssi'] ?? $data['RSSI'] ?? '0');
        $iaqScore = self::calculateIAQScore($pm25, $co2, $evoc, $temp, $humid);

        $sql = "INSERT INTO telemetry_records
            (site_id, site_name, pm25, pm10, co2, temp, humid, evoc, rssi, iaq_score, sensor_time, server_timestamp, server_time, route_used)
            VALUES (:site_id, :site_name, :pm25, :pm10, :co2, :temp, :humid, :evoc, :rssi, :iaq_score, :sensor_time, :server_timestamp, :server_time, :route_used)";

        try {
            $stmt = $db->prepare($sql);
            $res = $stmt->execute([
                ':site_id'          => $siteId,
                ':site_name'        => $siteName,
                ':pm25'             => $pm25,
                ':pm10'             => $pm10,
                ':co2'              => $co2,
                ':temp'             => $temp,
                ':humid'            => $humid,
                ':evoc'             => $evoc,
                ':rssi'             => $rssi,
                ':iaq_score'        => $iaqScore,
                ':sensor_time'      => (string)($data['lastUpdate'] ?? $serverTime),
                ':server_timestamp' => $ts,
                ':server_time'      => $serverTime,
                ':route_used'       => (string)($data['route'] ?? 'UNKNOWN'),
            ]);

            // Auto-check for safety threshold alerts
            self::checkAndLogThresholdAlerts($siteId, $pm25, $pm10, $co2, $temp, $humid, $evoc);

            return $res;
        } catch (Throwable $e) {
            error_log('[IAQDatabase insertTelemetry Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check interval and insert 45-minute snapshot
     */
    public static function insert45MinSnapshotIfNeeded(array $data, int $intervalSec = 2700, ?int $serverTs = null): bool {
        $db = self::getConnection();
        if (!$db) return false;

        $ts = $serverTs ?: time();
        $siteId = (string)($data['siteId'] ?? $data['site'] ?? '4');

        try {
            $stmt = $db->prepare("SELECT server_timestamp FROM telemetry_45m_snapshots WHERE site_id = :site_id ORDER BY server_timestamp DESC LIMIT 1");
            $stmt->execute([':site_id' => $siteId]);
            $last = $stmt->fetch();

            if ($last && ($ts - (int)$last['server_timestamp']) < $intervalSec) {
                return false;
            }

            $serverTime = date('Y-m-d H:i:s', $ts);
            $label = date('d/m H:i', $ts);
            $pm25  = (float)($data['pm25'] ?? $data['PM2.5'] ?? 0);
            $pm10  = (float)($data['pm10'] ?? $data['PM10'] ?? 0);
            $co2   = (float)($data['co2']  ?? $data['CO2']  ?? 0);
            $temp  = (float)($data['temp'] ?? 0);
            $humid = (float)($data['humid'] ?? 0);
            $evoc  = (float)($data['evoc'] ?? 0);
            $rssi  = (string)($data['rssi'] ?? $data['RSSI'] ?? '0');
            $iaqScore = self::calculateIAQScore($pm25, $co2, $evoc, $temp, $humid);

            $sql = "INSERT INTO telemetry_45m_snapshots
                (site_id, site_name, pm25, pm10, co2, temp, humid, evoc, rssi, iaq_score, sensor_time, server_timestamp, server_time, label)
                VALUES (:site_id, :site_name, :pm25, :pm10, :co2, :temp, :humid, :evoc, :rssi, :iaq_score, :sensor_time, :server_timestamp, :server_time, :label)";

            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':site_id'          => $siteId,
                ':site_name'        => (string)($data['siteName'] ?? 'Site 4 - ICT401'),
                ':pm25'             => $pm25,
                ':pm10'             => $pm10,
                ':co2'              => $co2,
                ':temp'             => $temp,
                ':humid'            => $humid,
                ':evoc'             => $evoc,
                ':rssi'             => $rssi,
                ':iaq_score'        => $iaqScore,
                ':sensor_time'      => (string)($data['lastUpdate'] ?? $serverTime),
                ':server_timestamp' => $ts,
                ':server_time'      => $serverTime,
                ':label'            => $label,
            ]);
        } catch (Throwable $e) {
            error_log('[IAQDatabase insert45MinSnapshot Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update system synchronization status (Fully supports MySQL & SQLite upsert)
     */
    public static function updateSyncStatus(string $key, string $status, string $route = '', float $ms = 0, string $msg = ''): void {
        $db = self::getConnection();
        if (!$db) return;

        $ts = time();
        $serverTime = date('Y-m-d H:i:s', $ts);

        $isMysql = (self::$activeDriver === 'mysql');

        if ($isMysql) {
            // MySQL / MariaDB standard UPSERT syntax
            $sql = "INSERT INTO system_sync_status (sync_key, last_sync_timestamp, last_sync_time, status, route_used, response_ms, message, updated_at)
                VALUES (:key, :ts, :stime, :status, :route, :ms, :msg, :stime)
                ON DUPLICATE KEY UPDATE
                    last_sync_timestamp = VALUES(last_sync_timestamp),
                    last_sync_time      = VALUES(last_sync_time),
                    status              = VALUES(status),
                    route_used          = VALUES(route_used),
                    response_ms         = VALUES(response_ms),
                    message             = VALUES(message),
                    updated_at          = VALUES(updated_at);";
        } else {
            // SQLite UPSERT syntax
            $sql = "INSERT INTO system_sync_status (sync_key, last_sync_timestamp, last_sync_time, status, route_used, response_ms, message, updated_at)
                VALUES (:key, :ts, :stime, :status, :route, :ms, :msg, :stime)
                ON CONFLICT(sync_key) DO UPDATE SET
                    last_sync_timestamp = excluded.last_sync_timestamp,
                    last_sync_time      = excluded.last_sync_time,
                    status              = excluded.status,
                    route_used          = excluded.route_used,
                    response_ms         = excluded.response_ms,
                    message             = excluded.message,
                    updated_at          = excluded.updated_at;";
        }

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':key'    => $key,
                ':ts'     => $ts,
                ':stime'  => $serverTime,
                ':status' => $status,
                ':route'  => $route,
                ':ms'     => $ms,
                ':msg'    => $msg,
            ]);
        } catch (Throwable $e) {
            error_log('[IAQDatabase updateSyncStatus Notice] ' . $e->getMessage());
        }
    }

    /**
     * Check environmental metrics against safety thresholds and log active alerts
     */
    private static function checkAndLogThresholdAlerts(string $siteId, float $pm25, float $pm10, float $co2, float $temp, float $humid, float $evoc): void {
        $db = self::$pdo;
        if (!$db) return;

        $checks = [
            ['PM25',  $pm25, 37.5, 75.0,  'µg/m³', 'ระดับฝุ่น PM2.5 เกินเกณฑ์มาตรฐาน'],
            ['CO2',   $co2,  1000.0, 1500.0, 'ppm',   'ระดับก๊าซคาร์บอนไดออกไซด์ CO2 เกินเกณฑ์สบาย'],
            ['TEMP',  $temp, 28.0, 32.0,   '°C',    'อุณหภูมิห้องสูงเกินเกณฑ์กำหนด'],
            ['HUMID', $humid, 65.0, 75.0,  '%RH',   'ความชื้นสัมพัทธ์สูง เสี่ยงต่อการสะสมของเชื้อรา'],
            ['EVOC',  $evoc, 50.0, 100.0,  'ppb',   'สารระเหยอินทรีย์ง่าย VOC เกินเกณฑ์ปลอดภัย'],
        ];

        try {
            foreach ($checks as [$metric, $val, $warnLimit, $dangerLimit, $unit, $desc]) {
                if ($val >= $dangerLimit) {
                    self::recordAlert($db, $siteId, $metric, $val, $dangerLimit, 'DANGER', "{$desc} ({$val} {$unit} / เกณฑ์อันตราย {$dangerLimit})");
                } elseif ($val >= $warnLimit) {
                    self::recordAlert($db, $siteId, $metric, $val, $warnLimit, 'WARNING', "{$desc} ({$val} {$unit} / เกณฑ์เตือน {$warnLimit})");
                } else {
                    // Auto-resolve any active alert for this metric
                    self::autoResolveAlert($db, $siteId, $metric);
                }
            }
        } catch (Throwable $e) {
            // Non-fatal
        }
    }

    /**
     * Record new alert if no active alert exists within the last 10 minutes
     */
    private static function recordAlert(PDO $db, string $siteId, string $metric, float $val, float $threshold, string $severity, string $msg): void {
        $tenMinAgo = date('Y-m-d H:i:s', time() - 600);
        $check = $db->prepare("SELECT id FROM iaq_alerts WHERE site_id = :site AND metric_name = :metric AND status = 'ACTIVE' AND triggered_at >= :t LIMIT 1");
        $check->execute([':site' => $siteId, ':metric' => $metric, ':t' => $tenMinAgo]);
        if ($check->fetch()) {
            return; // Already logged recently
        }

        $ins = $db->prepare("INSERT INTO iaq_alerts (site_id, metric_name, metric_value, threshold_value, severity, status, message, triggered_at)
            VALUES (:site, :metric, :val, :thresh, :sev, 'ACTIVE', :msg, :t)");
        $ins->execute([
            ':site'   => $siteId,
            ':metric' => $metric,
            ':val'    => $val,
            ':thresh' => $threshold,
            ':sev'    => $severity,
            ':msg'    => $msg,
            ':t'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Auto-resolve active alert when reading returns to normal
     */
    private static function autoResolveAlert(PDO $db, string $siteId, string $metric): void {
        $now = date('Y-m-d H:i:s');
        $upd = $db->prepare("UPDATE iaq_alerts SET status = 'RESOLVED', resolved_at = :now WHERE site_id = :site AND metric_name = :metric AND status = 'ACTIVE'");
        $upd->execute([':now' => $now, ':site' => $siteId, ':metric' => $metric]);
    }

    /**
     * Get recent telemetry history for dashboard
     */
    public static function getRecentHistory(int $limit = 50, string $siteId = '4'): array {
        $db = self::getConnection();
        if (!$db) return [];

        try {
            $stmt = $db->prepare("SELECT * FROM telemetry_records WHERE site_id = :site_id ORDER BY server_timestamp DESC LIMIT :limit");
            $stmt->bindValue(':site_id', $siteId, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $rows = array_reverse($rows);

            $result = [];
            foreach ($rows as $r) {
                $ts = (int)$r['server_timestamp'];
                $result[] = [
                    'timestamp_sec' => $ts,
                    'timestamp'     => $ts * 1000,
                    'label'         => date('H:i:s', $ts),
                    'time'          => $r['sensor_time'] ?: $r['server_time'],
                    'site'          => $r['site_name'],
                    'pm25'          => (float)$r['pm25'],
                    'pm10'          => (float)$r['pm10'],
                    'co2'           => (float)$r['co2'],
                    'temp'          => (float)$r['temp'],
                    'humid'         => (float)$r['humid'],
                    'evoc'          => (float)$r['evoc'],
                    'rssi'          => (string)$r['rssi'],
                    'iaqScore'      => (int)$r['iaq_score'],
                ];
            }
            return $result;
        } catch (Throwable $e) {
            error_log('[IAQDatabase getRecentHistory Error] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get 45-minute snapshots for long-term trends
     */
    public static function get45MinSnapshots(int $limit = 500, string $siteId = '4'): array {
        $db = self::getConnection();
        if (!$db) return [];

        try {
            $stmt = $db->prepare("SELECT * FROM telemetry_45m_snapshots WHERE site_id = :site_id ORDER BY server_timestamp DESC LIMIT :limit");
            $stmt->bindValue(':site_id', $siteId, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $rows = array_reverse($rows);

            $result = [];
            foreach ($rows as $r) {
                $ts = (int)$r['server_timestamp'];
                $result[] = [
                    'timestamp_sec' => $ts,
                    'timestamp'     => $ts * 1000,
                    'label'         => $r['label'] ?: date('d/m H:i', $ts),
                    'time'          => $r['sensor_time'] ?: $r['server_time'],
                    'site'          => $r['site_name'],
                    'pm25'          => (float)$r['pm25'],
                    'pm10'          => (float)$r['pm10'],
                    'co2'           => (float)$r['co2'],
                    'temp'          => (float)$r['temp'],
                    'humid'         => (float)$r['humid'],
                    'evoc'          => (float)$r['evoc'],
                    'rssi'          => (string)$r['rssi'],
                    'iaqScore'      => (int)$r['iaq_score'],
                ];
            }
            return $result;
        } catch (Throwable $e) {
            error_log('[IAQDatabase get45MinSnapshots Error] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get aggregate statistics (Min, Max, Avg) for a time range
     */
    public static function getStatistics(string $range = '24h', string $siteId = '4'): array {
        $db = self::getConnection();
        if (!$db) return [];

        $now = time();
        $seconds = match ($range) {
            '1h'  => 3600,
            '6h'  => 21600,
            '24h' => 86400,
            '7d'  => 604800,
            '30d' => 2592000,
            default => 86400,
        };
        $startTs = $now - $seconds;

        try {
            $sql = "SELECT 
                COUNT(*) as total_samples,
                MIN(pm25) as min_pm25, MAX(pm25) as max_pm25, ROUND(AVG(pm25), 1) as avg_pm25,
                MIN(pm10) as min_pm10, MAX(pm10) as max_pm10, ROUND(AVG(pm10), 1) as avg_pm10,
                MIN(co2) as min_co2,   MAX(co2) as max_co2,   ROUND(AVG(co2), 1) as avg_co2,
                MIN(temp) as min_temp, MAX(temp) as max_temp, ROUND(AVG(temp), 1) as avg_temp,
                MIN(humid) as min_humid, MAX(humid) as max_humid, ROUND(AVG(humid), 1) as avg_humid,
                MIN(evoc) as min_evoc, MAX(evoc) as max_evoc, ROUND(AVG(evoc), 1) as avg_evoc,
                MIN(iaq_score) as min_score, MAX(iaq_score) as max_score, ROUND(AVG(iaq_score), 0) as avg_score
            FROM telemetry_records 
            WHERE site_id = :site_id AND server_timestamp >= :start_ts";

            $stmt = $db->prepare($sql);
            $stmt->execute([':site_id' => $siteId, ':start_ts' => $startTs]);
            $stats = $stmt->fetch() ?: [];
            $stats['range'] = $range;
            $stats['rangeSeconds'] = $seconds;
            $stats['siteId'] = $siteId;
            return $stats;
        } catch (Throwable $e) {
            error_log('[IAQDatabase getStatistics Error] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Query telemetry records for a custom or preset time range
     */
    public static function getRecordsByRange(?int $startTs = null, ?int $endTs = null, string $siteId = '4', int $limit = 2000): array {
        $db = self::getConnection();
        if (!$db) return [];

        $endTs = $endTs ?: time();

        try {
            $params = [':site_id' => $siteId, ':end_ts' => $endTs];
            $whereClause = "site_id = :site_id AND server_timestamp <= :end_ts";

            if ($startTs !== null && $startTs > 0) {
                $whereClause .= " AND server_timestamp >= :start_ts";
                $params[':start_ts'] = $startTs;
            }

            $sql = "SELECT * FROM telemetry_records WHERE {$whereClause} ORDER BY server_timestamp ASC LIMIT :limit";
            $stmt = $db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                $sqlSnap = "SELECT * FROM telemetry_45m_snapshots WHERE {$whereClause} ORDER BY server_timestamp ASC LIMIT :limit";
                $stmtSnap = $db->prepare($sqlSnap);
                foreach ($params as $key => $val) {
                    $stmtSnap->bindValue($key, $val);
                }
                $stmtSnap->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmtSnap->execute();
                $rows = $stmtSnap->fetchAll();
            }

            $isSingleDay = ($startTs !== null && ($endTs - $startTs) <= 86400);

            $result = [];
            foreach ($rows as $r) {
                $ts = (int)$r['server_timestamp'];
                $defaultLabel = $isSingleDay ? date('H:i', $ts) : date('d/m H:i', $ts);
                $result[] = [
                    'id'            => (int)$r['id'],
                    'timestamp_sec' => $ts,
                    'timestamp'     => $ts * 1000,
                    'label'         => isset($r['label']) && !empty($r['label']) && !$isSingleDay ? $r['label'] : $defaultLabel,
                    'timeLabel'     => date('H:i:s', $ts),
                    'dateLabel'     => date('d/m/Y', $ts),
                    'time'          => $r['sensor_time'] ?: $r['server_time'],
                    'server_time'   => $r['server_time'],
                    'site'          => $r['site_name'],
                    'pm25'          => (float)$r['pm25'],
                    'pm10'          => (float)$r['pm10'],
                    'co2'           => (float)$r['co2'],
                    'temp'          => (float)$r['temp'],
                    'humid'         => (float)$r['humid'],
                    'evoc'          => (float)$r['evoc'],
                    'rssi'          => (string)$r['rssi'],
                    'iaqScore'      => (int)$r['iaq_score'],
                ];
            }
            return $result;
        } catch (Throwable $e) {
            error_log('[IAQDatabase getRecordsByRange Error] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Calculate statistics for a specific time range
     */
    public static function getStatisticsForRange(?int $startTs = null, ?int $endTs = null, string $siteId = '4'): array {
        $db = self::getConnection();
        if (!$db) return [];

        $endTs = $endTs ?: time();

        try {
            $params = [':site_id' => $siteId, ':end_ts' => $endTs];
            $whereClause = "site_id = :site_id AND server_timestamp <= :end_ts";

            if ($startTs !== null && $startTs > 0) {
                $whereClause .= " AND server_timestamp >= :start_ts";
                $params[':start_ts'] = $startTs;
            }

            $sql = "SELECT 
                COUNT(*) as total_samples,
                MIN(pm25) as min_pm25, MAX(pm25) as max_pm25, ROUND(AVG(pm25), 1) as avg_pm25,
                MIN(pm10) as min_pm10, MAX(pm10) as max_pm10, ROUND(AVG(pm10), 1) as avg_pm10,
                MIN(co2) as min_co2,   MAX(co2) as max_co2,   ROUND(AVG(co2), 1) as avg_co2,
                MIN(temp) as min_temp, MAX(temp) as max_temp, ROUND(AVG(temp), 1) as avg_temp,
                MIN(humid) as min_humid, MAX(humid) as max_humid, ROUND(AVG(humid), 1) as avg_humid,
                MIN(evoc) as min_evoc, MAX(evoc) as max_evoc, ROUND(AVG(evoc), 1) as avg_evoc,
                MIN(iaq_score) as min_score, MAX(iaq_score) as max_score, ROUND(AVG(iaq_score), 0) as avg_score
            FROM telemetry_records 
            WHERE {$whereClause}";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetch() ?: [];

            if (empty($stats['total_samples']) || (int)$stats['total_samples'] === 0) {
                $sqlSnap = str_replace('telemetry_records', 'telemetry_45m_snapshots', $sql);
                $stmtSnap = $db->prepare($sqlSnap);
                $stmtSnap->execute($params);
                $stats = $stmtSnap->fetch() ?: [];
            }

            $stats['startTs'] = $startTs;
            $stats['endTs']   = $endTs;
            $stats['siteId']  = $siteId;
            return $stats;
        } catch (Throwable $e) {
            error_log('[IAQDatabase getStatisticsForRange Error] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get active incident alerts
     */
    public static function getActiveAlerts(string $siteId = '4', int $limit = 20): array {
        $db = self::getConnection();
        if (!$db) return [];

        try {
            $stmt = $db->prepare("SELECT * FROM iaq_alerts WHERE site_id = :site AND status = 'ACTIVE' ORDER BY triggered_at DESC LIMIT :lim");
            $stmt->bindValue(':site', $siteId, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Acknowledge all active alerts for a site
     */
    public static function acknowledgeAllAlerts(string $siteId = '4'): bool {
        $db = self::getConnection();
        if (!$db) return false;
        try {
            $stmt = $db->prepare("UPDATE iaq_alerts SET status = 'ACKNOWLEDGED' WHERE site_id = :site AND status = 'ACTIVE'");
            return $stmt->execute([':site' => $siteId]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Get all monitored sites
     */
    public static function getAllSites(): array {
        $db = self::getConnection();
        if (!$db) return [];

        try {
            return $db->query("SELECT * FROM sites ORDER BY site_id ASC")->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Database diagnostics information
     */
    public static function getDiagnostics(): array {
        $db = self::getConnection();
        $isOk = ($db !== null);

        $tables = [
            'sites'                   => 0,
            'telemetry_records'       => 0,
            'telemetry_45m_snapshots' => 0,
            'iaq_alerts'              => 0,
            'system_sync_status'      => 0,
            'system_settings'         => 0,
            'users'                   => 0,
        ];

        if ($isOk) {
            foreach ($tables as $t => &$cnt) {
                try {
                    $cnt = (int)$db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                } catch (Throwable $e) {
                    $cnt = -1; // table might not exist
                }
            }
        }

        $fileSize = 0;
        if (self::$activeDriver === 'sqlite' && file_exists(DB_SQLITE_FILE)) {
            $fileSize = filesize(DB_SQLITE_FILE);
        }

        return [
            'ok'                   => $isOk,
            'activeDriver'         => self::$activeDriver,
            'configuredDriver'     => DB_TYPE,
            'mysqlHost'            => DB_MYSQL_HOST,
            'mysqlPort'            => DB_MYSQL_PORT,
            'mysqlDatabase'        => DB_MYSQL_NAME,
            'mysqlUser'            => DB_MYSQL_USER,
            'connectionError'      => self::$lastConnectionError,
            'tableCounts'          => $tables,
            'telemetryCount'       => $tables['telemetry_records'] >= 0 ? $tables['telemetry_records'] : 0,
            'snapshotCount'        => $tables['telemetry_45m_snapshots'] >= 0 ? $tables['telemetry_45m_snapshots'] : 0,
            'sqliteFile'           => DB_SQLITE_FILE,
            'sqliteFileSize'       => $fileSize,
            'serverTime'           => date('Y-m-d H:i:s'),
            'serverTimeSec'        => time(),
            'timezone'             => date_default_timezone_get(),
        ];
    }

    /**
     * Helper to calculate standard AI IAQ Score (0-100%)
     */
    private static function calculateIAQScore(float $pm25, float $co2, float $evoc, float $temp, float $humid): int {
        $pm25Pen = min(35, max(0, ($pm25 / 50) * 35));
        $co2Pen  = min(35, max(0, (($co2 - 400) / 1200) * 35));
        $evocPen = min(15, max(0, ($evoc / 50) * 15));
        $tempPen = ($temp < 20 || $temp > 28) ? min(10, max(0, abs($temp - 24) * 2)) : 0;
        $humPen  = ($humid < 40 || $humid > 65) ? min(15, max(0, abs($humid - 50) * 0.3)) : 0;
        return (int)max(10, round(100 - ($pm25Pen + $co2Pen + $evocPen + $tempPen + $humPen)));
    }
}
