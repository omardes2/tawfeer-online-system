{{-- مؤشّر حالة إرسال الطلبات لشركة التوصيل: مزوّد التوصيل + عامل الطابور. --}}
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

    {{-- عامل الطابور --}}
    @if (! $h['queue_healthy'])
        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 text-amber-700 px-3 py-1">
            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
            {{ __('الطابور متوقّف؟') }} — {{ __(':n مهمة معلّقة', ['n' => $h['pending']]) }}
            @if ($h['oldest_age'] !== null) ({{ __('منذ :m دقيقة', ['m' => intdiv((int) $h['oldest_age'], 60)]) }}) @endif
        </span>
    @elseif ($h['enabled'])
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 text-emerald-700 px-3 py-1">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            {{ __('الطابور يعمل') }}
        </span>
    @endif

    {{-- مهام فاشلة --}}
    @if ($h['failed'] > 0)
        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 text-rose-700 px-3 py-1">
            <span class="h-2 w-2 rounded-full bg-rose-500"></span>
            {{ __(':n مهمة فاشلة', ['n' => $h['failed']]) }}
        </span>
    @endif

    {{-- تلميح عند وجود مشكلة --}}
    @unless ($h['ok'])
        <span class="text-xs text-gray-400">
            @if (! $h['enabled'])
                {{ __('اضبط SHIPPING_PROVIDER=opost في .env ثم config:cache.') }}
            @else
                {{ __('تأكّد أن عامل الطابور يعمل (supervisor / queue:work).') }}
            @endif
        </span>
    @endunless
</div>
