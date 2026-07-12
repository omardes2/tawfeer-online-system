# تصميم المرحلة 2.7 — الشحن والجغرافيا وطبقة تكامل مزوّدي التوصيل (Shipping)

> **الحالة:** مسودّة للاعتماد (Design-first). لا كود قبل اعتماد هذه الوثيقة.
> **المرجعية المُلزِمة:** `ADR-014` (الجغرافيا والشحن)، `ADR-010/010a` (دورة حياة الطلب/التسليم)،
> `ADR-017` (الحالات كبيانات)، `ADR-019` + المبدأ 13 (طبقة التكامل: عقد + Driver)، `ADR-020` (تدقيق/حذف ناعم)،
> `BR-ORD-10` (الحالات التشغيلية للتوصيل)، `BR-CUST-06` (العنوان يشير إلى area/city/governorate)،
> ومخطط الجغرافيا المجمّد في `PHASE_2_DESIGN §3–6`. **لا إعادة تصميم لنموذج الأعمال المعتمد.**

## 1. السياق والفجوة

- **لا مخطط جداول مجمّد للشحنات:** `API_CONTRACT §378` يضع `/shipments` في "Phase 3"؛ لا `shipments`/`carriers`
  في `PHASE_2_DESIGN`/`DATA_DICTIONARY`. المجمّد فقط: **مفردات حالة الشحن** المزروعة (`shipment_statuses`)
  وقواعد `ADR-014`/`BR-ORD-10`/`ADR-010`.
- **الجغرافيا مجمّدة لكنها غير مبنيّة:** `governorates`/`cities`/`areas`/`shipping_zones` (+ جدولا ربط) مُعرّفة
  تفصيليًا في `PHASE_2_DESIGN §3–6` (كانت ضمن "الدفعة 2A") لكنها **لم تُبنَ** في 2.1. الشحن يعتمد عليها.
- **تسعير الشحن مؤجّل صراحةً:** `PHASE_2_DESIGN §149` و`API_CONTRACT §187`: "التسعير الفعلي في Phase 3".

**قرار المالك (مُعتمد):** بناء الجغرافيا المجمّدة الآن **بحذافيرها** (§3–6) مع **حقول جاهزة للتكامل**
و**طبقة مزوّدين (Provider Layer)** للمزامنة مع واجهات شركات التوصيل مستقبلًا — مع **إبقاء محرّك تسعير الشحن
الفعلي وأي تكامل مزوّد محدّد مؤجّلًا** لمرحلته. هذه الوثيقة تُنزِل ذلك إلى مخطط تنفيذي (ADR-027).

## 2. النطاق

**داخل النطاق (2.7):**
1. **الجغرافيا (§3–6 بحذافيرها):** `governorates` · `cities` · `areas` · `shipping_zones` + `shipping_zone_city` + `shipping_zone_area`.
2. **حقول جاهزة للتكامل** (مضافة دون تغيير النموذج) على الجداول الجغرافية ومناطق الشحن.
3. **`shipments`** (+ `shipment_events` سجلّ تتبّع append-only) مرتبطة بالطلبات؛ آلة حالات تعتمد `shipment_statuses`.
4. **طبقة تكامل التوصيل:** عقود `DeliveryProviderInterface` · `GeographySyncProviderInterface` · `ShippingQuoteProviderInterface` + **Null Drivers** + مُحلّل تكلفة (Resolver) بأولوية/احتياط. **بلا مزوّد حقيقي**.
5. **لقطة تكلفة الشحن** على الشحنة والطلب (لا تتغيّر تاريخيًا).
6. **ربط حالات الشحنة بدورة حياة الطلب** (out_for_delivery/delivered/delivery_failed — أكملت ما أجّلته 2.6).
7. API `/api/v1/shipping/*` + `/api/v1/geo/*` (قراءة) + واجهة إدارة RTL + RBAC + اختبارات.

**خارج النطاق (مؤجّل بخُطّافات):**
| البند | يؤجَّل إلى | الخُطّاف المُجهَّز الآن |
|-------|-----------|------------------------|
| **محرّك تسعير الشحن الفعلي** (أسعار مناطق/وزن/أبعاد) | Phase 3 (التسعير) | مُحلّل التكلفة + لقطة السعر؛ فرع "سعر المنطقة" مُعرّف بلا جدول أسعار الآن |
| **تكامل شركة توصيل محدّدة** (Aramex/SMSA…) | عند توفّر مفاتيح/توثيق | العقود + Null Drivers + `config/shipping.php` لاختيار Driver |
| مزامنة الجغرافيا الحيّة من مزوّد | مع المزوّد الفعلي | `GeographySyncProviderInterface` + حقول `external_*`/`last_synced_at`/`sync_status` |
| كيان العملاء/عناوينهم المتعددة (BR-CUST-06) | 2.10 CRM | الشحنة تحمل لقطة مستلِم + `area_id`/`city_id` FK حقيقية (الجغرافيا مبنيّة الآن) |
| المدفوعات/COD والتسوية | 2.8 | لا ربط دفع؛ `shipping_total` لقطة على الطلب |
| الاعتراف بالإيراد محاسبيًا عند التسليم | 2.9 | معلم `delivered_at` + حالة الطلب `delivered` (خُطّاف) |

