param([string]$PhpPath='')
$ErrorActionPreference='Stop'
$project=Split-Path $PSScriptRoot
if(-not $PhpPath){$PhpPath=Join-Path $project '.runtime\php\php.exe';if(-not(Test-Path $PhpPath)){$PhpPath=(Get-Command php).Source}}
$fixture=Join-Path $project ('var\http-test-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixture -Force | Out-Null
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$listener.Start();$port=$listener.LocalEndpoint.Port;$listener.Stop()
$baseUrl="http://127.0.0.1:$port";$oldAuth=$env:PULSE_AUTH_DIR;$oldLan=$env:PULSE_ALLOW_LAN
$env:PULSE_AUTH_DIR=$fixture;$env:PULSE_ALLOW_LAN='0';$server=$null
$extensions=Join-Path (Split-Path $PhpPath) 'ext'
$arguments=@('-n','-d',('extension_dir="'+$extensions+'"'),'-d','extension=pdo_sqlite','-d','extension=curl','-d',('session.save_path="'+$fixture+'"'),'-S',"127.0.0.1:$port",'-t',('"'+(Join-Path $project 'public')+'"'),('"'+(Join-Path $project 'router.php')+'"'))
function Verify($label,$actual,$expected){if($actual -ne $expected){throw "$label returned $actual instead of $expected"};Write-Output "PASS: $label ($actual)"}
function Send-Auth($session,$token,$body){Invoke-WebRequest "$baseUrl/auth.php" -WebSession $session -Method Post -ContentType application/json -Headers @{'X-CSRF-Token'=$token;Origin=$baseUrl} -Body ($body|ConvertTo-Json -Compress) -SkipHttpErrorCheck}
try {
    $server=Start-Process $PhpPath -ArgumentList $arguments -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixture 'stdout.log') -RedirectStandardError (Join-Path $fixture 'stderr.log')
    $session=New-Object Microsoft.PowerShell.Commands.WebRequestSession;$ready=$false
    for($i=0;$i -lt 30;$i++){try{$initial=Invoke-WebRequest "$baseUrl/auth.php" -WebSession $session -TimeoutSec 2;$ready=$true;break}catch{Start-Sleep -Milliseconds 100}}
    if(-not $ready){throw 'Test server did not start'}
    $state=$initial.Content|ConvertFrom-Json;$token=$state.csrf;if($state.configured){throw 'Expected isolated empty auth database'}
    Verify 'Anonymous API blocked' (Invoke-WebRequest "$baseUrl/api.php" -SkipHttpErrorCheck).StatusCode 401
    $catalog=Get-Content (Join-Path $project 'src/locales.json') -Raw | ConvertFrom-Json -AsHashtable
    foreach($language in @('en','pt-BR','es','ja','zh-CN','ru')) {
        $localized=Invoke-WebRequest "$baseUrl/api.php" -Headers @{'X-Pulse-Language'=$language} -SkipHttpErrorCheck
        Verify "Localized API status $language" $localized.StatusCode 401
        if(($localized.Content | ConvertFrom-Json).error -cne $catalog['Entre na sua conta para acessar o painel.'][$language]){throw "Wrong API language $language"}
    }
    Verify 'Localization script' (Invoke-WebRequest "$baseUrl/i18n.js").StatusCode 200
    Verify 'Translation catalog' (Invoke-WebRequest "$baseUrl/messages.js").StatusCode 200
    Verify 'Private translation source' (Invoke-WebRequest "$baseUrl/src/locales.json" -SkipHttpErrorCheck).StatusCode 404
    Verify 'Fictional demo available' (Invoke-WebRequest "$baseUrl/?demo=1").StatusCode 200
    $password=[guid]::NewGuid().ToString('N')+[guid]::NewGuid().ToString('N')
    Verify 'Weak password rejected' (Send-Auth $session $token @{action='setup';username='test-admin';password='short'}).StatusCode 422
    Verify 'Initial setup' (Send-Auth $session $token @{action='setup';username='test-admin';password=$password}).StatusCode 200
    Verify 'Setup cannot overwrite account' (Send-Auth $session $token @{action='setup';username='test-admin';password=$password}).StatusCode 409
    Verify 'Wrong password' (Send-Auth $session $token @{action='login';username='test-admin';password='incorrect'}).StatusCode 401
    $oldId=$session.Cookies.GetCookies([uri]$baseUrl)['PULSESESSID'].Value
    $login=Send-Auth $session $token @{action='login';username='test-admin';password=$password};Verify 'Login' $login.StatusCode 200
    if($oldId -eq $session.Cookies.GetCookies([uri]$baseUrl)['PULSESESSID'].Value){throw 'Session ID did not rotate'};Write-Output 'PASS: Session rotation'
    $token=($login.Content|ConvertFrom-Json).csrf
    Verify 'Authenticated API' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session).StatusCode 200
    Verify 'Authenticated dashboard' (Invoke-WebRequest $baseUrl -WebSession $session).StatusCode 200
    Verify 'CSRF' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType application/json -Body '{}' -SkipHttpErrorCheck).StatusCode 403
    Verify 'Origin' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType application/json -Headers @{'X-CSRF-Token'=$token;Origin='http://other.example'} -Body '{}' -SkipHttpErrorCheck).StatusCode 403
    $headers=@{'X-CSRF-Token'=$token;Origin=$baseUrl}
    Verify 'Content type' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType text/plain -Headers $headers -Body '{}' -SkipHttpErrorCheck).StatusCode 415
    Verify 'JSON shape' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType application/json -Headers $headers -Body '[]' -SkipHttpErrorCheck).StatusCode 422
    Verify 'Body limit' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType application/json -Headers $headers -Body (' '*8193) -SkipHttpErrorCheck).StatusCode 413
    foreach($path in @('/config.local.php','/var/auth.sqlite','/src/auth.php','/.runtime/php/php.exe')){Verify "Private route $path" (Invoke-WebRequest ($baseUrl+$path) -SkipHttpErrorCheck).StatusCode 404}
    $page=Invoke-WebRequest "$baseUrl/login";foreach($header in @('Content-Security-Policy','X-Content-Type-Options','X-Frame-Options','Referrer-Policy','Permissions-Policy')){if(-not $page.Headers.ContainsKey($header)){throw "Missing $header"};Write-Output "PASS: $header"}
    Verify 'Logout' (Send-Auth $session $token @{action='logout'}).StatusCode 200
    Verify 'API after logout' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -SkipHttpErrorCheck).StatusCode 401
    $fresh=New-Object Microsoft.PowerShell.Commands.WebRequestSession;$token=((Invoke-WebRequest "$baseUrl/auth.php" -WebSession $fresh).Content|ConvertFrom-Json).csrf
    for($i=0;$i -lt 10;$i++){Verify 'Failed login counted' (Send-Auth $fresh $token @{action='login';username='test-admin';password='incorrect'}).StatusCode 401}
    $fresh=New-Object Microsoft.PowerShell.Commands.WebRequestSession;$token=((Invoke-WebRequest "$baseUrl/auth.php" -WebSession $fresh).Content|ConvertFrom-Json).csrf
    Verify 'New session cannot bypass limit' (Send-Auth $fresh $token @{action='login';username='test-admin';password=$password}).StatusCode 429
} finally {
    if($server -and -not $server.HasExited){Stop-Process -Id $server.Id;$server.WaitForExit()}
    $env:PULSE_AUTH_DIR=$oldAuth;$env:PULSE_ALLOW_LAN=$oldLan
    $resolved=[IO.Path]::GetFullPath($fixture);$allowed=[IO.Path]::GetFullPath((Join-Path $project 'var'))+[IO.Path]::DirectorySeparatorChar
    if($resolved.StartsWith($allowed,[StringComparison]::OrdinalIgnoreCase)){Remove-Item -LiteralPath $resolved -Recurse -Force}
}
