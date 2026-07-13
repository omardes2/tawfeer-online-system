@props(['title', 'range', 'searchable' => false, 'exportable' => false])
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2>
            <button type="button" onclick="window.print()" class="no-print px-3 py-1.5 bg-gray-700 text-white text-xs rounded-md">{{ __('reports.print_pdf') }}</button>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        {{-- شريط الفلترة السريع --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-4 no-print">
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="flex gap-1">
                    @foreach (['day', 'week', 'month'] as $p)
                        <a href="{{ request()->fullUrlWithQuery(['preset' => $p, 'from' => null, 'to' => null]) }}"
                           @class(['px-3 py-1.5 rounded-md text-sm', 'bg-emerald-600 text-white' => $range->preset === $p, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => $range->preset !== $p])>
                            {{ __('reports.preset_'.$p) }}
                        </a>
                    @endforeach
                </div>
                <input type="hidden" name="preset" value="custom">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('reports.from') }}</label>
                    <input type="date" name="from" value="{{ $range->fromString() }}" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('reports.to') }}</label>
                    <input type="date" name="to" value="{{ $range->toString() }}" class="rounded-md border-gray-300 text-sm">
                </div>
                @if ($searchable)
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('reports.search') }}</label>
                        <input type="text" name="q" value="{{ request('q') }}" class="rounded-md border-gray-300 text-sm">
                    </div>
                @endif
                <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('reports.apply') }}</button>
                @if ($exportable)
                    <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('reports.export_excel') }}</a>
                @endif
            </form>
        </div>

        <div class="text-xs text-gray-400 no-print">{{ $range->fromString() }} — {{ $range->toString() }}</div>

        {{ $slot }}
    </div>

    <style>
        @media print {
            .no-print { display: none !important; }
            nav, header button { display: none !important; }
            body { background: #fff; }
        }
    </style>
</x-app-layout>
