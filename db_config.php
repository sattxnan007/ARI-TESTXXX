<?php
/**
 * db_config.php — Centralized Database Configuration for AIR IAQ Dashboard
 * 
 * Supports both MySQL (Target Enterprise DB: air_quality_db) and SQLite (Local Fallback).
 * Configuration can be adjusted here directly or overridden via System Environment Variables.
 */

declare(strict_types=1);

// 1. Database Driver: 'mysql' (Enterprise Default) or 'sqlite'
if (!defined('DB_TYPE')) {
    define('DB_TYPE', getenv('DB_TYPE') ?: 'mysql');
}

// 2. MySQL / MariaDB Server Configuration
if (!defined('DB_MYSQL_HOST')) {
    define('DB_MYSQL_HOST', getenv('DB_HOST') ?: '10.7.1.95');
}

if (!defined('DB_MYSQL_PORT')) {
    define('DB_MYSQL_PORT', (int)(getenv('DB_PORT') ?: 3306));
}

if (!defined('DB_MYSQL_NAME')) {
    define('DB_MYSQL_NAME', getenv('DB_NAME') ?: 'air_quality_db');
}

if (!defined('DB_MYSQL_USER')) {
    define('DB_MYSQL_USER', getenv('DB_USER') ?: 'root');
}

if (!defined('DB_MYSQL_PASS')) {
    define('DB_MYSQL_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'Dev@1234');
}

// 3. SQLite Fallback Configuration (Zero-config local safety net)
if (!defined('DB_SQLITE_DIR')) {
    define('DB_SQLITE_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'data');
}

if (!defined('DB_SQLITE_FILE')) {
    define('DB_SQLITE_FILE', DB_SQLITE_DIR . DIRECTORY_SEPARATOR . 'iaq_database.sqlite');
}

// 4. Timezone & General Settings
if (!defined('DB_TIMEZONE')) {
    define('DB_TIMEZONE', 'Asia/Bangkok');
}

date_default_timezone_set(DB_TIMEZONE);
