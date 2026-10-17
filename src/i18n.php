<?php
declare(strict_types=1);
function pulseMessage(string $source): string {
    static $messages = null;
    $messages ??= json_decode(file_get_contents(__DIR__.'/locales.json'), true, 512, JSON_THROW_ON_ERROR);
    $language = $_SERVER['HTTP_X_PULSE_LANGUAGE'] ?? 'en';
    if (!in_array($language, ['en','pt-BR','es','ja','zh-CN','ru'], true)) $language = 'en';
    return $messages[$source][$language] ?? $source;
}
