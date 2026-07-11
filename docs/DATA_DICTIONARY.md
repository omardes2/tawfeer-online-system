<div dir="rtl">

# قاموس البيانات — المرحلة 2 (Data Dictionary)

**المشروع:** Tawfeer Online — MySQL 8 / Laravel 13.
**المكمِّل:** [`docs/PHASE_2_DESIGN.md`](PHASE_2_DESIGN.md) (نفس أسماء الأعمدة وأنواعها). المرجع القانوني: [`docs/DECISIONS.md`](DECISIONS.md).

هذا القاموس مرجع مسطّح لكل عمود في جداول المرحلة 2 الـ27 (مع جداول الربط/البنود المساعدة)، مرتّب أبجديًا حسب الجدول. يليه: مصطلحات، قوائم القيم المعدودة، والاصطلاحات.

**الرموز:** N = يقبل NULL. `—` = لا افتراضي (مطلوب). كل جدول يحوي `id BIGINT PK` وطوابع `created_at`/`updated_at` ما لم يُذكر خلاف ذلك.

---

## 1. الاصطلاحات (Conventions)

| البند | القاعدة | المرجع |
|------|--------|--------|
| مبالغ مالية | `decimal(15,2)` | ADR-001 / المبدأ 6 |
| تكلفة / WAC | `decimal(15,4)` | ADR-001 / ADR-005 |
| كميات | `decimal(15,3)` | ADR-001 |
| نِسب (%) | `decimal(8,4)` | ADR-006 / ADR-015 |
| سعر صرف | `decimal(15,6)` | ADR-001 |
| مفتاح داخلي | `id` BIGINT UNSIGNED auto-inc | المبدأ 4 |
| معرّف خارجي | `uuid` CHAR(36) فريد مفهرس — للكيانات المكشوفة | ADR-002 |
| طوابع | `created_at`,`updated_at`؛ الدفاتر: `created_at` فقط | ADR-020 |
| حذف ناعم | `deleted_at` على الكيانات الرئيسية؛ لا على الدفاتر/الحركات | المبدأ 5 / ADR-020 |
| `branch_id` | nullable، يُملأ بالفرع الافتراضي؛ لا `=1` ثابت | المبدأ 1 / ADR-003 |
| التسمية | جداول snake_case جمع؛ أعمدة snake_case مفرد؛ FK بصيغة `{table_singular}_id` | — |
| ممنوع | `float`/`double` لأي مبلغ | المبدأ 6 |
| فرادة | مركّبة لا عامة حيثما لزم (جاهزية Multi-Tenant) | ADR-004 |

---

## 2. القاموس (مرتّب أبجديًا حسب الجدول)

### `areas` — المناطق
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| city_id | BIGINT | لا | — | المدينة الأم | FK→cities (RESTRICT) |
| name | string(120) | لا | — | اسم المنطقة (عربي) | Unique(city_id,name) |
| name_en | string(120) | نعم | NULL | الاسم الإنجليزي | |
| code | string(20) | نعم | NULL | رمز إداري | |
| postal_code | string(15) | نعم | NULL | الرمز البريدي | |
| sort_order | int | لا | 0 | ترتيب العرض | |
| is_active | boolean | لا | true | مُفعّل | Index(is_active,sort_order) |

### `branch_settings` — إعدادات الفرع
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| branch_id | BIGINT | لا | — | الفرع | FK→branches (CASCADE) |
| key | string(150) | لا | — | مفتاح الإعداد | Unique(branch_id,key) |
| value | json | نعم | NULL | القيمة | |
| group | string(60) | نعم | 'general' | تجميع | |
| type | string(30) | نعم | 'string' | نوع القيمة | string/bool/int/json |

### `branches` — الفروع *(امتدادات Phase 2 فقط)*
القائم من Phase 1: `uuid, name, code(unique), address, phone, is_default, is_active, deleted_at`.
| العمود المُضاف | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| email | string(150) | نعم | NULL | بريد الفرع | |
| tax_number | string(50) | نعم | NULL | الرقم الضريبي | |
| default_currency_id | BIGINT | نعم | NULL | عملة العرض | FK→currencies (SET NULL)؛ الدفاتر بالأساسية |
| default_warehouse_id | BIGINT | نعم | NULL | مستودع افتراضي | FK→warehouses (SET NULL)؛ يُضاف بعد warehouses |
| timezone | string(40) | نعم | 'Asia/Riyadh' | المنطقة الزمنية | |

