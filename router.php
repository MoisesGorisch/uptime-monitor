<?php
declare(strict_types=1);
// Router for PHP's local development server. Production needs equivalent web-server rules.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
require __DIR__.'/src/auth.php';
requireNetwork();
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store');
switch ($path) {
    case '/': case '/index.html':
        header('Content-Type: text/html; charset=utf-8');
        if (($_GET['demo'] ?? '') !== '1' && !authenticated()) {
            header('Location: /login'); http_response_code(302); break;
        }
        readfile(__DIR__.'/public/index.html'); break;
    case '/login':
        header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__.'/public/login.html'); break;
    case '/auth.php': require __DIR__.'/public/auth.php'; break;
    case '/login.js':
        header('Content-Type: application/javascript; charset=utf-8'); readfile(__DIR__.'/public/login.js'); break;
    case '/style.css':
        header('Content-Type: text/css; charset=utf-8'); readfile(__DIR__.'/public/style.css'); break;
    case '/app.js':
        header('Content-Type: application/javascript; charset=utf-8'); readfile(__DIR__.'/public/app.js'); break;
    case '/messages.js':
        header('Content-Type: application/javascript; charset=utf-8'); echo 'window.PULSE_MESSAGES = '.file_get_contents(__DIR__.'/src/locales.json').';'; break;
    case '/i18n.js':
        header('Content-Type: application/javascript; charset=utf-8'); readfile(__DIR__.'/public/i18n.js'); break;
    case '/theme.js':
        header('Content-Type: application/javascript; charset=utf-8'); readfile(__DIR__.'/public/theme.js'); break;
    case '/api.php': require __DIR__.'/public/api.php'; break;
    default: http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found';
}
return true;
