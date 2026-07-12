<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\UpdateCheckoutSessionRequest;
use App\Http\Resources\Store\CheckoutResource;
use App\Http\Resources\Store\CheckoutSessionResource;
use App\Modules\Store\Models\CheckoutSession;
use App\Modules\Store\Services\CartService;
use App\Modules\Store\Services\CheckoutService;
use Illuminate\Http\Request;

/**
 * إتمام الشراء متعدّد الخطوات للهوية الحالية (مستخدم مُصادَق أو ضيف — Phase 3.2).
 * بدء جلسة → تحديث بيانات → إتمام. الملكية تُفحص لكل جلسة.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $service,
        private readonly CartService $carts,
    ) {}

    public function start(Request $request): CheckoutSessionResource
    {
        $cart = $this->carts->resolveActive($request->user(), $request->header('X-Cart-Token')) ?? abort(401);

        $session = $this->service->start($cart);

        return new CheckoutSessionResource($session->load('cart.items'));
    }

    public function show(Request $request, CheckoutSession $session): CheckoutSessionResource
    {
        $this->authorizeOwnership($request, $session);

        return new CheckoutSessionResource($session->load('cart.items'));
    }

    public function update(UpdateCheckoutSessionRequest $request, CheckoutSession $session): CheckoutSessionResource
    {
        $this->authorizeOwnership($request, $session);
        $session = $this->service->update($session, $request->validated());

        return new CheckoutSessionResource($session->load('cart.items'));
    }

    public function place(Request $request, CheckoutSession $session): CheckoutResource
    {
        $this->authorizeOwnership($request, $session);
        $order = $this->service->place($session);

        return new CheckoutResource($order);
    }

    /** الجلسة تخصّ الهوية الحالية (نفس المستخدم أو نفس رمز الضيف). */
    private function authorizeOwnership(Request $request, CheckoutSession $session): void
    {
        if ($session->user_id !== null) {
            abort_unless($request->user()?->id === $session->user_id, 403);

            return;
        }

        abort_unless(
            $session->session_token !== null
                && $request->header('X-Cart-Token') === $session->session_token,
            403,
        );
    }
}
