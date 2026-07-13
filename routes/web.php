<?php

use App\Http\Controllers\Admin\Accounting\AccountingController as AdminAccountingController;
use App\Http\Controllers\Admin\Accounting\JournalEntryController as AdminJournalEntryController;
use App\Http\Controllers\Admin\Ai\AiContentController;
use App\Http\Controllers\Admin\Catalog\BrandController;
use App\Http\Controllers\Admin\Catalog\CategoryController;
use App\Http\Controllers\Admin\Catalog\ProductAttributeController;
use App\Http\Controllers\Admin\Catalog\ProductController;
use App\Http\Controllers\Admin\Catalog\ProductTagController;
use App\Http\Controllers\Admin\Catalog\UnitController;
use App\Http\Controllers\Admin\Commissions\CommissionController as AdminCommissionController;
use App\Http\Controllers\Admin\Crm\CustomerController as AdminCustomerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\Inventory\InventoryController;
use App\Http\Controllers\Admin\Inventory\InventoryCountController as AdminInventoryCountController;
use App\Http\Controllers\Admin\Inventory\StockAdjustmentController as AdminStockAdjustmentController;
use App\Http\Controllers\Admin\Inventory\WarehouseController as AdminWarehouseController;
use App\Http\Controllers\Admin\Marketing\CampaignController as AdminCampaignController;
use App\Http\Controllers\Admin\Marketing\CampaignTemplateController as AdminCampaignTemplateController;
use App\Http\Controllers\Admin\Payment\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\Purchasing\GoodsReceiptController as AdminGoodsReceiptController;
use App\Http\Controllers\Admin\Purchasing\PurchaseOrderController as AdminPurchaseOrderController;
use App\Http\Controllers\Admin\Purchasing\SupplierController as AdminSupplierController;
use App\Http\Controllers\Admin\Purchasing\SupplierReturnController as AdminSupplierReturnController;
use App\Http\Controllers\Admin\Recommendations\RecommendationRuleController as AdminRecommendationRuleController;
use App\Http\Controllers\Admin\Reports\KpiController as AdminKpiController;
use App\Http\Controllers\Admin\Reports\ReportController as AdminReportController;
use App\Http\Controllers\Admin\Returns\ReturnController as AdminReturnController;
use App\Http\Controllers\Admin\Roles\RoleController as AdminRoleController;
use App\Http\Controllers\Admin\Sales\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\Settings\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\Settlements\SettlementController as AdminSettlementController;
use App\Http\Controllers\Admin\Shipping\DeliveryStatusController as AdminDeliveryStatusController;
use App\Http\Controllers\Admin\Shipping\GeographyController as AdminGeographyController;
use App\Http\Controllers\Admin\Shipping\ShipmentController as AdminShipmentController;
use App\Http\Controllers\Admin\Users\UserController as AdminUserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Storefront\Account\AccountController;
use App\Http\Controllers\Storefront\Account\AddressController;
use App\Http\Controllers\Storefront\Account\AuthController as AccountAuthController;
use App\Http\Controllers\Storefront\Account\LinkedProviderController;
use App\Http\Controllers\Storefront\Account\NotificationController as AccountNotificationController;
use App\Http\Controllers\Storefront\Account\OrderController as AccountOrderController;
use App\Http\Controllers\Storefront\Account\PasswordResetController;
use App\Http\Controllers\Storefront\Account\PreferenceController;
use App\Http\Controllers\Storefront\Account\ProfileCompletionController;
use App\Http\Controllers\Storefront\Account\ProfileController as AccountProfileController;
use App\Http\Controllers\Storefront\Account\SocialAuthController;
use App\Http\Controllers\Storefront\Account\WishlistController;
use App\Http\Controllers\Storefront\RecommendationTrackingController;
use App\Http\Controllers\Storefront\StorefrontController;
use App\Http\Middleware\EnforceMaintenanceMode;
use Illuminate\Support\Facades\Route;

