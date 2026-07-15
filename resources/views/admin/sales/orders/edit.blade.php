<x-app-layout :title="__('تعديل الطلب')">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">{{ __('تعديل الطلب') }} {{ $order->number }}</h2>
    </x-slot>

    <div class="py-8 max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 space-y-5">
            <x-admin.flash />

            <p class="text-sm text-gray-500">
                {{ __('تعديل بيانات التواصل والتوصيل فقط لتصحيح البيانات الخاطئة. لا يؤثّر على الأصناف أو القيود المحاسبية أو المخزون.') }}
            </p>

            <form method="POST" action="{{ route('admin.sales.orders.update', $order) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('اسم العميل') }} <span class="text-rose-500">*</span></label>
                    <input type="text" name="customer_name" required
                           value="{{ old('customer_name', $order->customer_name) }}"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    @error('customer_name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('رقم الهاتف') }} <span class="text-rose-500">*</span></label>
                    <input type="text" name="customer_phone" required inputmode="numeric" dir="ltr"
                           value="{{ old('customer_phone', $order->customer_phone) }}"
                           placeholder="0599123456"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 text-right" />
                    @error('customer_phone')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('البريد الإلكتروني') }}</label>
                    <input type="email" name="customer_email" dir="ltr"
                           value="{{ old('customer_email', $order->customer_email) }}"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 text-right" />
                    @error('customer_email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('العنوان') }} <span class="text-rose-500">*</span></label>
                    <textarea name="shipping_address" rows="3" required
                              class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('shipping_address', $order->shipping_address) }}</textarea>
                    @error('shipping_address')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('ملاحظات') }}</label>
                    <textarea name="notes" rows="2"
                              class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('notes', $order->notes) }}</textarea>
                    @error('notes')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2 border-t">
                    <a href="{{ route('admin.sales.orders.show', $order) }}"
                       class="text-center px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">{{ __('إلغاء') }}</a>
                    <button type="submit"
                            class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">{{ __('حفظ التعديلات') }}</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
