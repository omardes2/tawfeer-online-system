<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * إدارة وسائط المنتج: رفع إلى قرص public، وضمان صورة أساسية واحدة.
 */
class ProductImageService
{
    /**
     * الحدّ الأقصى لأطول ضلع (px) حسب دور الصورة — ضِعف مقاس العرض في الموقع ليبقى
     * حادًّا على شاشات الريتينا دون حمل زائد:
     *   • المصغّرة تُعرض 300×300  ⇒ 600
     *   • صور الألبوم تُعرض 800×800 ⇒ 1600
     * الصورة الأصغر من الحدّ تبقى كما هي (لا تكبير يفسد الجودة).
     */
    private const MAX_EDGE_THUMBNAIL = 600;

    private const MAX_EDGE_GALLERY = 1600;

    /** جودة WebP (0-100): توازن بين حجم الملف ووضوح الصورة. */
    private const WEBP_QUALITY = 82;

    public function store(Product $product, UploadedFile $file, array $meta = []): ProductImage
    {
        return DB::transaction(function () use ($product, $file, $meta) {
            // أول صورة للمنتج تصبح أساسية تلقائيًا.
            $makePrimary = ($meta['is_primary'] ?? false) || ! $product->images()->exists();

            // تحويل إلى WebP بحدّ أبعاد يناسب دور الصورة (المصغّرة أصغر من صور الألبوم)
            // — أخفّ حجمًا مع بقاء الوضوح، فتبقى صفحات الموقع سريعة.
            $path = $this->storeAsWebp($file, $makePrimary ? self::MAX_EDGE_THUMBNAIL : self::MAX_EDGE_GALLERY);

            $image = $product->images()->create([
                'path' => $path,
                'alt' => $meta['alt'] ?? null,
                'sort_order' => $meta['sort_order'] ?? 0,
                'is_primary' => $makePrimary,
            ]);

            if ($makePrimary) {
                $this->promote($product, $image);
            }

            return $image;
        });
    }

    public function setPrimary(Product $product, ProductImage $image): ProductImage
    {
        DB::transaction(function () use ($product, $image) {
            $image->update(['is_primary' => true]);
            $this->promote($product, $image);
        });

        return $image->refresh();
    }

    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            $product = $image->product;
            $wasPrimary = $image->is_primary;

            Storage::disk('public')->delete($image->path);
            $image->delete();

            // إن كانت الأساسية، رقّي أقدم صورة متبقّية.
            if ($wasPrimary && $next = $product->images()->orderBy('sort_order')->first()) {
                $next->update(['is_primary' => true]);
            }
        });
    }

    /**
     * تصفير الأساسية عن بقية صور المنتج.
     */
    private function promote(Product $product, ProductImage $image): void
    {
        $product->images()->whereKeyNot($image->id)->where('is_primary', true)->update(['is_primary' => false]);
    }

    /**
     * يحفظ الصورة المرفوعة بصيغة WebP على قرص public ويعيد مسارها النسبي.
     * يُصغّر الأبعاد الكبيرة ويحافظ على الشفافية. عند تعذّر التحويل (امتداد GD
     * غير متاح أو ملف تالف) يعود لحفظ الأصل كما هو دون تعطيل الرفع.
     */
    private function storeAsWebp(UploadedFile $file, int $maxEdge): string
    {
        $image = $this->createGdImage($file);
        if ($image === null) {
            return $this->storeOriginal($file); // fallback: حفظ الأصل
        }

        $image = $this->downscale($image, $maxEdge);

        $path = 'products/'.Str::random(40).'.webp';
        ob_start();
        $ok = imagewebp($image, null, self::WEBP_QUALITY);
        $binary = ob_get_clean();
        imagedestroy($image);

        if (! $ok || $binary === false || $binary === '') {
            return $this->storeOriginal($file); // fallback
        }

        // قرص `public` مضبوط على `throw => false`، فالكتابة الفاشلة (قرص ممتلئ أو
        // صلاحيات) تعود بـfalse بصمت. بلا هذا الفحص يُنشأ سجلّ صورة بلا ملف،
        // فتظهر صورة مكسورة بدل رسالة خطأ مفهومة.
        if (! Storage::disk('public')->put($path, $binary)) {
            throw new \RuntimeException(__('تعذّر حفظ الصورة على القرص. تحقّق من المساحة وصلاحيات مجلّد storage.'));
        }

        return $path;
    }

    /** حفظ الملف كما هو مع التحقّق من نجاح الكتابة. */
    private function storeOriginal(UploadedFile $file): string
    {
        $path = $file->store('products', 'public');

        if ($path === false || $path === '') {
            throw new \RuntimeException(__('تعذّر حفظ الصورة على القرص. تحقّق من المساحة وصلاحيات مجلّد storage.'));
        }

        return $path;
    }

    /**
     * ينشئ مورد صورة GD من الملف المرفوع حسب نوعه، أو null إن تعذّر ذلك.
     *
     * @return \GdImage|null
     */
    private function createGdImage(UploadedFile $file)
    {
        if (! function_exists('imagewebp')) {
            return null;
        }

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            return null;
        }

        $image = match ($file->getMimeType()) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => @imagecreatefromjpeg($realPath),
            'image/png' => @imagecreatefrompng($realPath),
            'image/webp' => @imagecreatefromwebp($realPath),
            'image/gif' => @imagecreatefromgif($realPath),
            default => false,
        };

        if ($image === false) {
            return null;
        }

        // الحفاظ على الشفافية (PNG/WebP) في ملف WebP الناتج.
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * يُصغّر الصورة إذا تجاوز أطول ضلع الحدّ الأقصى، مع الحفاظ على النسبة والشفافية.
     * يعيد نفس المورد إن لم تكن هناك حاجة للتصغير.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function downscale($image, int $maxEdge)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $maxEdge) {
            return $image;
        }

        $scale = $maxEdge / $longest;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }
}
