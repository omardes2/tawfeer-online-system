<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('commissions.title') }} — {{ __('commissions.rules') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <p class="text-xs text-gray-500 mb-4">{{ __('commissions.precedence_hint') }}</p>

            @if ($errors->any())
                <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
            @endif

            {{-- إضافة قاعدة --}}
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('commissions.add_rule') }}</h3>
            <form method="POST" action="{{ route('admin.commissions.rules.store') }}" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 border-b pb-6">
                @csrf
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.earner_type') }}</label>
                    <select name="earner_type" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="sales">{{ __('commissions.sales') }}</option>
                        <option value="affiliate">{{ __('commissions.affiliate') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.method') }}</label>
                    <select name="method" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="percent">{{ __('commissions.method_percent') }}</option>
                        <option value="fixed">{{ __('commissions.method_fixed') }}</option>
                        <option value="margin">{{ __('commissions.method_margin') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.rate') }} (0–1)</label>
                    <input type="number" step="0.000001" min="0" max="1" name="rate" value="{{ old('rate') }}" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.fixed_amount') }}</label>
                    <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.employee') }}</label>
                    <input type="number" name="user_id" value="{{ old('user_id') }}" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.branch') }}</label>
                    <input type="number" name="branch_id" value="{{ old('branch_id') }}" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.period_start') }}</label>
                    <input type="date" name="period_start" value="{{ old('period_start') }}" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.period_end') }}</label>
                    <input type="date" name="period_end" value="{{ old('period_end') }}" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div class="col-span-2 md:col-span-4">
                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('commissions.save') }}</button>
                </div>
            </form>

            {{-- القائمة --}}
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.earner_type') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.method') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.rate') }}/{{ __('commissions.fixed_amount') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.priority') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.active') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rules as $rule)
                            <tr class="border-b">
                                <td class="py-2 px-3">{{ __('commissions.'.$rule->earner_type) }}</td>
                                <td class="py-2 px-3">{{ __('commissions.method_'.$rule->method) }}</td>
                                <td class="py-2 px-3">{{ $rule->method === 'fixed' ? number_format((float) $rule->amount, 2) : ($rule->rate * 100).'%' }}</td>
                                <td class="py-2 px-3">{{ $rule->priority }}</td>
                                <td class="py-2 px-3">{{ $rule->is_active ? '✓' : '—' }}</td>
                                <td class="py-2 px-3">
                                    <form method="POST" action="{{ route('admin.commissions.rules.destroy', $rule) }}" onsubmit="return confirm('{{ __('commissions.delete') }}?')">
                                        @csrf @method('DELETE')
                                        <button class="text-rose-600 hover:underline">{{ __('commissions.delete') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $rules->links() }}</div>
        </div>
    </div>
</x-app-layout>
