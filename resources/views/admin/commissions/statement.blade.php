<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('commissions.statement') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <a href="{{ route('admin.commissions.index') }}" class="text-sm text-gray-500 hover:text-emerald-600">← {{ __('commissions.ledger') }}</a>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 my-4">
                @foreach (['pending', 'eligible', 'approved', 'paid'] as $k)
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-lg font-bold text-gray-900">{{ number_format($statement[$k], 2) }}</p>
                        <p class="text-xs text-gray-500 mt-1">{{ __('commissions.total_'.$k) }}</p>
                    </div>
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.order') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.entry_type') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.basis') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.amount') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.state') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($entries as $e)
                            <tr class="border-b">
                                <td class="py-2 px-3">{{ $e->order?->number }}</td>
                                <td class="py-2 px-3">{{ __('commissions.'.$e->entry_type) }}</td>
                                <td class="py-2 px-3">{{ number_format((float) $e->basis, 2) }}</td>
                                <td class="py-2 px-3 {{ (float) $e->amount < 0 ? 'text-rose-600' : '' }}">{{ number_format((float) $e->amount, 2) }}</td>
                                <td class="py-2 px-3">{{ __('commissions.'.$e->state) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $entries->links() }}</div>
        </div>
    </div>
</x-app-layout>