## 3. الجغرافيا — تُبنى بحذافير §3–6 + حقول تكامل

تُنفَّذ الأعمدة والقيود والفهارس **تمامًا** كما في `PHASE_2_DESIGN §3–6` (بلا uuid/soft-delete/auditable
للمحافظات/المدن/المناطق؛ shipping_zones بـ uuid/soft-delete/auditable). **يُضاف فقط** طقم حقول التكامل التالي
إلى كلٍّ من `governorates`, `cities`, `areas`, `shipping_zones` (المصدر المحلي يبقى مرجع النظام — المتطلّب 1):

| الحقل | النوع | الغرض |
|------|------|-------|
| external_provider | string(40) nullable | اسم المزوّد المصدر (المزامن الحالي) |
| external_id | string(80) nullable | معرّف السجلّ لدى المزوّد (ربط ID لا اسمًا — المتطلّب 9) |
| external_code | string(80) nullable | رمز المزوّد (بديل/إضافي) |
| provider_metadata | json nullable | حمولة المزوّد الخام (تعيينات، إحداثيات، مناطق فرعية) |
| last_synced_at | timestamp nullable | آخر مزامنة ناجحة |
| sync_status | string(20) default `'local'` | `local`/`synced`/`pending`/`stale`/`conflict` |
| is_active | boolean | موجود أصلًا في §3–6 (يخدم التعطيل — المتطلّب 10) |

- **فهرس مساعد:** (`external_provider`,`external_id`) على المدن والمناطق ومناطق الشحن (بحث سريع للتعيين العكسي).
- **المتطلّب 5 (تعيين خارجي→محلي):** يتم عبر هذه الحقول على السجلّ المحلي؛ المزامنة تكتبها والمُحلّل يقرأ `external_id`
  (لا الاسم) عند الاستعلام من مزوّد. **المتطلّب 10 (تعطيل/استبدال مزوّد دون فقد بيانات):** حذف/تعطيل المزوّد =
  تصفير حقول `external_*` أو تعطيل مزامنته؛ الجغرافيا والطلبات المحلية (المصدر) تبقى سليمة.
- **الترقيم/UUID:** يلتزم §3–6 (المحافظات/المدن/المناطق مرجعية بلا uuid؛ shipping_zones بـ uuid).
- **بذور:** بذرة محافظات/مدن أساسية (كما ألمح §187 "البذور تُزرع إداريًا الآن") — مجموعة صغيرة معقولة قابلة للإدارة.

## 4. `shipments` — الشحنة (كيان جديد، مُصمَّم من القواعد المجمّدة)

كيان حسّاس: `HasUuid` + `SoftDeletes` + `Auditable`. ترقيم `SHP-{YYYY}-{seq}` عبر `NumberGenerator`.

