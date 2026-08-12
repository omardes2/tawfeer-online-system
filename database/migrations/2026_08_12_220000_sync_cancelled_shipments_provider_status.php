<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ترقيع الشحنات الملغاة التي بقيت حالة المزوّد عليها قديمة: الإلغاء كان يحدّث الحالة
 * الداخلية دون `provider_status`، وهي المعروضة في عمود «حالة أوبتيموس» — فيظهر الطلب
 * «بانتظار الاستلام» رغم إلغاء طرده فعلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        DB::table('shipments')
            ->where('delivery_status', 'cancelled')
            ->where(fn ($q) => $q->where('provider_status', '!=', 'cancelled')->orWhereNull('provider_status'))
            ->update(['provider_status' => 'cancelled']);
    }

    public function down(): void
    {
        // لا عكس: القيمة المصحَّحة هي الواقع لدى المزوّد.
    }
};
