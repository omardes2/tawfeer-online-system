<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Models\DeliveryProviderEvent;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * أحداث شركة التوصيل الواردة (ADR-039b) — شاشة تشخيص: هل يصل الـwebhook فعلًا؟
 * وهل تُستوعَب الحالات أم تُرفض؟ تعرض كل محاولة (webhook/مزامنة) بنتيجتها وسببها.
 */
class ProviderEventController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Shipment::class);

        $events = DeliveryProviderEvent::query()
            ->with(['shipment:id,number,tracking_number,delivery_status', 'provider:id,name'])
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('external_id', 'like', $term)
                    ->orWhere('event_id', 'like', $term)
                    ->orWhere('provider_status', 'like', $term));
            })
            ->latest('id')->paginate(30)->withQueryString();

        // ملخّص آخر 24 ساعة — يجيب فورًا: هل يصلنا شيء؟ وهل يُعالَج؟
        $since = now()->subDay();
        $summary = [
            'total' => DeliveryProviderEvent::where('created_at', '>=', $since)->count(),
            'webhook' => DeliveryProviderEvent::where('created_at', '>=', $since)->where('channel', 'webhook')->count(),
            'processed' => DeliveryProviderEvent::where('created_at', '>=', $since)->where('status', 'processed')->count(),
            'problems' => DeliveryProviderEvent::where('created_at', '>=', $since)
                ->whereIn('status', ['failed', 'unverified', 'ignored', 'inconsistent'])->count(),
        ];

        return view('admin.shipping.provider_events.index', [
            'events' => $events,
            'summary' => $summary,
            'lastWebhookAt' => DeliveryProviderEvent::where('channel', 'webhook')->max('received_at'),
            'filters' => $request->only(['channel', 'status', 'search']),
            'webhookUrl' => url('/api/v1/webhooks/delivery/opost'),
        ]);
    }

    /** عرض الحمولة الخام لحدث واحد (لتشخيص شكل بيانات المزوّد). */
    public function show(DeliveryProviderEvent $providerEvent): View
    {
        $this->authorize('viewAny', Shipment::class);

        return view('admin.shipping.provider_events.show', [
            'event' => $providerEvent->load(['shipment:id,number,tracking_number,delivery_status', 'provider:id,name']),
        ]);
    }
}
