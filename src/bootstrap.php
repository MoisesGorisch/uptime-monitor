<?php
declare(strict_types=1);

function db(): PDO
{
    static $db;
    if ($db) return $db;
    $root = dirname(__DIR__);
    $config = is_file($root . '/config.local.php') ? require $root . '/config.local.php' : [];
    $db = new PDO($config['dsn'] ?? 'sqlite:' . $root . '/var/monitor.sqlite', $config['db_user'] ?? null, $config['db_password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) { $db->exec('PRAGMA foreign_keys=ON'); $db->exec('PRAGMA busy_timeout=5000'); }
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
    $db->exec("CREATE TABLE IF NOT EXISTS monitors (id $id, name VARCHAR(80) NOT NULL, url VARCHAR(2048) NOT NULL, interval_seconds INTEGER NOT NULL, slow_ms INTEGER NOT NULL, expected_text VARCHAR(200) NOT NULL DEFAULT '', last_checked INTEGER NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS checks (id $id, monitor_id INTEGER NOT NULL, checked_at INTEGER NOT NULL, status VARCHAR(12) NOT NULL, http_code INTEGER NOT NULL, latency_ms INTEGER NOT NULL, detail VARCHAR(255) NOT NULL, FOREIGN KEY(monitor_id) REFERENCES monitors(id) ON DELETE CASCADE)");
    $db->exec("CREATE TABLE IF NOT EXISTS incidents (id $id, monitor_id INTEGER NOT NULL, opened_at INTEGER NOT NULL, closed_at INTEGER NULL, detail VARCHAR(255) NOT NULL, FOREIGN KEY(monitor_id) REFERENCES monitors(id) ON DELETE CASCADE)");
    return $db;
}

function validateMonitor(array $input): array
{
    foreach (['name', 'url', 'expected_text'] as $field) {
        if (isset($input[$field]) && !is_string($input[$field])) {
            throw new InvalidArgumentException('Os campos de texto devem conter apenas texto.');
        }
    }
    $name = trim((string)($input['name'] ?? ''));
    $url = trim((string)($input['url'] ?? ''));
    $parts = parse_url($url);
    if ($name === '' || strlen($name) > 80) throw new InvalidArgumentException('Informe um nome com até 80 caracteres.');
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !$parts || !in_array($parts['scheme'] ?? '', ['http','https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !in_array($parts['port'] ?? 443, [80,443], true)) throw new InvalidArgumentException('Use uma URL HTTP/HTTPS, sem credenciais, fragmentos ou portas alternativas.');
    $host = strtolower($parts['host']);
    if (preg_match('/[\x00-\x20\x7f]/', $url) || $host === 'localhost' || preg_match('/\.(localhost|local|internal)$/', $host)
        || (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) && !publicIpv4($host))) {
        throw new InvalidArgumentException('Use um endereço público; destinos locais e internos não são permitidos.');
    }
    $interval = filter_var($input['interval_seconds'] ?? 300, FILTER_VALIDATE_INT);
    $slow = filter_var($input['slow_ms'] ?? 1500, FILTER_VALIDATE_INT);
    $expected = trim((string)($input['expected_text'] ?? ''));
    if ($interval === false || $interval < 60 || $interval > 86400) throw new InvalidArgumentException('Intervalo permitido: 60 a 86400 segundos.');
    if ($slow === false || $slow < 100 || $slow > 10000) throw new InvalidArgumentException('Limite de lentidão permitido: 100 a 10000 ms.');
    if (str_contains($name, "\0") || str_contains($expected, "\0")) throw new InvalidArgumentException('Caractere inválido no texto.');
    if (strlen($expected) > 200) throw new InvalidArgumentException('O texto esperado pode ter até 200 caracteres.');
    return ['name'=>$name,'url'=>$url,'interval_seconds'=>$interval,'slow_ms'=>$slow,'expected_text'=>$expected];
}

function publicIpv4(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    $number = ip2long($ip);
    // Conservative exclusions: special-purpose, documentation, benchmark and multicast networks.
    foreach ([['0.0.0.0',8], ['100.64.0.0',10], ['169.254.0.0',16], ['192.0.0.0',24], ['192.0.2.0',24], ['192.88.99.0',24], ['198.18.0.0',15], ['198.51.100.0',24], ['203.0.113.0',24], ['224.0.0.0',3]] as [$network, $bits]) {
        $mask = -1 << (32 - $bits);
        if (($number & $mask) === (ip2long($network) & $mask)) return false;
    }
    return true;
}

function checkUrl(array $monitor): array
{
    $parts = parse_url($monitor['url']);
    $host = strtolower($parts['host']);
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!publicIpv4($ip)) return ['status'=>'down','http_code'=>0,'latency_ms'=>0,'detail'=>'Destino bloqueado: é necessário um endereço IPv4 público.'];
    $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    $curl = curl_init($monitor['url']);
    $body = ''; $tooLarge = false;
    curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>10, CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY=>'', CURLOPT_RESOLVE=>["$host:$port:$ip"], CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'UptimeMonitor/0.1', CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body, &$tooLarge): int { if (strlen($body) + strlen($chunk) > 1048576) { $tooLarge=true; return 0; } $body .= $chunk; return strlen($chunk); }]);
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    curl_exec($curl);
    $error = curl_errno($curl); $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $ms = (int)round(curl_getinfo($curl, CURLINFO_TOTAL_TIME)*1000);
    curl_close($curl);
    $detail = 'Resposta dentro do esperado.';
    if ($tooLarge) $detail = 'Resposta excedeu o limite de 1 MB.';
    elseif ($error) $detail = 'Falha de conexão, certificado ou tempo limite (cURL ' . $error . ').';
    elseif ($code < 200 || $code >= 300) $detail = 'HTTP ' . $code . '. Redirecionamentos não são seguidos; use a URL final.';
    elseif ($monitor['expected_text'] !== '' && !str_contains($body, $monitor['expected_text'])) $detail = 'Texto esperado não encontrado.';
    $status = $detail !== 'Resposta dentro do esperado.' ? 'down' : ($ms > $monitor['slow_ms'] ? 'slow' : 'up');
    if ($status === 'slow') $detail = 'Tempo de resposta acima do limite configurado.';
    return ['status'=>$status,'http_code'=>$code,'latency_ms'=>$ms,'detail'=>$detail];
}

