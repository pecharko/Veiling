<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/lot_form.php';
$values=[12,'Fictief kavel','Een ondertitel','Kunst','Beschrijving','Details',
  '10','5.00',0,'draft','markt',null,null];
$stored=[
  'lot_number'=>'12','title'=>'Fictief kavel','subtitle'=>'Een ondertitel',
  'category'=>'Kunst','description'=>'Beschrijving','details'=>'Details',
  'market_price'=>'10.00','starting_bid'=>'5.00','auction_only'=>'0',
  'status'=>'draft','sale_location'=>'markt','auction_number'=>null,'seller_id'=>null,
];
if (lot_effective_fields_changed($stored,$values)) throw new RuntimeException('Unchanged lot incorrectly marked as changed');
$checks=[0,1,2,3,4,5,6,7,8,9,10,11,12];
foreach($checks as $index) {
  $different=$values;
  $different[$index]=match($index){
    0=>13,6=>'11',7=>'5.01',8=>1,9=>'published',10=>'veiling',11=>1,12=>34,
    default=>$values[$index].' gewijzigd'
  };
  if (!lot_effective_fields_changed($stored,$different))
    throw new RuntimeException('Missed persisted lot change at field '.$index);
}
$numeric=$values;
$numeric[6]='10.00';
$numeric[7]='5';
if (lot_effective_fields_changed($stored,$numeric)) throw new RuntimeException('Same decimal formatting falsely changes lot');
try {
  lot_effective_fields_changed($stored,[12,'incomplete']);
  throw new RuntimeException('Partial lot comparison incorrectly accepted');
} catch (InvalidArgumentException) {}
echo "PASS lot updates only on effective content change, including seller and status.\n";
