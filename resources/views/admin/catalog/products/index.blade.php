<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('app.modules.catalog') }} — {{ __('المنتجات') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('المنتجات')">
                @can('create', \App\Modules\Catalog\Models\Product::class)
                    <a href="{{ route('admin.products.create') }}" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('منتج جديد') }}</a>
                @endcan
            </x-admin.header>

            <form method="GET" class="mb-4 flex flex-wrap gap-2">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('بحث بالاسم أو SKU...') }}" class="w-full sm:w-72 rounded-md border-gray-300 text-sm" />
                <select name="status" class="rounded-md border-gray-300 text-sm" onchange="this.form.submit()">
                    <option value="">{{ __('كل الحالات') }}</option>
                    @foreach (['draft' => 'مسودّة', 'active' => 'مفعّل', 'archived' => 'مؤرشف'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(request('status') === $val)>{{ __($lbl) }}</option>
                    @endforeach
                </select>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الصورة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                        <th class="py-2 px-3 font-medium">SKU</th>
                        <th class="py-2 px-3 font-medium">{{ __('الفئة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراءات') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($products as $product)
                            <tr>
                                <td class="py-2 px-3">
                                    @if ($product->primaryImage)
                                        <img src="{{ $product->primaryImage->url() }}" alt="" class="w-10 h-10 rounded object-cover bg-gray-100" />
                                    @else
                                        <span class="inline-block w-10 h-10 rounded bg-gray-100"></span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-gray-800">{{ $product->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $product->sku }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $product->category?->name }}</td>
                                <td class="py-2 px-3">
                                    @php $map = ['active' => ['bg-emerald-100 text-emerald-700','مفعّل'], 'draft' => ['bg-amber-100 text-amber-700','مسودّة'], 'archived' => ['bg-gray-100 text-gray-500','مؤرشف']]; @endphp
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $map[$product->status][0] ?? '' }}">{{ __($map[$product->status][1] ?? $product->status) }}</span>
                                    @if ($product->is_featured)<span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700">{{ __('مميّز') }}</span>@endif
                                </td>
                                <td class="py-2 px-3"><div class="flex gap-2">
                                    @can('update', $product)<a href="{{ route('admin.products.edit', $product) }}" class="text-indigo-600 hover:underline">{{ __('تعديل') }}</a>@endcan
                                    @can('delete', $product)<form method="POST" action="{{ route('admin.products.destroy', $product) }}" onsubmit="return confirm('{{ __('تأكيد الحذف؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 hover:underline">{{ __('حذف') }}</button></form>@endcan
                                </div></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا توجد منتجات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $products->links() }}</div>
        </div>
    </div>
</x-app-layout>
