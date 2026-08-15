<x-app-layout :title="$editing ? __('تعديل شحنة') : __('شحنة استيراد جديدة')">
    <x-admin.header
        :title="$editing ? __('تعديل شحنة :n', ['n' => $shipment->number]) : __('شحنة استيراد جديدة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('شحنات الاستيراد') => route('admin.purchasing.shipments.index'), ($editing ? $shipment->number : __('جديدة')) => null]" />

    <x-admin.flash />

    <form method="POST" action="{{ $editing ? route('admin.purchasing.shipments.update', $shipment) : route('admin.purchasing.shipments.store') }}" class="space-y-6">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-admin.form-section :title="__('بيانات الشحنة')" :cols="2">
            <x-admin.field :label="__('رقم الكونتينر / مرجع الشحنة')" name="reference">
                <input type="text" name="reference" value="{{ old('reference', $editing ? $shipment->reference : '') }}"
                       placeholder="{{ __('مثال: MSKU1234567') }}"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>

            <x-admin.field :label="__('المورد')" name="supplier_id">
                <select name="supplier_id" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('— بلا مورد محدّد —') }}</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}" @selected(old('supplier_id', $editing ? $shipment->supplier_id : null) == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </x-admin.field>

            <x-admin.field :label="__('تاريخ الشحن')" name="shipped_at">
                <input type="date" name="shipped_at" value="{{ old('shipped_at', $editing ? $shipment->shipped_at?->toDateString() : '') }}"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>

            <x-admin.field :label="__('تاريخ الوصول')" name="arrived_at">
                <input type="date" name="arrived_at" value="{{ old('arrived_at', $editing ? $shipment->arrived_at?->toDateString() : '') }}"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>
        </x-admin.form-section>

        <x-admin.field :label="__('ملاحظات')" name="notes">
            <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">{{ old('notes', $editing ? $shipment->notes : '') }}</textarea>
        </x-admin.field>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ $editing ? route('admin.purchasing.shipments.show', $shipment) : route('admin.purchasing.shipments.index') }}" class="btn-secondary">{{ __('إلغاء') }}</a>
            <button type="submit" class="btn-primary">{{ __('حفظ') }}</button>
        </div>
    </form>
</x-app-layout>
