<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The platform's switchable modules.
 *
 * Properties, units, tenants, leases, invoices, payments, users, notifications, settings and the dashboard are the
 * core and are never switchable. Everything listed here can be turned off by a superadmin; a module that is off
 * answers "turned off" on its API routes (see EnsureModuleEnabled), is left out of the navigation, and its
 * scheduled work and notifications stop. Its data is kept, so turning it back on restores everything.
 *
 * Only the modules that are OFF are stored (Setting `modules`), so every module — including any added later — is on
 * until a superadmin says otherwise.
 */
class Modules
{
    /**
     * key => label, description, and the modules it needs. `resources` are the generic REST resources (EntityRegistry
     * keys) that belong to the module.
     *
     * @var array<string, array{label: string, description: string, requires: array<int,string>, resources: array<int,string>}>
     */
    public const DEFINITIONS = [
        'utilities' => [
            'label' => 'Utilities & meters',
            'description' => 'Meters, readings, bill preview, bulk and monthly utility billing, and the final bill at move-out.',
            'requires' => [],
            'resources' => ['meters'],
        ],
        'auto-debit' => [
            'label' => 'Auto-debit',
            'description' => 'Tenants save a card and rent is charged on the due date.',
            'requires' => [],
            'resources' => [],
        ],
        'reminders' => [
            'label' => 'Late fees & reminders',
            'description' => 'Late fees, rent reminders and lease-expiry reminders.',
            'requires' => [],
            'resources' => [],
        ],
        'maintenance' => [
            'label' => 'Maintenance',
            'description' => 'Maintenance requests and the work-order flow.',
            'requires' => [],
            'resources' => ['maintenance'],
        ],
        'payouts' => [
            'label' => 'Technician payouts',
            'description' => 'Payout requests, approvals and payments for completed work orders.',
            'requires' => ['maintenance'],
            'resources' => [],
        ],
        'inspections' => [
            'label' => 'Inspections',
            'description' => 'Property and unit inspections.',
            'requires' => [],
            'resources' => ['inspections'],
        ],
        'expenses' => [
            'label' => 'Expenses & vendors',
            'description' => 'Expenses and the vendors they are paid to.',
            'requires' => [],
            'resources' => ['expenses', 'vendors'],
        ],
        'leasing' => [
            'label' => 'Leasing & leads',
            'description' => 'Listings and leads.',
            'requires' => [],
            'resources' => ['listings', 'leads'],
        ],
        'documents' => [
            'label' => 'Documents',
            'description' => 'Document storage and sharing.',
            'requires' => [],
            'resources' => ['documents'],
        ],
        'communications' => [
            'label' => 'Communications',
            'description' => 'Notices and messages.',
            'requires' => [],
            'resources' => ['notices', 'messages'],
        ],
        'planner' => [
            'label' => 'Planner',
            'description' => 'Tasks and the calendar.',
            'requires' => [],
            'resources' => ['tasks', 'events'],
        ],
        'reports' => [
            'label' => 'Reports & analytics',
            'description' => 'Collections, occupancy, expense and owner-statement reports, and analytics.',
            'requires' => [],
            'resources' => [],
        ],
    ];

    /** @var array<int,string>|null the disabled keys, read once per instance */
    private ?array $disabled = null;

