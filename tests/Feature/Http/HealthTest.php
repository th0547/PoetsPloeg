<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_noemt_de_commit_niet(): void
    {
        config(['poetsploeg.commit' => 'abc123']);

        $this->getJson('/gezond')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['status' => 'ok', 'database' => 'ok']);
    }

    public function test_bevestigt_alleen_een_meegestuurde_commit(): void
    {
        config(['poetsploeg.commit' => 'abc123']);

        $this->getJson('/gezond?commit=abc123')->assertOk()->assertExactJson(['status' => 'ok', 'database' => 'ok', 'versie' => 'juist']);
        $this->getJson('/gezond?commit=def456')->assertOk()->assertExactJson(['status' => 'ok', 'database' => 'ok', 'versie' => 'anders']);
        $this->getJson('/gezond?commit=def456')->assertDontSee('abc123');
    }
}
