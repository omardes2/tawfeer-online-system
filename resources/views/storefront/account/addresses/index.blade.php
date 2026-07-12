<x-storefront.account-layout :title="__('account.addresses')" active="addresses">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('account.addresses') }}</h1>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- قائمة العناوين --}}
        <div class="space-y-3">
            @forelse ($addresses as $address)
                <div class="bg-white rounded-xl border border-gray-200 p-4" x-data="{ editing: false }">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-800">{{ $address->label ?: __('account.address_line') }}</span>
                                @if ($address->is_default)
                                    <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">{{ __('account.default') }}</span>
                                @endif
                            </div>
                            @if ($address->recipient_name)<p class="text-sm text-gray-600 mt-1">{{ $address->recipient_name }} — {{ $address->phone }}</p>@endif
                            <p class="text-sm text-gray-500 mt-1">{{ $address->address_line }}</p>
                        </div>
                        <button type="button" @click="editing = !editing" class="text-gray-400 hover:text-emerald-600 text-sm shrink-0">{{ __('account.edit_address') }}</button>
                    </div>

                    <div class="flex items-center gap-3 mt-3">
                        @unless ($address->is_default)
                            <form method="POST" action="{{ route('account.addresses.default', $address) }}">
                                @csrf
                                <button type="submit" class="text-xs text-emerald-600 hover:underline">{{ __('account.set_default') }}</button>
                            </form>
                        @endunless
                        <form method="POST" action="{{ route('account.addresses.destroy', $address) }}"
                              onsubmit="return confirm('{{ __('account.delete') }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-rose-600 hover:underline">{{ __('account.delete') }}</button>
                        </form>
                    </div>

                    {{-- تعديل --}}
                    <form x-show="editing" x-cloak method="POST" action="{{ route('account.addresses.update', $address) }}" class="mt-3 space-y-2 border-t border-gray-100 pt-3">
                        @csrf @method('PATCH')
                        <input name="label" value="{{ $address->label }}" placeholder="{{ __('account.label') }}" class="w-full rounded-lg border-gray-300 text-sm">
                        <input name="recipient_name" value="{{ $address->recipient_name }}" placeholder="{{ __('account.recipient_name') }}" class="w-full rounded-lg border-gray-300 text-sm">
                        <input name="phone" value="{{ $address->phone }}" placeholder="{{ __('account.phone') }}" class="w-full rounded-lg border-gray-300 text-sm">
                        <textarea name="address_line" rows="2" required class="w-full rounded-lg border-gray-300 text-sm">{{ $address->address_line }}</textarea>
                        <button type="submit" class="rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm px-4 py-2">{{ __('account.save') }}</button>
                    </form>
                </div>
            @empty
                <div class="bg-white rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-500">
                    {{ __('account.no_addresses') }}
                </div>
            @endforelse
        </div>

        {{-- إضافة عنوان --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 h-fit">
            <h2 class="font-bold text-gray-900 mb-3">{{ __('account.add_address') }}</h2>
            <form method="POST" action="{{ route('account.addresses.store') }}" class="space-y-3">
                @csrf
                <input name="label" value="{{ old('label') }}" placeholder="{{ __('account.label') }}" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <input name="recipient_name" value="{{ old('recipient_name') }}" placeholder="{{ __('account.recipient_name') }}" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <input name="phone" value="{{ old('phone') }}" placeholder="{{ __('account.phone') }}" inputmode="tel" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <textarea name="address_line" rows="3" required placeholder="{{ __('account.address_line') }}" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('address_line') }}</textarea>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="is_default" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    {{ __('account.set_default') }}
                </label>
                <button type="submit" class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium py-2.5">{{ __('account.save') }}</button>
            </form>
        </div>
    </div>
</x-storefront.account-layout>