    public static function exists(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /** The module a generic REST resource belongs to, or null if it is part of the core. */
    public static function forResource(string $resource): ?string
    {
        foreach (self::DEFINITIONS as $key => $def) {
            if (in_array($resource, $def['resources'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<int,string> keys of the modules that are turned off, in registry order */
    public function disabled(): array
    {
        if ($this->disabled === null) {
            $this->loadDisabled(Setting::query()->find('modules')?->value['disabled'] ?? []);
        }

        return $this->disabled;
    }

    /** A module is on unless it is switched off, or one it needs is. */
    public function enabled(string $key): bool
    {
        if (! self::exists($key)) {
            throw new InvalidArgumentException("Unknown module [{$key}].");
        }

        $off = $this->disabled();
        if (in_array($key, $off, true)) {
            return false;
        }

        foreach (self::DEFINITIONS[$key]['requires'] as $needed) {
            if (! $this->enabled($needed)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,bool> every module's state, keyed by module */
    public function states(): array
    {
        $states = [];
        foreach (self::keys() as $key) {
            $states[$key] = $this->enabled($key);
        }

        return $states;
    }

    /** @return array<int,array<string,mixed>> the registry with each module's current state */
    public function describe(): array
    {
        $rows = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $rows[] = [
                'key' => $key,
                'label' => $def['label'],
                'description' => $def['description'],
                'requires' => $def['requires'],
                'dependents' => $this->dependentsOf($key),
                'enabled' => $this->enabled($key),
            ];
        }

        return $rows;
    }

    /** @return array<int,string> every module that needs this one, directly or through another */
    public function dependentsOf(string $key): array
    {
        $found = [];
        $queue = [$key];
        while ($queue) {
            $current = array_shift($queue);
            foreach (self::DEFINITIONS as $other => $def) {
                if (in_array($current, $def['requires'], true) && ! in_array($other, $found, true)) {
                    $found[] = $other;
                    $queue[] = $other;
                }
            }
        }

        return array_values(array_filter(self::keys(), fn ($k) => in_array($k, $found, true)));
    }

    /**
     * Switch a module on or off. Switching off also switches off everything that needs it, but only when the caller
     * says so (`$cascade`) — otherwise the dependents are reported and nothing changes. Switching on needs what the
     * module needs to be on already.
     *
     * The stored list is read again under a lock inside a transaction, so two superadmins switching different modules
     * at the same moment cannot undo each other: the second waits for the first, then builds on what it wrote.
     *
     * @return array<int,string> the modules whose state changed
     *
     * @throws ValidationException
     */
    public function set(string $key, bool $enabled, bool $cascade = false): array
    {
        if (! self::exists($key)) {
            throw new InvalidArgumentException("Unknown module [{$key}].");
        }

        try {
            return DB::transaction(function () use ($key, $enabled, $cascade) {
                // The row has to exist to be locked; two first-ever switches racing each other both just make sure it is there.
                Setting::query()->insertOrIgnore(['key' => 'modules', 'value' => json_encode(['disabled' => []])]);
                $row = Setting::query()->lockForUpdate()->findOrFail('modules');

                // Always the list as stored now, under the lock: never one remembered from before it.
                $this->loadDisabled($row->value['disabled'] ?? []);
                $off = $this->disabled;
                $changed = [];

                if ($enabled) {
                    $blocked = array_values(array_filter(self::DEFINITIONS[$key]['requires'], fn ($needed) => ! $this->enabled($needed)));
                    if ($blocked !== []) {
                        throw ValidationException::withMessages(['requires' => array_map(
                            fn ($k) => self::DEFINITIONS[$k]['label'].' must be on first.', $blocked)]);
                    }
                    if (in_array($key, $off, true)) {
                        $changed[] = $key;
                        $off = array_values(array_diff($off, [$key]));
                    }
                } else {
                    $affected = array_values(array_filter($this->dependentsOf($key), fn ($k) => ! in_array($k, $off, true)));
                    if ($affected !== [] && ! $cascade) {
                        throw ValidationException::withMessages(['dependents' => array_map(
                            fn ($k) => self::DEFINITIONS[$k]['label'].' needs '.self::DEFINITIONS[$key]['label'].' and would be turned off too.', $affected)]);
                    }
                    foreach ([$key, ...$affected] as $k) {
                        if (! in_array($k, $off, true)) {
                            $changed[] = $k;
                            $off[] = $k;
                        }
                    }
                }

                if ($changed !== []) {
                    // Stored in registry order, so the same set of switches is always the same stored value.
                    $row->update(['value' => ['disabled' => array_values(array_filter(self::keys(), fn ($k) => in_array($k, $off, true)))]]);
                }

                return $changed;
            });
        } finally {
            $this->disabled = null;                           // the next read sees what is stored now
        }
    }

    /** @param  array<int,string>  $stored */
    private function loadDisabled(array $stored): void
    {
        // Ignore anything no longer in the registry, and report in registry order.
        $this->disabled = array_values(array_filter(self::keys(), fn ($k) => in_array($k, $stored, true)));
    }
}
