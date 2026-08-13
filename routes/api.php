<?php

use App\Http\Controllers\Api\V1\Accounting\AccountController;
use App\Http\Controllers\Api\V1\Accounting\JournalEntryController;
use App\Http\Controllers\Api\V1\Accounting\ReportController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\Catalog\BrandController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductAttributeController;
use App\Http\Controllers\Api\V1\Catalog\ProductAttributeValueController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductImageController;
use App\Http\Controllers\Api\V1\Catalog\ProductTagController;
use App\Http\Controllers\Api\V1\Catalog\UnitController;
use App\Http\Controllers\Api\V1\Commissions\CommissionController;
use App\Http\Controllers\Api\V1\Commissions\CommissionRuleController;
use App\Http\Controllers\Api\V1\Crm\CustomerController;
use App\Http\Controllers\Api\V1\Inventory\InventoryCountController;
use App\Http\Controllers\Api\V1\Inventory\InventoryLedgerController;
use App\Http\Controllers\Api\V1\Inventory\InventoryMovementController;
use App\Http\Controllers\Api\V1\Inventory\InventoryOperationController;
use App\Http\Controllers\Api\V1\Inventory\InventoryStockController;
use App\Http\Controllers\Api\V1\Inventory\StockAdjustmentController;
use App\Http\Controllers\Api\V1\Inventory\StockReservationController;
use App\Http\Controllers\Api\V1\Inventory\WarehouseInsightController;
use App\Http\Controllers\Api\V1\Payment\PaymentController;
use App\Http\Controllers\Api\V1\Payment\PaymentMethodController;
use App\Http\Controllers\Api\V1\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Api\V1\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Purchasing\SupplierController;
use App\Http\Controllers\Api\V1\Purchasing\SupplierReturnController;
use App\Http\Controllers\Api\V1\Returns\ReturnController;
use App\Http\Controllers\Api\V1\Sales\AssistedOrderController;
use App\Http\Controllers\Api\V1\Sales\OrderController;
use App\Http\Controllers\Api\V1\Sales\OrderTransitionController;
use App\Http\Controllers\Api\V1\Settlements\SettlementController;
use App\Http\Controllers\Api\V1\Shipping\DeliveryExceptionController;
use App\Http\Controllers\Api\V1\Shipping\DeliveryFeeController;
use App\Http\Controllers\Api\V1\Shipping\DeliveryStatusController;
use App\Http\Controllers\Api\V1\Shipping\DeliveryWebhookController;
use App\Http\Controllers\Api\V1\Shipping\GeographyController;
use App\Http\Controllers\Api\V1\Shipping\ShipmentController;
use App\Http\Controllers\Api\V1\Store\CartController;
use App\Http\Controllers\Api\V1\Store\CheckoutController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WarehouseLocationController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| مسارات API (المبدأ 11: API-First)
|--------------------------------------------------------------------------
| مُصمَّمة بإصدار (v1) واستجابات موحّدة عبر API Resources، ومصادقة Sanctum،
| جاهزة لاستهلاكها من تطبيق موبايل لاحقًا.
*/

