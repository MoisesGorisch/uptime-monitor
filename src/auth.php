<?php
declare(strict_types=1);
require_once __DIR__.'/i18n.php';

function authDirectory(): string
{
    return getenv('PULSE_AUTH_DIR') ?: dirname(__DIR__) . '/var';
}

function authDb(): PDO
{
    static $db;
    if ($db) return $db;
    $directory = authDirectory();
    if (!is_dir($directory)) throw new RuntimeException('Authentication directory does not exist.');
    $db = new PDO('sqlite:' . $directory . '/auth.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS account (id INTEGER PRIMARY KEY CHECK(id=1), username TEXT NOT NULL, password_hash TEXT NOT NULL, version TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS failures (id INTEGER PRIMARY KEY, address TEXT NOT NULL, attempted_at INTEGER NOT NULL)');
    return $db;
}

function authAccount(): array|false
{
    return authDb()->query('SELECT * FROM account WHERE id=1')->fetch();
}

function privateLanIpv4(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $n = ip2long($ip);
    return ($n & 0xff000000) === ip2long('10.0.0.0')
        || ($n & 0xfff00000) === ip2long('172.16.0.0')
        || ($n & 0xffff0000) === ip2long('192.168.0.0');
}

function networkAllowed(string $remote, string $host, bool $lan): bool
{
    $loopback = in_array($remote, ['127.0.0.1', '::1'], true);
    $localHost = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:[0-9]{1,5})?$/i', $host);
    if ($loopback && $localHost) return true;
    if (!$lan || (!$loopback && !privateLanIpv4($remote))) return false;
    // Accept private IPv4 literals, never arbitrary DNS names or forwarded headers.
    return (bool)preg_match('/^([0-9.]+)(:[0-9]{1,5})?$/', $host, $matches) && privateLanIpv4($matches[1]);
}

function requireNetwork(): void
{
    if (!networkAllowed($_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_HOST'] ?? '', getenv('PULSE_ALLOW_LAN') === '1')) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>pulseMessage('Acesso de rede não permitido. O padrão é somente este computador.')]);
        exit;
    }
}

function authSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('display_errors', '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_name('PULSESESSID');
    session_set_cookie_params(['httponly'=>true, 'samesite'=>'Strict', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path'=>'/']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}

function authenticated(): bool
{
    authSession();
    if (!isset($_SESSION['auth'])) return false;
    $auth = $_SESSION['auth'];
    $account = authAccount();
    if (!$account || !hash_equals($account['version'], $auth['version'])
        || time() - $auth['last_activity'] > 1800 || time() - $auth['created'] > 28800) {
        unset($_SESSION['auth']);
        return false;
    }
    return true;
}

function requireAuthentication(): void
{
    if (!authenticated()) {
        http_response_code(401);
        echo json_encode(['error'=>pulseMessage('Entre na sua conta para acessar o painel.')]);
        exit;
    }
    // Automatic polling must not extend the idle session indefinitely.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') $_SESSION['auth']['last_activity'] = time();
}

function jsonInput(): array
{
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') throw new DomainException('Origem não permitida.', 403);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $expected = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    if ($origin !== null && $origin !== $expected) throw new DomainException('Origem não permitida.', 403);
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') throw new DomainException('Envie os dados como JSON.', 415);
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new DomainException('Atualize a página e tente novamente.', 403);
    $body = file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($body) > 8192) throw new DomainException('Solicitação muito grande.', 413);
    $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Envie um objeto JSON válido.');
    return $input;
}

function validateCredentials(array $input): array
{
    $username = $input['username'] ?? null;
    $password = $input['password'] ?? null;
    if (!is_string($username) || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username)) throw new InvalidArgumentException('Use um usuário de 3 a 64 letras, números, pontos, traços ou sublinhados.');
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) throw new InvalidArgumentException('A senha deve ter entre 12 e 72 bytes. Prefira uma frase longa.');
    return [$username, $password];
}

function createAccount(array $input): void
{
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) throw new DomainException('Crie o primeiro acesso no próprio computador servidor.', 403);
    [$username, $password] = validateCredentials($input);
    $db = authDb();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $db->prepare('INSERT INTO account (id,username,password_hash,version) VALUES (1,?,?,?)')->execute([$username,$hash,bin2hex(random_bytes(24))]);
    } catch (PDOException $e) {
        if (authAccount()) throw new DomainException('O primeiro acesso já foi configurado.', 409);
        throw $e;
    }
}

function loginAccount(array $input): void
{
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    if (!is_string($username) || !is_string($password) || strlen($username)>64 || strlen($password)>72 || str_contains($password,"\0")) throw new InvalidArgumentException('Usuário ou senha inválidos.');
    $lock = fopen(authDirectory().'/auth.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot acquire authentication lock.');
    try {
        $db = authDb(); $now = time(); $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $db->prepare('DELETE FROM failures WHERE attempted_at < ?')->execute([$now-900]);
        $q=$db->prepare('SELECT COUNT(*) FROM failures WHERE address=?'); $q->execute([$ip]);
        if ((int)$q->fetchColumn() >= 10 || (int)$db->query('SELECT COUNT(*) FROM failures')->fetchColumn() >= 30) throw new DomainException('Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.', 429);
        $account=authAccount();
        // Use the same password-verification work for unknown usernames.
        $valid=$account && password_verify($password,$account['password_hash']);
        if (!$valid || !hash_equals($account['username'], $username)) {
            $db->prepare('INSERT INTO failures(address,attempted_at) VALUES (?,?)')->execute([$ip,$now]);
            throw new DomainException('Usuário ou senha inválidos.', 401);
        }
        $db->prepare('DELETE FROM failures WHERE address=?')->execute([$ip]);
        session_regenerate_id(true);
        $_SESSION['csrf']=bin2hex(random_bytes(24));
        $_SESSION['auth']=['username'=>$account['username'],'version'=>$account['version'],'created'=>$now,'last_activity'=>$now];
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
