<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات الصندوق الموحّد ووكيل المبيعات.
 *
 * في الهجرة لا في البذرة: النشر يشغّل `migrate` وحده.
 *
 * والتقسيم مقصود: **الردّ** على الزبائن عملُ خدمةٍ يومي، بينما **إيقاف الوكيل
 * أو تشغيله** قرارٌ إداريّ، و**المعرفة البيعية** قرارُ محتوًى يحدّد ما يقوله
 * النظام باسم الشركة — فلا تُمنح الثلاثة لمن يُمنح إحداها.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const GRANTS = [
        // الصندوق: عرضٌ وردّ وإسناد.
        'inbox.view' => ['admin', 'manager', 'sales_supervisor', 'sales'],
        'inbox.reply' => ['admin', 'manager', 'sales_supervisor', 'sales'],
        'inbox.assign' => ['admin', 'manager', 'sales_supervisor'],
        // الوكيل: التحويل عملُ خدمة، والتشغيل/الإيقاف قرارٌ إداري.
        'ai_agent.handoff' => ['admin', 'manager', 'sales_supervisor', 'sales'],
        'ai_agent.toggle' => ['admin', 'manager'],
        'ai_agent.runs.view' => ['admin', 'manager'],
        // المعرفة البيعية: ما يقوله النظام باسم الشركة.
        'ai_agent.knowledge.view' => ['admin', 'manager', 'sales_supervisor'],
        'ai_agent.knowledge.manage' => ['admin', 'manager'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (self::GRANTS as $permission => $roles) {
            Permission::findOrCreate($permission, 'web');

            foreach ($roles as $role) {
                Role::where('name', $role)->first()?->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::whereIn('name', array_keys(self::GRANTS))->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
