<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeeOverview;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gegevens voor het medewerkerpaneel: waar is iemand nu, hoe ziet de dag en de week eruit.
 */
class EmployeeApiController extends Controller
{
    public function show(Request $request, Employee $employee, EmployeeOverview $overview): JsonResponse
    {
        $date = rescue(fn () => CarbonImmutable::parse((string) $request->query('datum', 'today')), null, false);

        return response()->json($overview->build($employee, $date));
    }
}
