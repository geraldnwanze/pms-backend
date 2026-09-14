<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain schema for the Property Management System.
 *
 * Column names mirror the frontend TypeScript interfaces (camelCase) so the
 * /api/bootstrap payload is a straight passthrough of `Model::all()` and
 * matches the Redux store shape exactly. Framework timestamps are disabled on
 * these models; each entity keeps its own domain date fields as strings.
 *
 * Every non-id column is nullable: the client is the source of truth for the
 * dataset and may legitimately omit optional fields when it syncs a record.
 * Types are declared via a compact spec — s=string, t=text, i=integer,
 * b=bigInteger, f=float, o=boolean, j=jsonb.
 */
return new class extends Migration
{
    private array $tables = [
        'properties' => [
            'code' => 's', 'name' => 's', 'type' => 's', 'status' => 's', 'owners' => 'j',
            'managerId' => 's', 'assignedStaffIds' => 'j', 'address' => 's', 'city' => 's',
            'state' => 's', 'country' => 's', 'lat' => 'f', 'lng' => 'f', 'description' => 't',
            'landSizeSqm' => 'i', 'builtUpAreaSqm' => 'i', 'yearBuilt' => 'i', 'renovationHistory' => 't',
            'amenities' => 'j', 'photos' => 'j', 'acquisitionDate' => 's', 'acquisitionValue' => 'b',
            'estimatedValue' => 'b', 'insuranceProvider' => 's', 'insuranceExpiry' => 's',
            'taxReference' => 's', 'createdAt' => 's',
        ],
        'buildings' => [
            'propertyId' => 's', 'name' => 's', 'code' => 's', 'floors' => 'i', 'description' => 't',
        ],
        'units' => [
            'buildingId' => 's', 'propertyId' => 's', 'code' => 's', 'type' => 's', 'floor' => 'i',
            'sizeSqm' => 'i', 'bedrooms' => 'i', 'bathrooms' => 'i', 'furnishing' => 's',
            'parkingSpaces' => 'i', 'status' => 's', 'rent' => 'b', 'serviceCharge' => 'b',
            'securityDeposit' => 'b', 'utilityCharge' => 'b', 'otherCharges' => 'b', 'meterIds' => 'j',
        ],
        'tenants' => [
            'name' => 's', 'email' => 's', 'phone' => 's', 'identityType' => 's', 'identityNumber' => 's',
            'emergencyContactName' => 's', 'emergencyContactPhone' => 's', 'guarantorName' => 's',
            'guarantorPhone' => 's', 'ownerId' => 's', 'avatarColor' => 's', 'createdAt' => 's',
        ],
        'leases' => [
            'code' => 's', 'unitId' => 's', 'propertyId' => 's', 'tenantId' => 's', 'startDate' => 's',
            'endDate' => 's', 'rent' => 'b', 'billingCycle' => 's', 'securityDeposit' => 'b',
            'noticePeriodDays' => 'i', 'status' => 's', 'signed' => 'o', 'createdAt' => 's', 'notes' => 't',
        ],
        'invoices' => [
            'code' => 's', 'leaseId' => 's', 'unitId' => 's', 'propertyId' => 's', 'tenantId' => 's',
            'issueDate' => 's', 'dueDate' => 's', 'lineItems' => 'j', 'total' => 'b', 'amountPaid' => 'b',
            'status' => 's', 'cycle' => 's', 'penalty' => 'b', 'discount' => 'b',
        ],
        'payments' => [
            'code' => 's', 'invoiceId' => 's', 'leaseId' => 's', 'unitId' => 's', 'propertyId' => 's',
            'tenantId' => 's', 'type' => 's', 'method' => 's', 'amount' => 'b', 'paymentDate' => 's',
            'bankName' => 's', 'reference' => 's', 'payerName' => 's', 'description' => 't',
            'receiptFileName' => 's', 'supportingFileName' => 's', 'status' => 's', 'submittedAt' => 's',
            'reviewedById' => 's', 'reviewedAt' => 's', 'verificationNotes' => 't', 'rejectionReason' => 't',
            'balanceAfter' => 'b',
        ],
        'maintenance' => [
            'code' => 's', 'propertyId' => 's', 'unitId' => 's', 'tenantId' => 's', 'title' => 's',
            'category' => 's', 'description' => 't', 'urgency' => 's', 'status' => 's', 'createdAt' => 's',
            'assignedToId' => 's', 'vendorId' => 's', 'materialsCost' => 'b', 'labourCost' => 'b',
            'specifiedMaterials' => 't', 'actualMaterials' => 't', 'materialsMatch' => 'o', 'rounds' => 'j',
            'photos' => 'j', 'afterPhotos' => 'j', 'completedAt' => 's', 'verifiedById' => 's', 'closedAt' => 's',
        ],
        'expenses' => [
            'code' => 's', 'propertyId' => 's', 'category' => 's', 'vendorId' => 's', 'description' => 's',
            'amount' => 'b', 'date' => 's', 'status' => 's', 'receiptFileName' => 's', 'approvedById' => 's',
        ],
        'vendors' => [
            'name' => 's', 'service' => 's', 'phone' => 's', 'email' => 's', 'ownerId' => 's',
            'totalPaid' => 'b', 'outstanding' => 'b',
        ],
        'inspections' => [
            'code' => 's', 'propertyId' => 's', 'unitId' => 's', 'tenantId' => 's', 'type' => 's',
            'status' => 's', 'scheduledDate' => 's', 'completedDate' => 's', 'inspectorId' => 's',
            'items' => 'j', 'depositDeductions' => 'b', 'notes' => 't',
        ],
        'meters' => [
            'propertyId' => 's', 'unitId' => 's', 'type' => 's', 'meterNumber' => 's', 'unitOfMeasure' => 's',
            'previousReading' => 'b', 'currentReading' => 'b', 'ratePerUnit' => 'f', 'history' => 'j',
        ],
        'listings' => [
            'propertyId' => 's', 'unitId' => 's', 'title' => 's', 'price' => 'b', 'status' => 's',
            'publishedAt' => 's', 'description' => 't', 'agentId' => 's',
        ],
        'leads' => [
            'name' => 's', 'email' => 's', 'phone' => 's', 'listingId' => 's', 'propertyId' => 's',
            'status' => 's', 'agentId' => 's', 'createdAt' => 's', 'viewingDate' => 's', 'notes' => 't',
        ],
        'documents' => [
            'name' => 's', 'category' => 's', 'propertyId' => 's', 'unitId' => 's', 'tenantId' => 's',
            'fileType' => 's', 'sizeKb' => 'i', 'uploadedAt' => 's', 'uploadedById' => 's',
        ],
        'notifications' => [
            'kind' => 's', 'title' => 's', 'body' => 't', 'createdAt' => 's', 'read' => 'o',
            'forRole' => 's', 'forUserId' => 's', 'link' => 's',
        ],
        'notices' => [
            'title' => 's', 'body' => 't', 'propertyId' => 's', 'audience' => 's', 'createdAt' => 's',
            'createdById' => 's',
        ],
        'messages' => [
            'threadId' => 's', 'fromUserId' => 's', 'toUserId' => 's', 'body' => 't', 'createdAt' => 's',
            'read' => 'o',
        ],
        'audit' => [
            'action' => 's', 'entity' => 's', 'entityId' => 's', 'userId' => 's', 'summary' => 't',
            'timestamp' => 's', 'ip' => 's', 'device' => 's',
        ],
        'tasks' => [
            'title' => 's', 'dueDate' => 's', 'done' => 'o', 'assignedToId' => 's', 'propertyId' => 's',
            'priority' => 's',
        ],
        'events' => ['title' => 's', 'date' => 's', 'kind' => 's', 'refId' => 's'],
    ];

    public function up(): void
    {
        foreach ($this->tables as $name => $columns) {
            Schema::create($name, function (Blueprint $t) use ($columns) {
                $t->string('id')->primary();
                foreach ($columns as $col => $type) {
                    match ($type) {
                        's' => $t->string($col)->nullable(),
                        't' => $t->text($col)->nullable(),
                        'i' => $t->integer($col)->nullable(),
                        'b' => $t->bigInteger($col)->nullable(),
                        'f' => $t->float($col)->nullable(),
                        'o' => $t->boolean($col)->nullable(),
                        'j' => $t->jsonb($col)->nullable(),
                    };
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
