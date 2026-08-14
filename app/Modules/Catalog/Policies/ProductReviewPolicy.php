<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\ProductReview;

/**
 * صلاحيات مراجعة التقييمات عبر RBAC (المبدأ 12، ADR-021).
 *
 * لا `create` هنا: التقييم يكتبه الزبون في المتجر لا مستخدم اللوحة.
 * `update` تعني الاعتماد أو الرفض.
 */
class ProductReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.reviews.view');
    }

    public function view(User $user, ProductReview $model): bool
    {
        return $user->can('catalog.reviews.view');
    }

    public function update(User $user, ProductReview $model): bool
    {
        return $user->can('catalog.reviews.update');
    }

    public function delete(User $user, ProductReview $model): bool
    {
        return $user->can('catalog.reviews.delete');
    }
}
