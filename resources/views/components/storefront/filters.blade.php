@props(['filters', 'categories', 'brands', 'action'])

{{--
    نموذج التصفية — **الفلاتر المدعومة في الخلفية فقط**: الفئة، العلامة، أقلّ سعر،
    أعلى سعر. لا فلتر توفّر ولا تقييم: النظام لا يدعمهما، وواجهة بلا خلفية خداع.

    يُستخدم مرّتين بنفس المحتوى (شريط جانبي على الحواسيب، ودرج سفلي على الجوّال)،
    ولذلك تُمرَّر `id` فريدة لكل نسخة كي تبقى تسميات الحقول صحيحة.
--}}
@php
    $uid = $attributes->get('uid', 'd');
    $q = $filters['q'] ?? null;
    $activeCount = collect(['category', 'brand', 'min', 'max'])
        ->filter(fn ($k) => ($filters[$k] ?? '') !== '')->count();
@endphp

{{-- الحقول الفارغة تُعطَّل قبل الإرسال، فلا يمتلئ الرابط بـ`category=&brand=`
     بلا قيمة — رابط أنظف للمشاركة وللرجوع في المتصفّح. --}}
<form action="{{ $action }}" method="GET" class="flex flex-col gap-5"
      onsubmit="this.querySelectorAll('select,input[type=number]').forEach(f => { if (! f.value) f.disabled = true })">
    {{-- الحقول غير المعروضة تُحمل كما هي كي لا يضيع البحث أو الترتيب عند التصفية --}}
    @if ($q) <input type="hidden" name="q" value="{{ $q }}"> @endif
    @if (! empty($filters['sort'])) <input type="hidden" name="sort" value="{{ $filters['sort'] }}"> @endif

    <div>
        <label for="f-cat-{{ $uid }}" class="sf-label">{{ __('storefront.category') }}</label>
        <select id="f-cat-{{ $uid }}" name="category" class="sf-select">
            <option value="">{{ __('storefront.all') }}</option>
            @foreach ($categories as $c)
                <option value="{{ $c->slug }}" @selected(($filters['category'] ?? '') === $c->slug)>{{ $c->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="f-brand-{{ $uid }}" class="sf-label">{{ __('storefront.brand') }}</label>
        <select id="f-brand-{{ $uid }}" name="brand" class="sf-select">
            <option value="">{{ __('storefront.all') }}</option>
            @foreach ($brands as $b)
                <option value="{{ $b->slug }}" @selected(($filters['brand'] ?? '') === $b->slug)>{{ $b->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <span class="sf-label">{{ __('storefront.price_range') }}</span>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label for="f-min-{{ $uid }}" class="sr-only">{{ __('storefront.min_price') }}</label>
                <input id="f-min-{{ $uid }}" type="number" inputmode="decimal" min="0" step="0.01" name="min"
                       value="{{ $filters['min'] ?? '' }}" placeholder="{{ __('storefront.min_price') }}" class="sf-input">
            </div>
            <div>
                <label for="f-max-{{ $uid }}" class="sr-only">{{ __('storefront.max_price') }}</label>
                <input id="f-max-{{ $uid }}" type="number" inputmode="decimal" min="0" step="0.01" name="max"
                       value="{{ $filters['max'] ?? '' }}" placeholder="{{ __('storefront.max_price') }}" class="sf-input">
            </div>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="sf-btn-primary flex-1">{{ __('storefront.apply') }}</button>
        @if ($activeCount > 0)
            {{-- إعادة التعيين تُبقي البحث والترتيب وتمسح الفلاتر وحدها --}}
            <a href="{{ $action.'?'.http_build_query(array_filter([
                    'q' => $q,
                    'sort' => $filters['sort'] ?? null,
                ])) }}" class="sf-btn-outline">{{ __('storefront.clear') }}</a>
        @endif
    </div>
</form>
