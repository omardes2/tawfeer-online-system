@props(['product' => null])

{{--
    مساعد محتوى المنتجات بالذكاء الاصطناعي (Phase 6 / ADR-044).
    **المحتوى المُولَّد اقتراح دائمًا** — لا يُنشر ولا يُكتب تلقائيًا؛ الموظّف يراجع
    ثم يضغط «تطبيق» لنقله إلى الحقل. مجرّد خلف مزوّد (Null افتراضيًا). RTL + عربي/إنجليزي.
--}}
@can('ai.content.use')
    <div class="bg-white shadow-sm sm:rounded-lg p-6 border-s-4 border-fuchsia-500"
         x-data="aiContentPanel({{ $product?->id ?? 'null' }})">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold text-gray-800 flex items-center gap-2">
                <span class="text-fuchsia-600">✦</span> {{ __('مساعد المحتوى بالذكاء الاصطناعي') }}
            </h3>
            <span class="text-xs text-gray-400">{{ __('اقتراح فقط — راجِع وطبّق يدويًا') }}</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-3">
            <div>
                <label class="block text-xs text-gray-600 mb-1">{{ __('نوع المحتوى') }}</label>
                <select x-model="type" class="w-full rounded-md border-gray-300 text-sm">
                    @foreach ([
                        'title_ar' => 'عنوان (عربي)', 'title_en' => 'عنوان (إنجليزي)',
                        'short_description' => 'وصف مختصر', 'description' => 'وصف تفصيلي',
                        'features' => 'المزايا والفوائد', 'specs' => 'المواصفات التقنية',
                        'seo_title' => 'عنوان SEO', 'meta_description' => 'وصف Meta',
                        'keywords' => 'كلمات مفتاحية', 'social_caption' => 'منشور تواصل اجتماعي',
                        'whatsapp' => 'نص بيع واتساب',
                    ] as $v => $l)
                        <option value="{{ $v }}">{{ __($l) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">{{ __('اللغة') }}</label>
                <select x-model="locale" class="w-full rounded-md border-gray-300 text-sm">
                    <option value="ar">{{ __('عربي') }}</option>
                    <option value="en">{{ __('إنجليزي') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">{{ __('النبرة') }}</label>
                <select x-model="tone" class="w-full rounded-md border-gray-300 text-sm">
                    <option value="">{{ __('افتراضية') }}</option>
                    <option value="professional">{{ __('احترافية') }}</option>
                    <option value="friendly">{{ __('ودّية') }}</option>
                    <option value="marketing">{{ __('تسويقية') }}</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex items-center gap-1 text-sm pb-1">
                    <input type="checkbox" x-model="useImage" class="rounded border-gray-300 text-fuchsia-600" />
                    {{ __('استخدم صورة المنتج') }}
                </label>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 mb-3">
            <button type="button" @click="run('generate')" :disabled="loading"
                    class="px-3 py-1.5 bg-fuchsia-600 text-white text-sm rounded-md hover:bg-fuchsia-700 disabled:opacity-50">
                <span x-show="!loading">{{ __('توليد') }}</span>
                <span x-show="loading">{{ __('جارٍ التوليد…') }}</span>
            </button>
            <button type="button" @click="run('regenerate')" :disabled="loading" class="px-3 py-1.5 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إعادة توليد') }}</button>
            <button type="button" @click="run('shorten')" :disabled="loading || !suggestion" class="px-3 py-1.5 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('اختصار') }}</button>
            <button type="button" @click="run('expand')" :disabled="loading || !suggestion" class="px-3 py-1.5 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('توسيع') }}</button>
        </div>

        <template x-if="error">
            <p class="text-xs text-rose-600 mb-2" x-text="error"></p>
        </template>

        <template x-if="suggestion">
            <div>
                <textarea x-model="suggestion" rows="4" class="w-full rounded-md border-gray-300 text-sm mb-2"></textarea>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="apply()" class="px-3 py-1.5 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('تطبيق على الحقل') }}</button>
                    <span class="text-xs text-gray-400" x-text="meta"></span>
                </div>
            </div>
        </template>
    </div>

    @once
        @push('scripts')
            <script>
                function aiContentPanel(productId) {
                    return {
                        type: 'title_ar', locale: 'ar', tone: '', useImage: false,
                        loading: false, suggestion: '', error: '', meta: '', productId,
                        // خريطة نوع المحتوى ⇄ حقل النموذج المستهدف للتطبيق اليدوي.
                        fieldMap: {
                            title_ar: 'name', title_en: 'name_en',
                            short_description: 'short_description', description: 'description',
                            features: 'description', specs: 'description',
                            seo_title: 'meta_title', meta_description: 'meta_description',
                            keywords: 'meta_keywords', social_caption: null, whatsapp: null,
                        },
                        gather() {
                            const val = (n) => (document.querySelector(`[name="${n}"]`)?.value || '').trim();
                            return {
                                name: this.locale === 'en' ? (val('name_en') || val('name')) : val('name'),
                                brand: document.querySelector('[name="brand_id"] option:checked')?.textContent?.trim() || '',
                                category: document.querySelector('[name="category_id"] option:checked')?.textContent?.trim() || '',
                                notes: val('short_description'),
                            };
                        },
                        async run(action) {
                            this.loading = true; this.error = ''; this.meta = '';
                            try {
                                const res = await fetch('{{ route('admin.ai.content.generate') }}', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                        'Accept': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        type: this.type, action, locale: this.locale,
                                        tone: this.tone || null, inputs: this.gather(),
                                        product_id: this.productId, use_image: this.useImage,
                                    }),
                                });
                                if (!res.ok) {
                                    const body = await res.json().catch(() => ({}));
                                    this.error = body.message || '{{ __('تعذّر التوليد. حاول لاحقًا.') }}';
                                    return;
                                }
                                const data = await res.json();
                                this.suggestion = data.suggestion;
                                this.meta = `${data.model} · ${data.total_tokens} tokens${data.status !== 'success' ? ' · ' + data.status : ''}`;
                            } catch (e) {
                                this.error = '{{ __('خطأ في الاتصال.') }}';
                            } finally {
                                this.loading = false;
                            }
                        },
                        apply() {
                            const target = this.fieldMap[this.type];
                            if (!target) { alert('{{ __('انسخ النص يدويًا — لا يوجد حقل مطابق.') }}'); return; }
                            const el = document.querySelector(`[name="${target}"]`);
                            if (el) { el.value = this.suggestion; el.dispatchEvent(new Event('input')); }
                        },
                    };
                }
            </script>
        @endpush
    @endonce
@endcan
