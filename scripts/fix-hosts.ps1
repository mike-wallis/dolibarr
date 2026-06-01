$hosts = "C:\Windows\System32\drivers\etc\hosts"
$lines = Get-Content $hosts
$fixed = $lines | Where-Object { $_ -notmatch '::1.*dolibarr' }
Set-Content $hosts $fixed
Write-Host "Done. dolibarr.test IPv6 entry removed."
