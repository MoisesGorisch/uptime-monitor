<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
requireNetwork();
authSession();
try {
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        echo json_encode(['configured'=>(bool)authAccount(),'authenticated'=>authenticated(),'csrf'=>$_SESSION['csrf'],'can_setup'=>in_array($_SERVER['REMOTE_ADDR'],['127.0.0.1','::1'],true)]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: GET, POST'); http_response_code(405); exit; }
    $input=jsonInput();
    switch ($input['action'] ?? '') {
        case 'setup': createAccount($input); break;
        case 'login':
            if (!authAccount()) throw new DomainException('Configure o primeiro acesso no computador servidor.', 409);
            loginAccount($input); break;
        case 'logout':
            $_SESSION=[];
            session_regenerate_id(true);
            $_SESSION['csrf']=bin2hex(random_bytes(24));
            break;
        default: throw new InvalidArgumentException('Ação inválida.');
    }
    echo json_encode(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
} catch (DomainException $e) {
    http_response_code($e->getCode());
    if ($e->getCode()===429) header('Retry-After: 900');
    echo json_encode(['error'=>pulseMessage($e instanceof JsonException ? 'Envie um objeto JSON válido.' : $e->getMessage())]);
} catch (InvalidArgumentException|JsonException $e) { http_response_code(422); echo json_encode(['error'=>pulseMessage($e instanceof JsonException ? 'Envie um objeto JSON válido.' : $e->getMessage())]); }
catch (Throwable $e) { error_log(get_class($e).': authentication failure'); http_response_code(500); echo json_encode(['error'=>pulseMessage('Não foi possível acessar a autenticação. Verifique a configuração do servidor.')]); }
