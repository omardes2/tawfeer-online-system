@props(['class' => 'h-9', 'withName' => true])

{{--
    شعار المتجر: الصورة المرفوعة من الإعدادات إن وُجدت، وإلا حرف الاسم داخل مربّع
    بلون الهوية. لا يُعاد تصميم الشعار — يُعرض كما هو.
--}}
@php
    $logo = \App\Modules\Foundation\Services\Settings::get('store.logo');
    $siteName = __('storefront.site_name');
@endphp

<a href="{{ route('storefront.home') }}" class="inline-flex items-center gap-2.5 shrink-0" aria-label="{{ $siteName }}">
    @if ($logo)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logo) }}"
             alt="{{ $siteName }}" class="{{ $class }} w-auto object-contain" />
    @else
        <span class="grid place-items-center h-9 w-9 rounded-xl bg-brand-600 text-white font-extrabold text-lg shrink-0">ت</span>
        @if ($withName)
            <span class="font-extrabold text-lg text-[color:var(--sf-text)] hidden sm:inline whitespace-nowrap">{{ $siteName }}</span>
        @endif
    @endif
</a>
