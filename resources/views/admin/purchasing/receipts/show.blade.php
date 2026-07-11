<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('إيصال استلام') }} {{ $receipt->number }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <x-admin.flash />
            <div class="flex flex-wrap gap-4 text-sm">
                <span>{{ __('أمر الشراء') }}: <b>{{ $receipt->purchaseOrder?->number }}</b></span>
                <span>{{ __('المستودع') }}: <b>{{ $receipt->warehouse?->name }}</b></span>
                <span>{{ __('الحالة') }}: <x-purchasing.status :status="$receipt->status" /></span>
                @can('pricing.view_cost')
                    <span>{{ __('تكلفة إضافية') }}: {{ $receipt->additional_cost }}</span>
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">SKU</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستلم') }}</th>
                        @can('pricing.view_cost')
                            <th class="py-2 px-3 font-medium">{{ __('تكلفة الوحدة') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('تكلفة محمّلة') }}</th>
                        @endcan
                    </tr></thead>
                    <tbody class="divide-y">
                        @foreach ($receipt->items as $item)
                            <tr>
                                <td class="py-2 px-3 text-gray-500">{{ $item->variant?->sku }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($item->qty_received, '0'), '.') }}</td>
                                @can('pricing.view_cost')
                                    <td class="py-2 px-3">{{ $item->unit_cost }}</td>
                                    <td class="py-2 px-3">{{ $item->landed_unit_cost }}</td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap gap-2 pt-2 border-t">
                @if ($receipt->status === 'draft')
                    @can('post', $receipt)
                        <form method="POST" action="{{ route('admin.purchasing.receipts.post', $receipt) }}" onsubmit="return confirm('{{ __('ترحيل الاستلام وزيادة المخزون؟') }}')">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('ترحيل') }}</button></form>
                    @endcan
                @endif
                <a href="{{ route('admin.purchasing.receipts.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('رجوع') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
