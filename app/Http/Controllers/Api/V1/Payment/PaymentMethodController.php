<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\PaymentMethodResource;
use App\Modules\Foundation\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentMethodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('payments.view'), 403);

        $query = PaymentMethod::query();
        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        return PaymentMethodResource::collection($query->orderBy('sort_order')->get());
    }
}
