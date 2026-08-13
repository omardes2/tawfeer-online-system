@props(['category'])

{{--
    بطاقة قسم: صورة القسم إن وُجدت، وإلا أيقونة موحّدة.
    الحرف المفرد كان بديلًا رديئًا — «إلكترونيات» تظهر كخطّ رأسي بلا معنى.
--}}
<a href="{{ route('storefront.category', $category->slug) }}"
   class="sf-card sf-card-hover shrink-0 w-[104px] sm:w-auto flex flex-col items-center gap-2.5 p-3 text-center group">
    <span class="grid place-items-center w-14 h-14 rounded-full bg-brand-50 text-brand-600 overflow-hidden
                 transition-colors group-hover:bg-brand-600 group-hover:text-white">
        @if (! empty($category->image))
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($category->image) }}"
                 alt="{{ $category->name }}" loading="lazy" class="w-full h-full object-cover" />
        @else
            <x-storefront.icon name="grid" class="w-6 h-6" />
        @endif
    </span>
    <span class="text-[13px] font-semibold leading-snug line-clamp-2 text-[color:var(--sf-text)]">{{ $category->name }}</span>
</a>
