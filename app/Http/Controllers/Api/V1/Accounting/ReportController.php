<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function trialBalance(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('accounting.reports.view'), 403);

        $fiscalYear = $request->filled('fiscal_year')
            ? FiscalYear::where('uuid', $request->string('fiscal_year'))->first()
            : null;

        $rows = $this->accounting->trialBalance($fiscalYear);

        return response()->json([
            'data' => $rows,
            'totals' => [
                'debit' => round($rows->sum('debit'), 2),
                'credit' => round($rows->sum('credit'), 2),
            ],
        ]);
    }
}
