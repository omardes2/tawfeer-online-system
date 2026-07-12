<x-storefront.account-layout :title="__('account.settings')" active="profile">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('account.settings') }}</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- الملف --}}
    <form method="POST" action="{{ route('account.profile.update') }}" class="bg-white rounded-xl border border-gray-200 p-5 space-y-4 max-w-xl mb-4">
        @csrf @method('PATCH')
        <h2 class="font-bold text-gray-900">{{ __('account.profile') }}</h2>
        <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.name') }}</label>
            <input id="name" name="name" value="{{ old('name', $customer->name) }}" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', $customer->email) }}" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.phone') }}</label>
            <input id="phone" name="phone" value="{{ old('phone', $customer->primary_phone) }}" required inputmode="tel" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="birth_date" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.birth_date') }}</label>
            <input id="birth_date" name="birth_date" type="date" value="{{ old('birth_date', optional($customer->birth_date)->format('Y-m-d')) }}" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <button type="submit" class="rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5">{{ __('account.update') }}</button>
    </form>

    {{-- كلمة المرور --}}
    <form method="POST" action="{{ route('account.password.update') }}" class="bg-white rounded-xl border border-gray-200 p-5 space-y-4 max-w-xl">
        @csrf @method('PATCH')
        <h2 class="font-bold text-gray-900">{{ __('account.change_password') }}</h2>
        <div>
            <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.current_password') }}</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.new_password') }}</label>
            <input id="new_password" name="password" type="password" autocomplete="new-password" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">{{ __('account.password_confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <button type="submit" class="rounded-lg bg-gray-800 hover:bg-gray-900 text-white text-sm font-medium px-5 py-2.5">{{ __('account.change_password') }}</button>
    </form>
</x-storefront.account-layout>
