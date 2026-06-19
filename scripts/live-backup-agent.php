<?php
/**
 * Dolibarr live backup agent.
 * Deployed to erp_dolibarr/public_html/ on the live server.
 * Protected by a secret token stored in .env (LIVE_BACKUP_TOKEN).
 * Called by scripts/backup-live.ps1 — do not expose or link to this file.
 *
 * Usage: https://erp.southsidesupplies.com.au/dol_backup_agent.php?token=TOKEN
 */

// ── Auth — token read from .env one level above public_html ──────────────────
// .env lives at ~/erp_dolibarr/.env; this script is at ~/erp_dolibarr/public_html/
$envToken = null;
$envPath  = dirname(__DIR__) . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*LIVE_BACKUP_TOKEN\s*=\s*(.+)$/', $line, $m)) {
            $envToken = trim($m[1]);
            break;
        }
    }
}

if (!$envToken || ($_GET['token'] ?? '') !== $envToken) {
    http_response_code(403);
    exit('Forbidden');
}

// ── DB credentials (read from Dolibarr conf) ──────────────────────────────────
$confFile = __DIR__ . '/../public_html/htdocs/conf/conf.php';
if (!file_exists($confFile)) {
    // Fallback: conf.php is in the same public_html
    $confFile = __DIR__ . '/htdocs/conf/conf.php';
}
require_once $confFile;

$dbhost = $dolibarr_main_db_host ?? 'localhost';
$dbname = $dolibarr_main_db_name;
$dbuser = $dolibarr_main_db_user;
$dbpass = $dolibarr_main_db_pass;

// ── Dump ──────────────────────────────────────────────────────────────────────
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="dolibarr_live.sql"');

// Try exec-based mysqldump first (fastest, most reliable)
if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
    $passFile = tempnam(sys_get_temp_dir(), 'dmp');
    file_put_contents($passFile, "[client]\nhost={$dbhost}\nuser={$dbuser}\npassword={$dbpass}\n");
    $cmd = "mysqldump --defaults-extra-file=" . escapeshellarg($passFile)
         . " --single-transaction --no-tablespaces --routines --triggers "
         . escapeshellarg($dbname);
    passthru($cmd);
    unlink($passFile);
    exit;
}

// Fallback: PDO-based dump (works when exec() is disabled)
$pdo = new PDO("mysql:host={$dbhost};dbname={$dbname};charset=utf8mb4", $dbuser, $dbpass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "-- Dolibarr live DB dump (PDO fallback)\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    // Schema
    $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
    echo "DROP TABLE IF EXISTS `{$table}`;\n" . $row[1] . ";\n\n";

    // Data
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
        foreach ($rows as $r) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $r);
            echo "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(', ', $vals) . ");\n";
        }
        echo "\n";
    }
}

echo "SET FOREIGN_KEY_CHECKS=1;\n";
