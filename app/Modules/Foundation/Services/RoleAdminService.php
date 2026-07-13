<?php

namespace App\Modules\Foundation\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * إدارة الأدوار والصلاحيات (Production). الصلاحيات موجودة مسبقًا (مزروعة)؛ هذه الطبقة
 * تدير الأدوار (إنشاء/تعديل/نسخ/حذف آمن) وربط الصلاحيات. RBAC فقط (المبدأ 11).
 */
class RoleAdminService
{
    /** الأدوار المحميّة التي لا تُحذف/يُعاد تسميتها. */
    public const PROTECTED = ['admin'];

    /** @param array<int, string> $permissions */
    public function create(string $name, array $permissions): Role
    {
        $role = Role::findOrCreate($name, 'web');
        $role->syncPermissions($permissions);

        return $role;
    }

    /** @param array<int, string> $permissions */
    public function update(Role $role, string $name, array $permissions): Role
    {
        if (in_array($role->name, self::PROTECTED, true) && $role->name !== $name) {
            throw ValidationException::withMessages(['name' => __('لا يمكن إعادة تسمية هذا الدور.')]);
        }

        $role->update(['name' => $name]);
        $role->syncPermissions($permissions);

        return $role;
    }

    public function copy(Role $role, string $newName): Role
    {
        if (Role::where('name', $newName)->exists()) {
            throw ValidationException::withMessages(['name' => __('اسم الدور مستخدم.')]);
        }

        $copy = Role::create(['name' => $newName, 'guard_name' => 'web']);
        $copy->syncPermissions($role->permissions->pluck('name')->all());

        return $copy;
    }

    public function delete(Role $role): void
    {
        if (in_array($role->name, self::PROTECTED, true)) {
            throw ValidationException::withMessages(['role' => __('لا يمكن حذف دور نظامي محميّ.')]);
        }
        if ($role->users()->count() > 0) {
            throw ValidationException::withMessages(['role' => __('لا يمكن حذف دور مُسنَد لمستخدمين.')]);
        }

        $role->delete();
    }

    /**
     * الصلاحيات مجمّعة حسب الوحدة (الجزء الأول من المفتاح) لعرضها في مجموعات.
     *
     * @return Collection<string, Collection<int, Permission>>
     */
    public function grouped(): Collection
    {
        return Permission::orderBy('name')->get()
            ->groupBy(fn (Permission $p) => explode('.', $p->name)[0]);
    }
}
