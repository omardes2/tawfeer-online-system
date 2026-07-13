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
 * فاتورة مورد/شراء (REQUIREMENTS §2.5). دورة: مسودّة → معتمدة → مُرحّلة، مع حالة دفع
 * (غير مدفوعة/جزئية/مدفوعة). الترحيل عبر محرّك القيد المزدوج (عكس لا حذف).
 */
class PurchaseInvoice extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    public const TRANSITIONS = [
        'draft' => ['approved', 'cancelled'],
        'approved' => ['posted', 'cancelled', 'draft'],
        'posted' => ['reversed'],
    ];

    protected $fillable = [
        'uuid', 'number', 'supplier_id', 'purchase_order_id', 'goods_receipt_id',
        'supplier_reference', 'invoice_date', 'due_date', 'status', 'payment_status',
        'subtotal', 'tax_amount', 'total', 'amount_paid', 'currency', 'notes',
        'journal_entry_id', 'reversal_entry_id',
        'created_by', 'approved_by', 'posted_by', 'approved_at', 'posted_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePosted($q)
    {
        return $q->where('status', 'posted');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function canTransition(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** الرصيد المتبقّي على الفاتورة. */
    public function balanceDue(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }
}
