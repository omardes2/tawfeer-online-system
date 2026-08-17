{{--
    الاستيراد بموجّه Blade المخصّص له، وخارج المكوّن: داخل وسم المكوّن يُصرَّف
    المحتوى إلى جسم دالّة، والاستيراد هناك خطأ صياغي.

    ولا يُذكر الموجّه الآخر حرفيًّا داخل تعليق: Blade يستخرج كتل الشيفرة قبل أن
    يحذف التعليقات، فيلتقط ما بينها ويبتلع بقيّة الملف.
--}}
@use('App\Modules\Marketing\Models\AdExternalMap')

<x-app-layout :title="__('ربط الحملات')">
    <x-admin.header
        :title="__('ربط الحملات')"
        :description="__('ربط حملات منصّة الإعلانات ومجموعاتها الإعلانية بقنوات النظام وأصنافه.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الإعدادات') => null, __('ربط الحملات') => null]" />

    <div class="mb-5">
        <x-admin.alert tone="blue" :title="__('لماذا الربط يدويّ')">
            {{ __('المنصّة تعطي معرّفًا واسمًا نصّيًا، ولا تعرف أيّ صنفٍ في الكتالوج تقصد. يقترح النظام المطابقة بالاسم، وتؤكّدها أنت مرّة واحدة — ثم يُحفَظ الربط بالمعرّف فلا يتأثّر بإعادة التسمية.') }}
            <span class="block mt-1 text-xs">{{ __('الحملة تُربَط بقناة (صفحة)، والمجموعة الإعلانية تُربَط بصنف. وما لا يُربَط لا يُكتَب صرفُه — لا يُخمَّن.') }}</span>
        </x-admin.alert>
    </div>

    @if ($driver === 'null')
        <div class="mb-5">
            <x-admin.alert tone="amber" :title="__('المنصّة غير مربوطة بعد')">
                {{ __('المحرّك الحالي معطّل، فلا يُسحَب شيء والإدخال اليدوي يعمل كما هو. تُضبط بيانات الاتصال في ملف البيئة (.env) على الخادم — ولا تُكتب في النظام.') }}
                <code class="block mt-2 text-[11px] font-mono bg-amber-100 rounded p-2 leading-relaxed" dir="ltr">ADS_DRIVER=meta<br>META_ADS_TOKEN=…<br>META_ADS_ACCOUNT_ID=…<br>ADS_SYNC_ENABLED=true</code>
            </x-admin.alert>
        </div>
    @endif

    <div class="admin-card p-4 mb-5 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('admin.settings.ad_maps.sync') }}">
            @csrf
            <button type="submit" class="btn-primary btn-sm">{{ __('سحب الآن') }}</button>
        </form>
        <form method="POST" action="{{ route('admin.settings.ad_maps.accept') }}">
            @csrf
            <button type="submit" class="btn-secondary btn-sm">{{ __('اربط كل المقترحات') }}</button>
        </form>
        <p class="text-xs text-gray-500 ms-auto">
            {{ __('السحب يجري تلقائيًّا كل ليلة، ويُعيد آخر عدّة أيام لأن أرقام المنصّة تُراجَع بعد نشرها.') }}
        </p>
    </div>

    {{-- بانتظار الربط أولًا: هذه وحدها ما يحتاج تصرّفًا. --}}
    <h3 class="font-semibold text-gray-800 mb-3">
        {{ __('بانتظار الربط') }}
        @if ($pending->isNotEmpty())<span class="ms-1 rounded-full bg-amber-100 text-amber-800 text-xs px-2 py-0.5">{{ $pending->count() }}</span>@endif
    </h3>

    <x-admin.table dense>
        <thead>
            <tr>
                <th>{{ __('الاسم على المنصّة') }}</th>
                <th>{{ __('النوع') }}</th>
                <th>{{ __('يُربَط بـ') }}</th>
                <th>{{ __('آخر ظهور') }}</th>
                <th class="w-px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($pending as $map)
                @php $isCampaign = $map->external_type === AdExternalMap::TYPE_CAMPAIGN; @endphp
                <tr>
                    <td>
                        <form method="POST" action="{{ route('admin.settings.ad_maps.update', $map) }}" id="map-{{ $map->id }}">
                            @csrf @method('PUT')
                        </form>
                        <span class="font-medium text-gray-800">{{ $map->external_name ?: '—' }}</span>
                        <span class="block text-[11px] text-gray-400 font-mono" dir="ltr">{{ $map->external_id }}</span>
                    </td>
                    <td>
                        <span class="rounded px-1.5 py-0.5 text-[11px] {{ $isCampaign ? 'bg-sky-50 text-sky-700' : 'bg-gray-100 text-gray-600' }}">
                            {{ $isCampaign ? __('حملة ← صفحة') : __('مجموعة إعلانية ← صنف') }}
                        </span>
                    </td>
                    <td>
                        @if ($isCampaign)
                            <select name="ad_channel_id" form="map-{{ $map->id }}" required
                                    class="min-w-[13rem] rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="">{{ __('— اختر صفحة —') }}</option>
                                @foreach ($channels as $c)
                                    <option value="{{ $c->id }}" @selected($map->suggested_ad_channel_id === $c->id)>{{ $c->name }}</option>
                                @endforeach
                            </select>
                        @else
                            <select name="product_id" form="map-{{ $map->id }}" required
                                    class="min-w-[15rem] rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="">{{ __('— اختر صنفًا —') }}</option>
                                @foreach ($products as $p)
                                    <option value="{{ $p->id }}" @selected($map->suggested_product_id === $p->id)>{{ $p->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        {{-- المقترح مُحدَّد سلفًا، ويُقال صراحةً إنه اقتراح لا ربط. --}}
                        @if ($map->suggestedChannel || $map->suggestedProduct)
                            <span class="block mt-1 text-[11px] text-emerald-700">
                                {{ __('مقترح بالاسم: :n', ['n' => $map->suggestedChannel?->name ?? $map->suggestedProduct?->name]) }}
                            </span>
                        @else
                            <span class="block mt-1 text-[11px] text-amber-600">{{ __('لا مقترح — اختر يدويًّا.') }}</span>
                        @endif
                    </td>
                    <td class="text-xs text-gray-500">{{ $map->last_seen_at?->diffForHumans() ?: '—' }}</td>
                    <td class="whitespace-nowrap">
                        <button type="submit" form="map-{{ $map->id }}" class="btn-primary btn-sm">{{ __('ربط') }}</button>
                        <form method="POST" action="{{ route('admin.settings.ad_maps.ignore', $map) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-500 hover:text-gray-700 text-sm font-medium ms-2">{{ __('تجاهل') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا شيء بانتظار الربط')"
                        :description="__('كل ما ظهر من المنصّة مربوط أو مُتجاهَل. الجديد يظهر هنا تلقائيًّا بعد أول سحب يليه.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    @if ($linked->isNotEmpty())
        <h3 class="font-semibold text-gray-800 mt-6 mb-3">{{ __('المربوط') }} <span class="text-gray-400 text-sm">({{ $linked->count() }})</span></h3>
        <x-admin.table dense>
            <thead>
                <tr>
                    <th>{{ __('الاسم على المنصّة') }}</th>
                    <th>{{ __('النوع') }}</th>
                    <th>{{ __('مربوط بـ') }}</th>
                    <th class="w-px"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($linked as $map)
                    @php $isCampaign = $map->external_type === AdExternalMap::TYPE_CAMPAIGN; @endphp
                    <tr>
                        <td>
                            <form method="POST" action="{{ route('admin.settings.ad_maps.update', $map) }}" id="linked-{{ $map->id }}">
                                @csrf @method('PUT')
                            </form>
                            <span class="font-medium text-gray-800">{{ $map->external_name ?: '—' }}</span>
                            <span class="block text-[11px] text-gray-400 font-mono" dir="ltr">{{ $map->external_id }}</span>
                        </td>
                        <td class="text-xs text-gray-500">{{ $isCampaign ? __('حملة') : __('مجموعة إعلانية') }}</td>
                        <td>
                            @if ($isCampaign)
                                <select name="ad_channel_id" form="linked-{{ $map->id }}"
                                        class="min-w-[13rem] rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    @foreach ($channels as $c)
                                        <option value="{{ $c->id }}" @selected($map->ad_channel_id === $c->id)>{{ $c->name }}</option>
                                    @endforeach
                                </select>
                            @else
                                <select name="product_id" form="linked-{{ $map->id }}"
                                        class="min-w-[15rem] rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    @foreach ($products as $p)
                                        <option value="{{ $p->id }}" @selected($map->product_id === $p->id)>{{ $p->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                        <td><button type="submit" form="linked-{{ $map->id }}" class="btn-secondary btn-sm">{{ __('تعديل') }}</button></td>
                    </tr>
                @endforeach
            </tbody>
        </x-admin.table>
    @endif

    @if ($ignored->isNotEmpty())
        <h3 class="font-semibold text-gray-800 mt-6 mb-3">{{ __('المُتجاهَل') }} <span class="text-gray-400 text-sm">({{ $ignored->count() }})</span></h3>
        <x-admin.table dense>
            <tbody>
                @foreach ($ignored as $map)
                    <tr>
                        <td class="text-gray-600">{{ $map->external_name ?: $map->external_id }}</td>
                        <td class="text-xs text-gray-400">{{ $map->external_type === AdExternalMap::TYPE_CAMPAIGN ? __('حملة') : __('مجموعة إعلانية') }}</td>
                        <td class="w-px">
                            <form method="POST" action="{{ route('admin.settings.ad_maps.ignore', $map) }}">
                                @csrf
                                <button type="submit" class="btn-secondary btn-sm">{{ __('إعادة') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-admin.table>
    @endif

    <p class="mt-4 text-xs text-gray-500 leading-relaxed">
        {{ __('ما لا يُربَط لا يُكتَب صرفُه — لا يُخمَّن. وصرفُ مجموعتين تُعلنان الصنف نفسه على الصفحة نفسها يُجمَع في صفٍّ واحد.') }}
        {{ __('والإدخال اليدوي يبقى فوق المزامنة: القيمة التي تكتبها بيدك لا تُدهَس، ويُعرَض بجانبها ما تقوله المنصّة عند الاختلاف.') }}
    </p>
</x-app-layout>
