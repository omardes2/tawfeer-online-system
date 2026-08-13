<x-storefront.account-layout :title="__('account.preferences')" active="preferences">
    <h1 class="sf-section-title mb-4">{{ __('account.preferences') }}</h1>

    @if ($errors->any())
        <div class="sf-alert sf-alert-error" role="alert">
            <x-storefront.icon name="close" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    @php $comms = $customer->communication_preferences ?? []; @endphp

    <form method="POST" action="{{ route('account.preferences.update') }}" class="sf-card sf-card-pad max-w-xl space-y-5">
        @csrf @method('PATCH')

        <div>
            <label for="preferred_locale" class="sf-label">{{ __('account.preferred_language') }}</label>
            <select id="preferred_locale" name="preferred_locale" class="sf-select">
                <option value="">{{ __('account.none') }}</option>
                @foreach ($locales as $loc)
                    <option value="{{ $loc }}" @selected($customer->preferred_locale === $loc)>{{ strtoupper($loc) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="preferred_branch_id" class="sf-label">{{ __('account.preferred_branch') }}</label>
            <select id="preferred_branch_id" name="preferred_branch_id" class="sf-select">
                <option value="">{{ __('account.none') }}</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) $customer->preferred_branch_id === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>

        <fieldset>
            <legend class="sf-label">{{ __('account.communication_preferences') }}</legend>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @foreach (['whatsapp', 'email', 'sms', 'push'] as $channel)
                    <label class="sf-check">
                        <input type="checkbox" name="communication_preferences[{{ $channel }}]" value="1"
                               @checked(! empty($comms[$channel]))>
                        {{ __('account.channel_'.$channel) }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <button type="submit" class="sf-btn-primary">{{ __('account.save') }}</button>
    </form>
</x-storefront.account-layout>
