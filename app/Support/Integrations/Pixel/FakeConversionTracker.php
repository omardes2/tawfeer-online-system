<?php

namespace App\Support\Integrations\Pixel;

use App\Support\Contracts\Pixel\ConversionTrackerInterface;

/**
 * محرّك قياس وهمي للاختبارات — يحفظ ما أُرسل بلا نداء شبكي.
 */
class FakeConversionTracker implements ConversionTrackerInterface
{
    /** @var array<int, ConversionEvent> */
    private static array $sent = [];

    private static bool $configured = true;

    public static function reset(bool $configured = true): void
    {
        self::$sent = [];
        self::$configured = $configured;
    }

    /** @return array<int, ConversionEvent> */
    public static function sent(): array
    {
        return self::$sent;
    }

    public static function first(?string $name = null): ?ConversionEvent
    {
        foreach (self::$sent as $event) {
            if ($name === null || $event->name === $name) {
                return $event;
            }
        }

        return null;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return self::$configured;
    }

    public function track(ConversionEvent $event): void
    {
        self::$sent[] = $event;
    }
}
