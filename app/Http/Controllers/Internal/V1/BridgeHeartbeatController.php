<?php

namespace App\Http\Controllers\Internal\V1;

use App\Exceptions\WorkflowConflictException;
use App\Http\Controllers\Controller;
use App\Integrations\Pohoda\BridgeHeartbeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BridgeHeartbeatController extends Controller
{
    public function __invoke(Request $request, BridgeHeartbeatService $service): JsonResponse
    {
        try {
            return response()->json($service->record($request), 202);
        } catch (WorkflowConflictException) {
            return response()->json(['error' => ['code' => 'IDEMPOTENCY_CONFLICT']], 409);
        }
    }
}
