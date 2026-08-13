<x-storefront.layout :title="__('account.complete_profile')" :noindex="true">
    <x-storefront.auth-card :title="__('account.complete_profile')" :subtitle="__('account.complete_profile_hint')" icon="user">

        <form method="POST" action="{{ route('account.profile.complete.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="phone" class="sf-label">{{ __('account.phone') }}</label>
                <input id="phone" name="phone" value="{{ old('phone', $customer->primary_phone) }}" required
                       inputmode="tel" dir="ltr" class="sf-input">
            </div>

            <div>
                <label for="birth_date" class="sf-label">{{ __('account.birth_date') }}</label>
                <input id="birth_date" name="birth_date" type="date"
                       value="{{ old('birth_date', optional($customer->birth_date)->format('Y-m-d')) }}" required class="sf-input">
            </div>

            <div>
                <label for="preferred_locale" class="sf-label">{{ __('account.preferred_language') }}</label>
                <select id="preferred_locale" name="preferred_locale" required class="sf-select">
                    @foreach ($locales as $loc)
                        <option value="{{ $loc }}" @selected(old('preferred_locale', $customer->preferred_locale ?? app()->getLocale()) === $loc)>{{ strtoupper($loc) }}</option>
                    @endforeach
                </select>
            </div>

            <fieldset>
                <legend class="sf-label">{{ __('account.communication_preferences') }}</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach (['whatsapp', 'email', 'sms', 'push'] as $channel)
                        <label class="sf-check">
                            <input type="checkbox" name="communication_preferences[{{ $channel }}]" value="1">
                            {{ __('account.channel_'.$channel) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <button type="submit" class="sf-btn-primary sf-btn-block sf-btn-lg">{{ __('account.save') }}</button>
        </form>
    </x-storefront.auth-card>
</x-storefront.layout>
