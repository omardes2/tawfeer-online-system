<x-storefront.account-layout :title="__('account.linked_providers')" active="profile">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('account.linked_providers') }}</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 max-w-xl">
        @foreach ($providers as $provider)
            @php $isLinked = in_array($provider, $linked, true); @endphp
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-full {{ $provider === 'facebook' ? 'bg-[#1877F2] text-white' : 'bg-gray-100 text-gray-700' }} font-bold">
                        {{ strtoupper(substr($provider, 0, 1)) }}
                    </span>
                    <div>
                        <p class="font-medium text-gray-800 capitalize">{{ $provider }}</p>
                        <p class="text-xs {{ $isLinked ? 'text-emerald-600' : 'text-gray-400' }}">
                            {{ $isLinked ? __('account.connected') : __('account.not_connected') }}
                        </p>
                    </div>
                </div>

                @if ($isLinked)
                    <form method="POST" action="{{ route('account.providers.unlink', $provider) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-sm text-rose-600 hover:underline">{{ __('account.unlink') }}</button>
                    </form>
                @else
                    <a href="{{ route('account.providers.link', $provider) }}" class="text-sm text-emerald-600 hover:underline">{{ __('account.link') }}</a>
                @endif
            </div>
        @endforeach
    </div>
</x-storefront.account-layout>
