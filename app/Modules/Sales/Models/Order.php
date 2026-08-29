<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Marketing\Jobs\SendPurchaseConversion;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Services\AdAttributionService;
use App\Modules\Payment\Models\Payment;
use App\Modules\Shipping\Models\Shipment;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Sales\OrderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * طلب بيع (ADR-009/010/026). كيان حسّاس: uuid + soft-delete + auditable.
 * draft → confirmed → stock_reserved → preparing → ready_to_ship → shipped → delivered (+cancelled).
 */
class Order extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'number', 'branch_id', 'warehouse_id', 'customer_id',
        'customer_name', 'customer_phone', 'customer_email', 'shipping_address',
        'city_id', 'area_id', 'has_return', 'return_notes', 'parcels_count',
        'tracking_number', 'delivery_external_id', 'delivery_status',
        'delivery_dispatch_error', 'delivery_dispatch_attempts', 'delivery_dispatch_attempted_at',
        'delivery_cancel_error', 'delivery_cancel_attempted_at',
        'channel', 'ad_channel_id', 'ad_click_id', 'ad_source', 'ad_campaign_ref', 'ad_set_ref', 'status', 'payment_status', 'assigned_to', 'affiliate_id',
        'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'total', 'amount_paid',
        // ما حُصِّل فعلًا من الزبون — قد يقلّ عن الإجمالي حين تُعدّل شركة
        // التوصيل مبلغ التحصيل قبل التسليم.
        'collected_total', 'collection_note', 'collection_recorded_at', 'collection_recorded_by',
        'collection_entry_id',
        'notes', 'cancel_reason',
        'confirmed_at', 'reserved_at', 'shipped_at', 'delivered_at', 'cancelled_at', 'settled_at',
        // اعتماد المدير — يُغلق الإلغاء في وجه مُدخِل الطلب (مستقلّ عن التأكيد الداخلي).
        'approved_at', 'approved_by',
        'return_received_at', 'revenue_entry_id', 'cogs_entry_id', 'created_by',
    ];

    protected $casts = [
        'has_return' => 'boolean',
        'parcels_count' => 'integer',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'collected_total' => 'decimal:2',
        'collection_recorded_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'approved_at' => 'datetime',
        'reserved_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'settled_at' => 'datetime',
        'return_received_at' => 'datetime',
        'delivery_cancel_attempted_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** مُنشئ الطلب (موظف عند الطلب اليدوي). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** العميل المرتبط (إن وُجد). */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** مدينة التوصيل المُعيَّنة (نمط Opost). */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /** منطقة التوصيل المُعيَّنة (نمط Opost). */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    /** أحدث شحنة للطلب (اسم المستلم يؤخذ منها). */
    public function latestShipment(): HasOne
    {
        return $this->hasOne(Shipment::class)->latestOfMany();
    }

    /** المسوّق المُحيل (Phase 4.1). */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** سجلّ تغييرات السعر اليدوي غير القابل للتعديل (Phase 4.1). */
    public function priceChanges(): HasMany
    {
        return $this->hasMany(OrderPriceChange::class)->orderBy('id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** قناة الإعلان (صفحة البيع) التي جاء منها الطلب — لقطةٌ لا اشتقاق. */
    public function adChannel(): BelongsTo
    {
        return $this->belongsTo(AdChannel::class);
    }

    /**
     * تثبيت نسبة الإعلان لحظة الإنشاء — بمسارين لا مسار واحد.
     *
     * هنا لا في `OrderService`: للطلب أكثر من مسار إنشاء (شاشة الموظف، الطلب
     * المُساعَد، نقطة البيع، متجر الويب)، ولو وُضع في أحدها لخرجت طلبات الباقين
     * بلا قناة فبدت صفحاتُها أقلّ مبيعًا ممّا هي.
     *
     * **طلب الموظفة** يُنسَب عبر حساب البزنس: الطلب ← منشئُه ← حسابُ بزنسه ←
     * الصفحة. و**طلب الويب** لا منشئ له، فيُنسَب عبر معرّف المجموعة الإعلانية
     * الذي حمله رابط الإعلان — وبدونه كان يسقط بلا قناة، فتظهر كل مبيعات
     * حملات الموقع تحت «غير منسوب» ولا يُحكَم على واحدةٍ منها.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $order): void {
            if ($order->ad_channel_id !== null) {
                return;
            }

            if ($order->created_by) {
                $business = User::whereKey($order->created_by)->value('delivery_business_id');

                $order->ad_channel_id = $business
                    ? AdChannel::where('delivery_business_id', $business)->value('id')
                    : null;

                return;
            }

            $order->applyWebAttribution();
        });

        /*
        | حدث «شراء» لمنصّة القياس — من هنا لا من متحكّم الإتمام: مسار الإتمام
        | محميّ ولا يُمسّ، وهذا يعمل لكل طلب ويبٍّ مهما تعدّدت مساراته.
        |
        | ويُرسَل عند **الإنشاء** لا عند التسليم، اتّساقًا مع أساس «الميزانية
        | اليومية» (الاحتساب على الطلبات المُدخَلة، والمرتجعات ≤ 5%). والانتظار
        | أسبوعًا حتى التسليم كان سيحرم المنصّة من الإشارة التي تُحسِّن عليها.
        */
        static::created(function (self $order): void {
            if ($order->channel === 'web') {
                SendPurchaseConversion::dispatch($order->id);
            }
        });
    }

    /**
     * نسبة طلب الويب من الكعكة التي كتبتها زيارة الإعلان.
     *
     * الشرط الوحيد أن تكون القناة `web`. ولا حاجة لفحص سياق التشغيل: الطلب
     * المُنشأ في مهمّةٍ مجدولة أو أمر سطرٍ لا كعكة له، فتعود النسبة فارغة
     * وحدها — وفحصُ «هل نحن في سطر الأوامر؟» كان يُلغي النسبة في الاختبارات
     * كلّها وهي تعمل من سطر الأوامر بطبيعتها.
     */
    private function applyWebAttribution(): void
    {
        if ($this->channel !== 'web') {
            return;
        }

        $attribution = app(AdAttributionService::class);
        $stored = $attribution->stored();

        if ($stored === []) {
            return;
        }

        $this->ad_click_id = $stored['click_id'] ?? null;
        $this->ad_source = $stored['source'] ?? null;
        $this->ad_campaign_ref = $stored['campaign'] ?? null;
        $this->ad_set_ref = $stored['adset'] ?? null;

        $this->ad_channel_id = $attribution->resolveChannelId($this->ad_set_ref);
    }

    protected static function newFactory(): Factory
    {
        return OrderFactory::new();
    }
}
