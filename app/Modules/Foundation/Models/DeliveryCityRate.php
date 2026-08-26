<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سعر توصيل مدينة لدى مزوّد (نمط Opost). المدن تُزامَن من المزوّد؛ الأسعار تُدار محليًا.
 */
class DeliveryCityRate extends Model
{
    protected $fillable = [
        'delivery_provider_id', 'city_id', 'external_code', 'name', 'name_en',
        'delivery_fee', 'customer_fee', 'return_fee', 'currency', 'is_active', 'synced_at',
    ];

    protected $casts = [
        'delivery_fee' => 'decimal:2',
        'customer_fee' => 'decimal:2',
        'return_fee' => 'decimal:2',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * ما يُتقاضى من الزبون — سعرُ البيع إن ضُبط، وإلّا التكلفة نفسها.
     *
     * موضعٌ واحد يقرّر ذلك، تقرأ منه السلّة ونموذج الطلب ووكيل واتساب ومُحلّل
     * التكلفة معًا. فلا يفترق ما يُعرض على الزبون عمّا يُقيَّد على الطلب.
     */
    public function customerFee(): float
    {
        return round((float) ($this->customer_fee ?? $this->delivery_fee), 2);
    }

    /** التكلفة لدى شركة التوصيل — تُزامَن منها ولا تُمَسّ يدويًّا. */
    public function providerCost(): float
    {
        return round((float) $this->delivery_fee, 2);
    }

    /** الهامش على الطرد الواحد: ما يُتقاضى ناقص ما يُدفَع. */
    public function margin(): float
    {
        return round($this->customerFee() - $this->providerCost(), 2);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
