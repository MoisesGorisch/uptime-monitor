<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
requireNetwork();
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') { http_response_code(403); echo json_encode(['error'=>'Origem não permitida.']); exit; }
authSession();
requireAuthentication();
try {
    $db=db();
    if ($_SERVER['REQUEST_METHOD']==='GET') { echo json_encode(snapshot($db)+['csrf'=>$_SESSION['csrf']], JSON_THROW_ON_ERROR); exit; }
    if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: GET, POST'); http_response_code(405); exit; }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $expectedOrigin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    if ($origin !== null && $origin !== $expectedOrigin) { http_response_code(403); echo json_encode(['error'=>'Origem não permitida.']); exit; }
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') { http_response_code(415); echo json_encode(['error'=>'Envie os dados como JSON.']); exit; }
    if (!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); echo json_encode(['error'=>'Atualize a página e tente novamente.']); exit; }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($raw)>8192) { http_response_code(413); echo json_encode(['error'=>'Solicitação muito grande.']); exit; }
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Envie um objeto JSON válido.');
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
