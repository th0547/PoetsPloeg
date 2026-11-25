<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EmployeeRef;
use App\Models\Employee;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Actieve medewerkers (naam en kleur), voor keuzelijsten in de app. */
class EmployeeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return EmployeeRef::collection(Employee::query()->active()->orderBy('name')->get(['id', 'name', 'color']));
    }
}
