<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ما حُصِّل فعلًا من الزبون — منفصلًا عن إجمالي الطلب.
 *
 * ## الخلل الذي يعالجه
 *
 * شركة التوصيل تُعدّل مبلغ التحصيل أحيانًا قبل التسليم: طلبٌ إجماليّه ٦٤٠
 * يصل ملصقُه بـ`COD: 620`. والنظام يُعلّم الطلب «مدفوعًا بالكامل» عند التسليم
 * لأنه لا يعرف إلّا رقمًا واحدًا — فتقول الفاتورة ٦٤٠ مدفوعة، والصندوق يستلم
 * ٦٢٠، ولا شيء يقول أين ذهب الفرق.
 *
 * فأُضيف رقمٌ ثانٍ: **المُحصَّل**. والفرق بينه وبين الإجمالي يُعرَض ويُفلتَر
 * ويُقيَّد حين يستحقّ قيدًا.
 *
 * ## ولماذا `NULL` لا صفر
 *
 * الفراغ يعني «لم يُراجَع بعد» — والصفر يعني «لم يُحصَّل شيء». وطلبٌ لم
 * يُراجَع تحصيلُه ليس طلبًا لم يُدفَع، فخلطُهما يجعل كل طلبٍ قديم يبدو فرقًا.
 *
 * ## والقيد يقع على الفرق الذي يمسّ البضاعة وحده
 *
 * ترحيلُ البيع يقع على قيمة البضاعة بلا رسوم التوصيل، فحين يكون الفرق رسومَ
 * توصيلٍ رفضها الزبون تكون ذمّةُ الطلب قد أُقفلت تمامًا — ولا قيد. أمّا نقصٌ
 * يمسّ قيمة البضاعة فخصمٌ حقيقيّ يُقيَّد.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'sales.collection_variance.view',
        'sales.collection_variance.record',
    ];

    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'collected_total')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('collected_total', 15, 2)->nullable()->after('amount_paid');
                $table->string('collection_note', 255)->nullable()->after('collected_total');
                $table->timestamp('collection_recorded_at')->nullable()->after('collection_note');
                $table->foreignId('collection_recorded_by')->nullable()->after('collection_recorded_at')
                    ->constrained('users')->nullOnDelete();
                // قيدُ الخصم الناتج عن الفرق — يُعكس عند إعادة التسجيل فلا يتراكم.
                $table->unsignedBigInteger('collection_entry_id')->nullable()->after('collection_recorded_by');

                $table->index('collected_total');
            });
        }

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // التسجيل يُقفل ذمّةً ويُنشئ قيد خصم — بيد من يملك المال لا من يُدخل الطلبات.
        Role::where('name', 'admin')->first()?->givePermissionTo(self::PERMISSIONS);
        Role::where('name', 'accountant')->first()?->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'collected_total')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('collection_recorded_by');
                $table->dropColumn([
                    'collected_total', 'collection_note', 'collection_recorded_at', 'collection_entry_id',
                ]);
            });
        }

        if (Schema::hasTable('permissions')) {
            Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
