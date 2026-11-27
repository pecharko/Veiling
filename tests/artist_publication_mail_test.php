<?php
declare(strict_types=1);

require dirname(__DIR__).'/private/lot_sellers.php';

$messages=[];
$send=static function(string $email,string $subject,string $message,array $headers) use (&$messages): bool {
  $messages[]=[$email,$subject,$message,$headers];
  return true;
};
seller_send_artist_publication_mail(42,'Testkunstenaar','test@example.invalid',$send);
seller_send_artist_publication_mail(42,'Testkunstenaar',"a@example.invalid\r\nBcc: b@example.invalid",$send);
if (count($messages)!==1) throw new RuntimeException('Only a valid artist address may receive the mail.');
[$email,$subject,$body,$headers]=$messages[0];
if ($email!=='test@example.invalid' || $subject!=='Je kunstenaarspagina staat online'
    || !str_starts_with($body,'Dag Testkunstenaar,')
    || !str_contains($body,'https://41lokeren.be/kunstenaar.php?id=42')
    || !str_contains($body,'Wil je je pagina delen')
    || !str_contains($body,'Neem contact op met je buddy of mail naar veiling@41lokeren.be.')
    || !str_contains($body,'We brengen het zo snel mogelijk in orde.')
    || $headers['From']!=='41 Lokeren <noreply@41lokeren.be>') {
  throw new RuntimeException('Publication mail must contain the direct profile link, sharing request, instructions for requesting changes and noreply sender.');
}
echo "PASS publication mail address, content and direct link with mock transport.\n";

$queue=new PDO('sqlite::memory:');
$queue->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$queue->exec("CREATE TABLE settings (id INTEGER,is_site_online INTEGER,show_artists_overview INTEGER);
  INSERT INTO settings VALUES (1,1,0);
  CREATE TABLE lot_sellers (id INTEGER,seller_type TEXT,artist_published INTEGER,artist_name TEXT,email TEXT);
  INSERT INTO lot_sellers VALUES (42,'kunstenaar',1,'Testkunstenaar','test@example.invalid'),
     (43,'kunstenaar',0,'Verborgen kunstenaar','hidden@example.invalid');
  CREATE TABLE artist_publication_mail_queue (seller_id INTEGER PRIMARY KEY);
  INSERT INTO artist_publication_mail_queue VALUES (42),(43)");
$messages=[];
seller_send_pending_artist_publication_mail($queue,$send);
$queue->exec('UPDATE settings SET is_site_online=0,show_artists_overview=1');
seller_send_pending_artist_publication_mail($queue,$send);
if ($messages || (int)$queue->query('SELECT COUNT(*) FROM artist_publication_mail_queue')->fetchColumn()!==2) {
  throw new RuntimeException('Both public visibility switches must be on before sending.');
}
$queue->exec('UPDATE settings SET is_site_online=1');
seller_send_pending_artist_publication_mail($queue,$send);
seller_send_pending_artist_publication_mail($queue,$send);
if (count($messages)!==1 || $messages[0][0]!=='test@example.invalid'
    || (int)$queue->query('SELECT seller_id FROM artist_publication_mail_queue')->fetchColumn()!==43) {
  throw new RuntimeException('Only the published, pending artist receives a single mail.');
}
echo "PASS queue waits for the site and artist directory, ignores hidden profiles and sends once.\n";
