{{--
    Admin sidebar — RTL, deep-green rail, Tawfeer accent.

    الشجرة نفسها تُبنى في `AdminNavigation` (مُصفّاة بالصلاحيات)، وهذا الملف يعرضها.
    الأقسام تُعرض واحدًا مفتوحًا في كل مرّة، وفوقها «المثبّتة» (اختيار كل مستخدم،
    محفوظ في متصفّحه)، وفوق الجميع بحث فوري يجعل الوصول لأي وجهة بكتابة حرفين
    بدل تذكّر القسم الذي تسكنه.

    حالة Alpine (sidebarOpen, collapsed) تأتي من التخطيط الأب.
--}}
@php
    use App\Modules\Foundation\Support\AdminNavigation;

    // مسارات أيقونات Heroicons (outline) — تُحقن في بيانات Alpine ليرسمها العميل.
    $iconPaths = [
        'home' => 'M2.25 12l8.954-8.955a1.5 1.5 0 012.122 0L22.5 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75',
        'cart' => 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
        'box' => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
        'inventory' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
        'users' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'truck' => 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-6m6 0V4.5A1.5 1.5 0 0015 3h-9A1.5 1.5 0 004.5 4.5v13.5H2.25',
        'wallet' => 'M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3',
        'badge' => 'M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.504-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 007.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 002.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 012.916.52 6.003 6.003 0 01-5.395 4.972m0 0a6.726 6.726 0 01-2.749 1.35m0 0a6.772 6.772 0 01-3.044 0',
        'sparkles' => 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z',
        'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'cog' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.49l1.216.455c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
    ];

    $groups = collect(AdminNavigation::groups())
        ->map(fn ($g) => [...$g, 'iconPath' => $iconPaths[$g['icon']] ?? $iconPaths['box']])
        ->all();

    $activeGroup = AdminNavigation::activeGroupIndex($groups);
    $dashActive = request()->routeIs('admin.dashboard');
@endphp

<aside
    x-cloak
    x-data="adminNav(@js($groups), @js($activeGroup))"
    class="fixed inset-y-0 right-0 z-40 flex flex-col bg-rail text-rail-ink transition-all duration-200 ease-in-out
           ltr:right-auto ltr:left-0 md:translate-x-0"
    :class="{
        'w-64': !collapsed, 'md:w-[76px]': collapsed,
        'translate-x-0': sidebarOpen, 'translate-x-full ltr:-translate-x-full md:translate-x-0': !sidebarOpen
    }"
    style="width:16rem"
