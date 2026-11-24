<?php

namespace Tests\Unit;

use App\Models\Building;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Issue #74: korte pandnaam voor de maandweergave. */
class BuildingShortLabelTest extends TestCase
{
    public static function names(): array
    {
        return [
            ['Ferlin Plastic Automations', 'Ferlin'],
            ['Harmeling Interieurs', 'Harmeling'],
            ['JB Ventures', 'JBV'],
            ['Kippers Rijssen', 'Kippers'],
            ['Regge IT', 'Regge'],
            ['RAPIDprototyping', 'RAPID'],
            ['3D Shop Twente', '3DST'],
            ['De Linde', 'Linde'],
            ['Kringloop Rijssen-Holten', 'Kringloop'],
            ['Gemeentehuis', 'Gemeentehuis'],
        ];
    }

    #[DataProvider('names')]
    public function test_derives_a_short_label_without_cutting_words(string $name, string $expected): void
    {
        $this->assertSame($expected, (new Building(['name' => $name]))->shortLabel());
    }

    public function test_filled_short_name_wins(): void
    {
        $this->assertSame('Voortman', (new Building(['name' => 'Voortman Steel Machinery', 'short_name' => 'Voortman']))->shortLabel());
    }
}
