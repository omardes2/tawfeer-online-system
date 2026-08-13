<x-storefront.account-layout :title="__('account.addresses')" active="addresses">
    <h1 class="sf-section-title mb-4">{{ __('account.addresses') }}</h1>

    @if ($errors->any())
        <div class="sf-alert sf-alert-error" role="alert">
            <x-storefront.icon name="close" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    {{-- نموذج الإضافة أولًا على الجوّال (order-first) كي لا يُدفن أسفل قائمة طويلة --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- إضافة عنوان --}}
        <section class="sf-card sf-card-pad h-fit order-first lg:order-last">
            <h2 class="font-bold text-[color:var(--sf-text)] mb-4">{{ __('account.add_address') }}</h2>
            <form method="POST" action="{{ route('account.addresses.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label for="new-label" class="sf-label">{{ __('account.label') }}</label>
                    <input id="new-label" name="label" value="{{ old('label') }}" class="sf-input">
                </div>
                <div>
                    <label for="new-recipient" class="sf-label">{{ __('account.recipient_name') }}</label>
                    <input id="new-recipient" name="recipient_name" value="{{ old('recipient_name') }}" class="sf-input">
                </div>
                <div>
                    <label for="new-phone" class="sf-label">{{ __('account.phone') }}</label>
                    <input id="new-phone" name="phone" value="{{ old('phone') }}" inputmode="tel" dir="ltr" class="sf-input">
                </div>
                <div>
                    <label for="new-line" class="sf-label">{{ __('account.address_line') }}</label>
                    <textarea id="new-line" name="address_line" rows="3" required class="sf-textarea">{{ old('address_line') }}</textarea>
                </div>

                <label class="sf-check">
                    <input type="checkbox" name="is_default" value="1">
                    {{ __('account.set_default') }}
                </label>

                <button type="submit" class="sf-btn-primary sf-btn-block">{{ __('account.save') }}</button>
            </form>
        </section>

        {{-- قائمة العناوين --}}
        <div class="space-y-3 min-w-0">
            @forelse ($addresses as $address)
                <section class="sf-card sf-card-pad" x-data="{ editing: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-[color:var(--sf-text)]">{{ $address->label ?: __('account.address_line') }}</span>
                                @if ($address->is_default)
                                    <span class="sf-badge sf-badge-soft">{{ __('account.default') }}</span>
                                @endif
                            </div>
                            @if ($address->recipient_name)
                                <p class="text-sm text-[color:var(--sf-text)] mt-1.5">
                                    {{ $address->recipient_name }}
                                    @if ($address->phone)<span dir="ltr" class="text-[color:var(--sf-text-soft)]">— {{ $address->phone }}</span>@endif
                                </p>
                            @endif
                            <p class="text-sm text-[color:var(--sf-text-soft)] mt-1">{{ $address->address_line }}</p>
                        </div>

                        <button type="button" @click="editing = !editing"
                                class="sf-section-link shrink-0"
                                :aria-expanded="editing ? 'true' : 'false'">
                            {{ __('account.edit_address') }}
                            <x-storefront.icon name="chevron-down" class="w-4 h-4 transition-transform"
                                               ::class="editing && 'rotate-180'" />
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-[color:var(--sf-border)]">
                        @unless ($address->is_default)
                            <form method="POST" action="{{ route('account.addresses.default', $address) }}">
                                @csrf
                                <button type="submit" class="sf-section-link">{{ __('account.set_default') }}</button>
                            </form>
                        @endunless
                        <form method="POST" action="{{ route('account.addresses.destroy', $address) }}"
                              onsubmit="return confirm('{{ __('account.delete') }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="sf-section-link !text-[color:var(--sf-danger)]">{{ __('account.delete') }}</button>
                        </form>
                    </div>

                    {{-- تعديل --}}
                    <form x-show="editing" x-cloak x-transition method="POST"
                          action="{{ route('account.addresses.update', $address) }}"
                          class="mt-3 pt-3 border-t border-[color:var(--sf-border)] space-y-3">
                        @csrf @method('PATCH')
                        <div>
                            <label for="label-{{ $address->id }}" class="sf-label">{{ __('account.label') }}</label>
                            <input id="label-{{ $address->id }}" name="label" value="{{ $address->label }}" class="sf-input">
                        </div>
                        <div>
                            <label for="recipient-{{ $address->id }}" class="sf-label">{{ __('account.recipient_name') }}</label>
                            <input id="recipient-{{ $address->id }}" name="recipient_name" value="{{ $address->recipient_name }}" class="sf-input">
                        </div>
                        <div>
                            <label for="phone-{{ $address->id }}" class="sf-label">{{ __('account.phone') }}</label>
                            <input id="phone-{{ $address->id }}" name="phone" value="{{ $address->phone }}" inputmode="tel" dir="ltr" class="sf-input">
                        </div>
                        <div>
                            <label for="line-{{ $address->id }}" class="sf-label">{{ __('account.address_line') }}</label>
                            <textarea id="line-{{ $address->id }}" name="address_line" rows="2" required class="sf-textarea">{{ $address->address_line }}</textarea>
                        </div>
                        <button type="submit" class="sf-btn-primary">{{ __('account.save') }}</button>
                    </form>
                </section>
            @empty
                <div class="sf-card px-6 py-10 text-center">
                    <span class="mx-auto mb-3 grid place-items-center w-14 h-14 rounded-full bg-brand-50 text-brand-600">
                        <x-storefront.icon name="map-pin" class="w-7 h-7" />
                    </span>
                    <p class="text-sm text-[color:var(--sf-text-soft)]">{{ __('account.no_addresses') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</x-storefront.account-layout>
