<x-storefront.account-layout :title="__('account.notifications')" active="notifications">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="sf-section-title">{{ __('account.notifications') }}</h1>
        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('account.notifications.read_all') }}">
                @csrf
                <button type="submit" class="sf-section-link">{{ __('account.mark_all_read') }}</button>
            </form>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <x-storefront.empty-state icon="bell" :title="__('account.no_notifications')" />
    @else
        <div class="sf-card overflow-hidden divide-y divide-[color:var(--sf-border)]">
            @foreach ($notifications as $notification)
                @php $unread = is_null($notification->read_at); @endphp
                <div @class(['p-4 flex items-start gap-3', 'bg-brand-50/50' => $unread])>
                    {{-- نقطة «غير مقروء» بدل تلوين البطاقة وحده: تُقرأ حتى بلا تمييز لوني --}}
                    <span @class([
                        'mt-2 h-2 w-2 rounded-full shrink-0',
                        'bg-brand-600' => $unread,
                        'bg-transparent' => ! $unread,
                    ]) aria-hidden="true"></span>

                    <div class="min-w-0 flex-1">
                        <p @class(['text-sm text-[color:var(--sf-text)]', 'font-bold' => $unread, 'font-semibold' => ! $unread])>
                            {{ $notification->data['title'] ?? $notification->type }}
                        </p>
                        @if (! empty($notification->data['body']))
                            <p class="text-sm text-[color:var(--sf-text-soft)] mt-0.5">{{ $notification->data['body'] }}</p>
                        @endif
                        <p class="text-xs text-[color:var(--sf-text-soft)] mt-1">{{ $notification->created_at?->diffForHumans() }}</p>
                    </div>

                    @if ($unread)
                        <form method="POST" action="{{ route('account.notifications.read', $notification->id) }}" class="shrink-0">
                            @csrf
                            <button type="submit" class="sf-section-link">{{ __('account.mark_read') }}</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        <nav class="mt-6" aria-label="{{ __('storefront.pagination') }}">{{ $notifications->links('vendor.pagination.storefront') }}</nav>
    @endif
</x-storefront.account-layout>
