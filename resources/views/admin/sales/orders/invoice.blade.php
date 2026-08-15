<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('الفاتورة') }} {{ $order->number }}</title>
    @php
        $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
        $store = fn ($k, $d = '') => \App\Modules\Foundation\Services\Settings::get($k, $d);
        $subtotal = (float) $order->subtotal;
        $discount = (float) $order->discount_total;
        $shipping = (float) $order->shipping_total;
        $total = (float) $order->total;
        $paid = (float) $order->amount_paid;
        $due = max(0, round($total - $paid, 2));
        $money = fn ($v) => number_format((float) $v, 2).' '.$sym;

        // حالة الدفع: مدفوعة / دُفعت جزئياً / غير مدفوعة.
        if ($paid + 0.001 >= $total && $total > 0) {
            [$stLabel, $stColor] = [__('مدفوعة'), '#16a34a'];
        } elseif ($paid > 0) {
            [$stLabel, $stColor] = [__('دُفعت جزئياً'), '#0d9488'];
        } else {
            [$stLabel, $stColor] = [__('غير مدفوعة'), '#dc2626'];
        }
        $recipient = $order->latestShipment?->recipient_name ?: ($order->customer_name ?: '—');
        $canPay = $order->status !== 'cancelled' && $order->payment_status !== 'paid' && $due > 0;
    @endphp
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; color: #1f2937; margin: 0; background: #f3f4f6; }
        .sheet { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px; position: relative; overflow: hidden;
                 box-shadow: 0 1px 4px rgba(0,0,0,.08); }

        /* شريط الحالة القُطري أعلى اليسار */
        .ribbon { position: absolute; top: 22px; left: -52px; width: 200px; transform: rotate(-45deg);
                  background: {{ $stColor }}; color: #fff; text-align: center; font-size: 13px; font-weight: 700;
                  padding: 7px 0; box-shadow: 0 1px 3px rgba(0,0,0,.2); }

        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; margin-bottom: 32px; }
        .inv-title { font-size: 26px; font-weight: 800; color: #111827; }
        .company { text-align: left; font-size: 12.5px; line-height: 1.9; color: #374151; }
        .company .cname { font-weight: 800; font-size: 15px; color: #111827; }
        .company .muted { color: #6b7280; }

        .parties { display: flex; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 28px; }
        .parties > div { padding: 16px 18px; }
        .parties .to { flex: 1; }
        .parties .meta { width: 46%; background: #fafafa; border-inline-start: 1px solid #e5e7eb; }
        .lbl { color: #6b7280; font-size: 12px; text-decoration: underline; }
        .to .name { font-weight: 800; font-size: 16px; margin-top: 6px; }
        .meta-row { display: flex; justify-content: space-between; font-size: 13px; padding: 3px 0; }
        .meta-row .k { color: #6b7280; }
        .meta-row .v { font-weight: 700; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items thead th { background: #4b5563; color: #fff; font-size: 13px; font-weight: 700; padding: 11px 12px; text-align: start; }
        table.items thead th.num, table.items tbody td.num { text-align: start; }
        table.items tbody td { padding: 11px 12px; font-size: 13.5px; border-bottom: 1px solid #eef0f2; vertical-align: top; }
        table.items tbody tr:nth-child(even) td { background: #fbfbfc; }
        table.items td.prod .opts { display: block; margin-top: 2px; font-size: 12px; color: #6b7280; }
        .prod { font-weight: 600; color: #111827; }
        .desc { color: #4b5563; }
        .accent { color: #2563eb; }

        .totals { margin-top: 22px; display: flex; justify-content: flex-start; }
        .totals table { width: 320px; border-collapse: collapse; }
        .totals td { padding: 7px 4px; font-size: 13.5px; }
        .totals td.k { color: #6b7280; }
        .totals td.v { text-align: start; font-weight: 700; }
        .totals tr.grand td { font-weight: 800; }
        .totals tr.due td { border-top: 2px solid #111827; padding-top: 12px; font-size: 16px; font-weight: 800; }

        .actions { max-width: 820px; margin: 0 auto 40px; display: flex; gap: 10px; justify-content: flex-end; }
        .btn { border: 0; border-radius: 8px; padding: 9px 16px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-print { background: #111827; color: #fff; }
        .btn-pay { background: #059669; color: #fff; }
        .btn-back { background: #e5e7eb; color: #374151; }

        /* نافذة التحصيل */
        .modal { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; padding: 16px; z-index: 50; }
        .modal.open { display: flex; }
        .modal .box { background: #fff; border-radius: 12px; padding: 22px; width: 100%; max-width: 420px; }
        .modal h3 { margin: 0 0 14px; font-size: 17px; }
        .modal label { display: block; font-size: 13px; color: #374151; margin-bottom: 5px; }
        .modal input, .modal select { width: 100%; padding: 9px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; margin-bottom: 14px; }
        .modal .row { display: flex; gap: 8px; justify-content: flex-end; }

        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; max-width: none; padding: 24px; }
            .actions, .modal, .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="ribbon">{{ $stLabel }}</div>

        <div class="head">
            <div>
                <div class="inv-title">{{ __('الفاتورة') }} {{ $order->number }}</div>
            </div>
            <div class="company">
                <div class="cname">{{ $store('store.name', config('app.name')) }}</div>
                @if ($store('store.address'))<div class="muted">{{ $store('store.address') }}</div>@endif
                @if ($store('store.phone'))<div><span class="muted">{{ __('هاتف') }}:</span> {{ $store('store.phone') }}</div>@endif
                @if ($store('store.email'))<div><span class="muted">{{ __('الإيميل') }}:</span> {{ $store('store.email') }}</div>@endif
                @if ($store('store.tax_number'))<div><span class="muted">{{ __('رقم التعريف الضريبي') }}:</span> {{ $store('store.tax_number') }}</div>@endif
            </div>
        </div>

        <div class="parties">
            <div class="to">
                <div class="lbl">{{ __('صدرت الفاتورة إلى') }}:</div>
                <div class="name">{{ $recipient }}</div>
                @if ($order->customer_phone)<div class="muted" style="font-size:13px;color:#6b7280">{{ $order->customer_phone }}</div>@endif
                @if ($order->shipping_address)<div class="muted" style="font-size:12.5px;color:#6b7280;margin-top:4px">{{ $order->shipping_address }}</div>@endif
            </div>
            <div class="meta">
                <div class="meta-row"><span class="k">{{ __('تاريخ الفاتورة') }}</span><span class="v">{{ \Illuminate\Support\Carbon::parse($order->created_at)->format('Y/m/d') }}</span></div>
                <div class="meta-row"><span class="k">{{ __('المبلغ المستحق') }}</span><span class="v">{{ $money($due) }}</span></div>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th>{{ __('المنتج') }}</th>
                    <th>{{ __('الوصف') }}</th>
                    <th class="num">{{ __('الكمية') }}</th>
                    <th class="num">{{ __('السعر') }}</th>
                    <th class="num">{{ __('المبلغ') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td class="prod">
                            {{ $item->variant?->product?->name ?? $item->variant?->name ?? $item->variant?->sku ?? __('صنف') }}
                            {{-- اللون/المقاس: بند «قميص» بلا خياراته لا يكفي مَن يجهّزه --}}
                            @if ($opts = $item->optionsLabel())
                                <span class="opts">{{ $opts }}</span>
                            @endif
                        </td>
                        <td class="desc accent">{{ $item->variant?->sku ?: '—' }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $item->qty, 2), '0'), '.') }}</td>
                        <td class="num">{{ $money($item->unit_price) }}</td>
                        <td class="num">{{ $money((float) $item->qty * (float) $item->unit_price - (float) $item->discount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <table>
                <tr><td class="k">{{ __('المجموع الجزئي') }}:</td><td class="v">{{ $money($subtotal) }}</td></tr>
                @if ($discount > 0)<tr><td class="k">{{ __('الخصم') }}:</td><td class="v">- {{ $money($discount) }}</td></tr>@endif
                @if ($shipping > 0)<tr><td class="k">{{ __('رسوم التوصيل') }}:</td><td class="v">{{ $money($shipping) }}</td></tr>@endif
                <tr class="grand"><td class="k">{{ __('الإجمالي') }}:</td><td class="v">{{ $money($total) }}</td></tr>
                <tr><td class="k">{{ __('المدفوع') }}:</td><td class="v">{{ $money($paid) }}</td></tr>
                <tr class="due"><td class="k">{{ __('إجمالي المبلغ المستحق') }}:</td><td class="v">{{ $money($due) }}</td></tr>
            </table>
        </div>
    </div>

    <div class="actions no-print">
        <a href="{{ route('admin.sales.orders.show', $order) }}" class="btn btn-back">{{ __('رجوع') }}</a>
        @can('update', $order)
            @if ($canPay)
                <button type="button" class="btn btn-pay" onclick="document.getElementById('payModal').classList.add('open')">{{ __('تحصيل دفعة') }}</button>
            @endif
        @endcan
        <button type="button" class="btn btn-print" onclick="window.print()">{{ __('طباعة') }}</button>
    </div>

    @can('update', $order)
        @if ($canPay)
            <div class="modal no-print" id="payModal" onclick="if(event.target===this)this.classList.remove('open')">
                <div class="box">
                    <h3>{{ __('تحصيل دفعة') }} — <span style="color:#6b7280;font-family:monospace">{{ $order->number }}</span></h3>
                    <form method="POST" action="{{ route('admin.sales.orders.settle', $order) }}">
                        @csrf
                        <label>{{ __('المبلغ') }} <span style="color:#9ca3af">({{ __('المتبقّي') }}: {{ $money($due) }})</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" max="{{ $due }}" value="{{ $due }}" required />

                        <label>{{ __('إيداع في') }}</label>
                        <select name="treasury_id" required>
                            <option value="">{{ __('— اختر الخزينة/الحساب —') }}</option>
                            @foreach ($treasuries as $t)
                                <option value="{{ $t->id }}">{{ $t->name }}</option>
                            @endforeach
                        </select>

                        <div class="row">
                            <button type="button" class="btn btn-back" onclick="document.getElementById('payModal').classList.remove('open')">{{ __('إلغاء') }}</button>
                            <button type="submit" class="btn btn-pay">{{ __('تحصيل') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endcan
</body>
</html>
