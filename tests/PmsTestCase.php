<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePaystack;
use Tests\Support\World;

/** Base for feature tests: fresh schema, the standard {@see World}, mail captured, Paystack configured but faked. */
abstract class PmsTestCase extends TestCase
{
    use RefreshDatabase;

    protected World $w;

    protected FakePaystack $paystack;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'services.paystack.secret_key' => 'sk_test_secret',
            'services.paystack.base_url' => 'https://api.paystack.test',
        ]);

        $this->paystack = FakePaystack::install();
        $this->w = World::build();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Authenticate subsequent requests as this user (Sanctum). */
    protected function as(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }
}