>
    {{-- Brand --}}
    <div class="flex items-center gap-3 h-16 px-4 border-b border-rail-line shrink-0">
        <span class="grid place-items-center w-9 h-9 rounded-lg bg-emerald-600 text-white font-bold text-lg shrink-0">ت</span>
        <span class="font-bold text-white text-lg whitespace-nowrap" x-show="!collapsed" x-transition.opacity>{{ config('app.name') }}</span>
    </div>

    {{-- Instant search: يجعل الوصول لأي وجهة بكتابة حرفين بدل تذكّر قسمها --}}
    <div class="relative px-3 pt-3 pb-1 shrink-0" x-show="!collapsed" x-transition.opacity>
        <svg class="absolute top-[1.4rem] start-[1.4rem] w-4 h-4 text-slate-500 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/>
        </svg>
        <input type="search" x-model="q" x-ref="search"
               placeholder="{{ __('ابحث في القائمة…') }}"
               aria-label="{{ __('بحث في القائمة') }}"
               class="w-full rounded-lg bg-rail-2 border-rail-line text-slate-200 placeholder:text-slate-500 text-sm ps-9 pe-3 py-2 focus:border-emerald-500 focus:ring-emerald-500">
    </div>

    {{-- Nav --}}
    <nav class="flex-1 overflow-y-auto py-2 px-2 space-y-0.5 admin-scroll">
        {{-- Dashboard (دائمًا في الأعلى) --}}
        @can('dashboard.view')
            <a href="{{ route('admin.dashboard') }}" x-show="!q"
               @class([
                   'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                   'bg-emerald-600 text-white' => $dashActive,
                   'text-rail-ink hover:bg-white/5 hover:text-white' => ! $dashActive,
               ])
               title="{{ __('dashboard.title') }}">
                <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPaths['home'] }}"/></svg>
                <span class="whitespace-nowrap" x-show="!collapsed" x-transition.opacity>{{ __('dashboard.title') }}</span>
            </a>
        @endcan

        {{-- المثبّتة: اختيار كل مستخدم، محفوظ في متصفّحه --}}
        <template x-if="!q && !collapsed && pinned.length">
            <div class="pt-2">
                <p class="px-3 pb-1 text-[11px] font-bold text-slate-500">{{ __('المثبّتة') }}</p>
                <template x-for="item in pinned" :key="item.url">
                    <div class="flex items-center gap-1 rounded-lg pe-1 transition"
                         :class="item.active ? 'bg-emerald-600' : 'hover:bg-white/5'">
                        <a :href="item.url"
                           class="flex items-center gap-2 flex-1 min-w-0 px-3 py-1.5 text-[13px]"
                           :class="item.active ? 'text-white font-medium' : 'text-rail-ink hover:text-white'">
                            <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="item.active ? 'bg-white' : 'bg-slate-600'"></span>
                            <span class="truncate" x-text="item.label"></span>
                        </a>
                        <button type="button" @click="togglePin(item.url)"
                                class="shrink-0 p-1 rounded text-emerald-400 hover:text-white"
                                :title="'{{ __('إلغاء التثبيت') }}'" :aria-label="'{{ __('إلغاء التثبيت') }}'">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M11.48 3.5a.75.75 0 011.04 0l2.6 2.5a.75.75 0 01.16.83l-1 2.3 3.6 3.47a.75.75 0 01-.53 1.28h-4.6l-1.6 4.9a.75.75 0 01-1.4.06L8.5 14H4.9a.75.75 0 01-.53-1.28l3.6-3.47-1-2.3a.75.75 0 01.16-.83l2.6-2.5z"/></svg>
                        </button>
                    </div>
                </template>
                <p class="px-3 pt-3 pb-1 text-[11px] font-bold text-slate-500">{{ __('كل الأقسام') }}</p>
            </div>
        </template>

        {{-- الأقسام --}}
        <template x-for="(group, gi) in visibleGroups" :key="group.label">
            <div class="pt-0.5">
                <button type="button"
                        @click="collapsed ? (collapsed = false, open = gi) : toggle(gi)"
                        class="w-full flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-slate-200 hover:bg-white/5 transition"
                        :title="collapsed ? group.label : ''">
                    <svg class="w-5 h-5 shrink-0" :class="group.active ? 'text-emerald-400' : 'text-slate-400'"
                         fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="group.iconPath"/>
                    </svg>
                    <span class="flex-1 text-right whitespace-nowrap truncate" x-show="!collapsed" x-transition.opacity x-text="group.label"></span>
                    <svg class="w-4 h-4 shrink-0 transition-transform text-slate-500" x-show="!collapsed"
                         :class="{ 'rotate-180': isOpen(gi) }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>

                <div x-show="isOpen(gi) && !collapsed" x-transition.opacity.duration.150ms
                     class="mt-0.5 space-y-0.5 ms-3 ps-2 border-s border-rail-line">
                    <template x-for="item in group.items" :key="item.url + item.label">
                        <div class="group flex items-center gap-1 rounded-md pe-1 transition"
                             :class="item.active ? 'bg-emerald-600' : 'hover:bg-white/5'">
                            <a :href="item.url"
                               class="flex items-center gap-2 flex-1 min-w-0 px-3 py-1.5 text-[13px]"
                               :class="item.active ? 'text-white font-medium' : 'text-slate-400 hover:text-slate-100'">
                                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="item.active ? 'bg-white' : 'bg-slate-600'"></span>
                                <span class="truncate" x-text="item.label"></span>
                            </a>
                            <button type="button" @click="togglePin(item.url)"
                                    class="shrink-0 p-1 rounded transition opacity-0 group-hover:opacity-100 focus:opacity-100"
                                    :class="isPinned(item.url) ? 'text-emerald-300 opacity-100' : 'text-slate-500 hover:text-slate-200'"
                                    :title="isPinned(item.url) ? '{{ __('إلغاء التثبيت') }}' : '{{ __('تثبيت في الأعلى') }}'"
                                    :aria-label="isPinned(item.url) ? '{{ __('إلغاء التثبيت') }}' : '{{ __('تثبيت في الأعلى') }}'">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M11.48 3.5a.75.75 0 011.04 0l2.6 2.5a.75.75 0 01.16.83l-1 2.3 3.6 3.47a.75.75 0 01-.53 1.28h-4.6l-1.6 4.9a.75.75 0 01-1.4.06L8.5 14H4.9a.75.75 0 01-.53-1.28l3.6-3.47-1-2.3a.75.75 0 01.16-.83l2.6-2.5z"/></svg>
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="q && visibleGroups.length === 0">
            <p class="px-3 py-4 text-[13px] text-slate-500">{{ __('لا توجد وجهة تطابق بحثك.') }}</p>
        </template>
    </nav>

    {{-- User footer --}}
    <div class="border-t border-rail-line p-3 shrink-0">
        <div class="flex items-center gap-3">
            <span class="grid place-items-center w-9 h-9 rounded-full bg-rail-2 text-emerald-300 font-semibold shrink-0">
                {{ mb_substr(auth()->user()->name, 0, 1) }}
            </span>
            <div class="min-w-0 flex-1" x-show="!collapsed" x-transition.opacity>
                <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name }}</p>
                <p class="text-xs text-slate-500 truncate">{{ auth()->user()->getRoleNames()->map(fn ($r) => trans()->has('roles.'.$r) ? __('roles.'.$r) : $r)->implode('، ') ?: __('مستخدم') }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}" x-show="!collapsed">
                @csrf
                <button type="submit" class="grid place-items-center w-8 h-8 rounded-md text-slate-400 hover:text-rose-300 hover:bg-white/5 transition" title="{{ __('تسجيل الخروج') }}">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/></svg>
                </button>
            </form>
        </div>
    </div>

    {{-- Collapse toggle (desktop only) --}}
    {{-- الطيّ يُخفي مربّع البحث، فيُمسح نصّه معه حتى لا تبقى القائمة مُفلترة بلا سبب ظاهر --}}
    <button type="button" @click="collapsed = !collapsed; q = ''; localStorage.setItem('admin_sidebar_collapsed', collapsed)"
            {{-- أسفل مربّع البحث لا بمحاذاته، وإلا تداخل الزرّان على الحافة --}}
            class="hidden md:grid place-items-center absolute top-32 -start-3 w-6 h-6 rounded-full bg-rail-2 border border-rail-line text-slate-300 hover:text-white shadow"
            title="{{ __('طيّ/فتح القائمة') }}">
        <svg class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': collapsed }" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
    </button>
</aside>
