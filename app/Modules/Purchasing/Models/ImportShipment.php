<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * شحنة استيراد (كونتينر) — وعاء التكلفة الذي يربط فاتورة البضاعة بفواتير
 * المصاريف التي تصل بعدها بأشهر.
 *
 * فاتورة البضاعة تُحمّل الحساب الوسيط بتقديرها، وفواتير المصاريف تُطفئه بالفعلي،
 * وما يتبقّى فرقُ تقدير يُقفل عند إغلاق الشحنة يدويًا.
 */
class ImportShipment extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid', 'number', 'reference', 'supplier_id', 'status',
        'shipped_at', 'arrived_at', 'variance_amount', 'variance_entry_id',
        'closed_at', 'closed_by', 'notes', 'created_by',
    ];

    protected $casts = [
        'shipped_at' => 'date',
        'arrived_at' => 'date',
        'variance_amount' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'import_shipment_id');
    }

    /** فواتير البضاعة — هي التي حمّلت الحساب الوسيط. */
    public function goodsInvoices(): HasMany
    {
        return $this->invoices()->where('kind', 'goods');
    }

    /** فواتير المصاريف (شحن بحري، جمارك، عمولة مكتب) — هي التي تُطفئه. */
    public function expenseInvoices(): HasMany
    {
        return $this->invoices()->where('kind', 'expenses');
    }

    public function varianceEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'variance_entry_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function scopeOpen($q)
    {
        return $q->where('status', 'open');
    }
}
