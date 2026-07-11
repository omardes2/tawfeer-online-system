<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// إضافة الأسس للمستخدمين: uuid (خارجي)، branch_id (تعدد الفروع)، هاتف، تفعيل، حذف ناعم.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->foreignId('branch_id')->nullable()->after('uuid')->constrained()->nullOnDelete();
            $table->string('phone')->nullable()->unique()->after('email');
            $table->boolean('is_active')->default(true)->after('phone');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['uuid', 'phone', 'is_active', 'last_login_at', 'deleted_at']);
        });
    }
};
