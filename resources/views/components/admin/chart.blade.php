{{--
    مكوّن رسم بياني تفاعلي (Chart.js) — خطي/عمودي/دائري/حلقي/مساحي.

    Props:
      id       معرّف فريد للـ canvas (يُولّد تلقائيًا إن غاب).
      type     line | bar | pie | doughnut | area (area = line مملوء).
      labels   مصفوفة تسميات المحور/القطاعات.
      datasets مصفوفة سلاسل: كل عنصر ['label' => .., 'data' => [..], 'color' => '#..'?].
      height   ارتفاع الحاوية بالبكسل (افتراضي 288).
      stacked  تكديس الأعمدة (bool).
      horizontal  أعمدة أفقية (bool).
      currency عرض قيم المحور كعملة (bool).
--}}
@props([
    'id' => null,
    'type' => 'bar',
    'labels' => [],
    'datasets' => [],
    'height' => 288,
    'stacked' => false,
    'horizontal' => false,
    'currency' => false,
])

@php
    $chartId = $id ?: 'chart_'.\Illuminate\Support\Str::random(8);
    $isArea = $type === 'area';
    $chartType = $isArea ? 'line' : $type;
    $isPie = in_array($type, ['pie', 'doughnut'], true);

    // لوحة ألوان متناسقة مع هوية اللوحة (أخضر/سماوي/كهرماني/بنفسجي/وردي...).
    $palette = ['#10b981', '#0ea5e9', '#f59e0b', '#8b5cf6', '#ef4444', '#14b8a6', '#f97316', '#6366f1', '#ec4899', '#84cc16'];

    // نقبل Collection أو array للتسميات والبيانات.
    $labelValues = collect($labels)->values()->all();

    $ds = [];
    foreach ($datasets as $i => $d) {
        $color = $d['color'] ?? $palette[$i % count($palette)];
        $base = [
            'label' => $d['label'] ?? '',
            'data' => collect($d['data'] ?? [])->map(fn ($v) => is_numeric($v) ? $v + 0 : $v)->values()->all(),
        ];
        if ($isPie) {
            $base['backgroundColor'] = $d['colors'] ?? array_slice(array_merge($palette, $palette), 0, count($base['data']));
            $base['borderColor'] = '#fff';
            $base['borderWidth'] = 2;
        } else {
            $base['backgroundColor'] = $isArea ? $color.'22' : $color;
            $base['borderColor'] = $color;
            $base['borderWidth'] = 2;
            $base['borderRadius'] = $chartType === 'bar' ? 6 : 0;
            $base['fill'] = $isArea;
            $base['tension'] = 0.35;
            $base['pointRadius'] = $chartType === 'line' ? 2 : 0;
            $base['maxBarThickness'] = 42;
        }
        $ds[] = $base;
    }

    $config = [
        'type' => $chartType,
        'data' => ['labels' => $labelValues, 'datasets' => $ds],
        'options' => [
            'responsive' => true,
            'indexAxis' => $horizontal ? 'y' : 'x',
            'plugins' => [
                'legend' => ['display' => $isPie || count($ds) > 1, 'position' => 'bottom'],
            ],
            'scales' => $isPie ? new \stdClass : [
                'x' => ['stacked' => $stacked, 'grid' => ['display' => false]],
                'y' => ['stacked' => $stacked, 'beginAtZero' => true, 'grid' => ['color' => '#f1f5f9']],
            ],
        ],
    ];
@endphp

<div style="height: {{ (int) $height }}px" class="relative">
    <canvas id="{{ $chartId }}"></canvas>
</div>
<script>
    (function () {
        var cfg = @json($config);
        function boot() {
            if (!window.Chart) { return setTimeout(boot, 60); }
            var el = document.getElementById(@json($chartId));
            if (!el || el.dataset.rendered) { return; }
            el.dataset.rendered = '1';
            new window.Chart(el.getContext('2d'), cfg);
        }
        boot();
    })();
</script>
