<#
  Registers the Windows Task Scheduler entry that wakes Laravel's scheduler
  once a minute. Without it, the 02:30 nightly database backup in
  routes/console.php is a command nobody ever calls.

  Run once, from an elevated PowerShell, from the project root:

      powershell -ExecutionPolicy Bypass -File deploy\windows\install-scheduler-task.ps1

  Check it took:   php artisan schedule:list      (what is registered)
                   Get-ScheduledTask MuseoBaler*  (that Windows will call it)
  Remove it:       Unregister-ScheduledTask -TaskName "MuseoBaler Scheduler"
#>
param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path,
    [string]$Php = (Get-Command php -ErrorAction SilentlyContinue).Source
)

if (-not $Php) {
    # Laragon does not put php on PATH for non-Laragon shells.
    $Php = Get-ChildItem "C:\laragon\bin\php\*\php.exe" -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $Php) { throw "php.exe not found. Pass -Php with the full path to php.exe." }

$taskName = "MuseoBaler Scheduler"
$action   = New-ScheduledTaskAction -Execute $Php -Argument "artisan schedule:run" -WorkingDirectory $ProjectRoot

# Every minute, indefinitely. The scheduler itself decides what is due;
# most minutes it does nothing and exits at once.
$trigger  = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 1)

$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
    -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -Hidden

# Runs whether or not anyone is signed in, as SYSTEM, so the 02:30 backup
# happens on a locked machine.
$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null

Write-Host "Registered '$taskName':"
Write-Host "  $Php artisan schedule:run"
Write-Host "  in $ProjectRoot, every minute, as SYSTEM."
Write-Host ""
Write-Host "Test it now:  php artisan db:backup"
Write-Host "Then check:   Get-ChildItem storage\app\backups"
