<#
.SYNOPSIS
    Dolibarr LIVE site backup:
      1. Database — PHP PDO dump (MySQL 9.1 client can't connect to live server)
      2. conf.php  — cPanel UAPI GET
      3. data/     — FTP recursive mirror to OneDrive\Dolibarr_backups\live\data-mirror\
                     (sync: only downloads files that are new or have changed size)

.USAGE
    Run manually:   powershell -ExecutionPolicy Bypass -File scripts\backup-live.ps1
    Automated:      run scripts\backup-scheduler-live.ps1 as Administrator (once)
#>

$ErrorActionPreference = 'Stop'

# ── Config ────────────────────────────────────────────────────────────────────
$DolibarrRoot = 'C:\wamp64\www\dolibarr'
$BackupRoot   = 'C:\Users\mhwal\OneDrive\Dolibarr_backups\live'
$DataMirror   = "$BackupRoot\data-mirror"
$KeepDays     = 14
$LogFile      = "$DolibarrRoot\scripts\backup-live.log"

# ── Cert bypass (cPanel shared IP uses mismatched cert) ───────────────────────
Add-Type @"
using System.Net; using System.Security.Cryptography.X509Certificates;
public class LiveBackupTrust : ICertificatePolicy {
    public bool CheckValidationResult(ServicePoint sp, X509Certificate cert, WebRequest req, int err) { return true; }
}
"@ -ErrorAction SilentlyContinue
[System.Net.ServicePointManager]::CertificatePolicy = New-Object LiveBackupTrust

# ── Load .env ─────────────────────────────────────────────────────────────────
$envVars = @{}
Get-Content "$DolibarrRoot\.env" | ForEach-Object {
    if ($_ -match '^\s*([A-Z_]+)\s*=\s*(.+)$') { $envVars[$Matches[1]] = $Matches[2].Trim() }
}
$cPanelUrl   = $envVars['LIVE_CPANEL_URL']
$cPanelUser  = $envVars['LIVE_CPANEL_USER']
$cPanelToken = $envVars['LIVE_CPANEL_TOKEN']
$dbHost      = $envVars['LIVE_DB_HOST']
$dbName      = $envVars['LIVE_DB_NAME']
$dbUser      = $envVars['LIVE_DB_USER']
$dbPass      = $envVars['LIVE_DB_PASS']
$ftpServer   = $envVars['LIVE_FTP_SERVER']
$ftpUser     = $envVars['LIVE_FTP_USER']
$ftpPass     = $envVars['LIVE_FTP_PASS']
$cHeaders    = @{ Authorization = "cpanel ${cPanelUser}:${cPanelToken}" }

# ── Helpers ───────────────────────────────────────────────────────────────────
function Log($msg) {
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $msg"
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
    Write-Host $line
}
function FmtMB($bytes) { "$([Math]::Round($bytes / 1MB, 2)) MB" }

# ── FTP mirror via curl.exe (FTPS explicit TLS, -k bypasses shared-IP cert) ───
$Curl = "$env:SystemRoot\System32\curl.exe"

function Get-FtpListing($ftpUri, $user, $pass) {
    # Trailing slash triggers LIST on a directory
    $lines = & $Curl --ftp-ssl-reqd -k "${ftpUri}/" -u "${user}:${pass}" `
                 --connect-timeout 20 --silent 2>&1
    return $lines -split "`n" | ForEach-Object { $_.TrimEnd("`r") } | Where-Object { $_ -match '\S' }
}

function Sync-FtpDir($ftpUri, $localDir, $user, $pass, [ref]$dl, [ref]$skip) {
    New-Item -ItemType Directory -Path $localDir -Force | Out-Null
    $lines = Get-FtpListing $ftpUri $user $pass
    foreach ($line in $lines) {
        # Unix LIST: drwxr-xr-x 2 user group size month day time/year name
        if ($line -notmatch '^([\-d])\S+\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+\S+\s+(.+)$') { continue }
        $isDir = $Matches[1] -eq 'd'
        $size  = [long]$Matches[2]
        $name  = $Matches[3].Trim()
        if ($name -in '.', '..') { continue }

        $encodedName = [System.Uri]::EscapeDataString($name)
        $childUri    = "$ftpUri/$encodedName"
        $childPath   = Join-Path $localDir $name

        if ($isDir) {
            Sync-FtpDir $childUri $childPath $user $pass $dl $skip
        } else {
            $existing = if (Test-Path $childPath) { (Get-Item $childPath).Length } else { -1 }
            if ($existing -eq $size) {
                $skip.Value++
            } else {
                New-Item -ItemType Directory -Path (Split-Path $childPath) -Force | Out-Null
                & $Curl --ftp-ssl-reqd -k $childUri -u "${user}:${pass}" `
                    -o $childPath --silent --connect-timeout 60 2>&1 | Out-Null
                if ($LASTEXITCODE -ne 0) { throw "curl download failed (exit $LASTEXITCODE): $childUri" }
                $dl.Value++
            }
        }
    }
}

