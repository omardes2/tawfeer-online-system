<?php

namespace App\Modules\Marketing\Console;

use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Services\CampaignService;
use Illuminate\Console\Command;

/**
 * تشغيل حملات عيد الميلاد اليومية (Phase 6 / ADR-046).
 * تعتمد birth_date الموجود (بلا بيانات جديدة). المرجع اليومي يضمن idempotency.
 */
class RunBirthdayCampaignsCommand extends Command
{
    protected $signature = 'marketing:run-birthdays';

    protected $description = 'تشغيل حملات عيد الميلاد المفعّلة لعملاء اليوم';

    public function handle(CampaignService $campaigns): int
    {
        $active = Campaign::query()->active()->where('use_case', 'birthday')->get();

        foreach ($active as $campaign) {
            $summary = $campaigns->run($campaign, 'birthday:'.now()->format('Ymd'));
            $this->info($campaign->name.': '.json_encode($summary));
        }

        return self::SUCCESS;
    }
}
