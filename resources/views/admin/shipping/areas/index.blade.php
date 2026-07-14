<x-app-layout :title="__('مناطق الشحن')">
    <x-admin.header
        :title="__('مناطق الشحن')"
        :description="__('المناطق الفرعية داخل كل مدينة — تظهر في قائمة «المنطقة» عند إنشاء الطلب. اختر المدينة ثم أضِف/عدّل/احذف مناطقها.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الشحن والتوصيل') => null, __('مناطق الشحن') => null]" />

    <x-admin.flash />

    {{-- اختيار المدينة --}}
    <form method="GET" class="admin-card admin-card-pad mb-6 flex flex-wrap items-end gap-4">
        <x-admin.field :label="__('المدينة')" name="city">
            <select name="city" onchange="this.form.submit()" class="w-full md:w-72 rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                @foreach ($cities as $c)
                    <option value="{{ $c->id }}" @selected($cityId == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </x-admin.field>
        <p class="text-sm text-gray-400 pb-2">{{ __('عدد المناطق') }}: <span class="font-semibold text-gray-600">{{ $areas->count() }}</span></p>
    </form>

    {{-- إضافة منطقة --}}
    @can('settings.geography.manage')
        <div class="admin-card admin-card-pad mb-6">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('إضافة منطقة') }}</h3>
            <form method="POST" action="{{ route('admin.shipping.areas.store') }}" class="grid gap-4 md:grid-cols-4 items-end">
                @csrf
                <input type="hidden" name="city_id" value="{{ $cityId }}" />
                <x-admin.field :label="__('اسم المنطقة')" name="name" required>
                    <input type="text" name="name" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>
                <x-admin.field :label="__('الاسم بالإنجليزية (اختياري)')" name="name_en">
                    <input type="text" name="name_en" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>
                <button type="submit" class="btn-primary">{{ __('إضافة') }}</button>
            </form>
        </div>
    @endcan

    {{-- جدول المناطق --}}
    <form method="POST" action="{{ route('admin.shipping.areas.update') }}">
        @csrf
        @method('PUT')
        <x-admin.table :title="__('المناطق')">
            <thead>
                <tr>
                    <th>{{ __('اسم المنطقة') }}</th>
                    <th>{{ __('مفعّلة') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($areas as $area)
                    <tr>
                        <td>
                            @can('settings.geography.manage')
                                <input type="text" name="areas[{{ $area->id }}][name]" value="{{ $area->name }}" class="w-64 rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            @else
                                {{ $area->name }}
                            @endcan
                        </td>
                        <td>
                            @can('settings.geography.manage')
                                <input type="checkbox" name="areas[{{ $area->id }}][is_active]" value="1" @checked($area->is_active) class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                            @else
                                <x-admin.badge :tone="$area->is_active ? 'green' : 'gray'" :label="$area->is_active ? __('نعم') : __('لا')" />
                            @endcan
                        </td>
                        <td class="text-end">
                            @can('settings.geography.manage')
                                <x-admin.confirm
                                    :action="route('admin.shipping.areas.destroy', $area)"
                                    :title="__('حذف المنطقة')"
                                    :message="__('ستُحذف منطقة :name.', ['name' => $area->name])" />
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="!p-0">
                        <x-admin.empty-state
                            :title="__('لا توجد مناطق لهذه المدينة')"
                            :description="__('أضِف منطقة من النموذج أعلاه.')"
                            :icon="'M15 10.5a3 3 0 11-6 0 3 3 0 016 0z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z'" />
                    </td></tr>
                @endforelse
            </tbody>
        </x-admin.table>

        @can('settings.geography.manage')
            @if ($areas->isNotEmpty())
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="btn-primary">{{ __('حفظ المناطق') }}</button>
                </div>
            @endif
        @endcan
    </form>
</x-app-layout>
