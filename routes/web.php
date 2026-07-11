<?php

use App\Http\Controllers\Admin\Catalog\BrandController;
use App\Http\Controllers\Admin\Catalog\CategoryController;
use App\Http\Controllers\Admin\Catalog\ProductAttributeController;
use App\Http\Controllers\Admin\Catalog\ProductController;
use App\Http\Controllers\Admin\Catalog\ProductTagController;
use App\Http\Controllers\Admin\Catalog\UnitController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
});

require __DIR__.'/auth.php';
