<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Services\DepositService;
use App\Services\ExpenseService;
use App\Services\InvoiceService;
use App\Services\LeaseService;
use App\Services\MessageService;
use App\Services\MeterService;
use App\Services\NotificationCrudService;
use App\Services\TaskService;
use App\Services\UtilityBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The smaller single-verb workflow actions, grouped. Each delegates straight
 * to its service; REST CRUD for these resources is served by EntityController.
 */
class WorkflowController extends Controller
{
    use ApiResponses;

    public function __construct(
        private LeaseService $leases,
        private InvoiceService $invoices,
        private ExpenseService $expenses,
        private MeterService $meters,
        private TaskService $tasks,
        private MessageService $messages,
        private NotificationCrudService $notifications,
    ) {}

    public function renewLease(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['endDate' => ['required', 'date', 'after:today']]);

        return $this->ok(new EntityResource($this->leases->renew($request->user(), $id, $data['endDate'])), 'Lease renewed.');
    }

    public function terminateLease(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'closingReadings' => ['sometimes', 'array', 'max:30'],
            'closingReadings.*.meterId' => ['required', 'string', 'distinct'],
            'closingReadings.*.value' => ['required', 'numeric', 'min:0'],
        ]);

        [$lease, $invoice] = $this->leases->endTenancy($request->user(), $id, $data['closingReadings'] ?? []);

        return $this->ok(
            new EntityResource($lease),
            'Lease terminated.',
            ['finalBill' => $invoice ? ['id' => $invoice->id, 'code' => $invoice->code, 'total' => $invoice->total] : null],
        );
    }

    public function cancelInvoice(Request $request, string $id): JsonResponse
    {
        return $this->ok(new EntityResource($this->invoices->cancel($request->user(), $id)), 'Invoice cancelled.');
    }

    public function waiveLateFee(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return $this->ok(new EntityResource($this->invoices->waiveLateFee($request->user(), $id, $data['reason'])), 'Late fee waived.');
    }

    public function setExpenseStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:pending,approved,rejected,paid']]);

        return $this->ok(new EntityResource($this->expenses->setStatus($request->user(), $id, $data['status'])), "Expense marked {$data['status']}.");
    }

    public function addMeterReading(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
        ]);

        return $this->created(new EntityResource($this->meters->addReading($request->user(), $id, (float) $data['value'], $data['date'])), 'Reading recorded.');
    }

    public function deposit(Request $request, string $id): JsonResponse
    {
        return $this->ok(app(DepositService::class)->summary($request->user(), $id));
    }

    public function receiveDeposit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:cash,bank-transfer,card,cheque,other'],
            'reference' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date'],
        ]);

        return $this->created(app(DepositService::class)->receive($request->user(), $id, $data['amount'], $data['method'], $data['reference'] ?? null, $data['date'] ?? null), 'Deposit recorded.');
    }

    public function settleDeposit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'deductions' => ['array', 'max:20'],
            'applyToArrears' => ['sometimes', 'boolean'],
            'deductions.*.reason' => ['required', 'string', 'min:3', 'max:255'],
            'deductions.*.amount' => ['required', 'integer', 'min:1'],
        ]);

        return $this->ok(app(DepositService::class)->settle($request->user(), $id, $data['deductions'] ?? [], (bool) ($data['applyToArrears'] ?? false)), 'Deposit settled.');
    }

    public function billMeter(Request $request, string $id): JsonResponse
    {
        return $this->created(new EntityResource(app(UtilityBillingService::class)->billMeter($request->user(), $id)), 'Utility bill issued.');
    }

    /** What billing this meter would raise, or why it cannot be billed; `reading` asks it as if the meter had just been read at that value. */
    public function previewMeterBill(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reading' => ['sometimes', 'nullable', 'numeric', 'min:0']]);

        return $this->ok(app(UtilityBillingService::class)->previewMeter($request->user(), $id, isset($data['reading']) ? (float) $data['reading'] : null));
    }

    /** Bill every meter that is ready, or (`dryRun`) report what that would raise. */
    public function billAllMeters(Request $request): JsonResponse
    {
        $data = $request->validate([
            'propertyIds' => ['sometimes', 'array'],
            'propertyIds.*' => ['string'],
            'dryRun' => ['sometimes', 'boolean'],
        ]);

        $result = app(UtilityBillingService::class)->billAll($request->user(), $data['propertyIds'] ?? null, (bool) ($data['dryRun'] ?? false));

        return $this->ok($result, $result['dryRun'] ? 'Here is what would be billed.' : 'Utility bills issued.');
    }

    /** The latest "bill all ready meters" runs on the caller's properties. */
    public function billRuns(Request $request): JsonResponse
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50']]);

        return $this->ok(app(UtilityBillingService::class)->recentRuns($request->user(), (int) ($data['limit'] ?? 10)));
    }

    public function toggleTask(Request $request, string $id): JsonResponse
    {
        return $this->ok(new EntityResource($this->tasks->toggle($request->user(), $id)), 'Task updated.');
    }

    public function markThreadRead(Request $request): JsonResponse
    {
        $data = $request->validate(['threadId' => ['required', 'string']]);
        $count = $this->messages->markThreadRead($request->user(), $data['threadId']);

        return $this->ok(['updated' => $count], 'Thread marked as read.');
    }

    public function markNotificationRead(Request $request, string $id): JsonResponse
    {
        return $this->ok(new EntityResource($this->notifications->markRead($request->user(), $id)), 'Notification marked as read.');
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        return $this->ok(['updated' => $count], 'All notifications marked as read.');
    }
}
