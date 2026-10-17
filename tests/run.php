<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$passed=0;
function expect(bool $condition,string $label): void { global $passed; if(!$condition)throw new RuntimeException('FAIL: '.$label); $passed++; echo 'PASS: '.$label.PHP_EOL; }
foreach(['127.0.0.1','10.1.2.3','192.168.0.1','169.254.169.254','100.100.1.1','0.0.0.0','224.0.0.1','::1','not-an-ip'] as $ip) expect(!publicIpv4($ip),'Block '.$ip);
expect(publicIpv4('1.1.1.1'),'Allow public IPv4');
foreach(['file:///etc/passwd','http://user:password@example.com','https://example.com:8080','javascript:alert(1)'] as $url){$rejected=false;try{validateMonitor(['name'=>'Test','url'=>$url]);}catch(InvalidArgumentException){$rejected=true;}expect($rejected,'Reject unsafe URL');}
expect(validateMonitor(['name'=>' Test ','url'=>'https://example.com'])['name']==='Test','Normalize valid monitor');
expect(checkUrl(['url'=>'http://127.0.0.1','slow_ms'=>1000,'expected_text'=>''])['status']==='down','Block local request before connection');
// Disposable in-memory database: never touches local monitor history.
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE monitors (id INTEGER PRIMARY KEY, name TEXT, url TEXT, interval_seconds INTEGER, slow_ms INTEGER, expected_text TEXT, last_checked INTEGER)');
$db->exec('CREATE TABLE checks (id INTEGER PRIMARY KEY, monitor_id INTEGER, checked_at INTEGER, status TEXT, http_code INTEGER, latency_ms INTEGER, detail TEXT)');
$db->exec('CREATE TABLE incidents (id INTEGER PRIMARY KEY, monitor_id INTEGER, opened_at INTEGER, closed_at INTEGER, detail TEXT)');
$db->exec("INSERT INTO monitors VALUES (1,'Test','https://example.com',300,1500,'',NULL)");
$result=['status'=>'down','http_code'=>503,'latency_ms'=>50,'detail'=>'Unavailable'];$now=time();recordCheck($db,1,$result,$now-10);recordCheck($db,1,$result,$now-5);
expect((int)$db->query('SELECT COUNT(*) FROM incidents')->fetchColumn()===1,'Repeated failure opens only one incident');
$result['status']='slow';recordCheck($db,1,$result,$now);expect((int)$db->query('SELECT closed_at FROM incidents')->fetchColumn()===$now,'Available but slow closes downtime');
$data=snapshot($db);expect($data['monitors'][0]['uptime']===33.33,'Availability calculated from samples');expect(count($data['monitors'][0]['history'])===3,'History persisted');
echo "$passed assertions passed.\n";
