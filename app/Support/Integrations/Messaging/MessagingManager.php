<?php

namespace App\Support\Integrations\Messaging;

use App\Support\Contracts\Messaging\MessagingProviderInterface;
use Illuminate\Contracts\Container\Container;

/**
 * مصنع مزوّدي المراسلة (ADR-030، المبدأ 13). النقطة الوحيدة لإنشاء مزوّد قناة.
 * يربط القناة (whatsapp/email/sms/marketing) بصنف Driver من config/messaging.php.
 * إضافة مزوّد = إدخال في الإعداد + صنف ينفّذ العقد، دون لمس منطق الأعمال.
 */
class MessagingManager
{
    public function __construct(private readonly Container $app) {}

    public function for(string $channel): MessagingProviderInterface
    {
        $driver = config("messaging.channels.{$channel}", config('messaging.default'));
        $class = config("messaging.drivers.{$driver}", config('messaging.drivers.null'));

        return $this->app->make($class);
    }
}
