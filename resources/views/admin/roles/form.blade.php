@use('App\Support\PermissionLabel')

<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $role->exists ? __('roles.edit') : __('roles.new') }}</h2></x-slot>

    @php
        // يُحسَب مرّة هنا لا داخل الحلقة: الصفحة تعرض ~224 صلاحية، واستدعاء
        // التسمية والشرح لكلٍّ منها ثلاث مرّات في العرض كان يتكرّر بلا داعٍ.
        $granted = old('permissions', $assigned);
        $meta = [];
        foreach ($groups as $module => $perms) {
            foreach ($perms as $perm) {
                $meta[$perm->name] = [
                    'label' => PermissionLabel::for($perm->name),
                    'hint' => PermissionLabel::describe($perm->name),
                    'sensitive' => PermissionLabel::isSensitive($perm->name),
                    'unused' => in_array($perm->name, $unused, true),
                    'granted' => in_array($perm->name, $granted, true),
                ];
            }
        }
        $sensitiveCount = collect($meta)->where('sensitive', true)->count();
        // الميتة الممنوحة تبقى ظاهرة دائمًا: إخفاؤها يجعلها تُحذف عند الحفظ.
        $unusedHidden = collect($meta)->filter(fn ($m) => $m['unused'] && ! $m['granted'])->count();
    @endphp

    <div class="py-8 max-w-6xl mx-auto sm:px-6 lg:px-8"
         x-data="{
            q: '',
            onlySelected: false,
            hideSensitive: false,
            showUnused: false,
            /** يطابق الاسم العربي والشرح والمفتاح الإنجليزي معًا. */
            matches(haystack) {
                return this.q === '' || haystack.toLowerCase().includes(this.q.toLowerCase());
            },
            selectedIn(el) {
                return el.querySelectorAll('input[name=\'permissions[]\']:checked').length;
            },
         }">
        <div class="admin-card p-5 md:p-6">
            <x-admin.flash />

            <form method="POST" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="space-y-5">
                @csrf @if ($role->exists) @method('PUT') @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('roles.name_key')" name="name">
                        <input type="text" name="name" value="{{ old('name', $role->name) }}" required pattern="[a-z0-9_\-]+"
                            @if(in_array($role->name, ['admin'])) readonly @endif
                            class="w-full rounded-lg border-gray-300 font-mono focus:border-emerald-500 focus:ring-emerald-500" />
                        <p class="text-xs text-gray-400 mt-1">{{ __('أحرف صغيرة/أرقام/شرطة فقط') }}</p>
                    </x-admin.field>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('بحث في الصلاحيات') }}</label>
                        <input type="text" x-model="q" placeholder="{{ __('اكتب اسم شاشة أو إجراء… مثل: ترحيل، الميزانية، مخزون') }}"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    </div>
                </div>

                {{--
                    شرحُ الأفعال قبل الجدول: أكثر ما يُربك ليس أسماء الشاشات بل
                    الفرق بين «اعتماد» و«ترحيل» و«عكس» — من لا يعرفه يمنح الثلاثة
                    معًا لأنها تبدو مترادفة.
                --}}
                <details class="rounded-lg border border-sky-200 bg-sky-50/60 p-4 text-sm text-sky-900">
                    <summary class="cursor-pointer font-semibold">{{ __('ماذا تعني هذه الأفعال؟ (اقرأها مرّة واحدة)') }}</summary>
                    <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        @foreach ([
                            'عرض' => 'يرى ولا يغيّر شيئًا.',
                            'إنشاء' => 'يُضيف سجلًّا جديدًا.',
                            'تعديل' => 'يغيّر سجلًّا قائمًا.',
                            'إدارة' => 'إضافة وتعديل وحذف معًا — أوسع من «عرض» بكثير.',
                            'اعتماد' => 'يوافق فيسمح بالتنفيذ. الموافقة قرارٌ إداري لا أثر محاسبي.',
                            'ترحيل' => 'يُثبّت في الدفاتر. بعده لا يُحذف السجلّ، والتصحيح بقيدٍ عاكس.',
                            'عكس' => 'يُلغي أثر ما رُحِّل بقيدٍ مضادّ — لا يمحو التاريخ.',
                            'صرف' => 'يُخرج مالًا فعليًّا من الخزينة.',
                            'حذف' => 'يمحو السجلّ — لا تراجع.',
                            'عرض التكلفة' => 'يكشف تكلفة الشراء وهامش الربح.',
                        ] as $verb => $meaning)
                            <div class="flex gap-2">
                                <dt class="font-semibold shrink-0">{{ __($verb) }}</dt>
                                <dd class="text-sky-800">{{ __($meaning) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-xs">
                        {{ __('الصلاحيات الموسومة') }}
                        <span class="mx-1 rounded px-1.5 py-0.5 text-[10px] font-semibold bg-rose-100 text-rose-700 ring-1 ring-rose-200">{{ __('حسّاسة') }}</span>
                        {{ __('تُخرج مالًا أو لا يُتراجَع عنها أو تكشف التكلفة — امنحها بقصد، لا ضمن «تحديد الكل».') }}
                    </p>
                </details>

                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" x-model="onlySelected" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                        <span class="text-gray-600">{{ __('أظهر الممنوح فقط') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" x-model="hideSensitive" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                        <span class="text-gray-600">{{ __('أخفِ الحسّاسة') }}</span>
                    </label>
                    @if ($unusedHidden > 0)
                        {{--
                            الميتة مخفيّة افتراضيًّا لا محذوفة: بقايا مراحل سابقة
                            لا يفحصها الكود، فعرضُها يوهم أنها تفتح شيئًا. وتبقى في
                            الصفحة كي لا تُسحَب من دورٍ يحملها عند الحفظ.
                        --}}
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" x-model="showUnused" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                            <span class="text-gray-600">{{ __('أظهر غير المستخدمة (:n)', ['n' => $unusedHidden]) }}</span>
                        </label>
                    @endif
                    <span class="text-xs text-gray-400 ms-auto">
                        {{ trans_choice('صلاحية واحدة|:count صلاحية مستخدمة', count($meta) - $unusedHidden, ['count' => count($meta) - $unusedHidden]) }}
                        · {{ __(':n منها حسّاسة', ['n' => $sensitiveCount]) }}
                    </span>
                </div>

                <div class="space-y-4">
                    @foreach ($groups as $module => $perms)
                        @php
                            $glabel = __('perm_groups.'.$module);
                            $glabel = $glabel === 'perm_groups.'.$module ? \Illuminate\Support\Str::headline($module) : $glabel;
                            // بيانات ثابتة للقسم: تُحسب ظهورَه من المرشّحات مباشرةً
                            // بدل قراءة حالة عناصره من DOM — تلك لا تتفاعل مع تغيّر
                            // المرشّح، فيبقى قسمٌ فارغٌ ظاهرًا أو يختفي قسمٌ فيه نتائج.
                            $items = collect($perms)->map(fn ($p) => [
                                'h' => $meta[$p->name]['label'].' '.$meta[$p->name]['hint'].' '.$p->name,
                                's' => $meta[$p->name]['sensitive'],
                                // الميتة الممنوحة تُحسَب ظاهرةً دائمًا.
                                'u' => $meta[$p->name]['unused'] && ! $meta[$p->name]['granted'],
                            ])->values();
                            $visibleCount = $items->where('u', false)->count();
                        @endphp
                        <div class="rounded-lg border border-gray-200"
                             x-data="{
                                open: true,
                                count: 0,
                                items: {{ Illuminate\Support\Js::from($items) }},
                                get shown() {
                                    return this.items.filter(i => matches(i.h)
                                        && (! hideSensitive || ! i.s)
                                        && (showUnused || ! i.u)).length;
                                },
                             }"
                             x-init="count = selectedIn($el); $el.addEventListener('change', () => count = selectedIn($el))"
                             x-show="shown > 0 && (! onlySelected || count > 0)">
                            <div class="flex items-center justify-between gap-3 flex-wrap px-4 py-3 bg-gray-50 rounded-t-lg border-b border-gray-200">
                                <button type="button" x-on:click="open = ! open" class="flex items-center gap-2 font-semibold text-gray-800">
                                    <svg class="w-4 h-4 text-gray-400 transition" :class="! open && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                    {{ $glabel }}
                                    {{-- عدّاد الممنوح: يقول بلمحة أين مُنح الدور صلاحيات دون فتح القسم. --}}
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                          :class="count > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-200 text-gray-500'"
                                          x-text="count + ' / ' + (showUnused ? {{ count($perms) }} : {{ $visibleCount }})"></span>
                                </button>
                                <label class="text-xs text-gray-500 flex items-center gap-1.5">
                                    {{-- يُطلَق `change` عمدًا: التحديد الجماعي يكتب على DOM مباشرةً،
                                         وبلا الحدث تبقى حالة كل صفٍّ قديمة فيخطئ مرشّح «الممنوح فقط». --}}
                                    <input type="checkbox"
                                           x-on:click="$el.closest('.rounded-lg').querySelectorAll('input[name=\'permissions[]\']').forEach(c => {
                                               c.checked = $el.checked;
                                               c.dispatchEvent(new Event('change', { bubbles: true }));
                                           })"
                                           class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                                    {{ __('تحديد الكل في هذا القسم') }}
                                </label>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-4 gap-y-1 p-3" x-show="open">
                                @foreach ($perms as $perm)
                                    @php $m = $meta[$perm->name]; @endphp
                                    <label data-perm
                                        x-data="{ granted: {{ in_array($perm->name, old('permissions', $assigned)) ? 'true' : 'false' }} }"
                                        x-show="matches(@js($m['label'].' '.$m['hint'].' '.$perm->name))
                                                && (! onlySelected || granted)
                                                && (! hideSensitive || ! {{ $m['sensitive'] ? 'true' : 'false' }})
                                                && (showUnused || granted || ! {{ $m['unused'] ? 'true' : 'false' }})"
                                        class="flex items-start gap-2.5 rounded-md p-2 hover:bg-gray-50 cursor-pointer {{ $m['unused'] ? 'bg-gray-50/70' : '' }}">
                                        <input type="checkbox" name="permissions[]" value="{{ $perm->name }}"
                                            @checked(in_array($perm->name, old('permissions', $assigned)))
                                            x-model="granted"
                                            class="mt-1 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                                        <span class="min-w-0 leading-snug">
                                            <span class="flex items-center gap-1.5 flex-wrap">
                                                <span class="text-sm font-medium text-gray-800">{{ $m['label'] }}</span>
                                                @if ($m['sensitive'])
                                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold bg-rose-100 text-rose-700 ring-1 ring-rose-200">{{ __('حسّاسة') }}</span>
                                                @endif
                                                @if ($m['unused'])
                                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold bg-gray-200 text-gray-600 ring-1 ring-gray-300"
                                                          title="{{ __('لا يفحصها الكود في أي مكان — بقيّة من مرحلة سابقة، ومنحها لا يفتح شيئًا.') }}">{{ __('غير مستخدمة') }}</span>
                                                @endif
                                            </span>
                                            {{-- الشرح هو المقصود من التعديل: الاسم وحده لا يميّز. --}}
                                            <span class="block text-xs text-gray-500 mt-0.5">{{ $m['hint'] }}</span>
                                            <span class="block text-gray-300 font-mono text-[10px] mt-0.5" dir="ltr">{{ $perm->name }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-2 pt-2">
                    <button class="btn-primary">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.roles.index') }}" class="btn-secondary">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
