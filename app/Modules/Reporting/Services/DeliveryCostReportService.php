<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Support\DateRange;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * ما دفعتَه لشركة التوصيل — وحده.
 *
 * **لا مبيعات في هذا التقرير ولا ربح.** الأرقام هنا للمطابقة مع كشف الشركة:
 * أنت تستلم منها فاتورةً شهرية بمبلغٍ واحد، وتحتاج أن تعرف مِمَّ تكوّن — بأيّ
 * مدينة ومنطقة وكم طردًا. وخلطُ المبيعات بها يجعل الرقم غير قابلٍ للمطابقة،
 * وهو الغرض الوحيد من الشاشة.
 *
 * ## Protected Delivery Integration — Do Not Modify
 *
 * هذا التقرير **يقرأ فقط**: `shipments.shipping_cost` كما كُتبت وقت الإنشاء،
 * والمدينة والمنطقة كما ثُبّتت على الطرد. لا يُعيد حساب رسمٍ، ولا يستدعي
 * الشركة، ولا يمسّ حمولةً ولا webhook. وأيّ فرقٍ بين هذه الأرقام وكشف الشركة
 * **يُبلَّغ ولا يُصلَح من هنا**.
 *
 * والتاريخ `created_at` للطرد لا `delivered_at`: الشركة تُحاسبك على ما أرسلتَه
 * في الشهر، والطرد المُرسَل في آخر الشهر يُسلَّم في الذي يليه — فلو عُدّ بتاريخ
 * التسليم لاختلف مجموعُك عن كشفها كلَّ شهر.
 */
class DeliveryCostReportService
{
    /**
     * الطرود ضمن فترة، مُصفّاةً بالمدينة والمنطقة.
     *
     * والمرتجعة داخلة عمدًا: الشركة تتقاضى أجرة الإرجاع، وإخفاؤها يُظهر تكلفةً
     * أقلّ من فاتورتها.
     */
    private function query(DateRange $range, ?int $cityId, ?int $areaId)
    {
        return Shipment::query()
            ->whereBetween('shipments.created_at', [$range->from, $range->to])
            ->when($cityId, fn ($q) => $q->where('shipments.city_id', $cityId))
            ->when($areaId, fn ($q) => $q->where('shipments.area_id', $areaId));
    }

    /**
     * صفٌّ لكل (مدينة · منطقة) بعدد طرودها وما دُفع عنها.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(DateRange $range, ?int $cityId = null, ?int $areaId = null): Collection
    {
        return $this->query($range, $cityId, $areaId)
            ->leftJoin('cities', 'cities.id', '=', 'shipments.city_id')
            ->leftJoin('areas', 'areas.id', '=', 'shipments.area_id')
            ->groupBy('shipments.city_id', 'shipments.area_id', 'cities.name', 'areas.name')
            ->selectRaw('shipments.city_id, shipments.area_id, '
                .'cities.name as city, areas.name as area, '
                .'COUNT(*) as parcels, '
                .'SUM(COALESCE(shipments.shipping_cost, 0)) as cost, '
                // طردٌ بلا تكلفة يعني رسمًا لم يُكتب — يُعدّ ولا يُخفى.
                .'SUM(CASE WHEN COALESCE(shipments.shipping_cost, 0) = 0 THEN 1 ELSE 0 END) as unpriced')
            ->get()
            ->map(fn ($r) => [
                'city_id' => $r->city_id,
                'area_id' => $r->area_id,
                'city' => $r->city ?? __('بلا مدينة'),
                'area' => $r->area ?? __('بلا منطقة'),
                'parcels' => (int) $r->parcels,
                'cost' => round((float) $r->cost, 2),
                'unpriced' => (int) $r->unpriced,
                'avg' => $r->parcels > 0 ? round((float) $r->cost / (int) $r->parcels, 2) : 0.0,
            ])
            ->sortByDesc('cost')
            ->values();
    }

    /**
     * الإجماليّات.
     *
     * `unpriced` هو رقم المراجعة: طرودٌ خرجت بلا رسمٍ مكتوب عندنا، وستأتي في
     * فاتورة الشركة. فوجودُها يفسّر فرقًا بين مجموعنا ومجموعها قبل أن يُبحث عنه.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function totals(Collection $rows): array
    {
        $parcels = (int) $rows->sum('parcels');
        $cost = round((float) $rows->sum('cost'), 2);

        return [
            'parcels' => $parcels,
            'cost' => $cost,
            'unpriced' => (int) $rows->sum('unpriced'),
            'avg' => $parcels > 0 ? round($cost / $parcels, 2) : 0.0,
        ];
    }
}
