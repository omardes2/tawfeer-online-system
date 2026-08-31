<x-app-layout :title="__('صرف نهاية الخدمة')">
    <x-admin.header
        :title="__('صرف مكافأة نهاية الخدمة')"
        :description="__('التراكم شهريّ مع الكشف، والصرف مرّةً في نهاية السنة.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الرواتب والموظفون') => route('admin.hr.employees.index'), __('صرف نهاية الخدمة') => null]">
        <a href="{{ route('admin.hr.employees.index') }}" class="btn-secondary btn-sm">{{ __('الموظفون') }}</a>
        @can('hr.payroll.view')
            <a href="{{ route('admin.hr.payroll.index') }}" class="btn-secondary btn-sm">{{ __('كشوفات الرواتب') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    {{--
        الصرف يدويّ: يُكتب صراحةً كي لا يُنتظر من النظام ما لا يفعله. من يظنّ
        الصرف آليًّا لا يصرف، فيبقى المخصّص معلّقًا سنةً بعد سنة.
    --}}
    <div class="admin-card admin-card-pad mb-5 border-s-4 border-sky-400 bg-sky-50 text-sm text-sky-900 leading-7">
        <h3 class="font-semibold mb-1">{{ __('كيف يعمل') }}</h3>
        <ul class="list-disc ps-5 space-y-1">
            <li>{{ __('المخصّص يتراكم شهريًّا مع كل كشف (الراتب الأساسيّ ÷ ١٢) — وهو التزامٌ على الشركة لا مبلغٌ يُصرف مع الراتب.') }}</li>
            <li>{{ __('الصرف يدويّ لا آليّ: النظام لا يُحوّل مالًا ولا يصرف في موعدٍ مُبرمَج. يُسلَّم المبلغ باليد، ثم يُسجَّل هنا.') }}</li>
            <li>{{ __('التسجيل يُنشئ سند صرفٍ لكل موظف: مدين «مخصّص مكافأة نهاية الخدمة ٢٢١٠» / دائن الخزينة — ولا يمرّ بالمصروف ثانيةً لأنه حُمّل شهرًا بشهر.') }}</li>
            <li>{{ __('الرصيد يشمل ما لم يُصرف من سنواتٍ سابقة، وهو وحده ما يجوز صرفه.') }}</li>
        </ul>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-5">
        <x-admin.stat-card :label="__('المخصّص المستحقّ')" :value="$totals['balance']" money tone="amber"
                           :hint="__('الرصيد القابل للصرف الآن')" />
        <x-admin.stat-card :label="__('متراكم :y', ['y' => $year])" :value="$totals['accrued']" money tone="blue"
                           :hint="__('من كشوفات السنة المُرحَّلة')" />
        <x-admin.stat-card :label="__('مصروف :y', ['y' => $year])" :value="$totals['settled']" money tone="green"
                           :hint="__('ما سُلّم فعلًا وسُجّل')" />
        <x-admin.stat-card :label="__('موظفون لهم رصيد')" :value="$totals['due']"
                           :tone="$totals['due'] > 0 ? 'red' : 'green'"
                           :hint="__('لم تُصرف مكافأتهم بعد')" />
    </div>

    <form method="GET" class="admin-card admin-card-pad mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('السنة') }}</label>
            <select name="year" class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                @for ($y = today()->year; $y >= today()->year - 5; $y--)
                    <option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <button type="submit" class="btn-secondary btn-sm">{{ __('عرض') }}</button>
    </form>

    @if ($rows->isEmpty())
        <div class="admin-card admin-card-pad text-sm text-gray-500">
            {{ __('لا مخصّص متراكم ولا حركة في :y.', ['y' => $year]) }}
        </div>
    @else
        <form method="POST" action="{{ route('admin.hr.eos.settle') }}"
              x-data="{
                  rows: @js($rows->mapWithKeys(fn ($r) => [$r['profile']->id => (float) $r['balance']])),
                  picked: {},
                  amounts: @js($rows->mapWithKeys(fn ($r) => [$r['profile']->id => number_format((float) $r['balance'], 2, '.', '')])),
                  get total() {
                      return Object.keys(this.picked)
                          .filter(id => this.picked[id])
                          .reduce((sum, id) => sum + (parseFloat(this.amounts[id]) || 0), 0);
                  },
                  get count() { return Object.keys(this.picked).filter(id => this.picked[id]).length; },
                  all(value) { Object.keys(this.rows).forEach(id => { if (this.rows[id] > 0) this.picked[id] = value; }); },
              }"
              @submit="if (! confirm('{{ __('تسجيل صرف المكافأة؟ سيُنشأ سند صرفٍ لكل موظف مُختار.') }}')) $event.preventDefault()">
            @csrf

            <div class="admin-card overflow-hidden mb-5">
                <div class="overflow-x-auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                @can('hr.payroll.manage')
                                    <th class="w-10">
                                        <input type="checkbox" @change="all($event.target.checked)"
                                               class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                    </th>
                                @endcan
                                <th>{{ __('الموظف') }}</th>
                                <th>{{ __('الفرع') }}</th>
                                <th class="text-start">{{ __('متراكم :y', ['y' => $year]) }}</th>
                                <th class="text-start">{{ __('مصروف :y', ['y' => $year]) }}</th>
                                <th class="text-start">{{ __('الرصيد المستحقّ') }}</th>
                                @can('hr.payroll.manage')
                                    <th class="text-start w-40">{{ __('مبلغ الصرف') }}</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php $p = $row['profile']; @endphp
                                <tr>
                                    @can('hr.payroll.manage')
                                        <td>
                                            <input type="checkbox" x-model="picked[{{ $p->id }}]"
                                                   @disabled($row['balance'] <= 0)
                                                   class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 disabled:opacity-40">
                                        </td>
                                    @endcan
                                    <td>
                                        <a href="{{ route('admin.hr.employees.show', $p) }}" class="text-emerald-700 hover:underline">
                                            {{ $p->user?->name ?? '—' }}
                                        </a>
                                        @if ($p->status !== 'active')
                                            <span class="ms-1 text-[11px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">{{ __('انتهت خدمته') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-gray-500">{{ $p->branch?->name ?? '—' }}</td>
                                    <td class="text-start tabular-nums text-gray-600">{{ number_format($row['accrued'], 2) }}</td>
                                    <td class="text-start tabular-nums text-emerald-700">{{ number_format($row['settled'], 2) }}</td>
                                    <td class="text-start tabular-nums font-semibold text-amber-700">{{ number_format($row['balance'], 2) }}</td>
                                    @can('hr.payroll.manage')
                                        <td class="text-start">
                                            {{-- المبلغ يُعدَّل: قد يُصرف بعضُه ويبقى الباقي مخصّصًا. --}}
                                            <input type="number" step="0.01" min="0" max="{{ $row['balance'] }}"
                                                   name="amounts[{{ $p->id }}]"
                                                   x-model="amounts[{{ $p->id }}]"
                                                   :disabled="! picked[{{ $p->id }}]"
                                                   @disabled($row['balance'] <= 0)
                                                   class="w-32 rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-100 disabled:text-gray-400">
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-amber-50 font-bold text-amber-800">
                                <td colspan="{{ auth()->user()->can('hr.payroll.manage') ? 5 : 4 }}">{{ __('الإجمالي') }}</td>
                                <td class="text-start tabular-nums">{{ number_format($totals['balance'], 2) }}</td>
                                @can('hr.payroll.manage')
                                    <td class="text-start tabular-nums" x-text="total.toFixed(2)"></td>
                                @endcan
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            @can('hr.payroll.manage')
                <div class="admin-card admin-card-pad flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('الخزينة') }}</label>
                        <select name="treasury_id" required
                                class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach ($treasuries as $treasury)
                                <option value="{{ $treasury->id }}">{{ $treasury->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grow min-w-48">
                        <label class="block text-xs text-gray-500 mb-1">{{ __('ملاحظة (اختياري)') }}</label>
                        <input type="text" name="note" maxlength="255" value="{{ old('note') }}"
                               placeholder="{{ __('مثال: صرف نهاية سنة :y نقدًا باليد', ['y' => $year]) }}"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <button type="submit" class="btn-primary btn-sm" :disabled="count === 0">
                        {{ __('تسجيل الصرف') }}
                        <span x-show="count > 0" x-cloak>(<span x-text="count"></span>)</span>
                    </button>
                    <p class="text-[11px] text-gray-400 basis-full">
                        {{ __('يُسجَّل ما سُلّم فعلًا. ولا يُصرف مبلغٌ فوق الرصيد — الفارق يُضاف تسويةً من ملفّ الموظف أوّلًا كي يبقى سببُه مكتوبًا.') }}
                    </p>
                </div>
            @endcan
        </form>
    @endif
</x-app-layout>
