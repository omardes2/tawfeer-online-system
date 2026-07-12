<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('دفعة جديدة') }}</h2></x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />

            @if (! $order)
                <p class="text-sm text-gray-600 mb-3">{{ __('اختر طلبًا لتسجيل دفعة له:') }}</p>
                @if ($payableOrders->isEmpty())
                    <p class="text-gray-400 text-sm">{{ __('لا توجد طلبات بحاجة إلى دفع.') }}</p>
                @else
                    <ul class="divide-y text-sm">
                        @foreach ($payableOrders as $o)
                            <li class="py-2 flex items-center justify-between">
                                <span>{{ $o->number }} — {{ $o->customer_name }} <x-payment.status :status="$o->payment_status" /> ({{ $o->amount_paid }} / {{ $o->total }})</span>
                                <a href="{{ route('admin.payments.create', ['order' => $o->uuid]) }}" class="text-indigo-600 hover:underline">{{ __('دفعة') }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <div class="flex flex-wrap gap-4 text-sm mb-4">
                    <span>{{ __('الطلب') }}: <b>{{ $order->number }}</b></span>
                    <span>{{ __('الإجمالي') }}: <b>{{ $order->total }}</b></span>
                    <span>{{ __('المدفوع') }}: <b>{{ $order->amount_paid }}</b></span>
                    <span>{{ __('حالة الدفع') }}: <x-payment.status :status="$order->payment_status" /></span>
                </div>
                <form method="POST" action="{{ route('admin.payments.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="order" value="{{ $order->uuid }}" />
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-admin.field :label="__('طريقة الدفع')" name="method">
                            <select name="method" required class="w-full rounded-md border-gray-300 text-sm">
                                @foreach ($methods as $m)<option value="{{ $m->code }}">{{ $m->name }}</option>@endforeach
                            </select>
                        </x-admin.field>
                        <x-admin.field :label="__('المبلغ')" name="amount">
                            <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', $order->total - $order->amount_paid) }}" required class="w-full rounded-md border-gray-300 text-sm" />
                        </x-admin.field>
                    </div>
                    <x-admin.field :label="__('ملاحظات')" name="notes">
                        <input type="text" name="notes" value="{{ old('notes') }}" class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                    <div class="flex gap-2 pt-2">
                        <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('إنشاء الدفعة') }}</button>
                        <a href="{{ route('admin.payments.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
