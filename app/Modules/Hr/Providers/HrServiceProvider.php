<?php

namespace App\Modules\Hr\Providers;

use App\Modules\Accounting\Events\VoucherRevised;
use App\Modules\Hr\Listeners\SyncEndOfServiceOnVoucherRevised;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * وحدة الموارد البشرية — الرواتب والإجازات ومخصّص نهاية الخدمة.
 */
class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // تعديلُ سند صرفٍ يُصلح حركةَ التصفية المرتبطة به، وإلّا افترق دفتر
        // المخصّص عن الدفتر العامّ.
        Event::listen(VoucherRevised::class, SyncEndOfServiceOnVoucherRevised::class);
    }
}
