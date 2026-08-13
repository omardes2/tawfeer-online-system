@props(['title', 'subtitle' => null, 'breadcrumbs' => []])

{{--
    ترويسة صفحة موحّدة: مسار تنقّل اختياري، ثم العنوان، ثم سطر معلومات.
    الفتحة الافتراضية للإجراءات (فرز/فلترة) على يسار العنوان.
--}}
<div class="mb-5">
    @if (! empty($breadcrumbs))
        <nav class="mb-2 flex items-center gap-1.5 text-xs flex-wrap text-[color:var(--sf-text-soft)]"
             aria-label="{{ __('storefront.breadcrumb') }}">
            @foreach ($breadcrumbs as $label => $url)
                @if (! $loop->first)
                    <x-storefront.icon name="chevron-left" class="w-3 h-3 opacity-50 ltr:rotate-180" />
                @endif
                @if ($url && ! $loop->last)
                    <a href="{{ $url }}" class="hover:text-brand-600 transition-colors">{{ $label }}</a>
                @else
                    <span class="font-semibold text-[color:var(--sf-text)]">{{ $label }}</span>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-extrabold text-[color:var(--sf-text)]">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-1 text-sm text-[color:var(--sf-text-soft)]">{{ $subtitle }}</p>
            @endif
        </div>
        @if (! $slot->isEmpty())
            <div class="flex items-center gap-2 shrink-0">{{ $slot }}</div>
        @endif
    </div>
</div>
