<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$lock=fopen(dirname(__DIR__).'/var/worker.lock','c');
if (!flock($lock,LOCK_EX|LOCK_NB)) exit("Another check is running.\n");
try {
    $db=db();
    foreach (dueMonitors($db) as $m) { $result=checkUrl($m); recordCheck($db,(int)$m['id'],$result); echo $m['name'].': '.$result['status'].PHP_EOL; }
    $cutoff=time()-90*86400; $db->prepare('DELETE FROM checks WHERE checked_at < ?')->execute([$cutoff]);
} finally { flock($lock,LOCK_UN); fclose($lock); }