function dueMonitors(PDO $db, ?int $now = null): array
{
    $query = $db->prepare('SELECT * FROM monitors WHERE last_checked IS NULL OR last_checked + interval_seconds <= ?');
    // SQLite expressions have no column affinity: bind an integer, not PDO's default string.
    $query->bindValue(1, $now ?? time(), PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll();
}

function recordCheck(PDO $db, int $id, array $result, ?int $now = null): void
{
    $now ??= time();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO checks (monitor_id,checked_at,status,http_code,latency_ms,detail) VALUES (?,?,?,?,?,?)')->execute([$id,$now,$result['status'],$result['http_code'],$result['latency_ms'],$result['detail']]);
        $q=$db->prepare('SELECT id FROM incidents WHERE monitor_id=? AND closed_at IS NULL'); $q->execute([$id]); $open=$q->fetchColumn();
        if ($result['status'] === 'down' && !$open) $db->prepare('INSERT INTO incidents (monitor_id,opened_at,detail) VALUES (?,?,?)')->execute([$id,$now,$result['detail']]);
        if ($result['status'] !== 'down' && $open) $db->prepare('UPDATE incidents SET closed_at=? WHERE id=?')->execute([$now,$open]);
        $db->prepare('UPDATE monitors SET last_checked=? WHERE id=?')->execute([$now,$id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

function snapshot(PDO $db): array
{
    $monitors=$db->query('SELECT * FROM monitors ORDER BY id DESC')->fetchAll();
    foreach ($monitors as &$m) {
        $q=$db->prepare('SELECT * FROM checks WHERE monitor_id=? AND checked_at>=? ORDER BY id DESC LIMIT 100'); $q->execute([$m['id'],time()-86400]); $m['history']=array_reverse($q->fetchAll());
        $q=$db->prepare('SELECT * FROM checks WHERE monitor_id=? ORDER BY id DESC LIMIT 1'); $q->execute([$m['id']]); $m['latest']=$q->fetch() ?: null;
        $q=$db->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status <> 'down' THEN 1 ELSE 0 END) AS available FROM checks WHERE monitor_id=? AND checked_at>=?"); $q->execute([$m['id'],time()-86400]); $counts=$q->fetch();
        $m['uptime']=$counts['total'] ? round(100*(int)$counts['available']/(int)$counts['total'],2) : null;
    }
    return ['monitors'=>$monitors,'incidents'=>$db->query('SELECT incidents.*, monitors.name FROM incidents JOIN monitors ON monitors.id=incidents.monitor_id ORDER BY incidents.id DESC LIMIT 30')->fetchAll(),'generated_at'=>time()];
}
