<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('الشحن') }} — {{ __('الجغرافيا') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
            <x-admin.flash />
            <div>
                <h3 class="font-medium text-gray-800 mb-3">{{ __('المحافظات والمدن') }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @forelse ($governorates as $gov)
                        <div class="border rounded-md p-3">
                            <div class="font-medium text-sm text-gray-800">{{ $gov->name }}</div>
                            <ul class="mt-1 text-sm text-gray-500 space-y-0.5">
                                @foreach ($gov->cities as $city)<li>{{ $city->name }}</li>@endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="text-gray-400 text-sm">{{ __('لا توجد بيانات جغرافية.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="border-t pt-4">
                <h3 class="font-medium text-gray-800 mb-3">{{ __('مناطق الشحن') }}</h3>
                @if ($zones->isEmpty())
                    <p class="text-gray-400 text-sm">{{ __('لا توجد مناطق شحن.') }}</p>
                @else
                    <table class="min-w-full text-sm text-right">
                        <thead class="text-gray-500 border-b"><tr>
                            <th class="py-2 px-3 font-medium">{{ __('الرمز') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        </tr></thead>
                        <tbody class="divide-y">
                            @foreach ($zones as $z)
                                <tr>
                                    <td class="py-2 px-3">{{ $z->code }}</td>
                                    <td class="py-2 px-3">{{ $z->name }}</td>
                                    <td class="py-2 px-3">{{ $z->is_active ? __('نشط') : __('غير نشط') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
