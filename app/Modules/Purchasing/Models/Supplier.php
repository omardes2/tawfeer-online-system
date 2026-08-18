<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Foundation\Models\Branch;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Purchasing\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * المورد (PHASE_2_DESIGN §9). كيان حسّاس: uuid + soft-delete + auditable.
 */
class Supplier extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'branch_id', 'name', 'code', 'legal_name', 'tax_number', 'email', 'phone',
        'address', 'governorate_id', 'city_id', 'currency_id', 'gl_account_id',
        // `opening_balance` و`opening_entry_id` خارج القائمة عمدًا: يُكتبان من
        // الخدمة مع قيدهما في معاملةٍ واحدة. وإسنادٌ جماعي كان يترك رقمًا على
        // الصفّ لا يعرفه ميزان المراجعة — وهو ما كان يحدث فعلًا قبل هذا.
        'payment_terms_days', 'credit_limit', 'notes', 'is_active',
    ];

    protected $casts = [
        'payment_terms_days' => 'integer',
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** الحساب الفرعي للمورد في دليل الحسابات (تحت «ذمم الموردين»). */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    /** قيد الرصيد الافتتاحي — بمعرفته يُصحَّح الرقم بعكسٍ لا بقيدٍ ثانٍ فوقه. */
    public function openingEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'opening_entry_id');
    }

    /**
     * رصيدٌ افتتاحي مكتوبٌ على الصفّ بلا قيدٍ في الدفاتر.
     *
     * حالةُ ما قبل هذه المرحلة: الرقم كان يُقبل ويُعرض ولا يُرحَّل. يُعرض تنبيهًا
     * في صفحة المورد، ويُرحَّل عند أول حفظ.
     */
    public function hasUnpostedOpening(): bool
    {
        return abs((float) $this->opening_balance) >= 0.01 && $this->opening_entry_id === null;
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): Factory
    {
        return SupplierFactory::new();
    }
}
