<?php

namespace Tests\Feature\Docs;

use Illuminate\Support\Facades\Route;
use Tests\PmsTestCase;

/** The generated API documentation (Scramble): available while developing, hidden elsewhere, and covering the v1 API. */
class ApiDocsTest extends PmsTestCase
{
    /** Generating the document walks every route, which is slow and memory-hungry, so it is done once for the whole class. */
    private static ?array $spec = null;

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '512M');
    }

    private function spec(): array
    {
        $this->app['env'] = 'local';

        return self::$spec ??= $this->getJson('/docs/api.json')->assertOk()->json();
    }

    // ---- who can see it ----------------------------------------------------------------------------------------

    public function test_the_docs_are_not_served_outside_a_local_environment(): void
    {
        foreach (['testing', 'production', 'staging'] as $env) {
            $this->app['env'] = $env;

            $this->get('/docs/api')->assertForbidden();
            $this->getJson('/docs/api.json')->assertForbidden();
        }
    }

    public function test_the_docs_can_be_switched_on_for_a_staging_site_and_are_off_by_default(): void
    {
        $this->assertFalse(config('scramble.public'), 'Closed unless API_DOCS_PUBLIC says otherwise.');

        $this->app['env'] = 'staging';
        config(['scramble.public' => true]);
        $this->getJson('/docs/api.json')->assertOk();

        config(['scramble.public' => false]);
        $this->getJson('/docs/api.json')->assertForbidden();
    }

    public function test_the_docs_page_and_the_spec_are_served_locally(): void
    {
        $this->app['env'] = 'local';

        $this->get('/docs/api')->assertOk()->assertSee('PMS API');
        $this->getJson('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'PMS API');
    }

    // ---- what it describes -------------------------------------------------------------------------------------

    public function test_it_documents_the_v1_api_and_nothing_of_the_legacy_one(): void
    {
        $spec = $this->spec();

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertCount(1, $spec['servers']);
        $this->assertStringEndsWith('/api/v1', $spec['servers'][0]['url']);
        foreach (['/auth/login', '/modules', '/bootstrap', '/meters/bill-all', '/payments/submit', '/leases/{id}/terminate'] as $path) {
            $this->assertArrayHasKey($path, $spec['paths'], $path);
        }
        // The legacy /api/* demo routes (login, me, sync, a route per collection) are not in it.
        foreach (['/login', '/me', '/sync', '/demo-users', '/reset', '/logout'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $spec['paths'], $legacy);
        }
    }

    public function test_every_v1_route_is_documented(): void
    {
        $spec = $this->spec();
        $norm = fn (string $p) => preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim($p, '/'));
        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[strtoupper($method).' '.$norm($path)] = true;
            }
        }

        $missing = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || str_starts_with($route->uri(), 'api/v1/broadcasting')) {
                continue;
            }
            $path = $norm(substr($route->uri(), strlen('api/v1/')));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                // A route that accepts PUT and PATCH is documented once, as PUT.
                $twin = $method === 'PATCH' && isset($documented['PUT '.$path]);
                if (! isset($documented["{$method} {$path}"]) && ! $twin) {
                    $missing[] = "{$method} {$path}";
                }
            }
        }

        $this->assertSame([], $missing, 'a v1 route is missing from the generated docs');
    }

    public function test_it_says_which_endpoints_need_a_token_and_which_are_public(): void
    {
        $spec = $this->spec();

        $this->assertSame('bearer', $spec['components']['securitySchemes']['http']['scheme']);
        $this->assertSame([['http' => []]], $spec['security'], 'bearer auth applies by default');

        $public = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (($operation['security'] ?? null) === []) {
                    $public[] = strtoupper($method).' '.$path;
                }
            }
        }
        sort($public);
        $this->assertSame([
            'GET /auth/captcha', 'GET /auth/demo-users', 'GET /payments/{id}/receipt', 'POST /auth/forgot-password', 'POST /auth/login',
            'POST /auth/register', 'POST /auth/reset-password', 'POST /auth/verify-email', 'POST /webhooks/paystack',
        ], $public, 'exactly the routes outside auth:sanctum are public');
        $this->assertArrayNotHasKey('security', $spec['paths']['/modules']['get'], 'a signed-in route is not marked public');
    }

    public function test_the_page_explains_the_envelope_and_the_module_refusal(): void
    {
        $description = $this->spec()['info']['description'];

        $this->assertStringContainsString('Authorization: Bearer', $description);
        $this->assertStringContainsString('"data"', $description);
        $this->assertStringContainsString('turned off', $description);
    }

    // ---- the generic resource routes ---------------------------------------------------------------------------

    public function test_each_resource_route_has_its_own_title_roles_and_module(): void
    {
        $paths = $this->spec()['paths'];

        $this->assertSame('List meters', $paths['/meters']['get']['summary']);
        $this->assertSame('Create a meter', $paths['/meters']['post']['summary']);
        $this->assertSame('Show a meter', $paths['/meters/{id}']['get']['summary']);
        $this->assertSame('Update a meter', $paths['/meters/{id}']['put']['summary']);
        $this->assertSame('Delete a meter', $paths['/meters/{id}']['delete']['summary']);
        $this->assertSame('List properties', $paths['/properties']['get']['summary']);

        $meters = $paths['/meters']['get']['description'];
        $this->assertStringContainsString('**Allowed roles:**', $meters);
        $this->assertStringContainsString('`superadmin`', $meters);
        $this->assertStringContainsString('Utilities & meters (`utilities`)', $meters);

        // Reading and changing are different role lists, and a core resource names no module.
        $this->assertStringContainsString('`tenant`', $paths['/payments']['post']['description']);
        $this->assertStringNotContainsString('`tenant`', $paths['/properties']['post']['description'] ?? '');
        $this->assertStringNotContainsString('**Module:**', $paths['/properties']['get']['description']);
    }

    public function test_hand_written_routes_keep_their_own_documentation(): void
    {
        $login = $this->spec()['paths']['/auth/login']['post'];

        $this->assertStringNotContainsString('Allowed roles', $login['description'] ?? '');
        $this->assertNotSame('Create a login', $login['summary'] ?? '');
    }
}
