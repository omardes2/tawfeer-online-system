<x-storefront.layout :title="__('account.login')" :noindex="true">
    <x-storefront.auth-card :title="__('account.login')" :subtitle="__('storefront.site_name')" icon="user">

        <x-storefront.social-buttons />

        <form method="POST" action="{{ route('account.login') }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="sf-label">{{ __('account.email_or_phone') }}</label>
                <input id="email" name="email" type="text" value="{{ old('email') }}" required autofocus
                       autocomplete="username" dir="ltr" class="sf-input">
            </div>

            <div>
                <div class="flex items-center justify-between gap-2 mb-1.5">
                    <label for="password" class="sf-label mb-0">{{ __('account.password') }}</label>
                    {{-- الحشو يرفع مساحة اللمس إلى 40px والهامش السالب يُبقي الموضع البصري --}}
                    <a href="{{ route('account.password.request') }}"
                       class="inline-flex items-center min-h-10 -my-2.5 px-2 -mx-2 text-xs font-semibold text-brand-600 hover:text-brand-700">
                        {{ __('account.forgot_password') }}
                    </a>
                </div>
                <input id="password" name="password" type="password" required autocomplete="current-password" class="sf-input">
            </div>

            <label class="sf-check sf-check-bare">
                <input type="checkbox" name="remember" value="1">
                {{ __('account.remember_me') }}
            </label>

            <button type="submit" class="sf-btn-primary sf-btn-block sf-btn-lg">{{ __('account.sign_in') }}</button>
        </form>

        <x-slot:footer>
            {{ __('account.no_account') }}
            <a href="{{ route('account.register') }}" class="font-semibold text-brand-600 hover:text-brand-700">{{ __('account.create_one') }}</a>
        </x-slot:footer>
    </x-storefront.auth-card>
</x-storefront.layout>
