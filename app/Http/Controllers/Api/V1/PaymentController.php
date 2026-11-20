<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Payment;
use App\Services\AutoDebitService;
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
        private AutoDebitService $autoDebit,
    ) {}

    /** Start a Paystack redirect checkout for one invoice. */
    public function paystackInitialize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoiceId' => ['required', 'string', 'exists:invoices,id'],
            'amount' => ['sometimes', 'nullable', 'integer', 'gt:0'],
            'saveCard' => ['sometimes', 'boolean'],
        ]);

        $result = $this->paystack->initialize($request->user(), $data['invoiceId'], $data['amount'] ?? null, (bool) ($data['saveCard'] ?? false));

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

        // A card-setup checkout (auto-debit) isn't a payment — it saves the card and refunds the ₦50.
        if (AutoDebitService::isSetupReference($data['reference'])) {
            $setup = $this->autoDebit->completeSetup($data['reference'], $request->user());

            return $this->ok(['status' => $setup['status'], 'payment' => null, 'autoDebit' => $setup], $setup['message']);
        }

        $payment = $this->paystack->verify($data['reference'], $request->user());

        return $this->ok([
            'status' => $payment->status,
            'payment' => new EntityResource($payment),
        ], $payment->status === 'approved' ? 'Payment confirmed.' : 'Payment not completed.');
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],      // becomes part of a file path
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
            'receiptDataUrl' => ['nullable', 'string', 'starts_with:data:', 'max:11300000'],   // ≈ 8 MB once decoded
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

    private const RECEIPT_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    /** Stream the stored proof-of-payment file. Auth is the URL signature. */
    public function receipt(Request $request, string $id): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'This link has expired.');

        /** @var Payment $payment */
        $payment = Payment::query()->findOrFail($id);
        abort_unless($payment->receiptPath && Storage::disk('local')->exists($payment->receiptPath), 404);

        // The type comes from our own extension map, never from sniffing the bytes, and the file can't
        // run anything in the browser: it is shown inline only if it is an image or a PDF.
        $type = self::RECEIPT_TYPES[strtolower(pathinfo($payment->receiptPath, PATHINFO_EXTENSION))] ?? null;

        return Storage::disk('local')->response(
            $payment->receiptPath,
            $payment->receiptFileName ?: basename($payment->receiptPath),
            [
                'Content-Type' => $type ?? 'application/octet-stream',
                'Content-Disposition' => $type ? 'inline' : 'attachment',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
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
