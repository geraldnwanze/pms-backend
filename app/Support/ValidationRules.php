<?php

namespace App\Support;

/**
 * Validation rules per resource, keyed by the URL segment used in EntityRegistry.
 *
 * `rulesFor($entity, $creating)` returns the rule set. On update every rule is
 * prefixed with `sometimes` so partial (PATCH-style) payloads are accepted; on
 * create the listed `required` fields are enforced.
 */
class ValidationRules
{
    public static function rulesFor(string $entity, bool $creating): array
    {
        $rules = self::MAP[$entity] ?? ['*' => ['array']];

        if ($creating) {
            return $rules;
        }

        $relaxed = [];
        foreach ($rules as $field => $set) {
            $set = is_string($set) ? explode('|', $set) : $set;
            $set = array_values(array_filter($set, fn ($r) => $r !== 'required'));
            array_unshift($set, 'sometimes');
            $relaxed[$field] = $set;
        }

        return $relaxed;
    }

    private const MAP = [
        'properties' => [
            // code + owners are assigned by PropertyService (the creator becomes
            // the sole owner), so they're optional on the wire.
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:residential,commercial,industrial,mixed-use,estate'],
            'status' => ['sometimes', 'in:active,inactive,construction,renovation,sold,archived'],
            'owners' => ['sometimes', 'array'],
            'owners.*.ownerId' => ['sometimes', 'string'],
            'owners.*.percentage' => ['sometimes', 'numeric', 'between:0,100'],
            'managerId' => ['nullable', 'string'],
            'assignedStaffIds' => ['array'],
            'assignedStaffIds.*' => ['string'],
            'address' => ['required', 'string'],
            'city' => ['required', 'string'],
            'state' => ['required', 'string'],
            'country' => ['required', 'string'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
            'landSizeSqm' => ['nullable', 'integer', 'min:0'],
            'builtUpAreaSqm' => ['nullable', 'integer', 'min:0'],
            'yearBuilt' => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'renovationHistory' => ['nullable', 'string'],
            'amenities' => ['array'],
            'photos' => ['array'],
            'acquisitionDate' => ['nullable', 'date'],
            'acquisitionValue' => ['nullable', 'integer', 'min:0'],
            'estimatedValue' => ['nullable', 'integer', 'min:0'],
            'insuranceProvider' => ['nullable', 'string'],
            'insuranceExpiry' => ['nullable', 'date'],
            'taxReference' => ['nullable', 'string'],
        ],
        'buildings' => [
            'propertyId' => ['required', 'string', 'exists:properties,id'],
            'name' => ['required', 'string'],
            'code' => ['required', 'string'],
            'buildingType' => ['nullable', 'string', 'max:60'],
            'floors' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
        ],
        'units' => [
            'buildingId' => ['required', 'string', 'exists:buildings,id'],
            'propertyId' => ['required', 'string', 'exists:properties,id'],
            'code' => ['required', 'string'],
            'type' => ['required', 'string'],
            'floor' => ['nullable', 'integer'],
            'sizeSqm' => ['nullable', 'integer', 'min:0'],
            'bedrooms' => ['nullable', 'integer', 'min:0'],
            'bathrooms' => ['nullable', 'integer', 'min:0'],
            'furnishing' => ['nullable', 'in:unfurnished,semi-furnished,furnished'],
            'parkingSpaces' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:available,occupied,reserved,maintenance,renovation,unavailable'],
            'rent' => ['nullable', 'integer', 'min:0'],
            'serviceCharge' => ['nullable', 'integer', 'min:0'],
            'securityDeposit' => ['nullable', 'integer', 'min:0'],
            'utilityCharge' => ['nullable', 'integer', 'min:0'],
            'otherCharges' => ['nullable', 'integer', 'min:0'],
            'meterIds' => ['array'],
        ],
        'tenants' => [
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string'],
            'identityType' => ['required', 'string'],
            'identityNumber' => ['required', 'string'],
            'emergencyContactName' => ['nullable', 'string'],
            'emergencyContactPhone' => ['nullable', 'string'],
            'guarantorName' => ['nullable', 'string'],
            'guarantorPhone' => ['nullable', 'string'],
            'ownerId' => ['required', 'string'],
            'avatarColor' => ['nullable', 'string'],
        ],
        'leases' => [
            'code' => ['nullable', 'string'],
            'unitId' => ['required', 'string', 'exists:units,id'],
            'propertyId' => ['required', 'string', 'exists:properties,id'],
            'tenantId' => ['required', 'string', 'exists:tenants,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after:startDate'],
            'rent' => ['required', 'integer', 'min:0'],
            'billingCycle' => ['required', 'in:monthly,quarterly,half-yearly,annual,custom'],
            'securityDeposit' => ['nullable', 'integer', 'min:0'],
            'noticePeriodDays' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,expiring,renewed,terminated,expired'],
            'signed' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ],
        'invoices' => [
            'code' => ['nullable', 'string'],
            'leaseId' => ['required', 'string', 'exists:leases,id'],
            'unitId' => ['required', 'string', 'exists:units,id'],
            'propertyId' => ['required', 'string', 'exists:properties,id'],
            'tenantId' => ['required', 'string', 'exists:tenants,id'],
            'issueDate' => ['required', 'date'],
            'dueDate' => ['required', 'date'],
            'lineItems' => ['required', 'array', 'min:1'],
            'lineItems.*.description' => ['required', 'string'],
            'lineItems.*.type' => ['required', 'string'],
            'lineItems.*.amount' => ['required', 'numeric', 'min:0'],
            'cycle' => ['required', 'in:monthly,quarterly,half-yearly,annual,custom'],
            'penalty' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
        ],
        'payments' => [
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
            'supportingFileName' => ['nullable', 'string'],
        ],
        'maintenance' => [
            'propertyId' => ['required', 'string', 'exists:properties,id'],
            'unitId' => ['required', 'string', 'exists:units,id'],
            'tenantId' => ['nullable', 'string', 'exists:tenants,id'],
            'title' => ['required', 'string'],
            'category' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'urgency' => ['required', 'in:low,medium,high,emergency'],
            'status' => ['nullable', 'in:open,assigned,in-progress,completed,verified,closed'],
            'assignedToId' => ['nullable', 'string', 'exists:users,id'],
            'vendorId' => ['nullable', 'string', 'exists:vendors,id'],
            'materialsCost' => ['nullable', 'integer', 'min:0'],
            'labourCost' => ['nullable', 'integer', 'min:0'],
            'specifiedMaterials' => ['nullable', 'string'],
            'photos' => ['array'],
        ],
        'expenses' => [
            'propertyId' => ['required', 'string', 'exists:properties,id'],
            'category' => ['required', 'in:repairs,security,cleaning,utilities,fuel,insurance,tax,staff,management-fee,other'],
            'vendorId' => ['nullable', 'string', 'exists:vendors,id'],
            'description' => ['required', 'string'],
            'amount' => ['required', 'integer', 'gt:0'],
            'date' => ['required', 'date'],
            'status' => ['nullable', 'in:pending,approved,rejected,paid'],
            'receiptFileName' => ['nullable', 'string'],
        ],
        'vendors' => [
            'name' => ['required', 'string'],
            'service' => ['required', 'string'],
            'phone' => ['required', 'string'],
            'email' => ['required', 'email'],
            'ownerId' => ['required', 'string'],
        ],
        'inspections' => [
            'propertyId' => ['required', 'string', 'exists:properties,id'],
            'unitId' => ['required', 'string', 'exists:units,id'],
            'tenantId' => ['nullable', 'string', 'exists:tenants,id'],
            'type' => ['required', 'in:move-in,routine,maintenance,move-out'],
            'status' => ['nullable', 'in:scheduled,completed,cancelled'],
            'scheduledDate' => ['required', 'date'],
            'completedDate' => ['nullable', 'date'],
            'inspectorId' => ['nullable', 'string', 'exists:users,id'],
            'items' => ['array'],
            'items.*.area' => ['required_with:items', 'string'],
            'items.*.condition' => ['required_with:items', 'in:good,fair,poor,damaged'],
            'depositDeductions' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ],
        'meters' => [
            'propertyId' => ['required', 'string', 'exists:properties,id'],
            'unitId' => ['nullable', 'string', 'exists:units,id'],
            'type' => ['required', 'in:electricity,water,gas,other'],
            'meterNumber' => ['required', 'string'],
            'unitOfMeasure' => ['required', 'string'],
            'previousReading' => ['nullable', 'integer', 'min:0'],
            'currentReading' => ['nullable', 'integer', 'min:0'],
            'ratePerUnit' => ['nullable', 'numeric', 'min:0'],
            'history' => ['array'],
        ],
        'listings' => [
            'propertyId' => ['required', 'string', 'exists:properties,id'],
            'unitId' => ['required', 'string', 'exists:units,id'],
            'title' => ['required', 'string'],
            'price' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', 'in:published,draft,let,archived'],
            'publishedAt' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'agentId' => ['nullable', 'string', 'exists:users,id'],
        ],
        'leads' => [
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string'],
            'listingId' => ['nullable', 'string', 'exists:listings,id'],
            'propertyId' => ['nullable', 'string', 'exists:properties,id'],
            'status' => ['nullable', 'in:new,contacted,viewing-scheduled,negotiating,converted,lost'],
            'agentId' => ['nullable', 'string', 'exists:users,id'],
            'viewingDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ],
        'documents' => [
            'name' => ['required', 'string'],
            'category' => ['required', 'in:deed,lease-agreement,tenant-id,guarantor,inspection,invoice,receipt,maintenance,vendor-contract,insurance,tax,other'],
            'propertyId' => ['nullable', 'string', 'exists:properties,id'],
            'unitId' => ['nullable', 'string', 'exists:units,id'],
            'tenantId' => ['nullable', 'string', 'exists:tenants,id'],
            'fileType' => ['required', 'in:pdf,jpg,png,docx'],
            'sizeKb' => ['nullable', 'integer', 'min:0'],
        ],
        'notifications' => [
            'kind' => ['required', 'in:rent,payment,lease,maintenance,inspection,announcement,system'],
            'title' => ['required', 'string'],
            'body' => ['required', 'string'],
            'forRole' => ['nullable', 'in:admin,owner,manager,agent,tenant,maintenance,finance'],
            'forUserId' => ['nullable', 'string'],
            'link' => ['nullable', 'string'],
            'read' => ['boolean'],
        ],
        'notices' => [
            'title' => ['required', 'string'],
            'body' => ['required', 'string'],
            'propertyId' => ['nullable', 'string', 'exists:properties,id'],
            'audience' => ['required', 'in:all,owners,tenants,staff'],
        ],
        'messages' => [
            'threadId' => ['required', 'string'],
            'fromUserId' => ['required', 'string', 'exists:users,id'],
            'toUserId' => ['required', 'string', 'exists:users,id'],
            'body' => ['required', 'string'],
            'read' => ['boolean'],
        ],
        'tasks' => [
            'title' => ['required', 'string'],
            'dueDate' => ['required', 'date'],
            'done' => ['boolean'],
            'assignedToId' => ['nullable', 'string', 'exists:users,id'],
            'propertyId' => ['nullable', 'string', 'exists:properties,id'],
            'priority' => ['required', 'in:low,medium,high'],
        ],
        'events' => [
            'title' => ['required', 'string'],
            'date' => ['required', 'date'],
            'kind' => ['required', 'in:inspection,viewing,lease,maintenance,payment,task'],
            'refId' => ['nullable', 'string'],
        ],
        'audit' => [
            '*' => ['prohibited'], // read-only resource
        ],
        'users' => [
            'name' => ['required', 'string'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string'],
            'role' => ['required', 'in:admin,owner,manager,agent,tenant,maintenance,finance'],
            'title' => ['nullable', 'string'],
            'ownerId' => ['nullable', 'string'],
            'assignedPropertyIds' => ['array'],
            'tenantId' => ['nullable', 'string', 'exists:tenants,id'],
            'twoFactorEnabled' => ['boolean'],
            'status' => ['nullable', 'in:active,inactive,invited'],
            'avatarColor' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:8'],
        ],
    ];
}
