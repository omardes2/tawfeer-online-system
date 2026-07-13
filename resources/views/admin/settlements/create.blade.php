<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('settlements.new') }}</h2></x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <x-admin.flash />
            @if ($errors->any())
                <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.settlements.store') }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('settlements.provider') }}</label>
                        <select name="delivery_provider_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">—</option>
                            @foreach ($providers as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('settlements.period_start') }}</label>
                        <input type="date" name="period_start" class="w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('settlements.period_end') }}</label>
                        <input type="date" name="period_end" class="w-full rounded-md border-gray-300 text-sm">
                    </div>
                </div>

                <p class="text-xs text-gray-500 border-t pt-3">{{ __('settlements.reported') }}</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div><label class="block text-xs text-gray-500 mb-1">{{ __('settlements.cod') }}</label><input type="number" step="0.01" name="reported_cod_total" value="0" class="w-full rounded-md border-gray-300 text-sm"></div>
                    <div><label class="block text-xs text-gray-500 mb-1">{{ __('settlements.fees') }}</label><input type="number" step="0.01" name="reported_fees_total" value="0" class="w-full rounded-md border-gray-300 text-sm"></div>
                    <div><label class="block text-xs text-gray-500 mb-1">{{ __('settlements.deductions') }}</label><input type="number" step="0.01" name="reported_deductions_total" value="0" class="w-full rounded-md border-gray-300 text-sm"></div>
                    <div><label class="block text-xs text-gray-500 mb-1">{{ __('settlements.net') }}</label><input type="number" step="0.01" name="reported_net" value="0" class="w-full rounded-md border-gray-300 text-sm"></div>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('settlements.notes') }}</label>
                    <textarea name="notes" rows="2" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                </div>

                <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('settlements.create') }}</button>
            </form>
        </div>
    </div>
</x-app-layout>
