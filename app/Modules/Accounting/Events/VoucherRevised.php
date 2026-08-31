<?php

namespace App\Modules\Accounting\Events;

use App\Modules\Accounting\Models\FinancialVoucher;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * تغيّرت بيانات سندٍ ماليّ بعد إنشائه — تعديلُ مسودّة أو إعادةُ ترحيلِ مُرحَّل.
 *
 * ## لماذا حدثٌ لا استدعاءٌ مباشر
 *
 * السند وثيقةٌ محاسبية تقف وحدها، وتتعلّق بها وثائقُ وحداتٍ أخرى: دفعةُ عمولة،
 * تصفيةُ نهاية خدمة، وما سيأتي. ولو نادت خدمةُ السندات هذه الوحدات باسمها
 * لصارت المحاسبةُ تعرف العمولات والموارد البشرية، فينقلب اتجاه الاعتماد ويصير
 * كل جدولٍ جديدٍ تعديلًا في قلب المحاسبة.
 *
 * فالمحاسبة تُعلن أن سندًا تغيّر، ومن ربط وثيقته به يسمع ويُصلح نفسه.
 */
class VoucherRevised
{
    use Dispatchable;

    public function __construct(public readonly FinancialVoucher $voucher) {}
}
