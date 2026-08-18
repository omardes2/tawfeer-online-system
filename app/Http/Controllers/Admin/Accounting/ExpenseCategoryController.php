<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ExpenseCategoryRequest;
use App\Modules\Accounting\Models\ExpenseCategory;
use App\Modules\Accounting\Services\ExpenseCategoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * تصنيفات المصروفات — تعريفها من اللوحة وفتحُ حساباتها تلقائيًا.
 *
 * `store` تخدم مسارين: النموذج الكامل في شاشة التصنيفات، وطلبَ JSON من نافذة
 * «تصنيف جديد» داخل سند المصروف — فيُنشأ التصنيف ويعود للنافذة دون أن يغادر
 * المستخدم السند ويفقد ما أدخله فيه.
 */
class ExpenseCategoryController extends Controller
{
    public function __construct(private readonly ExpenseCategoryService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        return view('admin.accounting.expense_categories.index', [
            'categories' => ExpenseCategory::with('account')->ordered()->get(),
        ]);
    }

    public function store(ExpenseCategoryRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', ExpenseCategory::class);

        try {
            $category = $this->service->create($request->validated());
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first();

            return $request->wantsJson()
                ? response()->json(['message' => $message], 422)
                : back()->withInput()->with('error', $message);
        }

        if ($request->wantsJson()) {
            $category->load('account');

            return response()->json([
                'id' => $category->id,
                'name' => $category->name,
                'account_code' => $category->account?->code,
            ], 201);
        }

        return redirect()->route('admin.accounting.expense_categories.index')
            ->with('success', __('أُنشئ التصنيف وفُتح حسابه :code.', ['code' => $category->account?->code]));
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $this->service->update($category, $request->validated());

        return redirect()->route('admin.accounting.expense_categories.index')
            ->with('success', __('حُدّث التصنيف وحسابه.'));
    }

    public function destroy(ExpenseCategory $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        try {
            $this->service->delete($category);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.accounting.expense_categories.index')
            ->with('success', __('حُذف التصنيف وعُطّل حسابه.'));
    }
}
