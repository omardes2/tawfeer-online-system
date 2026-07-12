<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('warehouse.batch') }} — {{ $warehouse->name }}</h2></x-slot>
    <div class="py-6 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <x-admin.flash />
            @if ($errors->any())
                <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.inventory.batch.store') }}" x-data="{ rows: [{}, {}, {}] }">
                @csrf
                <div class="mb-3">
                    <label class="block text-xs text-gray-500 mb-1">{{ __('warehouse.reason') }}</label>
                    <input type="text" name="reason" required class="w-full rounded-md border-gray-300 text-sm">
                </div>

                <table class="min-w-full text-sm text-right mb-3">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-2 font-medium">{{ __('warehouse.variant') }} (id)</th>
                        <th class="py-2 px-2 font-medium">{{ __('warehouse.delta') }}</th>
                        <th class="py-2 px-2 font-medium">{{ __('warehouse.reason') }}</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="i">
                            <tr class="border-b">
                                <td class="py-1 px-2"><input type="number" :name="`lines[${i}][variant_id]`" class="w-28 rounded-md border-gray-300 text-sm"></td>
                                <td class="py-1 px-2"><input type="number" step="0.001" :name="`lines[${i}][delta]`" value="0" class="w-28 rounded-md border-gray-300 text-sm"></td>
                                <td class="py-1 px-2"><input type="text" :name="`lines[${i}][note]`" class="w-full rounded-md border-gray-300 text-sm"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div class="flex gap-2">
                    <button type="button" @click="rows.push({})" class="px-3 py-2 bg-gray-200 text-gray-700 text-sm rounded-md">{{ __('warehouse.add_line') }}</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md">{{ __('warehouse.apply') }}</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
