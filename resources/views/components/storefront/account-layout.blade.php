@props(['title' => null, 'active' => 'dashboard'])

@php
    $nav = [
        'dashboard' => ['label' => __('account.overview'), 'route' => 'account.dashboard'],
        'orders' => ['label' => __('account.orders'), 'route' => 'account.orders'],
        'wishlist' => ['label' => __('account.wishlist'), 'route' => 'account.wishlist'],
        'addresses' => ['label' => __('account.addresses'), 'route' => 'account.addresses'],
        'notifications' => ['label' => __('account.notifications'), 'route' => 'account.notifications'],
        'preferences' => ['label' => __('account.preferences'), 'route' => 'account.preferences'],
        'profile' => ['label' => __('account.settings'), 'route' => 'account.profile'],
    ];
@endphp

<x-storefront.layout :title="$title ?? __('account.my_account')" :noindex="true">
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {{-- الشريط الجانبي --}}
        <aside class="lg:col-span-1">
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="p-4 border-b border-gray-100">
                    <p class="text-sm text-gray-500">{{ __('account.hello') }}</p>
                    <p class="font-bold text-gray-900 truncate">{{ auth()->user()->name }}</p>
                </div>
                <nav class="p-2" aria-label="{{ __('account.my_account') }}">
                    @foreach ($nav as $key => $item)
                        <a href="{{ route($item['route']) }}"
                           @class([
                               'block px-3 py-2 rounded-lg text-sm',
                               'bg-emerald-50 text-emerald-700 font-medium' => $active === $key,
                               'text-gray-700 hover:bg-gray-50' => $active !== $key,
                           ])>{{ $item['label'] }}</a>
                    @endforeach
                    <form method="POST" action="{{ route('account.logout') }}" class="mt-1">
                        @csrf
                        <button type="submit" class="w-full text-start px-3 py-2 rounded-lg text-sm text-rose-600 hover:bg-rose-50">
                            {{ __('account.logout') }}
                        </button>
                    </form>
                </nav>
            </div>
        </aside>

        {{-- المحتوى --}}
        <div class="lg:col-span-3">
            @if (session('status'))
                <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3">
                    {{ session('status') }}
                </div>
            @endif
            {{ $slot }}
        </div>
    </div>
</x-storefront.layout>
