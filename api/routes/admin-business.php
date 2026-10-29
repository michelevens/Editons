<?php

use App\Http\Controllers\Admin\Business\BillingController;
use App\Http\Controllers\Admin\Business\GrowthController;
use App\Http\Controllers\Admin\Business\InvitationsController;
use App\Http\Controllers\Admin\Business\OnboardingController;
use App\Http\Controllers\Admin\Business\PartnersController;
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
// An author's (or a house imprint's) royalty statement as a PDF: every
// download is audited, since it is another account's money.
Route::get('/sales/statements/{user}/{period}.pdf', [SalesController::class, 'statement'])
    ->whereNumber('user')
    ->where('period', '\d{4}-(0[1-9]|1[0-2]|Q[1-4])')
    ->middleware('throttle:sales-exports')
    ->name('sales.statement');
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

/*
 | Invitations (ADR — author invitations). Owner and support both invite —
 | support without a plan and with credits up to its grant limit, which
 | Invitations::perks enforces. Sending is throttled twice: the console's
 | write limit, and `invitations` per staff member; the daily cap is counted
 | from the audit log.
 */
Route::get('/invitations', [InvitationsController::class, 'index'])->name('invitations.index');
Route::get('/invitations/export', [InvitationsController::class, 'export'])
    ->middleware('throttle:admin-actions')
    ->name('invitations.export');
Route::get('/invitations/{invitation}', [InvitationsController::class, 'show'])->whereNumber('invitation')->name('invitations.show');
Route::post('/invitations/preview', [InvitationsController::class, 'preview'])
    ->middleware('throttle:60,1')
    ->name('invitations.preview');

Route::middleware(['throttle:admin-actions', 'throttle:invitations'])->group(function () {
    Route::post('/invitations', [InvitationsController::class, 'store'])->name('invitations.store');
    Route::post('/invitations/bulk', [InvitationsController::class, 'bulk'])->name('invitations.bulk');
    Route::post('/invitations/{invitation}/resend', [InvitationsController::class, 'resend'])->whereNumber('invitation')->name('invitations.resend');
    Route::post('/invitations/{invitation}/revoke', [InvitationsController::class, 'revoke'])->whereNumber('invitation')->name('invitations.revoke');
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

/*
 | Écrivain Partners (ADR — partner program). All staff read and decide
 | applications; money, a partner's standing and the program's settings are
 | the owner's.
 */
Route::get('/partners', [PartnersController::class, 'index'])->name('partners.index');
Route::get('/partners/{partner}', [PartnersController::class, 'show'])->whereNumber('partner')->name('partners.show');
Route::get('/partners/commissions.csv', [PartnersController::class, 'export'])
    ->middleware('throttle:admin-actions')
    ->name('partners.export');

Route::middleware('throttle:admin-actions')->group(function () {
    Route::post('/partners/{partner}/approve', [PartnersController::class, 'approve'])->whereNumber('partner')->name('partners.approve');
    Route::post('/partners/{partner}/decline', [PartnersController::class, 'decline'])->whereNumber('partner')->name('partners.decline');
});

Route::middleware(['can:platform.owner', 'throttle:admin-actions'])->group(function () {
    Route::post('/partners/{partner}/suspend', [PartnersController::class, 'suspend'])->whereNumber('partner')->name('partners.suspend');
    Route::post('/partners/{partner}/reinstate', [PartnersController::class, 'reinstate'])->whereNumber('partner')->name('partners.reinstate');
    Route::post('/partners/{partner}/release', [PartnersController::class, 'release'])->whereNumber('partner')->name('partners.release');
    Route::post('/partners/{partner}/forfeit', [PartnersController::class, 'forfeit'])->whereNumber('partner')->name('partners.forfeit');
    Route::post('/partners/{partner}/payout', [PartnersController::class, 'payout'])->whereNumber('partner')->name('partners.payout');
    Route::post('/partners/{partner}/code', [PartnersController::class, 'code'])->whereNumber('partner')->name('partners.code');
    Route::post('/partners/{partner}/tax-form', [PartnersController::class, 'taxForm'])->whereNumber('partner')->name('partners.tax-form');
    Route::post('/partners/{partner}/unrestrict', [PartnersController::class, 'unrestrict'])->whereNumber('partner')->name('partners.unrestrict');
    Route::post('/partners/settings', [PartnersController::class, 'settings'])->name('partners.settings');
});

// Reads that compute, while a form is being filled in: the owner's, and
// throttled, though they change nothing.
Route::middleware(['can:platform.owner', 'throttle:60,1'])->prefix('growth')->name('growth.')->group(function () {
    Route::post('/audiences/preview', [GrowthController::class, 'preview'])->name('audiences.preview');
    Route::post('/broadcasts/preview', [GrowthController::class, 'renderPreview'])->name('broadcasts.preview');
});
