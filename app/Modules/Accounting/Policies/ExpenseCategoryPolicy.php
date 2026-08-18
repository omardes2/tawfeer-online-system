<?php

namespace App\Modules\Accounting\Policies;

use App\Models\User;
use App\Modules\Accounting\Models\ExpenseCategory;

/**
 * التعريف صلاحية إدارية — لأنه يفتح حسابًا في دليل المحاسبة، لا مجرّد اسمٍ في
 * قائمة. والعرض لمن يُدخل المصروفات كي يختار.
 */
class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.expense_categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounting.expense_categories.manage');
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return $user->can('accounting.expense_categories.manage');
    }

    /** تصنيف النظام لا يُحذف مهما كانت الصلاحية — الخدمة تحرسه أيضًا. */
    public function delete(User $user, ExpenseCategory $category): bool
    {
        return $user->can('accounting.expense_categories.manage') && ! $category->is_system;
    }
}
