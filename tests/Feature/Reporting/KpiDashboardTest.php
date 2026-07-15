<?php

namespace Tests\Feature\Reporting;

use App\Modules\Ai\Models\AiGenerationLog;
use App\Modules\Recommendations\Models\RecommendationEvent;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\DateRange;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_kpis_aggregate_growth_signals(): void
    {
        AiGenerationLog::create([
            'user_id' => null, 'type' => 'title_ar', 'action' => 'generate', 'locale' => 'ar',
            'provider' => 'null', 'model' => 'null-template', 'total_tokens' => 42, 'status' => 'success', 'created_at' => now(),
        ]);
        RecommendationEvent::create(['recommended_product_id' => null, 'event' => 'impression', 'created_at' => now()]);
        RecommendationEvent::create(['recommended_product_id' => null, 'event' => 'click', 'created_at' => now()]);

        $kpis = app(ReportingService::class)->kpis(DateRange::resolve('month'));

        $this->assertArrayHasKey('sales', $kpis);
        $this->assertArrayHasKey('delivery', $kpis);
        $this->assertArrayHasKey('recommendations', $kpis);
        $this->assertSame(42, $kpis['ai']['tokens']);
        $this->assertSame(1, $kpis['ai']['requests']);
        $this->assertSame(1, $kpis['recommendations']['impressions']);
        $this->assertSame(1, $kpis['recommendations']['clicks']);
        $this->assertSame(100.0, $kpis['recommendations']['ctr']);
    }
}
