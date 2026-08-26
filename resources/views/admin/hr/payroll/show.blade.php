<x-app-layout :title="__('مسيّر :p', ['p' => $run->periodLabel()])">
    <x-admin.header
        :title="__('مسيّر رواتب :p', ['p' => $run->periodLabel()])"
        :description="$run->number.' · '.$run->statusLabel()"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('مسيّرات الرواتب') => route('admin.hr.payroll.index'), $run->periodLabel() => null]">
        @can('hr.payroll.manage')
            @if ($run->isDraft())
                <form method="POST" action="{{ route('admin.hr.payroll.generate') }}" class="inline">
                    @csrf
                    <input type="hidden" name="period_year" value="{{ $run->period_year }}">
                    <input type="hidden" name="period_month" value="{{ $run->period_month }}">
                    <button class="btn-secondary btn-sm">{{ __('إعادة التوليد') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.hr.payroll.post', $run) }}" class="inline"
                      onsubmit="return confirm('{{ __('ترحيل المسيّر؟ سيُنشأ قيدٌ محاسبيّ لا يُحذف.') }}')">
                    @csrf
                    <button class="btn-primary btn-sm">{{ __('ترحيل') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.hr.payroll.destroy', $run) }}" class="inline"
                      onsubmit="return confirm('{{ __('حذف المسودّة؟') }}')">
                    @csrf @method('DELETE')
                    <button class="btn-danger btn-sm">{{ __('حذف') }}</button>
                </form>
            @elseif ($run->status !== 'reversed')
                <form method="POST" action="{{ route('admin.hr.payroll.reverse', $run) }}" class="inline"
                      onsubmit="return confirm('{{ __('عكس المسيّر بقيدٍ عاكس؟') }}')">
                    @csrf
                    <button class="btn-danger btn-sm">{{ __('عكس المسيّر') }}</button>
                </form>
            @endif
        @endcan
    </x-admin.header>

    <x-admin.flash />

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-5">
        <x-admin.stat-card :label="__('إجمالي الاستحقاق')" :value="$run->total_earnings" money tone="blue" />
        <x-admin.stat-card :label="__('إجمالي الخصم')" :value="$run->total_deductions" money tone="red"
                           :hint="__('إجازات بلا راتب وخصومات')" />
        <x-admin.stat-card :label="__('صافي المسيّر')" :value="$run->total_net" money tone="green"
                           :hint="__('هو المصروف المُرحَّل')" />
        <x-admin.stat-card :label="__('مخصّص نهاية الخدمة')" :value="$run->total_eos" money tone="amber"
                           :hint="__('قيدٌ مستقلّ')" />
    </div>

    {{-- أثر الترحيل مكتوبًا: من يضغط «ترحيل» يجب أن يعرف ما سيُقيَّد. --}}
    <div class="admin-card admin-card-pad mb-5 text-sm text-gray-600 leading-7">
        <h3 class="font-semibold text-gray-800 mb-2">{{ __('القيود') }}</h3>
        <ul class="list-disc ps-5 space-y-1">
            <li>{{ __('الرواتب: مدين «مصروف الرواتب والأجور ٥٢٠٠» / دائن «رواتب مستحقة ٢٢٠٠» بصافي المسيّر.') }}</li>
            <li>{{ __('نهاية الخدمة: مدين «مصروف مكافأة نهاية الخدمة ٥٢١٠» / دائن «مخصّص مكافأة نهاية الخدمة ٢٢١٠».') }}</li>
            <li>{{ __('الصرف: سند صرفٍ لكل موظف — مدين «رواتب مستحقة ٢٢٠٠» / دائن الخزينة.') }}</li>
            <li>{{ __('المصروف هو الصافي لا الاستحقاق: ما خُصم لم تستحقّه الذمّة أصلًا، فلا يُقيَّد دَينًا لأحد.') }}</li>
            @if ($run->journalEntry)
                <li class="text-emerald-700">{{ __('قيد الرواتب: :n', ['n' => $run->journalEntry->number ?? $run->journal_entry_id]) }}</li>
            @endif
        </ul>
    </div>

    <form method="POST" action="{{ route('admin.hr.payroll.pay', $run) }}"
          x-data="{ all: false }" onsubmit="return confirm('{{ __('صرف الرواتب المحدَّدة من الخزينة؟') }}')">
        @csrf

        <div class="admin-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="admin-table">
                    <thead>
                        <tr>
                            @if ($run->isPosted() && $run->status !== 'reversed')
                                <th class="w-8">
                                    <input type="checkbox" x-model="all"
                                           @change="$root.querySelectorAll('input[name=&quot;lines[]&quot;]:not(:disabled)').forEach(c => c.checked = all)"
                                           class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                </th>
                            @endif
                            <th>{{ __('الموظف') }}</th>
                            <th class="text-start">{{ __('الأساسيّ') }}</th>
                            <th class="text-start">{{ __('البدلات') }}</th>
                            <th class="text-start">{{ __('إضافات') }}</th>
                            <th class="text-start">{{ __('إجازة بلا راتب') }}</th>
                            <th class="text-start">{{ __('خصومات') }}</th>
                            <th class="text-start">{{ __('الصافي') }}</th>
                            <th class="text-start">{{ __('نهاية الخدمة') }}</th>
                            <th>{{ __('الصرف') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $line)
                            <tr>
                                @if ($run->isPosted() && $run->status !== 'reversed')
                                    <td>
                                        <input type="checkbox" name="lines[]" value="{{ $line->id }}"
                                               @disabled($line->isPaid() || (float) $line->net <= 0)
                                               class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 disabled:opacity-40">
                                    </td>
                                @endif
                                <td class="font-medium text-gray-800">
                                    <a href="{{ route('admin.hr.employees.show', $line->employee_profile_id) }}"
                                       class="text-emerald-700 hover:underline">{{ $line->profile?->user?->name ?? __('—') }}</a>
                                    @if ($line->note)<span class="block text-[11px] text-gray-400">{{ $line->note }}</span>@endif
                                </td>
                                <td class="text-start tabular-nums">{{ number_format((float) $line->basic_salary, 2) }}</td>
                                <td class="text-start tabular-nums text-gray-500">{{ number_format((float) $line->allowances, 2) }}</td>
                                <td class="text-start tabular-nums text-emerald-700">{{ number_format((float) $line->other_additions, 2) }}</td>
                                <td class="text-start tabular-nums text-rose-600">
                                    {{ number_format((float) $line->unpaid_leave_amount, 2) }}
                                    @if ((float) $line->unpaid_leave_days > 0)
                                        <span class="block text-[11px] text-gray-400">{{ number_format((float) $line->unpaid_leave_days, 1) }} {{ __('يوم') }}</span>
                                    @endif
                                </td>
                                <td class="text-start tabular-nums text-rose-600">{{ number_format((float) $line->other_deductions, 2) }}</td>
                                <td class="text-start tabular-nums font-bold">{{ number_format((float) $line->net, 2) }}</td>
                                <td class="text-start tabular-nums text-amber-700">{{ number_format((float) $line->eos_provision, 2) }}</td>
                                <td class="text-sm">
                                    @if ($line->isPaid())
                                        <span class="text-emerald-700">{{ __('مدفوع') }}</span>
                                        <span class="block text-[11px] text-gray-400">{{ $line->voucher?->number }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="py-10 text-center text-gray-400">
                                    {{ __('لا بنود — لا موظف نشط بعقدٍ ساري في هذا الشهر.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @can('hr.payroll.manage')
            {{-- الصرف بعد الترحيل وحده: النقدية لا تخرج قبل إثبات الالتزام. --}}
            @if ($run->isPosted() && $run->status !== 'reversed' && $run->unpaidTotal() > 0)
                <div class="admin-card admin-card-pad mt-5 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('الخزينة') }}</label>
                        <select name="treasury_id" required
                                class="rounded-lg border-gray-300 text-sm min-w-[12rem] focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach ($treasuries as $t)
                                <option value="{{ $t->id }}">{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn-primary btn-sm">{{ __('صرف المحدَّد') }}</button>
                    <p class="text-[11px] text-gray-400 basis-full">
                        {{ __('غير المدفوع بعد: :v — سندُ صرفٍ لكل موظف، فيُصرف من شاء نقدًا ومن شاء بنكًا.', ['v' => number_format($run->unpaidTotal(), 2)]) }}
                    </p>
                </div>
            @endif
        @endcan
    </form>

    @can('hr.payroll.manage')
        @if ($run->isDraft() && $lines->isNotEmpty())
            <div class="admin-card overflow-hidden mt-5">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">{{ __('إضافات وخصومات يدوية') }}</h3>
                    <p class="text-[11px] text-gray-400 mt-0.5">{{ __('في المسودّة وحدها — بعد الترحيل يُصحَّح بالعكس.') }}</p>
                </div>
                <div class="divide-y divide-gray-100">
                    @foreach ($lines as $line)
                        <form method="POST" action="{{ route('admin.hr.payroll.lines.update', [$run, $line]) }}"
                              class="p-4 grid gap-3 sm:grid-cols-5 items-end">
                            @csrf @method('PUT')
                            <div class="text-sm font-medium text-gray-700 self-center">{{ $line->profile?->user?->name }}</div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">{{ __('إضافة') }}</label>
                                <input type="number" step="0.01" min="0" name="other_additions" value="{{ (float) $line->other_additions }}"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">{{ __('خصم') }}</label>
                                <input type="number" step="0.01" min="0" name="other_deductions" value="{{ (float) $line->other_deductions }}"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">{{ __('ملاحظة') }}</label>
                                <input type="text" name="note" maxlength="255" value="{{ $line->note }}"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <button type="submit" class="btn-secondary btn-sm">{{ __('حفظ') }}</button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif
    @endcan
</x-app-layout>
