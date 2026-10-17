param([string]$BaseUrl='http://127.0.0.1:8787')
$ErrorActionPreference = 'Stop'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$response = Invoke-WebRequest "$baseUrl/api.php" -WebSession $session
$token = ($response.Content | ConvertFrom-Json).csrf
function Check-Status($label, $actual, $expected) {
    if ($actual -ne $expected) { throw "$label returned $actual instead of $expected" }
    Write-Output "PASS: $label ($actual)"
}
Check-Status 'Local API read' $response.StatusCode 200
Check-Status 'CSRF rejection' (Invoke-WebRequest "$baseUrl/api.php" -Method Post -ContentType application/json -Body '{"action":"create"}' -SkipHttpErrorCheck).StatusCode 403
$headers=@{'X-CSRF-Token'=$token; Origin='http://other.example'}
Check-Status 'Foreign origin rejection' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType application/json -Headers $headers -Body '{"action":"create"}' -SkipHttpErrorCheck).StatusCode 403
$headers=@{'X-CSRF-Token'=$token; Origin=$baseUrl}
Check-Status 'Content type rejection' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType text/plain -Headers $headers -Body '{}' -SkipHttpErrorCheck).StatusCode 415
Check-Status 'JSON list rejection' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType application/json -Headers $headers -Body '[]' -SkipHttpErrorCheck).StatusCode 422
Check-Status 'Oversized body rejection' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType application/json -Headers $headers -Body (' ' * 8193) -SkipHttpErrorCheck).StatusCode 413
Check-Status 'Invalid text field rejection' (Invoke-WebRequest "$baseUrl/api.php" -WebSession $session -Method Post -ContentType application/json -Headers $headers -Body '{"action":"create","name":[],"url":"https://example.com"}' -SkipHttpErrorCheck).StatusCode 422
Check-Status 'Cross-site fetch rejection' (Invoke-WebRequest "$baseUrl/api.php" -Headers @{'Sec-Fetch-Site'='cross-site'} -SkipHttpErrorCheck).StatusCode 403
foreach($path in @('/config.local.php','/.runtime/php/php.exe','/src/bootstrap.php','/var/monitor.sqlite','/README.md')) {
    Check-Status "Private route $path" (Invoke-WebRequest ($baseUrl+$path) -SkipHttpErrorCheck).StatusCode 404
}
$page=Invoke-WebRequest $baseUrl
foreach($header in @('Content-Security-Policy','X-Content-Type-Options','X-Frame-Options','Referrer-Policy','Permissions-Policy')) {
    if(-not $page.Headers.ContainsKey($header)){ throw "Missing $header" }
    Write-Output "PASS: $header"
}
if($page.Headers.ContainsKey('X-Powered-By')) { throw 'PHP version exposed' }
Write-Output 'PASS: PHP version header suppressed'
