<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// المستودع الافتراضي للفرع — يُضاف بعد إنشاء warehouses (PHASE_2_DESIGN §1، ترتيب الاعتماديات).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('default_warehouse_id')->nullable()->after('default_currency_id')
                ->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_warehouse_id');
        });
    }
};
