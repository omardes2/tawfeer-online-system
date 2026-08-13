@props(['price', 'regular' => null, 'size' => 'md'])

{{-- عرض السعر: الحالي بلون الهوية، والسابق مشطوبًا تحته إن وُجد خصم. --}}
@php
    $onSale = $regular !== null && (float) $regular > (float) $price;
    $sizes = ['sm' => 'text-sm', 'md' => 'text-base', 'lg' => 'text-2xl'][$size] ?? 'text-base';
@endphp

<span {{ $attributes->merge(['class' => 'flex flex-wrap items-baseline gap-x-2 gap-y-0.5']) }}>
    <span class="sf-price {{ $sizes }}">{{ number_format((float) $price, 2) }} {{ __('storefront.currency') }}</span>
    @if ($onSale)
        <span class="sf-price-old">{{ number_format((float) $regular, 2) }} {{ __('storefront.currency') }}</span>
    @endif
</span>
