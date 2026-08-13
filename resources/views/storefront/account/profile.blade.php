<x-storefront.account-layout :title="__('account.settings')" active="profile">
    <h1 class="sf-section-title mb-4">{{ __('account.settings') }}</h1>

    @if ($errors->any())
        <div class="sf-alert sf-alert-error" role="alert">
            <x-storefront.icon name="close" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    {{-- الملف الشخصي --}}
    <form method="POST" action="{{ route('account.profile.update') }}" class="sf-card sf-card-pad max-w-xl mb-4">
        @csrf @method('PATCH')
        <h2 class="font-bold text-[color:var(--sf-text)] mb-4">{{ __('account.profile') }}</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="name" class="sf-label">{{ __('account.name') }}</label>
                <input id="name" name="name" value="{{ old('name', $customer->name) }}" required class="sf-input">
            </div>
            <div>
                <label for="phone" class="sf-label">{{ __('account.phone') }}</label>
                <input id="phone" name="phone" value="{{ old('phone', $customer->primary_phone) }}" required
                       inputmode="tel" dir="ltr" class="sf-input">
            </div>
        </div>

        <div class="mt-4">
            <label for="email" class="sf-label">{{ __('account.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', $customer->email) }}" required
                   dir="ltr" class="sf-input">
        </div>

        <div class="mt-4">
            <label for="birth_date" class="sf-label">{{ __('account.birth_date') }}</label>
            <input id="birth_date" name="birth_date" type="date"
                   value="{{ old('birth_date', optional($customer->birth_date)->format('Y-m-d')) }}" required class="sf-input">
        </div>

        <button type="submit" class="sf-btn-primary mt-5">{{ __('account.update') }}</button>
    </form>

    {{-- كلمة المرور --}}
    <form method="POST" action="{{ route('account.password.update') }}" class="sf-card sf-card-pad max-w-xl">
        @csrf @method('PATCH')
        <h2 class="font-bold text-[color:var(--sf-text)] mb-4">{{ __('account.change_password') }}</h2>

        <div>
            <label for="current_password" class="sf-label">{{ __('account.current_password') }}</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" class="sf-input">
        </div>
        <div class="mt-4">
            <label for="new_password" class="sf-label">{{ __('account.new_password') }}</label>
            <input id="new_password" name="password" type="password" autocomplete="new-password" class="sf-input">
        </div>
        <div class="mt-4">
            <label for="password_confirmation" class="sf-label">{{ __('account.password_confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="sf-input">
        </div>

        <button type="submit" class="sf-btn-outline mt-5">{{ __('account.change_password') }}</button>
    </form>
</x-storefront.account-layout>
