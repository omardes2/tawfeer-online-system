<x-storefront.account-layout :title="__('account.notifications')" active="notifications">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold text-gray-900">{{ __('account.notifications') }}</h1>
        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('account.notifications.read_all') }}">
                @csrf
                <button type="submit" class="text-sm text-emerald-600 hover:underline">{{ __('account.mark_all_read') }}</button>
            </form>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500">
            {{ __('account.no_notifications') }}
        </div>
    @else
        <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
            @foreach ($notifications as $notification)
                <div @class(['p-4 flex items-start justify-between gap-3', 'bg-emerald-50/40' => is_null($notification->read_at)])>
                    <div class="min-w-0">
                        <p class="font-medium text-gray-800">{{ $notification->data['title'] ?? $notification->type }}</p>
                        @if (! empty($notification->data['body']))
                            <p class="text-sm text-gray-500 mt-0.5">{{ $notification->data['body'] }}</p>
                        @endif
                        <p class="text-xs text-gray-400 mt-1">{{ $notification->created_at?->diffForHumans() }}</p>
                    </div>
                    @if (is_null($notification->read_at))
                        <form method="POST" action="{{ route('account.notifications.read', $notification->id) }}" class="shrink-0">
                            @csrf
                            <button type="submit" class="text-xs text-emerald-600 hover:underline whitespace-nowrap">{{ __('account.mark_read') }}</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $notifications->links() }}</div>
    @endif
</x-storefront.account-layout>
