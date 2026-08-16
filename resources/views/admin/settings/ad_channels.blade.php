<x-app-layout :title="__('قنوات الإعلان')">
    <x-admin.header
        :title="__('قنوات الإعلان')"
        :description="__('صفحات البيع التي يُصرَف عليها إعلانيًّا، وربط كلٍّ منها بحساب البزنس الخاصّ بها.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الإعدادات') => null, __('قنوات الإعلان') => null]" />

    <div class="mb-5">
        <x-admin.alert tone="blue" :title="__('كيف يُسنَد الطلب إلى صفحته')">
            {{ __('الطلب ← الموظفة التي أنشأته ← حساب البزنس المرتبط بها ← الصفحة. فالربط أدناه هو ما يجعل الإسناد آليًّا بلا اختيارٍ يدوي في كل طلب، ولكل صفحة حساب مستقلّ.') }}
            <span class="block mt-1 text-xs">{{ __('القناة التي لا حساب لها لا يُنسب إليها أي طلب، وتظهر صفوفُها بلا مبيعات في «الميزانية اليومية».') }}</span>
        </x-admin.alert>
    </div>

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('اسم الصفحة') }}</th>
                <th>{{ __('المنصّة') }}</th>
                <th>{{ __('حساب البزنس') }}</th>
                <th>{{ __('الموظفات') }}</th>
                <th class="text-start">{{ __('الترتيب') }}</th>
                <th>{{ __('نشطة') }}</th>
                <th class="w-px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($channels as $channel)
                <tr>
                    <td>
                        {{-- النموذج داخل الخليّة لا داخل الصفّ: النموذج لا يجوز أن
                             يمتدّ عبر خلايا، وبقيّة الحقول تشير إليه بخاصّية `form`. --}}
                        <form method="POST" action="{{ route('admin.settings.ad_channels.update', $channel) }}" id="ch-{{ $channel->id }}">
                            @csrf @method('PUT')
                        </form>
                        <input type="text" name="name" form="ch-{{ $channel->id }}" value="{{ $channel->name }}" required maxlength="120"
                               class="w-48 rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    </td>
                    <td>
                        <select name="platform" form="ch-{{ $channel->id }}"
                                class="rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach (\App\Modules\Marketing\Models\AdChannel::PLATFORMS as $key => $label)
                                <option value="{{ $key }}" @selected($channel->platform === $key)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <select name="delivery_business_id" form="ch-{{ $channel->id }}"
                                class="min-w-[13rem] rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">{{ __('— غير مربوطة —') }}</option>
                            @foreach ($businesses as $b)
                                <option value="{{ $b->id }}" @selected($channel->delivery_business_id === $b->id)>
                                    {{ $b->name }}{{ $b->is_active ? '' : ' ('.__('معطّل').')' }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    {{-- أسماء الموظفات: الخطأ في الربط يُرى بالعين قبل أن يظهر رقمًا مغلوطًا. --}}
                    <td class="text-xs text-gray-500 max-w-[16rem]">
                        {{ $channel->delivery_business_id
                            ? (implode('، ', $staff[$channel->delivery_business_id] ?? []) ?: __('لا موظفات على هذا الحساب'))
                            : '—' }}
                    </td>
                    <td class="text-start">
                        <input type="number" name="sort_order" form="ch-{{ $channel->id }}" value="{{ $channel->sort_order }}" min="0" max="999"
                               class="w-16 rounded-md border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500" />
                    </td>
                    <td>
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" name="is_active" value="1" form="ch-{{ $channel->id }}" @checked($channel->is_active)
                                   class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                            <span class="text-gray-500">{{ __('نشطة') }}</span>
                        </label>
                    </td>
                    <td class="whitespace-nowrap">
                        <button type="submit" form="ch-{{ $channel->id }}" class="btn-primary btn-sm">{{ __('حفظ') }}</button>
                        <x-admin.confirm
                            :action="route('admin.settings.ad_channels.destroy', $channel)"
                            method="DELETE"
                            :message="__('حذف القناة «:name»؟ لا يمكن حذفها إن ارتبطت بها طلبات.', ['name' => $channel->name])" />
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="!p-0">
                    <x-admin.empty-state :title="__('لا قنوات')" :description="__('أضف صفحة البيع الأولى من النموذج أدناه.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <div class="admin-card p-4 mt-5">
        <h3 class="font-semibold text-gray-800 mb-3">{{ __('إضافة قناة') }}</h3>
        <form method="POST" action="{{ route('admin.settings.ad_channels.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('اسم الصفحة') }}</label>
                <input type="text" name="name" required maxlength="120"
                       class="w-52 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('المنصّة') }}</label>
                <select name="platform" class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @foreach (\App\Modules\Marketing\Models\AdChannel::PLATFORMS as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('حساب البزنس') }}</label>
                <select name="delivery_business_id" class="min-w-[13rem] rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('— غير مربوطة —') }}</option>
                    @foreach ($businesses as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <label class="inline-flex items-center gap-2 text-sm pb-2">
                <input type="checkbox" name="is_active" value="1" checked
                       class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                <span class="text-gray-500">{{ __('نشطة') }}</span>
            </label>
            <button type="submit" class="btn-primary btn-sm">{{ __('إضافة') }}</button>
        </form>
    </div>

    <p class="mt-4 text-xs text-gray-500">
        {{ __('بعد ربط القنوات، شغّل «php artisan ads:backfill-order-channels» مرّة واحدة لملء قناة الطلبات السابقة. الطلبات الجديدة تُثبَّت قناتُها لحظة الإنشاء.') }}
    </p>
</x-app-layout>