/*
| واجهة المتجر العامّة (Phase 3.3 / ADR-034) — SSR للـSEO، عربي RTL + إنجليزي.
| قراءة عبر StorefrontService؛ السلة/الإتمام عبر واجهات API (3.1/3.2) من العميل.
*/
Route::middleware(['storefront.locale', EnforceMaintenanceMode::class])->group(function () {
    Route::get('/', [StorefrontController::class, 'home'])->name('storefront.home');
    Route::get('/shop', [StorefrontController::class, 'index'])->name('storefront.shop');
    Route::get('/search', [StorefrontController::class, 'search'])->name('storefront.search');
    Route::get('/categories', [StorefrontController::class, 'categories'])->name('storefront.categories');
    Route::get('/brands', [StorefrontController::class, 'brands'])->name('storefront.brands');
    Route::get('/c/{slug}', [StorefrontController::class, 'category'])->name('storefront.category');
    Route::get('/b/{slug}', [StorefrontController::class, 'brand'])->name('storefront.brand');
    Route::get('/p/{slug}', [StorefrontController::class, 'show'])->name('storefront.product');
    Route::get('/cart', [StorefrontController::class, 'cart'])->name('storefront.cart');
    Route::get('/checkout', [StorefrontController::class, 'checkout'])->middleware('profile.complete')->name('storefront.checkout');
    Route::get('/lang/{locale}', [StorefrontController::class, 'setLocale'])->name('storefront.locale');

    // تتبّع أحداث التوصيات (انطباع/نقر/تحويل) — Phase 6 / ADR-045. عام + محدود المعدّل.
    Route::post('/recommendations/track', [RecommendationTrackingController::class, 'store'])
        ->middleware('throttle:60,1')->name('storefront.recommendations.track');

    /*
    | حساب العميل (Phase 3.4 / ADR-035) — جلسة الويب، عربي RTL + إنجليزي، noindex.
    */
    Route::middleware('guest')->group(function () {
        Route::get('/account/login', [AccountAuthController::class, 'showLogin'])->name('account.login');
        // حدّ معدّل ضدّ حشو بيانات الاعتماد (Security Review) — الدخول/التسجيل.
        Route::post('/account/login', [AccountAuthController::class, 'login'])->middleware('throttle:10,1');
        Route::get('/account/register', [AccountAuthController::class, 'showRegister'])->name('account.register');
        Route::post('/account/register', [AccountAuthController::class, 'register'])->middleware('throttle:10,1');

        // استرجاع كلمة المرور (Phase 3.5) — محدود لمنع التعداد/الإغراق.
        Route::get('/account/forgot-password', [PasswordResetController::class, 'request'])->name('account.password.request');
        Route::post('/account/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:6,1')->name('account.password.email');
        Route::get('/account/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('account.password.reset');
        Route::post('/account/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:6,1')->name('account.password.store');
    });

    // الدخول الاجتماعي (Phase 3.5 / ADR-036) — login أو link حسب النيّة.
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('social.callback');

    Route::middleware('require.customer')->prefix('account')->name('account.')->group(function () {
        Route::post('logout', [AccountAuthController::class, 'logout'])->name('logout');
        Route::get('/', [AccountController::class, 'dashboard'])->name('dashboard');

        Route::get('profile', [AccountProfileController::class, 'edit'])->name('profile');
        Route::patch('profile', [AccountProfileController::class, 'update'])->name('profile.update');
        Route::patch('password', [AccountProfileController::class, 'updatePassword'])->name('password.update');

        Route::get('orders', [AccountOrderController::class, 'index'])->name('orders');
        Route::get('orders/{order}', [AccountOrderController::class, 'show'])->name('orders.show');
        Route::get('orders/{order}/status', [AccountOrderController::class, 'status'])->name('orders.status');
        Route::post('orders/{order}/reorder', [AccountOrderController::class, 'reorder'])->name('orders.reorder');

        Route::get('addresses', [AddressController::class, 'index'])->name('addresses');
        Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::patch('addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
        Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
        Route::post('addresses/{address}/default', [AddressController::class, 'setDefault'])->name('addresses.default');

        Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist');
        Route::post('wishlist/{product}/toggle', [WishlistController::class, 'toggle'])->name('wishlist.toggle');

        Route::get('preferences', [PreferenceController::class, 'edit'])->name('preferences');
        Route::patch('preferences', [PreferenceController::class, 'update'])->name('preferences.update');

        // إكمال الملف بعد الدخول الاجتماعي (Phase 3.5).
        Route::get('complete-profile', [ProfileCompletionController::class, 'show'])->name('profile.complete');
        Route::post('complete-profile', [ProfileCompletionController::class, 'store'])->name('profile.complete.store');

        // مزوّدو الدخول المربوطون (Phase 3.5).
        Route::get('providers', [LinkedProviderController::class, 'index'])->name('providers');
        Route::get('providers/{provider}/link', [SocialAuthController::class, 'redirectLink'])->name('providers.link');
        Route::delete('providers/{provider}', [LinkedProviderController::class, 'unlink'])->name('providers.unlink');

        Route::get('notifications', [AccountNotificationController::class, 'index'])->name('notifications');
        Route::post('notifications/{id}/read', [AccountNotificationController::class, 'markRead'])->name('notifications.read');
        Route::post('notifications/read-all', [AccountNotificationController::class, 'markAllRead'])->name('notifications.read_all');
    });
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
| لوحة إدارة الكتالوج (Phase 2.2) — واجهات عربية RTL محكومة بالصلاحيات (Policies).
*/
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('categories', CategoryController::class)->except('show');
    Route::resource('brands', BrandController::class)->except('show');
    Route::resource('units', UnitController::class)->except('show');
    Route::resource('tags', ProductTagController::class)->except('show');
    Route::resource('attributes', ProductAttributeController::class)->except('show');
    Route::post('attributes/{attribute}/values', [ProductAttributeController::class, 'storeValue'])->name('attributes.values.store');
    Route::delete('attributes/{attribute}/values/{value}', [ProductAttributeController::class, 'destroyValue'])->name('attributes.values.destroy');

    // المنتجات ووسائطها (Phase 2.3)
    Route::resource('products', ProductController::class)->except('show');
    Route::post('products/{product}/images', [ProductController::class, 'storeImage'])->name('products.images.store');
    Route::post('products/{product}/images/{image}/primary', [ProductController::class, 'setPrimaryImage'])->name('products.images.primary');
    Route::delete('products/{product}/images/{image}', [ProductController::class, 'destroyImage'])->name('products.images.destroy');

    // المخزون (Phase 2.4)
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('stocks', [InventoryController::class, 'stocks'])->name('stocks');
        Route::get('movements', [InventoryController::class, 'movements'])->name('movements');
        Route::get('reservations', [InventoryController::class, 'reservations'])->name('reservations');
        Route::post('reservations/{reservation}/release', [InventoryController::class, 'releaseReservation'])->name('reservations.release');
        Route::get('operations', [InventoryController::class, 'operations'])->name('operations');
        Route::post('operations/receive', [InventoryController::class, 'receive'])->name('operations.receive');
        Route::post('operations/issue', [InventoryController::class, 'issue'])->name('operations.issue');
        Route::post('operations/transfer', [InventoryController::class, 'transfer'])->name('operations.transfer');
        Route::resource('adjustments', AdminStockAdjustmentController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
        Route::post('adjustments/{adjustment}/approve', [AdminStockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('adjustments/{adjustment}/post', [AdminStockAdjustmentController::class, 'post'])->name('adjustments.post');

        // Phase 5 — لوحة المستودع + جرد + تنبيهات نقص + باركود + دفعات
        Route::get('warehouse', [AdminWarehouseController::class, 'dashboard'])->name('warehouse')->middleware('can:inventory.stocks.view');
        Route::get('low-stock', [AdminWarehouseController::class, 'lowStock'])->name('low_stock')->middleware('can:inventory.alerts.view');
        Route::get('barcode', [AdminWarehouseController::class, 'barcode'])->name('barcode')->middleware('can:inventory.stocks.view');
        Route::get('batch', [AdminWarehouseController::class, 'batch'])->name('batch')->middleware('can:inventory.batch');
        Route::post('batch', [AdminWarehouseController::class, 'batchStore'])->name('batch.store')->middleware('can:inventory.batch');

        Route::get('counts', [AdminInventoryCountController::class, 'index'])->name('counts.index')->middleware('can:inventory.counts.view');
        Route::post('counts', [AdminInventoryCountController::class, 'store'])->name('counts.store')->middleware('can:inventory.counts.manage');
        Route::get('counts/{count}', [AdminInventoryCountController::class, 'show'])->name('counts.show')->middleware('can:inventory.counts.view');
        Route::post('counts/{count}/record', [AdminInventoryCountController::class, 'record'])->name('counts.record')->middleware('can:inventory.counts.manage');
        Route::post('counts/{count}/record-batch', [AdminInventoryCountController::class, 'recordBatch'])->name('counts.record_batch')->middleware('can:inventory.counts.manage');
        Route::post('counts/{count}/review', [AdminInventoryCountController::class, 'review'])->name('counts.review')->middleware('can:inventory.counts.manage');
        Route::post('counts/{count}/complete', [AdminInventoryCountController::class, 'complete'])->name('counts.complete')->middleware('can:inventory.counts.manage');
        Route::post('counts/{count}/cancel', [AdminInventoryCountController::class, 'cancel'])->name('counts.cancel')->middleware('can:inventory.counts.manage');
    });

    // المشتريات (Phase 2.5)
    Route::prefix('purchasing')->name('purchasing.')->group(function () {
        Route::resource('suppliers', AdminSupplierController::class)->except('show');

        Route::resource('orders', AdminPurchaseOrderController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('orders/{order}/submit', [AdminPurchaseOrderController::class, 'submit'])->name('orders.submit');
        Route::post('orders/{order}/approve', [AdminPurchaseOrderController::class, 'approve'])->name('orders.approve');
        Route::post('orders/{order}/cancel', [AdminPurchaseOrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('orders/{order}/close', [AdminPurchaseOrderController::class, 'close'])->name('orders.close');

        Route::resource('receipts', AdminGoodsReceiptController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('receipts/{receipt}/post', [AdminGoodsReceiptController::class, 'post'])->name('receipts.post');

        Route::resource('returns', AdminSupplierReturnController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('returns/{return}/approve', [AdminSupplierReturnController::class, 'approve'])->name('returns.approve');
        Route::post('returns/{return}/post', [AdminSupplierReturnController::class, 'post'])->name('returns.post');
    });

    // المبيعات (Phase 2.6)
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::resource('orders', AdminOrderController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('orders/{order}/confirm', [AdminOrderController::class, 'confirm'])->name('orders.confirm');
        Route::post('orders/{order}/reserve', [AdminOrderController::class, 'reserve'])->name('orders.reserve');
        Route::post('orders/{order}/prepare', [AdminOrderController::class, 'prepare'])->name('orders.prepare');
        Route::post('orders/{order}/ready', [AdminOrderController::class, 'ready'])->name('orders.ready');
        Route::post('orders/{order}/ship', [AdminOrderController::class, 'ship'])->name('orders.ship');
        Route::post('orders/{order}/deliver', [AdminOrderController::class, 'deliver'])->name('orders.deliver');
        Route::post('orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->name('orders.cancel');
    });

    // الشحن والجغرافيا (Phase 2.7)
    Route::prefix('shipping')->name('shipping.')->group(function () {
        Route::get('geography', [AdminGeographyController::class, 'index'])->name('geography.index');
        Route::resource('shipments', AdminShipmentController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('shipments/{shipment}/dispatch', [AdminShipmentController::class, 'dispatchShipment'])->name('shipments.dispatch');
        Route::post('shipments/{shipment}/out-for-delivery', [AdminShipmentController::class, 'outForDelivery'])->name('shipments.out_for_delivery');
        Route::post('shipments/{shipment}/deliver', [AdminShipmentController::class, 'deliver'])->name('shipments.deliver');
        Route::post('shipments/{shipment}/fail', [AdminShipmentController::class, 'fail'])->name('shipments.fail');

        // محرّك حالة التوصيل القانوني (Phase 4.3 / ADR-038)
        Route::get('delivery', [AdminDeliveryStatusController::class, 'index'])->name('delivery.index')->middleware('can:shipping.delivery.view');
        Route::get('delivery/{shipment}', [AdminDeliveryStatusController::class, 'show'])->name('delivery.show')->middleware('can:shipping.delivery.view');
        Route::post('delivery/{shipment}/transition', [AdminDeliveryStatusController::class, 'transition'])->name('delivery.transition')->middleware('can:shipping.delivery.manage');
        Route::post('delivery/{shipment}/close', [AdminDeliveryStatusController::class, 'close'])->name('delivery.close')->middleware('can:shipping.delivery.close');
        Route::post('delivery/{shipment}/exceptions', [AdminDeliveryStatusController::class, 'openException'])->name('delivery.exceptions.open')->middleware('can:shipping.delivery.manage');
        Route::post('delivery/exceptions/{exception}/resolve', [AdminDeliveryStatusController::class, 'resolveException'])->name('delivery.exceptions.resolve')->middleware('can:shipping.delivery.manage');
        Route::post('delivery/{shipment}/fees', [AdminDeliveryStatusController::class, 'addFee'])->name('delivery.fees.add')->middleware('can:shipping.delivery.fees');
    });

    // المدفوعات (Phase 2.8)
    Route::resource('payments', AdminPaymentController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('payments/{payment}/capture', [AdminPaymentController::class, 'capture'])->name('payments.capture');
    Route::post('payments/{payment}/refund', [AdminPaymentController::class, 'refund'])->name('payments.refund');

    // العمولات/الأرباح (Phase 4.2)
    Route::prefix('commissions')->name('commissions.')->group(function () {
        Route::get('/', [AdminCommissionController::class, 'index'])->name('index')->middleware('can:commissions.view_team');
        Route::get('statement/{earnerId}', [AdminCommissionController::class, 'statement'])->name('statement')->middleware('can:commissions.view_team');
        Route::post('approve', [AdminCommissionController::class, 'approve'])->name('approve')->middleware('can:commissions.approve');
        Route::post('payout', [AdminCommissionController::class, 'payout'])->name('payout')->middleware('can:commissions.payout');
        Route::get('rules', [AdminCommissionController::class, 'rules'])->name('rules')->middleware('can:commissions.rules.manage');
        Route::post('rules', [AdminCommissionController::class, 'storeRule'])->name('rules.store')->middleware('can:commissions.rules.manage');
        Route::delete('rules/{rule}', [AdminCommissionController::class, 'destroyRule'])->name('rules.destroy')->middleware('can:commissions.rules.manage');
    });

    // التقارير والتحليلات (Phase 4.7) — للقراءة فقط
    Route::prefix('reports')->name('reports.')->middleware('can:reports.view')->group(function () {
        Route::get('/', [AdminReportController::class, 'index'])->name('index');
        Route::get('executive', [AdminReportController::class, 'executive'])->name('executive');
        Route::get('sales', [AdminReportController::class, 'sales'])->name('sales');
        Route::get('orders', [AdminReportController::class, 'orders'])->name('orders');
        Route::get('delivery', [AdminReportController::class, 'delivery'])->name('delivery');
        Route::get('finance', [AdminReportController::class, 'finance'])->name('finance');
        Route::get('sales-employees', [AdminReportController::class, 'salesEmployees'])->name('sales_employees');
        Route::get('marketers', [AdminReportController::class, 'marketers'])->name('marketers');
        Route::get('products', [AdminReportController::class, 'products'])->name('products');
        Route::get('customers', [AdminReportController::class, 'customers'])->name('customers');
        Route::get('delivery-companies', [AdminReportController::class, 'deliveryCompanies'])->name('delivery_companies');
        Route::get('returns', [AdminReportController::class, 'returns'])->name('returns');
    });

    // لوحة التحكّم التنفيذية (Production) — للقراءة فقط
    Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard')->middleware('can:dashboard.view');

    // لوحات مؤشّرات الأداء الموسّعة (Phase 6 / ADR-047) — للقراءة فقط
    Route::get('kpis', [AdminKpiController::class, 'index'])->name('kpis')->middleware('can:kpis.view');

    // إدارة المستخدمين/الموظّفين (Production)
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->name('index')->middleware('can:settings.users.view');
        Route::get('create', [AdminUserController::class, 'create'])->name('create')->middleware('can:settings.users.create');
        Route::post('/', [AdminUserController::class, 'store'])->name('store')->middleware('can:settings.users.create');
        Route::get('{user}/edit', [AdminUserController::class, 'edit'])->name('edit')->middleware('can:settings.users.update');
        Route::put('{user}', [AdminUserController::class, 'update'])->name('update')->middleware('can:settings.users.update');
        Route::delete('{user}', [AdminUserController::class, 'destroy'])->name('destroy')->middleware('can:settings.users.delete');
        Route::post('{user}/toggle', [AdminUserController::class, 'toggleActive'])->name('toggle')->middleware('can:settings.users.update');
        Route::post('{user}/reset-password', [AdminUserController::class, 'resetPassword'])->name('reset_password')->middleware('can:settings.users.update');
    });

    // إدارة الأدوار والصلاحيات (Production)
    Route::prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [AdminRoleController::class, 'index'])->name('index')->middleware('can:settings.roles.view');
        Route::get('create', [AdminRoleController::class, 'create'])->name('create')->middleware('can:settings.roles.manage');
        Route::post('/', [AdminRoleController::class, 'store'])->name('store')->middleware('can:settings.roles.manage');
        Route::get('{role}/edit', [AdminRoleController::class, 'edit'])->name('edit')->middleware('can:settings.roles.manage');
        Route::put('{role}', [AdminRoleController::class, 'update'])->name('update')->middleware('can:settings.roles.manage');
        Route::post('{role}/copy', [AdminRoleController::class, 'copy'])->name('copy')->middleware('can:settings.roles.manage');
        Route::delete('{role}', [AdminRoleController::class, 'destroy'])->name('destroy')->middleware('can:settings.roles.manage');
    });

    // إعدادات النظام (Production) — ديناميكية من قاعدة البيانات
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [AdminSettingsController::class, 'edit'])->name('edit')->middleware('can:settings.system.view');
        Route::put('/', [AdminSettingsController::class, 'update'])->name('update')->middleware('can:settings.system.manage');
    });

    // مساعد محتوى المنتجات بالذكاء الاصطناعي (Phase 6 / ADR-044) — اقتراح فقط
    Route::post('ai/content/generate', [AiContentController::class, 'generate'])->name('ai.content.generate')->middleware('can:ai.content.use');
    // زر «توليد بالذكاء الاصطناعي» — حزمة محتوى منتج كاملة (اقتراح فقط)
    Route::post('ai/content/bundle', [AiContentController::class, 'bundle'])->name('ai.content.bundle')->middleware('can:ai.content.use');

    // محرّك التوصيات — قواعد يدوية واستثناءات (Phase 6 / ADR-045)
    Route::prefix('recommendations')->name('recommendations.')->middleware('can:recommendations.manage')->group(function () {
        Route::get('/', [AdminRecommendationRuleController::class, 'index'])->name('index');
        Route::post('/', [AdminRecommendationRuleController::class, 'store'])->name('store');
        Route::delete('{recommendation}', [AdminRecommendationRuleController::class, 'destroy'])->name('destroy');
    });

    // أتمتة التسويق — الحملات والقوالب (Phase 6 / ADR-046)
    Route::prefix('marketing')->name('marketing.')->group(function () {
        Route::get('templates', [AdminCampaignTemplateController::class, 'index'])->name('templates.index')->middleware('can:marketing.templates.manage');
        Route::post('templates', [AdminCampaignTemplateController::class, 'store'])->name('templates.store')->middleware('can:marketing.templates.manage');
        Route::delete('templates/{template}', [AdminCampaignTemplateController::class, 'destroy'])->name('templates.destroy')->middleware('can:marketing.templates.manage');

        Route::get('campaigns', [AdminCampaignController::class, 'index'])->name('campaigns.index')->middleware('can:marketing.campaigns.view');
        Route::get('campaigns/create', [AdminCampaignController::class, 'create'])->name('campaigns.create')->middleware('can:marketing.campaigns.manage');
        Route::post('campaigns', [AdminCampaignController::class, 'store'])->name('campaigns.store')->middleware('can:marketing.campaigns.manage');
        Route::get('campaigns/{campaign}', [AdminCampaignController::class, 'show'])->name('campaigns.show')->middleware('can:marketing.campaigns.view');
        Route::post('campaigns/{campaign}/submit', [AdminCampaignController::class, 'submit'])->name('campaigns.submit')->middleware('can:marketing.campaigns.manage');
        Route::post('campaigns/{campaign}/approve', [AdminCampaignController::class, 'approve'])->name('campaigns.approve')->middleware('can:marketing.campaigns.approve');
        Route::post('campaigns/{campaign}/activate', [AdminCampaignController::class, 'activate'])->name('campaigns.activate')->middleware('can:marketing.campaigns.approve');
        Route::post('campaigns/{campaign}/pause', [AdminCampaignController::class, 'pause'])->name('campaigns.pause')->middleware('can:marketing.campaigns.manage');
        Route::post('campaigns/{campaign}/test', [AdminCampaignController::class, 'test'])->name('campaigns.test')->middleware('can:marketing.campaigns.manage');
    });

    // التسويات المالية (Phase 4.6)
    Route::prefix('settlements')->name('settlements.')->group(function () {
        Route::get('/', [AdminSettlementController::class, 'index'])->name('index')->middleware('can:settlements.view');
        Route::get('create', [AdminSettlementController::class, 'create'])->name('create')->middleware('can:settlements.manage');
        Route::post('/', [AdminSettlementController::class, 'store'])->name('store')->middleware('can:settlements.manage');
        Route::get('{settlement}', [AdminSettlementController::class, 'show'])->name('show')->middleware('can:settlements.view');
        Route::post('{settlement}/shipments', [AdminSettlementController::class, 'addShipments'])->name('shipments')->middleware('can:settlements.manage');
        Route::post('{settlement}/reconcile', [AdminSettlementController::class, 'reconcile'])->name('reconcile')->middleware('can:settlements.reconcile');
        Route::post('{settlement}/post', [AdminSettlementController::class, 'post'])->name('post')->middleware('can:settlements.post');
        Route::post('{settlement}/close', [AdminSettlementController::class, 'close'])->name('close')->middleware('can:settlements.post');
        Route::post('{settlement}/cancel', [AdminSettlementController::class, 'cancel'])->name('cancel')->middleware('can:settlements.manage');
    });

    // المرتجعات والاستبدال RMA (Phase 4.4)
    Route::prefix('returns')->name('returns.')->group(function () {
        Route::get('/', [AdminReturnController::class, 'index'])->name('index')->middleware('can:returns.view');
        Route::get('create', [AdminReturnController::class, 'create'])->name('create')->middleware('can:returns.create');
        Route::post('/', [AdminReturnController::class, 'store'])->name('store')->middleware('can:returns.create');
        Route::get('{return}', [AdminReturnController::class, 'show'])->name('show')->middleware('can:returns.view');
        Route::post('{return}/photo', [AdminReturnController::class, 'photo'])->name('photo')->middleware('can:returns.create');
        Route::post('{return}/approve', [AdminReturnController::class, 'approve'])->name('approve')->middleware('can:returns.approve');
        Route::post('{return}/reject', [AdminReturnController::class, 'reject'])->name('reject')->middleware('can:returns.approve');
        Route::post('{return}/cancel', [AdminReturnController::class, 'cancel'])->name('cancel')->middleware('can:returns.create');
        Route::post('{return}/receive', [AdminReturnController::class, 'receive'])->name('receive')->middleware('can:returns.receive');
        Route::post('{return}/inspect', [AdminReturnController::class, 'inspect'])->name('inspect')->middleware('can:returns.inspect');
        Route::post('{return}/complete', [AdminReturnController::class, 'complete'])->name('complete')->middleware('can:returns.finalize');
    });

    // CRM/العملاء (Phase 2.10)
    Route::prefix('crm')->name('crm.')->group(function () {
        Route::resource('customers', AdminCustomerController::class)->except('destroy');
        Route::post('customers/{customer}/notes', [AdminCustomerController::class, 'addNote'])->name('customers.notes.store');
        Route::post('customers/{customer}/block', [AdminCustomerController::class, 'block'])->name('customers.block');
        Route::post('customers/{customer}/unblock', [AdminCustomerController::class, 'unblock'])->name('customers.unblock');
    });

    // المحاسبة (Phase 2.9)
    Route::prefix('accounting')->name('accounting.')->group(function () {
        Route::get('accounts', [AdminAccountingController::class, 'accounts'])->name('accounts.index');
        Route::get('reports/trial-balance', [AdminAccountingController::class, 'trialBalance'])->name('reports.trial_balance');
        Route::resource('journal', AdminJournalEntryController::class)->only(['index', 'create', 'store', 'show'])->parameters(['journal' => 'journalEntry']);
        Route::post('journal/{journalEntry}/post', [AdminJournalEntryController::class, 'post'])->name('journal.post');
        Route::post('journal/{journalEntry}/reverse', [AdminJournalEntryController::class, 'reverse'])->name('journal.reverse');
    });
});

require __DIR__.'/auth.php';
