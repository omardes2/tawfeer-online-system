<?php

namespace App\Modules\Foundation\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * إدارة مستخدمي/موظّفي النظام (Production). كل منطق الإنشاء/التحديث هنا (متحكّم رفيع).
 * كلمة المرور تُجزَّأ (cast hashed)؛ الأدوار عبر spatie؛ التدقيق تلقائي عبر تريتة Auditable.
 */
class UserAdminService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, ?string $role = null): User
    {
        return DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'department' => $data['department'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'delivery_business_id' => $data['delivery_business_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'password' => $data['password'] ?? Str::password(16),
                'email_verified_at' => now(), // موظّف يُنشئه المدير — مُوثَّق.
            ]);

            if ($role) {
                $user->syncRoles([$role]);
            }

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data, ?string $role = null): User
    {
        return DB::transaction(function () use ($user, $data, $role) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'department' => $data['department'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'delivery_business_id' => $data['delivery_business_id'] ?? null,
                'is_active' => $data['is_active'] ?? $user->is_active,
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();

            if ($role !== null) {
                $user->syncRoles([$role]);
            }

            return $user;
        });
    }

    /** توليد كلمة مرور مؤقّتة وإرجاعها للعرض مرّة واحدة (لا تُخزَّن نصًّا). */
    public function resetPassword(User $user): string
    {
        $plain = Str::password(12);
        $user->update(['password' => Hash::make($plain)]);

        return $plain;
    }

    public function toggleActive(User $user): User
    {
        $user->update(['is_active' => ! $user->is_active]);

        return $user;
    }
}
