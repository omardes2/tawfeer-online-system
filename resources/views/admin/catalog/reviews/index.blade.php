<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('app.modules.catalog') }} — {{ __('تقييمات الزبائن') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('تقييمات الزبائن')">
                @can('catalog.reviews.update')
                    <a href="{{ route('admin.reviews.import') }}" class="btn-secondary btn-sm">{{ __('استيراد من ملف') }}</a>
                @endcan
            </x-admin.header>

            {{-- التبويبات: المعلّق أوّلًا لأنه ما ينتظر قرارًا --}}
            @php
                $tabs = [
                    \App\Modules\Catalog\Models\ProductReview::PENDING => __('بانتظار المراجعة'),
                    \App\Modules\Catalog\Models\ProductReview::APPROVED => __('معتمَد'),
                    \App\Modules\Catalog\Models\ProductReview::REJECTED => __('مرفوض'),
                ];
            @endphp
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach ($tabs as $key => $label)
                    <a href="{{ route('admin.reviews.index', ['status' => $key]) }}"
                       class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md text-sm border {{ $status === $key ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50' }}">
                        {{ $label }}
                        @if ($key === \App\Modules\Catalog\Models\ProductReview::PENDING && $pendingCount > 0)
                            <span class="inline-flex min-w-5 h-5 px-1 items-center justify-center rounded-full text-xs {{ $status === $key ? 'bg-white text-emerald-700' : 'bg-rose-100 text-rose-700' }}">{{ $pendingCount }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('المنتج') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الزبون') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('التقييم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الرأي') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('التاريخ') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراءات') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($reviews as $review)
                            <tr class="align-top">
                                <td class="py-3 px-3 text-gray-800">
                                    <a href="{{ route('storefront.product', $review->product->slug) }}" target="_blank" rel="noopener"
                                       class="text-emerald-600 hover:underline">{{ $review->product->name }}</a>
                                </td>
                                <td class="py-3 px-3 text-gray-600">
                                    {{ $review->customer?->name ?? '—' }}
                                    @if ($review->isVerifiedPurchase())
                                        <span class="block text-xs text-emerald-600">{{ __('شراء موثّق') }}</span>
                                    @endif
                                </td>
                                <td class="py-3 px-3 tabular-nums text-gray-800">{{ $review->rating }} / 5</td>
                                <td class="py-3 px-3 text-gray-600 max-w-md">
                                    @if (filled($review->title))<span class="block font-medium text-gray-800">{{ $review->title }}</span>@endif
                                    @if (filled($review->body))<span class="block whitespace-pre-line">{{ $review->body }}</span>@endif
                                    @if (filled($review->moderation_note))
                                        <span class="block mt-1 text-xs text-rose-600">{{ __('سبب الرفض') }}: {{ $review->moderation_note }}</span>
                                    @endif
                                    @if ($review->moderator)
                                        <span class="block mt-1 text-xs text-gray-400">{{ __('راجعه') }}: {{ $review->moderator->name }}</span>
                                    @endif
                                </td>
                                <td class="py-3 px-3 text-gray-500 whitespace-nowrap">{{ $review->created_at->format('Y-m-d') }}</td>
                                <td class="py-3 px-3"><div class="flex flex-wrap gap-2">
                                    @can('update', $review)
                                        @if ($review->status !== \App\Modules\Catalog\Models\ProductReview::APPROVED)
                                            <form method="POST" action="{{ route('admin.reviews.approve', $review) }}">@csrf @method('PATCH')
                                                <button class="text-emerald-600 hover:underline">{{ __('اعتماد') }}</button>
                                            </form>
                                        @endif
                                        @if ($review->status !== \App\Modules\Catalog\Models\ProductReview::REJECTED)
                                            <form method="POST" action="{{ route('admin.reviews.reject', $review) }}">@csrf @method('PATCH')
                                                <button class="text-amber-600 hover:underline">{{ __('رفض') }}</button>
                                            </form>
                                        @endif
                                    @endcan
                                    @can('delete', $review)
                                        <form method="POST" action="{{ route('admin.reviews.destroy', $review) }}" onsubmit="return confirm('{{ __('تأكيد الحذف؟') }}')">@csrf @method('DELETE')
                                            <button class="text-rose-600 hover:underline">{{ __('حذف') }}</button>
                                        </form>
                                    @endcan
                                </div></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا توجد تقييمات في هذه الحالة.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $reviews->links() }}</div>
        </div>
    </div>
</x-app-layout>
