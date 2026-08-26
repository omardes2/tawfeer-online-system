<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\EmployeeLeave;
use App\Modules\Hr\Models\EmployeeProfile;
use Illuminate\Support\Carbon;

/**
 * رصيد الإجازة السنوية.
 *
 * ## أيامٌ لا مال
 *
 * الإجازة المدفوعة لا تُنشئ قيدًا: الموظف يقبض راتبه كاملًا سواءٌ عمل أم أخذ
 * إجازته المستحقّة، فلا مصروف زائد ولا التزام جديد. وغيرُ المدفوعة تُخصَم من
 * راتب شهرها في المسيّر — وذاك خصمٌ من مصروفٍ قائم لا التزامٌ يُقيَّد.
 *
 * ## والاستحقاق بالتناسب لا كاملًا
 *
 * من عُيّن في تشرين لا يستحقّ إجازة سنةٍ كاملة في كانون الأول. فاستحقاقُ
 * السنة الأولى بنسبة أشهر الخدمة فيها — وإلا لظهر رصيدٌ لم يُكتسَب.
 */
class LeaveService
{
    /**
     * رصيد سنةٍ ميلادية.
     *
     * @return array{entitlement: float, taken: float, remaining: float, unpaid: float, sick: float}
     */
    public function balance(EmployeeProfile $profile, int $year): array
    {
        $entitlement = $this->entitlementFor($profile, $year);

        $byKind = EmployeeLeave::where('employee_profile_id', $profile->id)
            ->inYear($year)
            ->selectRaw('kind, COALESCE(SUM(days), 0) as d')
            ->groupBy('kind')
            ->pluck('d', 'kind');

        $taken = round((float) ($byKind['annual'] ?? 0), 2);

        return [
            'entitlement' => $entitlement,
            'taken' => $taken,
            'remaining' => round($entitlement - $taken, 2),
            'unpaid' => round((float) ($byKind['unpaid'] ?? 0), 2),
            'sick' => round((float) ($byKind['sick'] ?? 0), 2),
        ];
    }

    /**
     * المستحقّ في سنةٍ بعينها — كاملًا لسنةٍ خُدمت كلّها، وبالتناسب لسنة
     * التعيين أو سنة انتهاء الخدمة.
     */
    public function entitlementFor(EmployeeProfile $profile, int $year): float
    {
        $full = (float) $profile->annual_leave_days;

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        $from = $profile->hire_date->gt($yearStart) ? $profile->hire_date->copy()->startOfDay() : $yearStart;
        $to = $profile->end_date && $profile->end_date->lt($yearEnd)
            ? $profile->end_date->copy()->endOfDay()
            : $yearEnd;

        if ($to->lt($from)) {
            return 0.0;
        }

        // النسبة بالأيام لا بالأشهر: من عُيّن في منتصف شهرٍ يستحقّ نصفه.
        $served = $from->diffInDays($to) + 1;
        $inYear = $yearStart->diffInDays($yearEnd) + 1;

        return round($full * min(1, $served / $inYear), 2);
    }

    /** أيام الإجازة غير المدفوعة الواقعة في شهرٍ بعينه — ما يُخصَم من مسيّره. */
    public function unpaidDaysInMonth(EmployeeProfile $profile, int $year, int $month): float
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        return round((float) EmployeeLeave::where('employee_profile_id', $profile->id)
            ->kind('unpaid')
            ->whereBetween('from_date', [$start->toDateString(), $end->toDateString()])
            ->sum('days'), 2);
    }
}
