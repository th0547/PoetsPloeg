<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Employee;
use App\Models\TaskTemplatePattern;
use App\Services\FixedStaffCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controle van de vaste bezetting, vóór het opslaan, per patroon:
 * - met de velden van het taakformulier: per patroon en medewerker of hij of zij de diensten van dat patroon kan draaien;
 * - met alleen een pand: hetzelfde voor alle patronen van de bestaande taken van dat pand (bezettingstabel).
 */
class StaffingCheckController extends Controller
{
    public function __invoke(Request $request, FixedStaffCheck $check): JsonResponse
    {
        $request->validate(['building_id' => ['required', 'exists:buildings,id']]);
        $building = Building::findOrFail($request->integer('building_id'));
        $employeeIds = Employee::active()->pluck('id');

        if (! $request->has('patterns')) {
            $templates = $building->taskTemplates()->with(['patterns.employees:id', 'skills'])->get();
            $patterns = [];
            foreach ($templates as $template) {
                $results = $check->forTemplate($template, $employeeIds->merge($template->staff()->pluck('id')), $template->id);
                foreach ($template->patterns->values() as $i => $pattern) {
                    $patterns[$pattern->id] = $results[$i] ?? [];
                }
            }

            return response()->json(['patterns' => (object) $patterns]);
        }

        $data = $request->validate([
            'template_id' => ['nullable', 'integer'],
            'required_staff' => ['nullable', 'integer', 'min:1', 'max:50'],
            ...TaskTemplatePattern::rules(),
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'skip_holidays' => ['boolean'],
            'skills' => ['array'],
            'skills.*' => ['integer'],
        ]);

        $template = FixedStaffCheck::draft($building, $data);
        $existing = isset($data['template_id'])
            ? $building->taskTemplates()->whereKey($data['template_id'])->value('id')
            : null;
        $selected = collect($data['patterns'])->flatMap(fn ($p) => TaskTemplatePattern::employeeIdsFromInput($p));
        $results = $check->forTemplate($template, $employeeIds->merge($selected), $existing);
        $slots = $check->occurrences($template)->countBy(fn ($slot) => $slot[2]);

        return response()->json([
            'patterns' => collect($results)->map(fn ($employees, $i) => [
                'total' => $slots[$i] ?? 0,
                'employees' => (object) $employees,
            ])->values(),
        ]);
    }
}
