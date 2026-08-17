import Alpine from 'alpinejs';

/*
| واجهة المتجر (Phase 3.3). Alpine + سلة عميل تُعيد استخدام واجهة السلة (3.1/3.2)
| بهوية مزدوجة (ضيف برمز سلة، أو مُصادَق بـBearer). لا منطق أعمال — تنسيق عرض فقط.
*/

const API = '/api/v1/store';

// ---- تنبيه عابر (toast): يُطلَق من مخزن السلة لا من زرّ بعينه، فيظهر لأي
// إضافة في الموقع (الشريط العائم، بطاقة المنتج، صفحة التفاصيل) بلا تكرار كود ----
function toast(message, kind = 'success') {
    window.dispatchEvent(new CustomEvent('storefront:toast', { detail: { message, kind } }));
}
window.StorefrontToast = toast;

// ---- نقطة امتداد التحليلات (بلا مزوّد): تُطلق أحداث نافذة يشترك بها مستقبلًا ----
const Analytics = {
    track(event, payload = {}) {
        window.dispatchEvent(
            new CustomEvent('storefront:analytics', { detail: { event, payload } }),
        );
    },
};
window.StorefrontAnalytics = Analytics;

// ---- الهوية: رمز سلة الضيف (يُولَّد ويُحفَظ) أو توكن مُصادَق ----
function cartToken() {
    let t = localStorage.getItem('cart_token');
    if (!t) {
        t = (crypto.randomUUID && crypto.randomUUID()) || `${Date.now()}-${Math.random()}`;
        localStorage.setItem('cart_token', t);
    }
    // نسخة في كوكي حتى يقرأها الخادم لدمج سلة الضيف عند تسجيل الدخول (Phase 3.4).
    document.cookie = `cart_token=${t}; path=/; max-age=31536000; SameSite=Lax`;
    return t;
}

function authToken() {
    return localStorage.getItem('auth_token');
}

function headers() {
    const h = { Accept: 'application/json', 'Content-Type': 'application/json' };
    const bearer = authToken();
    if (bearer) {
        h.Authorization = `Bearer ${bearer}`;
    } else {
        h['X-Cart-Token'] = cartToken();
    }
    return h;
}

// ---- مخزن السلة (Alpine store) ----
const cartStore = {
    count: 0,
    subtotal: 0,
    items: [],
    loading: false,
    error: false,

    apply(data) {
        this.items = data.items || [];
        this.count = data.item_count || 0;
        this.subtotal = data.subtotal || 0;
    },

    async refresh() {
        this.loading = true;
        this.error = false;
        try {
            const res = await fetch(`${API}/cart`, { headers: headers() });
            if (!res.ok) throw new Error('cart');
            this.apply((await res.json()).data);
        } catch (e) {
            this.error = true;
        } finally {
            this.loading = false;
        }
    },

    async add(variantUuid, qty = 1) {
        this.error = false;
        try {
            const res = await fetch(`${API}/cart/items`, {
                method: 'POST',
                headers: headers(),
                body: JSON.stringify({ variant: variantUuid, qty }),
            });
            if (!res.ok) throw new Error('add');
            this.apply((await res.json()).data);
            Analytics.track('ProductAddedToCart', { variant: variantUuid, qty });
            toast(window.SF_I18N?.added_to_cart);
            return true;
        } catch (e) {
            this.error = true;
            toast(window.SF_I18N?.add_failed, 'error');
            return false;
        }
    },

    async setQty(variantUuid, qty) {
        const res = await fetch(`${API}/cart/items/${variantUuid}`, {
            method: 'PATCH',
            headers: headers(),
            body: JSON.stringify({ qty }),
        });
        if (res.ok) this.apply((await res.json()).data);
    },

    async remove(variantUuid) {
        const res = await fetch(`${API}/cart/items/${variantUuid}`, {
            method: 'DELETE',
            headers: headers(),
        });
        if (res.ok) {
            this.apply((await res.json()).data);
            Analytics.track('ProductRemovedFromCart', { variant: variantUuid });
        }
    },
};

// إتاحة الترويسات لصفحات الإتمام (تُعيد استخدام نفس الهوية).
window.StorefrontIdentity = { headers, cartToken, authToken };

