{{--
    تنبيه عابر يظهر بعد الإضافة للسلة (ومع فشلها).

    يستمع لحدث نافذة واحد يُطلقه مخزن السلة، فيعمل لأي زرّ إضافة في الموقع دون
    أن يعرف الزرّ بوجوده.

    الموضع يُحسب وقت الظهور لا في CSS: صفحة المنتج تحمل شريط شراء لاصقًا فوق
    شريط التنقّل، فلو ثُبِّت الإزاحة رقمًا لغطّى التنبيهُ الشريطَ في صفحة وطفا
    على فراغ في غيرها.
--}}
<div x-data="sfToast()" x-on:storefront:toast.window="show($event.detail)"
     x-show="open" x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-2"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-end="opacity-0 translate-y-2"
     :style="`bottom: calc(var(--sf-bottomnav) + ${offset}px + .75rem)`"
     class="fixed inset-x-0 z-50 px-4 pointer-events-none"
     role="status" aria-live="polite">
    <div class="mx-auto max-w-md flex items-center gap-3 px-4 py-3 rounded-xl shadow-[0_8px_24px_-8px_rgba(34,34,34,.35)] pointer-events-auto"
         :class="kind === 'error' ? 'bg-[color:var(--sf-danger)] text-white' : 'bg-[color:var(--sf-text)] text-white'">
        <span class="grid place-items-center w-6 h-6 shrink-0">
            <template x-if="kind !== 'error'">
                <x-storefront.icon name="check-circle" class="w-6 h-6" />
            </template>
            <template x-if="kind === 'error'">
                <x-storefront.icon name="close" class="w-6 h-6" />
            </template>
        </span>

        <span class="flex-1 min-w-0 text-sm font-semibold" x-text="message"></span>

        <a x-show="kind !== 'error'" href="{{ route('storefront.cart') }}"
           class="shrink-0 text-sm font-bold underline underline-offset-4 hover:opacity-80 transition-opacity">
            {{ __('storefront.view_cart') }}
        </a>
    </div>
</div>

<script>
    function sfToast() {
        return {
            open: false,
            message: '',
            kind: 'success',
            offset: 0,
            timer: null,

            show(detail) {
                this.message = detail?.message || '{{ __('storefront.added_to_cart') }}';
                this.kind = detail?.kind || 'success';
                // ارتفاع شريط الشراء اللاصق إن وُجد في هذه الصفحة (صفر في غيرها).
                const bar = document.querySelector('[data-buybar]');
                this.offset = bar && getComputedStyle(bar).display !== 'none'
                    ? Math.round(bar.getBoundingClientRect().height)
                    : 0;

                this.open = true;
                clearTimeout(this.timer);
                this.timer = setTimeout(() => { this.open = false; }, 3000);
            },
        };
    }
</script>
