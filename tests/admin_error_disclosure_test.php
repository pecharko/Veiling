<?php
declare(strict_types=1);
$root=dirname(__DIR__).'/catalogus-public/admin/';
$patterns=[
 'backup.php'=>["\$error='De actie is mislukt. Probeer opnieuw of raadpleeg de beheerder.';"],
 'kavel.php'=>["\$error='Er ging iets mis met het bewaren.';","\$error='Foto-upload mislukt; het kavel is niet opgeslagen.';"],
 'veiling-organisatie.php'=>["\$error = \$ex instanceof InvalidArgumentException ?"],
 'gebruikers.php'=>["\$error = 'Ongeldige invoer of de bewerking is niet toegestaan.';"]
];
foreach($patterns as $file=>$needles){
 $source=file_get_contents($root.$file);
 foreach($needles as $needle)if(!str_contains($source,$needle))throw new RuntimeException("Missing safe error handling in ".$file);
}
foreach(['backup.php','kavel.php','veiling-organisatie.php','gebruikers.php'] as $file){
 $source=file_get_contents($root.$file);
 if(preg_match('/\\$error\\s*=\\s*\\$(?:e|ex|exception)->getMessage\\(\\)/',$source))
   throw new RuntimeException("Raw exception message assigned to UI in ".$file);
}
echo "Admin error disclosure regression passed\n";
