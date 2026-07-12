<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Recommendations\Models\RecommendationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * تتبّع أداء التوصيات (Phase 6 / ADR-045) — نقطة عامة للمتجر.
 * تسجّل ظهور/نقر/تحويل التوصيات (append-only) لتغذية لوحات KPI. لا منطق أعمال.
 */
class RecommendationTrackingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recommended_product_id' => ['required', 'integer', 'exists:products,id'],
            'source_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'string', 'max:30'],
            'event' => ['required', Rule::in(['impression', 'click', 'conversion'])],
            'source' => ['nullable', 'string', 'max:20'],
            'placement' => ['nullable', Rule::in(['product', 'cart', 'checkout', 'home'])],
        ]);

        RecommendationEvent::create([
            'source_product_id' => $data['source_product_id'] ?? null,
            'recommended_product_id' => $data['recommended_product_id'],
            'type' => $data['type'] ?? 'related',
            'event' => $data['event'],
            'source' => $data['source'] ?? 'rule',
            'placement' => $data['placement'] ?? null,
            'user_id' => $request->user()?->id,
            'session_token' => substr((string) $request->session()->getId(), 0, 80),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
