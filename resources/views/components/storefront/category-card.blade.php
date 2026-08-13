@props(['category'])

{{-- بطاقة قسم: أيقونة دائرية + الاسم. الصورة إن وُجدت للقسم، وإلا حرف الاسم. --}}
<a href="{{ route('storefront.category', $category->slug) }}"
   class="sf-card sf-card-hover shrink-0 w-[104px] sm:w-auto flex flex-col items-center gap-2.5 p-3 text-center group">
    <span class="grid place-items-center w-14 h-14 rounded-full bg-brand-50 text-brand-600 font-extrabold text-xl
                 transition-colors group-hover:bg-brand-600 group-hover:text-white overflow-hidden">
        @if (! empty($category->image))
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($category->image) }}"
                 alt="{{ $category->name }}" loading="lazy" class="w-full h-full object-cover" />
        @else
            {{ mb_substr($category->name, 0, 1) }}
        @endif
    </span>
    <span class="text-xs font-semibold text-[color:var(--sf-text)] line-clamp-2 leading-snug">{{ $category->name }}</span>
</a>
