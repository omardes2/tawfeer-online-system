<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $campaign->name }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />

        {{-- الحالة والانتقالات --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <span class="text-sm text-gray-500">{{ __('الحالة') }}:</span>
                    <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-sm">{{ __('marketing.status.'.$campaign->status) }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($campaign->status === 'draft')
                        <form method="POST" action="{{ route('admin.marketing.campaigns.submit', $campaign) }}">@csrf<button class="px-3 py-1.5 bg-amber-500 text-white text-sm rounded-md">{{ __('إرسال للاعتماد') }}</button></form>
                    @endif
                    @can('marketing.campaigns.approve')
                        @if ($campaign->status === 'pending_approval')
                            <form method="POST" action="{{ route('admin.marketing.campaigns.approve', $campaign) }}">@csrf<button class="px-3 py-1.5 bg-emerald-600 text-white text-sm rounded-md">{{ __('اعتماد') }}</button></form>
                        @endif
                        @if ($campaign->status === 'approved')
                            <form method="POST" action="{{ route('admin.marketing.campaigns.activate', $campaign) }}">@csrf<button class="px-3 py-1.5 bg-emerald-600 text-white text-sm rounded-md">{{ __('تفعيل') }}</button></form>
                        @endif
                    @endcan
                    @if ($campaign->status === 'active')
                        <form method="POST" action="{{ route('admin.marketing.campaigns.pause', $campaign) }}">@csrf<button class="px-3 py-1.5 bg-gray-200 text-gray-700 text-sm rounded-md">{{ __('إيقاف مؤقت') }}</button></form>
                    @endif
                </div>
            </div>

            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div><dt class="text-gray-500">{{ __('القناة') }}</dt><dd>{{ __('marketing.channel.'.$campaign->channel) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('المحفّز') }}</dt><dd>{{ $campaign->trigger_type }}{{ $campaign->trigger_event ? ' · '.$campaign->trigger_event : '' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('حدّ التكرار') }}</dt><dd>{{ $campaign->frequency_cap_days ?: '—' }} {{ __('يوم') }}</dd></div>
                <div><dt class="text-gray-500">{{ __('ساعات الهدوء') }}</dt><dd>{{ $campaign->quiet_start !== null ? $campaign->quiet_start.'–'.$campaign->quiet_end : '—' }}</dd></div>
            </dl>
        </div>

        {{-- اختبار الإرسال --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('إرسال تجريبي') }}</h3>
            <form method="POST" action="{{ route('admin.marketing.campaigns.test', $campaign) }}" class="flex items-end gap-2">
                @csrf
                <div><label class="block text-xs text-gray-600 mb-1">{{ __('معرّف العميل') }}</label><input type="number" name="customer_id" required class="rounded-md border-gray-300 text-sm" /></div>
                <button class="px-3 py-2 bg-indigo-600 text-white text-sm rounded-md">{{ __('إرسال تجريبي') }}</button>
            </form>
        </div>

        {{-- إحصاءات ورسائل التنفيذ --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('سجلّ التنفيذ') }}</h3>
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach ($stats as $status => $count)
                    <span class="px-2 py-1 rounded-md bg-gray-100 text-gray-700 text-xs">{{ $status }}: {{ $count }}</span>
                @endforeach
            </div>
            <table class="w-full text-sm">
                <thead class="text-gray-500 border-b"><tr><th class="py-2 text-start">{{ __('المستلِم') }}</th><th class="py-2 text-start">{{ __('الحالة') }}</th><th class="py-2 text-start">{{ __('محاولات') }}</th><th class="py-2 text-start">{{ __('أُرسلت') }}</th></tr></thead>
                <tbody>
                    @forelse ($messages as $m)
                        <tr class="border-b last:border-0"><td class="py-2">{{ $m->recipient }}</td><td class="py-2">{{ $m->status }}</td><td class="py-2">{{ $m->attempts }}</td><td class="py-2">{{ $m->sent_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-gray-400">{{ __('لا رسائل بعد.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
