<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound payment-gateway webhooks. Unauthenticated — each handler verifies the
 * provider's own signature.
 */
class WebhookController extends Controller
{
    public function paystack(Request $request, PaystackService $paystack): JsonResponse
    {
        $paystack->handleWebhook($request->getContent(), $request->header('X-Paystack-Signature'));

        return response()->json(['received' => true]);
    }
}
