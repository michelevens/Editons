<?php

use App\Http\Controllers\Admin\AccountActionsController;
use App\Http\Controllers\Admin\AccountsController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

/*
 | The platform owner's console (docs/ADMIN.md).
 |
 | One group, one gate. `platform.admin` answers 404 to everybody who is not
 | staff — guests included, which is why `auth` is not in front of it: `auth`
 | would redirect a guest to the sign-in page and so confirm the console is
 | here. DenyAdminPaths repeats the check for every /admin path as a global
 | middleware, in case a route is ever added outside this group.
 |
 | `platform.2fa` sends staff without two-factor to its set-up page, the one
 | page outside it. Owner-only actions add `can:platform.owner`; every write
 | is throttled per admin (`admin-actions`).
 */
Route::prefix('admin')->name('admin.')->middleware('platform.admin')->group(function () {
    Route::get('/two-factor', [TwoFactorSetupController::class, 'show'])->name('two-factor');

    Route::middleware('platform.2fa')->group(function () {
        Route::get('/', [DashboardController::class, 'show'])->name('dashboard');

        Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{user}', [AccountsController::class, 'show'])->whereNumber('user')->name('accounts.show');

        Route::get('/impersonations', [ImpersonationController::class, 'index'])->name('impersonations.index');
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditLogController::class, 'export'])
            ->middleware('throttle:admin-actions')
            ->name('audit.export');
        Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');

        // Support and owner.
        Route::middleware('throttle:admin-actions')->group(function () {
            Route::post('/accounts/{user}/impersonate', [ImpersonationController::class, 'start'])
                ->whereNumber('user')
                ->middleware('throttle:impersonation')
                ->name('accounts.impersonate');
            Route::post('/accounts/{user}/sign-out', [AccountActionsController::class, 'signOut'])->whereNumber('user')->name('accounts.sign-out');
            Route::post('/accounts/{user}/password-reset', [AccountActionsController::class, 'passwordReset'])->whereNumber('user')->name('accounts.password-reset');
            // Support may add a small top-up; AccountAdmin enforces the limit
            // and that only the owner may take credits away.
            Route::post('/accounts/{user}/credits', [AccountActionsController::class, 'credits'])->whereNumber('user')->name('accounts.credits');
            Route::post('/accounts/{user}/notes', [AccountActionsController::class, 'storeNote'])->whereNumber('user')->name('accounts.notes.store');
            Route::patch('/accounts/{user}/notes/{note}', [AccountActionsController::class, 'updateNote'])->whereNumber('user')->name('accounts.notes.update');
            Route::delete('/accounts/{user}/notes/{note}', [AccountActionsController::class, 'destroyNote'])->whereNumber('user')->name('accounts.notes.destroy');
        });

        // Owner only.
        Route::middleware(['can:platform.owner', 'throttle:admin-actions'])->group(function () {
            Route::post('/accounts/{user}/suspend', [AccountActionsController::class, 'suspend'])->whereNumber('user')->name('accounts.suspend');
            Route::post('/accounts/{user}/reactivate', [AccountActionsController::class, 'reactivate'])->whereNumber('user')->name('accounts.reactivate');
            Route::post('/accounts/{user}/two-factor-reset', [AccountActionsController::class, 'resetTwoFactor'])->whereNumber('user')->name('accounts.two-factor-reset');
            Route::post('/accounts/{user}/plan', [AccountActionsController::class, 'plan'])->whereNumber('user')->name('accounts.plan');
            Route::post('/accounts/{user}/role', [AccountActionsController::class, 'role'])->whereNumber('user')->name('accounts.role');
            Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
        });
    });
});

// Stopping "View as" is outside /admin: while it runs, the browser is signed
// in as the author, and the console is closed to them.
Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])
    ->middleware('auth')
    ->name('impersonation.stop');
