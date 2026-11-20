<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\AutoDebitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A tenant managing their own auto-debit. Every action is scoped to the caller's tenant
 * record — there is deliberately no way for staff to enrol or alter a tenant's card.
 */
class AutoDebitController extends Controller
{
    use ApiResponses;

    public function __construct(private AutoDebitService $autoDebit) {}

    /** GET /auto-debit — is it on, which card, and which invoices are queued. */
    public function status(Request $request): JsonResponse
    {
        return $this->ok($this->autoDebit->status($request->user()), 'Auto-debit status.');
    }

    /** POST /auto-debit/setup — start the ₦50 card check; returns the Paystack checkout URL. */
    public function setup(Request $request): JsonResponse
    {
        return $this->ok($this->autoDebit->startSetup($request->user()), 'Card setup ready.');
    }

    /** DELETE /auto-debit — turn auto-debit off and forget the card. */
    public function cancel(Request $request): JsonResponse
    {
        return $this->autoDebit->cancel($request->user())
            ? $this->message('Auto-debit turned off.')
            : $this->message('Auto-debit was not on.');
    }

    // ---- Staff (owner / manager / finance / admin) --------------------------------

    /** GET /auto-debit/overview — auto-debit state per invoice, scoped to the caller's properties. */
    public function overview(Request $request): JsonResponse
    {
        return $this->ok($this->autoDebit->overview($request->user()), 'Auto-debit overview.');
    }

    /** GET /auto-debit/invoices/{id} — one invoice's enrolment and attempt history. */
    public function invoice(Request $request, string $id): JsonResponse
    {
        return $this->ok($this->autoDebit->invoiceDetail($request->user(), $id), 'Auto-debit for this invoice.');
    }
}
