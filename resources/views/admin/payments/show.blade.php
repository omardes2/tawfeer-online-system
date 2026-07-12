<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('دفعة') }} {{ $payment->number }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <x-admin.flash />
            <div class="flex flex-wrap gap-4 text-sm">
                <span>{{ __('الطلب') }}: <b>{{ $payment->order?->number }}</b></span>
                <span>{{ __('الطريقة') }}: <b>{{ $payment->method?->name }}</b></span>
                <span>{{ __('المبلغ') }}: <b>{{ $payment->amount }}</b></span>
                <span>{{ __('المُسترَد') }}: {{ $payment->refunded_amount }}</span>
                <span>{{ __('الحالة') }}: <x-payment.status :status="$payment->status" /></span>
            </div>
            @if ($payment->provider_reference)
                <p class="text-sm text-gray-500">{{ __('مرجع المزوّد') }}: {{ $payment->provider_reference }}</p>
            @endif

            <div class="flex flex-wrap gap-2 pt-2 border-t" x-data="{ refunding: false }">
                @if (in_array($payment->status, ['pending', 'processing']))
                    @can('capture', $payment)
                        <form method="POST" action="{{ route('admin.payments.capture', $payment) }}">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('تحصيل') }}</button></form>
                    @endcan
                @endif
                @if (in_array($payment->status, ['paid', 'partially_refunded']))
                    @can('refund', $payment)
                        <button type="button" @click="refunding = true" x-show="!refunding" class="px-4 py-2 bg-rose-100 text-rose-700 text-sm rounded-md hover:bg-rose-200">{{ __('استرداد') }}</button>
                        <form method="POST" action="{{ route('admin.payments.refund', $payment) }}" x-show="refunding" x-cloak class="flex gap-2 items-center">@csrf
                            <input type="number" step="0.01" min="0.01" name="amount" placeholder="{{ __('المبلغ (فارغ = كامل)') }}" class="rounded-md border-gray-300 text-sm w-40" />
                            <button class="px-3 py-2 bg-rose-600 text-white text-sm rounded-md hover:bg-rose-700">{{ __('تأكيد الاسترداد') }}</button>
                        </form>
                    @endcan
                @endif
                <a href="{{ route('admin.payments.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('رجوع') }}</a>
            </div>

            <div class="border-t pt-3">
                <h3 class="text-sm font-medium text-gray-700 mb-2">{{ __('سجلّ العمليات') }}</h3>
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('النوع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المبلغ') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('التاريخ') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @foreach ($payment->transactions as $t)
                            <tr>
                                <td class="py-2 px-3">{{ $t->kind }}</td>
                                <td class="py-2 px-3"><x-payment.status :status="$t->status" /></td>
                                <td class="py-2 px-3">{{ $t->amount }}</td>
                                <td class="py-2 px-3 text-gray-400">{{ $t->created_at?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
