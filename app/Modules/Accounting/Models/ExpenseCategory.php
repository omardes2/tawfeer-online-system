<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * تصنيف مصروف — اسمٌ يفهمه المستخدم فوق حسابٍ في دليل المحاسبة.
 *
 * الحساب هو مصدر الحقيقة المحاسبي؛ التصنيف واجهتُه: يُنشأ من اللوحة، ويفتح
 * حسابه الطرفي تحت «مصاريف تشغيلية» تلقائيًا، ويُزامن اسمَه عند التعديل.
 */
class ExpenseCategory extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    /**
     * مصادر الاحتساب الآلي: تصنيفٌ موسومٌ بأحدها تحتسبه الميزانية من مصدره لا
     * من سندات الصرف — فتُستثنى سنداتُه من الإجمالي ولا يُعدّ الرقم مرّتين.
     *
     * @var array<string, string>
     */
    public const AUTO_SOURCES = [
        'ads' => 'جدول الصرف الإعلاني',
        'commissions' => 'دفتر العمولات (استحقاق الفترة)',
        'payroll' => 'مسيّرات الرواتب المُرحّلة',
    ];

    protected $fillable = [
        'uuid', 'name', 'name_en', 'account_id', 'is_system', 'auto_source',
        'is_active', 'sort_order', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** هل تحتسب الميزانيةُ هذا التصنيف من مصدره؟ */
    public function isAutoCounted(): bool
    {
        return isset(self::AUTO_SOURCES[(string) $this->auto_source]);
    }

    /** اسمُ المصدر الذي يُحتسب منه — للعرض في التحذير. */
    public function autoSourceLabel(): ?string
    {
        return self::AUTO_SOURCES[(string) $this->auto_source] ?? null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(FinancialVoucher::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * هل تحرّك على حسابه قيد؟
     *
     * يُقاس بالقيود لا بالسندات: السند طريقٌ واحد إلى الحساب، وقيدٌ يدوي أو
     * ترحيلٌ آلي يصل إليه دونه. تعطيلُ تصنيفٍ تحرّك حسابُه يترك في التقرير رقمًا
     * بلا اسم.
     */
    public function hasActivity(): bool
    {
        return $this->account?->lines()->exists() ?? false;
    }
}
