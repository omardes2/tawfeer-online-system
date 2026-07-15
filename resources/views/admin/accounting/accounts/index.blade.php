<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المحاسبة') }} — {{ __('دليل الحسابات') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرمز') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('النوع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('قابل للترحيل') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @php $types = ['asset' => 'أصول', 'liability' => 'خصوم', 'equity' => 'حقوق ملكية', 'revenue' => 'إيرادات', 'expense' => 'مصروفات', 'cost_of_goods' => 'تكلفة بضاعة']; @endphp
                        @foreach ($accounts as $a)
                            @php $depth = $a->depth ?? 0; @endphp
                            <tr>
                                <td class="py-2 px-3 text-gray-800 {{ $depth === 0 ? 'font-semibold' : '' }}" style="padding-inline-start: {{ 0.75 + $depth * 1.5 }}rem">{{ $a->code }}</td>
                                <td class="py-2 px-3 {{ $depth === 0 ? 'font-semibold text-gray-800' : 'text-gray-600' }}">{{ $a->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ __($types[$a->type] ?? $a->type) }}</td>
                                <td class="py-2 px-3">{{ $a->is_postable ? '✓' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
