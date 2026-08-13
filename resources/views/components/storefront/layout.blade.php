@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'image' => null,
    'pageEvent' => null,
    'noindex' => false,
    'wide' => false,
])

@php
    $locale = app()->getLocale();
    $isRtl = $locale === 'ar';
    $siteName = __('storefront.site_name');
    $pageTitle = $title ? $title.' — '.$siteName : $siteName.' · '.__('storefront.tagline');
    $metaDescription = $description ?: __('storefront.tagline');
    $canonicalUrl = $canonical ?: url()->current();
    $freeShip = config('storefront.promotions.free_shipping_threshold');

    // تنقّل رئيسي — الوجهات القائمة فقط، بلا صفحات مخترعة.
    $mainNav = [
        ['url' => route('storefront.home'), 'label' => __('storefront.home'), 'active' => request()->routeIs('storefront.home')],
        ['url' => route('storefront.categories'), 'label' => __('storefront.categories'), 'active' => request()->routeIs('storefront.categories', 'storefront.category')],
        ['url' => route('storefront.shop'), 'label' => __('storefront.shop'), 'active' => request()->routeIs('storefront.shop')],
        ['url' => route('storefront.shop', ['sort' => 'newest']), 'label' => __('storefront.new_arrivals'), 'active' => false],
        ['url' => route('storefront.brands'), 'label' => __('storefront.brands'), 'active' => request()->routeIs('storefront.brands', 'storefront.brand')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#6B2AA8">

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

    {{-- الخطّ مُستضاف محليًا داخل حزمة CSS — لا طلب لطرف ثالث --}}
    @vite(['resources/css/storefront.css', 'resources/js/storefront.js'])
</head>
<body class="min-h-screen flex flex-col antialiased sf-has-bottomnav">

    {{-- حدث تحليلات الصفحة (نقطة امتداد — بلا مزوّد) --}}
    @if ($pageEvent)
        <div id="sf-page-event" class="hidden"
             data-event="{{ $pageEvent['name'] }}"
             data-payload='@json($pageEvent['payload'] ?? [])'></div>
    @endif

    {{-- ══════════ الترويسة ══════════ --}}
    {{-- شريط علوي: خدمات وحساب (حواسيب فقط) — خارج المنطقة اللاصقة عمدًا --}}
    <div class="hidden md:block bg-brand-800 text-white/90 text-[13px]">
            <div class="sf-container flex items-center justify-between h-9">
                <div class="flex items-center gap-5">
                    <a href="{{ route('storefront.home') }}#contact" class="inline-flex items-center gap-1.5 hover:text-white transition-colors">
                        <x-storefront.icon name="phone" class="w-4 h-4" /> {{ __('storefront.top_contact') }}
                    </a>
                    <a href="{{ route('storefront.home') }}#help" class="inline-flex items-center gap-1.5 hover:text-white transition-colors">
                        <x-storefront.icon name="question" class="w-4 h-4" /> {{ __('storefront.top_help') }}
                    </a>
                    <a href="{{ auth()->check() ? route('account.orders') : route('account.login') }}"
                       class="inline-flex items-center gap-1.5 hover:text-white transition-colors">
                        <x-storefront.icon name="truck" class="w-4 h-4" /> {{ __('storefront.top_track') }}
                    </a>
                </div>

                <div class="flex items-center gap-4">
                    @auth
                        <a href="{{ route('account.dashboard') }}" class="font-semibold hover:text-white transition-colors">{{ __('storefront.my_account') }}</a>
                    @else
                        <a href="{{ route('account.login') }}" class="font-semibold hover:text-white transition-colors">{{ __('storefront.login') }}</a>
                        <span class="text-white/30">|</span>
                        <a href="{{ route('account.register') }}" class="font-semibold hover:text-white transition-colors">{{ __('storefront.register') }}</a>
                    @endauth
                    <a href="{{ route('storefront.locale', $locale === 'ar' ? 'en' : 'ar') }}"
                       class="hover:text-white transition-colors" aria-label="{{ __('storefront.language') }}">
                        {{ $locale === 'ar' ? 'EN' : 'عربي' }}
                    </a>
                </div>
            </div>
    </div>

    <header x-data="{ menu: false }" class="bg-white sticky top-0 z-40 border-b border-[color:var(--sf-border)]">
        {{-- الترويسة الرئيسية --}}
        <div class="sf-container">
            <div class="flex items-center gap-3 h-16">
                {{-- جوّال: زرّ القائمة --}}
                <button type="button" @click="menu = true"
                        class="md:hidden grid place-items-center w-10 h-10 -ms-2 rounded-xl text-[color:var(--sf-text)] hover:bg-brand-50 transition-colors"
                        aria-label="{{ __('storefront.open_menu') }}">
                    <x-storefront.icon name="menu" class="w-6 h-6" />
                </button>

                {{-- على الجوّال يتوسّط الشعار بين القائمة والسلة --}}
                <div class="md:hidden flex-1 flex justify-center">
                    <x-storefront.logo />
                </div>
                <div class="hidden md:block">
                    <x-storefront.logo />
                </div>

                {{-- البحث (حواسيب) --}}
                <form action="{{ route('storefront.search') }}" method="GET" role="search"
                      class="hidden md:block flex-1 max-w-2xl xl:max-w-4xl mx-6 lg:mx-10">
                    <label for="sf-search" class="sr-only">{{ __('storefront.search') }}</label>
                    <div class="relative" x-data="{ q: @js(request('q') ?? '') }">
                        <input id="sf-search" x-ref="sfq" type="search" name="q" x-model="q"
                               placeholder="{{ __('storefront.search_placeholder_long') }}"
                               class="sf-input !rounded-full !bg-[color:var(--sf-bg)] ps-4 pe-24 !py-3">
                        {{-- مسح النصّ دون مغادرة الصفحة --}}
                        <button type="button" x-show="q.length > 0" x-cloak @click="q = ''; $refs.sfq.focus()"
                                class="absolute inset-y-0 my-auto end-12 grid place-items-center w-9 h-9 rounded-full
                                       text-[color:var(--sf-text-soft)] hover:text-[color:var(--sf-text)] transition-colors"
                                aria-label="{{ __('storefront.clear_search') }}">
                            <x-storefront.icon name="close" class="w-4 h-4" />
                        </button>
                        <button type="submit" aria-label="{{ __('storefront.search') }}"
                                class="absolute inset-y-0 my-auto end-1 grid place-items-center w-10 h-10 rounded-full bg-brand-600 text-white hover:bg-brand-700 transition-colors">
                            <x-storefront.icon name="search" class="w-5 h-5" />
                        </button>
                    </div>
                </form>

                {{-- الأدوات --}}
                <div class="flex items-center gap-1 ms-auto shrink-0">
                    <a href="{{ auth()->check() ? route('account.wishlist') : route('account.login') }}"
                       class="hidden sm:grid place-items-center w-10 h-10 rounded-xl text-[color:var(--sf-text)] hover:bg-brand-50 hover:text-brand-600 transition-colors"
                       aria-label="{{ __('storefront.favorites') }}" title="{{ __('storefront.favorites') }}">
                        <x-storefront.icon name="heart" class="w-6 h-6" />
                    </a>

                    <a href="{{ auth()->check() ? route('account.dashboard') : route('account.login') }}"
                       class="hidden md:grid place-items-center w-10 h-10 rounded-xl text-[color:var(--sf-text)] hover:bg-brand-50 hover:text-brand-600 transition-colors"
                       aria-label="{{ __('storefront.my_account') }}" title="{{ __('storefront.my_account') }}">
                        <x-storefront.icon name="user" class="w-6 h-6" />
                    </a>

                    <a href="{{ route('storefront.cart') }}"
                       class="relative grid place-items-center w-10 h-10 rounded-xl text-[color:var(--sf-text)] hover:bg-brand-50 hover:text-brand-600 transition-colors"
                       aria-label="{{ __('storefront.cart') }}" title="{{ __('storefront.cart') }}">
                        <x-storefront.icon name="cart" class="w-6 h-6" />
                        <span x-data x-show="$store.cart.count > 0" x-text="$store.cart.count" x-cloak
                              class="absolute top-0.5 end-0.5 min-w-[18px] h-[18px] px-1 grid place-items-center
                                     rounded-full bg-[color:var(--sf-danger)] text-white text-[10px] font-bold"></span>
                    </a>
                </div>
            </div>

            {{-- البحث (جوّال) — بعرض الشاشة تحت الترويسة --}}
            <form action="{{ route('storefront.search') }}" method="GET" role="search" class="md:hidden pb-3">
                <label for="sf-search-m" class="sr-only">{{ __('storefront.search') }}</label>
                <div class="relative" x-data="{ q: @js(request('q') ?? '') }">
                    <span class="absolute inset-y-0 start-0 ps-3.5 grid place-items-center text-[color:var(--sf-text-soft)] pointer-events-none">
                        <x-storefront.icon name="search" class="w-5 h-5" />
                    </span>
                    <input id="sf-search-m" x-ref="sfqm" type="search" name="q" x-model="q"
                           placeholder="{{ __('storefront.search_placeholder_long') }}"
                           class="sf-input !rounded-full !bg-[color:var(--sf-bg)] ps-11 pe-12">
                    <button type="button" x-show="q.length > 0" x-cloak @click="q = ''; $refs.sfqm.focus()"
                            class="absolute inset-y-0 my-auto end-1 grid place-items-center w-10 h-10 rounded-full
                                   text-[color:var(--sf-text-soft)]"
                            aria-label="{{ __('storefront.clear_search') }}">
                        <x-storefront.icon name="close" class="w-5 h-5" />
                    </button>
                </div>
            </form>

            {{-- تنقّل رئيسي (حواسيب) --}}
            <nav class="hidden md:flex items-center gap-1 h-12 -mb-px" aria-label="{{ __('storefront.menu') }}">
                @foreach ($mainNav as $item)
                    <a href="{{ $item['url'] }}"
                       @class([
                           'px-4 h-full inline-flex items-center text-sm font-semibold border-b-2 transition-colors',
                           'border-brand-600 text-brand-600' => $item['active'],
                           'border-transparent text-[color:var(--sf-text)] hover:text-brand-600' => ! $item['active'],
                       ])
                       @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
        </div>

        {{-- درج القائمة (جوّال) --}}
        <div x-show="menu" x-cloak @keydown.escape.window="menu = false" class="md:hidden">
            <div x-show="menu" x-transition.opacity @click="menu = false"
                 class="fixed inset-0 z-40 sf-scrim"></div>

            {{-- يفتح من جهة زرّ القائمة: `start` = اليمين في العربية.
                 التحويلات لا تنقلب مع الاتجاه، فتُقلب يدويًا للإنجليزية. --}}
            <aside x-show="menu"
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="translate-x-full ltr:-translate-x-full"
                   x-transition:enter-end="translate-x-0"
                   x-transition:leave="transition ease-in duration-150"
                   x-transition:leave-start="translate-x-0"
                   x-transition:leave-end="translate-x-full ltr:-translate-x-full"
                   class="fixed inset-y-0 start-0 z-50 w-[82%] max-w-xs bg-white flex flex-col shadow-xl">
                <div class="flex items-center justify-between gap-3 h-16 px-4 border-b border-[color:var(--sf-border)]">
                    <x-storefront.logo />
                    <button type="button" @click="menu = false"
                            class="grid place-items-center w-10 h-10 rounded-xl text-[color:var(--sf-text-soft)] hover:bg-brand-50"
                            aria-label="{{ __('storefront.close_menu') }}">
                        <x-storefront.icon name="close" class="w-6 h-6" />
                    </button>
                </div>

                <nav class="flex-1 overflow-y-auto p-3" aria-label="{{ __('storefront.menu') }}">
                    @foreach ($mainNav as $item)
                        <a href="{{ $item['url'] }}"
                           @class([
                               'block px-3 py-3 rounded-xl text-sm font-semibold transition-colors',
                               'bg-brand-50 text-brand-600' => $item['active'],
                               'text-[color:var(--sf-text)] hover:bg-[color:var(--sf-bg)]' => ! $item['active'],
                           ])>{{ $item['label'] }}</a>
                    @endforeach

                    <hr class="my-3 border-[color:var(--sf-border)]">

                    <a href="{{ auth()->check() ? route('account.wishlist') : route('account.login') }}"
                       class="block px-3 py-3 rounded-xl text-sm font-semibold text-[color:var(--sf-text)] hover:bg-[color:var(--sf-bg)]">
                        {{ __('storefront.favorites') }}
                    </a>
                    <a href="{{ auth()->check() ? route('account.orders') : route('account.login') }}"
                       class="block px-3 py-3 rounded-xl text-sm font-semibold text-[color:var(--sf-text)] hover:bg-[color:var(--sf-bg)]">
                        {{ __('storefront.top_track') }}
                    </a>
                    <a href="{{ route('storefront.locale', $locale === 'ar' ? 'en' : 'ar') }}"
                       class="block px-3 py-3 rounded-xl text-sm font-semibold text-[color:var(--sf-text)] hover:bg-[color:var(--sf-bg)]">
                        {{ $locale === 'ar' ? 'English' : 'العربية' }}
                    </a>
                </nav>

                <div class="p-3 border-t border-[color:var(--sf-border)]">
                    @auth
                        <a href="{{ route('account.dashboard') }}" class="sf-btn-primary sf-btn-block">{{ __('storefront.my_account') }}</a>
                    @else
                        <a href="{{ route('account.login') }}" class="sf-btn-primary sf-btn-block">{{ __('storefront.login') }}</a>
                        <a href="{{ route('account.register') }}" class="sf-btn-outline sf-btn-block mt-2">{{ __('storefront.register') }}</a>
                    @endauth
                </div>
            </aside>
        </div>
    </header>

    {{-- شريط الشحن المجّاني (إن كان مضبوطًا في الإعدادات) --}}
    @if ($freeShip)
        <div class="bg-gold-300 text-[color:var(--sf-text)] text-center text-[13px] font-semibold py-2 px-4">
            {{ __('storefront.free_shipping_hint', ['amount' => number_format((float) $freeShip, 0)]) }}
        </div>
    @endif

    {{-- ══════════ المحتوى ══════════ --}}
    <main class="flex-1 w-full">
        <div class="{{ $wide ? 'w-full' : 'sf-container' }} py-5 sm:py-7">
            {{ $slot }}
        </div>
    </main>

    {{-- ══════════ التذييل ══════════ --}}
    <footer class="bg-brand-800 text-white/80 mt-10">
        <div class="sf-container py-10 grid grid-cols-2 md:grid-cols-4 gap-8 text-sm">
            <div class="col-span-2 md:col-span-1">
                <span class="block font-extrabold text-white text-lg mb-2">{{ $siteName }}</span>
                <p class="leading-relaxed">{{ __('storefront.footer_about') }}</p>
            </div>

            <div>
                <span class="block font-bold text-white mb-3">{{ __('storefront.footer_shop') }}</span>
                <ul class="space-y-2">
                    <li><a href="{{ route('storefront.shop') }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.all_products') }}</a></li>
                    <li><a href="{{ route('storefront.categories') }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.categories') }}</a></li>
                    <li><a href="{{ route('storefront.brands') }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.brands') }}</a></li>
                    <li><a href="{{ route('storefront.shop', ['sort' => 'newest']) }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.new_arrivals') }}</a></li>
                </ul>
            </div>

            <div>
                <span class="block font-bold text-white mb-3">{{ __('storefront.footer_account') }}</span>
                <ul class="space-y-2">
                    @auth
                        <li><a href="{{ route('account.dashboard') }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.my_account') }}</a></li>
                        <li><a href="{{ route('account.orders') }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.my_orders') }}</a></li>
                        <li><a href="{{ route('account.wishlist') }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.favorites') }}</a></li>
                    @else
                        <li><a href="{{ route('account.login') }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.login') }}</a></li>
                        <li><a href="{{ route('account.register') }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.register') }}</a></li>
                    @endauth
                    <li><a href="{{ route('storefront.cart') }}" class="inline-block py-2 hover:text-white transition-colors">{{ __('storefront.cart') }}</a></li>
                </ul>
            </div>

            <div id="help">
                <span class="block font-bold text-white mb-3">{{ __('storefront.footer_help') }}</span>
                <ul class="space-y-2">
                    <li class="flex items-center gap-2"><x-storefront.icon name="truck" class="w-4 h-4 shrink-0" /> {{ __('storefront.trust_delivery') }}</li>
                    <li class="flex items-center gap-2"><x-storefront.icon name="shield" class="w-4 h-4 shrink-0" /> {{ __('storefront.trust_cod') }}</li>
                    <li class="flex items-center gap-2"><x-storefront.icon name="headset" class="w-4 h-4 shrink-0" /> {{ __('storefront.trust_support') }}</li>
                </ul>
            </div>
        </div>

        <div class="border-t border-white/10">
            <div class="sf-container py-4 text-xs text-center text-white/60">
                © {{ date('Y') }} {{ $siteName }} — {{ __('storefront.all_rights') }}
            </div>
        </div>
    </footer>

    <x-storefront.bottom-nav />
</body>
</html>
