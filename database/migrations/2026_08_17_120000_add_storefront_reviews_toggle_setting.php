<?php

use App\Modules\Foundation\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * مفتاح إظهار قسم تقييمات الزبائن في المتجر.
 *
 * افتراضُه `true` فلا يتغيّر سلوك أي متجر بالترقية. وإطفاؤه **لا يحذف رأيًا**:
 * يُخفي القسم ويُغلق استقبال الجديد، وتعود التقييمات المحفوظة كما هي بالإشعال.
 */
return new class extends Migration
{
    private const KEY = 'storefront.reviews_enabled';

    public function up(): void
    {
        if (! Schema::hasTable('settings') || Settings::has(self::KEY)) {
            return;
        }

        Settings::set(self::KEY, true, 'storefront', 'boolean');
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            Settings::forget(self::KEY);
        }
    }
};
