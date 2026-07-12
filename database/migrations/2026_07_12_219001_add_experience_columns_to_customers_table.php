<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول تجربة العميل (Phase 3.4 / ADR-035) — إضافية فقط، بلا إعادة تصميم (ADR-032).
 * جاهزية لمكافآت الميلاد/التسويق/التفضيلات مستقبلًا دون تغيير مخطط لاحق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('email');
            $table->string('preferred_locale', 5)->nullable()->after('birth_date');
            $table->foreignId('preferred_branch_id')->nullable()->after('preferred_locale')
                ->constrained('branches')->nullOnDelete();
            $table->json('communication_preferences')->nullable()->after('preferred_branch_id');
            $table->string('acquisition_source', 40)->nullable()->after('communication_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_branch_id');
            $table->dropColumn(['birth_date', 'preferred_locale', 'communication_preferences', 'acquisition_source']);
        });
    }
};
