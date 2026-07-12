<?php

namespace App\Support\Contracts\Messaging;

/**
 * عقد مزوّد المراسلة (المبدأ 13، ADR-030). أي قناة (WhatsApp/Email/SMS/Marketing) خلف هذا العقد + Driver.
 * لا يُستدعى مزوّد مباشرةً من متحكم/خدمة أعمال — فقط عبر طبقة التكامل (تكامل مستقبلي).
 */
interface MessagingProviderInterface
{
    /**
     * إرسال رسالة عبر قناة إلى مستلِم.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed> نتيجة الإرسال (status/reference/...)
     */
    public function send(string $channel, string $to, string $message, array $meta = []): array;

    public function name(): string;
}
