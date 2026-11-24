<?php

namespace Tests\Feature\Http;

use App\Models\Building;
use App\Models\Employee;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Issue #71: het telefoonveld accepteert alleen een telefoonnummer. */
class PhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    private function buildingData(string $phone): array
    {
        return ['name' => 'Voortman Steel Machinery', 'color' => '#336699', 'active' => '1', 'contact_name' => 'Jan', 'contact_phone' => $phone];
    }

    public function test_building_and_employee_reject_text_as_phone_number(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'teamleider']));
        $building = Building::factory()->create();
        $employee = Employee::factory()->create();

        $this->put(route('buildings.update', $building), $this->buildingData('Voortman'))
            ->assertSessionHasErrors(['contact_phone' => 'Vul een telefoonnummer in, bijv. 06 12345678.']);
        $this->put(route('employees.update', $employee), ['name' => $employee->name, 'color' => '#336699', 'active' => '1', 'phone' => 'Voortman'])
            ->assertSessionHasErrors('phone');

        foreach (['+31 6 1234 5678', '(026) 7266084', '06-12345678'] as $phone) {
            $this->put(route('buildings.update', $building), $this->buildingData($phone))->assertSessionHasNoErrors();
            $this->assertSame($phone, $building->fresh()->contact_phone);
        }
    }

    public function test_only_a_real_number_becomes_a_call_link(): void
    {
        $this->assertSame('tel:+31612345678', Phone::href('+31 6 1234 5678'));
        $this->assertSame('tel:0267266084', Phone::href('(026) 7266084'));
        $this->assertNull(Phone::href('Voortman'));
        $this->assertNull(Phone::href('12345'));
        $this->assertNull(Phone::href(null));

        // Een oude waarde die geen nummer is: wel tonen, geen bel-link.
        $this->actingAs(User::factory()->create());
        $building = Building::factory()->create(['contact_phone' => 'Voortman']);
        $this->get(route('buildings.show', $building))->assertOk()->assertSee('Voortman')->assertDontSee('tel:Voortman');
    }
}
