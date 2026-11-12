<?php

namespace Tests;

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
}