### `brands` — العلامات التجارية
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique |
| name | string(150) | لا | — | اسم العلامة | |
| slug | string(170) | لا | — | معرّف URL | Unique |
| logo | string(255) | نعم | NULL | مسار الشعار | |
| description | text | نعم | NULL | وصف | |
| is_active | boolean | لا | true | مُفعّل | |
| deleted_at | timestamp | نعم | NULL | حذف ناعم | Soft delete + Auditable |

### `categories` — الفئات
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique |
| parent_id | BIGINT | نعم | NULL | الفئة الأم | FK→categories self (SET NULL) |
| name | string(150) | لا | — | اسم الفئة | |
| slug | string(170) | لا | — | معرّف URL | Unique |
| description | text | نعم | NULL | وصف | |
| image | string(255) | نعم | NULL | صورة | |
| sort_order | int | لا | 0 | ترتيب | |
| is_active | boolean | لا | true | مُفعّل | |
| deleted_at | timestamp | نعم | NULL | حذف ناعم | Soft delete + Auditable |

### `cities` — المدن
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| governorate_id | BIGINT | لا | — | المحافظة الأم | FK→governorates (RESTRICT) |
| name | string(120) | لا | — | اسم المدينة | Unique(governorate_id,name) |
| name_en | string(120) | نعم | NULL | الاسم الإنجليزي | |
| code | string(20) | نعم | NULL | رمز | |
| sort_order | int | لا | 0 | ترتيب | |
| is_active | boolean | لا | true | مُفعّل | |

### `currencies` — العملات
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| code | char(3) | لا | — | رمز ISO 4217 | Unique |
| name | string(80) | لا | — | اسم العملة | |
| symbol | string(10) | نعم | NULL | الرمز | |
| exchange_rate | decimal(15,6) | لا | 1.000000 | سعر الصرف مقابل الأساسية | |
| is_base | boolean | لا | false | العملة الأساسية | واحدة فقط true (SAR افتراضي) |
| is_active | boolean | لا | true | مُفعّل | Auditable |

### `governorates` — المحافظات
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| name | string(120) | لا | — | اسم المحافظة | |
| name_en | string(120) | نعم | NULL | الاسم الإنجليزي | |
| code | string(20) | نعم | NULL | رمز | Unique(country_code,code) |
| country_code | char(2) | لا | 'SA' | رمز الدولة | |
| sort_order | int | لا | 0 | ترتيب | |
| is_active | boolean | لا | true | مُفعّل | |

### `inventory_ledger` — دفتر المخزون (append-only، لا حذف، لا updated_at)
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| variant_id | BIGINT | لا | — | المتغيّر | FK→product_variants (RESTRICT) |
| warehouse_id | BIGINT | لا | — | المستودع | FK→warehouses (RESTRICT) |
| movement_id | BIGINT | نعم | NULL | الحركة المولّدة | FK→inventory_movements (SET NULL) |
| branch_id | BIGINT | نعم | NULL | الفرع | FK→branches (SET NULL) |
| movement_type | string(30) | لا | — | نوع الحركة | من مفردات ADR-008 |
| bucket | string(20) | لا | 'on_hand' | الدلو المتأثّر | on_hand/reserved/damaged/returned_pending/in_transit |
| reference_type | string(60) | نعم | NULL | نوع المرجع | polymorphic |
| reference_id | BIGINT | نعم | NULL | معرّف المرجع | |
| qty_change | decimal(15,3) | لا | — | تغيّر الكمية (مُوقّع) | +/− |
| balance_after | decimal(15,3) | لا | — | الرصيد الجاري للدلو | |
| unit_cost | decimal(15,4) | نعم | NULL | تكلفة الوحدة | |
| wac_after | decimal(15,4) | لا | 0.0000 | WAC بعد الحركة | ADR-005 |
| value_change | decimal(15,4) | لا | 0.0000 | تغيّر القيمة | |
| balance_value_after | decimal(15,4) | لا | 0.0000 | قيمة المخزون الجارية | |
| created_by | BIGINT | نعم | NULL | المُنشئ | FK→users (SET NULL) |
| created_at | timestamp | لا | — | وقت القيد | **لا updated_at، لا deleted_at** |

