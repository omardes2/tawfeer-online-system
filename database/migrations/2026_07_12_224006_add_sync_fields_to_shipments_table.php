<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول مزامنة إضافية (Phase 4.3 / ADR-039): عدّاد المحاولات وآخر خطأ لإعادة المحاولة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedInteger('sync_attempts')->default(0)->after('sync_status');
            $table->string('sync_error', 500)->nullable()->after('sync_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['sync_attempts', 'sync_error']);
        });
    }
};
