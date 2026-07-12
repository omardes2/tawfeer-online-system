<x-storefront.account-layout :title="__('account.preferences')" active="preferences">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('account.preferences') }}</h1>

    @php $comms = $customer->communication_preferences ?? []; @endphp

    <form method="POST" action="{{ route('account.preferences.update') }}" class="bg-white rounded-xl border border-gray-200 p-5 space-y-5 max-w-xl">
        @csrf @method('PATCH')

        <div>
            <label for="preferred_locale" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.preferred_language') }}</label>
            <select id="preferred_locale" name="preferred_locale" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('account.none') }}</option>
                @foreach ($locales as $loc)
                    <option value="{{ $loc }}" @selected($customer->preferred_locale === $loc)>{{ strtoupper($loc) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="preferred_branch_id" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.preferred_branch') }}</label>
            <select id="preferred_branch_id" name="preferred_branch_id" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('account.none') }}</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) $customer->preferred_branch_id === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <p class="block text-sm font-medium text-gray-700 mb-2">{{ __('account.communication_preferences') }}</p>
            <div class="grid grid-cols-2 gap-2">
                @foreach (['whatsapp', 'email', 'sms', 'push'] as $channel)
                    <label class="flex items-center gap-2 text-sm text-gray-700 p-2 rounded-lg border border-gray-200">
                        <input type="checkbox" name="communication_preferences[{{ $channel }}]" value="1"
                               @checked(! empty($comms[$channel]))
                               class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        {{ __('account.channel_'.$channel) }}
                    </label>
                @endforeach
            </div>
        </div>

        <button type="submit" class="rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5">{{ __('account.save') }}</button>
    </form>
</x-storefront.account-layout>