### `inventory_movements` — حركات المخزون (لا حذف ناعم)
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique |
| branch_id | BIGINT | نعم | NULL | الفرع | FK→branches (SET NULL) |
| variant_id | BIGINT | لا | — | المتغيّر | FK→product_variants (RESTRICT) |
| warehouse_id | BIGINT | لا | — | المستودع المصدر/المتأثّر | FK→warehouses (RESTRICT) |
| to_warehouse_id | BIGINT | نعم | NULL | مستودع الوجهة (تحويل) | FK→warehouses (RESTRICT) |
| type | string(30) | لا | — | نوع الحركة | مفردات ADR-008 (انظر القوائم) |
| bucket | string(20) | لا | 'on_hand' | الدلو المتأثّر | |
| qty | decimal(15,3) | لا | — | الكمية (موجبة؛ الاتجاه من type) | |
| unit_cost | decimal(15,4) | نعم | NULL | تكلفة الوحدة | |
| total_cost | decimal(15,4) | نعم | NULL | إجمالي التكلفة | |
| reference_type | string(60) | نعم | NULL | نوع المرجع | polymorphic |
| reference_id | BIGINT | نعم | NULL | معرّف المرجع | |
| reason | string(150) | نعم | NULL | السبب | |
| note | text | نعم | NULL | ملاحظة | |
| created_by | BIGINT | نعم | NULL | المُنشئ | FK→users (SET NULL) |

### `inventory_stocks` — أرصدة المخزون (رصيد حيّ، لا حذف ناعم)
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| variant_id | BIGINT | لا | — | المتغيّر | FK→product_variants (CASCADE)؛ Unique(variant_id,warehouse_id) |
| warehouse_id | BIGINT | لا | — | المستودع | FK→warehouses (RESTRICT) |
| on_hand | decimal(15,3) | لا | 0.000 | الموجود ماديًا | ADR-007 |
| reserved | decimal(15,3) | لا | 0.000 | المحجوز | |
| damaged | decimal(15,3) | لا | 0.000 | التالف | لا يُحتسب متاحًا |
| returned_pending | decimal(15,3) | لا | 0.000 | مرتجع بانتظار الفحص | لا يُحتسب متاحًا |
| in_transit | decimal(15,3) | لا | 0.000 | قيد التحويل | لا يُحتسب متاحًا |
| average_cost | decimal(15,4) | لا | 0.0000 | WAC الحالي | ADR-005 |
| cost_price | decimal(15,4) | لا | 0.0000 | تكلفة آخر شراء | |
| reorder_level | decimal(15,3) | نعم | NULL | حدّ إعادة الطلب | |
| reorder_qty | decimal(15,3) | نعم | NULL | كمية إعادة الطلب | |
| last_movement_at | timestamp | نعم | NULL | آخر حركة | |
| *available* | *محسوب* | — | — | `on_hand − reserved` | **لا يُخزَّن** (ADR-007) |

### `product_attribute_values` — قيم السمات
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| attribute_id | BIGINT | لا | — | السمة الأم | FK→product_attributes (CASCADE) |
| value | string(120) | لا | — | القيمة | Unique(attribute_id,value) |
| label | string(120) | نعم | NULL | التسمية المعروضة | |
| color_hex | char(7) | نعم | NULL | لون (لسمات اللون) | |
| sort_order | int | لا | 0 | ترتيب | |
| is_active | boolean | لا | true | مُفعّل | |

### `product_attributes` — سمات المنتج
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| name | string(100) | لا | — | اسم السمة | |
| slug | string(120) | لا | — | معرّف | Unique |
| type | string(20) | لا | 'select' | نوع السمة | select/color/text/number |
| sort_order | int | لا | 0 | ترتيب | |
| is_active | boolean | لا | true | مُفعّل | |

