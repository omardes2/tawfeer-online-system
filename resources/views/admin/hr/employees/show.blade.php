<x-app-layout :title="$employee->user?->name ?? __('ملفّ الموظف')">
    <x-admin.header
        :title="$employee->user?->name ?? __('ملفّ الموظف')"
        :description="trim(($employee->user?->job_title ?? '').' · '.__('تعيين :d', ['d' => $employee->hire_date->toDateString()]), ' ·')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الرواتب والموظفون') => route('admin.hr.employees.index'), ($employee->user?->name ?? '—') => null]">
        @can('hr.employees.manage')
            <a href="{{ route('admin.hr.employees.edit', $employee) }}" class="btn-secondary btn-sm">{{ __('تعديل الملفّ') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    {{--
        نوعُ التعاقد يحكم الاستحقاقات، فيُقال في أعلى الملفّ لا في حاشية: من
        يفتح الصفحة ويرى الإجازة صفرًا يجب أن يعرف السبب قبل أن يظنّه خللًا.
    --}}
    @unless ($employee->accruesBenefits())
        <div class="admin-card admin-card-pad mb-5 border-s-4 border-amber-400 bg-amber-50 text-sm text-amber-800">
            {{ __('تعاقدٌ :t — أجرٌ مقابل عمل: لا رصيد إجازةٍ سنوية ولا مكافأة نهاية خدمة.', [
                't' => ['part_time' => __('بدوام جزئي'), 'contract' => __('بعقد')][$employee->employment_type] ?? $employee->employment_type,
            ]) }}
        </div>
    @endunless

    @if ($employee->status === 'ended')
        <div class="admin-card admin-card-pad mb-5 border-s-4 border-gray-400 bg-gray-50 text-sm text-gray-700">
            {{ __('انتهت خدمته في :d — لا يدخل المسيّرات ولا يتراكم له مخصّص.', ['d' => $employee->end_date?->toDateString()]) }}
        </div>
    @endif

    {{-- الأرقام الأربعة التي يُسأل عنها الموظف. --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-5">
        <x-admin.stat-card :label="__('الراتب الشهريّ')" :value="$currentSalary?->gross() ?? 0" money tone="blue"
                           :hint="$currentSalary ? __('أساسيّ :b + بدلات :a', ['b' => number_format((float) $currentSalary->basic_salary, 2), 'a' => number_format((float) $currentSalary->allowances, 2)]) : __('لا راتب مسجَّل')" />
        <x-admin.stat-card :label="__('رصيد الإجازة :y', ['y' => $year])"
                           :value="$employee->accruesBenefits() ? number_format($leave['remaining'], 1).' '.__('يوم') : '—'"
                           :tone="! $employee->accruesBenefits() ? 'gray' : ($leave['remaining'] < 0 ? 'red' : 'green')"
                           :hint="$employee->accruesBenefits()
                               ? __('المستحقّ :e · المأخوذ :t', ['e' => $leave['entitlement'], 't' => $leave['taken']])
                               : __('لا إجازة سنوية لهذا التعاقد')" />
        <x-admin.stat-card :label="__('مخصّص نهاية الخدمة')"
                           :value="$employee->accruesBenefits() || $eosBalance != 0 ? $eosBalance : '—'"
                           :money="$employee->accruesBenefits() || $eosBalance != 0"
                           :tone="$employee->accruesBenefits() ? 'amber' : 'gray'"
                           :hint="$employee->accruesBenefits()
                               ? __(':m شهر خدمة', ['m' => number_format($serviceMonths, 1)])
                               : __('لا مكافأة نهاية خدمة لهذا التعاقد')" />
        <x-admin.stat-card :label="__('رصيد العمولات')" :value="$commissions['outstanding'] ?? 0" money tone="gray"
                           :hint="__('للاطّلاع — تُصرف من شاشة العمولات')" />
    </div>

    <div class="grid gap-5 lg:grid-cols-2">

        {{-- ① الرواتب: تاريخٌ لا رقمٌ واحد. --}}
        <div class="admin-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-800">{{ __('الرواتب') }}</h3>
                <span class="text-[11px] text-gray-400">{{ __('الساري هو أحدث تاريخ سريان') }}</span>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('سريان من') }}</th>
                        <th class="text-start">{{ __('أساسيّ') }}</th>
                        <th class="text-start">{{ __('بدلات') }}</th>
                        <th class="text-start">{{ __('الإجماليّ') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($employee->salaries as $s)
                        <tr class="{{ $currentSalary && $s->id === $currentSalary->id ? 'bg-emerald-50/60 font-semibold' : '' }}">
                            <td class="tabular-nums">{{ $s->effective_from->toDateString() }}</td>
                            <td class="text-start tabular-nums">{{ number_format((float) $s->basic_salary, 2) }}</td>
                            <td class="text-start tabular-nums text-gray-500">{{ number_format((float) $s->allowances, 2) }}</td>
                            <td class="text-start tabular-nums">{{ number_format($s->gross(), 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('لا راتب مسجَّل بعد.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>

            @can('hr.employees.manage')
                <form method="POST" action="{{ route('admin.hr.employees.salaries.store', $employee) }}"
                      class="p-4 border-t border-gray-100 grid gap-3 sm:grid-cols-4 items-end bg-gray-50/50">
                    @csrf
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('سريان من') }}</label>
                        <input type="date" name="effective_from" required value="{{ today()->startOfMonth()->toDateString() }}"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('الأساسيّ') }}</label>
                        <input type="number" step="0.01" min="0" name="basic_salary" required
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('البدلات') }}</label>
                        <input type="number" step="0.01" min="0" name="allowances" value="0"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <button type="submit" class="btn-primary btn-sm">{{ __('تسجيل') }}</button>
                </form>
            @endcan
        </div>

        {{-- ② الإجازات --}}
        <div class="admin-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-800">{{ __('الإجازات :y', ['y' => $year]) }}</h3>
                <span class="text-[11px] {{ $employee->accruesBenefits() ? 'text-gray-400' : 'text-amber-600' }}">
                    {{ $employee->accruesBenefits()
                        ? __('بلا راتب :u · مرضية :s', ['u' => $leave['unpaid'], 's' => $leave['sick']])
                        : __('لا رصيد سنويّ — التسجيل للسجلّ وخصمِ ما بلا راتب') }}
                </span>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('النوع') }}</th>
                        <th>{{ __('من') }}</th>
                        <th>{{ __('إلى') }}</th>
                        <th class="text-start">{{ __('أيام') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($leaves as $l)
                        <tr>
                            <td>{{ $l->label() }}</td>
                            <td class="tabular-nums text-gray-600">{{ $l->from_date->toDateString() }}</td>
                            <td class="tabular-nums text-gray-600">{{ $l->to_date->toDateString() }}</td>
                            <td class="text-start tabular-nums">{{ number_format((float) $l->days, 1) }}</td>
                            <td class="text-end">
                                @can('hr.employees.manage')
                                    <form method="POST" action="{{ route('admin.hr.employees.leaves.destroy', [$employee, $l]) }}"
                                          onsubmit="return confirm('{{ __('حذف هذه الإجازة؟') }}')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-rose-600 hover:underline">{{ __('حذف') }}</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('لا إجازات في هذه السنة.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>

            @can('hr.employees.manage')
                <form method="POST" action="{{ route('admin.hr.employees.leaves.store', $employee) }}"
                      class="p-4 border-t border-gray-100 grid gap-3 sm:grid-cols-5 items-end bg-gray-50/50">
                    @csrf
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('النوع') }}</label>
                        <select name="kind" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="annual">{{ __('سنوية') }}</option>
                            <option value="unpaid">{{ __('بلا راتب') }}</option>
                            <option value="sick">{{ __('مرضية') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('من') }}</label>
                        <input type="date" name="from_date" required
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('إلى') }}</label>
                        <input type="date" name="to_date" required
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('أيام') }}</label>
                        <input type="number" step="0.5" min="0.5" name="days" required
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <button type="submit" class="btn-primary btn-sm">{{ __('تسجيل') }}</button>
                </form>
                <p class="px-4 pb-3 text-[11px] text-gray-400">
                    {{ __('«بلا راتب» تُخصَم تلقائيًّا من مسيّر شهرها. و«سنوية» تُنقص الرصيد ولا تمسّ الراتب.') }}
                </p>
            @endcan
        </div>

        {{-- ③ مخصّص نهاية الخدمة --}}
        <div class="admin-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100">
                <h3 class="font-semibold text-gray-800">{{ __('مكافأة نهاية الخدمة') }}</h3>
                <p class="text-[11px] mt-0.5 {{ $employee->accruesBenefits() ? 'text-gray-400' : 'text-amber-600' }}">
                    {{ $employee->accruesBenefits()
                        ? __('شهرٌ عن كل سنة خدمة — يتراكم مع كل مسيّر بمقدار الراتب الأساسيّ ÷ ١٢.')
                        : __('لا تراكم لهذا التعاقد. ويبقى الدفتر ظاهرًا لحركاتٍ سابقة إن وُجدت.') }}
                </p>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('التاريخ') }}</th>
                        <th>{{ __('البيان') }}</th>
                        <th class="text-start">{{ __('المبلغ') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($eosEntries as $entry)
                        <tr>
                            <td class="tabular-nums text-gray-600">{{ $entry->entry_date->toDateString() }}</td>
                            <td>
                                {{ $entry->label() }}
                                @if ($entry->note)<span class="block text-[11px] text-gray-400">{{ $entry->note }}</span>@endif
                                @if ($entry->voucher)<span class="block text-[11px] text-gray-400">{{ $entry->voucher->number }}</span>@endif
                            </td>
                            <td class="text-start tabular-nums {{ (float) $entry->amount < 0 ? 'text-rose-600' : 'text-emerald-700' }}">
                                {{ number_format((float) $entry->amount, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-6 text-center text-gray-400">{{ __('لا حركات بعد — تبدأ مع أوّل مسيّر مُرحَّل.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="bg-amber-50 font-bold text-amber-800">
                        <td colspan="2">{{ __('الرصيد') }}</td>
                        <td class="text-start tabular-nums">{{ number_format($eosBalance, 2) }}</td>
                    </tr>
                </tfoot>
            </table>

            @can('hr.payroll.manage')
                <div class="p-4 border-t border-gray-100 bg-gray-50/50 space-y-4">
                    <form method="POST" action="{{ route('admin.hr.employees.eos.settle', $employee) }}"
                          class="grid gap-3 sm:grid-cols-4 items-end"
                          onsubmit="return confirm('{{ __('صرف المكافأة نقدًا من الخزينة؟') }}')">
                        @csrf
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('مبلغ التصفية') }}</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required max="{{ $eosBalance }}"
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('الخزينة') }}</label>
                            <select name="treasury_id" required class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                @foreach ($treasuries as $t)
                                    <option value="{{ $t->id }}">{{ $t->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('ملاحظة') }}</label>
                            <input type="text" name="note" maxlength="255"
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <button type="submit" class="btn-danger btn-sm" @disabled($eosBalance <= 0)>{{ __('صرف وتصفية') }}</button>
                    </form>

                    <form method="POST" action="{{ route('admin.hr.employees.eos.adjust', $employee) }}"
                          class="grid gap-3 sm:grid-cols-4 items-end">
                        @csrf
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('تسوية (± مبلغ)') }}</label>
                            <input type="number" step="0.01" name="amount" required
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs text-gray-500 mb-1">{{ __('السبب') }} <span class="text-rose-500">*</span></label>
                            <input type="text" name="note" required maxlength="255" placeholder="{{ __('رصيد افتتاحي عن خدمةٍ سابقة للنظام') }}"
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <button type="submit" class="btn-secondary btn-sm">{{ __('تسجيل تسوية') }}</button>
                    </form>
                    <p class="text-[11px] text-gray-400">
                        {{ __('التسوية تُعدّل الدفتر ولا تُنشئ قيدًا — قيّدها بسندٍ مستقلّ إن أثّرت على الميزانية.') }}
                    </p>
                </div>
            @endcan
        </div>

        {{-- ④ المستحقّات: العمولات (للاطّلاع) وآخر المسيّرات --}}
        <div class="space-y-5">
            <div class="admin-card overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">{{ __('العمولات') }}</h3>
                    <p class="text-[11px] text-gray-400 mt-0.5">
                        {{ __('دفترٌ مستقلّ بحالاته ومسار اعتماده — يُعرض هنا ويُصرف من شاشته.') }}
                    </p>
                </div>
                @if ($commissions)
                    <dl class="p-4 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-gray-500">{{ __('المستحقّ') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ number_format($commissions['earned'], 2) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('المدفوع') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ number_format($commissions['paid'], 2) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('قيد الاعتماد') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ number_format($commissions['pending_payout'], 2) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('الرصيد') }}</dt>
                            <dd class="font-bold tabular-nums text-emerald-700">{{ number_format($commissions['outstanding'], 2) }}</dd></div>
                    </dl>
                    @can('commissions.view_team')
                        <div class="px-4 pb-4">
                            <a href="{{ route('admin.commissions.statement', $employee->user_id) }}?type={{ $earnerType }}"
                               class="btn-secondary btn-sm">{{ __('كشف العمولات') }}</a>
                        </div>
                    @endcan
                @else
                    <p class="p-6 text-center text-gray-400 text-sm">{{ __('لا حساب مستخدم مرتبط.') }}</p>
                @endif
            </div>

            <div class="admin-card overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">{{ __('آخر المسيّرات') }}</h3>
                </div>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>{{ __('الشهر') }}</th>
                            <th class="text-start">{{ __('الصافي') }}</th>
                            <th>{{ __('الحالة') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payrollLines as $line)
                            <tr>
                                <td>
                                    @if ($line->run)
                                        <a href="{{ route('admin.hr.payroll.show', $line->run) }}" class="text-emerald-700 hover:underline">
                                            {{ $line->run->periodLabel() }}
                                        </a>
                                    @endif
                                </td>
                                <td class="text-start tabular-nums font-semibold">{{ number_format((float) $line->net, 2) }}</td>
                                <td class="text-sm {{ $line->isPaid() ? 'text-emerald-700' : 'text-amber-600' }}">
                                    {{ $line->isPaid() ? __('مدفوع') : __('غير مدفوع') }}
                                    @if ($line->voucher)<span class="block text-[11px] text-gray-400">{{ $line->voucher->number }}</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-6 text-center text-gray-400">{{ __('لم يدخل مسيّرًا بعد.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
