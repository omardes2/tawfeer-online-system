<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حالات المحادثة الافتراضية (المتطلّب FR-INB-02).
 *
 * في الهجرة لا في البذرة: النشر يشغّل `migrate` وحده، وجدولُ حالاتٍ فارغ على
 * الإنتاج يعني صندوقًا بلا تصنيفٍ ومحادثاتٍ بلا حالة.
 */
return new class extends Migration
{
    /** @var array<int, array<string, mixed>> */
    private const STATUSES = [
        ['key' => 'new', 'name' => 'جديدة', 'color' => '#f59e0b', 'sort_order' => 1, 'is_default' => true],
        ['key' => 'open', 'name' => 'مفتوحة', 'color' => '#3b82f6', 'sort_order' => 2],
        ['key' => 'pending', 'name' => 'بانتظار الزبون', 'color' => '#8b5cf6', 'sort_order' => 3],
        ['key' => 'closed', 'name' => 'مغلقة', 'color' => '#22c55e', 'sort_order' => 4, 'is_final' => true],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('conversation_statuses')) {
            return;
        }

        foreach (self::STATUSES as $status) {
            DB::table('conversation_statuses')->updateOrInsert(
                ['key' => $status['key']],
                $status + [
                    'is_default' => $status['is_default'] ?? false,
                    'is_final' => $status['is_final'] ?? false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conversation_statuses')) {
            DB::table('conversation_statuses')
                ->whereIn('key', array_column(self::STATUSES, 'key'))->delete();
        }
    }
};
