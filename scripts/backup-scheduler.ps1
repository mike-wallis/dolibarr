<#
.SYNOPSIS
    Registers the Dolibarr backup script as a Windows Scheduled Task.
    Run once as Administrator. Re-run to update the schedule.

.USAGE
    Right-click PowerShell → Run as Administrator, then:
    powershell -ExecutionPolicy Bypass -File scripts\backup-scheduler.ps1
#>

$ScriptPath = 'C:\wamp64\www\dolibarr\scripts\backup.ps1'
$TaskName   = 'DolibarrBackup'

$action = New-ScheduledTaskAction `
    -Execute 'powershell.exe' `
    -Argument "-NonInteractive -ExecutionPolicy Bypass -File `"$ScriptPath`""

# Run daily at 7am AND at startup (catches days the laptop was off at 7am)
$triggers = @(
    $(New-ScheduledTaskTrigger -Daily -At '07:00'),
    $(New-ScheduledTaskTrigger -AtStartup)
)

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName   $TaskName `
    -Action     $action `
    -Trigger    $triggers `
    -Settings   $settings `
    -RunLevel   Highest `
    -Force

Write-Host "Task '$TaskName' registered. Run it now to verify:"
Write-Host "  Start-ScheduledTask -TaskName '$TaskName'"
