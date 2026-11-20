<?php

/**
 * Role-based access control.
 *
 * `permissions` — coarse capabilities per role (mirrors the frontend's
 * `ROLE_PERMISSIONS` in src/config/roles.ts).
 *
 * `resources` — which roles may read (`view`) vs write (`manage`) each REST
 * resource. `view` is deliberately broad for operational data (dashboards and
 * cross-references need it) and narrow for sensitive data; per-record
 * visibility (which properties/tenant a user sees) is still enforced by each
 * service's query scope (App\Services\PropertyScope). `manage` follows the
 * frontend's action gating.
 */
$allStaff = ['admin', 'owner', 'manager', 'agent', 'finance', 'maintenance'];
$everyone = [...$allStaff, 'tenant'];

// A registered user who is not yet on any property. They can create their first
// property (which makes them its owner) and act on account-level resources only.
$withUser = [...$everyone, 'user'];

return [

    'roles' => $withUser,

    'permissions' => [
        'admin' => ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'export', 'manage_payments', 'manage_users', 'manage_documents', 'manage_financials'],
        'owner' => ['view', 'export', 'manage_financials'],
        'manager' => ['view', 'create', 'edit', 'approve', 'reject', 'export', 'manage_payments', 'manage_documents'],
        'agent' => ['view', 'create', 'edit'],
        'tenant' => ['view', 'create'],
        'maintenance' => ['view', 'edit'],
        'finance' => ['view', 'create', 'edit', 'approve', 'reject', 'export', 'manage_payments', 'manage_financials'],
        'user' => ['view', 'create'],
    ],

    'payment_approvers' => ['finance', 'admin'],

    'resources' => [
        'properties' => ['view' => $withUser, 'manage' => ['admin', 'owner', 'manager', 'user']],
        'buildings' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager']],
        'units' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager']],
        'tenants' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager']],
        'leases' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager']],
        'invoices' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager', 'finance']],
        'payments' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager', 'finance', 'tenant']],
        'maintenance' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager', 'maintenance', 'tenant']],
        'expenses' => ['view' => $allStaff, 'manage' => ['admin', 'owner', 'manager', 'finance']],
        'vendors' => ['view' => $allStaff, 'manage' => ['admin', 'manager', 'finance']],
        'inspections' => ['view' => $allStaff, 'manage' => ['admin', 'owner', 'manager', 'maintenance']],
        // A tenant may read the meters of the unit they rent (CrudService narrows them to it) — never change or bill one.
        'meters' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager']],
        'listings' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager', 'agent']],
        'leads' => ['view' => $allStaff, 'manage' => ['admin', 'manager', 'agent']],
        'documents' => ['view' => $everyone, 'manage' => ['admin', 'owner', 'manager', 'finance', 'tenant']],
        // Clients only read and mark-read their own; the server creates them. Free-form creation would let anyone push a message (and link) to any user or role.
        'notifications' => ['view' => $withUser, 'manage' => ['admin']],
        'notices' => ['view' => $withUser, 'manage' => ['admin', 'owner', 'manager', 'finance', 'agent', 'maintenance']],
        'messages' => ['view' => $withUser, 'manage' => $withUser],
        'audit' => ['view' => ['admin', 'finance'], 'manage' => []],
        'tasks' => ['view' => $allStaff, 'manage' => ['admin', 'manager', 'agent', 'maintenance']],
        'events' => ['view' => $allStaff, 'manage' => ['admin', 'manager']],
        'users' => ['view' => ['admin'], 'manage' => ['admin']],
    ],
];
