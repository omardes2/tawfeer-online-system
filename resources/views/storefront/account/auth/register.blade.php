<x-storefront.layout :title="__('account.register')" :noindex="true">
    <div class="max-w-md mx-auto bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 mt-4">
        <h1 class="text-xl font-bold text-gray-900 mb-1">{{ __('account.register') }}</h1>
        <p class="text-sm text-gray-500 mb-6">{{ __('storefront.site_name') }}</p>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('account.register') }}" class="space-y-4">
            @csrf
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.name') }}</label>
                <input id="name" name="name" value="{{ old('name') }}" required autofocus
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.phone') }}</label>
                <input id="phone" name="phone" value="{{ old('phone') }}" required inputmode="tel"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label for="birth_date" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.birth_date') }}</label>
                <input id="birth_date" name="birth_date" type="date" value="{{ old('birth_date') }}" required
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                <p class="text-xs text-gray-400 mt-1">{{ __('account.birth_date_hint') }}</p>
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.password') }}</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.password_confirmation') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <button type="submit" class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5">
                {{ __('account.register') }}
            </button>
        </form>

        <p class="text-sm text-gray-500 mt-5 text-center">
            {{ __('account.have_account') }}
            <a href="{{ route('account.login') }}" class="text-emerald-600 hover:underline">{{ __('account.sign_in') }}</a>
        </p>
    </div>
</x-storefront.layout>
