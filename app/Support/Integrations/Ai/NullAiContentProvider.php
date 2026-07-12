<?php

namespace App\Support\Integrations\Ai;

use App\Support\Contracts\Ai\AiContentProviderInterface;
use App\Support\Contracts\Ai\AiContentRequest;
use App\Support\Contracts\Ai\AiContentResult;
use Illuminate\Support\Str;

/**
 * مزوّد افتراضي بلا شبكة (Phase 6 / ADR-044) — قوالب مسوّدة من المدخلات فقط.
 * آمن (لا اتصال خارجي) ويُستخدم كبديل عند غياب مزوّد حقيقي أو فشله.
 * **لا يخترع مواصفات** — يعيد صياغة المدخلات المُعطاة فقط.
 */
class NullAiContentProvider implements AiContentProviderInterface
{
    public function generate(AiContentRequest $request): AiContentResult
    {
        $content = $this->draft($request);

        return new AiContentResult(
            content: $content,
            model: 'null-template',
            promptTokens: (int) ceil(mb_strlen(json_encode($request->inputs)) / 4),
            completionTokens: (int) ceil(mb_strlen($content) / 4),
            status: 'success',
        );
    }

    private function draft(AiContentRequest $request): string
    {
        $in = $request->inputs;
        $ar = $request->locale === 'ar';
        $name = trim((string) ($in['name'] ?? ''));
        $brand = trim((string) ($in['brand'] ?? ''));
        $category = trim((string) ($in['category'] ?? ''));
        $notes = trim((string) ($in['notes'] ?? ''));
        $specs = (array) ($in['specifications'] ?? []);
        $head = trim($name.' '.$brand);

        $text = match ($request->type) {
            'title_ar' => $name !== '' ? $name.($brand ? ' — '.$brand : '') : ($ar ? 'عنوان المنتج' : 'Product title'),
            'title_en' => $head !== '' ? Str::title($head) : 'Product title',
            'short_description' => $ar
                ? (trim($head.' '.($category ? 'ضمن '.$category.'. ' : '').($notes ?: '')) ?: 'وصف مختصر للمنتج.')
                : (trim($head.' '.($notes ?: '')) ?: 'A short product description.'),
            'description' => $ar
                ? $head."\n".($notes ?: '').($category ? "\nالفئة: ".$category : '')
                : $head."\n".($notes ?: ''),
            'features' => $this->bullets($specs, $notes, $ar),
            'specs' => $this->specsTable($specs, $ar),
            'seo_title' => Str::limit(trim($head.($category ? ' '.$category : '')), 60, ''),
            'meta_description' => Str::limit(trim($head.' '.($notes ?: '')), 155, ''),
            'keywords' => implode(', ', array_filter([$name, $brand, $category])),
            'social_caption' => ($ar ? '✨ ' : '✨ ').$head.($notes ? ' — '.Str::limit($notes, 80, '') : ''),
            'whatsapp' => ($ar ? 'مرحبًا! ' : 'Hello! ').$head.($notes ? "\n".Str::limit($notes, 120, '') : ''),
            default => $head,
        };

        // تعديلات الإجراء.
        return match ($request->action) {
            'shorten' => Str::limit($text, max(40, (int) (mb_strlen($text) / 2)), ''),
            'expand' => $text."\n".($ar ? '(مسوّدة قابلة للتوسيع بمراجعتك.)' : '(Draft — expand as you review.)'),
            default => $text,
        };
    }

    /** @param  array<int|string, mixed>  $specs */
    private function bullets(array $specs, string $notes, bool $ar): string
    {
        $lines = [];
        foreach ($specs as $k => $v) {
            $lines[] = '• '.(is_string($k) ? $k.': ' : '').(is_array($v) ? implode(', ', $v) : $v);
        }
        if ($notes) {
            $lines[] = '• '.$notes;
        }

        return $lines ? implode("\n", $lines) : ($ar ? '• (أضِف المزايا)' : '• (add features)');
    }

    /** @param  array<int|string, mixed>  $specs */
    private function specsTable(array $specs, bool $ar): string
    {
        $lines = [];
        foreach ($specs as $k => $v) {
            $lines[] = (is_string($k) ? $k : '-').' | '.(is_array($v) ? implode(', ', $v) : $v);
        }

        return $lines ? implode("\n", $lines) : ($ar ? '(لا مواصفات مُعطاة)' : '(no specs supplied)');
    }

    public function name(): string
    {
        return 'null';
    }
}
