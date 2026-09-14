<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Services\ExpenseService;
use App\Services\InvoiceService;
use App\Services\LeaseService;
use App\Services\MessageService;
use App\Services\MeterService;
use App\Services\NotificationCrudService;
use App\Services\TaskService;
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
        return $this->ok(new EntityResource($this->leases->terminate($request->user(), $id)), 'Lease terminated.');
    }

    public function cancelInvoice(Request $request, string $id): JsonResponse
    {
        return $this->ok(new EntityResource($this->invoices->cancel($request->user(), $id)), 'Invoice cancelled.');
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
