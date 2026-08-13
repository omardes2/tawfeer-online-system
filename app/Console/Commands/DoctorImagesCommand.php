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
        $healthy = $this->checkStorageLink();
        $healthy = $this->checkFiles() && $healthy;

        $this->line('');
        $this->line($healthy ? '<info>✔ لا مشكلة ظاهرة في الصور.</info>' : '<comment>راجع النقاط أعلاه.</comment>');

        return self::SUCCESS;
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
