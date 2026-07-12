<?php

namespace App\Modules\Marketing\Console;

use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Store\Models\Cart;
use Illuminate\Console\Command;

/**
 * متابعة السلال المهجورة (Phase 6 / ADR-046).
 * يلتقط السلال النشطة الراكدة (لعميل معروف) بعد عتبة زمنية ويحفّز حملات abandoned_cart.
 * المرجع لكل سلّة يضمن عدم التكرار (idempotency) عبر التشغيلات.
 */
class RunAbandonedCartsCommand extends Command
{
    protected $signature = 'marketing:run-abandoned-carts {--minutes=60 : عتبة الركود بالدقائق}';

    protected $description = 'تحفيز حملات متابعة السلال المهجورة';

    public function handle(CampaignService $campaigns): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

        $carts = Cart::query()
            ->whereIn('status', ['active', 'abandoned'])
            ->whereNotNull('customer_id')
            ->where('updated_at', '<=', $threshold)
            ->get();

        $count = 0;
        foreach ($carts as $cart) {
            if (! ($customer = Customer::find($cart->customer_id))) {
                continue;
            }
            $summary = $campaigns->handleTrigger('abandoned_cart', $customer, [], 'abandoned_cart:'.$cart->id);
            $count += array_sum($summary);
        }

        $this->info("Processed {$carts->count()} carts, {$count} messages.");

        return self::SUCCESS;
    }
}
