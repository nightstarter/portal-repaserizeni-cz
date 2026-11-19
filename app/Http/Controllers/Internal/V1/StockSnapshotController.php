<?php

namespace App\Http\Controllers\Internal\V1;

use App\Application\Audit\AuditLogger;
use App\Exceptions\WorkflowConflictException;
use App\Http\Controllers\Controller;
use App\Integrations\Pohoda\StockSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StockSnapshotController extends Controller
{
    public function __invoke(Request $request, StockSnapshotService $service, AuditLogger $audit): JsonResponse
    {
        try {
            return response()->json($service->ingest($request), 202);
        } catch (WorkflowConflictException $exception) {
            return response()->json(['error' => ['code' => 'IDEMPOTENCY_CONFLICT']], 409);
        } catch (ValidationException $exception) {
            $audit->record('POHODA_STOCK_SNAPSHOT_REJECTED', newValues: ['code' => 'INVALID_SNAPSHOT']);
            throw $exception;
        }
    }
}
