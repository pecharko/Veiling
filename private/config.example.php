<?php
declare(strict_types=1);
// KOPIEER dit bestand naar private/config.php en pas de waarden aan.
// Deze map moet NAAST catalogus-public/ staan, nooit in de openbare catalogus-public/-map.
return [
  'database' => [
    'host' => 'JOUW-OVH-MYSQL-SERVER',
    'port' => 3306,
    'name' => 'JOUW_DATABASE',
    'user' => 'JOUW_GEBRUIKER',
    'password' => 'JOUW_DATABASE_WACHTWOORD',
  ],
  // Indien de catalogus op domein.be/catalogus staat: '/catalogus'.
  // Bij de domein-root: ''.
  'base_path' => '',
  // Minstens 24 unieke tekens. Nodig om de eerste beheerder aan te maken.
  'setup_key' => 'VERVANG_DIT_MET_EEN_WILLEKEURIGE_GEHEIME_SLEUTEL',
];
