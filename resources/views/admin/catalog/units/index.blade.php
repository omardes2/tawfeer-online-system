<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('app.modules.catalog') }} — {{ __('وحدات القياس') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('وحدات القياس')">
                @can('create', \App\Modules\Catalog\Models\Unit::class)<a href="{{ route('admin.units.create') }}" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('وحدة جديدة') }}</a>@endcan
            </x-admin.header>
            <form method="GET" class="mb-4"><input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('بحث بالاسم أو الرمز...') }}" class="w-full sm:w-72 rounded-md border-gray-300 text-sm" /></form>
            <div class="overflow-x-auto"><table class="min-w-full text-sm text-right">
                <thead class="text-gray-500 border-b"><tr><th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th><th class="py-2 px-3 font-medium">{{ __('الرمز') }}</th><th class="py-2 px-3 font-medium">{{ __('الوحدة الأساس') }}</th><th class="py-2 px-3 font-medium">{{ __('معامل التحويل') }}</th><th class="py-2 px-3 font-medium">{{ __('إجراءات') }}</th></tr></thead>
                <tbody class="divide-y">
                    @forelse ($units as $unit)
                        <tr>
                            <td class="py-2 px-3 text-gray-800">{{ $unit->name }} @if($unit->name_en)<span class="text-gray-400">/ {{ $unit->name_en }}</span>@endif</td>
                            <td class="py-2 px-3 text-gray-500">{{ $unit->code }}</td>
                            <td class="py-2 px-3 text-gray-500">{{ $unit->baseUnit?->name ?? '—' }}</td>
                            <td class="py-2 px-3 text-gray-500">{{ rtrim(rtrim($unit->conversion_factor, '0'), '.') }}</td>
                            <td class="py-2 px-3"><div class="flex gap-2">
                                @can('update', $unit)<a href="{{ route('admin.units.edit', $unit) }}" class="text-indigo-600 hover:underline">{{ __('تعديل') }}</a>@endcan
                                @can('delete', $unit)<form method="POST" action="{{ route('admin.units.destroy', $unit) }}" onsubmit="return confirm('{{ __('تأكيد الحذف؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 hover:underline">{{ __('حذف') }}</button></form>@endcan
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('لا توجد وحدات.') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            <div class="mt-4">{{ $units->links() }}</div>
        </div>
    </div>
</x-app-layout>
