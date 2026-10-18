<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/lot_form.php';

function check_value(mixed $expected, mixed $actual, string $case): void {
  if ($expected !== $actual) {
    throw new RuntimeException($case . ': expected ' . var_export($expected, true) .
      ', got ' . var_export($actual, true));
  }
}

$defaults = [
  'lot_number' => '',
  'title' => '',
  'subtitle' => '',
  'category' => 'Overige',
  'description' => '',
  'details' => '',
  'estimate_min' => '',
  'estimate_max' => '',
  'status' => 'draft',
];

$existing = array_merge($defaults, [
  'lot_number' => 7,
  'title' => 'Bewaarde titel',
  'description' => 'Bewaarde tekst',
  'status' => 'published',
]);

$initial = lot_form_values($defaults, null);
check_value('Overige', $initial['category'], 'Nieuw kavel: standaardcategorie');
check_value('draft', $initial['status'], 'Nieuw kavel: standaardstatus');

$edit = lot_form_values($defaults, $existing);
check_value('Bewaarde titel', $edit['title'], 'Bestaand kavel: titel uit database');
check_value('published', $edit['status'], 'Bestaand kavel: opgeslagen status');

$post = [
  'lot_number' => '7',
  'title' => 'Nieuwe titel <&>',
  'subtitle' => 'Extra tekst',
  'category' => 'Design',
  'description' => 'Nieuwe beschrijving',
  'details' => 'Onvolledige invoer',
  'estimate_min' => '200',
  'estimate_max' => '100',
  'status' => 'draft',
  'action' => 'delete-lot',
];
$newWithError = lot_form_values($defaults, null, $post);
check_value('Nieuwe titel <&>', $newWithError['title'], 'Aanmaakfout: ingevoerde titel behouden');
check_value('200', $newWithError['estimate_min'], 'Aanmaakfout: schatting behouden');
check_value('100', $newWithError['estimate_max'], 'Aanmaakfout: ongeldige max behouden');
check_value('draft', $newWithError['status'], 'Aanmaakfout: gekozen status behouden');
check_value(false, array_key_exists('action', $newWithError), 'Onbekende velden niet overnemen');

$editWithError = lot_form_values($defaults, $existing, $post);
check_value('Nieuwe titel <&>', $editWithError['title'], 'Wijzigfout: POST vervangt opgeslagen titel');
check_value('Nieuwe beschrijving', $editWithError['description'], 'Wijzigfout: POST-beschrijving');

$badStatus = lot_form_values($defaults, null, ['status' => 'unknown', 'title' => ['invalid']]);
check_value('draft', $badStatus['status'], 'Onbekende status nooit selecteren');
check_value('', $badStatus['title'], 'Array als formulierveld niet accepteren');

echo "lot_form_values: alle 13 controles geslaagd\n";
