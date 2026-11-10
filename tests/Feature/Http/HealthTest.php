<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_geeft_commit_en_databasestatus_zonder_login(): void
    {
        config(['poetsploeg.commit' => 'abc123']);

        $this->getJson('/gezond')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['status' => 'ok', 'commit' => 'abc123', 'database' => 'ok']);
    }
}
