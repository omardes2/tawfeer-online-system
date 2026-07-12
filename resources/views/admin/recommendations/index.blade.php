<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('توصيات المنتجات اليدوية') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />

        {{-- إضافة قاعدة --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('إضافة قاعدة (تثبيت أو حجب)') }}</h3>
            <form method="POST" action="{{ route('admin.recommendations.store') }}" class="grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
                @csrf
                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">{{ __('المنتج') }}</label>
                    <select name="product_id" required class="w-full rounded-md border-gray-300 text-sm">
                        @foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">{{ __('المنتج الموصى به') }}</label>
                    <select name="recommended_product_id" required class="w-full rounded-md border-gray-300 text-sm">
                        @foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">{{ __('النوع') }}</label>
                    <select name="type" class="w-full rounded-md border-gray-300 text-sm">
                        @foreach (['related' => 'ذات صلة', 'cross_sell' => 'بيع تكميلي', 'upsell' => 'ترقية', 'complementary' => 'حزمة/ملحق', 'fbt' => 'يُشترى معًا'] as $v => $l)<option value="{{ $v }}">{{ __($l) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">{{ __('الإجراء') }}</label>
                    <select name="kind" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="include">{{ __('تثبيت') }}</option>
                        <option value="exclude">{{ __('حجب') }}</option>
                    </select>
                </div>
                <div class="sm:col-span-6 flex items-end gap-2">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">{{ __('الترتيب') }}</label>
                        <input type="number" name="position" min="0" value="0" class="w-24 rounded-md border-gray-300 text-sm" />
                    </div>
                    <button class="px-4 py-2 bg-fuchsia-600 text-white text-sm rounded-md hover:bg-fuchsia-700">{{ __('حفظ') }}</button>
                </div>
                @foreach ($errors->all() as $e)<p class="sm:col-span-6 text-xs text-rose-600">{{ $e }}</p>@endforeach
            </form>
        </div>

        {{-- القواعد الحالية --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <table class="w-full text-sm">
                <thead class="text-gray-500 text-start border-b">
                    <tr class="text-start">
                        <th class="py-2 text-start">{{ __('المنتج') }}</th>
                        <th class="py-2 text-start">{{ __('الموصى به') }}</th>
                        <th class="py-2 text-start">{{ __('النوع') }}</th>
                        <th class="py-2 text-start">{{ __('الإجراء') }}</th>
                        <th class="py-2 text-start">{{ __('الترتيب') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rules as $rule)
                        <tr class="border-b last:border-0">
                            <td class="py-2">{{ $rule->product?->name }}</td>
                            <td class="py-2">{{ $rule->recommended?->name }}</td>
                            <td class="py-2">{{ $rule->type }}</td>
                            <td class="py-2">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $rule->kind === 'exclude' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' }}">
                                    {{ $rule->kind === 'exclude' ? __('حجب') : __('تثبيت') }}
                                </span>
                            </td>
                            <td class="py-2">{{ $rule->position }}</td>
                            <td class="py-2 text-end">
                                <form method="POST" action="{{ route('admin.recommendations.destroy', $rule) }}" onsubmit="return confirm('{{ __('حذف القاعدة؟') }}')">
                                    @csrf @method('DELETE')
                                    <button class="text-rose-600 text-xs hover:underline">{{ __('حذف') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-center text-gray-400">{{ __('لا توجد قواعد بعد.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $rules->links() }}</div>
        </div>
    </div>
</x-app-layout>
