<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('قيد') }} {{ $entry->number }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <x-admin.flash />
            <div class="flex flex-wrap gap-4 text-sm">
                <span>{{ __('التاريخ') }}: <b>{{ $entry->entry_date?->toDateString() }}</b></span>
                <span>{{ __('البيان') }}: {{ $entry->description }}</span>
                <span>{{ __('المصدر') }}: {{ $entry->source }}</span>
                <span>{{ __('الحالة') }}:
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $entry->status === 'posted' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $entry->status === 'posted' ? __('مُرحّل') : __('مسودّة') }}</span>
                    @if ($entry->isReversed()) <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">{{ __('معكوس') }}</span> @endif
                </span>
            </div>
            @if ($entry->reverses_entry_id)
                <p class="text-sm text-amber-600">{{ __('قيد عاكس') }}</p>
            @endif

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الحساب') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('مدين') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('دائن') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('البيان') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @foreach ($entry->lines as $line)
                            <tr>
                                <td class="py-2 px-3 text-gray-700">{{ $line->account?->code }} — {{ $line->account?->name }}</td>
                                <td class="py-2 px-3">{{ $line->debit > 0 ? $line->debit : '' }}</td>
                                <td class="py-2 px-3">{{ $line->credit > 0 ? $line->credit : '' }}</td>
                                <td class="py-2 px-3 text-gray-400">{{ $line->description }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t font-medium">
                        <tr>
                            <td class="py-2 px-3">{{ __('الإجمالي') }}</td>
                            <td class="py-2 px-3">{{ number_format($entry->lines->sum('debit'), 2) }}</td>
                            <td class="py-2 px-3">{{ number_format($entry->lines->sum('credit'), 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="flex flex-wrap gap-2 pt-2 border-t">
                @if ($entry->status === 'draft')
                    @can('post', $entry)
                        <form method="POST" action="{{ route('admin.accounting.journal.post', $entry) }}">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('ترحيل') }}</button></form>
                    @endcan
                @elseif (! $entry->isReversed() && ! $entry->reverses_entry_id)
                    @can('reverse', $entry)
                        <form method="POST" action="{{ route('admin.accounting.journal.reverse', $entry) }}" onsubmit="return confirm('{{ __('إنشاء قيد عاكس؟') }}')">@csrf<button class="px-4 py-2 bg-amber-600 text-white text-sm rounded-md hover:bg-amber-700">{{ __('عكس') }}</button></form>
                    @endcan
                @endif
                <a href="{{ route('admin.accounting.journal.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('رجوع') }}</a>
            </div>
            @if ($entry->status === 'posted')
                <p class="text-xs text-gray-400 border-t pt-2">{{ __('القيد المُرحّل غير قابل للتعديل أو الحذف — التصحيح بقيد عاكس فقط.') }}</p>
            @endif
        </div>
    </div>
</x-app-layout>