| العمود | النوع | ملاحظات |
|--------|------|---------|
| id / uuid / number | — | number فريد |
| order_id | FK orders RESTRICT | الطلب المصدر (طلب واحد → شحنة واحدة في MVP؛ FK يسمح بالتعدّد لاحقًا) |
| branch_id | FK branches RESTRICT | متعدّد الفروع |
| warehouse_id | FK warehouses RESTRICT | مصدر الإرسال (من الطلب) |
| status | string(25) default `not_shipped` | مفتاح من `shipment_statuses` (ADR-017) |
| carrier_name | string(120) nullable | شركة التوصيل (يدوي الآن؛ يُملأ من المزوّد لاحقًا) |
| tracking_number | string(120) nullable | رقم التتبّع |
| **العنوان (لقطة + معرّفات مُعيَّنة — المتطلّب 9):** | | |
| recipient_name | string(180) | لقطة |
| recipient_phone | string(40) | لقطة |
| address_text | text nullable | لقطة نصّية |
| governorate_id / city_id / area_id | FK (SET NULL) nullable | معرّفات مُعيَّنة (الجغرافيا مبنيّة الآن) — لا يُحسب السعر من الاسم |
| shipping_zone_id | FK shipping_zones SET NULL nullable | المنطقة المُحلّة للتسعير |
| **التكلفة (لقطة — المتطلّب 8):** | | |
| shipping_cost | decimal(15,2) default 0 | التكلفة المطبّقة (لقطة ثابتة) |
| cost_source | string(20) default `pending` | `provider_live`/`provider_synced`/`zone`/`manual`/`pending` |
| cost_currency | string(3) nullable | عملة التكلفة (افتراضي من الإعدادات) |
| **التكامل:** | | |
| external_provider / external_id / external_code | string nullable | مرجع الشحنة لدى المزوّد |
| provider_metadata | json nullable | حمولة المزوّد (ملصق، تتبّع، حالة خام) |
| last_synced_at / sync_status | timestamp/string nullable | حالة مزامنة الشحنة |
| **المعالم الزمنية:** | | |
| dispatched_at / delivered_at / failed_at | timestamp nullable | معالم دورة الحياة |
| delivery_attempts | unsignedInt default 0 | عدّاد المحاولات (BR-ORD-10) |
| notes / failure_reason | text/string nullable | |
| created_by / timestamps / softDeletes | — | ADR-020 |

**فهارس:** `order_id`, `status`, `tracking_number`, (`external_provider`,`external_id`), `number`.

### 4.1 `shipment_events` — سجلّ تتبّع (append-only، بلا updated_at/soft-delete)
| العمود | النوع | ملاحظات |
|--------|------|---------|
| id / shipment_id (FK CASCADE) | — | |
| from_status / to_status | string(25) | الانتقال |
| source | string(20) default `manual` | `manual`/`provider`/`system` |
| note | string(255) nullable | |
| provider_payload | json nullable | حمولة حدث المزوّد (webhook مستقبلًا) |
| changed_by | FK users SET NULL nullable | |
| created_at | timestamp | لا updated_at |

## 5. مفردات حالة الشحن (Statuses)

يُوسّع `StatusSeeder` مجموعة `shipment_statuses` (القابلة للإدارة — ADR-017) لتغطّي جزء التوصيل من دورة حياة الطلب
(ADR-010) و`BR-ORD-10`:
`not_shipped → preparing → in_transit → out_for_delivery → delivered` + `delayed` · `customer_unavailable` · `failed`.
(المزروع سابقًا: not_shipped/preparing/in_transit/delivered/failed — يُضاف out_for_delivery/delayed/customer_unavailable.)

## 6. آلة حالات الشحنة وربطها بالطلب (`ShipmentService`)

الشحن **لا يكرّر** خصم المخزون؛ خصم `sale_out` يبقى في `OrderService::ship` (2.6). الشحنة **طبقة تتبّع/توصيل**
فوق طلب مشحون، وتُكمل الحالات التشغيلية التي أجّلتها 2.6.

```
(الطلب shipped عبر 2.6) → إنشاء shipment(not_shipped)
not_shipped → preparing → in_transit → out_for_delivery → delivered
                                     ↘ delayed / customer_unavailable ↗ (عودة)
                                     ↘ failed
```

| انتقال الشحنة | من → إلى | أثره على الطلب (عبر `OrderService`) |
|---------------|----------|--------------------------------------|
| `create` | — → not_shipped | يتطلّب الطلب في `shipped` (بعد خصم المخزون في 2.6) |
| `dispatch` | not_shipped/preparing → in_transit | يضبط `dispatched_at`؛ الطلب يبقى `shipped` |
| `outForDelivery` | in_transit → out_for_delivery | الطلب → `out_for_delivery` (كان مؤجّلًا في 2.6) |
| `markDelayed` / `markCustomerUnavailable` | in_transit/out_for_delivery → (سجلّ تشغيلي) | الطلب → `delayed`/`customer_unavailable` (BR-ORD-10)؛ لا إنهاء |
| `deliver` | out_for_delivery → delivered | الطلب → `delivered` (خُطّاف اعتراف الإيراد — 2.9)، `delivered_at` |
| `fail` | in_transit/out_for_delivery → failed | الطلب → `delivery_failed`؛ `failure_reason`؛ يفتح لاحقًا مسار إرجاع/إعادة جدولة |

- **إضافة إلى `OrderService`:** توابع `markOutForDelivery`/`markDelayed`/`markCustomerUnavailable`/`markDeliveryFailed`
  + السماح للتسليم من `out_for_delivery` (تنفيذ حالات ADR-010 التي عرّفتها 2.6 وأجّلت مساراتها). **لا تغيير**
  للحالات المالية/المخزونية في 2.6؛ فقط إكمال جزء التوصيل.
