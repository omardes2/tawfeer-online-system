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
| الإطار الخلفي | **Laravel 11** (PHP 8.2+) |
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
| [`PROJECT_PLAN.md`](PROJECT_PLAN.md) | خطة التنفيذ على مراحل |
| [`REQUIREMENTS.md`](REQUIREMENTS.md) | المتطلبات الوظيفية وغير الوظيفية |
| [`DATABASE_DESIGN.md`](DATABASE_DESIGN.md) | تصميم قاعدة البيانات والعلاقات |
| [`CLAUDE.md`](CLAUDE.md) | إرشادات العمل داخل المستودع |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | خطوات النشر والتشغيل |

---

## البدء السريع (بعد بدء التطوير)

> ⚠️ المشروع حاليًا في **مرحلة التخطيط** — لم تُكتب أكواد بعد.

```bash
# استنساخ المشروع
git clone https://github.com/omardes2/tawfeer-online-system.git
cd tawfeer-online-system

# تثبيت الاعتماديات (بعد إنشاء مشروع Laravel)
composer install
npm install

# الإعداد
cp .env.example .env
php artisan key:generate
php artisan migrate --seed

# التشغيل
php artisan serve
npm run dev
```

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

🟡 **مرحلة التخطيط والتوثيق** — يتم إعداد الوثائق قبل بدء البرمجة.

راجع [`PROJECT_PLAN.md`](PROJECT_PLAN.md) لمعرفة المراحل القادمة.

---

## الترخيص

مشروع خاص (Proprietary) — جميع الحقوق محفوظة لـ توفير أونلاين.

</div>
