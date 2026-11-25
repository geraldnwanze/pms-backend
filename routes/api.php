<?php

use App\Http\Controllers\Api\AuthController as DemoAuthController;
use App\Http\Controllers\Api\BootstrapController as DemoBootstrapController;
use App\Http\Controllers\Api\EntityController as DemoEntityController;
use App\Http\Controllers\Api\ResetController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutoDebitController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\EntityController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\MaintenancePayoutController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\ModuleController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PortalController;
use App\Http\Controllers\Api\V1\PushController;
use App\Http\Controllers\Api\V1\RbacController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Support\Collections;
use App\Support\EntityRegistry;
use App\Support\Heartbeat;
use App\Support\Modules;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['ok' => true, 'time' => now()->toIso8601String()]);

// For an uptime monitor: 200 while the scheduler and the queue worker are both beating, 503 once either has been silent
// for three minutes. Reveals nothing but which of the two is down.
Route::get('/health/background', function () {
    $scheduler = Heartbeat::isAlive(Heartbeat::SCHEDULER);
    $worker = Heartbeat::isAlive(Heartbeat::WORKER);

    return response()->json(['ok' => $scheduler && $worker, 'scheduler' => $scheduler, 'worker' => $worker],
        $scheduler && $worker ? 200 : 503);
});

