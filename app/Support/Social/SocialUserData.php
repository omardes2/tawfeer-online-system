<?php

namespace App\Support\Social;

/**
 * بيانات مستخدم من مزوّد اجتماعي (Phase 3.5 / ADR-036) — DTO محايد عن Socialite،
 * ليبقى منطق الهوية قابلًا للاختبار دون OAuth حقيقي.
 */
final class SocialUserData
{
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $email = null,
        public readonly ?string $name = null,
        public readonly ?string $avatar = null,
    ) {}
}
