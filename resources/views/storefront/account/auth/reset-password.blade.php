<x-storefront.layout :title="__('account.reset_password')" :noindex="true">
    <div class="max-w-md mx-auto bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 mt-4">
        <h1 class="text-xl font-bold text-gray-900 mb-6">{{ __('account.reset_password') }}</h1>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('account.password.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.new_password') }}</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.password_confirmation') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <button type="submit" class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5">
                {{ __('account.reset_password') }}
            </button>
        </form>
    </div>
</x-storefront.layout>
