@props(['value' => 0, 'class' => ''])

{{--
    خمس نجوم تعكس تقييمًا. النصف الأعلى يُقرَّب لأعلى بصريًّا (4.5 → خمس مضيئة
    منها واحدة نصفية غير مرسومة هنا) — نكتفي بالتقريب لأقرب نجمة كاملة، فالرقم
    مكتوب بجوارها دائمًا ولا يعتمد القارئ على العدّ.
--}}
@php
    $filled = (int) round((float) $value);
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-0.5 '.$class]) }}
      role="img" aria-label="{{ trans_choice('storefront.reviews_star', $filled, ['count' => $filled]) }}">
    @for ($i = 1; $i <= 5; $i++)
        <x-storefront.icon name="star" class="w-4 h-4 {{ $i <= $filled ? 'text-gold-400' : 'text-gray-300' }}" filled />
    @endfor
</span>
