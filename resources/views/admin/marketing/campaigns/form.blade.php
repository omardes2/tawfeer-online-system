<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('حملة جديدة') }}</h2></x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ route('admin.marketing.campaigns.store') }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('اسم الحملة')" name="name"><input type="text" name="name" value="{{ old('name') }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('حالة الاستخدام')" name="use_case">
                        <select name="use_case" class="w-full rounded-md border-gray-300">
                            @foreach (['abandoned_cart','inactivity','post_delivery','birthday','new_product','back_in_stock','order_status','delivery_exception','return_update','win_back','recommendations'] as $u)
                                <option value="{{ $u }}" @selected(old('use_case')===$u)>{{ __('marketing.use_case.'.$u) }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('القناة')" name="channel">
                        <select name="channel" class="w-full rounded-md border-gray-300">
                            @foreach (['whatsapp','email','sms','internal'] as $c)<option value="{{ $c }}" @selected(old('channel')===$c)>{{ __('marketing.channel.'.$c) }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('نوع المحفّز')" name="trigger_type">
                        <select name="trigger_type" class="w-full rounded-md border-gray-300">
                            @foreach (['event','scheduled','manual'] as $t)<option value="{{ $t }}" @selected(old('trigger_type')===$t)>{{ __($t) }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('حدث المحفّز (لنوع event)')" name="trigger_event">
                        <select name="trigger_event" class="w-full rounded-md border-gray-300">
                            <option value="">—</option>
                            @foreach (['order_delivered','return_requested','return_completed','delivery_status_changed','abandoned_cart'] as $e)<option value="{{ $e }}" @selected(old('trigger_event')===$e)>{{ $e }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('قالب (اختياري)')" name="template_id">
                        <select name="template_id" class="w-full rounded-md border-gray-300">
                            <option value="">{{ __('— نص مباشر —') }}</option>
                            @foreach ($templates as $t)<option value="{{ $t->id }}" @selected(old('template_id')==$t->id)>{{ $t->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                </div>

                <x-admin.field :label="__('نص الرسالة (عربي)')" name="body_ar"><textarea name="body_ar" rows="3" class="w-full rounded-md border-gray-300">{{ old('body_ar') }}</textarea></x-admin.field>
                <x-admin.field :label="__('نص الرسالة (إنجليزي)')" name="body_en"><textarea name="body_en" rows="3" class="w-full rounded-md border-gray-300">{{ old('body_en') }}</textarea></x-admin.field>
                <p class="text-xs text-gray-400">{{ __('يدعم متغيّرات مثل') }} <code>{{ '{{name}}' }}</code></p>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <x-admin.field :label="__('تأخير (دقائق)')" name="delay_minutes"><input type="number" min="0" name="delay_minutes" value="{{ old('delay_minutes', 0) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('حدّ التكرار (أيام)')" name="frequency_cap_days"><input type="number" min="0" name="frequency_cap_days" value="{{ old('frequency_cap_days', 0) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('بداية الهدوء (ساعة)')" name="quiet_start"><input type="number" min="0" max="23" name="quiet_start" value="{{ old('quiet_start') }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('نهاية الهدوء (ساعة)')" name="quiet_end"><input type="number" min="0" max="23" name="quiet_end" value="{{ old('quiet_end') }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                </div>

                <details class="border rounded-md p-3">
                    <summary class="text-sm font-medium text-gray-700 cursor-pointer">{{ __('فلاتر الجمهور (للحملات المجدولة/اليدوية)') }}</summary>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-3">
                        <x-admin.field :label="__('عيد ميلاد اليوم')" name="audience[birthday_today]">
                            <select name="audience[birthday_today]" class="w-full rounded-md border-gray-300"><option value="">—</option><option value="1">{{ __('نعم') }}</option></select>
                        </x-admin.field>
                        <x-admin.field :label="__('خامل منذ (أيام)')" name="audience[inactive_days]"><input type="number" min="0" name="audience[inactive_days]" class="w-full rounded-md border-gray-300" /></x-admin.field>
                        <x-admin.field :label="__('فئة العميل')" name="audience[category]"><input type="text" name="audience[category]" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    </div>
                </details>

                @foreach ($errors->all() as $e)<p class="text-xs text-rose-600">{{ $e }}</p>@endforeach
                <div class="flex gap-2 pt-2"><button class="px-4 py-2 bg-fuchsia-600 text-white text-sm rounded-md hover:bg-fuchsia-700">{{ __('حفظ كمسودّة') }}</button><a href="{{ route('admin.marketing.campaigns.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a></div>
            </form>
        </div>
    </div>
</x-app-layout>
