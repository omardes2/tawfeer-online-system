<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| جدولة عمليات التوصيل (Phase 4.3 / ADR-039) — مفعّلة بالإعداد فقط (config/delivery.php).
*/
if (config('delivery.sync.enabled')) {
    Schedule::command('delivery:sync')->cron(config('delivery.sync.cron'))->withoutOverlapping();
}
if (config('delivery.escalation.enabled')) {
    Schedule::command('delivery:escalate-exceptions')->cron(config('delivery.escalation.cron'))->withoutOverlapping();
}
