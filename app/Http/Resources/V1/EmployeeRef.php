<?php

namespace App\Http\Resources\V1;

use App\Models\Employee;
use App\Support\Planning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Korte verwijzing naar een medewerker (naam, kleur), zonder persoonsgegevens.
 *
 * @mixin Employee
 */
class EmployeeRef extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'ink' => Planning::contrast($this->color),
            'initials' => Planning::initials($this->name),
        ];
    }
}
