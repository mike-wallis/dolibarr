<#
.SYNOPSIS
    Dolibarr local backup — database dump, documents folder, and conf.php.
    Stored in OneDrive for off-device redundancy. Prunes backups older than $KeepDays.

.USAGE
    Run manually:   powershell -ExecutionPolicy Bypass -File scripts\backup.ps1
    Automated via Windows Task Scheduler — see scripts\backup-scheduler.ps1 to register.
#>

$ErrorActionPreference = 'Stop'

# ── Config ────────────────────────────────────────────────────────────────────
$DolibarrRoot = 'C:\wamp64\www\dolibarr'
$BackupRoot   = 'C:\Users\mhwal\OneDrive\Dolibarr_backups'
$MysqlDump    = 'C:\wamp64\bin\mysql\mysql9.1.0\bin\mysqldump.exe'
$KeepDays     = 14
$LogFile      = "$DolibarrRoot\scripts\backup.log"

# ── Helpers ───────────────────────────────────────────────────────────────────
function Log($msg) {
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $msg"
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
    Write-Host $line
}

function FmtMB($bytes) {
    "$([Math]::Round($bytes / 1MB, 1)) MB"
}

# ── Load credentials from .env ────────────────────────────────────────────────
$envVars = @{}
Get-Content "$DolibarrRoot\.env" | ForEach-Object {
    if ($_ -match '^\s*([A-Z_]+)\s*=\s*(.+)$') {
        $envVars[$Matches[1]] = $Matches[2].Trim()
    }
}
$DbHost = $envVars['DB_HOST']
$DbName = $envVars['DB_NAME']
$DbUser = $envVars['DB_USER']
$DbPass = $envVars['DB_PASS']

# ── Create timestamped backup folder ─────────────────────────────────────────
$stamp     = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$backupDir = "$BackupRoot\$stamp"
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

Log ''
Log "=== Dolibarr backup started: $stamp ==="

try {

    # ── 1. Database dump ──────────────────────────────────────────────────────
    Log '--- Database'
    $sqlFile = "$backupDir\dolibarr.sql"

    # Write credentials to a temp options file — keeps password off the process command line
    $optFile = [System.IO.Path]::GetTempFileName()
    Set-Content -Path $optFile -Encoding ASCII -Value @"
[client]
host=$DbHost
user=$DbUser
password=$DbPass
"@
    try {
        & $MysqlDump "--defaults-extra-file=$optFile" --single-transaction --no-tablespaces --routines --triggers --result-file=$sqlFile $DbName
        if ($LASTEXITCODE -ne 0) { throw "mysqldump exited with code $LASTEXITCODE" }
    } finally {
        Remove-Item $optFile -Force
    }

    $sqlSize = (Get-Item $sqlFile).Length
    Log "  Dump written: $(FmtMB $sqlSize)"

    Compress-Archive -Path $sqlFile -DestinationPath "$sqlFile.zip" -CompressionLevel Optimal
    Remove-Item $sqlFile
    $zipSize = (Get-Item "$sqlFile.zip").Length
    Log "  Compressed:   $(FmtMB $zipSize)"

    # ── 2. Documents folder ───────────────────────────────────────────────────
    Log '--- Documents'
    $docsSource = "$DolibarrRoot\documents"
    if (Test-Path $docsSource) {
        $docsBytes = (Get-ChildItem $docsSource -Recurse -File | Measure-Object -Property Length -Sum).Sum
        Copy-Item -Path $docsSource -Destination "$backupDir\documents" -Recurse
        Log "  Copied: $(FmtMB $docsBytes)"
    } else {
        Log "  Skipped (folder not found: $docsSource)"
    }

    # ── 3. Config ─────────────────────────────────────────────────────────────
    Log '--- Config'
    $confFile = "$DolibarrRoot\htdocs\conf\conf.php"
    if (Test-Path $confFile) {
        Copy-Item -Path $confFile -Destination "$backupDir\conf.php"
        Log '  conf.php copied'
    } else {
        Log '  Skipped (conf.php not found)'
    }

    # ── 4. Prune old backups ──────────────────────────────────────────────────
    Log '--- Pruning'
    $cutoff = (Get-Date).AddDays(-$KeepDays)
    $pruned = 0
    Get-ChildItem -Path $BackupRoot -Directory | Where-Object { $_.CreationTime -lt $cutoff } | ForEach-Object {
        Remove-Item -Path $_.FullName -Recurse -Force
        Log "  Deleted: $($_.Name)"
        $pruned++
    }
    if ($pruned -eq 0) { Log '  Nothing to prune' }

    Log "=== Backup complete: $backupDir ==="

} catch {
    Log "ERROR: $_"
    Log "=== Backup FAILED ==="
    exit 1
}
