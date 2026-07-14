<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Catalog\Models\Product;

/**
 * يحلّ الحساب المحاسبي لوظيفة معيّنة بأولوية: المنتج → فئته → إعدادات الترحيل الافتراضية.
 * يعيد رمز الحساب (code) لأن محرّك القيود يعمل بالرموز.
 */
class PostingAccountResolver
{
    /** رمز الحساب لوظيفة محاسبية (revenue/cogs/inventory/receivable/tax/cash/shipping_revenue/payable). */
    public function code(string $function, ?Product $product = null, string $documentType = 'sales_invoice'): ?string
    {
        // تجاوز على مستوى المنتج ثم الفئة (للوظائف القابلة للتخصيص فقط).
        $column = match ($function) {
            'revenue' => 'revenue_account_id',
            'inventory' => 'inventory_account_id',
            'cogs' => 'cogs_account_id',
            default => null,
        };

        if ($column !== null && $product !== null) {
            $accountId = $product->{$column} ?? $product->category?->{$column};
            if ($accountId) {
                $code = Account::whereKey($accountId)->value('code');
                if ($code) {
                    return $code;
                }
            }
        }

        // الافتراضي من جدول إعدادات الترحيل.
        return AccountMapping::where('document_type', $documentType)
            ->where('function', $function)->first()?->account?->code;
    }
}
