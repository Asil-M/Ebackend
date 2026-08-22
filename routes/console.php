<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\DonationExpirationService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('donations:expire', function () {
    $count = app(DonationExpirationService::class)->expireDueDonations();
    $this->info("Expired {$count} donation(s).");
})->purpose('Expire unavailable food and medicine donations');

Schedule::command('donations:expire')->hourly()->withoutOverlapping();
