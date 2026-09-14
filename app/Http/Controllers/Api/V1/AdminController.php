<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    use ApiResponses;

    /** Re-seed the demo dataset. Admin only. The client re-bootstraps after. */
    public function reset(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only an admin can reset the demo data.');

        app(DatabaseSeeder::class)->run();

        return $this->message('Demo data reset to its original state.');
    }
}
