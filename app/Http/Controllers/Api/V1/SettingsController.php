<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persisted singleton config for the admin Settings screen.
 * Reads are open to any authenticated user (the SPA needs the org profile for
 * invoices/receipts); writes are admin only.
 */
class SettingsController extends Controller
{
    use ApiResponses;

    public function __construct(private SettingsService $settings, private AuditService $audit) {}

    public function organization(Request $request): JsonResponse
    {
        return $this->ok($this->settings->get('organization'), 'Organization settings.');
    }

    public function updateOrganization(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'supportEmail' => ['sometimes', 'email'],
            'currency' => ['sometimes', 'string', 'max:8'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'fiscalYearStart' => ['sometimes', 'in:January,April,July,October'],
            'lateFeePercent' => ['sometimes', 'numeric', 'between:0,100'],
            'gracePeriodDays' => ['sometimes', 'integer', 'between:0,90'],
            'invoicePrefix' => ['sometimes', 'string', 'max:8'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $merged = $this->settings->put('organization', $data);
        $this->audit->record($request->user(), 'edit', 'Settings', 'organization', 'Updated organization settings');

        return $this->ok($merged, 'Organization settings saved.');
    }

    public function integrations(Request $request): JsonResponse
    {
        return $this->ok($this->settings->get('integrations'), 'Integrations.');
    }

    public function updateIntegrations(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        // Body is a map of { key: { enabled: bool, ... } } — merged over stored.
        $incoming = collect($request->all())
            ->filter(fn ($v) => is_array($v))
            ->map(fn ($v) => array_intersect_key($v, ['enabled' => true, 'label' => true, 'category' => true]))
            ->all();

        $merged = $this->settings->put('integrations', $incoming);
        $this->audit->record($request->user(), 'edit', 'Settings', 'integrations', 'Updated integration settings');

        return $this->ok($merged, 'Integrations saved.');
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only an admin can change these settings.');
    }
}
