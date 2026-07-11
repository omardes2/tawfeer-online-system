<div dir="rtl">

# تقرير التأسيس — Foundation Report

**المشروع:** توفير أونلاين (Tawfeer Online) — منصة ERP + CRM + متجر إلكتروني
**الإصدار:** `v0.1.0-foundation`
**التاريخ:** 2026-07-11
**الحالة:** ✅ الأساس مُجمَّد (Frozen) — جاهز لبدء المرحلة 2

هذا التقرير يوثّق ما أُنجز في **المرحلة 1 (التأسيس)** ويُثبّت الحالة المرجعية للمشروع.

---

## 1. البيئة والإصدارات

| البند | القيمة |
|------|--------|
| **إطار العمل** | Laravel Framework **13.19.0** |
| **PHP** | **8.4.19** (الحد الأدنى المطلوب: 8.3) |
| **قاعدة البيانات (الإنتاج)** | MySQL 8 |
| **قاعدة البيانات (تحقّق محلي)** | SQLite (داخل الحاوية فقط) |
| **الواجهة** | Blade + Tailwind CSS 3 + Alpine.js (RTL) |
| **اللغة الافتراضية** | عربي (RTL) — مع دعم إنجليزي |

---

## 2. الحزم المثبّتة

### Composer — `require`
| الحزمة | الإصدار المطلوب | المثبَّت |
|--------|:---------------:|:--------:|
| `php` | `^8.3` | 8.4.19 |
| `laravel/framework` | `^13.8` | v13.19.0 |
| `laravel/sanctum` | `^4.0` | v4.3.2 |
| `laravel/tinker` | `^3.0` | — |
| `spatie/laravel-permission` | `^8.3` | 8.3.0 |

### Composer — `require-dev`
`laravel/breeze` (v2.4.2) · `laravel/pint` · `phpunit/phpunit` (^12.5) · `fakerphp/faker` · `mockery/mockery` · `nunomaduro/collision` · `laravel/pail`

### NPM — `devDependencies`
`tailwindcss` (^3.1) · `@tailwindcss/forms` · `alpinejs` · `vite` (^8) · `laravel-vite-plugin` · `autoprefixer` · `postcss` · `concurrently`

---

## 3. هيكل المجلدات (المجال)

```
app/
├── Http/
│   ├── Controllers/Auth/        ← مصادقة Breeze
│   ├── Requests/                ← Form Requests (تحقّق)
│   └── Resources/               ← UserResource (API-First)
├── Models/
│   └── User.php                 ← + UUID/Audit/Roles/SoftDeletes/Sanctum
├── Modules/                     ← وحدات مستقلة (المبدأ 14)
│   └── Foundation/
│       ├── Models/              ← Branch, Setting, AuditLog, *Status
│       ├── Services/            ← SettingsManager + Settings (Facade)
│       ├── Observers/
│       └── Providers/           ← FoundationServiceProvider
├── Support/                     ← طبقة دعم مشتركة
│   ├── Concerns/                ← HasUuid, Auditable, RunsInTransaction
│   ├── Contracts/               ← PaymentGateway (طبقة تكامل)
│   └── Integrations/Payment/    ← NullPaymentGateway (Driver)
└── Providers/
database/
├── migrations/                  ← 10 هجرات
└── seeders/                     ← 6 seeders
lang/{ar,en}/                    ← التوطين
routes/{web,api,auth}.php        ← api = /api/v1 (Sanctum)
```

---

## 4. الوحدات المُنشأة

| الوحدة | المحتوى | الحالة |
|--------|---------|:------:|
| **Foundation** | Branch، Settings، AuditLog، الحالات، طبقة التكامل، مزوّد الخدمة | ✅ مكتملة |

> الوحدات القادمة (Catalog, Inventory, Orders, Purchasing, Accounting, CRM, Affiliate, Messaging, Promotions, Reports) تتبع نفس نمط `app/Modules/{Module}`.

**طبقة الدعم المشتركة (`app/Support`):**
- `Concerns/HasUuid` — معرّف خارجي UUID (المبدأ 4)
- `Concerns/Auditable` — تدقيق آلي (المبدأ 8)
- `Concerns/RunsInTransaction` — معاملات ذرّية (المبدأ 7)
- `Contracts/PaymentGateway` + `Integrations/Payment/NullPaymentGateway` — طبقة التكامل (المبدأ 13)

---

## 5. قاعدة البيانات

### الهجرات (Migrations) — **10**
1. `create_users_table`
2. `create_cache_table`
3. `create_jobs_table`
4. `create_personal_access_tokens_table` (Sanctum)
5. `create_permission_tables` (spatie)
6. `create_branches_table`
7. `add_foundation_columns_to_users_table` (uuid, branch_id, phone, is_active, soft delete)
8. `create_audit_logs_table`
9. `create_settings_table`
10. `create_status_tables` (order / payment / shipment)

