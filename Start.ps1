param([string]$PhpPath = '', [ValidateRange(1024,65535)][int]$Port = 8787)
$ErrorActionPreference = 'Stop'
if (-not $PhpPath) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if (Test-Path (Join-Path $PSScriptRoot '.runtime\php\php.exe')) { $PhpPath = Join-Path $PSScriptRoot '.runtime\php\php.exe' }
    elseif ($phpCommand) { $PhpPath = $phpCommand.Source }
    elseif (Test-Path 'C:\SFTP\xampp\php\php.exe') { $PhpPath = 'C:\SFTP\xampp\php\php.exe' }
    else { throw 'PHP 8.2+ is required. Pass -PhpPath with the php.exe location.' }
}
$extensionPath = Join-Path (Split-Path $PhpPath) 'ext'
$sessionPath = Join-Path $PSScriptRoot 'var\sessions'
New-Item -ItemType Directory -Path $sessionPath -Force | Out-Null
$errorLog = Join-Path $PSScriptRoot 'var\php.log'
$phpOptions = @('-n', '-d', "extension_dir=$extensionPath", '-d', 'extension=pdo_sqlite', '-d', 'extension=pdo_mysql', '-d', 'extension=curl', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$errorLog", '-d', 'expose_php=0', '-d', "session.save_path=$sessionPath")
$worker = Start-Job -ArgumentList $PhpPath,$extensionPath,$PSScriptRoot -ScriptBlock {
    param($phpExe,$extensions,$project)
    while ($true) {
        & $phpExe -n -d "extension_dir=$extensions" -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=curl -d display_errors=0 -d log_errors=1 -d "error_log=$(Join-Path $project 'var\php.log')" (Join-Path $project 'bin/check.php') | Out-Null
        Start-Sleep -Seconds 15
    }
}
Write-Host "Pulse: http://127.0.0.1:$Port  | Demo: http://127.0.0.1:$Port/?demo=1"
Write-Host 'Automatic checks enabled. Keep this window open. Press Ctrl+C to stop.'
try { & $PhpPath @phpOptions -S "127.0.0.1:$Port" -t (Join-Path $PSScriptRoot 'public') (Join-Path $PSScriptRoot 'router.php') }
finally { Stop-Job $worker; Remove-Job $worker }
