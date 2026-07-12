<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Store\Models\SocialIdentity;
use App\Modules\Store\Notifications\AccountWelcomeNotification;
use App\Support\Social\SocialUserData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * هوية الدخول الاجتماعي (Phase 3.5 / ADR-036): تسجيل/دخول/ربط دون تكرار حسابات.
 * يُعيد استخدام CustomerService/GuestMergeService — لا منطق أعمال جديد. لا أسرار هنا.
 */
class SocialAuthService
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly GuestMergeService $guestMerge,
    ) {}

    /**
     * تسجيل/دخول عبر مزوّد اجتماعي. يمنع تكرار الحساب: هوية قائمة → دخول؛ بريد موثّق
     * قائم → ربط آمن؛ وإلا حساب جديد. يدمج بيانات الضيف. يُعيد حاجة إكمال الملف.
     *
     * @return array{user: User, needs_completion: bool}
     */
    public function authenticate(SocialUserData $data, ?string $guestToken): array
    {
        $identity = SocialIdentity::where('provider', $data->provider)
            ->where('provider_id', $data->id)->first();

        if ($identity) {
            $user = $identity->user;
        } elseif ($data->email && $existing = User::where('email', $data->email)->first()) {
            // بريد موثّق من المزوّد يطابق حسابًا قائمًا → ربط آمن (لا حساب مكرّر).
            $this->linkIdentity($existing, $data);
            $user = $existing;
        } else {
            $user = $this->createUser($data);
            $this->linkIdentity($user, $data);
        }

        Auth::login($user, true);
        $this->guestMerge->merge($user, $guestToken);

        $customer = Customer::where('user_id', $user->id)->first();

        return ['user' => $user, 'needs_completion' => ! ($customer?->isProfileComplete() ?? false)];
    }

    /** ربط مزوّد بحساب مُسجَّل الدخول (مع منع الربط بحساب آخر). */
    public function link(User $user, SocialUserData $data): void
    {
        $existing = SocialIdentity::where('provider', $data->provider)
            ->where('provider_id', $data->id)->first();

        if ($existing && $existing->user_id !== $user->id) {
            throw ValidationException::withMessages(['provider' => __('account.provider_linked_other')]);
        }

        $this->linkIdentity($user, $data);
    }

    /** فكّ ربط مزوّد — مع الحفاظ على وسيلة دخول واحدة على الأقل. */
    public function unlink(User $user, string $provider): void
    {
        $others = $user->socialIdentities()->where('provider', '!=', $provider)->count();
        $canPasswordLogin = $user->email && ! str_ends_with($user->email, '@social.local');

        if ($others === 0 && ! $canPasswordLogin) {
            throw ValidationException::withMessages(['provider' => __('account.cannot_unlink_last')]);
        }

        $user->socialIdentities()->where('provider', $provider)->delete();
    }

    private function createUser(SocialUserData $data): User
    {
        return DB::transaction(function () use ($data) {
            $branchId = Branch::default()?->id;
            $email = $data->email ?: $data->provider.'_'.$data->id.'@social.local';

            $user = User::create([
                'name' => $data->name ?: __('account.customer'),
                'email' => $email,
                'password' => Str::random(40),      // عشوائي؛ يُسترجَع لاحقًا
                'branch_id' => $branchId,
                'is_active' => true,
                'terms_accepted_at' => now(),       // قبول ضمني بالمتابعة عبر المزوّد
            ]);
            // البريد موثّق من المزوّد (email_verified_at ليس ضمن fillable — يُضبط صراحةً).
            if ($data->email) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $user->assignRole('customer');

            $this->customers->create([
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'name' => $data->name ?: __('account.customer'),
                'email' => $data->email,
                'primary_phone' => null,
                'source' => 'social:'.$data->provider,
            ]);

            $user->notify(new AccountWelcomeNotification);

            return $user;
        });
    }

    private function linkIdentity(User $user, SocialUserData $data): void
    {
        SocialIdentity::updateOrCreate(
            ['provider' => $data->provider, 'provider_id' => $data->id],
            ['user_id' => $user->id, 'email' => $data->email, 'name' => $data->name, 'avatar' => $data->avatar],
        );
    }
}
