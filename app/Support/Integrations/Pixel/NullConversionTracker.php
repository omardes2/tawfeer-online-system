<?php

namespace App\Support\Integrations\Pixel;

use App\Support\Contracts\Pixel\ConversionTrackerInterface;

/**
 * المحرّك الافتراضي: لا يقيس شيئًا ولا يفشل.
 *
 * يمرّ بهدوء بخلاف محرّك الكتابة الذي يرمي: هذا قياسٌ لا إنفاق، وفقدُ حدثٍ
 * يُضعف التحسين ولا يُتلف شيئًا — أمّا «أوقفتُ الإعلان» وهو لم يتوقّف فكذبة.
 */
class NullConversionTracker implements ConversionTrackerInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function track(ConversionEvent $event): void
    {
        // لا شيء عمدًا.
    }
}
