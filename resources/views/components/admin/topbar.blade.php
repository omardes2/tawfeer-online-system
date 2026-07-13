{{--
    Admin top bar — sticky, compact on mobile. Provides: mobile sidebar toggle,
    page title + breadcrumb (from $header stack), global search, notifications,
    language switcher, and the user profile dropdown with logout.
    Relies on Alpine state (sidebarOpen) from the parent layout.
--}}
@props(['title' => null])

<header class="sticky top-0 z-30 h-16 bg-white/95 backdrop-blur border-b border-gray-200 flex items-center gap-3 px-4 md:px-6">
    {{-- Mobile: open drawer --}}
    <button type="button" @click="sidebarOpen = true"
            class="md:hidden grid place-items-center w-10 h-10 rounded-lg text-gray-600 hover:bg-gray-100 shrink-0"
            aria-label="فتح القائمة">
        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
    </button>

    {{-- Page title (compact; breadcrumb lives in the page header) --}}
    <div class="min-w-0 flex-1">
        <h1 class="text-base md:text-lg font-semibold text-gray-800 truncate">{{ $title ?? config('app.name') }}</h1>
    </div>

    {{-- Global search (desktop) --}}
    <form action="{{ route('admin.products.index') }}" method="GET" class="hidden lg:block relative w-72">
        <span class="absolute inset-y-0 end-3 grid place-items-center text-gray-400">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        </span>
        <input type="search" name="q" placeholder="{{ __('بحث سريع في المنتجات...') }}"
               class="w-full rounded-lg border-gray-200 bg-gray-50 pe-9 ps-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 focus:bg-white" />
    </form>

    {{-- Notifications --}}
    <div class="relative" x-data="{ open: false }">
        <button @click="open = !open" class="relative grid place-items-center w-10 h-10 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="الإشعارات">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg>
        </button>
        <div x-show="open" x-cloak @click.outside="open = false" x-transition
             class="absolute {{ app()->getLocale() === 'ar' ? 'start-0' : 'end-0' }} mt-2 w-72 rounded-xl bg-white shadow-lg ring-1 ring-black/5 p-2 z-50">
            <p class="px-3 py-2 text-sm font-semibold text-gray-700">{{ __('الإشعارات') }}</p>
            <div class="px-3 py-6 text-center text-sm text-gray-400">
                <svg class="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0"/></svg>
                {{ __('لا توجد إشعارات جديدة') }}
            </div>
        </div>
    </div>

    {{-- Language switcher --}}
    @php $otherLocale = app()->getLocale() === 'ar' ? 'en' : 'ar'; @endphp
    <a href="{{ url('/lang/'.$otherLocale) }}"
       class="grid place-items-center h-10 px-3 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100"
       title="{{ __('تغيير اللغة') }}">{{ strtoupper($otherLocale) }}</a>

    {{-- User dropdown --}}
    <div class="relative" x-data="{ open: false }">
        <button @click="open = !open" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-100">
            <span class="grid place-items-center w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 font-semibold shrink-0">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
            <span class="hidden md:block text-sm font-medium text-gray-700 max-w-[9rem] truncate">{{ auth()->user()->name }}</span>
            <svg class="hidden md:block w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
        </button>
        <div x-show="open" x-cloak @click.outside="open = false" x-transition
             class="absolute {{ app()->getLocale() === 'ar' ? 'start-0' : 'end-0' }} mt-2 w-56 rounded-xl bg-white shadow-lg ring-1 ring-black/5 p-1.5 z-50">
            <div class="px-3 py-2 border-b border-gray-100 mb-1">
                <p class="text-sm font-medium text-gray-800 truncate">{{ auth()->user()->name }}</p>
                <p class="text-xs text-gray-500 truncate">{{ auth()->user()->email }}</p>
            </div>
            <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                {{ __('الملف الشخصي') }}
            </a>
            @can('settings.system.view')
                <a href="{{ route('admin.settings.edit') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    {{ __('settings.title') }}
                </a>
            @endcan
            <form method="POST" action="{{ route('logout') }}" class="mt-1 pt-1 border-t border-gray-100">
                @csrf
                <button type="submit" class="w-full flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/></svg>
                    {{ __('تسجيل الخروج') }}
                </button>
            </form>
        </div>
    </div>
</header>
