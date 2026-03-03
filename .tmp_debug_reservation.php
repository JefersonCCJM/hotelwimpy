<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$r = App\Models\Reservation::with(['payments','reservationRooms','stayNights','sales'])->latest('id')->first();
if (!$r) {
    echo "NO_RES\n";
    exit(0);
}

echo "RES_ID={$r->id}\n";
echo "TOTAL={$r->total_amount}\n";
echo "PAGOS_POS=".(float)$r->payments->where('amount','>',0)->sum('amount')."\n";
echo "PAGOS_NEG=".(float)$r->payments->where('amount','<',0)->sum('amount')."\n";
echo "SALES_DEBT=".(float)$r->sales->where('is_paid',false)->sum('total')."\n";

echo "ROOMS=".count($r->reservationRooms)."\n";
foreach ($r->reservationRooms as $rr) {
    echo "RR#{$rr->id} room={$rr->room_id} nights={$rr->nights} ppn={$rr->price_per_night} subtotal={$rr->subtotal} in={$rr->check_in_date} out={$rr->check_out_date}\n";
}

echo "SN_COUNT=".count($r->stayNights)."\n";
echo "SN_SUM=".(float)$r->stayNights->sum('price')."\n";
foreach ($r->stayNights->sortBy('date') as $sn) {
    $date = $sn->date instanceof Carbon\Carbon ? $sn->date->toDateString() : (string)$sn->date;
    echo "SN#{$sn->id} date={$date} price={$sn->price} paid=".((int)$sn->is_paid)." room={$sn->room_id}\n";
}
