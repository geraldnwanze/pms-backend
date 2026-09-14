<?php

use App\Http\Controllers\Api\AuthController as DemoAuthController;
use App\Http\Controllers\Api\BootstrapController as DemoBootstrapController;
use App\Http\Controllers\Api\EntityController as DemoEntityController;
use App\Http\Controllers\Api\ResetController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\EntityController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\MaintenancePayoutController;
use App\Http\Controllers\Api\V1\MembershipController;
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
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['ok' => true, 'time' => now()->toIso8601String()]);

/*
|--------------------------------------------------------------------------
| v1 — RBAC REST API (Sanctum bearer tokens)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {
    Route::get('/auth/demo-users', [AuthController::class, 'demoUsers']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    // Payment-gateway webhooks — unauthenticated, signature-verified in the handler.
    Route::post('/webhooks/paystack', [WebhookController::class, 'paystack']);

    // Proof-of-payment file — auth is the URL signature (see PaymentController@receiptLink).
    Route::get('/payments/{id}/receipt', [PaymentController::class, 'receipt'])->name('v1.payments.receipt');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/bootstrap', BootstrapController::class);
        Route::post('/admin/reset', [AdminController::class, 'reset']);

        // --- Dashboards: server-computed rollups, tailored per persona ---
        Route::get('/dashboard', DashboardController::class);
        Route::get('/portal', PortalController::class);

        // Web Push subscription management
        Route::get('/push/vapid-key', [PushController::class, 'key']);
        Route::post('/push/subscriptions', [PushController::class, 'store']);
        Route::delete('/push/subscriptions', [PushController::class, 'destroy']);
        Route::get('/analytics', AnalyticsController::class);
        Route::get('/rbac', RbacController::class);

        Route::get('/reports/collections', [ReportController::class, 'collections']);
        Route::get('/reports/occupancy', [ReportController::class, 'occupancy']);
        Route::get('/reports/expenses', [ReportController::class, 'expenses']);
        Route::get('/reports/owner-statement', [ReportController::class, 'ownerStatement']);

        Route::get('/owners', [DirectoryController::class, 'owners']);
        Route::get('/staff', [DirectoryController::class, 'staff']);

        Route::post('/reminders/overdue', [ReminderController::class, 'overdue']);

        Route::get('/settings/organization', [SettingsController::class, 'organization']);
        Route::put('/settings/organization', [SettingsController::class, 'updateOrganization']);
        Route::get('/integrations', [SettingsController::class, 'integrations']);
        Route::put('/integrations', [SettingsController::class, 'updateIntegrations']);

        // --- Workflow actions (declared before the generic resource routes) ---
        Route::post('/payments/submit', [PaymentController::class, 'submit']);
        Route::post('/payments/paystack/initialize', [PaymentController::class, 'paystackInitialize']);
        Route::post('/payments/paystack/verify', [PaymentController::class, 'paystackVerify']);
        Route::get('/payments/{id}/receipt-link', [PaymentController::class, 'receiptLink']);
        Route::post('/payments/{id}/approve', [PaymentController::class, 'approve']);
        Route::post('/payments/{id}/reject', [PaymentController::class, 'reject']);
        Route::post('/payments/{id}/request-info', [PaymentController::class, 'requestInfo']);
        Route::post('/payments/{id}/refund', [PaymentController::class, 'refund']);

        Route::post('/maintenance/{id}/transition', [MaintenanceController::class, 'transition']);
        Route::post('/maintenance/{id}/rounds', [MaintenanceController::class, 'addRound']);
        Route::post('/maintenance/{id}/confirm', [MaintenanceController::class, 'confirm']);

        // Technician payouts for completed, tenant-confirmed work orders.
        Route::get('/maintenance-payouts', [MaintenancePayoutController::class, 'index']);
        Route::post('/maintenance/{id}/payout', [MaintenancePayoutController::class, 'request']);
        Route::post('/maintenance-payouts/{id}/approve', [MaintenancePayoutController::class, 'approve']);
        Route::post('/maintenance-payouts/{id}/reject', [MaintenancePayoutController::class, 'reject']);
        Route::post('/maintenance-payouts/{id}/pay', [MaintenancePayoutController::class, 'pay']);

        Route::post('/leases/{id}/renew', [WorkflowController::class, 'renewLease']);
        Route::post('/leases/{id}/terminate', [WorkflowController::class, 'terminateLease']);
        Route::post('/invoices/{id}/cancel', [WorkflowController::class, 'cancelInvoice']);
        Route::post('/expenses/{id}/status', [WorkflowController::class, 'setExpenseStatus']);
        Route::post('/meters/{id}/readings', [WorkflowController::class, 'addMeterReading']);
        Route::post('/tasks/{id}/toggle', [WorkflowController::class, 'toggleTask']);
        Route::post('/messages/mark-thread-read', [WorkflowController::class, 'markThreadRead']);
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
            Route::prefix($resource)->group(function () use ($resource) {
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
| Legacy demo API — snapshot bootstrap/sync used by the current SPA
| (X-User-Id header auth). Kept until the frontend moves to v1.
|--------------------------------------------------------------------------
*/
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
