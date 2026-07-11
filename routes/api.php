<?php

use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\Catalog\BrandController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductAttributeController;
use App\Http\Controllers\Api\V1\Catalog\ProductAttributeValueController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductImageController;
use App\Http\Controllers\Api\V1\Catalog\ProductTagController;
use App\Http\Controllers\Api\V1\Catalog\UnitController;
use App\Http\Controllers\Api\V1\Inventory\InventoryLedgerController;
use App\Http\Controllers\Api\V1\Inventory\InventoryMovementController;
use App\Http\Controllers\Api\V1\Inventory\InventoryOperationController;
use App\Http\Controllers\Api\V1\Inventory\InventoryStockController;
use App\Http\Controllers\Api\V1\Inventory\StockAdjustmentController;
use App\Http\Controllers\Api\V1\Inventory\StockReservationController;
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

    // مسارات محميّة بـ Sanctum.
    Route::middleware('auth:sanctum')->group(function () {

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
        });

    });
});
