@use('App\Modules\Marketing\Models\MarketingContact')

<x-app-layout :title="__('جهات الاتصال التسويقية')">
    @php
        $can = auth()->user()->can('marketing.contacts.manage');
        $stateTones = [
            MarketingContact::CONSENT_EXPLICIT => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            MarketingContact::CONSENT_IMPLIED => 'bg-sky-50 text-sky-700 ring-sky-200',
            MarketingContact::CONSENT_UNKNOWN => 'bg-gray-100 text-gray-500 ring-gray-200',
            MarketingContact::CONSENT_OPTED_OUT => 'bg-rose-50 text-rose-700 ring-rose-200',
        ];
    @endphp

    <x-admin.header
        :title="__('جهات الاتصال التسويقية')"
        :description="__('قائمة أرقام الزبائن — مستقلّة عن سجلّ العملاء، بلا حسابات محاسبية.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التسويق') => null, __('جهات الاتصال') => null]" />

    <div class="mb-5">
        <x-admin.alert tone="blue" :title="__('لماذا جدولٌ مستقلّ عن العملاء')">
            {{ __('إنشاء عميلٍ في النظام يُنشئ معه حسابًا في دليل الحسابات. واستيراد خمسة عشر ألف رقمٍ كعملاء كان سيُنشئ خمسة عشر ألف حسابٍ محاسبي لأشخاصٍ لم يشتروا شيئًا.') }}
            <span class="block mt-1 text-xs">{{ __('ومن اشترى منهم يُربَط بعميله تلقائيًّا عند الاستيراد، فلا يتكرّر الشخص.') }}</span>
        </x-admin.alert>
    </div>

    @unless ($channelReady)
        <div class="mb-5">
            <x-admin.alert tone="amber" :title="__('قناة واتساب غير مربوطة بعد')">
                {{ __('القائمة تُبنى وتُنظَّف الآن، لكن لا إرسال حتى يُربَط محرّك واتساب. وهذا مقصود: القائمة تُجهَّز أولًا، ثم تُفتح الذراع.') }}
            </x-admin.alert>
        </div>
    @endunless

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
        <x-admin.stat-card :label="__('إجمالي الأرقام')" :value="number_format($stats['total'])" tone="blue" />
        <x-admin.stat-card :label="__('يجوز مراسلتهم')" :value="number_format($stats['sendable'])"
                           :tone="$stats['sendable'] > 0 ? 'green' : 'gray'"
                           :hint="__('موافقة ضمنية أو صريحة، وغير محجوبين')" />
        <x-admin.stat-card :label="__('مطابقون لعملاء')" :value="number_format($stats['matched'])" tone="gray"
                           :hint="__('اشتروا منك فعلًا')" />
        <x-admin.stat-card :label="__('حجبونا')" :value="number_format($stats['blocked'])"
                           :tone="$stats['blocked'] > 0 ? 'red' : 'gray'"
                           :hint="__('لا يُراسَلون أبدًا')" />
    </div>

    {{-- ═══ الاستيراد ═══ --}}
    @if ($can)
        <form method="POST" action="{{ route('admin.marketing.contacts.import') }}"
              enctype="multipart/form-data" class="admin-card p-5 mb-6">
            @csrf

            <h3 class="font-semibold text-gray-800 mb-1">{{ __('استيراد قائمة') }}</h3>
            <p class="text-xs text-gray-500 mb-4 leading-relaxed">
                {{ __('احفظ ملف الإكسل بصيغة CSV (UTF-8) ثم ارفعه. وحدّد رقم كل عمود بالترتيب — الأول 1، والثاني 2، وهكذا.') }}
                {{ __('الربط يدويّ لأن التخمين يضع عمود التاريخ في خانة الهاتف فيُستورَد الملف كلّه خطأً.') }}
            </p>

            <div class="grid gap-4 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label for="c-file" class="block text-xs text-gray-500 mb-1">{{ __('الملف (CSV)') }}</label>
                    <input id="c-file" type="file" name="file" accept=".csv,text/csv" required
                           class="w-full text-sm rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    @error('file')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="c-phone" class="block text-xs text-gray-500 mb-1">{{ __('عمود الهاتف *') }}</label>
                    <input id="c-phone" type="number" min="1" max="50" name="phone_column" value="1" required
                           class="w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="c-name" class="block text-xs text-gray-500 mb-1">{{ __('الاسم') }}</label>
                        <input id="c-name" type="number" min="1" max="50" name="name_column" placeholder="—"
                               class="w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label for="c-city" class="block text-xs text-gray-500 mb-1">{{ __('المدينة') }}</label>
                        <input id="c-city" type="number" min="1" max="50" name="city_column" placeholder="—"
                               class="w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                </div>
            </div>

            <label class="flex items-center gap-2 mt-3 cursor-pointer">
                <input type="checkbox" name="has_header" value="1" checked class="rounded text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm text-gray-700">{{ __('الصفّ الأول ترويسة (أسماء أعمدة) — يُتخطّى') }}</span>
            </label>

            {{-- الموافقة: إقرارُ تاجرٍ يُسجَّل بنصّه، لا موافقةُ زبونٍ يخترعها النظام --}}
            <div class="mt-5 pt-4 border-t">
                <h4 class="text-sm font-semibold text-gray-800 mb-1">{{ __('أساس المراسلة') }}</h4>
                <p class="text-xs text-gray-500 mb-3 leading-relaxed">
                    {{ __('هذا إقرارٌ منك يُحفَظ مع كل رقم — لا يُنشئ موافقةً من الزبون. ومن حالته «غير معروفة» لن يُراسَل، وهذا حارسٌ يحميك لا عطل.') }}
                </p>

                <div class="grid gap-2 lg:grid-cols-3">
                    @foreach ([
                        MarketingContact::CONSENT_IMPLIED => [__('ضمنية'), __('راسلوني أو اشتروا منّي — علاقةٌ قائمة')],
                        MarketingContact::CONSENT_EXPLICIT => [__('صريحة'), __('وافقوا على استقبال العروض صراحةً')],
                        MarketingContact::CONSENT_UNKNOWN => [__('غير معروفة'), __('أرقامٌ لا أعرف مصدرها — تُستورَد ولا تُراسَل')],
                    ] as $value => [$label, $hint])
                        <label class="flex items-start gap-2.5 rounded-xl border-2 border-gray-200 p-3 cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                            <input type="radio" name="consent_state" value="{{ $value }}"
                                   @checked($value === MarketingContact::CONSENT_IMPLIED)
                                   class="mt-0.5 text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                            <span class="min-w-0">
                                <span class="block font-semibold text-sm text-gray-800">{{ $label }}</span>
                                <span class="block text-[11px] text-gray-500 leading-relaxed">{{ $hint }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-3">
                    <label for="c-basis" class="block text-xs text-gray-500 mb-1">{{ __('وصف المصدر (يُحفَظ مع كل رقم)') }}</label>
                    <input id="c-basis" type="text" name="consent_basis" maxlength="60"
                           placeholder="{{ __('زبائن اشتروا عبر واتساب 2024–2026') }}"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>
            </div>

            <div class="mt-5 pt-4 border-t flex items-center justify-between gap-3">
                <p class="text-xs text-gray-500">
                    {{ __('الأرقام تُوحَّد صيغتها ويُحذف المكرّر، ويُربَط من كان عميلًا. وإعادة الاستيراد لا تُعيد من انسحب أو حجبنا.') }}
                </p>
                <button type="submit" class="btn-primary">{{ __('استورد') }}</button>
            </div>
        </form>
    @endif

    {{-- ═══ القائمة ═══ --}}
    <form method="GET" class="flex flex-wrap items-end gap-3 mb-3">
        <div>
            <label for="c-q" class="block text-xs text-gray-500 mb-1">{{ __('بحث') }}</label>
            <input id="c-q" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('رقم أو اسم') }}"
                   class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="c-state" class="block text-xs text-gray-500 mb-1">{{ __('الموافقة') }}</label>
            <select id="c-state" name="state" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('الكل') }}</option>
                @foreach (MarketingContact::CONSENT_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($state === $value)>{{ __($label) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-secondary btn-sm">{{ __('تصفية') }}</button>
    </form>

    <x-admin.table dense>
        <thead>
            <tr>
                <th>{{ __('الرقم') }}</th>
                <th>{{ __('الاسم') }}</th>
                <th>{{ __('عميل؟') }}</th>
                <th>{{ __('الموافقة') }}</th>
                <th>{{ __('المصدر') }}</th>
                <th class="w-px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($contacts as $contact)
                <tr class="{{ $contact->blocked_at ? 'bg-rose-50/60' : '' }}">
                    <td class="font-mono text-sm tabular-nums" dir="ltr">{{ $contact->phone }}</td>
                    <td class="text-sm text-gray-700">{{ $contact->name ?: '—' }}</td>
                    <td class="text-sm">
                        @if ($contact->customer)
                            <a href="{{ route('admin.crm.customers.show', $contact->customer) }}"
                               class="text-emerald-700 hover:underline">{{ __('نعم') }}</a>
                        @else
                            <span class="text-gray-400">{{ __('لا') }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="rounded px-1.5 py-0.5 text-[11px] ring-1 {{ $stateTones[$contact->consent_state] ?? '' }}">
                            {{ $contact->consentLabel() }}
                        </span>
                        @if ($contact->blocked_at)
                            <span class="block text-[11px] text-rose-600">{{ __('حجبنا') }}</span>
                        @endif
                    </td>
                    <td class="text-xs text-gray-500">
                        {{ $contact->consent_basis ?: $contact->source_ref ?: '—' }}
                    </td>
                    <td class="whitespace-nowrap">
                        @if ($can && $contact->consent_state !== MarketingContact::CONSENT_OPTED_OUT)
                            <form method="POST" action="{{ route('admin.marketing.contacts.opt_out', $contact) }}">
                                @csrf
                                <button type="submit" class="text-rose-600 hover:underline text-sm font-medium">{{ __('لا تراسله') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا جهات اتصال بعد')"
                        :description="__('ارفع ملف CSV بأرقام زبائنك — يُوحَّد ويُنظَّف ويُطابَق بمن اشترى منهم.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <div class="mt-4">{{ $contacts->links() }}</div>
</x-app-layout>
