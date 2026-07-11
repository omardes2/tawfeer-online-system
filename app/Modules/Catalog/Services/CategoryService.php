<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * منطق أعمال الفئات (خارج المتحكمات): توليد slug، منع الدورات الشجرية، الحذف الآمن.
 */
class CategoryService
{
    public function create(array $data): Category
    {
        return DB::transaction(function () use ($data) {
            $data['slug'] = $this->resolveSlug($data);

            return Category::create($data);
        });
    }

    public function update(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            if (array_key_exists('parent_id', $data)) {
                $this->guardAgainstCycle($category, $data['parent_id']);
            }

            if (isset($data['name']) || isset($data['slug'])) {
                $data['slug'] = $this->resolveSlug($data, $category);
            }

            $category->update($data);

            return $category;
        });
    }

    public function delete(Category $category): void
    {
        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => __('لا يمكن حذف فئة تحتوي على فئات فرعية.'),
            ]);
        }

        $category->delete();
    }

    private function resolveSlug(array $data, ?Category $category = null): string
    {
        $source = $data['slug'] ?? $data['name'] ?? ($category?->name ?? '');

        return SlugGenerator::make(Category::class, $source, $category?->id);
    }

    /**
     * منع أن يصبح الأب هو الفئة نفسها أو أحد أحفادها (دورة شجرية).
     */
    private function guardAgainstCycle(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $category->id) {
            throw ValidationException::withMessages(['parent_id' => __('لا يمكن أن تكون الفئة أبًا لنفسها.')]);
        }

        $ancestorId = $parentId;
        while ($ancestorId !== null) {
            if ($ancestorId === $category->id) {
                throw ValidationException::withMessages(['parent_id' => __('لا يمكن نقل الفئة إلى أحد فروعها.')]);
            }
            $ancestorId = Category::whereKey($ancestorId)->value('parent_id');
        }
    }
}
