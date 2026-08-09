<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ربط المستخدم بحساب بزنس لدى شركة التوصيل: تُدخَل طرود طلباته تحت هذا البزنس.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('delivery_business_id')->nullable()->after('branch_id')
                ->constrained('delivery_businesses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_business_id');
        });
    }
};
