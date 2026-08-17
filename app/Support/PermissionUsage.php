<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * أيّ الصلاحيات مستعملة فعلًا في النظام، وأيّها بقايا.
 *
 * تراكمت في قاعدة البيانات صلاحياتٌ من مراحل سابقة استُبدلت بأدقّ منها
 * (`users.view` صارت `settings.users.view`، و`catalog.manage` تفرّقت على موارد
 * الكتالوج). بقاؤها معروضةً يضلّل موزّع الأدوار: يمنحها ظنًّا أنها تفتح شيئًا،
 * فلا تفتح، أو يمنعها فيظنّ أنه أغلق بابًا وهو مفتوح من صلاحية أخرى.
 *
 * **الفحص متساهل عمدًا.** بعض الصلاحيات تُركَّب أثناء التنفيذ:
 * `'accounting.'.$res.'.post'` — لا يراها بحثٌ عن نصٍّ كامل، ووسمُها «غير
 * مستعملة» ثم إخفاؤها كان سيُعطّل السندات لكل من ليس مدير نظام. فتُجمع **بادئات**
 * التركيب أيضًا، وكل صلاحية تبدأ بإحداها تُعدّ مستعملة. الخطأ المحتمل هنا أن
 * تبقى صلاحيةٌ ميتة ظاهرة — وهو أرخص بكثير من إخفاء صلاحية حيّة.
 */
class PermissionUsage
{
    private const CACHE_KEY = 'permissions.usage.v1';

    /** الأماكن التي تُستهلك فيها الصلاحيات — لا `database/` فهي مكان تعريفها. */
    private const SCANNED = ['app', 'routes', 'resources/views'];

    /**
     * ما ورد في الكود، في ثلاث سلال:
     *
     * - `exact`: أي نصٍّ على هيئة مفتاح صلاحية — يشمل ذكرها بيانًا في مصفوفة
     *   (بطاقات لوحة التحكّم، بنود القائمة الجانبية) لا فحصًا. يُستعمل لتقرير
     *   «مستعملة» لأن التساهل هنا آمن.
     * - `prefixes`: بادئات تُوصَل بمتغيّر أثناء التنفيذ.
     * - `checked`: ما يُفحَص فعلًا في سياق تفويض (`can` وأخواتها) — وحده يصلح
     *   لكشف الناقص، لأن أي نصٍّ آخر قد يكون اسم مسار أو مفتاح إعداد لا صلاحية.
     *
     * @return array{exact: array<int, string>, prefixes: array<int, string>, checked: array<int, string>}
     */
    public static function references(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            $exact = [];
            $prefixes = [];
            $checked = [];

            $finder = (new Finder)->files()->name('*.php')
                ->in(array_map(base_path(...), self::SCANNED))
                // هذا الملف نفسه يذكر أمثلةً على هيئة مفاتيح داخل تعليقاته،
                // فقراءتُه لنفسه تُنتج صلاحياتٍ وهمية في التقرير.
                ->notPath('Support/PermissionUsage.php');

            foreach ($finder as $file) {
                /** @var SplFileInfo $file */
                $code = (string) file_get_contents($file->getRealPath());

                // نصّ كامل بصيغة مفتاح صلاحية: 'a.b' أو 'a.b.c'.
                preg_match_all('/[\'"]([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,3})[\'"]/', $code, $m);
                $exact = array_merge($exact, $m[1]);

                // وسيط المسار: can:a.b.c وcan:a.b|c.d
                preg_match_all('/can:([a-z0-9_.,|]+)/', $code, $mw);
                foreach ($mw[1] as $list) {
                    foreach (preg_split('/[,|]/', $list) ?: [] as $part) {
                        // منتهٍ بنقطة = بادئة يُكمّلها متغيّر (`'can:reports.'.$x`)،
                        // لا اسم صلاحية. إدراجها في «المفحوص» يُبلّغ عن صلاحيةٍ
                        // ناقصة لا وجود لها أصلًا.
                        if (str_ends_with($part, '.')) {
                            $prefixes[] = $part;

                            continue;
                        }

                        $exact[] = $part;
                        $checked[] = $part;
                    }
                }

                // فحصُ تفويضٍ صريح — وحده يدلّ على صلاحية يعتمد عليها الكود.
                preg_match_all(
                    '/(?:->can|->cannot|@can|@cannot|@canany|->authorize|authorize|hasPermissionTo|hasAnyPermission|Gate::allows|Gate::denies)\s*\(\s*[\'"]([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,3})[\'"]/',
                    $code, $ck,
                );
                $checked = array_merge($checked, $ck[1]);

                // بادئة تُوصَل بمتغيّر: 'accounting.' . $res . '.post'
                preg_match_all('/[\'"]([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\.)[\'"]\s*\./', $code, $pre);
                $prefixes = array_merge($prefixes, $pre[1]);
            }

            return [
                'exact' => array_values(array_unique(array_filter($exact))),
                'prefixes' => array_values(array_unique(array_filter($prefixes))),
                'checked' => array_values(array_unique(array_filter($checked))),
            ];
        });
    }

    /**
     * هل لهذه الصلاحية أثرٌ في الكود؟
     *
     * @param  array{exact: array<int, string>, prefixes: array<int, string>}|null  $refs
     */
    public static function isUsed(string $permission, ?array $refs = null): bool
    {
        $refs ??= self::references();

        return in_array($permission, $refs['exact'], true)
            || Str::startsWith($permission, $refs['prefixes']);
    }

    /**
     * الصلاحيات التي لا أثر لها في الكود.
     *
     * @param  iterable<int, string>  $permissions
     * @return array<int, string>
     */
    public static function unused(iterable $permissions): array
    {
        $refs = self::references();
        $unused = [];

        foreach ($permissions as $permission) {
            if (! self::isUsed($permission, $refs)) {
                $unused[] = $permission;
            }
        }

        sort($unused);

        return $unused;
    }

    /**
     * صلاحياتٌ يفحصها الكود ولا وجود لها في قاعدة البيانات.
     *
     * أخطر من الميتة: الفحص يفشل دائمًا، فتُغلق شاشةٌ في وجه الجميع بلا رسالة —
     * ولا يلاحظها مديرُ النظام إن كان يتخطّى الفحص.
     *
     * @param  iterable<int, string>  $existing
     * @return array<int, string>
     */
    public static function missing(iterable $existing): array
    {
        $known = collect($existing)->all();
        $refs = self::references();

        // ما يُفحَص فعلًا وحده: أي نصٍّ آخر على الهيئة نفسها قد يكون اسم مسار أو
        // مفتاح إعداد، فإدراجه هنا يُغرق التقرير بضجيجٍ يُفقده معناه.
        $missing = collect($refs['checked'])
            ->reject(fn (string $key) => in_array($key, $known, true))
            ->values()->all();

        sort($missing);

        return $missing;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
