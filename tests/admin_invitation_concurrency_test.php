<?php
declare(strict_types=1);
/** Two overlapping workers against disposable MySQL, never production. */
if (getenv('CI_DB_HOST') !== '127.0.0.1' || getenv('CI_DB_NAME') !== 'auction_test'
 || getenv('GITHUB_ACTIONS') !== 'true') throw new RuntimeException('CI-only');
if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) throw new RuntimeException('pcntl required');
require dirname(__DIR__) . '/private/admin_invitations.php';
$dsn='mysql:host=127.0.0.1;dbname=auction_test;charset=utf8mb4';
$user=(string)getenv('CI_DB_USER');$pass=(string)getenv('CI_DB_PASSWORD');
$db=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$token=bin2hex(random_bytes(32));$email='race-invitation@example.invalid';
$db->prepare("INSERT INTO admin_invitations (name,email,role,token_hash,created_at,expires_at)
 VALUES ('Race fixture',?,'assistant',?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))")
 ->execute([$email,hash('sha256',$token)]);
unset($db); // Do not inherit a live PDO connection.
$gate=tempnam(sys_get_temp_dir(),'invite-gate-');
if ($gate===false)throw new RuntimeException('Barrier failed');
unlink($gate);
$children=[];
for ($n=0;$n<2;$n++) {
 $pid=pcntl_fork();
 if($pid===-1)throw new RuntimeException('Fork failed');
 if($pid===0) {
  $deadline=microtime(true)+8;
  while(!is_file($gate)) {
   if(microtime(true)>$deadline)exit(12);
   usleep(10000);
  }
  try {
   $worker=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
   exit(invitation_accept($worker,$token,'secure-test-password-123')?0:1);
  }catch(Throwable $e){exit(13);}
 }
 $children[]=$pid;
}
touch($gate);
$results=[];
foreach($children as $pid) {
 pcntl_waitpid($pid,$status);
 $results[]=pcntl_wifexited($status)?pcntl_wexitstatus($status):99;
}
@unlink($gate);
sort($results);
$verify=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$st=$verify->prepare('SELECT COUNT(*) FROM admins WHERE email=?');$st->execute([$email]);
if($results!==[0,1]||(int)$st->fetchColumn()!==1)
 throw new RuntimeException('Concurrent acceptance did not allow exactly one account');
echo "PASS: overlapping MySQL workers: exactly one activation and one denial\n";
