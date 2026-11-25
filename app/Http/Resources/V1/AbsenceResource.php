<?php

namespace App\Http\Resources\V1;

use App\Models\Absence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Absence */
class AbsenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => EmployeeRef::make($this->employee)),
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'title' => $this->isPending() ? $this->requestLabel() : $this->typeLabel(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'period' => $this->periodLabel(),
            'note' => $this->note,
            'shift_count' => $this->whenCounted('shifts'),
        ];
    }
}
