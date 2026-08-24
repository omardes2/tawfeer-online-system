<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use App\Modules\Store\Models\WishlistItem;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Crm\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * عميل (ADR-030، BR-CUST). كيان مهم: uuid + soft-delete + auditable.
 * حقول credit_limit/loyalty_points جاهزة للربط المستقبلي دون إعادة تصميم.
 */
class Customer extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'user_id', 'branch_id', 'name', 'email', 'primary_phone', 'source',
        'category', 'tags', 'credit_limit', 'loyalty_points',
        'is_high_risk', 'is_blocked', 'blocked_reason', 'blocked_at',
        'cancelled_orders_count', 'returns_count', 'merged_into_id', 'notes', 'created_by',
        // Phase 3.4 — جاهزية تجربة/تسويق العميل (ADR-035)
        'birth_date', 'preferred_locale', 'preferred_branch_id',
        'communication_preferences', 'acquisition_source',
        'gl_account_id',
    ];

    protected $casts = [
        'tags' => 'array',
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'loyalty_points' => 'integer',
        'is_high_risk' => 'boolean',
        'is_blocked' => 'boolean',
        'blocked_at' => 'datetime',
        'cancelled_orders_count' => 'integer',
        'returns_count' => 'integer',
        'birth_date' => 'date',
        'communication_preferences' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** الفرع المفضّل (Phase 3.4 — تفضيلات العميل). */
    public function preferredBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'preferred_branch_id');
    }

    /** الحساب الفرعي للعميل في دليل الحسابات (تحت «ذمم العملاء»). */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    /**
     * الرصيد المتبقّي على العميل، في عمودٍ محسوب `outstanding_balance`.
     *
     * **استعلامٌ فرعيّ واحد لا استعلامٌ لكل عميل**: قراءة الرصيد من العلاقة
     * داخل حلقة العرض تعني عشرين استعلامًا في صفحةٍ من عشرين صفًّا، ومئاتٍ عند
     * ترقيم أوسع.
     *
     * والحساب هو نفسه حسابُ كشف الحساب في صفحة العميل — `مدين ناقص دائن` على
     * سطور حسابه الفرعيّ في «ذمم العملاء» — كي لا يختلف رقمُ القائمة عن رقم
     * البطاقة فيفقد الاثنان مصداقيّتهما.
     *
     * و**المُرحَّلة وحدها** تُحتسب (`status = posted`): قيدٌ مسوّدة لم يدخل
     * الدفاتر بعد، وإدخالُه في رصيدٍ يُطالَب به العميل مطالبةٌ بما لم يُعتمد.
     *
     * الموجب = على العميل، والسالب = له.
     */
    public function scopeWithOutstandingBalance(Builder $query): Builder
    {
        return $query->addSelect(['outstanding_balance' => JournalLine::query()
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0)')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereColumn('journal_lines.account_id', 'customers.gl_account_id')
            ->where('journal_entries.status', 'posted'),
        ]);
    }

    /**
     * الرصيد رقمًا — من العمود المحسوب إن وُجد، وإلّا صفر.
     *
     * صفرٌ لا `null`: عميلٌ بلا حسابٍ فرعيّ (لم يشترِ على الحساب قطّ) رصيدُه
     * صفرٌ فعلًا، وفراغٌ في العمود يُقرأ «مجهول» وهو معلوم.
     */
    public function outstandingBalance(): float
    {
        return round((float) ($this->outstanding_balance ?? 0), 2);
    }

    /**
     * قيد الرصيد الافتتاحي.
     *
     * خارج `$fillable` عمدًا هو و`opening_balance`: الرصيد يُكتب من الخدمة مع
     * قيده في معاملةٍ واحدة، فإسنادٌ جماعي يكتب الرقم بلا قيدٍ يقابله كان يترك
     * رصيدًا معروضًا لا أثر له في الدفاتر.
     */
    public function openingEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'opening_entry_id');
    }

    /**
     * اكتمل الملف بالبيانات المطلوبة قبل الشراء (Phase 3.5): هاتف + ميلاد + لغة مفضّلة
     * + تفضيلات تواصل. يُستخدم لتوجيه مستخدمي الدخول الاجتماعي لإكمال الملف.
     */
    public function isProfileComplete(): bool
    {
        return filled($this->primary_phone)
            && filled($this->birth_date)
            && filled($this->preferred_locale)
            && ! is_null($this->communication_preferences);
    }

    /** عناصر قائمة المفضّلة (Phase 3.4). */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function phones(): HasMany
    {
        return $this->hasMany(CustomerPhone::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function customerNotes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->latest('id');
    }

    /** سجلّ الطلبات (BR-CUST-07) — عبر عمود orders.customer_id (مربوط بالخدمة). */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_blocked', false)->whereNull('merged_into_id');
    }

    protected static function newFactory(): Factory
    {
        return CustomerFactory::new();
    }
}
