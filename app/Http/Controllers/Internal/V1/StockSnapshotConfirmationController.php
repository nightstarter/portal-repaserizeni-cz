<?php

namespace App\Http\Controllers\Internal\V1;

use App\Application\Audit\AuditLogger;
use App\Exceptions\DatasetFingerprintMismatchException;
use App\Exceptions\WorkflowConflictException;
use App\Http\Controllers\Controller;
use App\Integrations\Pohoda\StockSnapshotConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StockSnapshotConfirmationController extends Controller
{
    public function __invoke(Request $request, StockSnapshotConfirmationService $service, AuditLogger $audit): JsonResponse
    {
        try {
            return response()->json($service->confirm($request), 202);
        } catch (DatasetFingerprintMismatchException) {
            return response()->json(['error' => ['code' => 'DATASET_FINGERPRINT_MISMATCH']], 409);
        } catch (WorkflowConflictException) {
            return response()->json(['error' => ['code' => 'IDEMPOTENCY_CONFLICT']], 409);
        } catch (ValidationException $exception) {
            $audit->record('POHODA_STOCK_CONFIRMATION_REJECTED', newValues: ['code' => 'INVALID_CONFIRMATION']);
            throw $exception;
        }
    }
}
