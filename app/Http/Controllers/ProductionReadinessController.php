<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationCode;
use App\Operations\PreflightCheck;
use App\Operations\ProductionPreflight;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionReadinessController extends Controller
{
    public function __invoke(Request $request, ProductionPreflight $preflight): View
    {
        abort_unless(
            $request->user()->isAdministrator()
            && $request->user()->organization->code === OrganizationCode::AUTOKLUBY,
            404,
        );

        $checks = $preflight->run();
        $overall = $preflight->hasErrors($checks)
            ? PreflightCheck::ERROR
            : (collect($checks)->contains(fn (PreflightCheck $check): bool => $check->status === PreflightCheck::WARN)
                ? PreflightCheck::WARN
                : PreflightCheck::OK);

        return view('admin.operations.preflight', compact('checks', 'overall'));
    }
}
