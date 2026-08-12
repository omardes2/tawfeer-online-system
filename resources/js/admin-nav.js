/**
 * مكوّن التنقّل في لوحة التحكّم: بحث فوري، قسم واحد مفتوح، ووجهات مثبّتة.
 *
 * الشجرة تصل مُصفّاة بالصلاحيات من `AdminNavigation` — هذا الملف لا يقرّر ما يُعرض،
 * بل كيف يُعرض. التثبيت اختيار شخصي لكل مستخدم، لذا يُحفظ في متصفّحه لا في الخادم.
 */

const PINS_KEY = 'admin_nav_pins';

/**
 * تطبيع النص العربي للبحث: إزالة التشكيل والتطويل وتوحيد صور الألف والياء
 * والتاء المربوطة — فالبحث عن «مرتجعات» يجد «مُرتجَعات»، و«فواتير» تجد «الفواتير».
 */
function normalize(value) {
    return String(value)
        .replace(/[ً-ْـ]/g, '')
        .replace(/[أإآ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

export default function adminNav(groups, activeGroup) {
    return {
        groups,
        q: '',
        open: activeGroup,
        pins: [],

        init() {
            try {
                const stored = JSON.parse(localStorage.getItem(PINS_KEY));
                this.pins = Array.isArray(stored) ? stored : [];
            } catch {
                this.pins = []; // تخزين تالف لا يجوز أن يمنع ظهور القائمة.
            }
        },

        /** الأقسام بعد تطبيق البحث؛ القسم الذي لا يطابق أيّ عنصر فيه يختفي. */
        get visibleGroups() {
            const q = normalize(this.q);
            if (! q) {
                return this.groups;
            }

            return this.groups
                .map((group) => {
                    // مطابقة اسم القسم تُظهر عناصره كلّها؛ وإلّا نُظهر المطابق منها فقط.
                    if (normalize(group.label).includes(q)) {
                        return group;
                    }

                    const items = group.items.filter((item) => normalize(item.label).includes(q));

                    return items.length ? { ...group, items } : null;
                })
                .filter(Boolean);
        },

        /** الوجهات المثبّتة بترتيب تثبيتها، متجاهلةً ما لم يعد المستخدم يملك صلاحيته. */
        get pinned() {
            const all = this.groups.flatMap((group) => group.items);

            return this.pins.map((url) => all.find((item) => item.url === url)).filter(Boolean);
        },

        isPinned(url) {
            return this.pins.includes(url);
        },

        togglePin(url) {
            this.pins = this.isPinned(url) ? this.pins.filter((u) => u !== url) : [...this.pins, url];
            localStorage.setItem(PINS_KEY, JSON.stringify(this.pins));
        },

        /** أثناء البحث تُفتح كل النتائج؛ خارجه قسم واحد فقط. */
        isOpen(index) {
            return this.q ? true : this.open === index;
        },

        toggle(index) {
            this.open = this.open === index ? -1 : index;
        },
    };
}
