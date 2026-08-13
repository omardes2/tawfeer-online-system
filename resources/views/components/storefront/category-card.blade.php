@props(['category', 'wide' => false])

{{--
    بطاقة قسم — تُستخدم في الرئيسية (شريط تمرير أفقي) وفي صفحة الأقسام (شبكة).
    `wide` يجعلها تملأ خليّة الشبكة بدل عرض ثابت للتمرير.
    صورة القسم عبر `iconUrl()` القائمة، وإلا أيقونة موحّدة — الحرف المفرد بديل رديء.
--}}
<a href="{{ route('storefront.category', $category->slug) }}"
   @class([
       'sf-card sf-card-hover flex flex-col items-center gap-2.5 p-3 text-center group',
       'shrink-0 w-[104px] sm:w-auto' => ! $wide,
       'w-full py-5' => $wide,
   ])>
    <span @class([
        'grid place-items-center rounded-full bg-brand-50 text-brand-600 overflow-hidden',
        'transition-colors group-hover:bg-brand-600 group-hover:text-white',
        'w-14 h-14' => ! $wide,
        'w-16 h-16' => $wide,
    ])>
        @if ($category->iconUrl())
            <img src="{{ $category->iconUrl() }}" alt="{{ $category->name }}" loading="lazy"
                 class="w-full h-full object-cover" />
        @else
            <x-storefront.icon name="grid" :class="$wide ? 'w-7 h-7' : 'w-6 h-6'" />
        @endif
    </span>
    <span class="text-[13px] font-semibold leading-snug line-clamp-2 text-[color:var(--sf-text)]">{{ $category->name }}</span>
</a>
