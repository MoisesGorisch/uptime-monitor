<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$passed=0;
function expect(bool $condition,string $label): void { global $passed; if(!$condition)throw new RuntimeException('FAIL: '.$label); $passed++; echo 'PASS: '.$label.PHP_EOL; }
foreach(['127.0.0.1','10.1.2.3','172.16.0.1','192.168.0.1','169.254.169.254','100.100.1.1','0.0.0.0','192.0.0.8','192.0.2.1','192.88.99.1','198.18.0.1','198.51.100.1','203.0.113.1','224.0.0.1','255.255.255.255','::1','not-an-ip'] as $ip) expect(!publicIpv4($ip),'Block '.$ip);
expect(publicIpv4('1.1.1.1'),'Allow public IPv4');
foreach(['file:///etc/passwd','http://user:password@example.com','https://example.com:8080','javascript:alert(1)','http://127.0.0.1','http://localhost','http://server.local','http://[::1]','https://example.com/#secret'] as $url){$rejected=false;try{validateMonitor(['name'=>'Test','url'=>$url]);}catch(InvalidArgumentException){$rejected=true;}expect($rejected,'Reject unsafe URL');}
foreach (['name','url','expected_text'] as $field) {
    $input=['name'=>'Test','url'=>'https://example.com']; $input[$field]=['invalid'];
    $rejected=false; try { validateMonitor($input); } catch (InvalidArgumentException) { $rejected=true; }
    expect($rejected,'Reject structured input in '.$field);
}
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
ensureMonitorSchema($db);
expect(count(dueMonitors($db,$now+299))===0,'Collector waits for configured interval');
expect(count(dueMonitors($db,$now+300))===1,'Collector selects monitor when due');
manageMonitor($db,['action'=>'pause','id'=>1]);
expect(count(dueMonitors($db,$now+9999))===0,'Paused monitor never selected by collector');
expect((int)$db->query('SELECT COUNT(*) FROM checks')->fetchColumn()===3,'Pause preserves history');
manageMonitor($db,['action'=>'resume','id'=>1]);
expect(count(dueMonitors($db,$now))===1,'Resume schedules an immediate check');
manageMonitor($db,['action'=>'update','id'=>1,'name'=>'Edited','url'=>'https://example.org','interval_seconds'=>900,'slow_ms'=>3000,'expected_text'=>'Example']);
expect($db->query('SELECT name FROM monitors')->fetchColumn()==='Edited','Edit persists configuration');
expect((int)$db->query('SELECT COUNT(*) FROM checks')->fetchColumn()===3,'Edit preserves history');
$rejected=false;try{manageMonitor($db,['action'=>'delete','id'=>1,'confirmation'=>'wrong']);}catch(InvalidArgumentException){$rejected=true;}
expect($rejected,'Delete requires exact monitor name confirmation');
manageMonitor($db,['action'=>'delete','id'=>1,'confirmation'=>'Edited']);
expect((int)$db->query('SELECT COUNT(*) FROM monitors')->fetchColumn()===0,'Delete removes monitor');
expect((int)$db->query('SELECT COUNT(*) FROM checks')->fetchColumn()===0,'Delete removes check history');
expect((int)$db->query('SELECT COUNT(*) FROM incidents')->fetchColumn()===0,'Delete removes incidents');
echo "$passed assertions passed.\n";
