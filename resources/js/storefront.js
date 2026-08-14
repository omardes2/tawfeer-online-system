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

document.addEventListener('alpine:init', () => {
    Alpine.store('cart', cartStore);
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
