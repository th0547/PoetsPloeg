<?php

namespace App\Http\Resources\V1;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Een dienst in een lijst (app, api/v1). Details (contact, toegang, vaardigheden) via GET shifts/{id}.
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    /** @param  array<int, list<array>>  $warnings  waarschuwingen per medewerker (ConflictChecker::forShifts()[$shift->id]) */
    public function __construct(Shift $shift, private array $warnings = [])
    {
        parent::__construct($shift);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $now = now();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'date' => $this->starts_at->toDateString(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'start_time' => $this->starts_at->format('H:i'),
            'end_time' => $this->ends_at->format('H:i'),
            'required_staff' => $this->required_staff,
            'open_slots' => $this->openSlots(),
            'cancelled' => $this->cancelled_at !== null,
            'state' => $this->ends_at->lte($now) ? 'done' : ($this->starts_at->lte($now) ? 'now' : 'later'),
            'building' => $this->whenLoaded('building', fn () => BuildingResource::make($this->building)),
            'assignments' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn (ShiftAssignment $a) => [
                'employee' => EmployeeRef::make($a->employee),
                'source' => $a->source,
                'warnings' => $this->warnings[$a->employee_id] ?? [],
            ])->values()),
        ];
    }

    /** Lijst met waarschuwingen per dienst erbij. */
    public static function list(iterable $shifts, array $warnings = []): array
    {
        return collect($shifts)->map(fn (Shift $s) => new self($s, $warnings[$s->id] ?? []))->values()->all();
    }
}
