<x-app-layout :title="__('أحداث شركة التوصيل')">
    <x-admin.header
        :title="__('أحداث شركة التوصيل الواردة')"
        :description="__('كل تحديث يصل من شركة التوصيل (webhook فوري أو مزامنة مجدولة) ونتيجته. تُظهر فورًا هل يصلك الـwebhook وهل تُطبَّق الحالات.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الشحن والتوصيل') => null, __('أحداث المزوّد') => null]" />

    <x-admin.flash />

    {{-- ملخّص آخر 24 ساعة --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-lg font-bold text-gray-900">{{ number_format($summary['total']) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('أحداث آخر 24 ساعة') }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-lg font-bold {{ $summary['webhook'] > 0 ? 'text-emerald-700' : 'text-gray-400' }}">{{ number_format($summary['webhook']) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('عبر webhook (فوري)') }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-lg font-bold text-emerald-700">{{ number_format($summary['processed']) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('طُبّقت بنجاح') }}</p>
        </div>
        <div class="rounded-lg border {{ $summary['problems'] > 0 ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white' }} p-4">
            <p class="text-lg font-bold {{ $summary['problems'] > 0 ? 'text-amber-700' : 'text-gray-400' }}">{{ number_format($summary['problems']) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('تحتاج انتباهًا') }}</p>
        </div>
    </div>

    {{-- حالة الـwebhook + الرابط --}}
    <div class="mb-5">
        @if ($lastWebhookAt)
            <x-admin.alert tone="emerald" :title="__('الـwebhook يعمل')">
                {{ __('آخر حدث فوري وصل') }}: {{ \Illuminate\Support\Carbon::parse($lastWebhookAt)->diffForHumans() }}.
            </x-admin.alert>
        @else
            <x-admin.alert tone="amber" :title="__('لم يصل أي webhook بعد')">
                {{ __('الحالات تُحدَّث حاليًا بالمزامنة المجدولة فقط (كل دقيقة). لتحديث لحظي، اطلب من شركة التوصيل تفعيل الـwebhook على هذا الرابط:') }}
                <code class="block mt-2 select-all bg-white/70 border border-amber-200 rounded px-2 py-1 text-xs font-mono ltr text-left" dir="ltr">{{ $webhookUrl }}</code>
            </x-admin.alert>
        @endif
    </div>

    {{-- فلاتر --}}
    <form method="GET" class="bg-white border border-gray-200 rounded-lg p-4 mb-4 grid grid-cols-1 sm:grid-cols-4 gap-2">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('رقم تتبّع / معرّف حدث / حالة') }}" class="rounded-md border-gray-300 text-sm" />
        <select name="channel" class="rounded-md border-gray-300 text-sm">
            <option value="">{{ __('كل القنوات') }}</option>
            <option value="webhook" @selected(($filters['channel'] ?? '') === 'webhook')>{{ __('webhook (فوري)') }}</option>
            <option value="sync" @selected(($filters['channel'] ?? '') === 'sync')>{{ __('مزامنة مجدولة') }}</option>
        </select>
        <select name="status" class="rounded-md border-gray-300 text-sm">
            <option value="">{{ __('كل النتائج') }}</option>
            @foreach (['processed' => __('طُبّق'), 'duplicate' => __('مكرّر'), 'ignored' => __('شحنة غير معروفة'), 'failed' => __('فشل'), 'unverified' => __('توقيع غير صالح'), 'inconsistent' => __('يحتاج مراجعة')] as $k => $label)
                <option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="px-3 py-2 bg-gray-700 text-white text-sm rounded-md hover:bg-gray-800">{{ __('تصفية') }}</button>
    </form>

    {{-- الجدول --}}
    <div class="admin-card overflow-x-auto">
        <table class="min-w-full text-sm text-right">
            <thead class="bg-gray-50 text-gray-500 border-b"><tr>
                <th class="py-2 px-3 font-medium">{{ __('الوقت') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('القناة') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('الشحنة') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('معرّف المزوّد') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('حالة المزوّد') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('النتيجة') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('التفاصيل') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($events as $e)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2 px-3 text-gray-500 whitespace-nowrap">{{ ($e->received_at ?? $e->created_at)?->format('m-d H:i:s') }}</td>
                        <td class="py-2 px-3">
                            <span @class(['px-2 py-0.5 rounded text-xs', 'bg-emerald-100 text-emerald-700' => $e->channel === 'webhook', 'bg-sky-100 text-sky-700' => $e->channel !== 'webhook'])>
                                {{ $e->channel === 'webhook' ? __('فوري') : __('مزامنة') }}
                            </span>
                        </td>
                        <td class="py-2 px-3">
                            @if ($e->shipment)
                                <a href="{{ route('admin.shipping.shipments.show', $e->shipment) }}" class="text-emerald-600 hover:underline">{{ $e->shipment->tracking_number ?: $e->shipment->number }}</a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-2 px-3 font-mono text-xs text-gray-500" dir="ltr">{{ $e->external_id ?: '—' }}</td>
                        <td class="py-2 px-3 font-mono text-xs" dir="ltr">{{ $e->provider_status ?: '—' }}</td>
                        <td class="py-2 px-3">
                            <span @class([
                                'px-2 py-0.5 rounded text-xs',
                                'bg-emerald-100 text-emerald-700' => $e->status === 'processed',
                                'bg-gray-100 text-gray-600' => in_array($e->status, ['duplicate', 'received']),
                                'bg-amber-100 text-amber-700' => in_array($e->status, ['ignored', 'inconsistent']),
                                'bg-rose-100 text-rose-700' => in_array($e->status, ['failed', 'unverified']),
                            ])>{{ $e->status }}</span>
                        </td>
                        <td class="py-2 px-3">
                            <a href="{{ route('admin.shipping.provider_events.show', $e) }}" class="text-emerald-600 hover:underline">{{ __('عرض') }}</a>
                            @if ($e->error)
                                <span class="text-rose-600 text-xs block truncate max-w-xs" title="{{ $e->error }}">{{ $e->error }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center text-gray-400">{{ __('لم يصل أي حدث بعد — تأكّد من تفعيل المزامنة أو الـwebhook.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $events->links() }}</div>
</x-app-layout>
