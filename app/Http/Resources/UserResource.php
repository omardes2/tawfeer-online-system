<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * تنسيق موحّد لبيانات المستخدم عبر الـ API (المبدأ 11: API-First).
 * يكشف uuid لا id (المبدأ 4)، ويعتمد على الأدوار/الصلاحيات (المبدأ 12).
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch?->uuid,
                'name' => $this->branch?->name,
            ]),
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
        ];
    }
}
