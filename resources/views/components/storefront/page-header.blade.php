@props(['title', 'subtitle' => null, 'breadcrumbs' => []])

{{--
    ترويسة صفحة موحّدة: مسار تنقّل اختياري، ثم العنوان، ثم سطر معلومات.
    الفتحة الافتراضية للإجراءات (فرز/فلترة) على يسار العنوان.
--}}
<div class="mb-5">
    @if (! empty($breadcrumbs))
        {{-- الهامش السالب على الشريط يعوّض حشو الروابط فيبقى الارتفاع البصري كما هو --}}
        <nav class="mb-2 -my-1.5 flex items-center gap-1.5 text-xs flex-wrap text-[color:var(--sf-text-soft)]"
             aria-label="{{ __('storefront.breadcrumb') }}">
            @foreach ($breadcrumbs as $label => $url)
                @if (! $loop->first)
                    <x-storefront.icon name="chevron-left" class="w-3 h-3 opacity-50 ltr:rotate-180" />
                @endif
                @if ($url && ! $loop->last)
                    {{-- روابط المسار كانت 16px فقط؛ الحشو يرفع مساحة اللمس إلى 40px --}}
                    <a href="{{ $url }}"
                       class="inline-flex items-center min-h-10 -mx-1.5 px-1.5 hover:text-brand-600 transition-colors">{{ $label }}</a>
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
            {{-- بلا shrink-0: عند تكبير النصّ (٢٠٠٪) كان الفرز والتصفية يتجاوزان
                 عرض الشاشة بدل أن ينزلا سطرًا --}}
            <div class="flex items-center gap-2 flex-wrap min-w-0">{{ $slot }}</div>
        @endif
    </div>
</div>
