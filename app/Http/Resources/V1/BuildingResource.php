<?php

namespace App\Http\Resources\V1;

use App\Models\Building;
use App\Support\Planning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Building */
class BuildingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->shortLabel(),
            'color' => $this->color,
            'ink' => Planning::contrast($this->color),
        ];
    }
}
