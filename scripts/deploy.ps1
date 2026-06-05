# deploy.ps1 — copy custom source files into the local Dolibarr htdocs installation
# Run from repo root: .\scripts\deploy.ps1
# Works for the local WAMP dev environment (c:\wamp64\www\dolibarr\htdocs)

param(
    [string]$HtdocsDir = ""
)

$RepoRoot = Split-Path $PSScriptRoot -Parent

if ($HtdocsDir -eq "") {
    $HtdocsDir = Join-Path $RepoRoot "htdocs"
}

if (-not (Test-Path $HtdocsDir)) {
    Write-Error "htdocs not found at: $HtdocsDir`nPass -HtdocsDir <path> to specify a different location."
    exit 1
}

function Deploy-File($src, $dest) {
    $destDir = Split-Path $dest -Parent
    if (-not (Test-Path $destDir)) {
        New-Item -ItemType Directory -Force $destDir | Out-Null
    }
    Copy-Item -Path $src -Destination $dest -Force
    $rel = $src.Replace($RepoRoot + "\", "")
    Write-Host "  $rel" -ForegroundColor Cyan
}

Write-Host ""
Write-Host "Deploying custom files to: $HtdocsDir" -ForegroundColor Green
Write-Host ""

# ── Help pages ────────────────────────────────────────────────────────────────
Write-Host "Help pages:" -ForegroundColor Yellow
$destHelp = Join-Path $HtdocsDir "custom\help"
New-Item -ItemType Directory -Force $destHelp | Out-Null
Get-ChildItem (Join-Path $RepoRoot "custom\help\*.php") | ForEach-Object {
    Deploy-File $_.FullName (Join-Path $destHelp $_.Name)
}

# ── Invoice PDF templates ─────────────────────────────────────────────────────
Write-Host "Invoice templates:" -ForegroundColor Yellow
Get-ChildItem (Join-Path $RepoRoot "custom\core\modules\facture\doc\*.php") | ForEach-Object {
    Deploy-File $_.FullName (Join-Path $HtdocsDir "core\modules\facture\doc\$($_.Name)")
}

# ── Supplier order (PO) PDF templates ────────────────────────────────────────
Write-Host "Purchase order templates:" -ForegroundColor Yellow
Get-ChildItem (Join-Path $RepoRoot "custom\core\modules\supplier_order\doc\*.php") | ForEach-Object {
    Deploy-File $_.FullName (Join-Path $HtdocsDir "core\modules\supplier_order\doc\$($_.Name)")
}

# ── Quote / Proposal PDF templates ───────────────────────────────────────────
Write-Host "Quote templates:" -ForegroundColor Yellow
Get-ChildItem (Join-Path $RepoRoot "custom\core\modules\propale\doc\*.php") | ForEach-Object {
    Deploy-File $_.FullName (Join-Path $HtdocsDir "core\modules\propale\doc\$($_.Name)")
}

# ── Custom modules ───────────────────────────────────────────────────────────
Write-Host "Custom modules:" -ForegroundColor Yellow
$modulesPath = Join-Path $RepoRoot "custom\modules"
if (Test-Path $modulesPath) {
    Get-ChildItem $modulesPath -Directory | ForEach-Object {
        $modName = $_.Name
        $modDest = Join-Path $HtdocsDir "custom\$modName"
        New-Item -ItemType Directory -Force $modDest | Out-Null
        Copy-Item -Path "$($_.FullName)\*" -Destination $modDest -Recurse -Force
        Write-Host "  modules\$modName\" -ForegroundColor Cyan
    }
}

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host ""
