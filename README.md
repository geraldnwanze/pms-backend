# PMS Backend (Laravel)

API and persistence layer for the [PMS Next.js frontend](../../nextjs/pms-frontend).

Two APIs live here:

| API | Auth | Purpose |
|---|---|---|
| **`/api/v1/*`** | Sanctum bearer tokens | The REST API the Next.js SPA uses — RBAC, service layer, validation, standard envelopes. |
| **`/api/*`** (legacy) | `X-User-Id` header | Original snapshot `bootstrap` + `sync`. Superseded by v1; kept for reference. |

Both run against the same Postgres database and the same Eloquent models.

## Setup

Uses **PostgreSQL** via Docker (`docker-compose.yml` — Postgres 17 on host port
**5433**, to avoid clashing with a system Postgres).

```bash
composer install
cp .env.example .env
php artisan key:generate
docker compose up -d           # starts pms-postgres, waits for healthcheck
php artisan migrate:fresh --seed
php artisan serve              # http://localhost:8000
```

`docker compose down` stops it; `docker compose down -v` also wipes the volume.
Point `DB_*` in `.env` at any other Postgres to skip Docker.

Why Postgres: most tables store nested arrays/objects (`lineItems`, `rounds`,
`items`, `history`, …) in `jsonb` columns — binary-stored, indexable, directly
queryable. Seed data (`database/data/seed-data.json`) is generated from the
frontend's own generator via `npm run dump:seed` there.

---

## v1 API

### Request / response shape

Every response is an envelope:

```jsonc
// success
{ "data": <payload>, "message": "Property retrieved.", "meta": null }

// list (data is an array; meta carries pagination)
{ "data": [ … ], "message": "…", "meta": { "current_page": 1, "per_page": 25,
  "total": 140, "last_page": 6, "from": 1, "to": 25 } }

// error
{ "message": "The given data was invalid.", "errors": { "code": ["required"] } }
```

Status codes: `200` ok, `201` created, `401` unauthenticated, `403` forbidden
(RBAC), `404` not found, `422` validation / invalid state.

### Auth

```
POST /api/v1/auth/register         { name, email, password, password_confirmation, role, phone? }  → { data: { token, user } }
POST /api/v1/auth/login            { "email": "...", "password": "..." }                            → { data: { token, user } }
POST /api/v1/auth/forgot-password  { "email": "..." }   (local: returns { reset_token, reset_url })
POST /api/v1/auth/reset-password   { email, token, password, password_confirmation }
GET  /api/v1/auth/me               Authorization: Bearer <token>
POST /api/v1/auth/logout           revokes the current token

# local/testing convenience for the demo picker:
GET  /api/v1/auth/demo-users                        one account per role
POST /api/v1/auth/login  { "role": "finance" }      passwordless
POST /api/v1/auth/login  { "userId": "mgr_2" }      account switch
```

`register` takes no role — every new account is a plain **`user`** and sees no
property data. Access comes from **`property_members`**: create a property
(you become its `owner`) or accept an invitation. Password-reset mail uses
`MAIL_MAILER=log`; the `reset_url` (`FRONTEND_URL/reset-password`) is returned
in the response in local/testing so the flow works without SMTP.

### Team membership & invitations

```
POST   /api/v1/properties/{property}/invitations   { email, role }   owner/manager of that property
GET    /api/v1/properties/{property}/members
DELETE /api/v1/properties/{property}/members/{member}                 revoke (keeps ≥1 owner)
GET    /api/v1/invitations                                            pending invites for me
POST   /api/v1/invitations/{member}/accept
POST   /api/v1/invitations/{member}/decline
```

- A `property_members` row is `(propertyId, email, role, status)` —
  `invited → active | declined | revoked`. `userId` is linked when a matching
  account exists (or on registration with that email).
- **PropertyScope** grants a property only for an **active** membership, so a
  plain user's bootstrap is empty until they accept one. `visibleIds()` = the
  user's active membership property ids (admin = all).
- `User::recomputeRole()` denormalises `users.role` from active memberships by
  precedence (`owner > finance > manager > agent > maintenance > tenant > user`),
  so all the existing RBAC keeps working off `user->role`. It runs on accept,
  decline, revoke and property creation.
- Accepting a `tenant` invitation auto-creates a `tenants` record and links it
  (`property_members.tenantId` + `users.tenantId`) so the portal resolves.
