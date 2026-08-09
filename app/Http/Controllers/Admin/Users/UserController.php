<?php

namespace App\Http\Controllers\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryBusiness;
use App\Modules\Foundation\Services\UserAdminService;
use App\Modules\Shipping\Services\DeliveryBusinessSyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * إدارة المستخدمين/الموظّفين (Production) — متحكّم رفيع فوق UserAdminService.
 * الصلاحيات عبر middleware `can:settings.users.*` (routes). RTL + عربي/إنجليزي.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserAdminService $service) {}

    public function index(Request $request): View
    {
        $users = User::query()->with(['roles', 'branch'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $request->string('role'))))
            ->when($request->filled('department'), fn ($q) => $q->where('department', $request->string('department')))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->string('status') === 'active'))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->get(),
            'departments' => User::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
            'filters' => $request->only(['search', 'role', 'department', 'status']),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', $this->formData(new User));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->service->create($request->safe()->except('role'), $request->validated('role'));

        return redirect()->route('admin.users.index')->with('success', __('أُضيف المستخدم.'));
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', $this->formData($user->load('roles')));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->service->update($user, $request->safe()->except('role'), $request->validated('role'));

        return redirect()->route('admin.users.index')->with('success', __('حُدّث المستخدم.'));
    }

    public function destroy(User $user): RedirectResponse
    {
        // حماية: لا تحذف نفسك ولا آخر مدير.
        if ($user->id === auth()->id()) {
            return back()->with('error', __('لا يمكنك حذف حسابك.'));
        }
        if ($user->hasRole('admin') && User::role('admin')->count() <= 1) {
            return back()->with('error', __('لا يمكن حذف آخر مدير نظام.'));
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', __('حُذف المستخدم.'));
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $plain = $this->service->resetPassword($user);

        return back()->with('success', __('كلمة المرور المؤقّتة: :pw', ['pw' => $plain]));
    }

    public function toggleActive(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', __('لا يمكنك تعطيل حسابك.'));
        }
        $this->service->toggleActive($user);

        return back()->with('success', __('تم تحديث الحالة.'));
    }

    /** جلب/تحديث حسابات البزنس من شركة التوصيل (Opost) إلى القائمة المحلية. */
    public function syncDeliveryBusinesses(DeliveryBusinessSyncService $sync): RedirectResponse
    {
        $result = $sync->sync();

        if ($result['synced'] === 0) {
            return back()->with('error', __('لم تُجلب أي حسابات — تحقّق من ربط شركة التوصيل.'));
        }

        return back()->with('success', __('تمت مزامنة :n حساب بزنس من شركة التوصيل.', ['n' => $result['synced']]));
    }

    private function formData(User $user): array
    {
        return [
            'user' => $user,
            'roles' => Role::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
            // حسابات البزنس لدى شركة التوصيل (للقائمة المنسدلة).
            'deliveryBusinesses' => DeliveryBusiness::orderByDesc('is_active')->orderBy('name')->get(),
        ];
    }
}
