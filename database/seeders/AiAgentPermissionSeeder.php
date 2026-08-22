<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات الصندوق الموحّد ووكيل المبيعات.
 *
 * تُطابق هجرة `add_ai_agent_permissions` حرفًا بحرف: الهجرة لقاعدةٍ قائمة على
 * الإنتاج (حيث الأدوار موجودة)، والبذرة لتنصيبٍ جديد (حيث تُنشأ الأدوار بعد
 * الهجرات فلا تجد الهجرةُ ما تمنحه).
 *
 * والتقسيم مقصود: **الردّ** عملُ خدمةٍ يومي، و**تشغيل الوكيل أو إيقافه** قرارٌ
 * إداريّ، و**المعرفة البيعية** قرارُ محتوًى يحدّد ما يقوله النظام باسم الشركة.
 */
class AiAgentPermissionSeeder extends Seeder
{
    /** @var array<string, array<int, string>> */
    private array $grants = [
        'inbox.view' => ['admin', 'manager', 'sales_supervisor', 'sales'],
        'inbox.reply' => ['admin', 'manager', 'sales_supervisor', 'sales'],
        'inbox.assign' => ['admin', 'manager', 'sales_supervisor'],
        'ai_agent.handoff' => ['admin', 'manager', 'sales_supervisor', 'sales'],
        'ai_agent.toggle' => ['admin', 'manager'],
        'ai_agent.runs.view' => ['admin', 'manager'],
        'ai_agent.knowledge.view' => ['admin', 'manager', 'sales_supervisor'],
        'ai_agent.knowledge.manage' => ['admin', 'manager'],
    ];

    public function run(): void
    {
        foreach ($this->grants as $permission => $roles) {
            Permission::findOrCreate($permission, 'web');

            foreach ($roles as $roleName) {
                Role::where('name', $roleName)->first()?->givePermissionTo($permission);
            }
        }
    }
}
