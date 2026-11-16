<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Employee;
use App\Models\TaskTemplate;
use App\Models\TaskTemplatePattern;
use App\Services\FixedStaffCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controle van de vaste bezetting, vóór het opslaan:
 * - met de velden van het taakformulier: per medewerker of hij of zij de diensten van deze taak kan draaien;
 * - met alleen een pand: hetzelfde voor alle bestaande taken van dat pand (bezettingstabel).
 */
class StaffingCheckController extends Controller
{
    public function __invoke(Request $request, FixedStaffCheck $check): JsonResponse
    {
        $request->validate(['building_id' => ['required', 'exists:buildings,id']]);
        $building = Building::findOrFail($request->integer('building_id'));
        $employeeIds = Employee::active()->pluck('id');

        if (! $request->has('patterns')) {
            $templates = $building->taskTemplates()->with(['patterns', 'skills', 'employees:id'])->get();

            return response()->json([
                'templates' => $templates->mapWithKeys(fn (TaskTemplate $t) => [
                    $t->id => $check->forTemplate($t, $employeeIds->merge($t->employees->pluck('id')), $t->id),
                ]),
            ]);
        }

        $data = $request->validate([
            'template_id' => ['nullable', 'integer'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'required_staff' => ['nullable', 'integer', 'min:1', 'max:50'],
            ...TaskTemplatePattern::rules(),
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'skip_holidays' => ['boolean'],
            'skills' => ['array'],
            'skills.*' => ['integer'],
            'employees' => ['array'],
            'employees.*' => ['integer'],
        ]);

        $template = FixedStaffCheck::draft($building, $data);
        $existing = isset($data['template_id'])
            ? $building->taskTemplates()->whereKey($data['template_id'])->value('id')
            : null;

        return response()->json([
            'total' => $check->occurrences($template)->count(),
            'employees' => $check->forTemplate($template, $employeeIds->merge($data['employees'] ?? []), $existing),
        ]);
    }
}
