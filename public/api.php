<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// This MVP is a local administration app. A public deployment needs authentication.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) || !preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $_SERVER['HTTP_HOST'] ?? '')) { http_response_code(403); echo json_encode(['error'=>'Administração disponível apenas no computador local.']); exit; }
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict']); session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
try {
    $db=db();
    if ($_SERVER['REQUEST_METHOD']==='GET') { echo json_encode(snapshot($db)+['csrf'=>$_SESSION['csrf']], JSON_THROW_ON_ERROR); exit; }
    if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit; }
    if (!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); echo json_encode(['error'=>'Atualize a página e tente novamente.']); exit; }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>8192) throw new InvalidArgumentException('Solicitação muito grande.');
    $input=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
    if (($input['action'] ?? '')==='create') {
        if ((int)$db->query('SELECT COUNT(*) FROM monitors')->fetchColumn()>=20) throw new InvalidArgumentException('Limite desta versão: 20 monitores.');
        $m=validateMonitor($input); $db->prepare('INSERT INTO monitors (name,url,interval_seconds,slow_ms,expected_text) VALUES (?,?,?,?,?)')->execute(array_values($m));
    } elseif (($input['action'] ?? '')==='check') {
        $q=$db->prepare('SELECT * FROM monitors WHERE id=?'); $q->execute([(int)($input['id'] ?? 0)]); $m=$q->fetch();
        if (!$m) throw new InvalidArgumentException('Monitor não encontrado.');
        if ($m['last_checked'] && time()-(int)$m['last_checked']<15) throw new InvalidArgumentException('Aguarde 15 segundos entre verificações manuais.');
        // Shared lock prevents the CLI worker and manual checks from overlapping.
        $lock=fopen(dirname(__DIR__).'/var/worker.lock','c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) throw new InvalidArgumentException('Há uma verificação em andamento. Tente novamente.');
        try { recordCheck($db,(int)$m['id'],checkUrl($m)); } finally { flock($lock,LOCK_UN); fclose($lock); }
    } else throw new InvalidArgumentException('Ação inválida.');
    echo json_encode(['ok'=>true]);
} catch (InvalidArgumentException | JsonException $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()]); }
catch (Throwable $e) { error_log((string)$e); http_response_code(500); echo json_encode(['error'=>'Não foi possível concluir. Verifique o banco de dados e as extensões PHP.']); }
