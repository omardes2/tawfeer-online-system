import Alpine from 'alpinejs';

/*
| واجهة المتجر (Phase 3.3). Alpine + سلة عميل تُعيد استخدام واجهة السلة (3.1/3.2)
| بهوية مزدوجة (ضيف برمز سلة، أو مُصادَق بـBearer). لا منطق أعمال — تنسيق عرض فقط.
*/

const API = '/api/v1/store';

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
            return true;
        } catch (e) {
            this.error = true;
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

// تحميل السلة + إطلاق حدث الصفحة (ProductViewed/CategoryViewed/SearchPerformed).
document.addEventListener('DOMContentLoaded', () => {
    Alpine.store('cart').refresh();

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