### `product_images` — صور المنتج
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| product_id | BIGINT | لا | — | المنتج | FK→products (CASCADE) |
| variant_id | BIGINT | نعم | NULL | المتغيّر | FK→product_variants (CASCADE) |
| path | string(255) | لا | — | مسار الصورة | |
| alt | string(200) | نعم | NULL | نص بديل | |
| sort_order | int | لا | 0 | ترتيب | |
| is_primary | boolean | لا | false | الصورة الأساسية | واحدة لكل منتج |

### `product_tags` — وسوم المنتج
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| name | string(100) | لا | — | اسم الوسم | |
| slug | string(120) | لا | — | معرّف | Unique |
| is_active | boolean | لا | true | مُفعّل | M:N مع products عبر product_tag_links |

### `product_variants` — متغيّرات المنتج
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique |
| product_id | BIGINT | لا | — | المنتج | FK→products (CASCADE) |
| sku | string(60) | لا | — | رمز المخزون | Unique |
| barcode | string(60) | نعم | NULL | باركود | Unique عند الوجود |
| name | string(200) | نعم | NULL | اسم المتغيّر | |
| cost_price | decimal(15,4) | لا | 0.0000 | تكلفة آخر شراء | ADR-006 |
| average_cost | decimal(15,4) | لا | 0.0000 | WAC (آلي) | ADR-005 |
| retail_price | decimal(15,2) | لا | 0.00 | سعر التجزئة | |
| wholesale_price | decimal(15,2) | نعم | NULL | سعر الجملة | |
| marketer_price | decimal(15,2) | نعم | NULL | أساس عمولة المسوّق | |
| min_price | decimal(15,2) | نعم | NULL | أدنى بيع مسموح | حدّ صارم ADR-006a |
| promo_price | decimal(15,2) | نعم | NULL | سعر ترويجي | |
| weight | decimal(15,3) | نعم | NULL | الوزن | |
| reorder_level | decimal(15,3) | نعم | NULL | حدّ إعادة الطلب | |
| is_default | boolean | لا | false | المتغيّر الافتراضي | |
| is_active | boolean | لا | true | مُفعّل | |
| deleted_at | timestamp | نعم | NULL | حذف ناعم | Soft delete + Auditable |

### `products` — المنتجات
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique (ADR-002) |
| branch_id | BIGINT | نعم | NULL | الفرع | FK→branches (SET NULL) |
| category_id | BIGINT | لا | — | الفئة | FK→categories (RESTRICT) |
| brand_id | BIGINT | نعم | NULL | العلامة | FK→brands (SET NULL) |
| unit_id | BIGINT | لا | — | الوحدة | FK→units (RESTRICT) |
| tax_id | BIGINT | نعم | NULL | الضريبة | FK→taxes (SET NULL)؛ وإلا افتراضي الإعدادات |
| name | string(200) | لا | — | اسم المنتج | |
| slug | string(220) | لا | — | معرّف URL | Unique |
| sku | string(60) | لا | — | رمز المخزون | Unique (فريد عام ADR-004) |
| barcode | string(60) | نعم | NULL | باركود | Index |
| type | string(20) | لا | 'simple' | النوع | simple/variable |
| short_description | string(500) | نعم | NULL | وصف مختصر | |
| description | text | نعم | NULL | وصف | |
| cost_price | decimal(15,4) | لا | 0.0000 | تكلفة آخر شراء | ADR-006 |
| average_cost | decimal(15,4) | لا | 0.0000 | WAC (آلي) | ADR-005 |
| retail_price | decimal(15,2) | لا | 0.00 | تجزئة | |
| wholesale_price | decimal(15,2) | نعم | NULL | جملة | |
| marketer_price | decimal(15,2) | نعم | NULL | أساس عمولة المسوّق | |
| min_price | decimal(15,2) | نعم | NULL | أدنى بيع مسموح | حدّ صارم ADR-006a |
| promo_price | decimal(15,2) | نعم | NULL | ترويجي | |
| promo_starts_at | timestamp | نعم | NULL | بداية الترويج | |
| promo_ends_at | timestamp | نعم | NULL | نهاية الترويج | |
| target_margin | decimal(8,4) | نعم | NULL | هامش مستهدف | للتنبيه فقط |
| weight | decimal(15,3) | نعم | NULL | الوزن | |
| reorder_level | decimal(15,3) | نعم | NULL | حدّ إعادة الطلب | |
| track_inventory | boolean | لا | true | تتبّع المخزون | |
| is_active | boolean | لا | true | مُفعّل | |
| deleted_at | timestamp | نعم | NULL | حذف ناعم | Soft delete + Auditable؛ رؤية التكلفة بـ pricing.view_cost (ADR-013) |

