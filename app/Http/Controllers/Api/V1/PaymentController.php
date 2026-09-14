<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Custom actions for payments; REST CRUD is served by EntityController. */
class PaymentController extends Controller
{
    use ApiResponses;

    public function __construct(
        private PaymentService $payments,
        private PaystackService $paystack,
    ) {}

    /** Start a Paystack redirect checkout for one invoice. */
    public function paystackInitialize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoiceId' => ['required', 'string', 'exists:invoices,id'],
            'amount' => ['sometimes', 'nullable', 'integer', 'gt:0'],
        ]);

        $result = $this->paystack->initialize($request->user(), $data['invoiceId'], $data['amount'] ?? null);

        return $this->ok([
            'authorizationUrl' => $result['authorizationUrl'],
            'reference' => $result['reference'],
            'payment' => new EntityResource($result['payment']),
        ], 'Checkout ready.');
    }

    /** Confirm a Paystack transaction after the tenant returns from checkout. */
    public function paystackVerify(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string']]);

        $payment = $this->paystack->verify($data['reference'], $request->user());

        return $this->ok([
            'status' => $payment->status,
            'payment' => new EntityResource($payment),
        ], $payment->status === 'approved' ? 'Payment confirmed.' : 'Payment not completed.');
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'string'],
            'code' => ['sometimes', 'string'],
            'invoiceId' => ['required', 'string', 'exists:invoices,id'],
            'type' => ['required', 'in:rent,partial-rent,security-deposit,service-charge,utility,maintenance,application-fee,other'],
            'method' => ['required', 'in:online,bank-transfer,cash,pos,cheque'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'paymentDate' => ['required', 'date'],
            'bankName' => ['nullable', 'string'],
            'reference' => ['required', 'string'],
            'payerName' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'receiptFileName' => ['nullable', 'string'],
            'receiptDataUrl' => ['nullable', 'string', 'starts_with:data:'],
            'supportingFileName' => ['nullable', 'string'],
        ]);

        return $this->created(new EntityResource($this->payments->submit($request->user(), $data)), 'Payment submitted for verification.');
    }

    /**
     * A short-lived signed URL for the payment's proof-of-payment file, so the
     * frontend can open it directly. Returns `url: null` when nothing is stored.
     */
    public function receiptLink(Request $request, string $id): JsonResponse
    {
        /** @var Payment $payment */
        $payment = Payment::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $payment);

        $url = $payment->receiptPath && Storage::disk('local')->exists($payment->receiptPath)
            ? URL::temporarySignedRoute('v1.payments.receipt', now()->addMinutes(15), ['id' => $payment->id])
            : null;

        return $this->ok([
            'url' => $url,
            'fileName' => $payment->receiptFileName,
        ], 'Receipt link.');
    }

    /** Stream the stored proof-of-payment file. Auth is the URL signature. */
    public function receipt(Request $request, string $id): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'This link has expired.');

        /** @var Payment $payment */
        $payment = Payment::query()->findOrFail($id);
        abort_unless($payment->receiptPath && Storage::disk('local')->exists($payment->receiptPath), 404);

        return Storage::disk('local')->response(
            $payment->receiptPath,
            $payment->receiptFileName ?: basename($payment->receiptPath),
            ['Content-Disposition' => 'inline'],
        );
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string']]);

        return $this->ok(new EntityResource($this->payments->approve($request->user(), $id, $data['notes'] ?? null)), 'Payment approved.');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3']]);

        return $this->ok(new EntityResource($this->payments->reject($request->user(), $id, $data['reason'])), 'Payment rejected.');
    }

    public function requestInfo(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3']]);

        return $this->ok(new EntityResource($this->payments->requestInfo($request->user(), $id, $data['note'])), 'Information requested from payer.');
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3']]);

        return $this->ok(new EntityResource($this->payments->refund($request->user(), $id, $data['reason'])), 'Payment refunded.');
    }
}