/*
| اقتراحات البحث: قائمة تنسدل تحت الحقل بأسماء ما طابق الحروف المكتوبة، فيختار
| الزبون الاسم بدل تهجئته. تحسينٌ فوق نموذج قائم — إن سقط الطلب أو عُطّل
| جافاسكربت بقي الإرسال العادي إلى صفحة النتائج عاملًا كما هو.
*/
window.sfSearch = (initial = '') => ({
    q: initial,
    items: [],
    open: false,
    active: -1,      // ‎-1‎ يعني «لا اقتراح منتقى»: الإدخال يُرسل النموذج
    timer: null,
    seq: 0,          // ترتيب الطلبات: ردٌّ متأخّر لطلب قديم لا يدهس الأحدث

    input() {
        clearTimeout(this.timer);
        this.active = -1;
        if (this.q.trim().length < 2) {
            this.items = [];
            this.open = false;
            return;
        }
        // 200ms: طلبٌ لكل ضغطة مفتاح يغرق الخادم بلا فائدة للزبون.
        this.timer = setTimeout(() => this.fetch(), 200);
    },

    async fetch() {
        const mine = ++this.seq;
        try {
            const res = await fetch(`/search/suggest?q=${encodeURIComponent(this.q.trim())}`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) throw new Error('suggest');
            const data = (await res.json()).data || [];
            if (mine !== this.seq) return;
            this.items = data;
            this.open = data.length > 0;
        } catch (e) {
            if (mine !== this.seq) return;
            this.items = [];
            this.open = false;   // صامت: البحث نفسه ما زال يعمل
        }
    },

    move(step) {
        if (!this.open || this.items.length === 0) return;
        this.active = (this.active + step + this.items.length) % this.items.length;
    },

    // الإدخال على اقتراح منتقى يفتحه؛ وبلا انتقاء يُرسل النموذج كالمعتاد.
    enter(event) {
        if (this.open && this.active >= 0 && this.items[this.active]) {
            event.preventDefault();
            window.location.href = this.items[this.active].url;
        }
    },

    close() {
        this.open = false;
        this.active = -1;
    },

    clear(el) {
        this.q = '';
        this.items = [];
        this.close();
        el?.focus();
    },
});

/*
| ⚠️ Protected Delivery Integration — Do Not Modify.
|
| «شراء الآن» — شكلٌ آخر حول مسار الإتمام القائم لا مسار جديد. نفس النقاط، ونفس
| التسلسل (start ← PATCH ← place)، ونفس مفاتيح النموذج، ونفس ترويسات الهوية.
| **رسوم التوصيل تأتي من استجابة الـPATCH وحدها ولا تُحسب هنا إطلاقًا.**
|
| وجلسة الإتمام تُبنى من السلة لا من صنف، فالصنف يُضاف أولًا ثم تُفتح الجلسة —
| والملخّص يعرض السلة كاملةً ويُنبّه حين تحمل غير هذا الصنف.
*/
const quickBuy = (areas) => ({
    areas,
    shown: false,
    busy: false,
    placing: false,
    sessionId: null,
    error: null,
    order: null,
    variantUuid: null,
    totals: { subtotal: 0, delivery_fee: 0, total: 0 },
    form: {
        customer_name: '', customer_phone: '',
        shipping_address: '', city_id: '', area_id: '', payment_method_code: 'cod',
    },

    get cartCount() {
        return Alpine.store('cart').items.length;
    },

    /** أصنافٌ في السلة غير الذي فُتح اللوح من أجله. */
    get otherItems() {
        return Alpine.store('cart').items.filter((i) => i.variant_id !== this.variantUuid).length;
    },

    async open(variantUuid) {
        if (!variantUuid || this.busy) return;

        this.busy = true;
        this.error = null;
        this.order = null;
        this.variantUuid = variantUuid;

        try {
            const store = Alpine.store('cart');
            // لا يُضاف مكرّرًا: الصنف الموجود في السلة يبقى بكميته.
            const inCart = store.items.some((i) => i.variant_id === variantUuid);
            if (!inCart && !(await store.add(variantUuid, 1))) {
                this.busy = false;
                return;
            }

            this.shown = true;
            document.body.style.overflow = 'hidden';
            await this.start();
        } finally {
            this.busy = false;
        }
    },

    close() {
        this.shown = false;
        document.body.style.overflow = '';
    },

    async start() {
        try {
            const res = await fetch('/api/v1/store/checkout', {
                method: 'POST',
                headers: window.StorefrontIdentity.headers(),
            });
            if (!res.ok) throw new Error('start');
            const data = (await res.json()).data;
            this.sessionId = data.id;
            this.applyTotals(data);
            window.StorefrontAnalytics.track('CheckoutStarted', { session: this.sessionId, quick: true });
        } catch (e) {
            this.error = window.SF_I18N?.error;
        }
    },

    money(v) {
        return `${Number(v || 0).toFixed(2)} ${window.SF_I18N?.currency ?? ''}`.trim();
    },

    areasOf(cityId) {
        return cityId ? this.areas.filter((a) => Number(a.city_id) === Number(cityId)) : [];
    },

    pickCity() {
        this.form.area_id = '';
        this.sync();
    },

    applyTotals(data) {
        if (data && data.cart) {
            this.totals = {
                subtotal: data.cart.subtotal ?? 0,
                delivery_fee: data.cart.delivery_fee ?? 0,
                total: data.cart.total ?? 0,
            };
        }
    },

    /** يحفظ الجلسة ويقرأ الرسوم المحسوبة في الخلفية. */
    async sync() {
        if (!this.sessionId) return;
        try {
            const res = await fetch(`/api/v1/store/checkout/${this.sessionId}`, {
                method: 'PATCH',
                headers: window.StorefrontIdentity.headers(),
                body: JSON.stringify(this.form),
            });
            if (res.ok) this.applyTotals((await res.json()).data);
        } catch (e) { /* تجاهل — تُعاد المزامنة عند الإتمام */ }
    },

    async place() {
        if (!this.sessionId) return;
        this.placing = true;
        this.error = null;
        try {
            const h = window.StorefrontIdentity.headers();
            await fetch(`/api/v1/store/checkout/${this.sessionId}`, {
                method: 'PATCH', headers: h, body: JSON.stringify(this.form),
            });
            const res = await fetch(`/api/v1/store/checkout/${this.sessionId}/place`, { method: 'POST', headers: h });
            if (!res.ok) throw new Error('place');
            this.order = (await res.json()).data;
            await Alpine.store('cart').refresh();
        } catch (e) {
            this.error = window.SF_I18N?.error;
        } finally {
            this.placing = false;
        }
    },
});

