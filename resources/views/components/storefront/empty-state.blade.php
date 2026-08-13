@props(['title', 'description' => null, 'icon' => 'box', 'action' => null, 'actionLabel' => null])

{{-- حالة فارغة موحّدة: أيقونة + سبب + مخرج واضح بدل صفحة بيضاء. --}}
<div class="sf-card px-6 py-12 text-center">
    <span class="mx-auto mb-4 grid place-items-center w-16 h-16 rounded-full bg-brand-50 text-brand-600">
        <x-storefront.icon :name="$icon" class="w-8 h-8" />
    </span>
    <p class="font-bold text-[color:var(--sf-text)]">{{ $title }}</p>
    @if ($description)
        <p class="mt-1.5 text-sm text-[color:var(--sf-text-soft)] max-w-sm mx-auto">{{ $description }}</p>
    @endif
    @if ($action)
        <a href="{{ $action }}" class="sf-btn-primary mt-5">{{ $actionLabel ?? __('storefront.back_to_shop') }}</a>
    @endif
</div>
