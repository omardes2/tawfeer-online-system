<x-app-layout :title="__('تفاصيل حدث المزوّد')">
    <x-admin.header
        :title="__('تفاصيل الحدث الوارد')"
        :description="__('الحمولة الخام كما وصلت من شركة التوصيل — لتشخيص شكل البيانات وسبب القبول أو الرفض.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('أحداث المزوّد') => route('admin.shipping.provider_events.index'), '#'.$event->id => null]" />

    <div class="admin-card admin-card-pad mb-5 grid gap-4 md:grid-cols-3 text-sm">
        <div><span class="text-gray-500 block text-xs">{{ __('القناة') }}</span>{{ $event->channel === 'webhook' ? __('webhook (فوري)') : __('مزامنة مجدولة') }}</div>
        <div><span class="text-gray-500 block text-xs">{{ __('النتيجة') }}</span><span class="font-medium">{{ $event->status }}</span></div>
        <div><span class="text-gray-500 block text-xs">{{ __('وقت الوصول') }}</span>{{ ($event->received_at ?? $event->created_at)?->format('Y-m-d H:i:s') }}</div>
        <div><span class="text-gray-500 block text-xs">{{ __('حالة المزوّد') }}</span><span class="font-mono" dir="ltr">{{ $event->provider_status ?: '—' }}</span></div>
        <div><span class="text-gray-500 block text-xs">{{ __('معرّف المزوّد') }}</span><span class="font-mono text-xs" dir="ltr">{{ $event->external_id ?: '—' }}</span></div>
        <div><span class="text-gray-500 block text-xs">{{ __('معرّف الحدث') }}</span><span class="font-mono text-xs" dir="ltr">{{ $event->event_id ?: '—' }}</span></div>
        <div>
            <span class="text-gray-500 block text-xs">{{ __('الشحنة') }}</span>
            @if ($event->shipment)
                <a href="{{ route('admin.shipping.shipments.show', $event->shipment) }}" class="text-emerald-600 hover:underline">{{ $event->shipment->tracking_number ?: $event->shipment->number }}</a>
            @else
                <span class="text-amber-600">{{ __('لم تُطابَق أي شحنة') }}</span>
            @endif
        </div>
        <div>
            <span class="text-gray-500 block text-xs">{{ __('التوقيع') }}</span>
            {{ $event->signature_valid === null ? __('لم يُتحقَّق (بلا سرّ)') : ($event->signature_valid ? __('صالح') : __('غير صالح')) }}
        </div>
        <div><span class="text-gray-500 block text-xs">{{ __('وقت المعالجة') }}</span>{{ $event->processed_at?->format('Y-m-d H:i:s') ?: '—' }}</div>
    </div>

    @if ($event->error)
        <div class="mb-5"><x-admin.alert tone="rose" :title="__('سبب المشكلة')">{{ $event->error }}</x-admin.alert></div>
    @endif

    <div class="admin-card admin-card-pad">
        <h3 class="font-semibold text-gray-800 mb-3">{{ __('الحمولة الخام') }}</h3>
        <pre class="bg-gray-900 text-gray-100 rounded-lg p-4 text-xs overflow-x-auto select-all" dir="ltr">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</x-app-layout>
