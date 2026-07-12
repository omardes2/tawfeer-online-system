<?php

use App\Http\Controllers\Admin\Accounting\AccountingController as AdminAccountingController;
use App\Http\Controllers\Admin\Accounting\JournalEntryController as AdminJournalEntryController;
use App\Http\Controllers\Admin\Catalog\BrandController;
use App\Http\Controllers\Admin\Catalog\CategoryController;
use App\Http\Controllers\Admin\Catalog\ProductAttributeController;
use App\Http\Controllers\Admin\Catalog\ProductController;
use App\Http\Controllers\Admin\Catalog\ProductTagController;
use App\Http\Controllers\Admin\Catalog\UnitController;
use App\Http\Controllers\Admin\Crm\CustomerController as AdminCustomerController;
use App\Http\Controllers\Admin\Inventory\InventoryController;
use App\Http\Controllers\Admin\Inventory\StockAdjustmentController as AdminStockAdjustmentController;
use App\Http\Controllers\Admin\Payment\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\Purchasing\GoodsReceiptController as AdminGoodsReceiptController;
use App\Http\Controllers\Admin\Purchasing\PurchaseOrderController as AdminPurchaseOrderController;
use App\Http\Controllers\Admin\Purchasing\SupplierController as AdminSupplierController;
use App\Http\Controllers\Admin\Purchasing\SupplierReturnController as AdminSupplierReturnController;
use App\Http\Controllers\Admin\Sales\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\Shipping\GeographyController as AdminGeographyController;
use App\Http\Controllers\Admin\Shipping\ShipmentController as AdminShipmentController;
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
use App\Http\Controllers\Storefront\StorefrontController;
use Illuminate\Support\Facades\Route;

/*
| واجهة المتجر العامّة (Phase 3.3 / ADR-034) — SSR للـSEO، عربي RTL + إنجليزي.
| قراءة عبر StorefrontService؛ السلة/الإتمام عبر واجهات API (3.1/3.2) من العميل.
*/
Route::middleware('storefront.locale')->group(function () {
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

    /*
    | حساب العميل (Phase 3.4 / ADR-035) — جلسة الويب، عربي RTL + إنجليزي، noindex.
    */
    Route::middleware('guest')->group(function () {
        Route::get('/account/login', [AccountAuthController::class, 'showLogin'])->name('account.login');
        Route::post('/account/login', [AccountAuthController::class, 'login']);
        Route::get('/account/register', [AccountAuthController::class, 'showRegister'])->name('account.register');
        Route::post('/account/register', [AccountAuthController::class, 'register']);

        // استرجاع كلمة المرور (Phase 3.5).
        Route::get('/account/forgot-password', [PasswordResetController::class, 'request'])->name('account.password.request');
        Route::post('/account/forgot-password', [PasswordResetController::class, 'email'])->name('account.password.email');
        Route::get('/account/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('account.password.reset');
        Route::post('/account/reset-password', [PasswordResetController::class, 'update'])->name('account.password.store');
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
    });

    // المدفوعات (Phase 2.8)
    Route::resource('payments', AdminPaymentController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('payments/{payment}/capture', [AdminPaymentController::class, 'capture'])->name('payments.capture');
    Route::post('payments/{payment}/refund', [AdminPaymentController::class, 'refund'])->name('payments.refund');

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
