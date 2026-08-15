<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryBusiness;
use App\Modules\Store\Models\SocialIdentity;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'department', 'job_title', 'password', 'branch_id', 'delivery_business_id', 'is_active', 'last_login_at', 'terms_accepted_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, HasUuid, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * الفرع الذي ينتمي إليه المستخدم (المبدأ 1: Multi-Branch Ready).
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** حساب البزنس لدى شركة التوصيل — تُدخَل طرود طلبات هذا المستخدم تحته. */
    public function deliveryBusiness(): BelongsTo
    {
        return $this->belongsTo(DeliveryBusiness::class);
    }

    /** هويّات تسجيل الدخول الاجتماعي المربوطة (Phase 3.5). */
    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    /** أدوارٌ ترى كل الطلبات بحكم عملها — الإدارة وحدها. */
    public const FULL_ORDER_VIEW_ROLES = ['admin', 'manager'];

    /** أدوارٌ لا ترى إلا طلباتها هي — مهما مُنحت من صلاحيات. */
    public const OWN_ORDERS_ONLY_ROLES = ['sales', 'affiliate'];

    /**
     * هل يُقصَر هذا المستخدم على طلباته هو؟
     *
     * المسوّق وموظف المبيعات مقيَّدان **بحكم دورهما** لا بغياب صلاحية «العرض
     * الكامل». الاعتماد على الغياب وحده أمانٌ هشّ: أيّ منحٍ عارض للصلاحية —
     * تعديل دور، أو زارع، أو منح على مستوى المستخدم — يفتح للمسوّق طلبات
     * زملائه وأسماء عملائهم وأرقام هواتفهم بصمت.
     *
     * والدور الإداري يسبق القيد، فمديرٌ يحمل صفة مسوّق يبقى يرى الجميع.
     */
    public function restrictedToOwnOrders(): bool
    {
        if ($this->hasAnyRole(self::FULL_ORDER_VIEW_ROLES)) {
            return false;
        }

        if ($this->hasAnyRole(self::OWN_ORDERS_ONLY_ROLES)) {
            return true;
        }

        return ! $this->can('sales.orders.view');
    }
}
