@props(['product', 'summary', 'reviews', 'canReview' => false, 'existing' => null])

{{--
    التقييم وآراء الزبائن.

    المعروض هنا هو المعتمَد وحده؛ التقييم الجديد يُحفظ «بانتظار المراجعة» ولا
    يظهر لأحد قبل موافقة الإدارة. النموذج لا يُعرض إلا لمن استلم المنتج فعلًا —
    والخادم يفحص الشرط نفسه ثانيةً، فإخفاء النموذج تحسينُ عرض لا حاجز أمان.
--}}

<section id="reviews" class="mt-10 pt-8 border-t border-[color:var(--sf-border)] scroll-mt-24">
    <h2 class="text-lg sm:text-xl font-extrabold text-[color:var(--sf-text)]">
        {{ __('storefront.reviews_heading') }}
    </h2>

    @if (session('success'))
        <div class="sf-alert sf-alert-success mt-4" role="status">
            <x-storefront.icon name="check-circle" class="w-5 h-5 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @error('rating')
        <div class="sf-alert sf-alert-error mt-4" role="alert">
            <x-storefront.icon name="close" class="w-5 h-5 shrink-0" />
            <span>{{ $message }}</span>
        </div>
    @enderror

    @if ($summary['count'] === 0)
        <p class="mt-4 text-sm text-[color:var(--sf-text-soft)]">{{ __('storefront.reviews_empty') }}</p>
    @else
        {{-- الملخّص: المعدّل ثم توزيع النجوم --}}
        <div class="mt-5 grid grid-cols-1 sm:grid-cols-[auto_1fr] gap-5 sm:gap-8 items-center">
            <div class="text-center sm:text-start">
                <p class="text-4xl font-extrabold tabular-nums text-[color:var(--sf-text)]">
                    {{ number_format($summary['average'], 1) }}
                </p>
                <x-storefront.stars :value="$summary['average']" class="justify-center sm:justify-start mt-1" />
                <p class="mt-1 text-xs text-[color:var(--sf-text-soft)]">
                    {{ trans_choice('storefront.reviews_count', $summary['count'], ['count' => $summary['count']]) }}
                </p>
            </div>

            <div class="space-y-1.5">
                @foreach ($summary['breakdown'] as $star => $row)
                    <div class="flex items-center gap-2.5 text-xs">
                        <span class="w-8 shrink-0 tabular-nums text-[color:var(--sf-text-soft)]">{{ $star }} ★</span>
                        {{-- role=img: الشريط زخرفة، والنسبة تُقرأ من النصّ المجاور --}}
                        <span class="flex-1 h-2 rounded-full bg-[color:var(--sf-bg)] overflow-hidden">
                            <span class="block h-full bg-gold-400"
                                  style="width: {{ $row['percent'] }}%"></span>
                        </span>
                        <span class="w-9 shrink-0 text-end tabular-nums text-[color:var(--sf-text-soft)]">{{ $row['count'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- الآراء --}}
        <ul class="mt-6 space-y-4">
            @foreach ($reviews as $review)
                <li class="sf-card sf-card-pad">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0">
                            <p class="font-bold text-sm text-[color:var(--sf-text)]">
                                {{ $review->customer?->name ?: __('storefront.review_anonymous') }}
                            </p>
                            @if ($review->isVerifiedPurchase())
                                <span class="inline-flex items-center gap-1 mt-1 text-[11px] font-semibold text-[color:var(--sf-success)]">
                                    <x-storefront.icon name="check-circle" class="w-3.5 h-3.5" />
                                    {{ __('storefront.review_verified') }}
                                </span>
                            @endif
                        </div>
                        <div class="text-end shrink-0">
                            <x-storefront.stars :value="$review->rating" class="justify-end" />
                            <time class="block mt-1 text-[11px] text-[color:var(--sf-text-soft)]"
                                  datetime="{{ $review->created_at->toDateString() }}">
                                {{ $review->created_at->translatedFormat('j F Y') }}
                            </time>
                        </div>
                    </div>

                    @if (filled($review->title))
                        <p class="mt-3 font-semibold text-sm text-[color:var(--sf-text)]">{{ $review->title }}</p>
                    @endif
                    @if (filled($review->body))
                        <p class="mt-1 text-sm leading-relaxed text-[color:var(--sf-text-soft)] whitespace-pre-line">{{ $review->body }}</p>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($reviews->hasPages())
            <div class="mt-5">{{ $reviews->links() }}</div>
        @endif
    @endif

    {{-- كتابة رأي --}}
    @auth
        @if ($existing)
            <p class="mt-6 text-sm text-[color:var(--sf-text-soft)]">
                {{ $existing->status === \App\Modules\Catalog\Models\ProductReview::PENDING
                    ? __('storefront.review_pending_notice')
                    : __('storefront.review_already_sent') }}
            </p>
        @elseif ($canReview)
            <form method="POST" action="{{ route('storefront.product.reviews.store', $product->slug) }}"
                  class="sf-card sf-card-pad mt-6">
                @csrf
                <h3 class="font-bold text-[color:var(--sf-text)]">{{ __('storefront.review_write') }}</h3>

                {{--
                    النجوم أزرار راديو حقيقية: تعمل بلا جافاسكربت وبلوحة المفاتيح،
                    والتلوين بصريّ بحت عبر أقران Tailwind المسمّاة (`peer/sN`).

                    ترتيب DOM تنازليّ (5→1) و`flex-row-reverse` يعيده بصريًّا،
                    فيسبق كل مُدخَل كلَّ نجمة أدنى منه ويقدر `~` على تلوينها.
                    نجمة `k` تستجيب لكل `N ≥ k` — فتضيء ‎1..N‎ معًا.
                --}}
                @php
                    // مكتوبة كاملةً لا مركَّبة بالتشابك: ماسح Tailwind يقرأ نصّ
                    // الملف، و`peer-checked/s{$n}` لا يظهر له فلا يُولَّد صنفه.
                    $starGold = [
                        5 => 'peer-checked/s5:text-gold-400',
                        4 => 'peer-checked/s5:text-gold-400 peer-checked/s4:text-gold-400',
                        3 => 'peer-checked/s5:text-gold-400 peer-checked/s4:text-gold-400 peer-checked/s3:text-gold-400',
                        2 => 'peer-checked/s5:text-gold-400 peer-checked/s4:text-gold-400 peer-checked/s3:text-gold-400 peer-checked/s2:text-gold-400',
                        1 => 'peer-checked/s5:text-gold-400 peer-checked/s4:text-gold-400 peer-checked/s3:text-gold-400 peer-checked/s2:text-gold-400 peer-checked/s1:text-gold-400',
                    ];
                    $starPeer = [5 => 'peer/s5', 4 => 'peer/s4', 3 => 'peer/s3', 2 => 'peer/s2', 1 => 'peer/s1'];
                @endphp
                <fieldset class="mt-3">
                    <legend class="sf-label">{{ __('storefront.rating') }}</legend>
                    <div class="flex flex-row-reverse justify-end gap-1">
                        @foreach ([5, 4, 3, 2, 1] as $star)
                            <input type="radio" name="rating" id="r-{{ $star }}" value="{{ $star }}"
                                   @checked(old('rating') == $star) required
                                   class="sr-only {{ $starPeer[$star] }}">
                            <label for="r-{{ $star }}"
                                   class="cursor-pointer p-1 text-gray-300 transition-colors {{ $starGold[$star] }}"
                                   title="{{ trans_choice('storefront.reviews_star', $star, ['count' => $star]) }}">
                                <span class="sr-only">{{ trans_choice('storefront.reviews_star', $star, ['count' => $star]) }}</span>
                                <x-storefront.icon name="star" class="w-7 h-7" filled />
                            </label>
                        @endforeach
                    </div>
                    @error('rating')<p class="sf-error">{{ $message }}</p>@enderror
                </fieldset>

                <div class="mt-4">
                    <label for="review-title" class="sf-label">{{ __('storefront.review_title') }}</label>
                    <input id="review-title" name="title" value="{{ old('title') }}" maxlength="120" class="sf-input">
                    @error('title')<p class="sf-error">{{ $message }}</p>@enderror
                </div>

                <div class="mt-4">
                    <label for="review-body" class="sf-label">{{ __('storefront.review_body') }}</label>
                    <textarea id="review-body" name="body" rows="4" maxlength="1500" class="sf-textarea">{{ old('body') }}</textarea>
                    @error('body')<p class="sf-error">{{ $message }}</p>@enderror
                </div>

                <button type="submit" class="sf-btn-primary mt-4">{{ __('storefront.review_submit') }}</button>
                <p class="mt-2 text-xs text-[color:var(--sf-text-soft)]">{{ __('storefront.review_moderation_hint') }}</p>
            </form>
        @else
            <p class="mt-6 text-sm text-[color:var(--sf-text-soft)]">{{ __('storefront.review_requires_purchase') }}</p>
        @endif
    @else
        <p class="mt-6 text-sm text-[color:var(--sf-text-soft)]">
            <a href="{{ route('account.login') }}" class="sf-section-link">{{ __('storefront.login') }}</a>
            — {{ __('storefront.review_requires_purchase') }}
        </p>
    @endauth
</section>
