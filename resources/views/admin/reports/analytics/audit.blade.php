<x-app-layout :title="__('تقارير التدقيق')">
    <x-admin.header
        :title="__('تقارير التدقيق')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), __('التدقيق') => null]" />

    <x-admin.report-filters :range="$range" />

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-admin.stat-card :label="__('إجمالي الأحداث')" :value="number_format($data['total'])" tone="blue" />
        <x-admin.stat-card :label="__('عدد المستخدمين النشطين')" :value="number_format($data['by_user']->count())" tone="green" />
        <x-admin.stat-card :label="__('أنواع الإجراءات')" :value="number_format($data['by_action']->count())" tone="amber" />
        <x-admin.stat-card :label="__('الكيانات المتأثّرة')" :value="number_format($data['by_entity']->count())" tone="gray" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('الأحداث حسب الإجراء') }}</h3>
            @if ($data['by_action']->isNotEmpty())
                <x-admin.chart type="bar" horizontal :height="280" :labels="$data['by_action']->pluck('action')" :datasets="[['label' => __('الأحداث'), 'data' => $data['by_action']->pluck('c')]]" />
            @else <x-admin.empty-state :title="__('لا توجد أحداث')" /> @endif
        </div>
        <x-admin.table :title="__('النشاط حسب المستخدم')">
            <thead><tr><th>{{ __('المستخدم') }}</th><th>{{ __('الأحداث') }}</th></tr></thead>
            <tbody>
                @forelse ($data['by_user'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums">{{ $r->c }}</td></tr>
                @empty <tr><td colspan="2" class="!p-0"><x-admin.empty-state :title="__('لا يوجد نشاط')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
    </div>

    <x-admin.table :title="__('آخر الأحداث')">
        <thead><tr><th>{{ __('التاريخ') }}</th><th>{{ __('المستخدم') }}</th><th>{{ __('الإجراء') }}</th><th>{{ __('الكيان') }}</th><th>IP</th></tr></thead>
        <tbody>
            @forelse ($data['recent'] as $r)
                <tr>
                    <td class="text-gray-500 text-xs whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('Y-m-d H:i') }}</td>
                    <td class="font-medium text-gray-800">{{ $r->user }}</td>
                    <td class="text-xs">{{ $r->action }}</td>
                    <td class="text-xs text-gray-500">{{ $r->auditable_type }}</td>
                    <td class="tabular-nums text-xs text-gray-400" dir="ltr">{{ $r->ip_address }}</td>
                </tr>
            @empty <tr><td colspan="5" class="!p-0"><x-admin.empty-state :title="__('لا توجد أحداث في هذه الفترة')" /></td></tr> @endforelse
        </tbody>
    </x-admin.table>

    <p class="mt-4 text-xs text-gray-400">{{ __('يشمل: نشاط المستخدمين، تغييرات الأسعار والمخزون والصلاحيات (عبر سجلّ التدقيق المركزي). سجلّ محاولات الدخول الفاشلة يتطلّب تفعيل تسجيل المصادقة.') }}</p>
</x-app-layout>
