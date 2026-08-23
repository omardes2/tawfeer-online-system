<?php

namespace App\Modules\AiAgent\Providers;

use App\Modules\AiAgent\Console\AgentCheckCommand;
use App\Modules\AiAgent\Tools\CheckStockTool;
use App\Modules\AiAgent\Tools\CreateDraftOrderTool;
use App\Modules\AiAgent\Tools\EscalateToHumanTool;
use App\Modules\AiAgent\Tools\GetPriceTool;
use App\Modules\AiAgent\Tools\GetProductDetailsTool;
use App\Modules\AiAgent\Tools\ListDeliveryAreasTool;
use App\Modules\AiAgent\Tools\SearchProductsTool;
use App\Modules\AiAgent\Tools\ToolRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * وحدة وكيل المبيعات.
 *
 * السجلّ مفردٌ (singleton) ويُبنى من قائمةٍ صريحة: أداةٌ لا تُذكر هنا لا يراها
 * النموذج. والاكتشاف التلقائي مرفوض عمدًا — إضافة ملفٍّ إلى مجلد لا يجوز أن
 * تمنح ذكاءً اصطناعيًّا قدرةً جديدة على نظامٍ يبيع.
 */
class AiAgentServiceProvider extends ServiceProvider
{
    /** @var array<int, class-string> ترتيبها ترتيبُ عرضها على النموذج. */
    private const TOOLS = [
        SearchProductsTool::class,
        GetProductDetailsTool::class,
        CheckStockTool::class,
        GetPriceTool::class,
        ListDeliveryAreasTool::class,
        CreateDraftOrderTool::class,
        EscalateToHumanTool::class,
    ];

    public function boot(): void
    {
        // غير مجدول: أمرُ فحصٍ يُشغَّل بيدٍ عند التجربة أو حين لا يردّ الوكيل.
        if ($this->app->runningInConsole()) {
            $this->commands([AgentCheckCommand::class]);
        }
    }

    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry;

            foreach (self::TOOLS as $tool) {
                $registry->register($app->make($tool));
            }

            return $registry;
        });
    }
}
