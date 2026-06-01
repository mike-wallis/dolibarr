$lines = @(
    "127.0.0.1 localhost",
    "::1 localhost",
    "127.0.0.1`tsouthsidesupplies",
    "::1`tsouthsidesupplies",
    "127.0.0.1`tdolibarr.test"
)
Set-Content "C:\Windows\System32\drivers\etc\hosts" -Value $lines -Encoding ASCII
Write-Host "Done. Hosts file restored."
Get-Content "C:\Windows\System32\drivers\etc\hosts"
