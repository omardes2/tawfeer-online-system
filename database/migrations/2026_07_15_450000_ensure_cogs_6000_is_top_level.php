<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ضمان أن حساب «تكلفة البضاعة المباعة 6000» حساب رئيسي (بلا أب) وليس فرعيًا تحت
 * المصروفات 5000. idempotent — إن كان رئيسيًا سلفًا لا يغيّر شيئًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->where('code', '6000')
            ->whereNotNull('parent_id')
            ->update(['parent_id' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // لا عكس — لا نعيده فرعيًا.
    }
};