### `shipping_zones` — مناطق الشحن
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique |
| branch_id | BIGINT | نعم | NULL | الفرع | FK→branches (SET NULL) |
| name | string(120) | لا | — | اسم المنطقة | |
| code | string(30) | لا | — | رمز | Unique |
| description | text | نعم | NULL | وصف | |
| is_active | boolean | لا | true | مُفعّل | |
| deleted_at | timestamp | نعم | NULL | حذف ناعم | Soft delete + Auditable؛ M:N مع cities/areas |

### `stock_adjustments` — تسويات الجرد (رأس)
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique |
| number | string(30) | لا | — | رقم التسوية | `ADJ-{YYYY}-{seq}`؛ Unique |
| branch_id | BIGINT | نعم | NULL | الفرع | FK→branches (SET NULL) |
| warehouse_id | BIGINT | لا | — | المستودع | FK→warehouses (RESTRICT) |
| type | string(20) | لا | 'recount' | نوع التسوية | increase/decrease/recount |
| reason | string(150) | نعم | NULL | السبب | |
| status | string(25) | لا | 'draft' | الحالة | مقترح OQ-P2-2 |
| notes | text | نعم | NULL | ملاحظات | |
| created_by | BIGINT | نعم | NULL | المُنشئ | FK→users (SET NULL) |
| approved_by | BIGINT | نعم | NULL | المعتمِد | FK→users (SET NULL) |
| approved_at | timestamp | نعم | NULL | وقت الاعتماد | |
| posted_at | timestamp | نعم | NULL | وقت التطبيق | |
| deleted_at | timestamp | نعم | NULL | حذف ناعم | Soft delete + Auditable |

### `stock_reservations` — حجوزات المخزون
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique |
| variant_id | BIGINT | لا | — | المتغيّر | FK→product_variants (RESTRICT) |
| warehouse_id | BIGINT | لا | — | المستودع | FK→warehouses (RESTRICT) |
| order_id | BIGINT | نعم | NULL | الطلب | **مؤجّل Phase 3 — بلا FK الآن** |
| order_item_id | BIGINT | نعم | NULL | بند الطلب | مؤجّل Phase 3 |
| reference_type | string(60) | نعم | NULL | نوع المرجع | polymorphic (بديل عام) |
| reference_id | BIGINT | نعم | NULL | معرّف المرجع | |
| qty | decimal(15,3) | لا | — | الكمية المحجوزة | |
| status | string(20) | لا | 'active' | الحالة | active/released/consumed/expired (OQ-P2-3) |
| reserved_at | timestamp | لا | — | وقت الحجز | |
| expires_at | timestamp | نعم | NULL | انتهاء الحجز | |
| released_at | timestamp | نعم | NULL | وقت التحرير | |
| created_by | BIGINT | نعم | NULL | المُنشئ | FK→users (SET NULL)؛ Auditable |

### `supplier_contacts` — جهات اتصال المورد
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| supplier_id | BIGINT | لا | — | المورد | FK→suppliers (CASCADE) |
| name | string(150) | لا | — | اسم جهة الاتصال | |
| position | string(100) | نعم | NULL | المنصب | |
| email | string(150) | نعم | NULL | البريد | |
| phone | string(30) | نعم | NULL | الهاتف | |
| is_primary | boolean | لا | false | جهة أساسية | واحدة كحدّ أقصى |
| notes | text | نعم | NULL | ملاحظات | |