# ── Setup ─────────────────────────────────────────────────────────────────────
New-Item -ItemType Directory -Path $BackupRoot -Force | Out-Null
$stamp     = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$backupDir = "$BackupRoot\$stamp"
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

Log ''
Log "=== Dolibarr LIVE backup started: $stamp ==="

try {

    # ── 1. Database via PHP PDO ───────────────────────────────────────────────
    Log '--- Database (PHP PDO dump)'
    $sqlFile    = "$backupDir\dolibarr_live.sql"
    $sqlFileFwd = $sqlFile -replace '\\', '/'

    $phpTemplate = @'
<?php
$pdo = new PDO('mysql:host=##HOST##;dbname=##DB##;charset=utf8mb4', '##USER##', '##PASS##');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$out = fopen('##FILE##', 'w');
fwrite($out, "-- Dolibarr live DB dump\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n");
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
    fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n" . $row[1] . ";\n\n");
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
        foreach ($rows as $r) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $r);
            fwrite($out, "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(', ', $vals) . ");\n");
        }
        fwrite($out, "\n");
    }
}
fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($out);
echo "OK";
?>
'@
    $phpScript = $phpTemplate `
        -replace '##HOST##', $dbHost `
        -replace '##DB##',   $dbName `
        -replace '##USER##', $dbUser `
        -replace '##PASS##', $dbPass `
        -replace '##FILE##', $sqlFileFwd

    $phpFile = [System.IO.Path]::GetTempFileName() + '.php'
    Set-Content -Path $phpFile -Value $phpScript -Encoding UTF8
    $result = & php $phpFile 2>&1
    Remove-Item $phpFile -Force

    if ($result -ne 'OK') { throw "PHP PDO dump failed: $result" }
    $sqlSize = (Get-Item $sqlFile).Length
    if ($sqlSize -lt 10240) { throw "Dump too small ($sqlSize bytes) - likely failed" }
    Log "  Dump written: $(FmtMB $sqlSize)"

    Compress-Archive -Path $sqlFile -DestinationPath "$sqlFile.zip" -CompressionLevel Optimal
    Remove-Item $sqlFile
    Log "  Compressed:   $(FmtMB (Get-Item "$sqlFile.zip").Length)"

    # ── 2. conf.php via cPanel API ────────────────────────────────────────────
    Log '--- Config (conf.php)'
    $confUrl = "$cPanelUrl/execute/Fileman/get_file_content?dir=%2Ferp_dolibarr%2Fpublic_html%2Fconf&file=conf.php"
    $confR   = Invoke-RestMethod -Uri $confUrl -Headers $cHeaders -TimeoutSec 30
    if ($confR.status -ne 1) { throw "conf.php retrieval failed: $($confR.errors)" }
    Set-Content -Path "$backupDir\conf.php" -Value $confR.data.content -Encoding UTF8
    Log "  conf.php saved ($($confR.data.content.Length) chars)"

    # ── 3. data/ folder via FTP mirror ───────────────────────────────────────
    Log "--- Data folder (FTP mirror -> $DataMirror)"
    $ftpBase   = "ftp://$ftpServer/data"
    $dlCount   = [ref]0
    $skipCount = [ref]0
    Sync-FtpDir $ftpBase $DataMirror $ftpUser $ftpPass $dlCount $skipCount
    $mirrorSize = (Get-ChildItem $DataMirror -Recurse -File | Measure-Object Length -Sum).Sum
    Log "  Downloaded: $($dlCount.Value) file(s)  Skipped (unchanged): $($skipCount.Value)  Mirror total: $(FmtMB $mirrorSize)"

    # ── 4. Prune old dated backups (DB + conf.php folders only) ──────────────
    Log '--- Pruning dated backups'
    $cutoff = (Get-Date).AddDays(-$KeepDays)
    $pruned = 0
    Get-ChildItem -Path $BackupRoot -Directory |
        Where-Object { $_.Name -match '^\d{4}-\d{2}-\d{2}_' -and $_.CreationTime -lt $cutoff } |
        ForEach-Object {
            Remove-Item -Path $_.FullName -Recurse -Force
            Log "  Deleted: $($_.Name)"
            $pruned++
        }
    if ($pruned -eq 0) { Log '  Nothing to prune' }

    Log "=== Live backup complete: $stamp ==="
    Log "    DB+conf: $backupDir"
    Log "    Files:   $DataMirror (mirror, always current)"

} catch {
    Log "ERROR: $_"
    Log '=== Live backup FAILED ==='
    exit 1
}
