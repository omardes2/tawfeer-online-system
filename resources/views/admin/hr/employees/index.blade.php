<x-app-layout :title="__('الرواتب والموظفون')">
    <x-admin.header
        :title="__('الرواتب والموظفون')"
        :description="__('ملفّات الموظفين ورواتبهم وإجازاتهم ومستحقّاتهم.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الموظفون والعمولات') => null, __('الرواتب والموظفون') => null]">
        @can('hr.payroll.view')
            <a href="{{ route('admin.hr.payroll.index') }}" class="btn-secondary btn-sm">{{ __('مسيّرات الرواتب') }}</a>
        @endcan
        @can('hr.employees.manage')
            <a href="{{ route('admin.hr.employees.create') }}" class="btn-primary btn-sm">{{ __('موظف جديد') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-5">
        <x-admin.stat-card :label="__('عدد الموظفين')" :value="$totals['headcount']" tone="blue" />
        <x-admin.stat-card :label="__('الرواتب الشهرية')" :value="$totals['monthly']" money tone="gray"
                           :hint="__('الأساسيّ والبدلات بالعقود السارية')" />
        <x-admin.stat-card :label="__('مخصّص نهاية الخدمة')" :value="$totals['eos']" money tone="amber"
                           :hint="__('التزامٌ متراكم على الشركة')" />
        {{-- من بلا عقدٍ لا يدخل المسيّر. يُعدّ هنا كي يُرى قبل الترحيل لا بعده. --}}
        <x-admin.stat-card :label="__('بلا راتب مسجَّل')" :value="$totals['without_salary']"
                           :tone="$totals['without_salary'] > 0 ? 'red' : 'green'"
                           :hint="__('لا يدخلون مسيّر الرواتب')" />
    </div>

    <form method="GET" class="admin-card admin-card-pad mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('الحالة') }}</label>
            <select name="status" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm min-w-[10rem] focus:border-emerald-500 focus:ring-emerald-500">
                <option value="active" @selected($status === 'active')>{{ __('على رأس العمل') }}</option>
                <option value="ended" @selected($status === 'ended')>{{ __('انتهت خدمته') }}</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('سنة الإجازات') }}</label>
            <select name="year" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                @for ($y = (int) today()->year; $y >= (int) today()->year - 4; $y--)
                    <option value="{{ $y }}" @selected($year === $y)>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('بحث بالاسم') }}</label>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('اسم الموظف') }}"
                   class="rounded-lg border-gray-300 text-sm min-w-[12rem] focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <button type="submit" class="btn-secondary btn-sm">{{ __('تطبيق') }}</button>
    </form>

    <div class="admin-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('الموظف') }}</th>
                        <th>{{ __('المسمّى') }}</th>
                        <th>{{ __('تاريخ التعيين') }}</th>
                        <th class="text-start">{{ __('الراتب الأساسيّ') }}</th>
                        <th class="text-start">{{ __('البدلات') }}</th>
                        <th class="text-start">{{ __('الإجماليّ') }}</th>
                        <th class="text-start">{{ __('رصيد الإجازة') }}</th>
                        <th class="text-start">{{ __('نهاية الخدمة') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php $p = $r['profile']; @endphp
                        <tr>
                            <td class="font-medium text-gray-800">
                                <a href="{{ route('admin.hr.employees.show', $p) }}" class="text-emerald-700 hover:underline">
                                    {{ $p->user?->name ?? __('—') }}
                                </a>
                                @if ($p->branch)
                                    <span class="block text-[11px] text-gray-400">{{ $p->branch->name }}</span>
                                @endif
                            </td>
                            <td class="text-gray-600">{{ $p->user?->job_title ?: '—' }}</td>
                            <td class="text-gray-600 tabular-nums">{{ $p->hire_date->toDateString() }}</td>

                            @if ($r['basic'] === null)
                                {{-- بلا عقدٍ ساري: يُقال صراحةً لا يُعرض صفرٌ يُقرأ راتبًا. --}}
                                <td colspan="3" class="text-rose-600 text-sm">{{ __('لا راتب مسجَّل — لن يدخل المسيّر') }}</td>
                            @else
                                <td class="text-start tabular-nums">{{ number_format($r['basic'], 2) }}</td>
                                <td class="text-start tabular-nums text-gray-500">{{ number_format($r['allowances'], 2) }}</td>
                                <td class="text-start tabular-nums font-semibold">{{ number_format($r['gross'], 2) }}</td>
                            @endif

                            <td class="text-start tabular-nums {{ $r['leave_remaining'] < 0 ? 'text-rose-600 font-semibold' : 'text-gray-700' }}">
                                {{ number_format($r['leave_remaining'], 1) }}
                                <span class="text-[11px] text-gray-400">{{ __('يوم') }}</span>
                            </td>
                            <td class="text-start tabular-nums text-amber-700">{{ number_format($r['eos'], 2) }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.hr.employees.show', $p) }}" class="btn-secondary btn-sm">{{ __('الملفّ') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-10 text-center text-gray-400">{{ __('لا موظفين في هذه القائمة.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
