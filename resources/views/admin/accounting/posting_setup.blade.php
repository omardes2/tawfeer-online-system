<x-app-layout :title="__('إعدادات الترحيل المحاسبي')">
    <x-admin.header
        :title="__('إعدادات الترحيل المحاسبي')"
        :description="__('اربط كل وظيفة محاسبية بحساب من دليل الحسابات. تُستخدم تلقائيًا عند ترحيل المستندات (مبيعات/مشتريات).')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المحاسبة') => null, __('إعدادات الترحيل') => null]" />

    <x-admin.flash />

    @php($labels = ['sales_invoice' => __('فاتورة المبيعات'), 'purchase_invoice' => __('فاتورة المشتريات')])

    <form method="POST" action="{{ route('admin.accounting.posting_setup.update') }}" class="space-y-6">
        @csrf @method('PUT')

        @foreach ($documents as $documentType => $functions)
            <x-admin.form-section :title="$labels[$documentType] ?? $documentType">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-gray-500 border-b border-gray-100">
                            <tr>
                                <th class="py-2 px-3 font-medium text-start w-1/2">{{ __('الوظيفة') }}</th>
                                <th class="py-2 px-3 font-medium text-start">{{ __('الحساب') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($functions as $function => $label)
                                @php($key = $documentType.'|'.$function)
                                <tr>
                                    <td class="py-2.5 px-3 text-gray-800">{{ __($label) }}</td>
                                    <td class="py-2.5 px-3">
                                        <select name="mappings[{{ $key }}]" class="w-full max-w-md rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                            <option value="">{{ __('— بدون —') }}</option>
                                            @foreach ($accounts as $acc)
                                                <option value="{{ $acc->id }}" @selected(($map[$key] ?? null) == $acc->id)>{{ $acc->code }} — {{ $acc->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-admin.form-section>
        @endforeach

        <div class="flex justify-end">
            <button type="submit" class="btn-primary">{{ __('حفظ الإعدادات') }}</button>
        </div>
    </form>
</x-app-layout>
