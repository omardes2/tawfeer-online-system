<x-storefront.layout :title="__('storefront.orders_disabled_title')" :noindex="true">

    {{--
        الطلب أونلاين متوقّف (`store.online_orders_enabled` = false).
        تُعرَض بحالة 503 لا 404: الصفحة موجودة ومتوقّفة مؤقتًا، وهذا ما يفهمه
        محرّك البحث فلا يشطب الرابط من فهرسه.
    --}}
    <div class="sf-card px-6 py-12 text-center max-w-lg mx-auto">
        <span class="mx-auto mb-5 grid place-items-center w-20 h-20 rounded-full bg-brand-50 text-brand-600">
            <x-storefront.icon name="cart" class="w-10 h-10" />
        </span>

        <h1 class="text-xl font-extrabold text-[color:var(--sf-text)]">{{ __('storefront.orders_disabled_title') }}</h1>
        <p class="mt-2 text-sm text-[color:var(--sf-text-soft)]">{{ __('storefront.orders_disabled_body') }}</p>

        {{-- رقم الدعم اختياري: يُضبط من لوحة الإعدادات، ويختفي القسم كلّه إن كان فارغًا --}}
        @php
            $phone = \App\Modules\Foundation\Services\Settings::get('store.support_phone');
            $digits = $phone ? preg_replace('/\D+/', '', (string) $phone) : null;
        @endphp

        @if ($digits)
            <p class="mt-5 text-sm font-semibold text-[color:var(--sf-text)]">{{ __('storefront.orders_disabled_contact') }}</p>
            <div class="mt-3 flex flex-col sm:flex-row gap-2 justify-center">
                <a href="https://wa.me/{{ $digits }}" class="sf-btn-primary">
                    <x-storefront.icon name="phone" class="w-5 h-5" />
                    {{ __('storefront.contact_whatsapp') }}
                </a>
                <a href="tel:+{{ $digits }}" class="sf-btn-outline" dir="ltr">{{ $phone }}</a>
            </div>
        @endif

        <div class="mt-6 flex flex-col sm:flex-row gap-2 justify-center">
            <a href="{{ route('storefront.shop') }}" class="sf-btn-outline">{{ __('storefront.continue_shopping') }}</a>
            <a href="{{ route('storefront.cart') }}" class="sf-btn-ghost">{{ __('storefront.cart') }}</a>
        </div>
    </div>
</x-storefront.layout>
