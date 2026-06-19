<#
.SYNOPSIS
    Registers a Windows Task Scheduler job that runs backup-live.ps1 daily at 7am.
    Must be run once from an elevated (Administrator) PowerShell window.

.USAGE
    Right-click PowerShell > "Run as Administrator", then:
    powershell -ExecutionPolicy Bypass -File C:\wamp64\www\dolibarr\scripts\backup-scheduler-live.ps1

    To remove:
    Unregister-ScheduledTask -TaskName 'DolibarrLiveBackup' -Confirm:$false
#>

$scriptPath = 'C:\wamp64\www\dolibarr\scripts\backup-live.ps1'
$action     = New-ScheduledTaskAction `
    -Execute 'powershell.exe' `
    -Argument "-ExecutionPolicy Bypass -NonInteractive -File `"$scriptPath`""

$triggers = @(
    $(New-ScheduledTaskTrigger -Daily -At 7am),
    $(New-ScheduledTaskTrigger -AtStartup)
)

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

Register-ScheduledTask `
    -TaskName 'DolibarrLiveBackup' `
    -Description 'Daily live Dolibarr DB + conf.php backup to OneDrive' `
    -Action $action `
    -Trigger $triggers `
    -Settings $settings `
    -RunLevel Highest `
    -Force

Write-Host 'DolibarrLiveBackup task registered. Next run: tomorrow 7am (or at next startup).'
Write-Host 'To run now: Start-ScheduledTask -TaskName DolibarrLiveBackup'
Write-Host 'To remove:  Unregister-ScheduledTask -TaskName DolibarrLiveBackup -Confirm:$false'
