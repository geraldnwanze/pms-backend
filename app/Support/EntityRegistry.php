<?php

namespace App\Support;

use App\Http\Resources\EntityResource;
use App\Models;
use App\Services\AuditQueryService;
use App\Services\BuildingService;
use App\Services\CalendarService;
use App\Services\DocumentService;
use App\Services\ExpenseService;
use App\Services\InspectionService;
use App\Services\InvoiceService;
use App\Services\LeadService;
use App\Services\LeaseService;
use App\Services\ListingService;
use App\Services\MaintenanceService;
use App\Services\MessageService;
use App\Services\MeterService;
use App\Services\NoticeService;
use App\Services\NotificationCrudService;
use App\Services\PaymentService;
use App\Services\PropertyService;
use App\Services\TaskService;
use App\Services\TenantService;
use App\Services\UnitService;
use App\Services\UserService;
use App\Services\VendorService;

/**
 * Declarative description of every REST resource under /api/v1.
 *
 * Routes, the generic controller, validation and the resource serializer all
 * read this. Keys match the URL segment (kebab/lowercase plural).
 */
class EntityRegistry
{
    /**
     * @return array<string, array{
     *     model: class-string,
     *     service: class-string,
     *     resource: class-string,
     *     softDeletable?: bool
     * }>
     */
    public static function all(): array
    {
        return [
            'properties' => self::e(Models\Property::class, PropertyService::class),
            'buildings' => self::e(Models\Building::class, BuildingService::class),
            'units' => self::e(Models\Unit::class, UnitService::class),
            'tenants' => self::e(Models\Tenant::class, TenantService::class),
            'leases' => self::e(Models\Lease::class, LeaseService::class),
            'invoices' => self::e(Models\Invoice::class, InvoiceService::class),
            'payments' => self::e(Models\Payment::class, PaymentService::class),
            'maintenance' => self::e(Models\MaintenanceRequest::class, MaintenanceService::class),
            'expenses' => self::e(Models\Expense::class, ExpenseService::class),
            'vendors' => self::e(Models\Vendor::class, VendorService::class),
            'inspections' => self::e(Models\Inspection::class, InspectionService::class),
            'meters' => self::e(Models\Meter::class, MeterService::class),
            'listings' => self::e(Models\Listing::class, ListingService::class),
            'leads' => self::e(Models\Lead::class, LeadService::class),
            'documents' => self::e(Models\DocumentRecord::class, DocumentService::class),
            'notifications' => self::e(Models\Notification::class, NotificationCrudService::class),
            'notices' => self::e(Models\Notice::class, NoticeService::class),
            'messages' => self::e(Models\Message::class, MessageService::class),
            'audit' => self::e(Models\AuditLog::class, AuditQueryService::class),
            'tasks' => self::e(Models\TaskItem::class, TaskService::class),
            'events' => self::e(Models\CalendarEvent::class, CalendarService::class),
            'users' => self::e(Models\User::class, UserService::class),
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** Resource key for a model class, e.g. App\Models\Payment → "payments". */
    public static function keyForModel(string $modelClass): ?string
    {
        foreach (self::all() as $key => $entity) {
            if ($entity['model'] === $modelClass) {
                return $key;
            }
        }

        return null;
    }

    /** @return array{model:class-string,service:class-string,resource:class-string} */
    public static function get(string $key): array
    {
        return self::all()[$key];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    private static function e(string $model, string $service): array
    {
        return [
            'model' => $model,
            'service' => $service,
            'resource' => EntityResource::class,
        ];
    }
}
