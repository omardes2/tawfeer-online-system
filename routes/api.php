<?php

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
            'status'  => 'ok',
            'service' => config('app.name'),
            'time'    => now()->toIso8601String(),
        ]);
    })->name('api.health');

    // مسارات محميّة بـ Sanctum.
    Route::middleware('auth:sanctum')->group(function () {

        // المستخدم الحالي مع أدواره وصلاحياته.
        Route::get('/me', function (Request $request) {
            return new UserResource($request->user()->load('branch'));
        })->name('api.me');

    });
});
