<x-storefront.layout :title="__('account.complete_profile')" :noindex="true">
    <div class="max-w-md mx-auto bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 mt-4">
        <h1 class="text-xl font-bold text-gray-900 mb-1">{{ __('account.complete_profile') }}</h1>
        <p class="text-sm text-gray-500 mb-6">{{ __('account.complete_profile_hint') }}</p>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('account.profile.complete.store') }}" class="space-y-4">
            @csrf
            <div>
                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.phone') }}</label>
                <input id="phone" name="phone" value="{{ old('phone', $customer->primary_phone) }}" required inputmode="tel"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label for="birth_date" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.birth_date') }}</label>
                <input id="birth_date" name="birth_date" type="date" value="{{ old('birth_date', optional($customer->birth_date)->format('Y-m-d')) }}" required
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label for="preferred_locale" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.preferred_language') }}</label>
                <select id="preferred_locale" name="preferred_locale" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    @foreach ($locales as $loc)
                        <option value="{{ $loc }}" @selected(old('preferred_locale', $customer->preferred_locale ?? app()->getLocale()) === $loc)>{{ strtoupper($loc) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <p class="block text-sm font-medium text-gray-700 mb-2">{{ __('account.communication_preferences') }}</p>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['whatsapp', 'email', 'sms', 'push'] as $channel)
                        <label class="flex items-center gap-2 text-sm text-gray-700 p-2 rounded-lg border border-gray-200">
                            <input type="checkbox" name="communication_preferences[{{ $channel }}]" value="1"
                                   class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                            {{ __('account.channel_'.$channel) }}
                        </label>
                    @endforeach
                </div>
            </div>
            <button type="submit" class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5">
                {{ __('account.save') }}
            </button>
        </form>
    </div>
</x-storefront.layout>
