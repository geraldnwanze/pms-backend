<?php

namespace Tests\Feature\AutoDebit;

use App\Models\AutoDebitAttempt;
use App\Models\AutoDebitMandate;
use App\Models\Invoice;
use App\Models\Notification;
use App\Services\AutoDebitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\PmsTestCase;

abstract class AutoDebitTestCase extends PmsTestCase
{
    protected const CARD_CODE = 'AUTH_secret123';

    /** Enrol Ada with a saved card (enrolled long before any invoice used here is due). */
    protected function mandate(array $overrides = []): AutoDebitMandate
    {
        return AutoDebitMandate::query()->create($overrides + [
            'id' => 'adm_'.uniqid(),
            'tenantId' => 'ten_ada',
            'userId' => $this->w->tenantUser->id,
            'email' => 'ada@tenant.test',
            'authorizationCode' => self::CARD_CODE,
            'cardBrand' => 'visa', 'last4' => '4081',
            'expMonth' => '12', 'expYear' => (string) (now()->year + 3),
            'bank' => 'TEST BANK', 'signature' => 'SIG_abc',
            'status' => 'active',
            'enrolledAt' => '2026-10-01T09:00:00+00:00',
        ]);
    }

    /** Pretend it is 07:30 on $date and run the daily job. */
    protected function runOn(string $date, array $options = []): array
    {
        Carbon::setTestNow("{$date} 07:30:00");

        return app(AutoDebitService::class)->run(['date' => Carbon::parse($date)] + $options);
    }

    /** @return Collection<int,AutoDebitAttempt> */
    protected function attempts(?Invoice $invoice = null)
    {
        return AutoDebitAttempt::query()
            ->where('invoiceId', ($invoice ?? $this->w->invoice)->id)
            ->orderBy('attemptNo')
            ->get();
    }

    protected function chargeCalls(): array
    {
        return $this->paystack->to('/transaction/charge_authorization');
    }

    protected function tenantNotifications(): Collection
    {
        return Notification::query()->where('forUserId', 'ten_ada')->pluck('title');
    }

    protected function dueOn(string $date, array $overrides = []): Invoice
    {
        $this->w->invoice->update(['dueDate' => $date] + $overrides);

        return $this->w->invoice->refresh();
    }
}
