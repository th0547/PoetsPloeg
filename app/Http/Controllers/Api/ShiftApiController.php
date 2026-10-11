<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Skill;
use App\Services\ConflictChecker;
use App\Services\ReplacementFinder;
use App\Services\ShiftGenerator;
use App\Support\Planning;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftApiController extends Controller
{
    public function __construct(private ConflictChecker $checker) {}

    public function show(Shift $shift): JsonResponse
    {
        return response()->json($this->present($shift));
    }

    public function update(Request $request, Shift $shift): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'required_staff' => ['required', 'integer', 'min:1', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'skills' => ['array'],
            'skills.*' => ['exists:skills,id'],
        ]);

        [$startsAt, $endsAt] = ShiftGenerator::timesFor(CarbonImmutable::parse($data['date']), $data['start_time'], $data['end_time']);

        DB::transaction(function () use ($shift, $data, $startsAt, $endsAt) {
            $shift->fill([
                'title' => $data['title'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'required_staff' => $data['required_staff'],
                'notes' => $data['notes'] ?? null,
                'is_modified' => true,
            ]);
            // De datum van een sjabloondienst blijft de "herhalingsdatum", zodat hij niet opnieuw wordt aangemaakt.
            if ($shift->task_template_id === null) {
                $shift->date = $startsAt->toDateString();
            }
            $shift->save();
            $shift->skills()->sync($data['skills'] ?? []);
        });

        return response()->json($this->present($shift->fresh()));
    }

    /** Dienst annuleren (sjabloondiensten blijven bewaard zodat ze niet opnieuw verschijnen). */
    public function destroy(Shift $shift): JsonResponse
    {
        if ($shift->task_template_id === null) {
            $shift->delete();
        } else {
            $shift->update(['cancelled_at' => now(), 'is_modified' => true]);
        }

        return response()->json(['ok' => true]);
    }

    public function candidates(Shift $shift, ReplacementFinder $finder): JsonResponse
    {
        return response()->json($finder->candidates($shift));
    }

    public function assign(Request $request, Shift $shift): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'source' => ['nullable', 'in:manual,replacement'],
            'confirm' => ['boolean'],
        ]);
        $employeeId = (int) $data['employee_id'];

        if ($shift->assignments()->where('employee_id', $employeeId)->exists()) {
            return response()->json(['message' => 'Deze medewerker staat al op deze dienst.'], 422);
        }

        $result = $this->checker->forCandidate($shift, $employeeId);
        if (Planning::needsConfirmation($result['warnings']) && ! ($data['confirm'] ?? false)) {
            return response()->json(['needs_confirmation' => true, 'warnings' => $result['warnings']]);
        }

        $shift->assignments()->create(['employee_id' => $employeeId, 'source' => $data['source'] ?? 'manual']);
        $shift->update(['is_modified' => true]);

        return response()->json($this->present($shift->fresh()));
    }

    public function unassign(Shift $shift, Employee $employee): JsonResponse
    {
        $shift->assignments()->where('employee_id', $employee->id)->delete();
        $shift->update(['is_modified' => true]);

        return response()->json($this->present($shift->fresh()));
    }

    private function present(Shift $shift): array
    {
        $shift->load(['building', 'template', 'skills:id,name', 'assignments.employee:id,name,phone,color']);
        $warnings = $this->checker->forShifts(collect([$shift]))[$shift->id] ?? [];

        return [
            'id' => $shift->id,
            'title' => $shift->title,
            'date' => $shift->starts_at->toDateString(),
            'date_label' => $shift->starts_at->translatedFormat('l j F Y'),
            'start_time' => $shift->starts_at->format('H:i'),
            'end_time' => $shift->ends_at->format('H:i'),
            'duration' => Planning::hours($shift->durationHours()),
            'required_staff' => $shift->required_staff,
            'open_slots' => $shift->openSlots(),
            'notes' => $shift->notes,
            'is_modified' => $shift->is_modified,
            'cancelled' => $shift->cancelled_at !== null,
            'template' => $shift->template ? [
                'id' => $shift->template->id,
                'recurrence' => $shift->template->recurrenceLabel(),
                'url' => route('buildings.templates.edit', [$shift->building_id, $shift->template->id]),
            ] : null,
            'skills' => $shift->skills->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'all_skills' => Skill::orderBy('name')->get(['id', 'name']),
            'building' => [
                'id' => $shift->building->id,
                'name' => $shift->building->name,
                'color' => $shift->building->color,
                'address' => $shift->building->fullAddress(),
                'contact' => trim($shift->building->contact_name.' '.$shift->building->contact_phone),
                'access' => $shift->building->access_instructions,
                'url' => route('buildings.show', $shift->building),
            ],
            'assignments' => $shift->assignments->map(fn ($a) => [
                'employee_id' => $a->employee_id,
                'name' => $a->employee->name,
                'phone' => $a->employee->phone,
                'color' => $a->employee->color,
                'source' => ShiftAssignment::SOURCES[$a->source] ?? $a->source,
                'warnings' => $warnings[$a->employee_id] ?? [],
            ])->values(),
        ];
    }
}
