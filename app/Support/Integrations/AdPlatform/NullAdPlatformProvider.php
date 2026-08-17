<?php

namespace App\Support\Integrations\AdPlatform;

use App\Support\Contracts\AdPlatform\AdPlatformProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * المحرّك الافتراضي الآمن: لا اتصال ولا نتائج.
 *
 * يبقى النظام كاملًا بلا ربط — الصرف يُدخَل يدويًّا كما كان، والمهمّة المجدولة
 * تمرّ بهدوء بلا فشل ليليّ.
 */
class NullAdPlatformProvider implements AdPlatformProviderInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function dailyInsights(Carbon $from, Carbon $to): Collection
    {
        return collect();
    }
}
