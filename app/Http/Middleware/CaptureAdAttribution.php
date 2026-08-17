<?php

namespace App\Http\Middleware;

use App\Modules\Marketing\Services\AdAttributionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * التقاط معاملات الإعلان من أول زيارةٍ للمتجر.
 *
 * على صفحات المتجر وحدها لا على الـAPI: النقرة تصل بصفحةٍ كاملة، ونداءات
 * الواجهة اللاحقة لا تحمل معاملاتها. وهي **لا تلمس شيئًا** من مسار السلة أو
 * الإتمام — تقرأ من عنوان الصفحة وتكتب كعكة، ولا تُغيّر طلبًا ولا استجابة.
 */
class CaptureAdAttribution
{
    public function __construct(private readonly AdAttributionService $attribution) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            $this->attribution->capture($request);
        }

        return $next($request);
    }
}
