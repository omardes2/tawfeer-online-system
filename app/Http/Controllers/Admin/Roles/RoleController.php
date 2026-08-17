<?php

namespace App\Http\Controllers\Admin\Roles;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Services\RoleAdminService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * إدارة الأدوار والصلاحيات (Production) — متحكّم رفيع فوق RoleAdminService.
 * الصلاحيات عبر middleware `can:settings.roles.*`. RTL + عربي/إنجليزي.
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleAdminService $service) {}

    public function index(Request $request): View
    {
        $roles = Role::query()->withCount(['permissions', 'users'])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')->get();

        return view('admin.roles.index', ['roles' => $roles, 'search' => $request->input('search')]);
    }

    public function create(): View
    {
        return view('admin.roles.form', [
            'role' => new Role,
            'groups' => $this->service->grouped(),
            'unused' => $this->service->unusedPermissions(),
            'assigned' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRole($request);
        $this->service->create($data['name'], $data['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', __('أُضيف الدور.'));
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', [
            'role' => $role,
            'groups' => $this->service->grouped(),
            'unused' => $this->service->unusedPermissions(),
            'assigned' => $role->permissions->pluck('name')->all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validateRole($request, $role);
        $this->service->update($role, $data['name'], $data['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', __('حُدّث الدور.'));
    }

    public function copy(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:roles,name']]);
        $this->service->copy($role, $data['name']);

        return redirect()->route('admin.roles.index')->with('success', __('نُسِخ الدور.'));
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->service->delete($role);

        return redirect()->route('admin.roles.index')->with('success', __('حُذف الدور.'));
    }

    /** @return array<string, mixed> */
    private function validateRole(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('roles', 'name')->ignore($role?->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ], [], ['name' => __('اسم الدور')]);
    }
}
