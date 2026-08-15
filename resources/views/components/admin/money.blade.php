{{--
    مبلغ برمز عملته.

    رقمٌ بلا عملة يُقرأ خطأً في شاشةٍ تجتمع فيها ثلاث عملات (شيكل ودولار ورمبي)،
    فالرمز جزءٌ من المعنى لا زينة. ويُعرض بحجمٍ أصغر ولونٍ أهدأ ليبقى الرقمُ هو
    ما تقع عليه العين أولًا، والعملةُ تأكيدًا بجانبه.

    Props: value(المبلغ) · symbol(الرمز؛ الافتراضي عملة النظام) · decimals ·
           trim(يحذف الأصفار الزائدة — للنِّسب والكميات لا للمبالغ).
--}}
@props([
    'value' => 0,
    'symbol' => null,
    'decimals' => 2,
    'trim' => false,
])

@php
    $sym = $symbol ?? \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
    $text = number_format((float) $value, $decimals);
    if ($trim && str_contains($text, '.')) {
        $text = rtrim(rtrim($text, '0'), '.');
    }
@endphp

<span {{ $attributes->merge(['class' => 'tabular-nums whitespace-nowrap']) }}>{{ $text }}<span class="ms-1 text-[0.82em] font-normal text-gray-400">{{ $sym }}</span></span>
