<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('commissions.title') }} — {{ __('commissions.rules') }}</h2></x-slot>

    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <a href="{{ route('admin.commissions.index') }}" class="text-sm text-gray-500 hover:text-emerald-600">← {{ __('commissions.back_to_people') }}</a>

        <x-admin.flash />
        @if ($errors->any())
            <div class="rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
        @endif

        {{-- إضافة قاعدة: سؤالان أساسيان (لمن؟ وكم؟) والباقي اختياري مطويّ. --}}
        <form method="POST" action="{{ route('admin.commissions.rules.store') }}"
              x-data="{ method: '{{ old('method', 'percent') }}', advanced: false }"
              class="bg-white border border-gray-200 rounded-lg p-6 space-y-5">
            @csrf
            <h3 class="font-semibold text-gray-800">{{ __('commissions.add_rule') }}</h3>

            {{-- 1) لمن تُطبَّق؟ --}}
            <div>
                <p class="text-sm font-medium text-gray-700 mb-2">{{ __('commissions.q_who') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <select name="earner_type" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="sales">{{ __('commissions.all_sales_staff') }}</option>
                        <option value="affiliate">{{ __('commissions.all_affiliates') }}</option>
                    </select>
                    <select name="user_id" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="">{{ __('commissions.everyone_of_type') }}</option>
                        @foreach ($people as $p)
                            <option value="{{ $p->id }}" @selected(old('user_id') == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-gray-400 mt-1">{{ __('commissions.who_hint') }}</p>
            </div>

            {{-- 2) كم؟ --}}
            <div>
                <p class="text-sm font-medium text-gray-700 mb-2">{{ __('commissions.q_how_much') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <select name="method" x-model="method" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="percent">{{ __('commissions.method_percent') }}</option>
                        <option value="margin">{{ __('commissions.method_margin') }}</option>
                        <option value="fixed">{{ __('commissions.method_fixed') }}</option>
                    </select>

                    <div x-show="method === 'percent'">
                        <div class="relative">
                            <input type="number" step="0.01" min="0" max="100" name="rate_percent" value="{{ old('rate_percent') }}"
                                   placeholder="3" class="w-full rounded-md border-gray-300 text-sm pe-9">
                            <span class="absolute inset-y-0 end-3 grid place-items-center text-gray-400 text-sm">%</span>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">{{ __('commissions.percent_hint') }}</p>
                    </div>

                    {{-- الهامش يُمنح كاملًا — لا حقل نسبة. --}}
                    <div x-show="method === 'margin'" x-cloak class="flex items-center">
                        <p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md px-3 py-2 w-full">{{ __('commissions.margin_hint') }}</p>
                    </div>

                    <div x-show="method === 'fixed'" x-cloak>
                        <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}"
                               placeholder="5.00" class="w-full rounded-md border-gray-300 text-sm">
                        <p class="text-xs text-gray-400 mt-1">{{ __('commissions.fixed_hint') }}</p>
                    </div>
                </div>
            </div>

            {{-- 3) خيارات متقدّمة (مطويّة) --}}
            <div>
                <button type="button" x-on:click="advanced = ! advanced" class="text-sm text-emerald-600 hover:underline">
                    <span x-show="! advanced">+ {{ __('commissions.advanced_options') }}</span>
                    <span x-show="advanced" x-cloak>− {{ __('commissions.advanced_options') }}</span>
                </button>
                <div x-show="advanced" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2 border-t pt-4">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.only_product') }}</label>
                        <select name="product_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">{{ __('commissions.any') }}</option>
                            @foreach ($products as $p)
                                <option value="{{ $p->id }}" @selected(old('product_id') == $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.only_category') }}</label>
                        <select name="category_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">{{ __('commissions.any') }}</option>
                            @foreach ($categories as $c)
                                <option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.only_branch') }}</label>
                        <select name="branch_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">{{ __('commissions.any') }}</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.campaign') }}</label>
                        <input type="text" name="campaign" value="{{ old('campaign') }}" class="w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.period_from') }}</label>
                        <input type="date" name="period_start" value="{{ old('period_start') }}" class="w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.period_to') }}</label>
                        <input type="date" name="period_end" value="{{ old('period_end') }}" class="w-full rounded-md border-gray-300 text-sm">
                    </div>
                </div>
            </div>

            <div class="pt-1">
                <button type="submit" class="px-5 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('commissions.save') }}</button>
                <span class="text-xs text-gray-400 ms-2">{{ __('commissions.precedence_hint') }}</span>
            </div>
        </form>

        {{-- القواعد الحالية: النطاق بالاسم لا بالمعرّف، مرتّبة بأولويّة التطبيق. --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('commissions.current_rules') }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.applies_to') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.earner_type') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.value') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.period') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.active') }}</th>
                        <th class="py-2 px-3"></th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rules as $rule)
                            <tr class="border-b">
                                <td class="py-2.5 px-3 font-medium text-gray-800">{{ $rule->scopeLabel() }}</td>
                                <td class="py-2.5 px-3">{{ __('commissions.'.$rule->earner_type) }}</td>
                                <td class="py-2.5 px-3 tabular-nums">
                                    @if ($rule->method === 'fixed')
                                        {{ number_format((float) $rule->amount, 2) }}
                                        <span class="text-xs text-gray-400">{{ __('commissions.method_fixed') }}</span>
                                    @elseif ($rule->method === 'margin')
                                        <span class="text-gray-800">{{ __('commissions.method_margin') }}</span>
                                    @else
                                        {{ rtrim(rtrim(number_format((float) $rule->rate * 100, 2), '0'), '.') }}%
                                        <span class="text-xs text-gray-400">{{ __('commissions.method_percent') }}</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3 text-gray-500 text-xs">
                                    {{ $rule->period_start ? $rule->period_start->format('Y-m-d').' → '.($rule->period_end?->format('Y-m-d') ?? '…') : __('commissions.always') }}
                                </td>
                                <td class="py-2.5 px-3">{{ $rule->is_active ? '✓' : '—' }}</td>
                                <td class="py-2.5 px-3 text-end">
                                    <form method="POST" action="{{ route('admin.commissions.rules.destroy', $rule) }}" onsubmit="return confirm('{{ __('commissions.delete') }}؟')">
                                        @csrf @method('DELETE')
                                        <button class="text-rose-600 hover:underline">{{ __('commissions.delete') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('commissions.no_rules_default', ['rate' => '1%']) }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $rules->links() }}</div>
        </div>
    </div>
</x-app-layout>
