<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('عميل') }} — {{ $customer->name }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-5">
            <x-admin.flash />
            <div class="flex flex-wrap gap-4 text-sm items-center">
                <span>{{ __('الهاتف') }}: <b>{{ $customer->primary_phone }}</b></span>
                <span>{{ __('البريد') }}: {{ $customer->email ?: '—' }}</span>
                <span>{{ __('التصنيف') }}: {{ $customer->category ?: '—' }}</span>
                @if ($customer->glAccount)<span>{{ __('الحساب المحاسبي') }}: <b class="font-mono">{{ $customer->glAccount->code }}</b></span>@endif
                @if ($customer->is_blocked)<span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-rose-100 text-rose-700">{{ __('محظور') }}: {{ $customer->blocked_reason }}</span>@endif
                @can('update', $customer)
                    <a href="{{ route('admin.crm.customers.edit', $customer) }}" class="text-emerald-600 hover:underline">{{ __('تعديل') }}</a>
                @endcan
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                <div class="border rounded-md p-3">
                    <div class="font-medium text-gray-700 mb-1">{{ __('الهواتف') }}</div>
                    <ul class="text-gray-600 space-y-0.5">
                        @foreach ($customer->phones as $p)<li>{{ $p->phone }} @if($p->is_primary)<span class="text-xs text-emerald-600">({{ __('أساسي') }})</span>@endif</li>@endforeach
                    </ul>
                </div>
                <div class="border rounded-md p-3">
                    <div class="font-medium text-gray-700 mb-1">{{ __('العناوين') }}</div>
                    <ul class="text-gray-600 space-y-0.5">
                        @forelse ($customer->addresses as $a)<li>{{ $a->label }}: {{ $a->address_line }} @if($a->is_default)<span class="text-xs text-emerald-600">({{ __('افتراضي') }})</span>@endif</li>@empty<li class="text-gray-400">—</li>@endforelse
                    </ul>
                </div>
                <div class="border rounded-md p-3">
                    <div class="font-medium text-gray-700 mb-1">{{ __('المؤشّرات') }}</div>
                    <div class="text-gray-600">{{ __('نقاط الولاء') }}: {{ $customer->loyalty_points }}</div>
                    <div class="text-gray-600">{{ __('حدّ الائتمان') }}: {{ $customer->credit_limit }}</div>
                    <div class="text-gray-600">{{ __('إلغاءات') }}: {{ $customer->cancelled_orders_count }} · {{ __('مرتجعات') }}: {{ $customer->returns_count }}</div>
                </div>
            </div>

            {{-- إجراءات الحظر --}}
            <div class="flex flex-wrap gap-2 border-t pt-3" x-data="{ blocking: false }">
                @can('block', $customer)
                    @if ($customer->is_blocked)
                        <form method="POST" action="{{ route('admin.crm.customers.unblock', $customer) }}">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('رفع الحظر') }}</button></form>
                    @else
                        <button type="button" @click="blocking = true" x-show="!blocking" class="px-4 py-2 bg-rose-100 text-rose-700 text-sm rounded-md hover:bg-rose-200">{{ __('حظر') }}</button>
                        <form method="POST" action="{{ route('admin.crm.customers.block', $customer) }}" x-show="blocking" x-cloak class="flex gap-2 items-center">@csrf
                            <input type="text" name="reason" required placeholder="{{ __('سبب الحظر') }}" class="rounded-md border-gray-300 text-sm" />
                            <button class="px-3 py-2 bg-rose-600 text-white text-sm rounded-md hover:bg-rose-700">{{ __('تأكيد') }}</button>
                        </form>
                    @endif
                @endcan
            </div>

            {{-- الملاحظات --}}
            <div class="border-t pt-3">
                <h3 class="text-sm font-medium text-gray-700 mb-2">{{ __('الملاحظات') }}</h3>
                @can('update', $customer)
                    <form method="POST" action="{{ route('admin.crm.customers.notes.store', $customer) }}" class="flex gap-2 mb-3">@csrf
                        <input type="text" name="body" required placeholder="{{ __('أضف ملاحظة') }}" class="rounded-md border-gray-300 text-sm flex-1" />
                        <button class="px-3 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('إضافة') }}</button>
                    </form>
                @endcan
                <ul class="text-sm space-y-1">
                    @forelse ($customer->customerNotes as $n)
                        <li class="text-gray-600"><span class="text-gray-400">{{ $n->created_at?->format('Y-m-d H:i') }}</span> — {{ $n->body }} @if($n->author)<span class="text-gray-400">({{ $n->author->name }})</span>@endif</li>
                    @empty
                        <li class="text-gray-400">{{ __('لا ملاحظات.') }}</li>
                    @endforelse
                </ul>
            </div>

            {{-- سجلّ الطلبات (BR-CUST-07) --}}
            <div class="border-t pt-3">
                <h3 class="text-sm font-medium text-gray-700 mb-2">{{ __('سجلّ الطلبات') }}</h3>
                @if ($customer->orders->isEmpty())
                    <p class="text-gray-400 text-sm">{{ __('لا طلبات.') }}</p>
                @else
                    <ul class="text-sm space-y-1">
                        @foreach ($customer->orders as $o)
                            <li><a href="{{ route('admin.sales.orders.show', $o) }}" class="text-emerald-600 hover:underline">{{ $o->number }}</a> — {{ $o->status }} — {{ $o->total }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <a href="{{ route('admin.crm.customers.index') }}" class="inline-block px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('رجوع') }}</a>
        </div>
    </div>
</x-app-layout>
