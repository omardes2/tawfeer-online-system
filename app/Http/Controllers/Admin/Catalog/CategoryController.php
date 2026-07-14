<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Category::class);

        $categories = Category::query()->with('parent')->withCount('children')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('sort_order')->paginate(15)->withQueryString();

        return view('admin.catalog.categories.index', compact('categories'));
    }

    public function create(): View
    {
        $this->authorize('create', Category::class);

        return view('admin.catalog.categories.form', [
            'category' => new Category,
            'parents' => Category::orderBy('name')->get(),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', Category::class);
        $this->service->create($this->withIcon($request));

        return redirect()->route('admin.categories.index')->with('success', __('تم إنشاء الفئة بنجاح.'));
    }

    public function edit(Category $category): View
    {
        $this->authorize('update', $category);

        return view('admin.catalog.categories.form', [
            'category' => $category,
            'parents' => Category::whereKeyNot($category->id)->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);
        $this->service->update($category, $this->withIcon($request, $category));

        return redirect()->route('admin.categories.index')->with('success', __('تم تحديث الفئة بنجاح.'));
    }

    /**
     * يدمج رفع الأيقونة في بيانات الفئة: يخزّن الملف على قرص public ويحذف القديم عند الاستبدال.
     *
     * @return array<string, mixed>
     */
    private function withIcon(Request $request, ?Category $category = null): array
    {
        $data = collect($request->validated())->except('icon')->toArray();

        if ($request->hasFile('icon')) {
            if ($category?->image) {
                Storage::disk('public')->delete($category->image);
            }
            $data['image'] = $request->file('icon')->store('categories', 'public');
        }

        return $data;
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        try {
            $this->service->delete($category);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.categories.index')->with('success', __('تم حذف الفئة.'));
    }
}
