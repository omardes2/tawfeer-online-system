<x-app-layout :title="__('طلبات لم تكتمل')">
    @php
        $tones = [
            'new' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'contacted' => 'bg-sky-50 text-sky-700 ring-sky-200',
            'no_answer' => 'bg-gray-100 text-gray-600 ring-gray-200',
            'refused' => 'bg-rose-50 text-rose-700 ring-rose-200',
            'recovered' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'ignored' => 'bg-gray-100 text-gray-400 ring-gray-200',
        ];
        $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    @endphp

    <x-admin.header
        :title="__('طلبات لم تكتمل')"
        :description="__('زبائن ملؤوا بياناتهم في الإتمام ثم لم يُرسلوا الطلب — مكالمة واحدة تُنقذ ما أُنفق عليهم.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المبيعات') => null, __('طلبات لم تكتمل') => null]" />

    <x-admin.flash />

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-5">
        <x-admin.stat-card :label="__('غير مكتملة')" :value="$stats['count']" tone="gray"
                           :hint="__('في الفترة المحدّدة')" />
        <x-admin.stat-card :label="__('تنتظر اتصالًا')" :value="$stats['open_count']" tone="amber" />
        <x-admin.stat-card :label="__('قيمة معلّقة')" :value="$stats['open_value']" money tone="red"
                           :hint="__('قيمة السلال بلا رسوم توصيل')" />
        <x-admin.stat-card :label="__('استُرِدّ')" :value="$stats['recovered_count']" tone="green"
                           :hint="__(':v شيكل من طلبات تمّت لاحقًا', ['v' => number_format($stats['recovered_value'], 2)])" />
    </div>

    <x-admin.table :title="__('قائمة الاتصال')" stack>
        <x-slot name="toolbar">
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <select name="range" onchange="this.form.submit()"
                        class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @foreach (\App\Modules\Reporting\Support\DateRange::LABELS as $key => $label)
                        <option value="{{ $key }}" @selected($range->preset === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="status" onchange="this.form.submit()"
                        class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="open" @selected($status === 'open')>{{ __('تنتظر اتصالًا') }}</option>
                    <option value="all" @selected($status === 'all')>{{ __('الكل') }}</option>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="from" value="{{ $range->fromString() }}" />
                <input type="hidden" name="to" value="{{ $range->toString() }}" />
            </form>
        </x-slot>

        <thead>
            <tr>
                <th>{{ __('الزبون') }}</th>
                <th>{{ __('السلة') }}</th>
                <th class="text-start">{{ __('القيمة') }}</th>
                <th class="text-center">{{ __('منذ') }}</th>
                <th class="text-center">{{ __('الحالة') }}</th>
                <th class="text-center">{{ __('إجراء') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                @php
                    $waText = str_replace([':name', ':store'], [$r['name'] ?: '', $storeName], $waTemplate);
                    $waPhone = preg_replace('/\D/', '', (string) $r['phone']);
                    $waPhone = str_starts_with($waPhone, '0') ? '970'.substr($waPhone, 1) : $waPhone;
                @endphp
                <tr>
                    <td data-label="{{ __('الزبون') }}">
                        <span class="font-medium text-gray-800">{{ $r['name'] ?: __('بلا اسم') }}</span>
                        <span class="block text-xs text-gray-500 font-mono" dir="ltr">{{ $r['phone'] }}</span>
                        <span class="block text-[11px] text-gray-400">
                            {{ $r['city'] ?: __('بلا مدينة') }}@if ($r['area']) — {{ $r['area'] }}@endif
                        </span>
                        @if ($r['sessions'] > 1)
                            <span class="block mt-0.5 text-[11px] text-amber-700">
                                {{ __('عاد :n مرّات ولم يُكمل', ['n' => $r['sessions']]) }}
                            </span>
                        @endif
                    </td>
                    <td data-label="{{ __('السلة') }}" class="text-xs text-gray-600">
                        @foreach ($r['items'] as $item)
                            <span class="block">{{ $item['name'] }} × {{ $qty($item['qty']) }}</span>
                        @endforeach
                    </td>
                    <td data-label="{{ __('القيمة') }}" class="text-start tabular-nums whitespace-nowrap font-semibold">
                        {{ number_format($r['value'], 2) }}
                    </td>
                    <td data-label="{{ __('منذ') }}" class="text-center text-xs text-gray-500 whitespace-nowrap">
                        {{ $r['created_at']->diffForHumans(short: true) }}
                    </td>
                    <td data-label="{{ __('الحالة') }}" class="text-center">
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 whitespace-nowrap {{ $tones[$r['status']] ?? $tones['new'] }}">
                            {{ $statuses[$r['status']] ?? $r['status'] }}
                        </span>
                        @if ($r['recovered_order'])
                            <span class="block mt-0.5 text-[11px] text-emerald-700 font-mono">{{ $r['recovered_order'] }}</span>
                        @endif
                        @if ($r['attempts'] > 0)
                            <span class="block text-[11px] text-gray-400">{{ __(':n محاولة', ['n' => $r['attempts']]) }}</span>
                        @endif
                        @if ($r['note'])
                            <span class="block text-[11px] text-gray-500">{{ $r['note'] }}</span>
                        @endif
                        @if ($r['contacted_by'])
                            <span class="block text-[11px] text-gray-400">{{ $r['contacted_by'] }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('إجراء') }}" class="text-center">
                        <div class="flex flex-wrap items-center justify-center gap-2">
                            <a href="tel:{{ $r['phone'] }}"
                               class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-2.5 py-1 text-xs text-white hover:bg-emerald-700">
                                {{ __('اتصال') }}
                            </a>
                            <a href="https://wa.me/{{ $waPhone }}?text={{ rawurlencode($waText) }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-200">
                                {{ __('واتساب') }}
                            </a>
                            @can('create', \App\Modules\Sales\Models\Order::class)
                                {{-- الرقم يُمرَّر فيتعرّف النموذج على الزبون ويملأ الباقي بنفسه. --}}
                                <a href="{{ route('admin.sales.orders.create', ['phone' => $r['phone']]) }}"
                                   class="inline-flex items-center gap-1 rounded-lg bg-sky-50 px-2.5 py-1 text-xs text-sky-700 ring-1 ring-sky-200 hover:bg-sky-100">
                                    {{ __('إنشاء طلب') }}
                                </a>
                            @endcan
                        </div>

                        @can('sales.abandoned_checkouts.manage')
                            <form method="POST" action="{{ route('admin.sales.abandoned_checkouts.outcome', $r['uuid']) }}"
                                  class="mt-2 flex flex-wrap items-center justify-center gap-1.5">
                                @csrf
                                <select name="recovery_status" class="rounded-lg border-gray-300 text-[11px] py-1">
                                    @foreach ($statuses as $key => $label)
                                        <option value="{{ $key }}" @selected($r['status'] === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="recovery_note" value="{{ $r['note'] }}" maxlength="500"
                                       placeholder="{{ __('ملاحظة') }}"
                                       class="w-28 rounded-lg border-gray-300 text-[11px] py-1" />
                                <button class="rounded-lg bg-gray-800 px-2 py-1 text-[11px] text-white hover:bg-gray-900">
                                    {{ __('حفظ') }}
                                </button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا شيء ينتظر اتصالًا')"
                        :description="__('لم يترك أحدٌ بياناته دون إتمام الطلب في هذه الفترة — أو أن الجميع اتُّصل بهم.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <div class="mt-4 rounded-xl bg-gray-50 ring-1 ring-gray-200 p-4 text-xs leading-6 text-gray-600">
        <p class="font-semibold text-gray-700 mb-1">{{ __('ما الذي تراه هنا بالضبط') }}</p>
        <p>
            {{ __('جلسات إتمامٍ حُفظ فيها رقم الهاتف ولم يُرسَل الطلب، أقدم من نصف ساعة (كي لا يُزعَج من هو الآن في منتصف الشراء). القيمة قيمة السلة قبل رسوم التوصيل. من عاد فطلب لاحقًا بالرقم نفسه يظهر «تحوّل إلى طلب» تلقائيًّا فلا يُتصل به.') }}
        </p>
        <p class="mt-1 text-gray-500">
            {{ __('لا يظهر هنا من كتب رقمه ثم غادر قبل اختيار المدينة: الرقم لا يصل الخلفية إلا عند تلك الخطوة، وتغطيته تقتضي المساس بتسلسل الإتمام المجمّد.') }}
        </p>
    </div>
</x-app-layout>
