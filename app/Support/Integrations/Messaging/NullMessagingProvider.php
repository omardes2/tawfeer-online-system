<?php

namespace App\Support\Integrations\Messaging;

use App\Support\Contracts\Messaging\MessagingProviderInterface;

/**
 * Driver افتراضي "لا مزوّد" للمراسلة — placeholder آمن (لا شبكة) حتى ربط WhatsApp/Email/SMS لاحقًا.
 */
class NullMessagingProvider implements MessagingProviderInterface
{
    public function send(string $channel, string $to, string $message, array $meta = []): array
    {
        return ['status' => 'skipped', 'channel' => $channel, 'driver' => $this->name()];
    }

    public function name(): string
    {
        return 'null';
    }
}
