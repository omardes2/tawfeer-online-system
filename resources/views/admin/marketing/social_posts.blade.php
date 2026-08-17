@use('App\Modules\Marketing\Models\SocialPost')

<x-app-layout :title="__('محتوى المنشورات')">
    @php
        $can = auth()->user()->can('marketing.social.manage');
        $tones = ['marketing' => __('تسويقية'), 'friendly' => __('ودّية'), 'professional' => __('احترافية')];
        $statusTones = [
            'draft' => 'bg-gray-100 text-gray-600 ring-gray-200',
            'ready' => 'bg-sky-50 text-sky-700 ring-sky-200',
            'published' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        ];
    @endphp

    <x-admin.header
        :title="__('محتوى المنشورات')"
        :description="__('نصوص منشورات فيسبوك وإنستغرام لأصنافك، برابطٍ متتبَّع تُنسب طلباتُه إلى صفحته.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التسويق') => null, __('محتوى المنشورات') => null]" />

    <div class="mb-5">
        <x-admin.alert tone="blue" :title="__('يُولّد ويحفظ — ولا ينشر')">
            {{ __('النصّ اقتراحٌ تراجعه وتعدّله ثم تنسخه إلى الصفحة بنفسك. النشر التلقائي يحتاج صلاحيات صفحاتٍ ومراجعة تطبيق من المنصّة، والأهمّ أنه يجعل خطأً في التوليد يظهر أمام الزبائن قبل أن يراه أحد.') }}
            <span class="block mt-1 text-xs">{{ __('والرابط ليس رابط المنتج العادي: يحمل وسم صفحته، فيُعرَف أن هذا المنشور هو الذي باع بدل أن يسقط الطلب تحت «غير منسوب».') }}</span>
        </x-admin.alert>
    </div>

    @unless ($aiReady)
        <div class="mb-5">
            <x-admin.alert tone="amber" :title="__('مساعد الكتابة غير مفعّل')">
                {{ __('يمكنك كتابة المنشور بنفسك وحفظه، لكن زرّ «اقترح نصًّا» لن يعمل حتى يُفعَّل مزوّد الذكاء الاصطناعي من إعدادات النظام.') }}
            </x-admin.alert>
        </div>
    @endunless

    {{-- ═══ المؤلِّف ═══ --}}
    @if ($can)
        <div class="admin-card p-5 mb-6"
             x-data="socialComposer(@js($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku])))">

            <h3 class="font-semibold text-gray-800 mb-4">{{ __('منشور جديد') }}</h3>

            <form method="POST" action="{{ route('admin.marketing.social.store') }}" class="space-y-4">
                @csrf

                <div class="grid gap-4 lg:grid-cols-4">
                    {{-- الصنف: بحثٌ بالكتابة، فالقائمة طويلة --}}
                    <div class="lg:col-span-2 relative">
                        <label for="sp-product" class="block text-xs text-gray-500 mb-1">{{ __('الصنف') }}</label>
                        <input id="sp-product" type="text" x-model="query" @input="open = true" @focus="open = true"
                               autocomplete="off" placeholder="{{ __('اكتب اسم الصنف أو رمزه…') }}"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <input type="hidden" name="product_id" :value="picked?.id">

                        <div x-show="open && matches.length" x-cloak @click.outside="open = false"
                             class="absolute z-20 mt-1 w-full max-h-56 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                            <template x-for="m in matches" :key="m.id">
                                <button type="button" @click="pick(m)"
                                        class="block w-full text-start px-3 py-2 text-sm hover:bg-emerald-50">
                                    <span x-text="m.name"></span>
                                    <span class="text-[11px] text-gray-400" x-text="m.sku"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div>
                        <label for="sp-platform" class="block text-xs text-gray-500 mb-1">{{ __('المنصّة') }}</label>
                        <select id="sp-platform" name="platform" x-model="platform" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach (SocialPost::PLATFORMS as $value => $label)
                                <option value="{{ $value }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="sp-channel" class="block text-xs text-gray-500 mb-1">{{ __('الصفحة') }}</label>
                        <select id="sp-channel" name="ad_channel_id" x-model="channel" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">{{ __('— بلا صفحة —') }}</option>
                            @foreach ($channels as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-4">
                    <div>
                        <label for="sp-tone" class="block text-xs text-gray-500 mb-1">{{ __('النبرة') }}</label>
                        <select id="sp-tone" name="tone" x-model="tone" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">{{ __('افتراضية') }}</option>
                            @foreach ($tones as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="sp-locale" class="block text-xs text-gray-500 mb-1">{{ __('اللغة') }}</label>
                        <select id="sp-locale" name="locale" x-model="locale" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="ar">{{ __('العربية') }}</option>
                            <option value="en">{{ __('الإنجليزية') }}</option>
                        </select>
                    </div>
                    <div class="lg:col-span-2 flex items-end gap-2">
                        <button type="button" @click="suggest()" :disabled="!picked || busy"
                                class="btn-secondary" @disabled(! $aiReady)>
                            <span x-show="!busy">{{ __('اقترح نصًّا') }}</span>
                            <span x-show="busy" x-cloak>{{ __('جارٍ التوليد…') }}</span>
                        </button>
                        <p class="text-[11px] text-gray-500 leading-relaxed">
                            {{ __('اختر الصنف أولًا. والاقتراح لا يُحفَظ من تلقائه — راجعه ثم احفظ.') }}
                        </p>
                    </div>
                </div>

                <div>
                    <label for="sp-body" class="block text-xs text-gray-500 mb-1">{{ __('نصّ المنشور') }}</label>
                    <textarea id="sp-body" name="body" x-model="body" rows="7" required
                              class="w-full rounded-lg border-gray-300 text-sm leading-relaxed focus:border-emerald-500 focus:ring-emerald-500"
                              placeholder="{{ __('اكتب أو اضغط «اقترح نصًّا»…') }}"></textarea>
                    <p class="mt-1 text-[11px] text-gray-400" x-show="model" x-cloak>
                        {{ __('وُلِّد بـ') }} <span x-text="model"></span>
                        <span x-show="aiStatus && aiStatus !== 'success'" class="text-amber-600" x-text="'· ' + aiStatus"></span>
                    </p>
                    <input type="hidden" name="ai_model" :value="model">
                    <input type="hidden" name="ai_status" :value="aiStatus">
                </div>

                <div>
                    <label for="sp-tags" class="block text-xs text-gray-500 mb-1">{{ __('الوسوم (hashtags)') }}</label>
                    <input id="sp-tags" type="text" name="hashtags" x-model="hashtags" dir="auto"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                           placeholder="#توفير_اون_لاين #توصيل_لكل_فلسطين">
                </div>

                {{-- الرابط المتتبَّع: يُعرَض قبل الحفظ ليُنسخ مع النصّ --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <span class="text-xs font-semibold text-gray-700">{{ __('الرابط المتتبَّع') }}</span>
                        <button type="button" @click="copy(link)" x-show="link" x-cloak
                                class="text-xs font-medium text-emerald-700 hover:underline">{{ __('نسخ') }}</button>
                    </div>
                    <p class="text-[11px] font-mono text-gray-600 break-all" dir="ltr"
                       x-text="link || '{{ __('اختر صنفًا وصفحة ليظهر الرابط.') }}'"></p>
                    <p class="mt-1 text-[11px] text-gray-500">
                        {{ __('انسخه إلى المنشور بدل رابط المنتج العادي — به وحده يُعرَف أن هذا المنشور هو الذي باع.') }}
                    </p>
                </div>

                <div class="flex items-center justify-between gap-3 pt-2 border-t">
                    <div class="flex items-center gap-2">
                        <label for="sp-status" class="text-xs text-gray-500">{{ __('الحالة') }}</label>
                        <select id="sp-status" name="status" class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach (SocialPost::STATUSES as $value => $label)
                                <option value="{{ $value }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="copyAll()" :disabled="!body" class="btn-secondary">{{ __('انسخ المنشور كاملًا') }}</button>
                        <button type="submit" class="btn-primary">{{ __('احفظ') }}</button>
                    </div>
                </div>
            </form>
        </div>
    @endif

    {{-- ═══ السجلّ ═══ --}}
    <div class="flex items-center justify-between gap-3 mb-3">
        <h3 class="font-semibold text-gray-800">{{ __('المنشورات المحفوظة') }}</h3>
        <form method="GET" class="flex items-center gap-2">
            <select name="status" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('كل الحالات') }}</option>
                @foreach (SocialPost::STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ __($label) }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <x-admin.table dense>
        <thead>
            <tr>
                <th>{{ __('الصنف') }}</th>
                <th>{{ __('المنصّة / الصفحة') }}</th>
                <th>{{ __('النصّ') }}</th>
                <th>{{ __('الحالة') }}</th>
                <th>{{ __('التاريخ') }}</th>
                <th class="w-px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($posts as $post)
                <tr x-data="{ full: @js($post->fullText()) }">
                    <td>
                        <span class="font-medium text-gray-800">{{ $post->product?->name ?? __('—') }}</span>
                        <span class="block text-[11px] text-gray-400">{{ $post->author?->name }}</span>
                    </td>
                    <td class="text-sm text-gray-600">
                        {{ $post->platformLabel() }}
                        <span class="block text-[11px] text-gray-400">{{ $post->channel?->name ?? __('بلا صفحة') }}</span>
                    </td>
                    <td class="text-xs text-gray-600 max-w-lg">
                        <span class="line-clamp-3 leading-relaxed">{{ $post->body }}</span>
                        @if ($post->link)
                            <a href="{{ $post->link }}" target="_blank" rel="noopener"
                               class="block mt-1 text-[11px] text-emerald-700 hover:underline truncate" dir="ltr">{{ $post->link }}</a>
                        @endif
                    </td>
                    <td>
                        <span class="rounded px-1.5 py-0.5 text-[11px] ring-1 {{ $statusTones[$post->status] ?? '' }}">
                            {{ $post->statusLabel() }}
                        </span>
                    </td>
                    <td class="text-xs text-gray-500 whitespace-nowrap">
                        {{ $post->published_at?->format('Y-m-d') ?? $post->created_at->format('Y-m-d') }}
                    </td>
                    <td class="whitespace-nowrap">
                        <button type="button" @click="navigator.clipboard.writeText(full)"
                                class="text-emerald-700 hover:underline text-sm font-medium">{{ __('نسخ') }}</button>
                        @if ($can && $post->status !== 'published')
                            <form method="POST" action="{{ route('admin.marketing.social.published', $post) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-sky-700 hover:underline text-sm font-medium ms-2">{{ __('تمّ نشره') }}</button>
                            </form>
                        @endif
                        @if ($can)
                            <x-admin.confirm
                                :action="route('admin.marketing.social.destroy', $post)"
                                method="DELETE"
                                :trigger="__('حذف')"
                                :message="__('حذف هذا المنشور من السجلّ؟ لا يؤثّر على ما نُشر فعلًا على المنصّة.')" />
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا منشورات بعد')"
                        :description="__('اختر صنفًا واضغط «اقترح نصًّا» — أو اكتب المنشور بنفسك واحفظه.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <div class="mt-4">{{ $posts->links() }}</div>

    @push('scripts')
        <script>
            function socialComposer(products) {
                return {
                    products, query: '', picked: null, open: false,
                    platform: 'facebook', channel: '', tone: '', locale: 'ar',
                    body: '', hashtags: '', link: '', model: '', aiStatus: '', busy: false,

                    /* تطبيع عربي — الصنف مكتوبٌ بصيغٍ مختلفة عمّا يُكتب في البحث. */
                    norm(text) {
                        return (text ?? '').toString()
                            .replace(/[ً-ْـ]/g, '')
                            .replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
                            .toLowerCase().trim();
                    },

                    get matches() {
                        const q = this.norm(this.query);
                        if (!q) return this.products.slice(0, 12);
                        return this.products
                            .filter(p => this.norm(p.name).includes(q) || this.norm(p.sku).includes(q))
                            .slice(0, 12);
                    },

                    pick(m) {
                        this.picked = m;
                        this.query = m.name;
                        this.open = false;
                        this.refreshLink();
                    },

                    async refreshLink() {
                        if (!this.picked) { this.link = ''; return; }

                        const res = await fetch(@js(route('admin.marketing.social.link')), {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                product_id: this.picked.id,
                                ad_channel_id: this.channel || null,
                                platform: this.platform,
                            }),
                        });

                        if (res.ok) this.link = (await res.json()).link;
                    },

                    async suggest() {
                        if (!this.picked) return;
                        this.busy = true;

                        try {
                            const res = await fetch(@js(route('admin.marketing.social.suggest')), {
                                method: 'POST',
                                headers: this.headers(),
                                body: JSON.stringify({
                                    product_id: this.picked.id,
                                    platform: this.platform,
                                    locale: this.locale,
                                    tone: this.tone || null,
                                }),
                            });

                            const data = await res.json();

                            if (!res.ok) { alert(data.message || @js(__('تعذّر التوليد.'))); return; }

                            this.body = data.suggestion;
                            this.model = data.model;
                            this.aiStatus = data.status;
                        } finally {
                            this.busy = false;
                        }
                    },

                    fullText() {
                        return [this.body, this.hashtags, this.link].filter(Boolean).join('\n\n');
                    },

                    copy(text) { if (text) navigator.clipboard.writeText(text); },
                    copyAll() { this.copy(this.fullText()); },

                    headers() {
                        return {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        };
                    },

                    init() {
                        this.$watch('channel', () => this.refreshLink());
                        this.$watch('platform', () => this.refreshLink());
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
