<x-storefront.account-layout :title="__('account.linked_providers')" active="providers">
    <h1 class="sf-section-title mb-4">{{ __('account.linked_providers') }}</h1>

    @if ($errors->any())
        <div class="sf-alert sf-alert-error" role="alert">
            <x-storefront.icon name="close" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <div class="sf-card overflow-hidden divide-y divide-[color:var(--sf-border)] max-w-xl">
        @foreach ($providers as $provider)
            @php $isLinked = in_array($provider, $linked, true); @endphp
            <div class="flex items-center justify-between gap-3 p-4">
                <div class="flex items-center gap-3 min-w-0">
                    <span @class([
                        'grid place-items-center w-10 h-10 rounded-full font-bold shrink-0',
                        'bg-[#1877F2] text-white' => $provider === 'facebook',
                        'bg-[color:var(--sf-bg)] text-[color:var(--sf-text)]' => $provider !== 'facebook',
                    ])>{{ strtoupper(substr($provider, 0, 1)) }}</span>

                    <div class="min-w-0">
                        <p class="font-semibold text-[color:var(--sf-text)] capitalize">{{ $provider }}</p>
                        <p @class([
                            'text-xs mt-0.5',
                            'text-[color:var(--sf-success)]' => $isLinked,
                            'text-[color:var(--sf-text-soft)]' => ! $isLinked,
                        ])>{{ $isLinked ? __('account.connected') : __('account.not_connected') }}</p>
                    </div>
                </div>

                @if ($isLinked)
                    <form method="POST" action="{{ route('account.providers.unlink', $provider) }}" class="shrink-0">
                        @csrf @method('DELETE')
                        <button type="submit" class="sf-btn-ghost text-[color:var(--sf-danger)]">{{ __('account.unlink') }}</button>
                    </form>
                @else
                    <a href="{{ route('account.providers.link', $provider) }}" class="sf-btn-outline shrink-0">
                        <x-storefront.icon name="link" class="w-4 h-4" />
                        {{ __('account.link') }}
                    </a>
                @endif
            </div>
        @endforeach
    </div>
</x-storefront.account-layout>
