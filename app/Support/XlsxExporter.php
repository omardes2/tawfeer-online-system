<?php

namespace App\Support;

use Closure;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * تصدير جداول الإدارة إلى **xlsx** — المصدر الوحيد لصيغة الملف.
 *
 * ## لماذا لا CSV
 *
 * الـCSV ليس صيغةً بل عُرْف: Excel يفتحه بترميز النظام لا UTF-8، فالعربية تصل
 * مربّعاتٍ ما لم يُسبَق بـBOM؛ ويقرأ `0599...` رقمًا فيبتلع الصفر الأول؛ ويقصّ
 * الحقل عند أول فاصلةٍ في نصٍّ عربي. وxlsx يحمل ترميزه ونوعَ كل خلية في داخله،
 * فلا يحتاج حيلةً ولا يُفسد رقمًا.
 *
 * ## والبثّ صفًّا صفًّا
 *
 * الكشوف تبلغ آلاف الأسطر، فتُكتب على القرص عبر `openToFile` ثم تُرسَل: الكتابة
 * إلى المتصفّح مباشرةً تصطدم بمخازن PHP-FPM وتُنتج ملفًّا مقطوعًا. والملف المؤقّت
 * يُحذف بعد الإرسال (`deleteFileAfterSend`).
 */
final class XlsxExporter
{
    /**
     * ردّ تنزيلٍ يبني الملف من مُولِّدٍ للصفوف.
     *
     * @param  string  $filename  اسم الملف بلا امتداد
     * @param  array<int, string>  $head  ترويسة الأعمدة
     * @param  Closure(): iterable<int, array<int, mixed>>  $rows  مُولِّد الصفوف
     * @param  array<int, array<int, mixed>>  $preamble  أسطرٌ تعريفية قبل الترويسة
     */
    public static function download(string $filename, array $head, Closure $rows, array $preamble = []): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($preamble as $line) {
            $writer->addRow(Row::fromValues(self::normalize($line)));
        }
        if ($preamble !== []) {
            $writer->addRow(Row::fromValues([]));
        }

        $writer->addRow(Row::fromValuesWithStyle(self::normalize($head), (new Style)->withFontBold(true)));

        foreach ($rows() as $row) {
            $writer->addRow(Row::fromValues(self::normalize($row)));
        }

        $writer->close();

        return response()->download($path, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * الخلايا كما يجب أن تصل Excel.
     *
     * الأرقام تُرسَل أرقامًا لا نصوصًا — وإلا لم تُجمَع في الورقة ولا تُرتَّب.
     * والفراغ يُرسَل نصًّا فارغًا: `null` يُنتج خليةً بلا نوع تربك بعض القارئات.
     *
     * @param  array<int, mixed>  $row
     * @return array<int, mixed>
     */
    private static function normalize(array $row): array
    {
        return array_map(static fn ($cell) => match (true) {
            $cell === null => '',
            is_float($cell), is_int($cell) => $cell,
            default => (string) $cell,
        }, array_values($row));
    }
}
