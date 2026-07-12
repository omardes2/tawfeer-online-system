<?php

namespace App\Support\Integrations\Messaging;

use App\Support\Contracts\Messaging\MessagingProviderInterface;

/**
 * مزوّد مراسلة وهمي للاختبارات (Phase 6 / ADR-046).
 *
 * **لا يرسل أي رسالة حقيقية** — يسجّل الإرسال في ذاكرة العملية للتحقّق داخل الاختبارات.
 * يُفعَّل عبر config/messaging (channels → 'fake') فلا يُلمس منطق أعمال. يُرجِع status=sent.
 */
class FakeMessagingProvider implements MessagingProviderInterface
{
    /** @var array<int, array<string, mixed>> */
    public static array $sent = [];

    public function send(string $channel, string $to, string $message, array $meta = []): array
    {
        self::$sent[] = compact('channel', 'to', 'message', 'meta');

        return ['status' => 'sent', 'reference' => 'fake-'.count(self::$sent)];
    }

    public static function reset(): void
    {
        self::$sent = [];
    }

    public function name(): string
    {
        return 'fake';
    }
}