- كل انتقال داخل `DB::transaction` ويكتب `shipment_events` (+ تدقيق للحسّاس).
- منع الانتقالات غير القانونية (ValidationException).

## 7. طبقة تكامل مزوّدي التوصيل (Provider Layer — المبدأ 13/ADR-019)

بنمط `PaymentGateway`/`NullPaymentGateway` القائم. الموقع: `app/Support/Contracts/Shipping/*` و`app/Support/Integrations/Shipping/*`.

**العقود (Interfaces):**
- `DeliveryProviderInterface` — `createShipment(Shipment): array` · `track(string $tracking): array` · `cancel(string $ref): bool` · `name(): string`.
- `GeographySyncProviderInterface` — `pullGovernorates(): iterable` · `pullCities($govExternalId): iterable` · `pullAreas($cityExternalId): iterable` · `name(): string`.
- `ShippingQuoteProviderInterface` — `quote(ShippingQuoteRequest): ?ShippingQuote` (يعيد null عند عدم التوفّر) · `name(): string`.

**Drivers افتراضية (2.7):** `NullDeliveryProvider` · `NullGeographySyncProvider` · `NullShippingQuoteProvider`
— كلها آمنة تعيد "غير متوفّر" (بلا شبكة). **لا مزوّد حقيقي** (المتطلّب 4).

**الحقن والإعداد:** `ShippingServiceProvider` يربط العقود بالـNull Drivers عبر Container، ويقرأ الاختيار من
`config/shipping.php` (`shipping.provider=null` افتراضيًا). تبديل المزوّد = إضافة Driver + تغيير الإعداد،
دون لمس منطق الأعمال (المتطلّب 2 و10). **الوصول للمزوّد حصريًا عبر هذه الطبقة** — لا استدعاء مباشر من متحكم/نموذج.

## 8. مُحلّل تكلفة الشحن (Resolver) — الأولوية والاحتياط (المتطلّب 6/7/8)

`ShippingCostResolver::resolve(Shipment): ShippingCostResult` بترتيب صارم:
1. **عرض حيّ من المزوّد** (`ShippingQuoteProviderInterface::quote`) — في 2.7 يعيد Null Driver `null` ⇒ تخطٍّ.
2. **أحدث سعر مزوّد مُزامَن** — لا مزامنة بعد في 2.7 ⇒ تخطٍّ (البنية جاهزة عبر provider_metadata/last_synced_at).
3. **سعر المنطقة المحلي** — **محرّك التسعير مؤجّل (Phase 3)** ⇒ الفرع مُعرّف لكنه يعيد "غير مُهيّأ" في 2.7.
4. **تجاوز يدوي** بصلاحية `shipping.override_cost` ⇒ يُطبَّق ويُلقَّط.
5. وإلا **مراجعة يدوية**: `cost_source=pending`, `shipping_cost=0`, ويُعلَّم للمراجعة.

- **الفعّال في 2.7:** المستويان 1 (عبر Null ⇒ غير متوفّر) و4/5 (تجاوز يدوي أو مراجعة). المستويان 2/3 **مُعرّفان
  معماريًا ومؤجّلان تنفيذيًا** (لا محرّك تسعير — تأكيدًا لقرار المالك).
- **اللقطة (المتطلّب 8):** النتيجة تُكتب على `shipment.shipping_cost`/`cost_source` وتُنسخ إلى `order.shipping_total`
  وتُعاد بها `order.total`. لا يتغيّر طلب قائم إن تغيّرت أسعار المزوّد لاحقًا.
- **المتطلّب 9:** الاستعلام يستخدم `area_id`/`city_id` + `external_id` المُعيَّنة، **لا اسم المدينة**.

## 9. الصلاحيات (ADR-021)

- `shipping.shipments.{view, create, dispatch, deliver, fail, update}` (تتبّع/تشغيل الشحن).
- `shipping.override_cost` (تجاوز تكلفة الشحن يدويًا — حسّاسة).
- `settings.geography.{view, manage}` (إدارة الجغرافيا ومناطق الشحن؛ الكتابة الكاملة أُشير إليها للمرحلة 3 لكن
  الإدارة المحلية الأساسية تُتاح الآن للمدير — متّسق مع "البذور تُزرع إداريًا الآن").

**التوزيع (`ShippingPermissionSeeder`):** المدير/التنفيذي كامل؛ **المستودع** shipments.view/create/dispatch؛
**التوصيل/المبيعات** deliver/fail/dispatch حسب الحاجة؛ **المحاسب** view. `shipping.override_cost` للمدير فقط افتراضيًا.
(التفصيل النهائي يُثبَّت في التنفيذ ضمن نفس النمط.)

