<?php

namespace App\Modules\Purchasing\Providers;

use App\Modules\Purchasing\Console\AuditSupplierLedgerCommand;
use App\Modules\Purchasing\Console\AuditVariantSplitCostsCommand;
use App\Modules\Purchasing\Console\MergeSuppliersCommand;
use App\Modules\Purchasing\Console\SplitInvoiceVariantsCommand;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\ImportShipment;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Modules\Purchasing\Policies\GoodsReceiptPolicy;
use App\Modules\Purchasing\Policies\ImportShipmentPolicy;
use App\Modules\Purchasing\Policies\PurchaseOrderPolicy;
use App\Modules\Purchasing\Policies\SupplierPolicy;
use App\Modules\Purchasing\Policies\SupplierReturnPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(GoodsReceipt::class, GoodsReceiptPolicy::class);
        Gate::policy(SupplierReturn::class, SupplierReturnPolicy::class);
        Gate::policy(ImportShipment::class, ImportShipmentPolicy::class);

        // غير مجدولة عمدًا: أمرٌ يمسّ بنود فاتورةٍ مُرحّلة يُشغَّل بيدٍ ويُقرأ
        // ناتجه قبل اعتماده، لا يعمل وحده في الليل.
        if ($this->app->runningInConsole()) {
            $this->commands([
                // الفحص يقرأ ولا يكتب — يُشغَّل قبل التوزيع ليُعرف حجم الفرق.
                AuditSupplierLedgerCommand::class,
                AuditVariantSplitCostsCommand::class,
                MergeSuppliersCommand::class,
                SplitInvoiceVariantsCommand::class,
            ]);
        }
    }
}
