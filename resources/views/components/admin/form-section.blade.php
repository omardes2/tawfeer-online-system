{{--
    Form section — grouped fields inside a card, with a title + optional
    description. Put fields (e.g. <x-admin.field>) in the default slot.
    Use `cols=2` for a two-column grid on desktop (one column on mobile).
    Props: title, description(optional), cols(1|2).
--}}
@props(['title' => null, 'description' => null, 'cols' => 1])

<div class="admin-card p-5 md:p-6">
    @if ($title || $description)
        <div class="mb-5 pb-4 border-b border-gray-100">
            @if ($title)<h3 class="font-semibold text-gray-900">{{ $title }}</h3>@endif
            @if ($description)<p class="mt-1 text-sm text-gray-500">{{ $description }}</p>@endif
        </div>
    @endif
    <div class="grid gap-5 {{ (int) $cols === 2 ? 'md:grid-cols-2' : 'grid-cols-1' }}">
        {{ $slot }}
    </div>
</div>
