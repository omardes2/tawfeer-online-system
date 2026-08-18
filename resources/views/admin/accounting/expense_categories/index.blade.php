<x-app-layout :title="__('تصنيفات المصروفات')">
    @php($can = auth()->user()->can('accounting.expense_categories.manage'))

    <x-admin.header
        :title="__('تصنيفات المصروفات')"
        :description="__('كل تصنيف يفتح حسابه تلقائيًا تحت «مصاريف تشغيلية» في دليل الحسابات.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المالية والمحاسبة') => null, __('تصنيفات المصروفات') => null]" />

    <x-admin.flash />

    <div class="mb-5">
        <x-admin.alert tone="blue" :title="__('لماذا أبٌ مستقلّ')">
            {{ __('تحت «المصروفات 5000» تعيش حسابات النظام — فروق تقدير الاستيراد وفروق الصرف — وهي نتائجُ تقديرٍ لا مصروفٌ أُنفق. تصنيفاتك تُفتح تحت «مصاريف تشغيلية 5100» وحدها، فيبقى تقرير مصاريفك قابلًا للمقارنة بشهرٍ آخر.') }}
        </x-admin.alert>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- إضافة --}}
        @if ($can)
            <div class="admin-card admin-card-pad lg:order-2 self-start">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('تصنيف جديد') }}</h3>
                <form method="POST" action="{{ route('admin.accounting.expense_categories.store') }}" class="space-y-3">
                    @csrf
                    <x-admin.field :label="__('اسم التصنيف')" name="name" required :hint="__('مثال: عمال تنزيل')">
                        <input type="text" name="name" value="{{ old('name') }}" maxlength="120" required class="w-full rounded-md border-gray-300" />
                    </x-admin.field>
                    <x-admin.field :label="__('الاسم بالإنجليزية (اختياري)')" name="name_en">
                        <input type="text" name="name_en" value="{{ old('name_en') }}" maxlength="120" class="w-full rounded-md border-gray-300" />
                    </x-admin.field>
                    <x-admin.field :label="__('الترتيب')" name="sort_order">
                        <input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0" max="9999" class="w-full rounded-md border-gray-300" />
                    </x-admin.field>
                    <button class="w-full px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('حفظ وفتح الحساب') }}</button>
                </form>
            </div>
        @endif

        {{-- القائمة --}}
        <div class="lg:col-span-2 lg:order-1">
            <x-admin.table :title="__('التصنيفات')">
                <thead>
                    <tr>
                        <th>{{ __('التصنيف') }}</th>
                        <th>{{ __('الحساب') }}</th>
                        <th class="text-center">{{ __('الحالة') }}</th>
                        <th class="text-center">{{ __('إجراءات') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        <tr x-data="{ editing: false }">
                            <td>
                                <div x-show="! editing">
                                    <span class="font-medium text-gray-800">{{ $category->name }}</span>
                                    @if ($category->is_system)
                                        <span class="ms-1 text-[11px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">{{ __('نظام') }}</span>
                                    @endif
                                    @if ($category->name_en)
                                        <span class="block text-xs text-gray-400">{{ $category->name_en }}</span>
                                    @endif
                                </div>

                                @if ($can)
                                    <form method="POST" action="{{ route('admin.accounting.expense_categories.update', $category) }}"
                                          x-show="editing" x-cloak class="space-y-2 py-1">
                                        @csrf @method('PUT')
                                        <input type="text" name="name" value="{{ $category->name }}" maxlength="120" required class="w-full rounded-md border-gray-300 text-sm" />
                                        <input type="text" name="name_en" value="{{ $category->name_en }}" maxlength="120" placeholder="{{ __('بالإنجليزية') }}" class="w-full rounded-md border-gray-300 text-sm" />
                                        <div class="flex items-center gap-3">
                                            <input type="number" name="sort_order" value="{{ $category->sort_order }}" min="0" max="9999" class="w-20 rounded-md border-gray-300 text-sm" />
                                            <label class="flex items-center gap-1 text-xs text-gray-600">
                                                <input type="hidden" name="is_active" value="0" />
                                                <input type="checkbox" name="is_active" value="1" @checked($category->is_active) class="rounded border-gray-300 text-emerald-600" />
                                                {{ __('نشط') }}
                                            </label>
                                        </div>
                                        <div class="flex gap-2">
                                            <button class="px-3 py-1 bg-emerald-600 text-white text-xs rounded-md">{{ __('حفظ') }}</button>
                                            <button type="button" @click="editing = false" class="px-3 py-1 bg-gray-100 text-gray-600 text-xs rounded-md">{{ __('إلغاء') }}</button>
                                        </div>
                                    </form>
                                @endif
                            </td>
                            <td class="font-mono text-xs text-gray-500">
                                {{ $category->account?->code ?? '—' }}
                            </td>
                            <td class="text-center">
                                <x-admin.badge :tone="$category->is_active ? 'green' : 'gray'"
                                               :label="$category->is_active ? __('نشط') : __('معطّل')" />
                            </td>
                            <td class="text-center whitespace-nowrap">
                                @if ($can)
                                    <button type="button" @click="editing = ! editing" class="text-xs text-emerald-600 hover:underline">{{ __('تعديل') }}</button>
                                    @unless ($category->is_system)
                                        <span class="text-gray-300 mx-1">|</span>
                                        <form method="POST" action="{{ route('admin.accounting.expense_categories.destroy', $category) }}" class="inline"
                                              onsubmit="return confirm('{{ __('حذف التصنيف؟ يُرفض إن تحرّكت عليه قيود.') }}')">
                                            @csrf @method('DELETE')
                                            <button class="text-xs text-rose-600 hover:underline">{{ __('حذف') }}</button>
                                        </form>
                                    @endunless
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-10 text-center text-gray-400">{{ __('لا توجد تصنيفات بعد.') }}</td></tr>
                    @endforelse
                </tbody>
            </x-admin.table>

            <p class="mt-3 text-xs text-gray-400">
                {{ __('تصنيف النظام مربوط بحساب يُرحّل عليه النظام آليًا (الشحن، عمولات المسوّقين) فلا يُحذف — يُعطَّل ليختفي من قائمة الاختيار. والحساب لا يُحذف أبدًا: حذفُه يترك قيودًا بلا حساب.') }}
            </p>
        </div>
    </div>
</x-app-layout>
