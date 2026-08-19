{{--
    انتهاء صلاحية الصفحة (419).

    يقع حين تُترك الصفحة مفتوحة حتى تنتهي الجلسة ثم يُضغط «حفظ»: رمز الحماية
    (CSRF) لم يعد صالحًا. وكان Laravel يعرض صفحةً إنجليزية بيضاء تقول
    «Page Expired» بلا سببٍ ولا مخرج — فيظنّ الموظف أن النظام تعطّل.

    الصفحة قائمةٌ بذاتها بلا تخطيط ولا أصول مبنيّة: تُعرَض لمن انقطعت جلسته،
    وربما لم يعد مسجَّلًا، فأيّ اعتمادٍ على المستخدم الحالي يكسرها في اللحظة
    التي يُفترض أن تشرح فيها الخطأ.
--}}
@php
    $isAdmin = request()->is('admin/*') || request()->is('admin');
    $loginUrl = $isAdmin
        ? (\Illuminate\Support\Facades\Route::has('login') ? route('login') : url('/login'))
        : (\Illuminate\Support\Facades\Route::has('account.login') ? route('account.login') : url('/account/login'));
    $back = url()->previous();
    $store = \App\Modules\Foundation\Services\Settings::get('store.name', 'توفير أونلاين');
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('انتهت صلاحية الصفحة') }} — {{ $store }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            background: #f8fafc; color: #0f172a; padding: 1.5rem;
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
        }
        .card {
            width: 100%; max-width: 34rem; background: #fff; border-radius: 1rem;
            box-shadow: 0 1px 3px rgb(15 23 42 / 8%), 0 8px 24px rgb(15 23 42 / 6%);
            padding: 2rem; text-align: center;
        }
        .badge {
            display: grid; place-items: center; width: 3.5rem; height: 3.5rem; margin: 0 auto 1rem;
            border-radius: 999px; background: #fffbeb; color: #b45309; font-size: 1.75rem;
        }
        h1 { margin: 0 0 .5rem; font-size: 1.4rem; }
        p { margin: 0 0 .75rem; color: #475569; line-height: 1.9; font-size: .95rem; }
        .hint { color: #64748b; font-size: .85rem; background: #f1f5f9; border-radius: .6rem; padding: .75rem 1rem; }
        .actions { display: flex; flex-wrap: wrap; gap: .6rem; justify-content: center; margin-top: 1.5rem; }
        a.btn {
            display: inline-block; padding: .6rem 1.25rem; border-radius: .6rem;
            text-decoration: none; font-size: .9rem; font-weight: 600;
        }
        .primary { background: #059669; color: #fff; }
        .secondary { background: #e2e8f0; color: #0f172a; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">⏳</div>
        <h1>{{ __('انتهت صلاحية الصفحة') }}</h1>
        <p>
            {{ __('بقيت الصفحة مفتوحة حتى انتهت جلستك، فلم يُقبَل الإرسال حمايةً لحسابك. لم يحدث خطأٌ في النظام ولم يُحفَظ شيء.') }}
        </p>
        <p class="hint">
            {{ __('إن كنت تملأ نموذجًا: افتح صفحةً أخرى وسجّل الدخول، ثم عُد إلى هذه الصفحة بزرّ الرجوع — يبقى ما كتبتَه غالبًا كما هو، فتُرسله من جديد.') }}
        </p>
        <div class="actions">
            <a class="btn primary" href="{{ $loginUrl }}">{{ __('تسجيل الدخول') }}</a>
            @if ($back && $back !== url()->current())
                <a class="btn secondary" href="{{ $back }}">{{ __('العودة للصفحة السابقة') }}</a>
            @endif
        </div>
    </div>
</body>
</html>
