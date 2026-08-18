<?php

namespace Tests\Feature\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * سعة عمود `journal_entries.source`.
 *
 * SQLite لا يفرض أطوال `varchar` — يقبل واحدًا وعشرين حرفًا في عمودٍ سعتُه
 * عشرون ولا يشتكي. فمرّت `import_shipment_close` خضراء في كل الاختبارات
 * وسقطت على MySQL وحده بخطأ 500 عند أول إغلاق شحنة في الإنتاج.
 *
 * هذا الاختبار يسدّ الفجوة: يقرأ السعة المعلنة في المخطّط، ويقيس عليها كل
 * قيمة `source` حرفية في الملفات التي تُنشئ قيودًا — فيقع الخطأ هنا لا هناك.
 */
class JournalSourceLengthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * سعة العمود كما في الهجرة `widen_journal_entry_source`.
     *
     * مكتوبةٌ هنا لأن SQLite يعلن `varchar` بلا طول، فلا سعة تُقرأ منه أصلًا؛
     * وحين يعمل الاختبار على MySQL تُقارَن بالمخطّط الحقيقي فلا تفترقان صامتتين.
     */
    private const CAPACITY = 40;

    public function test_every_journal_source_fits_the_column(): void
    {
        $declared = $this->declaredLength('journal_entries', 'source');

        if ($declared !== null) {
            $this->assertSame(self::CAPACITY, $declared, 'سعة العمود في المخطّط تخالف المعلنة في الاختبار.');
        }

        $capacity = self::CAPACITY;

        foreach ($this->declaredSources() as $file => $sources) {
            foreach ($sources as $source) {
                $this->assertLessThanOrEqual(
                    $capacity,
                    strlen($source),
                    "المصدر «{$source}» في {$file} أطول من سعة العمود ({$capacity}) — MySQL سيرفضه."
                );
            }
        }
    }

    /** السعة المعلنة للعمود، مقروءةً من نوعه في المخطّط (`varchar(40)`). */
    private function declaredLength(string $table, string $column): ?int
    {
        foreach (Schema::getColumns($table) as $col) {
            if ($col['name'] === $column && preg_match('/\((\d+)\)/', (string) $col['type'], $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * قيم `source` الحرفية في الملفات التي تستدعي محرّك القيود وحدها —
     * فـ«source» كلمةٌ شائعة في غيرها ولا تخصّ هذا العمود.
     *
     * @return array<string, array<int, string>>
     */
    private function declaredSources(): array
    {
        $found = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            if (! str_contains($code, 'postEntry(') && ! str_contains($code, 'createEntry(')) {
                continue;
            }

            if (preg_match_all("/'source' => '([^']+)'/", $code, $m)) {
                $found[str_replace(base_path().'/', '', $file->getPathname())] = array_unique($m[1]);
            }
        }

        $this->assertNotEmpty($found, 'لم يُعثر على أي مصدر قيد — الفحص فقد هدفه.');

        return $found;
    }
}
