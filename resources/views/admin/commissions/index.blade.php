<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('commissions.title') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />

            <div class="flex items-center justify-between mb-4">
                <div class="flex flex-wrap gap-1">
                    @foreach (['pending', 'eligible', 'approved', 'paid', 'reversed'] as $s)
                        <a href="{{ route('admin.commissions.index', ['state' => $s]) }}"
                           @class(['px-3 py-1.5 rounded-md text-sm', 'bg-emerald-600 text-white' => $state === $s, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => $state !== $s])>
                            {{ __('commissions.'.$s) }}
                        </a>
                    @endforeach
                </div>
                @can('commissions.rules.manage')
                    <a href="{{ route('admin.commissions.rules') }}" class="text-sm text-emerald-600 hover:underline">{{ __('commissions.rules') }}</a>
                @endcan
            </div>

            @if ($entries->isEmpty())
                <p class="text-gray-500 text-center py-8">{{ __('commissions.no_entries') }}</p>
            @else
                <form method="POST" action="{{ route('admin.commissions.approve') }}">
                    @csrf
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm text-right">
                            <thead class="text-gray-500 border-b"><tr>
                                <th class="py-2 px-3"></th>
                                <th class="py-2 px-3 font-medium">{{ __('commissions.earner') }}</th>
                                <th class="py-2 px-3 font-medium">{{ __('commissions.earner_type') }}</th>
                                <th class="py-2 px-3 font-medium">{{ __('commissions.order') }}</th>
                                <th class="py-2 px-3 font-medium">{{ __('commissions.entry_type') }}</th>
                                <th class="py-2 px-3 font-medium">{{ __('commissions.amount') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($entries as $e)
                                    <tr class="border-b hover:bg-gray-50">
                                        <td class="py-2 px-3">
                                            @if (in_array($state, ['eligible', 'approved'], true))
                                                <input type="checkbox" name="entry_ids[]" value="{{ $e->id }}" class="rounded border-gray-300">
                                            @endif
                                        </td>
                                        <td class="py-2 px-3">
                                            <a href="{{ route('admin.commissions.statement', ['earnerId' => $e->earner_id, 'earner_type' => $e->earner_type]) }}" class="text-emerald-600 hover:underline">{{ $e->earner?->name }}</a>
                                        </td>
                                        <td class="py-2 px-3">{{ __('commissions.'.$e->earner_type) }}</td>
                                        <td class="py-2 px-3">{{ $e->order?->number }}</td>
                                        <td class="py-2 px-3">{{ __('commissions.'.$e->entry_type) }}</td>
                                        <td class="py-2 px-3 font-medium {{ (float) $e->amount < 0 ? 'text-rose-600' : 'text-gray-800' }}">{{ number_format((float) $e->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($state === 'eligible')
                        @can('commissions.approve')
                            <button type="submit" class="mt-4 px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('commissions.approve_selected') }}</button>
                        @endcan
                    @endif
                </form>

                @if ($state === 'approved')
                    @can('commissions.payout')
                        {{-- الصرف لكل مستفيد على حدة (البنود المعتمدة له). --}}
                        <form method="POST" action="{{ route('admin.commissions.payout') }}" class="mt-4 flex flex-wrap items-end gap-2 border-t pt-4">
                            @csrf
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.earner') }} (id)</label>
                                <input type="number" name="earner_id" required class="rounded-md border-gray-300 text-sm w-32">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.earner_type') }}</label>
                                <select name="earner_type" class="rounded-md border-gray-300 text-sm">
                                    <option value="sales">{{ __('commissions.sales') }}</option>
                                    <option value="affiliate">{{ __('commissions.affiliate') }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.reference') }}</label>
                                <input type="text" name="reference" class="rounded-md border-gray-300 text-sm">
                            </div>
                            {{-- تُمرَّر كل البنود المعتمدة؛ الخدمة تصرف فقط ما يطابق المستفيد المحدّد. --}}
                            @foreach ($entries as $e)
                                <input type="hidden" name="entry_ids[]" value="{{ $e->id }}">
                            @endforeach
                            <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('commissions.payout_selected') }}</button>
                        </form>
                    @endcan
                @endif

                <div class="mt-4">{{ $entries->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
