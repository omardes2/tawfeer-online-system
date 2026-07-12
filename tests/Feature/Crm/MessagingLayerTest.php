<?php

namespace Tests\Feature\Crm;

use App\Support\Contracts\Messaging\MessagingProviderInterface;
use App\Support\Integrations\Messaging\MessagingManager;
use App\Support\Integrations\Messaging\NullMessagingProvider;
use Tests\TestCase;

class MessagingLayerTest extends TestCase
{
    public function test_manager_resolves_provider_for_each_channel(): void
    {
        $manager = app(MessagingManager::class);

        foreach (['whatsapp', 'email', 'sms', 'marketing'] as $channel) {
            $provider = $manager->for($channel);
            $this->assertInstanceOf(MessagingProviderInterface::class, $provider);
            $this->assertInstanceOf(NullMessagingProvider::class, $provider);
        }
    }

    public function test_null_provider_is_a_safe_noop(): void
    {
        $result = (new NullMessagingProvider)->send('whatsapp', '966500000000', 'مرحبا');
        $this->assertEquals('skipped', $result['status']);
    }
}
