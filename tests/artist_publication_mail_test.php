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
    || !str_contains($body,'Deel je pagina met je vrienden, familie en volgers.')
    || !str_contains($body,'Deel deze kunstenaar')
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
  CREATE TABLE artist_publication_mail_settings (id INTEGER PRIMARY KEY,enabled INTEGER NOT NULL DEFAULT 1);
  CREATE TABLE artist_publication_mail_queue (id INTEGER PRIMARY KEY AUTOINCREMENT,seller_id INTEGER UNIQUE,is_profile_update INTEGER NOT NULL DEFAULT 0);
  CREATE TABLE artist_publication_mail_history (id INTEGER PRIMARY KEY,artist_name TEXT,is_profile_update INTEGER,sent_at TEXT);
  INSERT INTO artist_publication_mail_queue (seller_id) VALUES (42),(43)");
if (!seller_artist_publication_mail_enabled($queue)) {
  throw new RuntimeException('Artist publication mail must be enabled by default.');
}
$messages=[];
seller_send_pending_artist_publication_mail($queue,$send);
$queue->exec('UPDATE settings SET is_site_online=0,show_artists_overview=1');
seller_send_pending_artist_publication_mail($queue,$send);
if ($messages || (int)$queue->query('SELECT COUNT(*) FROM artist_publication_mail_queue')->fetchColumn()!==2) {
  throw new RuntimeException('Both public visibility switches must be on before sending.');
}
$queue->exec('UPDATE settings SET is_site_online=1');
$queue->exec('INSERT INTO artist_publication_mail_settings (id,enabled) VALUES (1,0)');
seller_send_pending_artist_publication_mail($queue,$send);
if ($messages || (int)$queue->query('SELECT COUNT(*) FROM artist_publication_mail_queue')->fetchColumn()!==2) {
  throw new RuntimeException('Disabling artist publication mail must pause the queue without dropping notices.');
}
seller_queue_artist_profile_update($queue,42);
$queue->exec('UPDATE artist_publication_mail_settings SET enabled=1 WHERE id=1');
seller_send_pending_artist_publication_mail($queue,$send);
seller_send_pending_artist_publication_mail($queue,$send);
if (count($messages)!==1 || $messages[0][0]!=='test@example.invalid' || $messages[0][1]!=='Je kunstenaarspagina staat online'
    || (int)$queue->query('SELECT seller_id FROM artist_publication_mail_queue')->fetchColumn()!==43) {
  throw new RuntimeException('Only the published, pending artist receives a single mail.');
}
echo "PASS queue waits for the site, artist directory and mail switch, ignores hidden profiles and sends once.\n";

$queue->exec('UPDATE artist_publication_mail_settings SET enabled=0 WHERE id=1');
seller_queue_artist_profile_update($queue,42);
seller_queue_artist_profile_update($queue,42);
seller_send_pending_artist_publication_mail($queue,$send);
if (count($messages)!==1) throw new RuntimeException('Profile updates must respect the mail switch.');
$queue->exec('UPDATE artist_publication_mail_settings SET enabled=1 WHERE id=1');
seller_send_pending_artist_publication_mail($queue,$send);
if (count($messages)!==2 || $messages[1][0]!=='test@example.invalid'
    || $messages[1][1]!=='Je kunstenaarspagina is bijgewerkt'
    || !str_contains($messages[1][2],'https://41lokeren.be/kunstenaar.php?id=42')
    || !str_contains($messages[1][2],'controleer of alles klopt')
    || !str_contains($messages[1][2],'Neem contact op met je buddy of mail naar veiling@41lokeren.be.')
    || (int)$queue->query('SELECT seller_id FROM artist_publication_mail_queue')->fetchColumn()!==43) {
  throw new RuntimeException('Coalesced profile updates must send a single review request linking to the profile.');
}
$queue->exec('DELETE FROM artist_publication_mail_queue');
seller_queue_artist_profile_update($queue,43);
if ((int)$queue->query('SELECT COUNT(*) FROM artist_publication_mail_queue')->fetchColumn()!==0) {
  throw new RuntimeException('Changes to unpublished artists must not queue update mail.');
}
echo "PASS pending publication takes precedence, published updates send one review request, unpublished edits do not queue.\n";

seller_queue_artist_profile_update($queue,42);
seller_send_pending_artist_publication_mail($queue,static fn() => false);
if ((int)$queue->query('SELECT COUNT(*) FROM artist_publication_mail_history')->fetchColumn()!==2
    || (int)$queue->query('SELECT seller_id FROM artist_publication_mail_queue')->fetchColumn()!==42) {
  throw new RuntimeException('Failed sends must stay queued without appearing in the sent history.');
}
for ($index=1; $index<=10; $index++) {
  $queue->prepare('UPDATE lot_sellers SET artist_name=? WHERE id=42')->execute(['Testkunstenaar '.$index]);
  seller_queue_artist_profile_update($queue,42);
  seller_send_pending_artist_publication_mail($queue,$send);
}
$history=$queue->query('SELECT artist_name,is_profile_update,sent_at FROM artist_publication_mail_history ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
if (count($history)!==10 || $history[0]['artist_name']!=='Testkunstenaar 10'
    || $history[9]['artist_name']!=='Testkunstenaar 1' || (int)$history[0]['is_profile_update']!==1
    || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$history[0]['sent_at'])) {
  throw new RuntimeException('Only the ten most recent accepted messages must be recorded.');
}
echo "PASS failed sends stay pending and history retains the latest ten accepted messages.\n";