Route::prefix('v1')->group(function () {

    // فحص صحّة الخدمة — عام.
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => config('app.name'),
            'time' => now()->toIso8601String(),
        ]);
    })->name('api.health');

    // webhook مزوّدي التوصيل — عام (تحقّق التوقيع/idempotency في الخدمة/الـDriver، ADR-039).
    // محدود المعدّل بالـIP لمنع الإغراق/التخمين (Security Review).
    Route::post('webhooks/delivery/{provider}', [DeliveryWebhookController::class, 'handle'])
        ->middleware('throttle:60,1')->name('api.webhooks.delivery');

    // مسارات محميّة بـ Sanctum + حدّ معدّل عام (Security Review).
    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {

        // المستخدم الحالي مع أدواره وصلاحياته.
        Route::get('/me', function (Request $request) {
            return new UserResource($request->user()->load('branch'));
        })->name('api.me');

        /*
        | Phase 2.1 — البنية التنظيمية (الفروع/المستودعات/المواقع)
        | كل مسار محكوم بصلاحية دقيقة (ADR-021، المبدأ 12).
        */

        // الفروع
        Route::get('branches', [BranchController::class, 'index'])->middleware('can:settings.branches.view');
        Route::post('branches', [BranchController::class, 'store'])->middleware('can:settings.branches.create');
        Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('can:settings.branches.view');
        Route::match(['put', 'patch'], 'branches/{branch}', [BranchController::class, 'update'])->middleware('can:settings.branches.update');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('can:settings.branches.delete');

        // المستودعات
        Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('can:settings.warehouses.view');
        Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('can:settings.warehouses.create');
        Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->middleware('can:settings.warehouses.view');
        Route::match(['put', 'patch'], 'warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('can:settings.warehouses.update');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('can:settings.warehouses.delete');

        // مواقع التخزين (متداخلة تحت المستودع, ربط مُنطاق)
        Route::scopeBindings()->group(function () {
            Route::get('warehouses/{warehouse}/locations', [WarehouseLocationController::class, 'index'])->middleware('can:settings.warehouse_locations.view');
            Route::post('warehouses/{warehouse}/locations', [WarehouseLocationController::class, 'store'])->middleware('can:settings.warehouse_locations.create');
            Route::get('warehouses/{warehouse}/locations/{location}', [WarehouseLocationController::class, 'show'])->middleware('can:settings.warehouse_locations.view');
            Route::match(['put', 'patch'], 'warehouses/{warehouse}/locations/{location}', [WarehouseLocationController::class, 'update'])->middleware('can:settings.warehouse_locations.update');
            Route::delete('warehouses/{warehouse}/locations/{location}', [WarehouseLocationController::class, 'destroy'])->middleware('can:settings.warehouse_locations.delete');
        });

        /*
        | Phase 2.2 — الكتالوج (الفئات/العلامات/الوحدات/السمات/الوسوم)
        | الصلاحيات عبر Policies (authorizeResource) — ADR-021، المبدأ 12.
        | الفئات/العلامات تُربط بـ uuid؛ المراجع (وحدات/سمات/وسوم) بـ id (ADR-002).
        */
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('brands', BrandController::class);
        Route::apiResource('units', UnitController::class);
        Route::apiResource('attributes', ProductAttributeController::class);
        Route::apiResource('attributes.values', ProductAttributeValueController::class)->scoped();
        Route::apiResource('tags', ProductTagController::class);

        /*
        | Phase 2.3 — المنتجات ووسائطها
        */
        Route::apiResource('products', ProductController::class);
        Route::scopeBindings()->group(function () {
            Route::get('products/{product}/images', [ProductImageController::class, 'index']);
            Route::post('products/{product}/images', [ProductImageController::class, 'store']);
            Route::post('products/{product}/images/{image}/primary', [ProductImageController::class, 'setPrimary']);
            Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy']);
        });

        /*
        | Phase 2.4 — المخزون (أرصدة/حركات/دفتر/عمليات/حجوزات/تسويات)
        | الصلاحيات عبر Policies وسلاسل صلاحيات دقيقة (ADR-021).
        */
        Route::prefix('inventory')->group(function () {
            Route::get('stocks', [InventoryStockController::class, 'index']);
            Route::get('movements', [InventoryMovementController::class, 'index']);
            Route::get('ledger', [InventoryLedgerController::class, 'index']);

            Route::post('receive', [InventoryOperationController::class, 'receive']);
            Route::post('issue', [InventoryOperationController::class, 'issue']);
            Route::post('transfer', [InventoryOperationController::class, 'transfer']);

            Route::get('reservations', [StockReservationController::class, 'index']);
            Route::post('reservations', [StockReservationController::class, 'store']);
            Route::post('reservations/{reservation}/release', [StockReservationController::class, 'release']);

            Route::get('adjustments', [StockAdjustmentController::class, 'index']);
            Route::post('adjustments', [StockAdjustmentController::class, 'store']);
            Route::get('adjustments/{adjustment}', [StockAdjustmentController::class, 'show']);
            Route::delete('adjustments/{adjustment}', [StockAdjustmentController::class, 'destroy']);
            Route::post('adjustments/{adjustment}/approve', [StockAdjustmentController::class, 'approve']);
            Route::post('adjustments/{adjustment}/post', [StockAdjustmentController::class, 'post']);

            // Phase 5 — رؤى المستودع + الجرد الفعلي (ADR-043)
            Route::get('barcode', [WarehouseInsightController::class, 'barcode'])->middleware('can:inventory.stocks.view');
            Route::get('low-stock', [WarehouseInsightController::class, 'lowStock'])->middleware('can:inventory.alerts.view');
            Route::get('warehouse-dashboard', [WarehouseInsightController::class, 'dashboard'])->middleware('can:inventory.stocks.view');
            Route::get('counts', [InventoryCountController::class, 'index'])->middleware('can:inventory.counts.view');
            Route::post('counts', [InventoryCountController::class, 'store'])->middleware('can:inventory.counts.manage');
            Route::get('counts/{count}', [InventoryCountController::class, 'show'])->middleware('can:inventory.counts.view');
            Route::post('counts/{count}/record', [InventoryCountController::class, 'record'])->middleware('can:inventory.counts.manage');
            Route::post('counts/{count}/review', [InventoryCountController::class, 'review'])->middleware('can:inventory.counts.manage');
            Route::post('counts/{count}/complete', [InventoryCountController::class, 'complete'])->middleware('can:inventory.counts.manage');
            Route::post('counts/{count}/cancel', [InventoryCountController::class, 'cancel'])->middleware('can:inventory.counts.manage');
        });

        /*
        | Phase 2.5 — المشتريات (الموردون/أوامر الشراء/الاستلام/المرتجعات)
        | الصلاحيات عبر Policies؛ الاستلام والمرتجع يمرّان حصريًا عبر محرّك المخزون.
        */
        Route::prefix('purchasing')->group(function () {
            Route::apiResource('suppliers', SupplierController::class);

            Route::get('orders', [PurchaseOrderController::class, 'index']);
            Route::post('orders', [PurchaseOrderController::class, 'store']);
            Route::get('orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
            Route::match(['put', 'patch'], 'orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
            Route::delete('orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
            Route::post('orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
            Route::post('orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
            Route::post('orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
            Route::post('orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close']);

            Route::get('receipts', [GoodsReceiptController::class, 'index']);
            Route::post('receipts', [GoodsReceiptController::class, 'store']);
            Route::get('receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show']);
            Route::post('receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post']);

            Route::get('returns', [SupplierReturnController::class, 'index']);
            Route::post('returns', [SupplierReturnController::class, 'store']);
            Route::get('returns/{supplierReturn}', [SupplierReturnController::class, 'show']);
            Route::post('returns/{supplierReturn}/approve', [SupplierReturnController::class, 'approve']);
            Route::post('returns/{supplierReturn}/post', [SupplierReturnController::class, 'post']);
        });

        /*
        | Phase 2.6 — المبيعات (طلبات البيع)
        | آلة حالات ADR-010؛ الحجز/الاستهلاك/التحرير حصريًا عبر محرّك المخزون (ADR-009).
        */
        Route::prefix('sales')->group(function () {
            Route::get('orders', [OrderController::class, 'index']);
            Route::post('orders', [OrderController::class, 'store']);
            Route::get('orders/{order}', [OrderController::class, 'show']);
            Route::match(['put', 'patch'], 'orders/{order}', [OrderController::class, 'update']);
            Route::delete('orders/{order}', [OrderController::class, 'destroy']);

            Route::post('orders/{order}/confirm', [OrderTransitionController::class, 'confirm']);
            Route::post('orders/{order}/reserve', [OrderTransitionController::class, 'reserve']);
            Route::post('orders/{order}/prepare', [OrderTransitionController::class, 'prepare']);
            Route::post('orders/{order}/ready', [OrderTransitionController::class, 'ready']);
            Route::post('orders/{order}/ship', [OrderTransitionController::class, 'ship']);
            Route::post('orders/{order}/deliver', [OrderTransitionController::class, 'deliver']);
            Route::post('orders/{order}/cancel', [OrderTransitionController::class, 'cancel']);

            // البيع المُساعد (Phase 4.1 / ADR-037).
            Route::post('assisted-orders', [AssistedOrderController::class, 'store'])->middleware('can:sales.orders.assist');
            Route::get('assisted-orders/price-changes', [AssistedOrderController::class, 'priceChanges'])->middleware('can:sales.orders.override_price');
            Route::post('assisted-orders/price-changes/{priceChange}/approve', [AssistedOrderController::class, 'approve'])->middleware('can:sales.orders.override_price');
            Route::post('assisted-orders/price-changes/{priceChange}/reject', [AssistedOrderController::class, 'reject'])->middleware('can:sales.orders.override_price');
        });

        /*
        | العمولات/الأرباح (Phase 4.2 / ADR-037). دفتر غير قابل للتعديل + آلة حالات.
        */
        Route::prefix('commissions')->group(function () {
            Route::get('entries', [CommissionController::class, 'index']);
            Route::get('statement/{earnerId}', [CommissionController::class, 'statement']);
            Route::post('approve', [CommissionController::class, 'approve'])->middleware('can:commissions.approve');
            Route::post('payout', [CommissionController::class, 'payout'])->middleware('can:commissions.payout');

            Route::get('rules', [CommissionRuleController::class, 'index'])->middleware('can:commissions.rules.manage');
            Route::post('rules', [CommissionRuleController::class, 'store'])->middleware('can:commissions.rules.manage');
            Route::match(['put', 'patch'], 'rules/{rule}', [CommissionRuleController::class, 'update'])->middleware('can:commissions.rules.manage');
            Route::delete('rules/{rule}', [CommissionRuleController::class, 'destroy'])->middleware('can:commissions.rules.manage');
        });

        /*
        | Phase 2.7 — الشحن والجغرافيا (ADR-014/027)
        | الجغرافيا (قراءة) + الشحنات؛ المزوّدون حصريًا عبر طبقة التكامل.
        */
        Route::prefix('geo')->group(function () {
            Route::get('governorates', [GeographyController::class, 'governorates']);
            Route::get('cities', [GeographyController::class, 'cities']);
            Route::get('areas', [GeographyController::class, 'areas']);
            Route::get('shipping-zones', [GeographyController::class, 'shippingZones']);
        });

        Route::prefix('shipping')->group(function () {
            Route::get('shipments', [ShipmentController::class, 'index']);
            Route::post('shipments', [ShipmentController::class, 'store']);
            Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);
            Route::post('shipments/{shipment}/dispatch', [ShipmentController::class, 'dispatchShipment']);
            Route::post('shipments/{shipment}/out-for-delivery', [ShipmentController::class, 'outForDelivery']);
            Route::post('shipments/{shipment}/delay', [ShipmentController::class, 'delay']);
            Route::post('shipments/{shipment}/customer-unavailable', [ShipmentController::class, 'customerUnavailable']);
            Route::post('shipments/{shipment}/deliver', [ShipmentController::class, 'deliver']);
            Route::post('shipments/{shipment}/fail', [ShipmentController::class, 'fail']);
            Route::post('shipments/{shipment}/override-cost', [ShipmentController::class, 'overrideCost']);

            /*
            | Phase 4.3 — محرّك حالة التوصيل القانوني (ADR-038)
            | حالة قانونية داخلية منفصلة عن حالة المزوّد الخام؛ التعيين في الـDriver.
            | CLOSE = نقطة الاكتمال المالي الوحيدة (تُفعّل التسوية واستحقاق العمولات).
            */
            Route::get('shipments/{shipment}/delivery', [DeliveryStatusController::class, 'show'])->middleware('can:shipping.delivery.view');
            Route::get('shipments/{shipment}/delivery/timeline', [DeliveryStatusController::class, 'timeline'])->middleware('can:shipping.delivery.view');
            Route::get('delivery/hold-reasons', [DeliveryStatusController::class, 'holdReasons'])->middleware('can:shipping.delivery.view');
            Route::post('shipments/{shipment}/delivery/transition', [DeliveryStatusController::class, 'transition'])->middleware('can:shipping.delivery.manage');
            Route::post('shipments/{shipment}/delivery/provider-status', [DeliveryStatusController::class, 'providerStatus'])->middleware('can:shipping.delivery.sync');
            Route::post('shipments/{shipment}/delivery/close', [DeliveryStatusController::class, 'close'])->middleware('can:shipping.delivery.close');

            // استثناءات التوصيل (SLA/تصعيد/ملاحظات) — ADR-039
            Route::get('shipments/{shipment}/exceptions', [DeliveryExceptionController::class, 'index'])->middleware('can:shipping.delivery.view');
            Route::post('shipments/{shipment}/exceptions', [DeliveryExceptionController::class, 'store'])->middleware('can:shipping.delivery.manage');
            Route::post('delivery/exceptions/{exception}/note', [DeliveryExceptionController::class, 'note'])->middleware('can:shipping.delivery.manage');
            Route::post('delivery/exceptions/{exception}/assign', [DeliveryExceptionController::class, 'assign'])->middleware('can:shipping.delivery.manage');
            Route::post('delivery/exceptions/{exception}/escalate', [DeliveryExceptionController::class, 'escalate'])->middleware('can:shipping.delivery.manage');
            Route::post('delivery/exceptions/{exception}/resolve', [DeliveryExceptionController::class, 'resolve'])->middleware('can:shipping.delivery.manage');
            Route::post('delivery/exceptions/{exception}/reopen', [DeliveryExceptionController::class, 'reopen'])->middleware('can:shipping.delivery.manage');

            // رسوم الشحنة المُركّبة (مستقلّة عن المزوّد) — ADR-039
            Route::get('shipments/{shipment}/fees', [DeliveryFeeController::class, 'index'])->middleware('can:shipping.delivery.view');
            Route::post('shipments/{shipment}/fees', [DeliveryFeeController::class, 'store'])->middleware('can:shipping.delivery.fees');
        });

        /*
        | Phase 4.4 — المرتجعات والاستبدال RMA (ADR-040)
        | سير موافقات: مبيعات → مشرف → مستودع → قرار نهائي. الأثر عكسي عبر الخدمات القائمة.
        */
        /*
        | Phase 4.6 — التسويات المالية ومطابقة المزوّد (ADR-041)
        | مطابقة بيان المزوّد + ترحيل محاسبي عبر AccountingService القائم.
        */
        Route::prefix('settlements')->group(function () {
            Route::get('/', [SettlementController::class, 'index'])->middleware('can:settlements.view');
            Route::post('/', [SettlementController::class, 'store'])->middleware('can:settlements.manage');
            Route::get('{settlement}', [SettlementController::class, 'show'])->middleware('can:settlements.view');
            Route::post('{settlement}/shipments', [SettlementController::class, 'addShipments'])->middleware('can:settlements.manage');
            Route::post('{settlement}/lines', [SettlementController::class, 'addLine'])->middleware('can:settlements.manage');
            Route::post('{settlement}/reconcile', [SettlementController::class, 'reconcile'])->middleware('can:settlements.reconcile');
            Route::post('{settlement}/post', [SettlementController::class, 'post'])->middleware('can:settlements.post');
            Route::post('{settlement}/close', [SettlementController::class, 'close'])->middleware('can:settlements.post');
            Route::post('{settlement}/cancel', [SettlementController::class, 'cancel'])->middleware('can:settlements.manage');
        });

        Route::prefix('rma')->group(function () {
            Route::get('/', [ReturnController::class, 'index'])->middleware('can:returns.view');
            Route::post('/', [ReturnController::class, 'store'])->middleware('can:returns.create');
            Route::get('{return}', [ReturnController::class, 'show'])->middleware('can:returns.view');
            Route::post('{return}/photo', [ReturnController::class, 'photo'])->middleware('can:returns.create');
            Route::post('{return}/approve', [ReturnController::class, 'approve'])->middleware('can:returns.approve');
            Route::post('{return}/reject', [ReturnController::class, 'reject'])->middleware('can:returns.approve');
            Route::post('{return}/cancel', [ReturnController::class, 'cancel'])->middleware('can:returns.create');
            Route::post('{return}/receive', [ReturnController::class, 'receive'])->middleware('can:returns.receive');
            Route::post('{return}/inspect', [ReturnController::class, 'inspect'])->middleware('can:returns.inspect');
            Route::post('{return}/complete', [ReturnController::class, 'complete'])->middleware('can:returns.finalize');
        });

        /*
        | Phase 2.8 — المدفوعات (ADR-028)
        | مزوّدو الدفع حصريًا عبر طبقة التكامل (PaymentProviderManager).
        */
        Route::prefix('payments')->group(function () {
            Route::get('methods', [PaymentMethodController::class, 'index']);
            Route::get('/', [PaymentController::class, 'index']);
            Route::post('/', [PaymentController::class, 'store']);
            Route::get('{payment}', [PaymentController::class, 'show']);
            Route::post('{payment}/capture', [PaymentController::class, 'capture']);
            Route::post('{payment}/refund', [PaymentController::class, 'refund']);
            Route::post('{payment}/callback', [PaymentController::class, 'callback']);
        });

        /*
        | Phase 2.9 — المحاسبة (ADR-029/016)
        | قيد مزدوج؛ قيود غير قابلة للتعديل؛ الأرصدة تُشتقّ من الدفتر؛ العزل عبر AccountingService والأحداث.
        */
        Route::prefix('accounting')->group(function () {
            Route::get('accounts', [AccountController::class, 'index']);
            Route::post('accounts', [AccountController::class, 'store']);
            Route::get('accounts/{account}/balance', [AccountController::class, 'balance']);

            Route::get('journal-entries', [JournalEntryController::class, 'index']);
            Route::post('journal-entries', [JournalEntryController::class, 'store']);
            Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show']);
            Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post']);
            Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse']);

            Route::get('reports/trial-balance', [ReportController::class, 'trialBalance']);
        });

        /*
        | Phase 2.10 — CRM/العملاء (ADR-030، BR-CUST)
        | تعدد الهواتف/العناوين/جهات الاتصال/الملاحظات؛ حظر/دمج؛ لقطة عميل ثابتة على الطلبات.
        */
        Route::prefix('crm')->group(function () {
            Route::get('customers/duplicates', [CustomerController::class, 'duplicates']);
            Route::get('customers', [CustomerController::class, 'index']);
            Route::post('customers', [CustomerController::class, 'store']);
            Route::get('customers/{customer}', [CustomerController::class, 'show']);
            Route::match(['put', 'patch'], 'customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);
            Route::post('customers/{customer}/notes', [CustomerController::class, 'addNote']);
            Route::post('customers/{customer}/block', [CustomerController::class, 'block']);
            Route::post('customers/{customer}/unblock', [CustomerController::class, 'unblock']);
            Route::post('customers/{customer}/merge', [CustomerController::class, 'merge']);
        });

    });

    /*
    | Phase 3.1/3.2 — المتجر: السلة وإتمام الشراء (ADR-031/033)
    | هوية مزدوجة (store.identity): مستخدم مُصادَق أو ضيف برمز سلة (X-Cart-Token).
    */
    Route::prefix('store')->middleware('store.identity')->group(function () {
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'addItem']);
        Route::match(['put', 'patch'], 'cart/items/{variant}', [CartController::class, 'updateItem']);
        Route::delete('cart/items/{variant}', [CartController::class, 'removeItem']);
        Route::delete('cart', [CartController::class, 'clear']);

        // إتمام الشراء متعدّد الخطوات: بدء جلسة → تحديث بيانات → إتمام (ADR-033).
        // `orders.enabled`: بوّابة الطلب أونلاين. إغلاق الصفحة وحدها لا يكفي —
        // هذه النقاط تُنادى من JavaScript مباشرة فيبقى الطلب ممكنًا بدونها.
        Route::middleware('orders.enabled')->group(function () {
            Route::post('checkout', [CheckoutController::class, 'start']);
            Route::get('checkout/{session}', [CheckoutController::class, 'show']);
            Route::match(['put', 'patch'], 'checkout/{session}', [CheckoutController::class, 'update']);
            Route::post('checkout/{session}/place', [CheckoutController::class, 'place']);
        });
    });
});
