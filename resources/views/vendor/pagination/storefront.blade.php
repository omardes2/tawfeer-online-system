{{--
    ترقيم صفحات المتجر — بنظام التصميم نفسه (`sf-page-link`) وبالعربية.
    الافتراضي كان يعرض «Previous/Next» بالإنجليزية وبتنسيق لا يشبه بقية المتجر.
--}}
@if ($paginator->hasPages())
    <div class="flex items-center justify-center gap-2 flex-wrap">
        {{-- السابق --}}
        @if ($paginator->onFirstPage())
            <span class="sf-page-link" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                <x-storefront.icon name="chevron-right" class="w-4 h-4 ltr:rotate-180" />
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="sf-page-link"
               aria-label="{{ __('pagination.previous') }}">
                <x-storefront.icon name="chevron-right" class="w-4 h-4 ltr:rotate-180" />
            </a>
        @endif

        {{-- أرقام الصفحات (تُختصر على الجوّال إلى الصفحة الحالية فقط) --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="sf-page-link hidden sm:inline-flex" aria-disabled="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="sf-page-link" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="sf-page-link hidden sm:inline-flex"
                           aria-label="{{ __('pagination.go_to_page', ['page' => $page]) }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- التالي --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="sf-page-link"
               aria-label="{{ __('pagination.next') }}">
                <x-storefront.icon name="chevron-left" class="w-4 h-4 ltr:rotate-180" />
            </a>
        @else
            <span class="sf-page-link" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                <x-storefront.icon name="chevron-left" class="w-4 h-4 ltr:rotate-180" />
            </span>
        @endif
    </div>

    <p class="mt-3 text-center text-xs text-[color:var(--sf-text-soft)]">
        {{ __('pagination.page_of', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}
    </p>
@endif
