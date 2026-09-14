<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/rbac — the authoritative permission matrix (config/rbac.php) so
 * the admin "Users & Roles" screen renders it from the backend rather than a
 * client-side copy.
 */
class RbacController extends Controller
{
    use ApiResponses;

    public function __invoke(): JsonResponse
    {
        return $this->ok([
            'roles' => config('rbac.roles'),
            'permissions' => config('rbac.permissions'),
            'resources' => config('rbac.resources'),
            'paymentApprovers' => config('rbac.payment_approvers'),
        ], 'RBAC matrix.');
    }
}
