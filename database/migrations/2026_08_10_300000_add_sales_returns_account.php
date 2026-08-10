<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Database\Migrations\Migration;

/**
 * حساب «مردودات المبيعات 4030» (مقابل للإيراد — رصيده مدين) وربطه بإعدادات ترحيل المبيعات.
 * يُستخدم لعكس الإيراد عند إتمام المرتجع بدل ترك الإيراد متضخّمًا (ADR-012f).
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentId = Account::where('code', '4000')->value('id');
        if ($parentId === null) {
            return; // دليل الحسابات غير مزروع بعد — الزارع سيتكفّل.
        }

        $account = Account::firstOrCreate(
            ['code' => '4030'],
            ['name' => 'مردودات المبيعات', 'type' => 'revenue', 'parent_id' => $parentId, 'is_postable' => true, 'is_active' => true],
        );

        AccountMapping::firstOrCreate(
            ['document_type' => 'sales_invoice', 'function' => 'sales_returns'],
            ['account_id' => $account->id],
        );
    }

    public function down(): void
    {
        AccountMapping::where('document_type', 'sales_invoice')->where('function', 'sales_returns')->delete();
        Account::where('code', '4030')->delete();
    }
};
