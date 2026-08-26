<x-app-layout :title="__('مسيّرات الرواتب')">
    <x-admin.header
        :title="__('مسيّرات الرواتب')"
        :description="__('توليد شهريّ ثم ترحيلٌ محاسبيّ ثم صرف.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الرواتب والموظفون') => route('admin.hr.employees.index'), __('مسيّرات الرواتب') => null]">
        <a href="{{ route('admin.hr.employees.index') }}" class="btn-secondary btn-sm">{{ __('الموظفون') }}</a>
    </x-admin.header>

    <x-admin.flash />

    @if ($withoutSalary > 0)
        <div class="admin-card admin-card-pad mb-5 border-s-4 border-amber-400 bg-amber-50 text-sm text-amber-800">
            {{ __(':c موظفًا بلا راتب مسجَّل — لن يدخلوا المسيّر.', ['c' => $withoutSalary]) }}
            <a href="{{ route('admin.hr.employees.index') }}" class="underline">{{ __('راجع القائمة') }}</a>
        </div>
    @endif

    @can('hr.payroll.manage')
        <form method="POST" action="{{ route('admin.hr.payroll.generate') }}"
              class="admin-card admin-card-pad mb-5 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('السنة') }}</label>
                <select name="period_year" class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @for ($y = $year; $y >= $year - 3; $y--)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('الشهر') }}</label>
                <select name="period_month" class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @for ($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" @selected($m === $month)>{{ str_pad((string) $m, 2, '0', STR_PAD_LEFT) }}</option>
                    @endfor
                </select>
            </div>
            <button type="submit" class="btn-primary btn-sm">{{ __('توليد المسيّر') }}</button>
            <p class="text-[11px] text-gray-400 basis-full">
                {{ __('التوليد يُنشئ مسودّةً تُراجَع وتُصحَّح — لا قيد حتى الترحيل. وإعادة التوليد تُعيد بناء بنود المسودّة.') }}
            </p>
        </form>
    @endcan

    <div class="admin-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('الرقم') }}</th>
                        <th>{{ __('الشهر') }}</th>
                        <th class="text-start">{{ __('الموظفون') }}</th>
                        <th class="text-start">{{ __('الاستحقاق') }}</th>
                        <th class="text-start">{{ __('الخصم') }}</th>
                        <th class="text-start">{{ __('الصافي') }}</th>
                        <th class="text-start">{{ __('نهاية الخدمة') }}</th>
                        <th>{{ __('الحالة') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td class="tabular-nums text-gray-600">{{ $run->number }}</td>
                            <td class="font-medium tabular-nums">{{ $run->periodLabel() }}</td>
                            <td class="text-start tabular-nums">{{ $run->lines_count }}</td>
                            <td class="text-start tabular-nums">{{ number_format((float) $run->total_earnings, 2) }}</td>
                            <td class="text-start tabular-nums text-rose-600">{{ number_format((float) $run->total_deductions, 2) }}</td>
                            <td class="text-start tabular-nums font-semibold">{{ number_format((float) $run->total_net, 2) }}</td>
                            <td class="text-start tabular-nums text-amber-700">{{ number_format((float) $run->total_eos, 2) }}</td>
                            <td>
                                <span @class([
                                    'inline-block rounded-full px-2 py-0.5 text-[11px] font-medium',
                                    'bg-gray-100 text-gray-600' => $run->status === 'draft',
                                    'bg-sky-100 text-sky-700' => $run->status === 'posted',
                                    'bg-emerald-100 text-emerald-700' => $run->status === 'paid',
                                    'bg-rose-100 text-rose-700' => $run->status === 'reversed',
                                ])>{{ $run->statusLabel() }}</span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.hr.payroll.show', $run) }}" class="btn-secondary btn-sm">{{ __('فتح') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-gray-400">{{ __('لا مسيّرات بعد.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $runs->links() }}</div>
</x-app-layout>
