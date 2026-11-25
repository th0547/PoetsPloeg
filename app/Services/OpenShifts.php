<?php

namespace App\Services;

use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Diensten met open plekken. Gedeeld door de pagina Open diensten en de app.
 */
class OpenShifts
{
    public function __construct(private ShiftGenerator $generator, private FixedStaffCheck $fixedStaff) {}

    /**
     * Diensten met open plekken die nog niet zijn afgelopen, tot en met $weeks weken vooruit.
     *
     * @return Collection<int, Shift>
     */
    public function upcoming(int $weeks, ?int $buildingId = null): Collection
    {
        $today = CarbonImmutable::today();
        $until = $today->addWeeks($weeks);
        $this->generator->generate($today, $until);

        return Shift::query()->active()->understaffed()
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', $until->addDay())
            ->when($buildingId, fn ($q) => $q->where('building_id', $buildingId))
            ->with(['building', 'assignments.employee'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Waarom staat de plek open? Bij vaste taken: wat er met de vaste kracht is.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return array<int, array> [shift_id => FixedStaffCheck::missingOn()]
     */
    public function missing(Collection $shifts): array
    {
        return $shifts->mapWithKeys(fn (Shift $s) => [$s->id => $this->fixedStaff->missingOn($s)])->filter()->all();
    }
}
