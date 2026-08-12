<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Services\RemoteImageImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * سحب صور المنتجات من الموقع القديم وربطها بالأصناف — من ملف CSV بعمودين
 * (اسم الصنف/كوده + رابط الصورة). يعرض المطابقة أولًا، ولا ينزّل شيئًا إلا بـ--force.
 */
class ImportProductImagesCommand extends Command
{
    protected $signature = 'catalog:import-images
        {file : مسار ملف CSV (اسم الصنف, رابط الصورة)}
        {--force : تنفيذ التنزيل فعليًا (بدونه معاينة فقط)}
        {--overwrite : ربط الصورة حتى لو كان للصنف صور مسبقًا}';

    protected $description = 'تنزيل صور المنتجات من روابط خارجية وربطها بالأصناف (تُحوَّل إلى WebP تلقائيًا)';

    public function handle(RemoteImageImportService $service): int
    {
        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("تعذّر قراءة الملف: {$file}");

            return self::FAILURE;
        }

        ['rows' => $rows, 'errors' => $errors] = $service->parse($file);

        $this->line('');
        $this->info(sprintf('أسطر مطابقة: %d   |   أسطر متخطّاة: %d', count($rows), count($errors)));

        if ($errors !== []) {
            $this->line('');
            $this->warn('الأسطر المتخطّاة:');
            foreach (array_slice($errors, 0, 40) as $e) {
                $this->line('  • '.$e);
            }
            if (count($errors) > 40) {
                $this->line(sprintf('  … و%d سطرًا آخر.', count($errors) - 40));
            }
        }

        if ($rows !== []) {
            $this->line('');
            $this->table(['الصنف', 'الرابط'], collect($rows)->take(15)
                ->map(fn ($r) => [$r['product']->name, Str::limit($r['url'], 70)])->all());
            if (count($rows) > 15) {
                $this->line(sprintf('  … و%d صورة أخرى.', count($rows) - 15));
            }
        }

        if (! $this->option('force')) {
            $this->line('');
            $this->warn('معاينة فقط — لم يُنزَّل شيء. أضف ‎--force‎ للتنفيذ.');

            return self::SUCCESS;
        }

        $this->line('');
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        $result = $service->import($rows, skipIfHasImages: ! $this->option('overwrite'));

        $bar->finish();
        $this->line('');
        $this->line('');
        $this->info(sprintf('رُبطت %d صورة.', $result['attached']));

        foreach ($result['failed'] as $f) {
            $this->warn('  ⚠ '.$f);
        }

        return self::SUCCESS;
    }
}
