<x-app-layout :title="__('حسابات البزنس في شركة التوصيل')">
    <x-admin.header
        :title="__('حسابات البزنس في شركة التوصيل')"
        :description="__('الحسابات التي تُدخَل تحتها طرود الطلبات لدى شركة التوصيل. زامِنها تلقائيًا من المزوّد أو أضِفها يدويًا، ثم اربط كل مستخدم بحسابه من صفحة تعديل المستخدم.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المستخدمون') => route('admin.users.index'), __('حسابات التوصيل') => null]">
        @can('settings.users.update')
            <form method="POST" action="{{ route('admin.users.delivery_businesses.sync') }}"
                  onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='{{ __('جارٍ المزامنة...') }}'">
                @csrf
                <button type="submit" class="btn-primary btn-sm">{{ __('مزامنة من شركة التوصيل') }}</button>
            </form>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    {{-- إضافة حساب بزنس يدويًا --}}
    <div class="admin-card admin-card-pad mb-6">
        <h3 class="font-semibold text-gray-800 mb-4">{{ __('إضافة حساب بزنس يدويًا') }}</h3>
        <form method="POST" action="{{ route('admin.users.delivery_businesses.store') }}" class="grid gap-4 md:grid-cols-5 items-end">
            @csrf
            <x-admin.field :label="__('معرّف البزنس لدى المزوّد')" name="external_id" required>
                <input type="text" name="external_id" value="{{ old('external_id') }}" required placeholder="13359" class="w-full rounded-lg border-gray-300" />
            </x-admin.field>
            <x-admin.field :label="__('الاسم')" name="name" required>
                <input type="text" name="name" value="{{ old('name') }}" required placeholder="Tawfeer_web" class="w-full rounded-lg border-gray-300" />
            </x-admin.field>
            <x-admin.field :label="__('معرّف عنوان الالتقاط')" name="address_external_id">
                <input type="text" name="address_external_id" value="{{ old('address_external_id') }}" class="w-full rounded-lg border-gray-300" />
            </x-admin.field>
            <x-admin.field :label="__('الهاتف')" name="phone">
                <input type="text" name="phone" value="{{ old('phone') }}" class="w-full rounded-lg border-gray-300" />
            </x-admin.field>
            <div>
                <input type="hidden" name="is_active" value="1" />
                <button type="submit" class="btn-primary w-full">{{ __('إضافة') }}</button>
            </div>
        </form>
    </div>

    {{-- نماذج التعديل/الحذف مُعرّفة خارج الجدول ويشير إليها كل صفّ عبر السمة form (HTML5) --}}
    @foreach ($businesses as $biz)
        <form id="biz-edit-{{ $biz->id }}" method="POST" action="{{ route('admin.users.delivery_businesses.update', $biz) }}">@csrf @method('PUT')</form>
        <form id="biz-del-{{ $biz->id }}" method="POST" action="{{ route('admin.users.delivery_businesses.destroy', $biz) }}"
              onsubmit="return confirm('{{ __('حذف حساب البزنس؟ سيُلغى ربطه بالمستخدمين.') }}')">@csrf @method('DELETE')</form>
    @endforeach

    {{-- قائمة الحسابات --}}
    <div class="admin-card overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-right">
                <tr>
                    <th class="px-4 py-3 font-medium">{{ __('معرّف المزوّد') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('الاسم') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('عنوان الالتقاط') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('الهاتف') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('مستخدمون') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('نشط') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('إجراءات') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($businesses as $biz)
                    <tr>
                        <td class="px-4 py-3"><input type="text" form="biz-edit-{{ $biz->id }}" name="external_id" value="{{ $biz->external_id }}" class="w-24 rounded-md border-gray-300 text-sm" /></td>
                        <td class="px-4 py-3"><input type="text" form="biz-edit-{{ $biz->id }}" name="name" value="{{ $biz->name }}" class="w-48 rounded-md border-gray-300 text-sm" /></td>
                        <td class="px-4 py-3"><input type="text" form="biz-edit-{{ $biz->id }}" name="address_external_id" value="{{ $biz->address_external_id }}" class="w-28 rounded-md border-gray-300 text-sm" /></td>
                        <td class="px-4 py-3"><input type="text" form="biz-edit-{{ $biz->id }}" name="phone" value="{{ $biz->phone }}" class="w-32 rounded-md border-gray-300 text-sm" /></td>
                        <td class="px-4 py-3 text-gray-500">{{ $biz->users_count }}</td>
                        <td class="px-4 py-3">
                            <input type="hidden" form="biz-edit-{{ $biz->id }}" name="is_active" value="0" />
                            <input type="checkbox" form="biz-edit-{{ $biz->id }}" name="is_active" value="1" @checked($biz->is_active) class="rounded border-gray-300 text-emerald-600" />
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <button type="submit" form="biz-edit-{{ $biz->id }}" class="text-emerald-600 hover:underline">{{ __('حفظ') }}</button>
                            <span class="text-gray-300 mx-1">|</span>
                            <button type="submit" form="biz-del-{{ $biz->id }}" class="text-rose-600 hover:underline">{{ __('حذف') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">{{ __('لا توجد حسابات بعد — زامِن من شركة التوصيل أو أضِف يدويًا أعلاه.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