## 10. API والواجهة

- **قراءة الجغرافيا:** `GET /api/v1/geo/governorates|cities|areas|shipping-zones` (+ فلترة هرمية) — `settings.geography.view`.
- **الشحنات:** `GET/POST /api/v1/shipping/shipments`, `GET /{uuid}`, و`POST /{uuid}/{dispatch|out-for-delivery|deliver|fail|delay}` + تجاوز التكلفة.
- **واجهة إدارة RTL:** قائمة الجغرافيا (عرض/إدارة أساسية)، قائمة الشحنات + صفحة عرض بأزرار انتقالات التوصيل وسجلّ الأحداث؛ رابط تنقّل «الشحن».
- كل مسار محكوم بصلاحية دقيقة و`$this->authorize()` لكل إجراء (نمط 2.5/2.6).

## 11. الطبقات والملفات (اتّساقًا مع المراحل السابقة)

- **وحدة الجغرافيا:** ضمن `Foundation` (مرجعية) — نماذج `Governorate`/`City`/`Area`/`ShippingZone` + هجرات + بذرة.
- **وحدة الشحن:** `app/Modules/Shipping/{Models,Services,Policies,Providers}` — `Shipment`/`ShipmentEvent`, `ShipmentService`, `ShipmentPolicy`, `ShippingServiceProvider`.
- **التكامل:** `app/Support/Contracts/Shipping/*` (3 عقود + DTOs) و`app/Support/Integrations/Shipping/*` (3 Null Drivers) و`ShippingCostResolver`.
- **Form Requests / Resources / Controllers (API + Admin) / Routes / Nav** بنفس أنماط 2.5/2.6.
- **Seeders:** `GeographySeeder` (بذور أساسية) + `ShippingPermissionSeeder` + توسعة `StatusSeeder` (shipment_statuses).

## 12. الاختبارات (معايير القبول)

- بناء الجغرافيا (هرمية governorate→city→area؛ shipping_zone كثير-لكثير) + حقول التكامل موجودة وقابلة للكتابة.
- إنشاء شحنة لطلب `shipped` فقط؛ منع الإنشاء قبل الشحن.
- دورة التوصيل: dispatch→in_transit، outForDelivery→الطلب out_for_delivery، deliver→الطلب delivered، fail→الطلب delivery_failed.
- منع الانتقالات غير القانونية + تسجيل `shipment_events`.
- **لقطة التكلفة:** تجاوز يدوي بصلاحية يضبط cost_source=manual ويُحدّث order.shipping_total/total؛ بلا صلاحية يُرفض؛ بلا مصدر ⇒ pending.
- المُحلّل يتخطّى المستويات المؤجّلة ويسقط إلى اليدوي/المراجعة (Null Drivers تعيد "غير متوفّر").
- التفويض: المستودع لا يستطيع deliver إن قُصر عليه؛ المحاسب قراءة؛ override للمدير فقط.
- واجهة إدارة RTL تُصيَّر.

## 13. ما لا تفعله هذه المرحلة (تأكيد)

لا محرّك تسعير شحن فعلي (أسعار مناطق/وزن)، لا تكامل شركة توصيل محدّدة، لا مزامنة حيّة، لا مدفوعات/COD،
لا قيود محاسبية، لا كيان عملاء/عناوين متعددة. كلٌّ في مرحلته، بعقود وحقول وخُطّافات مُجهَّزة أعلاه.

---

### مقترح إضافة إلى `DECISIONS.md`: ADR-027 — مخطط الشحن وطبقة تكامل التوصيل (Phase 2.7)
يلخّص: بناء الجغرافيا المجمّدة (§3–6) + حقول تكامل (`external_*`/`provider_metadata`/`last_synced_at`/`sync_status`)؛
كيان `shipments`(+`shipment_events`) بلقطة عنوان ومعرّفات مُعيَّنة ولقطة تكلفة؛ آلة حالات شحن تُكمل جزء التوصيل من
ADR-010/BR-ORD-10 وتُزامن حالة الطلب؛ طبقة مزوّدين (3 عقود + Null Drivers) بنمط المبدأ 13؛ مُحلّل تكلفة بأولوية
(حيّ→مُزامَن→منطقة→يدوي→مراجعة) مع لقطة ثابتة؛ **تأجيل** محرّك التسعير الفعلي وأي مزوّد محدّد ومزامنة حيّة لمرحلتها.
