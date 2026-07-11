<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * مستخدم المدير الافتراضي، مرتبط بالفرع الافتراضي وبدور admin.
 *
 * ملاحظة أمنية: كلمة المرور الافتراضية للتطوير فقط — تُغيَّر عند الإنتاج.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::default();

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@tawfeer.online'],
            [
                'name' => 'مدير النظام',
                'password' => Hash::make('password'),
                'branch_id' => $branch?->id,
                'is_active' => true,
            ],
        );

        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }
    }
}
