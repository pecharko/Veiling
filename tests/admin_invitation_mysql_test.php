<?php
declare(strict_types=1);
/** Disposable MySQL only: invitation acceptance, revocation, replay and expiry. */
require dirname(__DIR__) . '/private/admin_invitations.php';
$host=getenv('CI_DB_HOST'); $name=getenv('CI_DB_NAME');
if ($host !== '127.0.0.1' || $name !== 'auction_test') throw new RuntimeException('Refuse non-CI database');
$pdo=new PDO('mysql:host=127.0.0.1;dbname=auction_test;charset=utf8mb4',
  (string)getenv('CI_DB_USER'), (string)getenv('CI_DB_PASSWORD'),
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function require_test(bool $result,string $reason):void {if(!$result)throw new RuntimeException($reason);}
function seed(PDO $pdo,string $email,string $token,int $hours=1):int{
  $st=$pdo->prepare("INSERT INTO admin_invitations (name,email,role,token_hash,created_at,expires_at)
    VALUES ('CI invitation',?,'assistant',?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? HOUR))");
  $st->execute([$email,hash('sha256',$token),$hours]);return (int)$pdo->lastInsertId();
}
$token=bin2hex(random_bytes(32));$id=seed($pdo,'ci-invite-accepted@example.invalid',$token);
require_test(invitation_accept($pdo,$token,'valid-password-123')===true,'valid acceptance');
require_test(!invitation_accept($pdo,$token,'valid-password-123'),'single use/replay');
$account=$pdo->query("SELECT password_hash,is_active FROM admins WHERE email='ci-invite-accepted@example.invalid'")->fetch();
require_test((bool)$account && (int)$account['is_active']===1 && password_verify('valid-password-123',$account['password_hash']),'active account and hash');
$token2=bin2hex(random_bytes(32));$id2=seed($pdo,'ci-invite-revoked@example.invalid',$token2);
require_test(invitation_revoke($pdo,$id2),'revoke');
require_test(!invitation_revoke($pdo,$id2),'revoke idempotence');
require_test(!invitation_accept($pdo,$token2,'valid-password-123'),'revoked token denied');
$token3=bin2hex(random_bytes(32));seed($pdo,'ci-invite-expired@example.invalid',$token3,-1);
require_test(!invitation_accept($pdo,$token3,'valid-password-123'),'expired token denied');
$token4=bin2hex(random_bytes(32));seed($pdo,'ci-invite-short@example.invalid',$token4);
require_test(!invitation_accept($pdo,$token4,'short'),'weak password denied');
$token5=bin2hex(random_bytes(32));seed($pdo,'ci-invite-duplicate@example.invalid',$token5);
$pdo->exec("INSERT INTO admins (name,email,password_hash,role,is_active) VALUES ('Already here','ci-invite-duplicate@example.invalid','not-usable','assistant',1)");
require_test(!invitation_accept($pdo,$token5,'valid-password-123'),'existing email denied');
// Simulate a new invitation issuance using the same UPSERT as the service,
// without sending email from CI; the previously revoked token must remain dead.
$replacement=bin2hex(random_bytes(32));
$reissued=$pdo->prepare("INSERT INTO admin_invitations
 (name,email,role,token_hash,created_at,expires_at)
 VALUES ('CI reissued',?,'assistant',?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 48 HOUR))
 ON DUPLICATE KEY UPDATE name=VALUES(name),role=VALUES(role),
 token_hash=VALUES(token_hash),created_at=UTC_TIMESTAMP(),
 expires_at=VALUES(expires_at),accepted_at=NULL,revoked_at=NULL");
$reissued->execute(['ci-invite-revoked@example.invalid',hash('sha256',$replacement)]);
require_test(!invitation_accept($pdo,$token2,'valid-password-123'),'old token rejected after reissue');
require_test(invitation_accept($pdo,$replacement,'valid-password-123'),'replacement token accepted');
require_test(!invitation_accept($pdo,$replacement,'valid-password-123'),'replacement replay denied');
$count=(int)$pdo->query("SELECT COUNT(*) FROM admins WHERE email='ci-invite-revoked@example.invalid'")->fetchColumn();
require_test($count===1,'exactly one account after reissue');

// A second independent connection must observe token consumption immediately.
$other=new PDO('mysql:host=127.0.0.1;dbname=auction_test;charset=utf8mb4',
  (string)getenv('CI_DB_USER'),(string)getenv('CI_DB_PASSWORD'),
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require_test(!invitation_accept($other,$replacement,'valid-password-123'),'cross-connection replay denied');
echo "PASS: reissue invalidates previous token, single account and cross-connection replay\n";
echo "PASS: accept, one-use replay, revoke, expiry, weak password, duplicate email (disposable MySQL)\n";
