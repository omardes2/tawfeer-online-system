<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Database\Seeder;

/**
 * إعدادات الترحيل الافتراضية (Posting Setup) لكل نوع مستند. تُنشأ مرّة وتُدار من اللوحة.
 */
class AccountMappingSeeder extends Seeder
{
    /** document_type => [function => account_code] */
    private array $defaults = [
        'sales_invoice' => [
            'receivable' => '1100',        // ذمم العملاء (مبيعات مباشرة/عميل)
            'cod_receivable' => '1050',    // ذمم شركة التوصيل (طلبات انشاء اوردر — COD)
            'cash' => '1011-0001',         // الصندوق الرئيسي (المبيعات المباشرة — حساب طرفي)
            'revenue' => '4010',           // إيراد المبيعات
            'shipping_revenue' => '4020',  // إيراد الشحن
            'sales_returns' => '4030',     // مردودات المبيعات (عكس الإيراد عند الإرجاع)
            'tax' => '2100',               // ضريبة مستحقة
            'cogs' => '6000',              // تكلفة البضاعة المباعة (حساب رئيسي)
            'inventory' => '1200',         // المخزون
        ],
        'purchase_invoice' => [
            'inventory' => '1200',
            'payable' => '2010',
            'tax' => '1250',
        ],
    ];

    public function run(): void
    {
        foreach ($this->defaults as $documentType => $functions) {
            foreach ($functions as $function => $code) {
                $accountId = Account::where('code', $code)->value('id');
                if ($accountId === null) {
                    continue;
                }
                AccountMapping::firstOrCreate(
                    ['document_type' => $documentType, 'function' => $function],
                    ['account_id' => $accountId],
                );
            }
        }
    }
}
