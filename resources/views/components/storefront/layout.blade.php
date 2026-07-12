@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'image' => null,
    'pageEvent' => null,
    'noindex' => false,
])

@php
    $locale = app()->getLocale();
    $isRtl = $locale === 'ar';
    $siteName = __('storefront.site_name');
    $pageTitle = $title ? $title.' — '.$siteName : $siteName.' · '.__('storefront.tagline');
    $metaDescription = $description ?: __('storefront.tagline');
    $canonicalUrl = $canonical ?: url()->current();
    $freeShip = config('storefront.promotions.free_shipping_threshold');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- SEO --}}
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    @if ($noindex)
        <meta name="robots" content="noindex, nofollow">
    @endif
    <link rel="canonical" href="{{ $canonicalUrl }}">

    {{-- Open Graph / social sharing --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_AR' : 'en_US' }}">
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
    @endif
    <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    @if ($image)
        <meta name="twitter:image" content="{{ $image }}">
    @endif

    {{-- Structured data (JSON-LD) — pages push here --}}
    @stack('structured-data')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=tajawal:400,500,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/storefront.css', 'resources/js/storefront.js'])
</head>
<body class="min-h-screen flex flex-col bg-gray-50 text-gray-800 antialiased">

    {{-- حدث تحليلات الصفحة (نقطة امتداد — بلا مزوّد) --}}
    @if ($pageEvent)
        <div id="sf-page-event" class="hidden"
             data-event="{{ $pageEvent['name'] }}"
             data-payload='@json($pageEvent['payload'] ?? [])'></div>
    @endif

    @if ($freeShip)
        <div class="bg-emerald-600 text-white text-center text-sm py-2 px-4">
            {{ __('storefront.free_shipping_hint', ['amount' => number_format((float) $freeShip, 0)]) }}
        </div>
    @endif

    {{-- الترويسة --}}
    <header class="bg-white border-b border-gray-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3 h-16">
                <a href="{{ route('storefront.home') }}" class="flex items-center gap-2 shrink-0">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-600 text-white font-bold">ت</span>
                    <span class="font-bold text-lg text-gray-900 hidden sm:inline">{{ $siteName }}</span>
                </a>

                {{-- البحث --}}
                <form action="{{ route('storefront.search') }}" method="GET" role="search"
                      class="flex-1 max-w-xl mx-auto">
                    <label for="sf-search" class="sr-only">{{ __('storefront.search') }}</label>
                    <div class="relative">
                        <input id="sf-search" type="search" name="q" value="{{ request('q') }}"
                               placeholder="{{ __('storefront.search_placeholder') }}"
                               class="w-full rounded-full border-gray-300 ps-4 pe-10 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <button type="submit" aria-label="{{ __('storefront.search') }}"
                                class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-emerald-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.3-4.3M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16Z"/></svg>
                        </button>
                    </div>
                </form>

                <div class="flex items-center gap-1 shrink-0">
                    {{-- مبدّل اللغة --}}
                    <a href="{{ route('storefront.locale', $locale === 'ar' ? 'en' : 'ar') }}"
                       class="px-2 py-1.5 rounded-md text-sm text-gray-600 hover:bg-gray-100" aria-label="{{ __('storefront.language') }}">
                        {{ $locale === 'ar' ? 'EN' : 'ع' }}
                    </a>

                    {{-- الحساب --}}
                    <a href="{{ auth()->check() ? route('account.dashboard') : route('account.login') }}"
                       class="p-2 rounded-md text-gray-700 hover:bg-gray-100" aria-label="{{ __('account.my_account') }}">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.98 19.5a6 6 0 0 0-11.96 0M12 12.75a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z"/></svg>
                    </a>

                    {{-- السلة --}}
                    <a href="{{ route('storefront.cart') }}" class="relative p-2 rounded-md text-gray-700 hover:bg-gray-100"
                       aria-label="{{ __('storefront.cart') }}">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.4l2.1 12.3a1.5 1.5 0 0 0 1.48 1.2h9.4a1.5 1.5 0 0 0 1.47-1.17l1.6-7.03H6.1M9 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm9 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg>
                        <span x-data x-show="$store.cart.count > 0" x-text="$store.cart.count" x-cloak
                              class="absolute -top-0.5 -end-0.5 min-w-5 h-5 px-1 inline-flex items-center justify-center rounded-full bg-emerald-600 text-white text-xs font-bold"></span>
                    </a>
                </div>
            </div>

            {{-- تنقّل ثانوي --}}
            <nav class="flex items-center gap-4 h-11 text-sm overflow-x-auto" aria-label="{{ __('storefront.menu') }}">
                <a href="{{ route('storefront.shop') }}" class="text-gray-600 hover:text-emerald-600 whitespace-nowrap">{{ __('storefront.shop') }}</a>
                <a href="{{ route('storefront.categories') }}" class="text-gray-600 hover:text-emerald-600 whitespace-nowrap">{{ __('storefront.categories') }}</a>
                <a href="{{ route('storefront.brands') }}" class="text-gray-600 hover:text-emerald-600 whitespace-nowrap">{{ __('storefront.brands') }}</a>
            </nav>
        </div>
    </header>

    <main class="flex-1 w-full">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            {{ $slot }}
        </div>
    </main>

    <footer class="bg-white border-t border-gray-200 mt-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 text-sm text-gray-500 flex flex-col sm:flex-row items-center justify-between gap-2">
            <span>© {{ date('Y') }} {{ $siteName }} — {{ __('storefront.all_rights') }}</span>
            <div class="flex items-center gap-4">
                <a href="{{ route('storefront.categories') }}" class="hover:text-emerald-600">{{ __('storefront.categories') }}</a>
                <a href="{{ route('storefront.brands') }}" class="hover:text-emerald-600">{{ __('storefront.brands') }}</a>
            </div>
        </div>
    </footer>

    <style>[x-cloak]{display:none!important}</style>
</body>
</html>
