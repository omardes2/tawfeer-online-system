<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ ($title ?? null) ? $title.' — '.config('app.name') : config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=tajawal:400,500,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased text-gray-800">
        <div
            x-data="{ sidebarOpen: false, collapsed: (localStorage.getItem('admin_sidebar_collapsed') === 'true') }"
            @keydown.escape.window="sidebarOpen = false"
            class="admin-scope min-h-screen bg-gray-50"
        >
            <!-- Mobile backdrop -->
            <div x-show="sidebarOpen" x-cloak x-transition.opacity @click="sidebarOpen = false"
                 class="fixed inset-0 z-30 bg-slate-900/50 md:hidden"></div>

            <!-- Sidebar -->
            <x-admin.sidebar />

            <!-- Main column (offset by sidebar width on desktop) -->
            <div class="min-h-screen transition-all duration-200"
                 :class="collapsed ? 'md:ms-[76px]' : 'md:ms-64'">
                <x-admin.topbar :title="$title ?? null" />

                <!-- Optional page heading slot (Breeze dashboard/profile) -->
                @isset($header)
                    <div class="bg-white border-b border-gray-200 px-4 md:px-6 py-4">
                        {{ $header }}
                    </div>
                @endisset

                <!-- Page content -->
                <main class="p-4 md:p-6 max-w-[1600px] mx-auto">
                    {{ $slot }}
                </main>
            </div>
        </div>
        @stack('scripts')
    </body>
</html>