### الـ Seeders — **6**
`DatabaseSeeder` (منسّق) → `BranchSeeder` · `RolePermissionSeeder` · `StatusSeeder` · `SettingsSeeder` · `AdminUserSeeder`

**بيانات مزروعة:**
- فرع افتراضي: **الفرع الرئيسي** (`MAIN`)
- 7 أدوار: admin, manager, sales, accountant, warehouse, affiliate, customer
- 27 صلاحية مجمّعة حسب الوحدة
- حالات: 7 طلب · 4 دفع · 5 شحن
- إعدادات افتراضية (العملة، الضريبة، بيانات المتجر)
- مدير افتراضي: `admin@tawfeer.online` (كلمة المرور للتطوير فقط)

---

## 6. الاختبارات

| المقياس | القيمة |
|---------|--------|
| **الاختبارات الناجحة** | **33 / 33** ✅ |
| **عدد التوكيدات (assertions)** | 81 |
| **إطار الاختبار** | PHPUnit 12 (SQLite in-memory) |
| **اختبار الأسس** | `FoundationTest` — يتحقق من المبادئ 1، 4، 6، 8، 9، 10، 11، 12 |

**تحقّق تشغيلي (Runtime):**
- `GET /api/v1/health` → `200 OK`
- `GET /api/v1/me` بلا توكن → `401`
- `GET /api/v1/me` بتوكن → resource يكشف `uuid` + الأدوار + الصلاحيات
- صفحة الدخول → `dir="rtl" lang="ar"` + خط Tajawal

---

## 7. مرجع Git

| البند | القيمة |
|------|--------|
| **الفرع** | `claude/tawfeer-online-setup-2ooosk` |
| **commit كود الأساس** | `6640e92` (feat(phase-1): scaffold Laravel foundation) |
| **commit تجميد الأساس** | `Foundation completed (v0.1.0)` |
| **الوسم (Tag)** | `v0.1.0-foundation` |
| **المستودع** | github.com/omardes2/tawfeer-online-system |

---

## 8. حالة المبادئ المعمارية (14)

| # | المبدأ | الحالة |
|:-:|--------|:------:|
| 1 | Multi-Branch Ready | ✅ مُطبَّق |
| 2 | Multi-Warehouse Ready | 🟡 مُصمَّم (يُنفَّذ في المرحلة 2) |
| 3 | Multi-Tenant Ready (بلا تفعيل) | ✅ تصميمًا |
| 4 | UUID للعناصر الخارجية | ✅ مُطبَّق |
| 5 | Soft Deletes | ✅ مُطبَّق |
| 6 | Decimal للمبالغ | ✅ معيار مُثبَّت |
| 7 | Database Transactions | ✅ تريتة جاهزة |
| 8 | Audit Log مركزي | ✅ مُطبَّق (آلي) |
| 9 | Settings ديناميكي | ✅ مُطبَّق |
| 10 | حالات قابلة للإدارة | ✅ مُطبَّق (بذور) |
| 11 | API-First | ✅ مُطبَّق (Sanctum + Resources) |
| 12 | RBAC | ✅ مُطبَّق |
| 13 | طبقة التكامل | ✅ عقد + Driver |
| 14 | وحدات مستقلة | ✅ مُطبَّق |

---

## 9. المهام المتبقية (TODOs)

**على مستوى الأساس (مؤجّلة عمدًا):**
- [ ] واجهات إدارة (CRUD) للفروع/الإعدادات/الحالات من لوحة التحكم (البنية جاهزة، الواجهات لاحقًا).
- [ ] لوحة عرض سجلّ التدقيق (`audit.view`).
- [ ] توطين كامل لرسائل التحقّق (`validation.php`) بالعربية (حاليًا fallback إنجليزي).
- [ ] تفعيل Multi-Tenant عند الحاجة (إضافة `tenant_id` + Scope).
- [ ] بوابة دفع حقيقية خلف عقد `PaymentGateway` (المرحلة 3).
- [ ] إعداد Redis للطوابير/الكاش في الإنتاج (مضبوط في `.env.example`).
- [ ] تشغيل فعلي على MySQL في بيئة بها خادم (تم التحقق محليًا على SQLite).

**المرحلة القادمة (2 — الكتالوج والمخزون):**
- [ ] الفئات، المنتجات، المتغيّرات (SKU/باركود)، الوحدات.
- [ ] المستودعات المتعددة وأرصدة المخزون لكل مستودع.
- [ ] حركات المخزون (وارد/صادر/تحويل/جرد) داخل معاملات ذرّية.
- [ ] تنبيهات حد إعادة الطلب.

---

*تم تجميد هذا الأساس عند الوسم `v0.1.0-foundation`. أي تغيير لاحق يبدأ من المرحلة 2.*

</div>
