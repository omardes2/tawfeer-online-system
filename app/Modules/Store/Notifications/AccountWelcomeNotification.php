<?php

namespace App\Modules\Store\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * إشعار ترحيبي معامَلاتي داخل التطبيق (Phase 3.4 / ADR-035) — قناة database فقط.
 * القنوات الخارجية (WhatsApp/Email/Push) تُوجَّه مستقبلًا عبر MessagingManager
 * كقنوات مخصّصة دون إعادة تصميم. لا محتوى تسويقي.
 */
class AccountWelcomeNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account.welcome',
            'title' => __('account.welcome_title'),
            'body' => __('account.welcome_body'),
        ];
    }
}
