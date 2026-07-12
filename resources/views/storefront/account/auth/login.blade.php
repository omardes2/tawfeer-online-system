<x-storefront.layout :title="__('account.login')" :noindex="true">
    <div class="max-w-md mx-auto bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 mt-4">
        <h1 class="text-xl font-bold text-gray-900 mb-1">{{ __('account.login') }}</h1>
        <p class="text-sm text-gray-500 mb-6">{{ __('storefront.site_name') }}</p>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">
                {{ $errors->first() }}
            </div>
        @endif

        <x-storefront.social-buttons />

        <form method="POST" action="{{ route('account.login') }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.email_or_phone') }}</label>
                <input id="email" name="email" type="text" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label for="password" class="block text-sm font-medium text-gray-700">{{ __('account.password') }}</label>
                    <a href="{{ route('account.password.request') }}" class="text-xs text-emerald-600 hover:underline">{{ __('account.forgot_password') }}</a>
                </div>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                {{ __('account.remember_me') }}
            </label>
            <button type="submit" class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5">
                {{ __('account.sign_in') }}
            </button>
        </form>

        <p class="text-sm text-gray-500 mt-5 text-center">
            {{ __('account.no_account') }}
            <a href="{{ route('account.register') }}" class="text-emerald-600 hover:underline">{{ __('account.create_one') }}</a>
        </p>
    </div>
</x-storefront.layout>
