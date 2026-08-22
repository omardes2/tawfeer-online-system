<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;

/**
 * المدن ومناطقها كما هي في النظام.
 *
 * إنشاء الطلب يحتاج `city_id` و`area_id`، ولا سبيل للنموذج إلى معرفتهما إلّا
 * من هنا: لو تُرك يخمّنهما لأنشأ طلبًا لمدينةٍ أخرى — والعنوان الخاطئ طردٌ
 * يُسلَّم إلى مكانٍ لا زبون فيه.
 *
 * ## Protected Delivery Integration — Do Not Modify
 *
 * قراءةٌ فقط من جداول المدن والمناطق القائمة، بلا قائمةٍ ثابتة في الكود وبلا
 * تعديلٍ في مسار التوصيل. والمناطق تُقرأ لمدينةٍ واحدة لا للكتالوج كلّه:
 * تمريرُ كل مناطق البلاد إلى النموذج يُنفق توكنز بلا مقابل ويُغريه بالاختيار
 * عن الزبون.
 */
class ListDeliveryAreasTool implements ToolContract
{
    public function name(): string
    {
        return 'list_delivery_areas';
    }

    public function description(): string
    {
        return 'اعرض المدن المتاحة للتوصيل، أو مناطق مدينةٍ بعينها. '
            .'استعملها قبل إنشاء الطلب — لا تخمّن المدينة أو المنطقة.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'city_id' => [
                    'type' => 'integer',
                    'description' => 'اتركه فارغًا لعرض المدن، أو مرّره لعرض مناطق تلك المدينة.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $cityId = (int) ($arguments['city_id'] ?? 0);

        if ($cityId <= 0) {
            return [
                'cities' => City::query()->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (City $c) => ['id' => $c->id, 'name' => $c->name])
                    ->all(),
            ];
        }

        $city = City::find($cityId);

        if ($city === null) {
            return ['error' => 'not_found', 'message' => 'المدينة غير موجودة.'];
        }

        return [
            'city' => ['id' => $city->id, 'name' => $city->name],
            'areas' => Area::where('city_id', $city->id)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Area $a) => ['id' => $a->id, 'name' => $a->name])
                ->all(),
        ];
    }
}