document.addEventListener('alpine:init', () => {
    Alpine.store('cart', cartStore);
    Alpine.data('quickBuy', quickBuy);
});

window.Alpine = Alpine;
Alpine.start();

/*
| تتبّع أداء التوصيات (Phase 6 / ADR-045). يلتقط أقسام [data-reco-section] ويطلق:
|  - ظهور (impression) مرة واحدة عندما يدخل القسم إطار العرض (IntersectionObserver).
|  - نقر (click) عند فتح منتج موصى به.
| **بلا تكرار ولا ضوضاء:** مجموعة إزالة تكرار لكل (حدث/منتج/موضع) مرّة واحدة لكل تحميل صفحة،
| ويُلغى مراقبة القسم بعد أوّل ظهور. يحترم جلسة الضيف/المُصادَق (كوكي الجلسة + CSRF).
*/
const RecoTracker = {
    seen: new Set(),
    csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    },
    send(event, card, section) {
        const productId = card?.dataset.recoProduct;
        if (!productId) return;
        const placement = section?.dataset.recoPlacement || null;
        const key = `${event}:${placement}:${productId}`;
        if (this.seen.has(key)) return; // إزالة التكرار
        this.seen.add(key);

        const body = JSON.stringify({
            recommended_product_id: Number(productId),
            source_product_id: section?.dataset.recoSource ? Number(section.dataset.recoSource) : null,
            type: section?.dataset.recoType || 'related',
            event,
            source: card?.dataset.recoSrc || 'rule',
            placement,
        });

        // keepalive ليكتمل الطلب حتى عند مغادرة الصفحة (النقر يقود إلى تنقّل).
        fetch('/recommendations/track', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
            body,
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
    },
    init() {
        const sections = document.querySelectorAll('[data-reco-section]');
        if (!sections.length) return;

        // الظهور: مرّة واحدة لكل قسم عند دخوله إطار العرض.
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                const section = entry.target;
                section.querySelectorAll('[data-reco-product]').forEach((card) => this.send('impression', card, section));
                obs.unobserve(section); // لا تكرار للظهور
            });
        }, { threshold: 0.4 });
        sections.forEach((s) => observer.observe(s));

        // النقر: تفويض حدث واحد على مستوى المستند.
        document.addEventListener('click', (e) => {
            const card = e.target.closest('[data-reco-product]');
            if (!card) return;
            const section = card.closest('[data-reco-section]');
            if (section) this.send('click', card, section);
        });
    },
};
window.StorefrontRecoTracker = RecoTracker;

// تحميل السلة + إطلاق حدث الصفحة (ProductViewed/CategoryViewed/SearchPerformed) + تتبّع التوصيات.
document.addEventListener('DOMContentLoaded', () => {
    Alpine.store('cart').refresh();
    RecoTracker.init();

    const el = document.getElementById('sf-page-event');
    if (el && el.dataset.event) {
        let payload = {};
        try {
            payload = el.dataset.payload ? JSON.parse(el.dataset.payload) : {};
        } catch (e) {
            payload = {};
        }
        Analytics.track(el.dataset.event, payload);
    }
});
