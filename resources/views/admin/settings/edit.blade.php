<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('settings.title') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8" x-data="{ tab: 'general' }">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />

            {{-- تبويبات --}}
            <div class="flex flex-wrap gap-1 border-b mb-6 -mx-2 px-2 overflow-x-auto">
                @foreach (['general' => 'settings.tab_general', 'openai' => 'settings.tab_openai', 'email' => 'settings.tab_email', 'whatsapp' => 'settings.tab_whatsapp', 'delivery' => 'settings.tab_delivery', 'seo' => 'settings.tab_seo', 'system' => 'settings.tab_system'] as $key => $label)
                    <button type="button" @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'border-emerald-600 text-emerald-600' : 'border-transparent text-gray-500'" class="px-3 py-2 text-sm border-b-2 whitespace-nowrap">{{ __($label) }}</button>
                @endforeach
            </div>

            <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf @method('PUT')

                {{-- عام --}}
                <div x-show="tab === 'general'" class="space-y-4">
                    <x-admin.field :label="__('settings.store_name')" name="store_name"><input type="text" name="store_name" value="{{ old('store_name', $values['store_name']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('settings.company_info')" name="store_company_info"><textarea name="store_company_info" rows="3" class="w-full rounded-md border-gray-300">{{ old('store_company_info', $values['store_company_info']) }}</textarea></x-admin.field>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-admin.field :label="__('settings.logo')" name="store_logo">
                            @if ($values['store_logo'])<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($values['store_logo']) }}" class="h-12 mb-2" alt="logo" />@endif
                            <input type="file" name="store_logo" accept="image/*" class="text-sm" />
                        </x-admin.field>
                        <x-admin.field :label="__('settings.favicon')" name="store_favicon">
                            @if ($values['store_favicon'])<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($values['store_favicon']) }}" class="h-8 mb-2" alt="favicon" />@endif
                            <input type="file" name="store_favicon" accept=".png,.ico" class="text-sm" />
                        </x-admin.field>
                    </div>

                    <x-admin.field :label="__('حدّ التنبيه بنقص المخزون (افتراضي)')" name="inventory_default_reorder_level">
                        <input type="number" step="0.001" min="0" name="inventory_default_reorder_level"
                               value="{{ old('inventory_default_reorder_level', $values['inventory_default_reorder_level'] ?? 0) }}"
                               class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                        <p class="mt-1 text-xs text-gray-500">
                            {{ __('يظهر الصنف في «تنبيهات النقص» عندما ينزل المتوفّر إلى هذا الحدّ أو دونه. صفر يعني تنبيه الأصناف النافدة فقط. يمكن تجاوزه لكل صنف من صفحة المنتج.') }}
                        </p>
                    </x-admin.field>
                </div>

                {{-- OpenAI --}}
                <div x-show="tab === 'openai'" x-cloak class="space-y-4">
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="openai_enabled" value="0" /><input type="checkbox" name="openai_enabled" value="1" @checked(old('openai_enabled', $values['openai_enabled'])) class="rounded border-gray-300 text-emerald-600" />{{ __('settings.ai_enable') }}</label>
                    <x-admin.field :label="__('settings.ai_model')" name="openai_model">
                        <select name="openai_model" class="w-full rounded-md border-gray-300">
                            @foreach (['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1'] as $m)<option value="{{ $m }}" @selected(old('openai_model', $values['openai_model'] ?: 'gpt-4o-mini') === $m)>{{ $m }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('settings.ai_key')" name="openai_key">
                        <input type="password" name="openai_key" placeholder="{{ $values['openai_key'] ? __('settings.secret_set') : __('settings.secret_unset') }}" autocomplete="new-password" class="w-full rounded-md border-gray-300" />
                        <p class="text-xs text-amber-600 mt-1">{{ __('settings.secret_note') }}</p>
                    </x-admin.field>
                </div>

                {{-- البريد --}}
                <div x-show="tab === 'email'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field label="SMTP Host" name="mail_host"><input type="text" name="mail_host" value="{{ old('mail_host', $values['mail_host']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field label="SMTP Port" name="mail_port"><input type="number" name="mail_port" value="{{ old('mail_port', $values['mail_port']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field label="Username" name="mail_username"><input type="text" name="mail_username" value="{{ old('mail_username', $values['mail_username']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field label="Password" name="mail_password"><input type="password" name="mail_password" placeholder="{{ $values['mail_password'] ? __('settings.secret_set') : __('settings.secret_unset') }}" autocomplete="new-password" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field label="Encryption" name="mail_encryption">
                        <select name="mail_encryption" class="w-full rounded-md border-gray-300">
                            @foreach (['tls', 'ssl', 'null'] as $e)<option value="{{ $e }}" @selected(old('mail_encryption', $values['mail_encryption']) === $e)>{{ $e }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('settings.from_address')" name="mail_from_address"><input type="email" name="mail_from_address" value="{{ old('mail_from_address', $values['mail_from_address']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('settings.from_name')" name="mail_from_name"><input type="text" name="mail_from_name" value="{{ old('mail_from_name', $values['mail_from_name']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                </div>

                {{-- واتساب --}}
                <div x-show="tab === 'whatsapp'" x-cloak class="space-y-4">
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="whatsapp_enabled" value="0" /><input type="checkbox" name="whatsapp_enabled" value="1" @checked(old('whatsapp_enabled', $values['whatsapp_enabled'])) class="rounded border-gray-300 text-emerald-600" />{{ __('settings.wa_enable') }}</label>
                    <x-admin.field :label="__('settings.wa_endpoint')" name="whatsapp_endpoint"><input type="url" name="whatsapp_endpoint" value="{{ old('whatsapp_endpoint', $values['whatsapp_endpoint']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('settings.wa_token')" name="whatsapp_token"><input type="password" name="whatsapp_token" placeholder="{{ $values['whatsapp_token'] ? __('settings.secret_set') : __('settings.secret_unset') }}" autocomplete="new-password" class="w-full rounded-md border-gray-300" /></x-admin.field>
                </div>

                {{-- التوصيل --}}
                <div x-show="tab === 'delivery'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('settings.default_fee')" name="delivery_default_fee"><input type="number" step="0.01" name="delivery_default_fee" value="{{ old('delivery_default_fee', $values['delivery_default_fee']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('settings.free_threshold')" name="delivery_free_threshold"><input type="number" step="0.01" name="delivery_free_threshold" value="{{ old('delivery_free_threshold', $values['delivery_free_threshold']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('settings.cod_treasury')" name="delivery_cod_treasury_id">
                        <select name="delivery_cod_treasury_id" class="w-full rounded-md border-gray-300">
                            <option value="">{{ __('settings.cod_treasury_default') }}</option>
                            @foreach ($treasuries as $t)
                                <option value="{{ $t->id }}" @selected((string) old('delivery_cod_treasury_id', $values['delivery_cod_treasury_id']) === (string) $t->id)>{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field label="Opost Base URL" name="opost_base_url"><input type="url" name="opost_base_url" value="{{ old('opost_base_url', $values['opost_base_url']) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field label="Opost API Key" name="opost_api_key"><input type="password" name="opost_api_key" placeholder="{{ $values['opost_api_key'] ? __('settings.secret_set') : __('settings.secret_unset') }}" autocomplete="new-password" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field label="Opost Webhook Secret" name="opost_webhook_secret"><input type="password" name="opost_webhook_secret" placeholder="{{ $values['opost_webhook_secret'] ? __('settings.secret_set') : __('settings.secret_unset') }}" autocomplete="new-password" class="w-full rounded-md border-gray-300" /></x-admin.field>
                </div>

                {{-- SEO --}}
                <div x-show="tab === 'seo'" x-cloak class="space-y-4">
                    <x-admin.field :label="__('settings.meta_title')" name="seo_meta_title"><input type="text" name="seo_meta_title" value="{{ old('seo_meta_title', $values['seo_meta_title']) }}" maxlength="70" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('settings.meta_description')" name="seo_meta_description"><textarea name="seo_meta_description" rows="2" maxlength="180" class="w-full rounded-md border-gray-300">{{ old('seo_meta_description', $values['seo_meta_description']) }}</textarea></x-admin.field>
                </div>

                {{-- النظام --}}
                <div x-show="tab === 'system'" x-cloak class="space-y-4">
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="system_maintenance" value="0" /><input type="checkbox" name="system_maintenance" value="1" @checked(old('system_maintenance', $values['system_maintenance'])) class="rounded border-gray-300 text-rose-600" />{{ __('settings.maintenance') }}</label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <x-admin.field :label="__('settings.timezone')" name="system_timezone">
                            <select name="system_timezone" class="w-full rounded-md border-gray-300">
                                @foreach ($timezones as $tz)<option value="{{ $tz }}" @selected(old('system_timezone', $values['system_timezone'] ?: config('app.timezone')) === $tz)>{{ $tz }}</option>@endforeach
                            </select>
                        </x-admin.field>
                        <x-admin.field :label="__('settings.currency')" name="system_currency"><input type="text" name="system_currency" value="{{ old('system_currency', $values['system_currency'] ?: 'ILS') }}" maxlength="3" class="w-full rounded-md border-gray-300 uppercase" /></x-admin.field>
                        <x-admin.field :label="__('settings.language')" name="system_locale">
                            <select name="system_locale" class="w-full rounded-md border-gray-300">
                                <option value="ar" @selected(old('system_locale', $values['system_locale'] ?: 'ar') === 'ar')>{{ __('عربي') }}</option>
                                <option value="en" @selected(old('system_locale', $values['system_locale']) === 'en')>English</option>
                            </select>
                        </x-admin.field>
                    </div>
                </div>

                @can('settings.system.manage')
                    <div class="flex gap-2 pt-4 border-t">
                        <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ الإعدادات') }}</button>
                    </div>
                @endcan
            </form>
        </div>
    </div>
</x-app-layout>