/*
|--------------------------------------------------------------------------
| v1 — RBAC REST API (Sanctum bearer tokens)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {
    Route::get('/auth/demo-users', [AuthController::class, 'demoUsers']);
    // Whether the "I'm not a robot" challenge is on, and the public key the page needs to draw it.
    Route::get('/auth/captcha', [AuthController::class, 'captcha']);
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware(['throttle:auth-register', 'captcha']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['throttle:auth-login', 'captcha:password']);
    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:email-verify');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware(['throttle:auth-recovery', 'captcha']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware(['throttle:auth-recovery', 'captcha']);

    // Payment-gateway webhooks — unauthenticated, signature-verified in the handler.
    Route::post('/webhooks/paystack', [WebhookController::class, 'paystack']);

    // Proof-of-payment file — auth is the URL signature (see PaymentController@receiptLink).
    Route::get('/payments/{id}/receipt', [PaymentController::class, 'receipt'])->name('v1.payments.receipt');

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:email-resend');

        Route::get('/bootstrap', BootstrapController::class);
        Route::post('/admin/reset', [AdminController::class, 'reset']);

        // --- Dashboards: server-computed rollups, tailored per persona ---
        Route::get('/dashboard', DashboardController::class);
        Route::get('/portal', PortalController::class);

        // Web Push subscription management
        Route::get('/push/vapid-key', [PushController::class, 'key']);
        Route::post('/push/subscriptions', [PushController::class, 'store']);
        Route::delete('/push/subscriptions', [PushController::class, 'destroy']);
        Route::get('/analytics', AnalyticsController::class)->middleware('module:reports');
        Route::get('/rbac', RbacController::class);
        Route::get('/modules', [ModuleController::class, 'index']);
        Route::put('/modules/{key}', [ModuleController::class, 'update']);

        Route::get('/reports/collections', [ReportController::class, 'collections'])->middleware('module:reports');
        Route::get('/reports/occupancy', [ReportController::class, 'occupancy'])->middleware('module:reports');
        Route::get('/reports/expenses', [ReportController::class, 'expenses'])->middleware('module:reports');
        Route::get('/reports/owner-statement', [ReportController::class, 'ownerStatement'])->middleware('module:reports');

        Route::get('/owners', [DirectoryController::class, 'owners']);
        Route::get('/staff', [DirectoryController::class, 'staff']);

        Route::post('/reminders/overdue', [ReminderController::class, 'overdue'])->middleware('module:reminders');
        Route::post('/reminders/expiring-leases', [ReminderController::class, 'expiringLeases'])->middleware('module:reminders');

        Route::get('/settings/organization', [SettingsController::class, 'organization']);
        Route::put('/settings/organization', [SettingsController::class, 'updateOrganization']);
        Route::get('/integrations', [SettingsController::class, 'integrations']);
        Route::put('/integrations', [SettingsController::class, 'updateIntegrations']);

        // --- Workflow actions (declared before the generic resource routes) ---
        Route::post('/payments/submit', [PaymentController::class, 'submit']);
        Route::post('/payments/paystack/initialize', [PaymentController::class, 'paystackInitialize']);
        Route::post('/payments/paystack/verify', [PaymentController::class, 'paystackVerify']);

        // Tenant auto-debit: rent charged to a saved card on the due date.
        Route::get('/auto-debit', [AutoDebitController::class, 'status'])->middleware('module:auto-debit');
        Route::post('/auto-debit/setup', [AutoDebitController::class, 'setup'])->middleware(['throttle:10,1', 'module:auto-debit']);
        Route::delete('/auto-debit', [AutoDebitController::class, 'cancel'])->middleware('module:auto-debit');
        Route::get('/auto-debit/overview', [AutoDebitController::class, 'overview'])->middleware('module:auto-debit');
        Route::get('/auto-debit/invoices/{id}', [AutoDebitController::class, 'invoice'])->middleware('module:auto-debit');
        Route::get('/payments/{id}/receipt-link', [PaymentController::class, 'receiptLink']);
        Route::post('/payments/{id}/approve', [PaymentController::class, 'approve']);
        Route::post('/payments/{id}/reject', [PaymentController::class, 'reject']);
        Route::post('/payments/{id}/request-info', [PaymentController::class, 'requestInfo']);
        Route::post('/payments/{id}/refund', [PaymentController::class, 'refund']);

        Route::post('/maintenance/{id}/transition', [MaintenanceController::class, 'transition'])->middleware('module:maintenance');
        Route::post('/maintenance/{id}/rounds', [MaintenanceController::class, 'addRound'])->middleware('module:maintenance');
        Route::post('/maintenance/{id}/confirm', [MaintenanceController::class, 'confirm'])->middleware('module:maintenance');

        // Technician payouts for completed, tenant-confirmed work orders.
        Route::get('/maintenance-payouts', [MaintenancePayoutController::class, 'index'])->middleware('module:payouts');
        Route::post('/maintenance/{id}/payout', [MaintenancePayoutController::class, 'request'])->middleware('module:payouts');
        Route::post('/maintenance-payouts/{id}/approve', [MaintenancePayoutController::class, 'approve'])->middleware('module:payouts');
        Route::post('/maintenance-payouts/{id}/reject', [MaintenancePayoutController::class, 'reject'])->middleware('module:payouts');
        Route::post('/maintenance-payouts/{id}/pay', [MaintenancePayoutController::class, 'pay'])->middleware('module:payouts');

        Route::post('/leases/{id}/renew', [WorkflowController::class, 'renewLease']);
        Route::post('/leases/{id}/terminate', [WorkflowController::class, 'terminateLease']);
        Route::get('/leases/{id}/deposit', [WorkflowController::class, 'deposit']);
        Route::post('/leases/{id}/deposit/receive', [WorkflowController::class, 'receiveDeposit']);
        Route::post('/leases/{id}/deposit/settle', [WorkflowController::class, 'settleDeposit']);
        Route::post('/invoices/{id}/cancel', [WorkflowController::class, 'cancelInvoice']);
        Route::post('/invoices/{id}/waive-late-fee', [WorkflowController::class, 'waiveLateFee']);
        Route::post('/expenses/{id}/status', [WorkflowController::class, 'setExpenseStatus'])->middleware('module:expenses');
        Route::post('/meters/{id}/readings', [WorkflowController::class, 'addMeterReading'])->middleware('module:utilities');
        Route::post('/meters/bill-all', [WorkflowController::class, 'billAllMeters'])->middleware('module:utilities');
        Route::get('/meters/bill-runs', [WorkflowController::class, 'billRuns'])->middleware('module:utilities');
        Route::post('/meters/{id}/bill-preview', [WorkflowController::class, 'previewMeterBill'])->middleware('module:utilities');
        Route::post('/meters/{id}/bill', [WorkflowController::class, 'billMeter'])->middleware('module:utilities');
        Route::post('/tasks/{id}/toggle', [WorkflowController::class, 'toggleTask'])->middleware('module:planner');
        Route::post('/messages/mark-thread-read', [WorkflowController::class, 'markThreadRead'])->middleware('module:communications');
        Route::post('/notifications/{id}/read', [WorkflowController::class, 'markNotificationRead']);
        Route::post('/notifications/read-all', [WorkflowController::class, 'markAllNotificationsRead']);

        // --- Team membership & invitations ---
        Route::get('/invitations', [MembershipController::class, 'myInvitations']);
        Route::post('/invitations/{member}/accept', [MembershipController::class, 'accept']);
        Route::post('/invitations/{member}/decline', [MembershipController::class, 'decline']);
        Route::put('/memberships/{member}/bank', [MembershipController::class, 'updateBank']);
        Route::get('/properties/{property}/members', [MembershipController::class, 'index']);
        Route::post('/properties/{property}/invitations', [MembershipController::class, 'invite']);
        Route::delete('/properties/{property}/members/{member}', [MembershipController::class, 'revoke']);

        // --- Generic REST for every registered resource ---
        foreach (EntityRegistry::keys() as $resource) {
            // A resource that belongs to a module answers "turned off" while that module is off.
            $module = Modules::forResource($resource);
            Route::prefix($resource)->middleware($module ? "module:{$module}" : [])->group(function () use ($resource) {
                Route::get('/', [EntityController::class, 'index'])->defaults('resource', $resource);
                Route::post('/', [EntityController::class, 'store'])->defaults('resource', $resource);
                Route::get('/{id}', [EntityController::class, 'show'])->defaults('resource', $resource);
                Route::match(['put', 'patch'], '/{id}', [EntityController::class, 'update'])->defaults('resource', $resource);
                Route::delete('/{id}', [EntityController::class, 'destroy'])->defaults('resource', $resource);
            });
        }
    });
});

/*
|--------------------------------------------------------------------------
| Legacy demo API — passwordless snapshot bootstrap/sync (X-User-Id header
| auth). The SPA now uses v1 only; this is local/testing/development-only
| (404 elsewhere) and its resolver is scoped to this group, never global.
|--------------------------------------------------------------------------
*/
Route::middleware(['demo.only', 'demo.resolve'])->group(function () {
    Route::get('/demo-users', [DemoAuthController::class, 'demoUsers']);
    Route::post('/login', [DemoAuthController::class, 'login']);

    Route::middleware('demo.auth')->group(function () {
        Route::get('/me', [DemoAuthController::class, 'me']);
        Route::post('/logout', [DemoAuthController::class, 'logout']);

        Route::get('/bootstrap', DemoBootstrapController::class);
        Route::put('/sync', SyncController::class);
        Route::post('/reset', ResetController::class);

        foreach (Collections::SYNCABLE as $name) {
            Route::get("/{$name}", [DemoEntityController::class, 'index'])->defaults('collection', $name);
            Route::post("/{$name}", [DemoEntityController::class, 'store'])->defaults('collection', $name);
            Route::get("/{$name}/{id}", [DemoEntityController::class, 'show'])->defaults('collection', $name);
            Route::match(['put', 'patch'], "/{$name}/{id}", [DemoEntityController::class, 'update'])->defaults('collection', $name);
            Route::delete("/{$name}/{id}", [DemoEntityController::class, 'destroy'])->defaults('collection', $name);
        }
    });
});
