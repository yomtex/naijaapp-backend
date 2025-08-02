<?php

// use Illuminate\Foundation\Inspiring;
// use Illuminate\Support\Facades\Artisan;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');


use App\Console\Commands\DecayRiskScores;
use App\Console\Commands\ReleasePendingFunds;

return [
    DecayRiskScores::class,
    ReleasePendingFunds::class,
];
