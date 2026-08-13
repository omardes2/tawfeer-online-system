<x-storefront.layout :title="__('storefront.categories')" :page-event="['name' => 'CategoryListViewed', 'payload' => []]">

    <x-storefront.page-header :title="__('storefront.browse_categories')"
        :subtitle="trans_choice('storefront.categories_count', $categories->count(), ['count' => $categories->count()])" />

    @if ($categories->isEmpty())
        <x-storefront.empty-state
            icon="grid"
            :title="__('storefront.no_categories')"
            :description="__('storefront.no_categories_hint')"
            :action="route('storefront.shop')"
            :action-label="__('storefront.back_to_shop')" />
    @else
        {{-- عمودان على الجوّال ثم يتّسع مع الشاشة. البطاقة نفسها المستخدمة في الرئيسية. --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6 gap-3 sm:gap-4">
            @foreach ($categories as $category)
                <x-storefront.category-card :category="$category" wide />
            @endforeach
        </div>
    @endif
</x-storefront.layout>
