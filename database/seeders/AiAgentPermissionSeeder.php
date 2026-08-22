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
 * والتوزيع المصمَّم: **الردّ** عملُ خدمةٍ يومي، و**تشغيل الوكيل أو إيقافه** قرارٌ
 * إداريّ، و**المعرفة البيعية** قرارُ محتوًى يحدّد ما يقوله النظام باسم الشركة.
 *
 * لكنّه **محصورٌ بمدير النظام في مرحلة التجربة**: وكيلٌ يحادث الزبائن باسم
 * الشركة لا يُفتح لفريقٍ قبل أن يُجرَّب. والتوزيع المصمَّم محفوظٌ في `down` من
 * هجرة `restrict_new_features_to_admin_during_trial`، ويُعاد بمنح الصلاحيات
 * من شاشة الأدوار بعد الاعتماد.
 */
class AiAgentPermissionSeeder extends Seeder
{
    /** @var array<string, array<int, string>> */
    private array $grants = [
        'inbox.view' => ['admin'],
        'inbox.reply' => ['admin'],
        'inbox.assign' => ['admin'],
        'ai_agent.handoff' => ['admin'],
        'ai_agent.toggle' => ['admin'],
        'ai_agent.runs.view' => ['admin'],
        'ai_agent.knowledge.view' => ['admin'],
        'ai_agent.knowledge.manage' => ['admin'],
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
