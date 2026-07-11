# CLAUDE.md

إرشادات العمل داخل هذا المستودع لأي مساعد ذكاء اصطناعي (Claude Code) أو مطوّر.

## عن المشروع

**Tawfeer Online (توفير أونلاين)** — منصة أعمال متكاملة تجمع **ERP + CRM + متجر إلكتروني** في نظام واحد. راجع `README.md` و`REQUIREMENTS.md` للنطاق الكامل.

## التقنيات

- **الخلفية:** Laravel 11 (PHP 8.2+)
- **قاعدة البيانات:** MySQL 8
- **الواجهة:** Blade + Livewire/Alpine.js + Tailwind CSS (RTL)
- **الصلاحيات:** spatie/laravel-permission
- **الطوابير:** Laravel Queue (+ Redis)
- **اللغة:** عربي (RTL) أساسي + إنجليزي

## قواعد أساسية

1. **لا تبدأ البرمجة قبل اعتماد المرحلة.** ننفّذ مرحلة واحدة في كل مرة حسب `PROJECT_PLAN.md`.
2. **RTL أولًا:** كل واجهة تُبنى عربية من اليمين لليسار، وكل النصوص عبر ملفات اللغة (`lang/ar`, `lang/en`) — لا نصوص مضمّنة (hardcoded).
3. **معايير الكود:** اتبع اصطلاحات Laravel وPSR-12. استخدم `php artisan` للتوليد.
4. **بنية الوحدات (Modules):** افصل المنطق حسب المجال (Catalog, Orders, Accounting...).
5. **الأمان:** لا تُسرّب أسرارًا. كل الأسرار في `.env` (غير متعقّب). تحقّق من المدخلات واستخدم Form Requests.
6. **قاعدة البيانات:** كل تغيير عبر migration. عدّل `DATABASE_DESIGN.md` عند تغيير المخطط.
7. **الاختبارات:** أضف اختبارات (Pest/PHPUnit) للمنطق المهم قبل اعتبار المهمة مكتملة.
8. **الوثائق:** حدّث الملف ذا الصلة (`REQUIREMENTS.md` / `PROJECT_PLAN.md` / `DATABASE_DESIGN.md`) مع أي تغيير جوهري.

## Git

- **الفرع:** طوّر على `claude/tawfeer-online-setup-2ooosk` (لا تدفع لفرع آخر دون إذن).
- **الالتزامات (Commits):** رسائل واضحة ووصفية. التزام واحد لكل وحدة عمل منطقية.
- **الدفع:** `git push -u origin claude/tawfeer-online-setup-2ooosk`.
- لا تنشئ Pull Request إلا بطلب صريح.

## أوامر شائعة (بعد بدء التطوير)

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve         # الخادم
npm run dev               # الأصول
php artisan test          # الاختبارات
./vendor/bin/pint         # تنسيق الكود
```

## اصطلاحات

- **النماذج (Models):** مفرد (`Product`, `Order`).
- **الجداول:** جمع (`products`, `orders`).
- **المتحكمات:** مورد (Resource controllers) حيثما أمكن.
- **التحقّق:** عبر Form Requests لا داخل المتحكم.
- **الصلاحيات:** تُفحص عبر Policies / middleware، لا شيكات يدوية متناثرة.
- **المبالغ المالية:** `decimal(15,2)`.

## سير العمل مع المراحل

1. اقرأ المرحلة الحالية في `PROJECT_PLAN.md`.
2. نفّذ نطاقها فقط.
3. تحقّق من معايير القبول.
4. التزم وادفع.
5. انتظر اعتماد المستخدم قبل المرحلة التالية.
