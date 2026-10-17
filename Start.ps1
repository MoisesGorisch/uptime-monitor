param([string]$PhpPath = '', [int]$Port = 8787)
$ErrorActionPreference = 'Stop'
if (-not $PhpPath) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { $PhpPath = $phpCommand.Source }
    elseif (Test-Path 'C:\SFTP\xampp\php\php.exe') { $PhpPath = 'C:\SFTP\xampp\php\php.exe' }
    else { throw 'PHP 8.2+ is required. Pass -PhpPath with the php.exe location.' }
}
$extensionPath = Join-Path (Split-Path $PhpPath) 'ext'
$phpOptions = @('-n', '-d', "extension_dir=$extensionPath", '-d', 'extension=pdo_sqlite', '-d', 'extension=pdo_mysql', '-d', 'extension=curl')
$worker = Start-Job -ArgumentList $PhpPath,$extensionPath,$PSScriptRoot -ScriptBlock {
    param($phpExe,$extensions,$project)
    while ($true) {
        & $phpExe -n -d "extension_dir=$extensions" -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=curl (Join-Path $project 'bin/check.php')
        Start-Sleep -Seconds 15
    }
}
Write-Host "Pulse: http://127.0.0.1:$Port  | Demo: http://127.0.0.1:$Port/?demo=1"
Write-Host 'Automatic checks enabled. Keep this window open. Press Ctrl+C to stop.'
try { & $PhpPath @phpOptions -S "127.0.0.1:$Port" -t (Join-Path $PSScriptRoot 'public') }
finally { Stop-Job $worker; Remove-Job $worker }
