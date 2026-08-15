<x-app-layout :title="__('شحنات الاستيراد')">
    <x-admin.header
        :title="__('شحنات الاستيراد')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('شحنات الاستيراد') => null]">
        @can('create', \App\Modules\Purchasing\Models\ImportShipment::class)
            <a href="{{ route('admin.purchasing.shipments.create') }}" class="btn-primary btn-sm">+ {{ __('شحنة جديدة') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    {{--
        الرصيد المُعلَّق هو جرس الإنذار: مصاريفُ حُمّلت على بضاعةٍ ولم تصل فواتيرها
        بعد. صفرٌ يعني لا شيء معلّقًا؛ ورقمٌ كبير يعني شحنةً تأخّرت فواتيرها.
    --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-admin.stat-card :label="__('شحنات مفتوحة')" :value="number_format($openCount)" tone="amber" />
        <x-admin.stat-card :label="__('شحنات مُغلقة')" :value="number_format($closedCount)" tone="green" />
        <x-admin.stat-card :label="__('رصيد معلّق (مصاريف لم تصل فواتيرها)')" :value="$openAccrued" money tone="blue" />
    </div>

    <div class="flex flex-wrap items-center gap-2 mb-4">
        @foreach ([null => __('الكل'), 'open' => __('مفتوحة'), 'closed' => __('مُغلقة')] as $key => $label)
            <a href="{{ route('admin.purchasing.shipments.index', $key ? ['status' => $key] : []) }}"
               class="{{ $activeStatus === $key ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ $label }}</a>
        @endforeach
    </div>

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('الرقم') }}</th>
                <th>{{ __('رقم الكونتينر') }}</th>
                <th>{{ __('المورد') }}</th>
                <th>{{ __('الوصول') }}</th>
                <th>{{ __('الفواتير') }}</th>
                <th>{{ __('الحالة') }}</th>
                <th class="text-start">{{ __('فرق التقدير') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($shipments as $s)
                <tr class="hover:bg-gray-50">
                    <td><a href="{{ route('admin.purchasing.shipments.show', $s) }}" class="font-mono text-emerald-600 hover:underline">{{ $s->number }}</a></td>
                    <td class="text-gray-700">{{ $s->reference ?? '—' }}</td>
                    <td class="text-gray-700">{{ $s->supplier?->name ?? '—' }}</td>
                    <td class="text-gray-500">{{ $s->arrived_at?->format('Y-m-d') ?? '—' }}</td>
                    <td class="tabular-nums text-gray-500">{{ $s->invoices_count }}</td>
                    <td><x-admin.badge :tone="$s->isOpen() ? 'amber' : 'green'" :label="$s->isOpen() ? __('مفتوحة') : __('مُغلقة')" /></td>
                    <td class="text-start tabular-nums {{ $s->isOpen() ? 'text-gray-300' : 'font-medium text-gray-800' }}">
                        @if ($s->isOpen()) — @else <x-admin.money :value="$s->variance_amount" /> @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-10">
                    <x-admin.empty-state :title="__('لا شحنات استيراد بعد')"
                        :description="__('أنشئ شحنة لكل كونتينر، واربط بها فاتورة البضاعة وفواتير الشحن والمصاريف التي تصل لاحقًا.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <div class="mt-4">{{ $shipments->links() }}</div>
</x-app-layout>
