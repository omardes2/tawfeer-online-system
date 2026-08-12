<?php

use App\Models\User;
use App\Modules\Sales\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * ترقيع مستفيدي العمولة للطلبات القائمة: صفحة «طلب بيع جديد» لم تكن تربط الطلب
 * بمنشئه، فبقيت assigned_to/affiliate_id فارغتين ولم يُحتسب لأي طلب عمولة.
 * يُسنَد كل طلب توصيل بلا مستفيد إلى منشئه إن كان موظف مبيعات (assigned_to)
 * أو مسوّقًا (affiliate_id). العمولة نفسها تُحتسب عند وصول in_accounting
 * (أو بإعادة تحفيزها يدويًا للطلبات التي وصلتها قبل الترقيع).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        Order::query()
            ->whereNull('assigned_to')->whereNull('affiliate_id')
            ->whereNotNull('created_by')
            ->where('channel', '!=', 'pos')
            ->with('creator')
            ->each(function (Order $order) {
                $creator = $order->creator;
                if (! $creator instanceof User) {
                    return;
                }

                if ($creator->hasAnyRole(['sales', 'sales_supervisor'])) {
                    $order->update(['assigned_to' => $creator->id]);
                } elseif ($creator->hasRole('affiliate')) {
                    $order->update(['affiliate_id' => $creator->id]);
                }
            });
    }

    public function down(): void
    {
        // لا عكس: القيم المرقَّعة صحيحة وظيفيًا ولا يمكن تمييزها عن المُدخلة يدويًا.
    }
};
