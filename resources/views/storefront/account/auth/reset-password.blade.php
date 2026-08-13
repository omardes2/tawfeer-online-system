<x-storefront.layout :title="__('account.reset_password')" :noindex="true">
    <x-storefront.auth-card :title="__('account.reset_password')" icon="shield">

        <form method="POST" action="{{ route('account.password.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label for="email" class="sf-label">{{ __('account.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required
                       autocomplete="email" dir="ltr" class="sf-input">
            </div>
            <div>
                <label for="password" class="sf-label">{{ __('account.new_password') }}</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" class="sf-input">
            </div>
            <div>
                <label for="password_confirmation" class="sf-label">{{ __('account.password_confirmation') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                       autocomplete="new-password" class="sf-input">
            </div>

            <button type="submit" class="sf-btn-primary sf-btn-block sf-btn-lg">{{ __('account.reset_password') }}</button>
        </form>
    </x-storefront.auth-card>
</x-storefront.layout>