### `suppliers` — الموردون
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique (ADR-002) |
| branch_id | BIGINT | نعم | NULL | الفرع | FK→branches (SET NULL) |
| name | string(150) | لا | — | اسم المورد | |
| code | string(30) | لا | — | رمز المورد | Unique |
| legal_name | string(200) | نعم | NULL | الاسم القانوني | |
| tax_number | string(50) | نعم | NULL | الرقم الضريبي | |
| email | string(150) | نعم | NULL | البريد | |
| phone | string(30) | نعم | NULL | الهاتف | |
| address | string(255) | نعم | NULL | العنوان | |
| governorate_id | BIGINT | نعم | NULL | المحافظة | FK→governorates (SET NULL) |
| city_id | BIGINT | نعم | NULL | المدينة | FK→cities (SET NULL) |
| currency_id | BIGINT | نعم | NULL | عملة التعامل | FK→currencies (SET NULL) |
| payment_terms_days | int | لا | 0 | مهلة السداد (يوم) | |
| credit_limit | decimal(15,2) | لا | 0.00 | حدّ الائتمان | |
| opening_balance | decimal(15,2) | لا | 0.00 | رصيد افتتاحي | |
| notes | text | نعم | NULL | ملاحظات | |
| is_active | boolean | لا | true | مُفعّل | |
| deleted_at | timestamp | نعم | NULL | حذف ناعم | Soft delete + Auditable |

### `taxes` — الضرائب
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| name | string(80) | لا | — | اسم الضريبة | |
| code | string(30) | نعم | NULL | رمز | Unique عند الوجود |
| rate | decimal(8,4) | لا | 15.0000 | النسبة (%) | |
| type | string(20) | لا | 'exclusive' | النوع | inclusive/exclusive |
| is_default | boolean | لا | false | الافتراضية | |
| is_active | boolean | لا | true | مُفعّلة | Auditable |

### `units` — وحدات القياس
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| name | string(60) | لا | — | اسم الوحدة | |
| name_en | string(60) | نعم | NULL | الاسم الإنجليزي | |
| code | string(20) | لا | — | رمز | Unique |
| symbol | string(20) | نعم | NULL | الرمز | |
| base_unit_id | BIGINT | نعم | NULL | الوحدة الأساس | FK→units self (SET NULL) |
| conversion_factor | decimal(15,6) | لا | 1.000000 | معامل التحويل | |
| is_active | boolean | لا | true | مُفعّلة | |

### `warehouse_locations` — مواقع التخزين
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| warehouse_id | BIGINT | لا | — | المستودع | FK→warehouses (CASCADE) |
| parent_id | BIGINT | نعم | NULL | الموقع الأب | FK→self (SET NULL) |
| code | string(40) | لا | — | رمز الموقع | Unique(warehouse_id,code) |
| name | string(120) | نعم | NULL | الاسم | |
| type | string(20) | لا | 'bin' | النوع | zone/rack/shelf/bin |
| is_active | boolean | لا | true | مُفعّل | |

### `warehouse_transfers` — التحويلات بين المستودعات (رأس)
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique |
| number | string(30) | لا | — | رقم التحويل | `TRF-{YYYY}-{seq}`؛ Unique |
| branch_id | BIGINT | نعم | NULL | الفرع | FK→branches (SET NULL) |
| from_warehouse_id | BIGINT | لا | — | المصدر | FK→warehouses (RESTRICT) |
| to_warehouse_id | BIGINT | لا | — | الوجهة | FK→warehouses (RESTRICT)؛ ≠ from |
| status | string(25) | لا | 'draft' | الحالة | مقترح OQ-P2-1 |
| notes | text | نعم | NULL | ملاحظات | |
| created_by | BIGINT | نعم | NULL | المُنشئ | FK→users (SET NULL) |
| approved_by | BIGINT | نعم | NULL | المعتمِد | FK→users (SET NULL) |
| shipped_at | timestamp | نعم | NULL | وقت الشحن | |
| received_at | timestamp | نعم | NULL | وقت الاستلام | |
| deleted_at | timestamp | نعم | NULL | حذف ناعم | Soft delete + Auditable |

