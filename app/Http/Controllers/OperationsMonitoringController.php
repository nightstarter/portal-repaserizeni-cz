<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationCode;
use App\Models\AuditLog;
use App\Models\MonitorRun;
use App\Models\OperationalAlert;
use App\Operations\OperationalAlertManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class OperationsMonitoringController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);
        $filters = $request->validate([
            'severity' => 'nullable|in:INFO,WARN,ERROR,CRITICAL',
            'lifecycle' => 'nullable|in:OPEN,ACKNOWLEDGED,RESOLVED',
            'check_key' => ['nullable', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9_]*\z/'],
        ]);
        $alerts = OperationalAlert::query()
            ->when($filters['severity'] ?? null, fn ($query, $value) => $query->where('severity', $value))
            ->when($filters['lifecycle'] ?? null, fn ($query, $value) => $query->where('lifecycle', $value))
            ->when($filters['check_key'] ?? null, fn ($query, $value) => $query->where('check_key', $value))
            ->orderByRaw("CASE severity WHEN 'CRITICAL' THEN 1 WHEN 'ERROR' THEN 2 WHEN 'WARN' THEN 3 ELSE 4 END")
            ->orderByDesc('last_seen_at')->simplePaginate(25)->withQueryString();
        $latestRun = MonitorRun::query()->latest('started_at')->first();

        return view('admin.operations.monitoring.index', compact('alerts', 'latestRun', 'filters'));
    }

    public function show(Request $request, OperationalAlert $alert): View
    {
        $this->authorizeAdmin($request);
        $alert->load(['events', 'acknowledgedBy']);

        return view('admin.operations.monitoring.show', compact('alert'));
    }

    public function acknowledge(Request $request, OperationalAlert $alert, OperationalAlertManager $manager): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:500'],
            'row_version' => ['required', 'integer', 'min:1'],
        ]);
        try {
            $updated = $manager->acknowledge($alert, $request->user(), $validated['note'], (int) $validated['row_version']);
        } catch (RuntimeException $exception) {
            abort_if($exception->getMessage() === 'ALERT_VERSION_CONFLICT', 409, __('app.monitoring.version_conflict'));
            throw $exception;
        }
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'subject_type' => OperationalAlert::class,
            'subject_id' => $updated->id,
            'action' => 'OPERATIONAL_ALERT_ACKNOWLEDGED',
            'old_values' => ['lifecycle' => $alert->lifecycle, 'row_version' => (int) $validated['row_version']],
            'new_values' => ['lifecycle' => $updated->lifecycle, 'row_version' => $updated->row_version],
            'reason' => $validated['note'],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        return redirect()->route('admin.operations.monitoring.show', $updated)->with('status', __('app.monitoring.acknowledged'));
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()->isAdministrator()
            && $request->user()->organization->code === OrganizationCode::AUTOKLUBY,
            404,
        );
    }
}
