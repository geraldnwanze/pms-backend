<?php

namespace App\Support;

use App\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * Single source of truth for the dataset shape shared with the frontend.
 *
 * The keys here are exactly the keys of the Redux `data` slice / the
 * `SeedData` interface, in the order the frontend expects them. Every
 * feature that walks the whole dataset — bootstrap, sync, seeding, reset —
 * iterates this map.
 */
class Collections
{
    /** @var array<string, class-string<Model>> */
    public const MAP = [
        'users' => Models\User::class,
        'properties' => Models\Property::class,
        'buildings' => Models\Building::class,
        'units' => Models\Unit::class,
        'tenants' => Models\Tenant::class,
        'leases' => Models\Lease::class,
        'invoices' => Models\Invoice::class,
        'payments' => Models\Payment::class,
        'maintenance' => Models\MaintenanceRequest::class,
        'expenses' => Models\Expense::class,
        'vendors' => Models\Vendor::class,
        'inspections' => Models\Inspection::class,
        'meters' => Models\Meter::class,
        'listings' => Models\Listing::class,
        'leads' => Models\Lead::class,
        'documents' => Models\DocumentRecord::class,
        'notifications' => Models\Notification::class,
        'notices' => Models\Notice::class,
        'messages' => Models\Message::class,
        'audit' => Models\AuditLog::class,
        'tasks' => Models\TaskItem::class,
        'events' => Models\CalendarEvent::class,
    ];

    /** Collections a normal client is allowed to overwrite via /api/sync. */
    public const SYNCABLE = [
        'properties', 'buildings', 'units', 'tenants', 'leases', 'invoices',
        'payments', 'maintenance', 'expenses', 'vendors', 'inspections',
        'meters', 'listings', 'leads', 'documents', 'notifications',
        'notices', 'messages', 'audit', 'tasks', 'events',
    ];

    public static function keys(): array
    {
        return array_keys(self::MAP);
    }
}
