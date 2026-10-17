<?php
// Copy to config.local.php to override defaults. Never commit credentials.
return [
    'dsn' => 'sqlite:' . __DIR__ . '/var/monitor.sqlite',
    'db_user' => null,
    'db_password' => null,
];
