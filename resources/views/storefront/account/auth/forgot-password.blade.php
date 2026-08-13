<x-storefront.layout :title="__('account.forgot_password')" :noindex="true">
    <x-storefront.auth-card :title="__('account.forgot_password')" :subtitle="__('account.forgot_password_hint')" icon="shield">

        <form method="POST" action="{{ route('account.password.email') }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="sf-label">{{ __('account.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       dir="ltr" class="sf-input">
            </div>
            <button type="submit" class="sf-btn-primary sf-btn-block sf-btn-lg">{{ __('account.send_reset_link') }}</button>
        </form>

        <x-slot:footer>
            <a href="{{ route('account.login') }}" class="font-semibold text-brand-600 hover:text-brand-700">{{ __('account.sign_in') }}</a>
        </x-slot:footer>
    </x-storefront.auth-card>
</x-storefront.layout>
