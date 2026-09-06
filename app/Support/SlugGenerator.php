<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * توليد slug فريد لكيان، مع احترام الحذف الناعم وتجاهل السجلّ الحالي عند التعديل.
 * منطق أعمال يُستدعى من طبقة الخدمات (خارج المتحكمات).
 */
class SlugGenerator
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function make(string $modelClass, string $source, ?int $ignoreId = null, string $column = 'slug'): string
    {
        $base = Str::slug($source);

        if ($base === '') {
            $base = 'item';
        }

        $slug = $base;
        $suffix = 2;

        while (self::exists($modelClass, $column, $slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private static function exists(string $modelClass, string $column, string $slug, ?int $ignoreId): bool
    {
        $query = $modelClass::query()->where($column, $slug);

        /*
            اشمل المحذوفة ناعمًا: قيد التفرّد في قاعدة البيانات يشمل الصفّ
            المحذوف ناعمًا، فسلَّةٌ لا تراه تُعطي slug مأخوذًا ويسقط الإدخال
            بـ«Duplicate entry» — وهو خطأ ٥٠٠ لا رسالةَ تحقّق.

            والفحص بالتريتة لا بـ`method_exists`: `withTrashed()` ليست دالّةً على
            النموذج، بل يُضيفها نطاقُ الحذف الناعم إلى **بانِي الاستعلام**. فكان
            الشرط لا يتحقّق أبدًا ولا تُشمل المحذوفة قطّ — والتعليق يقول عكسَ ما
            يفعل الكود.
        */
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}
