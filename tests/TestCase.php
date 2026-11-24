<?php

namespace Tests;

use App\Models\Employee;
use App\Models\TaskTemplate;
use App\Support\Policy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Het beleid wordt per datum gecachet; elke test begint met een lege database.
        Policy::forget();
    }

    /** Vaste krachten op alle patronen van een taak zetten, in deze volgorde. */
    protected function assignFixed(TaskTemplate $template, Employee|int ...$employees): void
    {
        $ids = collect($employees)->map(fn ($e) => $e instanceof Employee ? $e->id : $e)->values();
        foreach ($template->patterns()->get() as $pattern) {
            $pattern->employees()->syncWithoutDetaching($ids->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])->all());
        }
        $template->unsetRelation('patterns');
    }

    /** Vaste krachten van een patroon (standaard het eerste) van een taak. */
    protected function fixedIds(TaskTemplate $template, int $pattern = 0): array
    {
        return $template->patterns()->get()->values()->get($pattern)?->employees()->pluck('employees.id')->all() ?? [];
    }
}
