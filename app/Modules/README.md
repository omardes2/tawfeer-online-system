<div dir="rtl">

# الوحدات (Modules)

تطبيقًا للمبدأ 14 في [`ARCHITECTURE.md`](../../ARCHITECTURE.md)، يُقسَّم منطق المجال إلى وحدات مستقلة قدر الإمكان لتسهيل التطوير والاختبار.

## البنية

كل وحدة تحت `app/Modules/{Module}/` وقد تحوي:

```
Modules/
└── Foundation/            ← وحدة الأساس (المرحلة 1)
    ├── Models/            ← نماذج المجال (Branch, Setting, AuditLog, *Status)
    ├── Services/          ← الخدمات (SettingsManager, Settings facade)
    ├── Observers/         ← مراقبو النماذج (عند الحاجة)
    └── Providers/         ← مزوّد خدمة الوحدة (FoundationServiceProvider)
```

الوحدات القادمة (Catalog, Inventory, Orders, Purchasing, Accounting, CRM, Affiliate, Messaging, Promotions, Reports) تتبع نفس النمط، ولكل منها مزوّد خدمة يُسجَّل في `bootstrap/providers.php`.

## قواعد

- **الاستقلالية:** الوحدة لا تعتمد على تفاصيل داخلية لوحدة أخرى؛ التواصل عبر **الخدمات/العقود والأحداث (Events)**.
- **الأسس المشتركة** (تريتات UUID/Audit/Transaction، العقود) في `app/Support/`، لا داخل وحدة بعينها.
- **الهجرات** مركزية حاليًا في `database/migrations` (تُحمَّل تلقائيًا)، مع تسمية واضحة لكل وحدة.
- **التسجيل:** كل وحدة تُسجّل مزوّد خدمتها لربط خدماتها/عقودها.

## طبقة الدعم المشتركة (`app/Support`)

| المسار | الغرض |
|--------|-------|
| `Concerns/HasUuid` | معرّف خارجي UUID (المبدأ 4) |
| `Concerns/Auditable` | تدقيق آلي (المبدأ 8) |
| `Concerns/RunsInTransaction` | معاملات ذرّية (المبدأ 7) |
| `Contracts/PaymentGateway` | عقد بوابة الدفع (المبدأ 13) |
| `Integrations/Payment/*` | تنفيذات (Drivers) خلف العقد |

</div>