- `bootstrap` also returns `memberships` (all of the user's rows) and
  `invitations` (pending), keyed alongside the data collections.

Seeding is **fresh-start**: only `admin_1`, `owner_1`, `owner_2` get accounts
(password `password`); all property data is kept and owner memberships are
generated from each property's `owners`. Managers/agents/tenants are rebuilt
through invitations.

### Bootstrap & reset

```
GET  /api/v1/bootstrap      every collection the caller may see, keyed like the SPA's Redux store
POST /api/v1/admin/reset    admin only — re-seed and return a fresh bootstrap
```

Creates accept a client-supplied `id` (and `code`/timestamps) so an optimistic
SPA stays consistent; the service generates any that are omitted.

### Resources

`GET|POST /api/v1/{resource}` and `GET|PUT|PATCH|DELETE /api/v1/{resource}/{id}`
for: `properties`, `buildings`, `units`, `tenants`, `leases`, `invoices`,
`payments`, `maintenance`, `expenses`, `vendors`, `inspections`, `meters`,
`listings`, `leads`, `documents`, `notifications`, `notices`, `messages`,
`audit` (read-only), `tasks`, `events`, `users`.

List queries accept `?search=`, `?<column>=` / `?filter[<column>]=`,
`?sort=<col>` / `?sort=-<col>`, `?page=`, `?per_page=` (max 100).

### Workflow actions

```
POST /api/v1/payments/submit                 { invoiceId, type, method, amount, paymentDate, reference, … }
POST /api/v1/payments/{id}/approve           { notes? }
POST /api/v1/payments/{id}/reject            { reason }
POST /api/v1/payments/{id}/request-info      { note }
POST /api/v1/payments/{id}/refund            { reason }
POST /api/v1/maintenance/{id}/transition     { status, assignedToId?, materialsCost?, … }
POST /api/v1/maintenance/{id}/rounds         { stage, date, outcome, findings }
POST /api/v1/leases/{id}/renew               { endDate }
POST /api/v1/leases/{id}/terminate
POST /api/v1/invoices/{id}/cancel
POST /api/v1/expenses/{id}/status            { status }
POST /api/v1/meters/{id}/readings            { value, date }
POST /api/v1/tasks/{id}/toggle
POST /api/v1/messages/mark-thread-read       { threadId }
POST /api/v1/notifications/{id}/read
POST /api/v1/notifications/read-all
```

### RBAC

`config/rbac.php` holds the permission matrix, mirroring the frontend's
`src/config/roles.ts`. Enforcement has two layers:

- **Capability** — `App\Policies\EntityPolicy` checks the acting role has the
  permission for the verb (`view`/`create`/`edit`/`delete`/`approve`…). Admins
  bypass. Payment approval is restricted to `finance` + `admin`.
- **Visibility** — `App\Services\PropertyScope` computes the property/tenant ids
  a user may see (owner → owned, manager/agent → assigned, finance → their
  owner's portfolio, maintenance → assigned jobs, tenant → their unit). Every
  service's `scopeQuery()` applies it, so list *and* show/update/delete are
  filtered identically; an out-of-scope id returns `403`.

### Architecture

```
Route → EntityRequest (validation) → Controller (thin)
      → Service (RBAC scope + business logic + Eloquent)   ← no repository layer
      → Model
Controller → EntityResource → ApiResponses envelope
```

- `app/Services/CrudService.php` — base: paginate/find/create/update/delete with
  scope + lifecycle hooks. Concrete services (`PropertyService`, …) set
  searchable/filterable/sortable columns and override `scopeQuery()`.
- Workflow services (`PaymentService`, `MaintenanceService`, `LeaseService`,
  `InvoiceService`, `ExpenseService`, `MeterService`, …) add domain methods
  ported from the frontend's `src/store/dataSlice.ts` reducers.
- `app/Support/EntityRegistry.php` — resource → model + service map; drives the
  route loop, the generic `EntityController`, and policy registration.
- `app/Support/ValidationRules.php` — rules per resource (relaxed to `sometimes`
  on update).
- `app/Http/Concerns/ApiResponses.php` + `app/Exceptions/ApiExceptionRenderer.php`
  — the envelope and error shaping.
- `AuditService` / `NotificationService` — shared, injected into workflow services.

---

## Legacy API (`/api/*`)

Unchanged; documents itself in git history. Key routes: `POST /api/login`
(`{role}`), `GET /api/bootstrap` (full dataset, Redux-slice shape),
`PUT /api/sync` (snapshot reconcile), `POST /api/reset` (admin re-seed).
Auth is the `X-User-Id` header (`app/Http/Middleware/ResolveDemoUser.php`).

## Notes / limitations

- The demo passwordless `{role}` login is gated to `local`/`testing`/`development`.
- The legacy `/api/sync` is last-write-wins across clients — fine for the demo.
  The v1 API is the path forward; point the frontend at it to retire the snapshot.
