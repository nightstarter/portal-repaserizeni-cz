<?php

namespace App\Http\Controllers\Internal\V1;

use App\Exceptions\WorkflowConflictException;
use App\Http\Controllers\Controller;
use App\Integrations\Pohoda\BridgeCommandService;
use App\Models\BridgeCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BridgeCommandController extends Controller
{
    public function index(Request $request, BridgeCommandService $service): JsonResponse
    {
        $validated = $request->validate([
            'connector_id' => ['required', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(['commands' => $service->claim(
            $validated['connector_id'], (int) ($validated['limit'] ?? 50),
        )]);
    }

    public function result(Request $request, BridgeCommand $command, BridgeCommandService $service): JsonResponse
    {
        try {
            return response()->json($service->recordResult($command, $request->json()->all()), 202);
        } catch (WorkflowConflictException) {
            return response()->json(['error' => ['code' => 'COMMAND_LEASE_CONFLICT']], 409);
        }
    }
}
