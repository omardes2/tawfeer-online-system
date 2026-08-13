@props(['title', 'subtitle' => null, 'icon' => 'user'])

{{--
    غلاف موحّد لصفحات الدخول/التسجيل/استرجاع كلمة المرور/إكمال الملف.
    كانت كل صفحة تكرّر البطاقة والتنبيهات بأصنافها، فيتفرّع الشكل عند أي تعديل.
--}}
<div class="max-w-md mx-auto">
    <div class="text-center mb-5">
        <span class="mx-auto mb-3 grid place-items-center w-14 h-14 rounded-2xl bg-brand-50 text-brand-600">
            <x-storefront.icon :name="$icon" class="w-7 h-7" />
        </span>
        <h1 class="text-xl font-extrabold text-[color:var(--sf-text)]">{{ $title }}</h1>
        @if ($subtitle)
            <p class="text-sm text-[color:var(--sf-text-soft)] mt-1">{{ $subtitle }}</p>
        @endif
    </div>

    <div class="sf-card p-5 sm:p-7">
        @if (session('status'))
            <div class="sf-alert sf-alert-success" role="status">
                <x-storefront.icon name="check-circle" />
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="sf-alert sf-alert-error" role="alert">
                <x-storefront.icon name="close" />
                <div class="min-w-0">
                    @if ($errors->count() === 1)
                        {{ $errors->first() }}
                    @else
                        <ul class="list-disc list-inside space-y-0.5">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif

        {{ $slot }}
    </div>

    @isset($footer)
        <p class="text-sm text-[color:var(--sf-text-soft)] mt-5 text-center">{{ $footer }}</p>
    @endisset
</div>
