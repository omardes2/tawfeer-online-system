<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * قالب رسالة تسويق (Phase 6 / ADR-046) — عربي/إنجليزي.
 */
class CampaignTemplate extends Model
{
    protected $fillable = [
        'name', 'channel', 'subject', 'body_ar', 'body_en', 'is_active', 'created_by',
        // القالب المعتمَد لدى المنصّة — هو ما يُرسَل فعلًا خارج نافذة الردّ.
        'provider_template', 'provider_language', 'provider_params',
    ];

    protected $casts = ['is_active' => 'boolean', 'provider_params' => 'array'];

    /**
     * متغيّرات القالب بترتيبها كما تتوقّعها المنصّة.
     *
     * المنصّة ترقّم المتغيّرات {{1}} و{{2}} ولا تُسمّيها، فالترتيب هو العقد —
     * وتبديلُه يضع اسم الزبون مكان اسم الصنف بلا خطأٍ يُرفَع.
     *
     * @param  array<string, mixed>  $vars
     * @return array<int, string>
     */
    public function orderedParams(array $vars): array
    {
        return array_map(
            fn (string $key) => (string) ($vars[$key] ?? ''),
            (array) ($this->provider_params ?? []),
        );
    }
}
