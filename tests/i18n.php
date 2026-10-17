<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/i18n.php';
$catalog=json_decode(file_get_contents(dirname(__DIR__).'/src/locales.json'),true,512,JSON_THROW_ON_ERROR);
$languages=['pt-BR','en','es','ja','zh-CN','ru'];
$count=0;
foreach ($catalog as $source=>$translations) {
    foreach ($languages as $language) {
        if (!isset($translations[$language]) || !is_string($translations[$language]) || trim($translations[$language])==='') throw new RuntimeException("Missing translation: $language / $source");
        if (substr_count($source,'{code}')!==substr_count($translations[$language],'{code}')) throw new RuntimeException('Lost code placeholder');
        $_SERVER['HTTP_X_PULSE_LANGUAGE']=$language;
        if (pulseMessage($source)!==$translations[$language]) throw new RuntimeException('Incorrect selected language');
        $count++;
    }
}
foreach (['unknown','../../config.local.php','EN',''] as $language) {
    $_SERVER['HTTP_X_PULSE_LANGUAGE']=$language;
    if (pulseMessage('Ação inválida.')!=='Invalid action.') throw new RuntimeException('Unsafe language fallback');
    $count++;
}
unset($_SERVER['HTTP_X_PULSE_LANGUAGE']);
if(pulseMessage('Ação inválida.')!=='Invalid action.') throw new RuntimeException('Default must be English');
if(pulseMessage('My custom monitor')!=='My custom monitor') throw new RuntimeException('User data must be preserved');
echo ($count+2)." localization assertions passed.\n";
