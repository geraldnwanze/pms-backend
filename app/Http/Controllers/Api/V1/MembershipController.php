<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    use ApiResponses;

    public function __construct(private MembershipService $memberships) {}

    // ---- Property team (owner / manager) --------------------------------

    public function index(Request $request, string $property): JsonResponse
    {
        return $this->ok($this->memberships->membersOf($request->user(), $property), 'Property team.');
    }

    public function invite(Request $request, string $property): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:owner,manager,agent,finance,maintenance,tenant'],
        ]);

        $member = $this->memberships->invite($request->user(), $property, $data['email'], $data['role']);

        return $this->created($member, 'Invitation sent.');
    }

    public function revoke(Request $request, string $property, string $member): JsonResponse
    {
        $this->memberships->revoke($request->user(), $property, $member);

        return $this->message('Member removed.');
    }

    // ---- Invitee -------------------------------------------------------

    public function myInvitations(Request $request): JsonResponse
    {
        return $this->ok($this->memberships->pendingFor($request->user()), 'Your pending invitations.');
    }

    public function accept(Request $request, string $member): JsonResponse
    {
        return $this->ok($this->memberships->accept($request->user(), $member), 'Invitation accepted.');
    }

    public function decline(Request $request, string $member): JsonResponse
    {
        return $this->ok($this->memberships->decline($request->user(), $member), 'Invitation declined.');
    }

    /** The member sets their own payout bank details for this membership. */
    public function updateBank(Request $request, string $member): JsonResponse
    {
        $data = $request->validate([
            'bankName' => ['nullable', 'string', 'max:120'],
            'bankAccountName' => ['nullable', 'string', 'max:160'],
            'bankAccountNumber' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
            'bankCode' => ['nullable', 'string', 'max:20'],
        ]);

        $updated = $this->memberships->setBankDetails($request->user(), $member, $data);

        return $this->ok($this->memberships->presentOne($updated), 'Payout details saved.');
    }
}
