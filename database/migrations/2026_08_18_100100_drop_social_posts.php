<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * إزالة صفحة محتوى المنشورات (كان ADR-055) — بقرار المالك.
 *
 * كما في إزالة الطيّار: ملفّات الإنشاء حُذفت فالتنصيب الجديد لا يمرّ بها، وهذا
 * لأجل ما رُحّل فعلًا. والجدول يُحذف بما فيه — وهو محتوى مسوّدات لا سجلٌّ مالي
 * ولا أثرَ لطلبٍ أو قيد.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const PERMISSIONS = ['marketing.social.view', 'marketing.social.manage'];

    public function up(): void
    {
        Schema::dropIfExists('social_posts');

        if (Schema::hasTable('permissions')) {
            Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /** لا تراجع — الشيفرة نفسها حُذفت، واسترجاعها من تاريخ Git. */
    public function down(): void
    {
        // بلا أثر عمدًا.
    }
};
