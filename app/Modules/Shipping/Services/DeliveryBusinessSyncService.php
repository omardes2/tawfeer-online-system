<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Foundation\Models\DeliveryBusiness;
use App\Support\Contracts\Shipping\DeliveryProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * مزامنة حسابات «البزنس» من شركة التوصيل إلى الجدول المحلي (delivery_businesses).
 * يُنشئ الجديد، يُحدّث القائم، ويُعطّل ما لم يعُد موجودًا لدى المزوّد (لا يُحذف حفاظًا على الربط).
 */
class DeliveryBusinessSyncService
{
    /**
     * @return array{provider: string, synced: int, deactivated: int}
     */
    public function sync(): array
    {
        $code = (string) config('shipping.provider', 'null');
        $class = config("shipping.drivers.$code.delivery");

        if ($class === null) {
            return ['provider' => $code, 'synced' => 0, 'deactivated' => 0];
        }

        /** @var DeliveryProviderInterface $driver */
        $driver = app($class);
        $rows = collect($driver->pullBusinesses());

        $seen = [];
        $synced = 0;

        DB::transaction(function () use ($code, $rows, &$seen, &$synced) {
            foreach ($rows as $row) {
                $externalId = (string) ($row['external_id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                DeliveryBusiness::updateOrCreate(
                    ['provider' => $code, 'external_id' => $externalId],
                    [
                        'name' => (string) ($row['name'] ?? ('#'.$externalId)),
                        'address_external_id' => $row['address_external_id'] ?? null,
                        'phone' => $row['phone'] ?? null,
                        'is_active' => true,
                        'raw' => $row['raw'] ?? null,
                    ],
                );

                $seen[] = $externalId;
                $synced++;
            }
        });

        // تعطيل ما لم يعُد موجودًا لدى المزوّد (يبقى مربوطًا بالمستخدمين لكن غير نشط).
        $deactivated = 0;
        if ($synced > 0) {
            $deactivated = DeliveryBusiness::where('provider', $code)
                ->whereNotIn('external_id', $seen)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        return ['provider' => $code, 'synced' => $synced, 'deactivated' => $deactivated];
    }
}
