<?php

use App\Http\Controllers\Admin\Business\BillingController;
use App\Http\Controllers\Admin\Business\GrowthController;
use App\Http\Controllers\Admin\Business\OnboardingController;
use App\Http\Controllers\Admin\Business\PlansController;
use App\Http\Controllers\Admin\Business\SalesController;
use Illuminate\Support\Facades\Route;

/*
 | The console's business pages (docs/ADMIN.md, "Business"): billing, plans,
 | book sales, growth and onboarding.
 |
 | Required from inside routes/admin.php's group, so every route here is
 | already behind `platform.admin` (404 to everybody else) and
 | `platform.2fa`. All staff can read; every change is the owner's and
 | throttled like every console write.
 */

Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
Route::get('/plans', [PlansController::class, 'index'])->name('plans.index');
Route::get('/sales', [SalesController::class, 'index'])->name('sales.index');
Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');

Route::prefix('growth')->name('growth.')->group(function () {
    Route::redirect('/', '/admin/growth/acquisition')->name('index');
    Route::get('/acquisition', [GrowthController::class, 'acquisition'])->name('acquisition');
    Route::get('/audiences', [GrowthController::class, 'audiences'])->name('audiences.index');
    Route::get('/broadcasts', [GrowthController::class, 'broadcasts'])->name('broadcasts.index');
    Route::get('/broadcasts/new', [GrowthController::class, 'editBroadcast'])->name('broadcasts.create');
    Route::get('/broadcasts/{broadcast}', [GrowthController::class, 'editBroadcast'])->whereNumber('broadcast')->name('broadcasts.edit');
    Route::get('/discounts', [GrowthController::class, 'discounts'])->name('discounts.index');
    Route::get('/lifecycle', [GrowthController::class, 'lifecycle'])->name('lifecycle.index');
});

Route::middleware(['can:platform.owner', 'throttle:admin-actions'])->group(function () {
    // Money: refunds through the gateway, plans, recorded payouts.
    Route::post('/billing/orders/{order}/refund', [BillingController::class, 'refundOrder'])->whereNumber('order')->name('billing.refund-order');
    Route::post('/billing/credits/{transaction}/refund', [BillingController::class, 'refundCredits'])->whereNumber('transaction')->name('billing.refund-credits');
    Route::post('/plans', [PlansController::class, 'store'])->name('plans.store');
    Route::patch('/plans/{plan}', [PlansController::class, 'update'])->whereNumber('plan')->name('plans.update');
    Route::post('/sales/payouts/{user}', [SalesController::class, 'payout'])->whereNumber('user')->name('sales.payout');

    Route::prefix('growth')->name('growth.')->group(function () {
        Route::post('/audiences', [GrowthController::class, 'storeAudience'])->name('audiences.store');
        Route::patch('/audiences/{audience}', [GrowthController::class, 'updateAudience'])->whereNumber('audience')->name('audiences.update');
        Route::delete('/audiences/{audience}', [GrowthController::class, 'destroyAudience'])->whereNumber('audience')->name('audiences.destroy');

        Route::post('/broadcasts', [GrowthController::class, 'storeBroadcast'])->name('broadcasts.store');
        Route::patch('/broadcasts/{broadcast}', [GrowthController::class, 'updateBroadcast'])->whereNumber('broadcast')->name('broadcasts.update');
        Route::post('/broadcasts/{broadcast}/schedule', [GrowthController::class, 'scheduleBroadcast'])->whereNumber('broadcast')->name('broadcasts.schedule');
        Route::post('/broadcasts/{broadcast}/cancel', [GrowthController::class, 'cancelBroadcast'])->whereNumber('broadcast')->name('broadcasts.cancel');
        Route::post('/broadcasts/{broadcast}/test', [GrowthController::class, 'testBroadcast'])->whereNumber('broadcast')->name('broadcasts.test');

        Route::post('/discounts', [GrowthController::class, 'storeDiscount'])->name('discounts.store');
        Route::patch('/discounts/{discount}', [GrowthController::class, 'updateDiscount'])->whereNumber('discount')->name('discounts.update');

        Route::patch('/lifecycle/{campaign}', [GrowthController::class, 'updateLifecycle'])->whereNumber('campaign')->name('lifecycle.update');
        Route::post('/lifecycle/{campaign}/test', [GrowthController::class, 'testLifecycle'])->whereNumber('campaign')->name('lifecycle.test');
    });
});

// Reads that compute, while a form is being filled in: the owner's, and
// throttled, though they change nothing.
Route::middleware(['can:platform.owner', 'throttle:60,1'])->prefix('growth')->name('growth.')->group(function () {
    Route::post('/audiences/preview', [GrowthController::class, 'preview'])->name('audiences.preview');
    Route::post('/broadcasts/preview', [GrowthController::class, 'renderPreview'])->name('broadcasts.preview');
});
