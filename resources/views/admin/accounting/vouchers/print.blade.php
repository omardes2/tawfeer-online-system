<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('accounting.kind.'.$kind) }} — {{ $voucher->number }}</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; color: #111; margin: 0; padding: 32px; }
        .voucher { max-width: 720px; margin: 0 auto; border: 2px solid #333; padding: 24px; }
        .head { display: flex; justify-content: space-between; border-bottom: 2px solid #333; padding-bottom: 12px; margin-bottom: 16px; }
        .title { font-size: 22px; font-weight: bold; }
        .num { font-family: monospace; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        td { padding: 8px; border-bottom: 1px solid #ddd; font-size: 14px; }
        .label { color: #666; width: 35%; }
        .amount { font-size: 20px; font-weight: bold; }
        .sign { display: flex; justify-content: space-between; margin-top: 48px; font-size: 13px; color: #444; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body onload="window.print()">
    <div class="voucher">
        <div class="head">
            <div>
                <div class="title">{{ __('accounting.kind.'.$kind) }}</div>
                <div class="num">{{ $voucher->number }}</div>
            </div>
            <div style="text-align:left">
                <div>{{ \App\Modules\Foundation\Services\Settings::get('store.name', config('app.name')) }}</div>
                <div style="color:#666;font-size:13px">{{ $voucher->voucher_date->format('Y-m-d') }}</div>
            </div>
        </div>

        <table>
            <tr><td class="label">{{ $kind === 'payment' || $kind === 'expense' ? __('دفعنا إلى') : __('استلمنا من') }}</td><td>{{ $voucher->party_name ?: '—' }}</td></tr>
            <tr><td class="label">{{ __('المبلغ') }}</td><td class="amount">{{ number_format($voucher->amount, 2) }} {{ $voucher->currency }}</td></tr>
            <tr><td class="label">{{ __('الخزينة / البنك') }}</td><td>{{ $voucher->treasury?->name }}</td></tr>
            <tr><td class="label">{{ __('البيان') }}</td><td>{{ $voucher->description ?: '—' }}</td></tr>
            @if ($voucher->reference)<tr><td class="label">{{ __('المرجع') }}</td><td>{{ $voucher->reference }}</td></tr>@endif
            @if ($voucher->payment_method)<tr><td class="label">{{ __('طريقة الدفع') }}</td><td>{{ $voucher->payment_method }}</td></tr>@endif
        </table>

        <div class="sign">
            <span>{{ __('المُحاسب') }}: ____________</span>
            <span>{{ __('المستلِم') }}: ____________</span>
        </div>
    </div>
    <div class="no-print" style="text-align:center;margin-top:16px"><button onclick="window.print()">{{ __('طباعة') }}</button></div>
</body>
</html>
