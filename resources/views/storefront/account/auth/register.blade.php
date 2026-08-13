<x-storefront.layout :title="__('account.register')" :noindex="true">
    <x-storefront.auth-card :title="__('account.register')" :subtitle="__('storefront.site_name')" icon="user">

        <x-storefront.social-buttons />

        <form method="POST" action="{{ route('account.register') }}" class="space-y-4">
            @csrf
            <div>
                <label for="name" class="sf-label">{{ __('account.name') }}</label>
                <input id="name" name="name" value="{{ old('name') }}" required autofocus class="sf-input">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="email" class="sf-label">{{ __('account.email') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required
                           autocomplete="email" dir="ltr" class="sf-input">
                </div>
                <div>
                    <label for="phone" class="sf-label">{{ __('account.phone') }}</label>
                    <input id="phone" name="phone" value="{{ old('phone') }}" required inputmode="tel" dir="ltr" class="sf-input">
                </div>
            </div>

            <div>
                <label for="birth_date" class="sf-label">{{ __('account.birth_date') }}</label>
                <input id="birth_date" name="birth_date" type="date" value="{{ old('birth_date') }}" required class="sf-input">
                <p class="sf-hint">{{ __('account.birth_date_hint') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="password" class="sf-label">{{ __('account.password') }}</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password" class="sf-input">
                </div>
                <div>
                    <label for="password_confirmation" class="sf-label">{{ __('account.password_confirmation') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required
                           autocomplete="new-password" class="sf-input">
                </div>
            </div>

            <label class="sf-check sf-check-bare items-start">
                <input type="checkbox" name="terms" value="1" @checked(old('terms')) required class="mt-0.5">
                <span>{{ __('account.terms_accept') }}</span>
            </label>

            <button type="submit" class="sf-btn-primary sf-btn-block sf-btn-lg">{{ __('account.register') }}</button>
        </form>

        <x-slot:footer>
            {{ __('account.have_account') }}
            <a href="{{ route('account.login') }}" class="font-semibold text-brand-600 hover:text-brand-700">{{ __('account.sign_in') }}</a>
        </x-slot:footer>
    </x-storefront.auth-card>
</x-storefront.layout>
