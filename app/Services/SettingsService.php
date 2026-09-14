<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Singleton config (organization profile, integration toggles). Each key holds
 * a JSON object; reads merge stored values over the defaults so new fields
 * appear without a migration.
 */
class SettingsService
{
    public const DEFAULTS = [
        'organization' => [
            'name' => 'EstateHub',
            'supportEmail' => 'support@estatehub.africa',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'fiscalYearStart' => 'January',
            'lateFeePercent' => 10,
            'gracePeriodDays' => 5,
            'invoicePrefix' => 'INV',
            'address' => '',
        ],
        'integrations' => [
            'paystack' => ['enabled' => false, 'label' => 'Paystack', 'category' => 'Payments'],
            'flutterwave' => ['enabled' => false, 'label' => 'Flutterwave', 'category' => 'Payments'],
            'bank_transfer' => ['enabled' => true, 'label' => 'Bank transfer', 'category' => 'Payments'],
            'sms_termii' => ['enabled' => false, 'label' => 'Termii SMS', 'category' => 'Messaging'],
            'email_ses' => ['enabled' => true, 'label' => 'Amazon SES email', 'category' => 'Messaging'],
            'quickbooks' => ['enabled' => false, 'label' => 'QuickBooks', 'category' => 'Accounting'],
            'google_calendar' => ['enabled' => false, 'label' => 'Google Calendar', 'category' => 'Calendar'],
            'zapier' => ['enabled' => false, 'label' => 'Zapier', 'category' => 'Automation'],
        ],
    ];

    public function get(string $key): array
    {
        $defaults = self::DEFAULTS[$key] ?? [];
        $stored = Setting::query()->find($key)?->value ?? [];

        return $this->merge($defaults, $stored);
    }

    public function put(string $key, array $value): array
    {
        $merged = $this->merge($this->get($key), $value);

        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $merged, 'updatedAt' => now()->toIso8601String()],
        );

        return $merged;
    }

    /** All singletons in one shot, for the bootstrap payload. */
    public function all(): array
    {
        return [
            'organization' => $this->get('organization'),
            'integrations' => $this->get('integrations'),
        ];
    }

    private function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $k => $v) {
            $base[$k] = is_array($v) && isset($base[$k]) && is_array($base[$k])
                ? $this->merge($base[$k], $v)
                : $v;
        }

        return $base;
    }
}
