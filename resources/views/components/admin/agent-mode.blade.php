@props(['mode' => 'active', 'reason' => null])

{{--
    وضع الوكيل على المحادثة.

    الثلاثة مفترقة عمدًا في اللون والنصّ: «موقوف مؤقتًا» تعود تلقائيًّا حين
    تنتهي مقاطعة الموظفة، و«محوّلة» لا تعود إلّا بقرار. وخلطُهما في وسمٍ واحد
    يُفقد من يقرأ الصندوق قدرته على معرفة ما يحتاج متابعةً منه.
--}}
@php
    $map = [
        'active' => ['يردّ', 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'paused' => ['موقوف مؤقتًا', 'bg-amber-50 text-amber-700 ring-amber-200'],
        'handed_off' => ['محوّلة إلى موظفة', 'bg-sky-50 text-sky-700 ring-sky-200'],
    ];
    [$label, $classes] = $map[$mode] ?? $map['active'];
@endphp

<span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 {{ $classes }}"
      @if ($reason) title="{{ $reason }}" @endif>
    {{ __($label) }}
</span>
