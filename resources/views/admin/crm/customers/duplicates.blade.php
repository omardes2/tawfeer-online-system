<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('العملاء المكرّرون') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('العملاء المكرّرون')">
                <a href="{{ route('admin.crm.customers.index') }}" class="inline-flex px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">{{ __('كل العملاء') }}</a>
            </x-admin.header>

            {{--
                الشاشة تعرض ولا تدمج: الرقم المتطابق رجلٌ واحد، والاسم المتطابق
                قد يكون رجلين. و«زبون» تتكرّر عشرًا وهم عشرة.
            --}}
            <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900 leading-6">
                <p class="font-semibold">{{ __('اقرأ قبل الدمج') }}</p>
                <ul class="list-disc ps-5 mt-1 space-y-1">
                    <li>{{ __('المطابقة بالهاتف شبه مؤكّدة، والمطابقة بالاسم ظنّ — أسماءٌ عامّة مثل «زبون» تتشابه ولأشخاص مختلفين.') }}</li>
                    <li>{{ __('تختار السجلّ الباقي، فينتقل إليه كل شيء: الطلبات وسندات القبض والعناوين والهواتف والرصيد.') }}</li>
                    <li>{{ __('الرصيد ينتقل بقيدٍ محاسبيّ، والقيود القديمة تبقى كما هي — ومجموع «ذمم العملاء» لا يتغيّر.') }}</li>
                    <li class="font-semibold">{{ __('الدمج لا يُلغى. راجع الطلبات والرصيد قبل التأكيد.') }}</li>
                </ul>
            </div>
        </div>

        @forelse ($groups as $group)
            <div class="bg-white shadow-sm sm:rounded-lg p-6" x-data="{ target: '{{ $group['customers']->first()->uuid }}' }">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b pb-3 mb-3">
                    <div class="flex items-center gap-2">
                        <h3 class="font-semibold text-gray-800">{{ $group['label'] }}</h3>
                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $group['by'] === 'phone' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                            {{ $group['by'] === 'phone' ? __('تطابق بالهاتف') : __('تشابه بالاسم') }}
                        </span>
                        <span class="text-xs text-gray-400">{{ __(':n سجلّات', ['n' => $group['customers']->count()]) }}</span>
                    </div>
                    <div class="text-sm text-gray-500">
                        {{ __('مجموع الرصيد') }}
                        <span class="tabular-nums font-medium {{ $group['balance'] > 0 ? 'text-rose-600' : ($group['balance'] < 0 ? 'text-emerald-600' : 'text-gray-400') }}">
                            {{ $group['balance'] == 0 ? '—' : number_format(abs($group['balance']), 2) }}
                        </span>
                        <span class="mx-2 text-gray-300">|</span>
                        {{ __('الطلبات') }} <span class="tabular-nums">{{ $group['orders'] }}</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-right">
                        <thead class="text-gray-500 border-b"><tr>
                            <th class="py-2 px-3 font-medium">{{ __('الباقي') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الهاتف') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('التصنيف') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الطلبات') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الرصيد') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('أُنشئ') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                        </tr></thead>
                        <tbody class="divide-y">
                            @foreach ($group['customers'] as $c)
                                @php $balance = $c->outstandingBalance(); @endphp
                                <tr :class="target === '{{ $c->uuid }}' && 'bg-emerald-50'">
                                    <td class="py-2 px-3">
                                        <input type="radio" name="target-{{ $loop->parent->index }}" value="{{ $c->uuid }}"
                                               x-model="target" class="text-emerald-600 focus:ring-emerald-500" />
                                    </td>
                                    <td class="py-2 px-3 text-gray-800">{{ $c->name }}</td>
                                    <td class="py-2 px-3 text-gray-500">{{ $c->primary_phone ?: '—' }}</td>
                                    <td class="py-2 px-3 text-gray-500">{{ $c->category ?: '—' }}</td>
                                    <td class="py-2 px-3 text-gray-500 tabular-nums">{{ $c->orders_count }}</td>
                                    <td class="py-2 px-3 tabular-nums whitespace-nowrap
                                        @if ($balance > 0) text-rose-600 font-medium
                                        @elseif ($balance < 0) text-emerald-600
                                        @else text-gray-400 @endif">
                                        @if ($balance == 0)
                                            —
                                        @else
                                            {{ number_format(abs($balance), 2) }}
                                            <span class="text-[11px] text-gray-400">{{ $balance > 0 ? __('عليه') : __('له') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-gray-400 text-xs">{{ $c->created_at?->format('Y-m-d') }}</td>
                                    <td class="py-2 px-3">
                                        <a href="{{ route('admin.crm.customers.show', $c) }}" target="_blank" class="text-emerald-600 hover:underline">{{ __('عرض') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3 border-t pt-4">
                    <p class="text-sm text-gray-500 flex-1">
                        {{ __('اختر السجلّ الباقي أعلاه، ثم ادمج فيه البقيّة واحدًا واحدًا.') }}
                    </p>
                    @foreach ($group['customers'] as $c)
                        <form method="POST" action="{{ route('admin.crm.customers.merge', $c) }}"
                              x-show="target !== '{{ $c->uuid }}'"
                              onsubmit="return confirm('{{ __('دمج «:name» في السجلّ المختار؟ لا يمكن التراجع.', ['name' => $c->name]) }}')">
                            @csrf
                            <input type="hidden" name="target" :value="target" />
                            <button class="px-3 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">
                                {{ __('ادمج «:name»', ['name' => \Illuminate\Support\Str::limit($c->name, 22)]) }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="bg-white shadow-sm sm:rounded-lg p-10 text-center text-gray-400">
                {{ __('لا مكرّرين — لا هاتفٌ متكرّر ولا اسمٌ متشابه.') }}
            </div>
        @endforelse
    </div>
</x-app-layout>
