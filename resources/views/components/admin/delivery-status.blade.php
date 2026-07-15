{{-- مؤشّر ضمان وصول الطلبات لشركة التوصيل: مزوّد التوصيل + طلبات لم تُرسَل بعد. --}}
@php($h = \App\Support\SystemHealth::delivery())

<div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
    {{-- مزوّد التوصيل --}}
    @if ($h['enabled'])
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 text-emerald-700 px-3 py-1">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            {{ __('التوصيل') }}: {{ __('مفعّل') }} <span class="text-emerald-500/80 font-mono">({{ $h['provider'] }})</span>
        </span>
    @else
        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 text-rose-700 px-3 py-1">
            <span class="h-2 w-2 rounded-full bg-rose-500"></span>
            {{ __('التوصيل متوقّف') }} — {{ __('الطلبات لن تُرسَل لشركة التوصيل') }}
        </span>
    @endif

    {{-- حالة وصول الطلبات لشركة التوصيل --}}
    @if ($h['enabled'])
        @if ($h['stuck'] === 0)
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 text-emerald-700 px-3 py-1">
                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                {{ __('كل الطلبات مُرسَلة لشركة التوصيل') }}
            </span>
        @else
            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 text-amber-700 px-3 py-1">
                <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                {{ __(':n طلب لم يُرسَل لشركة التوصيل بعد', ['n' => $h['stuck']]) }}
            </span>
        @endif
    @endif

    {{-- تلميح عند وجود مشكلة --}}
    @unless ($h['ok'])
        <span class="text-xs text-gray-400">
            @if (! $h['enabled'])
                {{ __('اضبط SHIPPING_PROVIDER=opost في .env ثم config:cache.') }}
            @else
                {{ __('تُعاد المحاولة تلقائيًا كل دقيقة — تأكّد أن المجدول يعمل (cron: schedule:run).') }}
            @endif
        </span>
    @endunless
</div>
