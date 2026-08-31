<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المحاسبة') }} — {{ __('دليل الحسابات') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />

        @php $types = ['asset' => 'أصول', 'liability' => 'خصوم', 'equity' => 'حقوق ملكية', 'revenue' => 'إيرادات', 'expense' => 'مصروفات', 'cost_of_goods' => 'تكلفة بضاعة']; @endphp

        @if ($canManage)
            {{--
                نموذج الإضافة: **الأب وحده يُختار**، والنوع يُورَث منه ولا يُعرض
                حقلًا. اختيارُ النوع بحرّية يُنتج مصروفًا داخل الأصول — يختلّ به
                ميزان المراجعة ويتضخّم الربح، ولا يظهر خطأٌ يُنبّه.
            --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6"
                 x-data="{
                    parent: '',
                    parents: @js($parents->mapWithKeys(fn ($a) => [$a->id => ['code' => $a->code, 'name' => $a->name, 'type' => $a->type]])),
                    get chosen() { return this.parents[this.parent] || null; },
                 }">
                <h3 class="font-semibold text-gray-800 mb-4">{{ __('إضافة بند إلى الدليل') }}</h3>

                <form method="POST" action="{{ route('admin.accounting.accounts.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf

                    <x-admin.field :label="__('الحساب الأب')" name="parent_id" required
                                   :hint="__('البند الجديد يقع تحته، ويرث نوعه المحاسبي.')">
                        <select name="parent_id" x-model="parent" required class="w-full rounded-md border-gray-300">
                            <option value="">{{ __('— اختر —') }}</option>
                            @foreach ($parents as $p)
                                <option value="{{ $p->id }}" @selected(old('parent_id') == $p->id)>
                                    {{ str_repeat('— ', $p->depth ?? 0) }}{{ $p->code }} · {{ $p->name }}
                                </option>
                            @endforeach
                        </select>
                    </x-admin.field>

                    <x-admin.field :label="__('اسم الحساب')" name="name" required :hint="__('مثال: مصروف صيانة')">
                        <input type="text" name="name" value="{{ old('name') }}" maxlength="150" required class="w-full rounded-md border-gray-300" />
                    </x-admin.field>

                    <x-admin.field :label="__('الرمز (اختياري)')" name="code"
                                   :hint="__('يُقترح تاليًا تحت الأب إن تُرك فارغًا، ويجب أن يبدأ برمز الأب.')">
                        <input type="text" name="code" value="{{ old('code') }}" maxlength="30"
                               :placeholder="chosen ? chosen.code + '-0001' : '{{ __('يُولَّد تلقائيًا') }}'"
                               class="w-full rounded-md border-gray-300 font-mono" />
                    </x-admin.field>

                    <x-admin.field :label="__('الاسم بالإنجليزية (اختياري)')" name="name_en">
                        <input type="text" name="name_en" value="{{ old('name_en') }}" maxlength="150" class="w-full rounded-md border-gray-300" />
                    </x-admin.field>

                    {{-- النوع يُعرض ولا يُدخَل: يُطمئن المُدخِل إلى موضعه قبل الحفظ. --}}
                    <div class="md:col-span-2" x-show="chosen" x-cloak>
                        <div class="rounded-md bg-emerald-50 border border-emerald-100 px-3 py-2 text-sm text-emerald-800">
                            {{ __('سيُنشأ حسابًا من نوع') }}
                            <span class="font-semibold" x-text="({
                                asset: '{{ __('أصول') }}', liability: '{{ __('خصوم') }}', equity: '{{ __('حقوق ملكية') }}',
                                revenue: '{{ __('إيرادات') }}', expense: '{{ __('مصروفات') }}', cost_of_goods: '{{ __('تكلفة بضاعة') }}',
                            })[chosen.type] || chosen.type"></span>
                            — {{ __('موروثًا من') }} <span class="font-mono" x-text="chosen.code"></span>.
                            <span class="block mt-0.5 text-xs text-emerald-700">{{ __('ويصبح الأب حساب مراقبة لا يُرحَّل عليه مباشرةً — فلا يُحتسب رصيده مرّتين.') }}</span>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('إضافة') }}</button>
                    </div>
                </form>
            </div>
        @endif

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرمز') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('النوع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('قابل للترحيل') }}</th>
                        @if ($canManage)<th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>@endif
                    </tr></thead>
                    <tbody class="divide-y">
                        @foreach ($accounts as $a)
                            @php $depth = $a->depth ?? 0; @endphp
                            <tr x-data="{ editing: false }" class="{{ $a->is_active ? '' : 'opacity-50' }}">
                                <td class="py-2 px-3 text-gray-800 font-mono {{ $depth === 0 ? 'font-semibold' : '' }}" style="padding-inline-start: {{ 0.75 + $depth * 1.5 }}rem">{{ $a->code }}</td>
                                <td class="py-2 px-3 {{ $depth === 0 ? 'font-semibold text-gray-800' : 'text-gray-600' }}">
                                    <span x-show="! editing">
                                        {{ $a->name }}
                                        @unless ($a->is_active)<span class="ms-1 text-[11px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">{{ __('معطّل') }}</span>@endunless
                                    </span>

                                    @if ($canManage)
                                        {{-- الرمز والنوع والأب لا تُعدَّل: تغييرها يُعيد كتابة تاريخٍ مُرحَّل. --}}
                                        <form method="POST" action="{{ route('admin.accounting.accounts.update', $a) }}"
                                              x-show="editing" x-cloak class="space-y-2 py-1">
                                            @csrf @method('PUT')
                                            <input type="text" name="name" value="{{ $a->name }}" maxlength="150" required class="w-full rounded-md border-gray-300 text-sm" />
                                            <label class="flex items-center gap-1 text-xs text-gray-600">
                                                <input type="hidden" name="is_active" value="0" />
                                                <input type="checkbox" name="is_active" value="1" @checked($a->is_active) class="rounded border-gray-300 text-emerald-600" />
                                                {{ __('نشط') }}
                                            </label>
                                            <div class="flex gap-2">
                                                <button class="px-3 py-1 bg-emerald-600 text-white text-xs rounded-md">{{ __('حفظ') }}</button>
                                                <button type="button" @click="editing = false" class="px-3 py-1 bg-gray-100 text-gray-700 text-xs rounded-md">{{ __('إلغاء') }}</button>
                                            </div>
                                        </form>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-gray-500">{{ __($types[$a->type] ?? $a->type) }}</td>
                                <td class="py-2 px-3">{{ $a->is_postable ? '✓' : '—' }}</td>
                                @if ($canManage)
                                    <td class="py-2 px-3 whitespace-nowrap">
                                        <div class="flex gap-2">
                                            <button type="button" @click="editing = ! editing" class="text-emerald-600 hover:underline text-xs">{{ __('تعديل') }}</button>
                                            <form method="POST" action="{{ route('admin.accounting.accounts.destroy', $a) }}"
                                                  onsubmit="return confirm('{{ __('حذف الحساب؟ لا يُحذف إن كان عليه قيود أو له فروع.') }}')">
                                                @csrf @method('DELETE')
                                                <button class="text-rose-600 hover:underline text-xs">{{ __('حذف') }}</button>
                                            </form>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