### `warehouses` — المستودعات
| العمود | النوع | Null | افتراضي | المعنى | قيود/ملاحظات |
|--------|------|:----:|--------|--------|--------------|
| uuid | char(36) | لا | مُولّد | معرّف خارجي | Unique (ADR-002) |
| branch_id | BIGINT | لا | — | الفرع | FK→branches (RESTRICT) |
| name | string(120) | لا | — | اسم المستودع | |
| code | string(30) | لا | — | رمز | Unique(branch_id,code) |
| type | string(20) | لا | 'main' | النوع | main/transit/virtual/damaged |
| governorate_id | BIGINT | نعم | NULL | المحافظة | FK→governorates (SET NULL) |
| city_id | BIGINT | نعم | NULL | المدينة | FK→cities (SET NULL) |
| address | string(255) | نعم | NULL | العنوان | |
| phone | string(30) | نعم | NULL | الهاتف | |
| allow_negative | boolean | لا | false | السماح بالرصيد السالب | ADR-007a |
| is_default | boolean | لا | false | الافتراضي | |
| is_active | boolean | لا | true | مُفعّل | |
| deleted_at | timestamp | نعم | NULL | حذف ناعم | Soft delete + Auditable |

---

## 3. الجداول المساعدة (Pivot / Children — خارج الـ27)

### `shipping_zone_city` / `shipping_zone_area`
`shipping_zone_id` FK→shipping_zones (CASCADE) · `city_id`/`area_id` FK (CASCADE) · Unique(zone, city/area).

### `variant_attribute_value`
`variant_id` FK→product_variants (CASCADE) · `attribute_value_id` FK→product_attribute_values (CASCADE) · Unique(variant,value).

### `product_tag_links`
`product_id` FK→products (CASCADE) · `product_tag_id` FK→product_tags (CASCADE) · Unique(product,tag).

### `stock_adjustment_items`
`stock_adjustment_id` FK→stock_adjustments (CASCADE) · `variant_id` FK→product_variants (RESTRICT) · `qty_before` decimal(15,3) · `qty_counted` decimal(15,3) · `qty_diff` decimal(15,3) · `unit_cost` decimal(15,4) · `note`.

### `warehouse_transfer_items`
`warehouse_transfer_id` FK→warehouse_transfers (CASCADE) · `variant_id` FK→product_variants (RESTRICT) · `qty_requested` decimal(15,3) · `qty_shipped` decimal(15,3) default 0 · `qty_received` decimal(15,3) default 0 · `unit_cost` decimal(15,4).

---

## 4. مسرد المصطلحات (Glossary)

| المصطلح | التعريف |
|--------|---------|
| **WAC (المتوسط المرجّح المتحرّك)** | طريقة تقييم المخزون المعتمدة (ADR-005). عند كل استلام: `WAC = (قيمة المخزون الحالية + التكلفة المُحمّلة للوارد) / (الكمية الحالية + الواردة)`. تُخزَّن في `average_cost`. |
| **Landed Cost (التكلفة المُحمّلة)** | تكلفة الشراء + توزيع الشحن/الاستيراد/الجمارك على الأصناف (حسب القيمة/الوزن/الكمية — `purchasing.landed_allocation`). أساس تحديث WAC. |
| **COGS (تكلفة البضاعة المباعة)** | تُحتسب بـ WAC وقت الخصم النهائي (الشحن — Phase 3). |
| **on_hand** | الكمية الموجودة ماديًا في المستودع. |
| **reserved** | كمية محجوزة لطلبات مؤكّدة (لا تمسّ on_hand حتى الشحن). |
| **available (المتاح للبيع)** | `on_hand − reserved` — **محسوب لا مخزَّن**. الدلاء damaged/returned_pending/in_transit لا تُحتسب. |
| **damaged** | تالف خارج البيع. |
| **returned_pending** | مرتجع بانتظار الفحص قبل تصنيفه صالح/تالف. |
| **in_transit** | كمية قيد التحويل بين المستودعات. |
| **Ledger (الدفتر) مقابل Movement (الحركة)** | الحركة = الحدث الخام (نيّة: وارد/صادر/تحويل...). الدفتر = قيد append-only لكل تغيّر كمية/قيمة مع رصيد جارٍ وWAC. كل حركة تُنتج قيد/قيود دفتر داخل نفس المعاملة. |
| **Reservation (الحجز)** | تخصيص كمية لطلب (يزيد reserved)؛ يُحرَّر (release) أو يُستهلك (شحن). بنيته في Phase 2، استهلاكه Phase 3 (ADR-009). |
| **Shipping Zone (منطقة الشحن)** | تجميعة تسعير/تغطية مستقلة عن التسلسل الإداري، تُربط بمدن/مناطق كثير-لكثير (ADR-014). |
| **Variant (المتغيّر)** | تركيبة قابلة للبيع/التخزين من منتج (مقاس×لون). المخزون يُمسك لكل متغيّر×مستودع. |
| **Attribute مقابل Tag** | السمة (Attribute) محور تنويع منظّم يولّد متغيّرات (اللون/المقاس). الوسم (Tag) تسمية تسويقية حرّة للبحث/التصنيف بلا تأثير على المتغيّرات. |
| **Price Tiers (طبقات الأسعار)** | مجموعة أسعار لكل منتج/متغيّر: cost_price, average_cost, retail_price, wholesale_price, marketer_price, min_price, promo_price (ADR-006). |
| **min_price** | أدنى سعر بيع مسموح — حدّ صارم؛ التجاوز يتطلب صلاحية `pricing.override_min_price` وموافقة (ADR-006a). |
| **Human Number (الرقم المقروء)** | رقم عمل مُهيّأ: `TRF-{YYYY}-{seq}` للتحويل، `ADJ-{YYYY}-{seq}` للتسوية — يُولّد في معاملة مع قفل (ADR-002). |
| **Branch مقابل Warehouse** | الفرع وحدة تنظيمية؛ المستودع موقع مادّي للمخزون يتبع فرعًا واحدًا (ADR-003). |
| **Polymorphic reference** | `reference_type`+`reference_id` تسمح لأي مصدر مستقبلي (طلب/شحنة/أمر شراء) بالارتباط دون تعديل بنية الدفتر/الحركة. |

