<div dir="rtl">

# النشر والتشغيل — Tawfeer Online

دليل تهيئة بيئات التطوير والإنتاج ونشر المنصة.

> 📌 **دليل الإنتاج الكامل (Hostinger VPS · AlmaLinux 9 · cPanel):**
> راجع [`docs/PRODUCTION_DEPLOYMENT.md`](docs/PRODUCTION_DEPLOYMENT.md) —
> الخطوات التفصيلية للنشر، مع [`docs/OPERATIONS.md`](docs/OPERATIONS.md)
> (النسخ الاحتياطي/الاسترجاع/الفهارس) و[`docs/ACCEPTANCE.md`](docs/ACCEPTANCE.md)
> (خريطة قبول سير العمل) و`.env.production.example` وملفّات `deploy/`.

---

## 1. المتطلبات الأساسية

| الأداة | الإصدار |
|--------|---------|
| PHP | 8.3+ (مُختبَر على 8.4) |
| Composer | 2.x |
| MySQL | 8.x |
| Node.js | 20.x LTS |
| Redis | 7.x (للطوابير/الكاش — اختياري في التطوير) |
| خادم الويب | Nginx (موصى به) أو Apache |

---

## 2. بيئة التطوير المحلية

```bash
# 1) استنساخ المشروع
git clone https://github.com/omardes2/tawfeer-online-system.git
cd tawfeer-online-system

# 2) الاعتماديات
composer install
npm install

# 3) الإعداد
cp .env.example .env
php artisan key:generate

# 4) اضبط اتصال قاعدة البيانات في .env ثم:
php artisan migrate --seed

# 5) التشغيل
php artisan serve      # http://127.0.0.1:8000
npm run dev            # مراقبة الأصول
```

---

## 3. متغيّرات البيئة الأساسية (`.env`)

```dotenv
APP_NAME="Tawfeer Online"
APP_ENV=local            # production في الإنتاج
APP_KEY=                 # يُولّد بـ php artisan key:generate
APP_DEBUG=true           # false في الإنتاج
APP_URL=http://127.0.0.1:8000
APP_LOCALE=ar
APP_FALLBACK_LOCALE=en

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tawfeer
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=redis   # أو database
CACHE_STORE=redis        # أو file
SESSION_DRIVER=database

# تكاملات (تُضاف عند تنفيذ وحداتها)
# WHATSAPP_TOKEN=
# MESSENGER_TOKEN=
# INSTAGRAM_TOKEN=
# PAYMENT_GATEWAY_KEY=
```

> 🔐 لا تُضِف أي أسرار حقيقية للمستودع. `.env` غير متعقّب في Git؛ استخدم `.env.example` للقيم النموذجية فقط.

---

## 4. النشر للإنتاج

### 4.1 تجهيز الخادم
- تثبيت PHP-FPM 8.2+ مع الإضافات المطلوبة (`bcmath`, `ctype`, `mbstring`, `pdo_mysql`, `openssl`, `tokenizer`, `xml`).
- إعداد Nginx ليخدم مجلد `public/`.
- تأمين MySQL بمستخدم مخصّص للتطبيق.

### 4.2 خطوات النشر
```bash
git pull origin main            # أو الفرع المعتمد للإنتاج
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

# إعادة تشغيل عمّال الطوابير
php artisan queue:restart
```

### 4.3 المهام المجدولة والطوابير
- **Scheduler:** أضف Cron:
  ```
  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
  ```
- **Queue Worker:** شغّله عبر Supervisor:
  ```ini
  [program:tawfeer-worker]
  command=php /path/to/app/artisan queue:work redis --sleep=3 --tries=3
  autostart=true
  autorestart=true
  numprocs=2
  ```

### 4.4 إعدادات الإنتاج المهمة
- `APP_ENV=production` و`APP_DEBUG=false`.
- HTTPS إلزامي (شهادة SSL).
- صلاحيات المجلدات: `storage/` و`bootstrap/cache/` قابلة للكتابة.
- نسخ احتياطي دوري لقاعدة البيانات و`storage/`.

---

## 5. الأمان قبل الإطلاق

- [ ] `APP_DEBUG=false` وإخفاء رسائل الأخطاء التفصيلية.
- [ ] كل الأسرار في متغيّرات البيئة لا في الكود.
- [ ] HTTPS مفعّل و HSTS.
- [ ] نسخ احتياطي آلي ومختبَر للاستعادة.
- [ ] صلاحيات الأدوار مراجَعة.
- [ ] تحديثات الأمان للاعتماديات (`composer audit` / `npm audit`).
- [ ] حدود المعدّل (Rate Limiting) على نقاط الـ API.

---

## 6. CI/CD (مُخطَّط للمرحلة 11)

خط تكامل مستمر مقترح عبر GitHub Actions:
1. تثبيت الاعتماديات.
2. تشغيل `pint` (تنسيق) و`php artisan test` (اختبارات).
3. بناء الأصول (`npm run build`).
4. النشر التلقائي عند الدمج في فرع الإنتاج.

---

## 7. المراقبة (Production)

- سجلّات Laravel (`storage/logs`) + خدمة تجميع سجلات.
- مراقبة الأخطاء (مثل Sentry) — اختياري.
- مراقبة صحّة الطوابير وقاعدة البيانات.

</div>
