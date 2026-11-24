<?php

namespace App\Console\Commands;

use App\Models\PropertyMember;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates a superadmin, promotes an existing account to one, or (with --revoke) takes the role away again. This is the only way
 * to change who is one: the app's own screens cannot, on purpose, so that the one role that switches platform modules on and off
 * is managed by whoever runs the server.
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'pms:make-superadmin
        {email : The account\'s email address}
        {--name= : The name, for a new account}
        {--password= : The password, for a new account (it is visible in your shell history; leave it out to be asked)}
        {--revoke : Take the superadmin role away from this account instead}
        {--to=admin : With --revoke, the role the account goes back to: admin or user}
        {--force : Do not ask before promoting or revoking}';

    protected $description = 'Create a superadmin, promote an account to one, or revoke the role (--revoke)';

    public function handle(AuditService $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:180']])->fails()) {
            $this->error("\"{$email}\" is not a valid email address.");

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($this->option('revoke')) {
            return $this->revoke($user, $email, $audit);
        }
        if ($this->option('to') !== 'admin') {
            $this->error('--to only goes with --revoke.');

            return self::FAILURE;
        }

        return $user ? $this->promote($user, $audit) : $this->create($email, $audit);
    }

    private function revoke(?User $user, string $email, AuditService $audit): int
    {
        if ($this->option('name') !== null || $this->option('password') !== null) {
            $this->error('--revoke cannot be combined with --name or --password.');

            return self::FAILURE;
        }

        $to = (string) $this->option('to');
        if (! in_array($to, ['admin', 'user'], true)) {
            $this->error('--to must be admin or user.');

            return self::FAILURE;
        }

        if (! $user || ! $user->isSuperAdmin()) {
            $this->error("{$email} is not a superadmin.");

            return self::FAILURE;
        }

        // Somebody has to be able to switch modules. To replace the only superadmin, make the new one first.
        if (User::query()->where('role', 'superadmin')->count() <= 1) {
            $this->error("{$user->email} is the only superadmin. Create or promote another one first, then revoke this one.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Take the superadmin role away from {$user->email} (they become {$to})?", false)) {
            $this->warn('Cancelled. Nothing changed.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => $to])->save();
        $audit->record('system', 'permission-change', 'User', $user->id, "Removed superadmin from {$user->email} (now {$to}) from the command line", source: 'Command line');

        // Their sign-ins keep working, but the role is read afresh on every request, so the module switches are already out of reach.
        $this->info("{$user->email} is no longer a superadmin (now {$to}).");

        return self::SUCCESS;
    }

    private function promote(User $user, AuditService $audit): int
    {
        if ($user->isSuperAdmin()) {
            $this->info("{$user->email} is already a superadmin. Nothing changed.");

            return self::SUCCESS;
        }

        if ($user->status === 'inactive') {
            $this->error("{$user->email} is deactivated. Reactivate the account first.");

            return self::FAILURE;
        }

        // A superadmin runs the platform, not a property: an account that is a tenant or a member of a property keeps its job.
        if ($user->tenantId || PropertyMember::query()->where('userId', $user->id)->whereIn('status', ['active', 'invited'])->exists()) {
            $this->error("{$user->email} belongs to a property or a tenancy, so it cannot also be a superadmin. Use a separate account.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Make {$user->email} ({$user->role}) a superadmin?", false)) {
            $this->warn('Cancelled. Nothing changed.');

            return self::FAILURE;
        }

        $was = $user->role;
        $user->forceFill(['role' => 'superadmin'])->save();
        $audit->record('system', 'permission-change', 'User', $user->id, "Made {$user->email} a superadmin (was {$was}) from the command line", source: 'Command line');

        $this->info("{$user->email} is now a superadmin (was {$was}).");

        return self::SUCCESS;
    }

    private function create(string $email, AuditService $audit): int
    {
        $name = trim((string) ($this->option('name') ?? ''));
        if ($name === '' && $this->input->isInteractive()) {
            $name = trim((string) $this->ask('Name'));
        }

        $password = (string) ($this->option('password') ?? '');
        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Password (at least 8 characters)');
            if ($password !== (string) $this->secret('Confirm the password')) {
                $this->error('The passwords do not match.');

                return self::FAILURE;
            }
        }

        $check = Validator::make(['name' => $name, 'password' => $password], [
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        if ($check->fails()) {
            foreach ($check->errors()->all() as $message) {
                $this->error($message);
            }
            $this->line('A new account needs --name and --password (or run this interactively to be asked).');

            return self::FAILURE;
        }

        $user = User::query()->create([
            'id' => 'usr_'.Str::lower(Str::random(12)),
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'superadmin',
            'avatarColor' => '#0f766e',
            'twoFactorEnabled' => false,
            'status' => 'active',
            // Whoever runs this command owns the server; there is no inbox to confirm from.
            'emailVerifiedAt' => now()->toIso8601String(),
        ]);
        $audit->record('system', 'create', 'User', $user->id, "Created superadmin {$user->email} from the command line", source: 'Command line');

        $this->info("Created superadmin {$user->email}.");

        return self::SUCCESS;
    }
}
