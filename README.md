<div dir="rtl">

# توفير أونلاين — Tawfeer Online

> منصة أعمال متكاملة: **ERP + CRM + متجر إلكتروني** في نظام واحد.

[![Status](https://img.shields.io/badge/status-planning-yellow)]()
[![Stack](https://img.shields.io/badge/stack-Laravel%20%2B%20MySQL-red)]()
[![License](https://img.shields.io/badge/license-Proprietary-blue)]()

---

## نظرة عامة

**توفير أونلاين** ليست مجرد متجر إلكتروني، بل منصة إدارة أعمال متكاملة تجمع تحت سقف واحد:

- 🛒 متجر إلكتروني حديث وحسابات عملاء.
- 📊 لوحات تحكم للموظفين والإدارة.
- 📦 إدارة المخزون والمستودعات.
- 🧾 إدارة المشتريات والموردين.
- 💰 نظام محاسبة كامل.
- 🤝 نظام إدارة علاقات العملاء (CRM).
- 📣 إدارة المسوّقين بالعمولة (Affiliate) والعمولات.
- 💬 صندوق وارد موحّد (WhatsApp / Messenger / Instagram).
- 📈 تقارير وتحليلات متقدمة.
- 🎁 العروض والكوبونات ونظام الولاء.
- 🔐 صلاحيات متعددة الأدوار.
- 🏢 دعم مستقبلي لتعدد الفروع.

الهدف: منصة واحدة متكاملة لإدارة كامل أعمال "توفير أونلاين".

---

## التقنيات (Tech Stack)

| الطبقة | التقنية |
|--------|---------|
| الإطار الخلفي | **Laravel 13** (PHP 8.3+) |
| قاعدة البيانات | **MySQL 8** |
| الواجهة | Blade + Livewire / Alpine.js + Tailwind CSS (RTL) |
| المصادقة | Laravel Breeze/Fortify + Sanctum (API) |
| الصلاحيات | spatie/laravel-permission |
| الطوابير والمهام | Laravel Queue + Redis |
| البحث | Laravel Scout (اختياري) |
| التوطين | عربي (RTL) أساسي + إنجليزي |

> التفاصيل الكاملة في [`REQUIREMENTS.md`](REQUIREMENTS.md) و[`DATABASE_DESIGN.md`](DATABASE_DESIGN.md).

---

## الوثائق

| الملف | الوصف |
|-------|-------|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | المبادئ المعمارية المُلزِمة (14 مبدأ) |
| [`PROJECT_PLAN.md`](PROJECT_PLAN.md) | خطة التنفيذ على مراحل |
| [`REQUIREMENTS.md`](REQUIREMENTS.md) | المتطلبات الوظيفية وغير الوظيفية |
| [`DATABASE_DESIGN.md`](DATABASE_DESIGN.md) | تصميم قاعدة البيانات عالي المستوى |
| [`CHANGELOG.md`](CHANGELOG.md) | سجلّ التغييرات |
| [`CLAUDE.md`](CLAUDE.md) | إرشادات العمل داخل المستودع |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | خطوات النشر والتشغيل |

### وثائق التحليل والتصميم (`docs/`)

| الملف | الوصف |
|-------|-------|
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | القرارات المعمارية المركزية (SSOT) |
| [`docs/BUSINESS_RULES.md`](docs/BUSINESS_RULES.md) | قواعد العمل المرقّمة |
| [`docs/USER_JOURNEYS.md`](docs/USER_JOURNEYS.md) | رحلات المستخدمين (11 دورًا) |
| [`docs/EVENTS.md`](docs/EVENTS.md) | كتالوج أحداث الدومين |
| [`docs/AUTOMATIONS.md`](docs/AUTOMATIONS.md) | الأتمتة المستقبلية |
| [`docs/REPORTS.md`](docs/REPORTS.md) | تصميم التقارير والتحليلات |
| [`docs/API_CONTRACT.md`](docs/API_CONTRACT.md) | عقد REST API |
| [`docs/UI_NAVIGATION.md`](docs/UI_NAVIGATION.md) | معمارية التنقّل والواجهة |
| [`docs/APPROVAL_WORKFLOWS.md`](docs/APPROVAL_WORKFLOWS.md) | مسارات الاعتماد وانتقال الحالات |
| [`docs/NOTIFICATION_MATRIX.md`](docs/NOTIFICATION_MATRIX.md) | مصفوفة الإشعارات |
| [`docs/PHASE_2_DESIGN.md`](docs/PHASE_2_DESIGN.md) | تصميم قاعدة بيانات Phase 2 |
| [`docs/DATA_DICTIONARY.md`](docs/DATA_DICTIONARY.md) | قاموس البيانات |
| [`docs/DESIGN_REVIEW.md`](docs/DESIGN_REVIEW.md) | خلاصة مراجعة التصميم |
| [`docs/FOUNDATION_REPORT.md`](docs/FOUNDATION_REPORT.md) | تقرير تجميد الأساس (v0.1.0) |

---

## متطلبات التشغيل

| الأداة | الإصدار |
|--------|---------|
| PHP | 8.3+ (مُختبَر على 8.4) |
| Composer | 2.x |
| Node.js | 20+ (مُختبَر على 22) |
| MySQL | 8.x (الإنتاج) |

---

## خطوات التثبيت (Installation)

```bash
# 1) استنساخ المشروع
git clone https://github.com/omardes2/tawfeer-online-system.git
cd tawfeer-online-system

# 2) تثبيت اعتماديات PHP و JavaScript
composer install
npm install

# 3) إعداد ملف البيئة ومفتاح التطبيق
cp .env.example .env
php artisan key:generate

# 4) ضبط اتصال قاعدة البيانات في .env
#    الافتراضي MySQL — عدّل DB_DATABASE / DB_USERNAME / DB_PASSWORD
#    (للتجربة السريعة يمكن استخدام SQLite: DB_CONNECTION=sqlite
#     ثم:  touch database/database.sqlite )

# 5) تشغيل الهجرات وزرع البيانات الأساسية
php artisan migrate --seed

# 6) بناء الأصول
npm run build        # أو: npm run dev  (أثناء التطوير)

# 7) تشغيل الخادم
php artisan serve     # http://127.0.0.1:8000
```

### حساب المدير الافتراضي (للتطوير)
| الحقل | القيمة |
|------|--------|
| البريد | `admin@tawfeer.online` |
| كلمة المرور | `password` |

> ⚠️ غيّر كلمة المرور فورًا في أي بيئة غير تطويرية.

### التحقّق السريع
```bash
php artisan test                       # 33 اختبارًا يجب أن تنجح
curl http://127.0.0.1:8000/api/v1/health   # {"status":"ok",...}
```

### نقاط API الأساسية
| الطريقة | المسار | الوصف | الحماية |
|:------:|--------|-------|:------:|
| GET | `/api/v1/health` | فحص صحّة الخدمة | عام |
| GET | `/api/v1/me` | المستخدم الحالي + أدواره وصلاحياته | Sanctum |

---

## هيكل الوحدات (Modules)

```
توفير أونلاين
├── المتجر الإلكتروني (Storefront)
├── حسابات العملاء (Customers)
├── إدارة الطلبات (Orders)
├── المخزون والمستودعات (Inventory)
├── المشتريات والموردين (Purchasing)
├── المحاسبة (Accounting)
├── إدارة علاقات العملاء (CRM)
├── المسوّقون والعمولات (Affiliate)
├── الصندوق الموحّد (Unified Inbox)
├── العروض والولاء (Promotions & Loyalty)
├── التقارير والتحليلات (Reports)
└── الإدارة والصلاحيات (Admin & RBAC)
```

---

## الحالة الحالية

🟢 **الأساس مُجمَّد — `v0.1.0-foundation`** (المرحلة 1 مكتملة).

المرحلة 1 (التأسيس) منجزة ومُختبَرة (33 اختبارًا ناجحًا). التالي: **المرحلة 2 — الكتالوج والمخزون**.

راجع [`docs/FOUNDATION_REPORT.md`](docs/FOUNDATION_REPORT.md) لتقرير التأسيس، و[`PROJECT_PLAN.md`](PROJECT_PLAN.md) للمراحل القادمة.

---

## الترخيص

مشروع خاص (Proprietary) — جميع الحقوق محفوظة لـ توفير أونلاين.

</div>
