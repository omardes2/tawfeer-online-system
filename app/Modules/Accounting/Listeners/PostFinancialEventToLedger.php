<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\FinancialEventOccurred;
use App\Modules\Accounting\Services\AccountingService;

/**
 * مستمع المحاسبة (BR-ACC-11): يولّد قيدًا من الحدث المالي عبر AccountingService.
 * متزامن — يبقى ضمن معاملة العملية الأصلية (لا مستمع غير متزامن للمنطق المالي الحرج).
 */
class PostFinancialEventToLedger
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function handle(FinancialEventOccurred $event): void
    {
        $this->accounting->recordEvent($event->type, $event->context);
    }
}
