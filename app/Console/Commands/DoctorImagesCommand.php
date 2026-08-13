<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * تشخيص صور المنتجات: لماذا لا تظهر؟
 *
 * سببان يعطيان العَرَض نفسه (مربّع مكسور في الصفحة): وصلة `public/storage` مفقودة
 * فلا تُخدَم الملفات، أو سجلّ صورة بلا ملف على القرص. هذا الأمر يفرّق بينهما بدل
 * التخمين.
 */
class DoctorImagesCommand extends Command
{
    protected $signature = 'catalog:doctor-images {--prune : حذف سجلّات الصور التي لا ملف لها}';

    protected $description = 'فحص صور المنتجات: وصلة التخزين، والملفات المفقودة';

    public function handle(): int
    {
        $this->line('');
        $healthy = $this->checkAppUrl();
        $healthy = $this->checkStorageLink() && $healthy;
        $healthy = $this->checkFiles() && $healthy;

        $this->line('');
        $this->line($healthy ? '<info>✔ لا مشكلة ظاهرة في الصور.</info>' : '<comment>راجع النقاط أعلاه.</comment>');

        return self::SUCCESS;
    }

    /**
     * `APP_URL` هو ما يُبنى عليه رابط كل صورة. إن بقي على اسم المضيف الافتراضي
     * للاستضافة أو على localhost، فالملف موجود والرابط يشير إلى نطاق آخر —
     * فتظهر الصورة مكسورة بينما كل شيء آخر سليم.
     */
    private function checkAppUrl(): bool
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        $suspicious = ['localhost', '127.0.0.1', '::1'];
        $defaultHostingSuffixes = ['.hstgr.cloud', '.cloudwaysapps.com', '.herokuapp.com', '.ondigitalocean.app'];

        $looksDefault = in_array($host, $suspicious, true)
            || collect($defaultHostingSuffixes)->contains(fn ($s) => str_ends_with($host, $s));

        if ($looksDefault) {
            $this->error('✘ APP_URL يشير إلى '.$host.' — وهو ليس نطاق الموقع.');
            $this->line('  كل روابط الصور تُبنى عليه، فتُطلَب من نطاق آخر وتظهر مكسورة.');
            $this->line('  الحلّ: اضبط APP_URL في .env على نطاق الموقع، ثم:  php artisan config:clear');

            return false;
        }

        if (! str_starts_with($url, 'https://')) {
            $this->warn('⚠ APP_URL ليس https — المتصفّح يحجب الصور كمحتوى مختلط على صفحة آمنة: '.$url);

            return false;
        }

        $this->info('✔ APP_URL: '.$url);

        return true;
    }

    /** وصلة public/storage — بدونها تُحفظ الصور ولا تُخدَم. */
    private function checkStorageLink(): bool
    {
        $link = public_path('storage');
        $target = storage_path('app/public');

        if (! file_exists($link)) {
            $this->error('✘ وصلة public/storage غير موجودة — الصور محفوظة لكنها لا تُخدَم على الويب.');
            $this->line('  الحلّ:  php artisan storage:link');

            return false;
        }

        if (is_link($link) && realpath(readlink($link)) !== realpath($target)) {
            $this->error('✘ وصلة public/storage تشير إلى مسار خاطئ: '.readlink($link));
            $this->line('  الحلّ:  php artisan storage:link --force');

            return false;
        }

        $this->info('✔ وصلة public/storage سليمة.');

        return true;
    }

    /** سجلّات الصور مقابل الملفات الفعلية. */
    private function checkFiles(): bool
    {
        $disk = Storage::disk('public');
        $total = ProductImage::count();

        if ($total === 0) {
            $this->line('• لا توجد صور مسجّلة بعد.');

            return true;
        }

        $missing = ProductImage::with('product:id,name')->get()
            ->filter(fn (ProductImage $image) => ! $disk->exists($image->path));

        if ($missing->isEmpty()) {
            $this->info(sprintf('✔ كل الصور المسجّلة (%d) لها ملفات على القرص.', $total));

            return true;
        }

        $this->error(sprintf('✘ %d من %d صورة لا ملف لها على القرص:', $missing->count(), $total));
        foreach ($missing->take(10) as $image) {
            $this->line('  • '.($image->product?->name ?? '—').'  ←  '.$image->path);
        }
        if ($missing->count() > 10) {
            $this->line(sprintf('  … و%d أخرى.', $missing->count() - 10));
        }

        if ($this->option('prune')) {
            ProductImage::whereKey($missing->pluck('id'))->delete();
            $this->warn(sprintf('حُذفت %d سجلًّا بلا ملف — أعد رفع صور هذه الأصناف.', $missing->count()));
        } else {
            $this->line('  أضف ‎--prune‎ لحذف هذه السجلّات وإعادة الرفع من جديد.');
        }

        return false;
    }
}
