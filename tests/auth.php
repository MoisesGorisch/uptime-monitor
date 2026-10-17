<?php
declare(strict_types=1);
ob_start();
$testDirectory = sys_get_temp_dir().'/pulse-auth-test-'.bin2hex(random_bytes(6));
mkdir($testDirectory,0700,true);
putenv('PULSE_AUTH_DIR='.$testDirectory);
require dirname(__DIR__).'/src/auth.php';
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$_SERVER['HTTP_HOST']='127.0.0.1:8787';
authSession();
$passed=0;
function checkAuth(bool $condition,string $label): void { global $passed; if (!$condition) throw new RuntimeException('FAIL: '.$label); $passed++; echo 'PASS: '.$label.PHP_EOL; }
checkAuth(networkAllowed('127.0.0.1','127.0.0.1:8787',false),'Loopback default');
checkAuth(!networkAllowed('192.168.1.10','192.168.1.20:8787',false),'LAN disabled by default');
checkAuth(networkAllowed('192.168.1.10','192.168.1.20:8787',true),'Private IPv4 LAN opt in');
checkAuth(networkAllowed('10.1.1.2','10.1.1.3:8787',true),'Private 10/8');
checkAuth(networkAllowed('172.16.1.2','172.16.1.3:8787',true),'Private 172.16/12');
checkAuth(!networkAllowed('172.32.1.2','172.16.1.3:8787',true),'Nonprivate peer blocked');
checkAuth(!networkAllowed('8.8.8.8','192.168.1.20:8787',true),'Public peer blocked');
checkAuth(!networkAllowed('127.0.0.1','attacker.example:8787',true),'DNS host rebinding blocked');
checkAuth(!networkAllowed('127.0.0.1','192.168.1.20.attacker.example:8787',true),'Private-looking hostname blocked');
checkAuth(!authenticated(),'Anonymous session rejected');
$password=bin2hex(random_bytes(16));
createAccount(['username'=>'test-admin','password'=>$password]);
$account=authAccount();
checkAuth($account['password_hash']!==$password && password_verify($password,$account['password_hash']),'Salted password hash stored');
$duplicate=false;try{createAccount(['username'=>'another','password'=>$password]);}catch(DomainException $e){$duplicate=$e->getCode()===409;}
checkAuth($duplicate,'Initial setup cannot overwrite account');
$oldId=session_id();
loginAccount(['username'=>'test-admin','password'=>$password]);
checkAuth(authenticated() && session_id()!==$oldId,'Login rotates session');
$_SESSION['auth']['last_activity']=time()-1801;
checkAuth(!authenticated(),'Idle expiry enforced');
loginAccount(['username'=>'test-admin','password'=>$password]);
$_SESSION['auth']['created']=time()-28801;
checkAuth(!authenticated(),'Absolute expiry enforced');
for($i=0;$i<10;$i++){try{loginAccount(['username'=>'test-admin','password'=>'incorrect']);}catch(DomainException $e){checkAuth($e->getCode()===401,'Invalid login recorded');}}
$blocked=false;try{loginAccount(['username'=>'test-admin','password'=>$password]);}catch(DomainException $e){$blocked=$e->getCode()===429;}
checkAuth($blocked,'Rate limit survives a valid password');
$_SESSION=[];
session_regenerate_id(true);
$blocked=false;try{loginAccount(['username'=>'test-admin','password'=>$password]);}catch(DomainException $e){$blocked=$e->getCode()===429;}
checkAuth($blocked,'New session cannot bypass persistent limit');
echo "$passed authentication assertions passed.\n";
ob_end_flush();
