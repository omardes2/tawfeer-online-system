<x-storefront.layout :title="__('account.forgot_password')" :noindex="true">
    <div class="max-w-md mx-auto bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 mt-4">
        <h1 class="text-xl font-bold text-gray-900 mb-1">{{ __('account.forgot_password') }}</h1>
        <p class="text-sm text-gray-500 mb-6">{{ __('account.forgot_password_hint') }}</p>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3">
                {{ session('status') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('account.password.email') }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <button type="submit" class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5">
                {{ __('account.send_reset_link') }}
            </button>
        </form>

        <p class="text-sm text-gray-500 mt-5 text-center">
            <a href="{{ route('account.login') }}" class="text-emerald-600 hover:underline">{{ __('account.sign_in') }}</a>
        </p>
    </div>
</x-storefront.layout>
