<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\DeliveryTransitionRequest;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\DeliveryStatusService;
use App\Modules\Shipping\Support\DeliveryStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * لوحة محرّك حالة التوصيل القانوني (Phase 4.3 / ADR-038) — RTL. المنطق في الخدمة.
 * الإغلاق المالي (close) مفصول ومقصور بصلاحية مستقلّة.
 */
class DeliveryStatusController extends Controller
{
    public function __construct(private readonly DeliveryStatusService $service) {}

    public function index(Request $request): View
    {
        $status = $request->query('delivery_status');
        $query = Shipment::with('order:id,number')->latest('id');
        if ($status && DeliveryStatus::isValid($status)) {
            $query->where('delivery_status', $status);
        }

        return view('admin.delivery.index', [
            'shipments' => $query->paginate(25)->withQueryString(),
            'status' => $status,
            'statuses' => DeliveryStatus::all(),
            'holdReasons' => $this->service->holdReasonReport(),
        ]);
    }

    public function show(Shipment $shipment): View
    {
        return view('admin.delivery.show', [
            'shipment' => $shipment->load(['order:id,number', 'deliveryTransitions.actor', 'providerTransitions']),
            'allowed' => DeliveryStatus::TRANSITIONS[$shipment->delivery_status] ?? [],
            'reasons' => DeliveryStatus::HOLD_REASONS,
        ]);
    }

    public function transition(DeliveryTransitionRequest $request, Shipment $shipment): RedirectResponse
    {
        $to = $request->validated('to');
        if ($to === DeliveryStatus::CLOSED) {
            return back()->withErrors(['delivery_status' => __('delivery.close_separate')]);
        }

        $this->service->transitionTo($shipment, $to, [
            'source' => 'user',
            'actor' => $request->user(),
            'reason' => $request->validated('reason'),
            'note' => $request->validated('note'),
        ]);

        return back()->with('status', __('delivery.transition_done'));
    }

    public function close(Shipment $shipment): RedirectResponse
    {
        $this->service->close($shipment, request()->user());

        return back()->with('status', __('delivery.closed_done'));
    }
}