---

## 5. القيم المعدودة القانونية (Enumerated Values)

> **قاعدة إلزامية (DECISIONS):** لا اختراع مفاتيح خارج هذه القوائم دون تحديث `DECISIONS.md` أولًا.

### أنواع الحركة `inventory_movements.type` / `inventory_ledger.movement_type` (ADR-008)
`purchase_in` · `sale_out` · `transfer_out` · `transfer_in` · `adjustment_in` · `adjustment_out` · `reserve` · `release` · `return_in` · `damage_out`

### دلاء المخزون `bucket` (ADR-007)
`on_hand` · `reserved` · `damaged` · `returned_pending` · `in_transit`  — و`available` محسوب (`on_hand − reserved`).

### حالات أمر الشراء (Purchase Order — مرجع Phase 4، DECISIONS)
`draft → pending_approval → approved → partially_received → received → closed` + `cancelled`

### حالات فاتورة المورد (Phase 4، DECISIONS)
`draft → pending_approval → approved → partially_paid → paid` + `cancelled`

### أنواع محلّية لجداول Phase 2
| الحقل | القيم | الحالة |
|------|------|--------|
| `products.type` / `product_variants` سياق | `simple` · `variable` | ثابت |
| `product_attributes.type` | `select` · `color` · `text` · `number` | ثابت |
| `warehouses.type` | `main` · `transit` · `virtual` · `damaged` | ثابت |
| `warehouse_locations.type` | `zone` · `rack` · `shelf` · `bin` | ثابت |
| `taxes.type` | `inclusive` · `exclusive` | ADR-015 |
| `stock_adjustments.type` | `increase` · `decrease` · `recount` | ثابت |
| `stock_reservations.status` | `active` · `released` · `consumed` · `expired` | **مقترح OQ-P2-3** |
| `stock_adjustments.status` | `draft` · `pending_approval` · `approved` · `posted` · `cancelled` | **مقترح OQ-P2-2** |
| `warehouse_transfers.status` | `draft` · `pending_approval` · `approved` · `in_transit` · `partially_received` · `received` · `cancelled` | **مقترح OQ-P2-1** |

> حالات `stock_*`/`warehouse_transfers` غير مُعرّفة في المفردات القانونية بعد؛ مقترحة هنا وتحتاج اعتمادًا في `DECISIONS.md` (ADR-017).

---

*هذا القاموس والوثيقة `PHASE_2_DESIGN.md` مصدرا الحقيقة لتنفيذ هجرات المرحلة 2. أي تعارض يُحسم لصالح `DECISIONS.md`.*

</div>
