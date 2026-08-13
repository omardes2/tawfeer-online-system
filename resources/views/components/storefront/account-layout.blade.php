@props(['title' => null, 'active' => 'dashboard'])

{{--
    إطار منطقة الحساب.

    التنقّل نفسه بشكلين حسب المساحة: تبويبات أفقية تُمرَّر بالإصبع على الجوّال
    (٨ روابط رأسية كانت تدفع محتوى الصفحة أسفل الشاشة كلّها)، وشريط جانبي لاصق
    على الحواسيب. المصفوفة واحدة فلا يتفرّع مصدر التنقّل.
--}}
@php
    $nav = [
        'dashboard' => ['label' => __('account.overview'), 'route' => 'account.dashboard', 'icon' => 'grid'],
        'orders' => ['label' => __('account.orders'), 'route' => 'account.orders', 'icon' => 'box'],
        'wishlist' => ['label' => __('account.wishlist'), 'route' => 'account.wishlist', 'icon' => 'heart'],
        'addresses' => ['label' => __('account.addresses'), 'route' => 'account.addresses', 'icon' => 'map-pin'],
        'notifications' => ['label' => __('account.notifications'), 'route' => 'account.notifications', 'icon' => 'bell'],
        'preferences' => ['label' => __('account.preferences'), 'route' => 'account.preferences', 'icon' => 'cog'],
        'providers' => ['label' => __('account.linked_providers'), 'route' => 'account.providers', 'icon' => 'link'],
        'profile' => ['label' => __('account.settings'), 'route' => 'account.profile', 'icon' => 'user'],
    ];
    $user = auth()->user();
    $initial = mb_substr(trim((string) $user?->name), 0, 1) ?: '؟';
@endphp

<x-storefront.layout :title="$title ?? __('account.my_account')" :noindex="true">
    <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-5 lg:gap-6">

        {{-- ══ التنقّل ══ --}}
        <aside class="min-w-0">
            {{-- الجوّال: بطاقة هوية + تبويبات ممرَّرة --}}
            <div class="lg:hidden">
                <div class="flex items-center gap-3 mb-3">
                    <span class="grid place-items-center w-11 h-11 rounded-full bg-brand-600 text-white font-bold text-lg shrink-0">
                        {{ $initial }}
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs text-[color:var(--sf-text-soft)]">{{ __('account.hello') }}</p>
                        <p class="font-bold text-[color:var(--sf-text)] truncate">{{ $user?->name }}</p>
                    </div>
                </div>

                <div class="sf-scroll-x -mx-4 px-4 pb-1" role="tablist" aria-label="{{ __('account.my_account') }}">
                    @foreach ($nav as $key => $item)
                        <a href="{{ route($item['route']) }}"
                           @class(['sf-acct-tab', 'is-active' => $active === $key])
                           @if ($active === $key) aria-current="page" @endif>
                            <x-storefront.icon :name="$item['icon']" />
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- الحواسيب: شريط جانبي لاصق --}}
            <div class="hidden lg:block sf-card overflow-hidden lg:sticky lg:top-[7.5rem]">
                <div class="flex items-center gap-3 p-4 border-b border-[color:var(--sf-border)]">
                    <span class="grid place-items-center w-11 h-11 rounded-full bg-brand-600 text-white font-bold text-lg shrink-0">
                        {{ $initial }}
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs text-[color:var(--sf-text-soft)]">{{ __('account.hello') }}</p>
                        <p class="font-bold text-[color:var(--sf-text)] truncate">{{ $user?->name }}</p>
                    </div>
                </div>

                <nav class="p-2 space-y-0.5" aria-label="{{ __('account.my_account') }}">
                    @foreach ($nav as $key => $item)
                        <a href="{{ route($item['route']) }}"
                           @class(['sf-acct-link', 'is-active' => $active === $key])
                           @if ($active === $key) aria-current="page" @endif>
                            <x-storefront.icon :name="$item['icon']" />
                            {{ $item['label'] }}
                        </a>
                    @endforeach

                    <form method="POST" action="{{ route('account.logout') }}" class="pt-1">
                        @csrf
                        <button type="submit" class="sf-acct-link is-danger w-full">
                            <x-storefront.icon name="logout" />
                            {{ __('account.logout') }}
                        </button>
                    </form>
                </nav>
            </div>
        </aside>

        {{-- ══ المحتوى ══ --}}
        <div class="min-w-0">
            @if (session('status'))
                <div class="sf-alert sf-alert-success" role="status">
                    <x-storefront.icon name="check-circle" />
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            {{ $slot }}

            {{-- الخروج على الجوّال: أسفل الصفحة، بعيدًا عن أزرار التنقّل --}}
            <form method="POST" action="{{ route('account.logout') }}" class="lg:hidden mt-6">
                @csrf
                <button type="submit" class="sf-acct-link is-danger w-full justify-center bg-white border border-[color:var(--sf-border)]">
                    <x-storefront.icon name="logout" />
                    {{ __('account.logout') }}
                </button>
            </form>
        </div>
    </div>
</x-storefront.layout>
