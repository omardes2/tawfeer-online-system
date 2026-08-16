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

    /** فاتورة بضاعة (تُدخل مخزونًا) أو فاتورة مصاريف شحنة (تُطفئ الحساب الوسيط). */
    public const KIND_GOODS = 'goods';

    public const KIND_EXPENSES = 'expenses';

    /**
     * تصنيف فاتورة المصاريف — ما الذي تخصّه من مصاريف الشحنة.
     *
     * قائمة مغلقة لا نصّ حرّ: الوصف يُكتب بألف صيغة، والتجميع والفلترة يحتاجان
     * مفتاحًا ثابتًا. `other` هو المهرب الذي يمنع إجبار المستخدم على تصنيفٍ خطأ.
     *
     * @var array<string, string>
     */
    public const EXPENSE_CATEGORIES = [
        'sea_freight' => 'شحن بحري',
        'customs' => 'تخليص وجمارك',
        'commission' => 'عمولة مكتب',
        'inland' => 'نقل داخلي',
        'other' => 'مصاريف أخرى',
    ];

    protected $fillable = [
        'uuid', 'number', 'supplier_id', 'purchase_order_id', 'goods_receipt_id',
        'import_shipment_id', 'kind', 'expense_category',
        'supplier_reference', 'invoice_date', 'due_date', 'status', 'payment_status',
        'subtotal', 'tax_amount', 'total', 'amount_paid', 'currency', 'notes',
        'fx_rate_to_usd', 'usd_rate', 'commission_rate', 'cbm_rate_usd',
        'foreign_subtotal', 'landed_subtotal', 'total_cbm',
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
        'fx_rate_to_usd' => 'decimal:6',
        'usd_rate' => 'decimal:6',
        'commission_rate' => 'decimal:3',
        'cbm_rate_usd' => 'decimal:4',
        'foreign_subtotal' => 'decimal:2',
        'landed_subtotal' => 'decimal:2',
        'total_cbm' => 'decimal:6',
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

    public function importShipment(): BelongsTo
    {
        return $this->belongsTo(ImportShipment::class, 'import_shipment_id');
    }

    /**
     * فاتورة مصاريف شحنة: تُقيَّد على الحساب الوسيط لا على المخزون، ولا تُدخل
     * بضاعة. هي التي تُطفئ ما حمّلته فاتورةُ البضاعة من تقدير.
     */
    public function isExpenseInvoice(): bool
    {
        return $this->kind === self::KIND_EXPENSES;
    }

    /**
     * وسم الفاتورة كما يُقرأ في القائمة: «بضاعة» أو نوع المصروف.
     *
     * فواتير الشحنة الواحدة تتشابه أرقامًا ومورّدًا، فبلا وسمٍ لا يُعرف ما تخصّه
     * إلا بفتح كلٍّ منها.
     */
    public function kindLabel(): string
    {
        if (! $this->isExpenseInvoice()) {
            return __('بضاعة');
        }

        return __(self::EXPENSE_CATEGORIES[$this->expense_category] ?? self::EXPENSE_CATEGORIES['other']);
    }

    /**
     * لون الوسم من ألوان `x-admin.badge` الخمسة: البضاعة محايدة، والنقل بنوعيه
     * أزرق (بحريّ أو داخليّ — كلاهما نقل)، والرسوم الحكومية كهرمانية، والعمولة
     * خضراء. `red` محجوز للخطأ فلا يُستعمل تصنيفًا.
     */
    public function kindTone(): string
    {
        if (! $this->isExpenseInvoice()) {
            return 'gray';
        }

        return match ($this->expense_category) {
            'sea_freight', 'inland' => 'blue',
            'customs' => 'amber',
            'commission' => 'green',
            default => 'gray',
        };
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

    /** فاتورة استيراد: مُدخَلة بعملة أجنبية بسعري صرف، فتُحسب لها تكلفة شاملة. */
    public function isImport(): bool
    {
        return (float) $this->fx_rate_to_usd > 0 && (float) $this->usd_rate > 0;
    }

    /**
     * الفرق بين قيمة المخزون (التكلفة الشاملة) وذمّة المورد (السعر الحقيقي) — أي
     * المصاريف المحمّلة على البضاعة ولم تصل فواتيرها بعد. يُقيَّد في حساب وسيط
     * ابتداءً من المرحلة ٢؛ هنا يُعرض فقط.
     */
    public function importDifference(): float
    {
        return round((float) $this->landed_subtotal - (float) $this->subtotal, 2);
    }
}
