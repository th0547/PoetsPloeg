<?php

namespace Tests\Feature\Http;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 10:00');
        $this->actingAs(User::factory()->create());
        $this->building = Building::factory()->create();
    }

    private function taskData(array $patterns, array $extra = []): array
    {
        return $extra + [
            'name' => 'Archief',
            'start_time' => '18:00',
            'end_time' => '20:00',
            'required_staff' => 1,
            'valid_from' => '2026-11-13',
            'skip_holidays' => '1',
            'active' => '1',
            'patterns' => $patterns,
        ];
    }

    public function test_saves_several_patterns_with_own_start_and_times(): void
    {
        $this->post(route('buildings.templates.store', $this->building), $this->taskData([
            ['recurrence' => 'weekly', 'repeat_every' => 2, 'weekdays' => [4], 'starts_on' => '2026-11-16', 'own_times' => '0'],
            ['recurrence' => 'weekly', 'repeat_every' => 2, 'weekdays' => [1], 'starts_on' => '2026-11-23', 'own_times' => '1', 'start_time' => '07:00', 'end_time' => '09:00'],
            ['recurrence' => 'rrule', 'rrule' => 'freq=monthly;byday=-1fr', 'starts_on' => '2026-11-13'],
        ]))->assertRedirect(route('buildings.show', $this->building))->assertSessionHasNoErrors();

        $template = TaskTemplate::sole();
        $patterns = $template->patterns;
        $this->assertCount(3, $patterns);
        $this->assertNull($patterns[0]->start_time);
        $this->assertSame('07:00', substr($patterns[1]->start_time, 0, 5));
        $this->assertSame('FREQ=MONTHLY;BYDAY=-1FR', $patterns[2]->rrule, 'opgeslagen in genormaliseerde vorm');

        $first = Shift::orderBy('starts_at')->take(4)->get()->map(fn ($s) => $s->starts_at->format('Y-m-d H:i'))->all();
        $this->assertSame(['2026-11-19 18:00', '2026-11-23 07:00', '2026-11-27 18:00', '2026-12-03 18:00'], $first);

        $this->get(route('buildings.show', $this->building))->assertOk()
            ->assertSee('Om de week op do')
            ->assertSee('Eigen patroon: elke maand op de laatste vrijdag');
        $this->get(route('buildings.templates.edit', [$this->building, $template]))->assertOk()->assertSee('FREQ=MONTHLY;BYDAY=-1FR');
    }

    public function test_editing_patterns_rebuilds_future_shifts(): void
    {
        $template = TaskTemplate::factory()->weekly([1])->create(['building_id' => $this->building->id, 'valid_from' => '2026-11-01']);
        app(ShiftGenerator::class)->generateAhead();
        $this->assertTrue(Shift::whereDate('date', '2026-11-16')->exists());

        $this->put(route('buildings.templates.update', [$this->building, $template]), $this->taskData([
            ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [2]],
        ], ['valid_from' => '2026-11-01']))->assertSessionHasNoErrors();

        $this->assertFalse(Shift::whereDate('date', '2026-11-16')->exists());
        $this->assertTrue(Shift::whereDate('date', '2026-11-17')->exists());
        $this->assertSame(1, $template->patterns()->count());
    }

    public function test_patterns_are_required_and_limited(): void
    {
        $this->post(route('buildings.templates.store', $this->building), $this->taskData([]))->assertSessionHasErrors('patterns');
        $eleven = array_fill(0, 11, ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1]]);
        $this->post(route('buildings.templates.store', $this->building), $this->taskData($eleven))->assertSessionHasErrors('patterns');
        $this->post(route('buildings.templates.store', $this->building), $this->taskData([
            ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => []],
            ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1], 'own_times' => '1'],
        ]))->assertSessionHasErrors(['patterns.0.weekdays', 'patterns.1.start_time', 'patterns.1.end_time']);
        $this->assertSame(0, TaskTemplate::count());
    }

    /** @return list<string> */
    private static function maliciousCodes(): array
    {
        return [
            "FREQ=WEEKLY;BYDAY=TH');alert(1);('",
            '<script>alert(1)</script>',
            'FREQ=WEEKLY"onmouseover=alert(1)',
            "'}); alert(document.cookie); ({'",
            'FREQ=DAILY;BYHOUR=9',
        ];
    }

    public function test_malicious_codes_are_rejected_and_never_stored(): void
    {
        foreach (self::maliciousCodes() as $code) {
            $this->post(route('buildings.templates.store', $this->building), $this->taskData([
                ['recurrence' => 'rrule', 'rrule' => $code],
            ]))->assertSessionHasErrors('patterns.0.rrule');
        }
        $this->assertSame(0, DB::table('task_template_patterns')->count());
    }

    public function test_old_input_is_only_handed_to_alpine_as_json(): void
    {
        $code = "FREQ=WEEKLY;BYDAY=TH');alert(1);('<script>x</script>";
        $this->from(route('buildings.templates.create', $this->building))
            ->post(route('buildings.templates.store', $this->building), $this->taskData([['recurrence' => 'rrule', 'rrule' => $code]]))
            ->assertRedirect(route('buildings.templates.create', $this->building));

        $html = $this->get(route('buildings.templates.create', $this->building))->assertOk()->getContent();

        // De ruwe invoer staat nergens letterlijk in de pagina: niet als tag en niet als JavaScript.
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringNotContainsString("');alert(1);('", $html);
        $this->assertStringNotContainsString('&#039;);alert(1);(&#039;', $html, 'HTML-escaping alleen is binnen x-data niet genoeg');
        // Wel: als JSON-string in x-data (via @js), met gecodeerde quotes en hoekhaken.
        $this->assertMatchesRegularExpression('/x-data="recurrenceEditor\(JSON\.parse\(/', $html);
        $this->assertStringContainsString('\\u0027', $html);
        $this->assertStringContainsString('\\u003C', $html);
    }

    public function test_preview_returns_dates_and_safe_errors(): void
    {
        $this->postJson(route('api.recurrence.preview'), [
            'rrule' => 'freq=weekly;interval=2;byday=th', 'starts_on' => '2026-11-16', 'from' => '2026-11-01', 'to' => '2026-12-10',
        ])->assertOk()
            ->assertJsonPath('rrule', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TH')
            ->assertJsonPath('label', 'om de week op donderdag')
            ->assertJsonPath('dates', ['2026-11-19', '2026-12-03']);

        foreach (self::maliciousCodes() as $code) {
            $response = $this->postJson(route('api.recurrence.preview'), [
                'rrule' => $code, 'starts_on' => '2026-11-16', 'from' => '2026-11-01', 'to' => '2026-12-10',
            ])->assertStatus(422);
            $this->assertStringNotContainsString('alert', $response->getContent());
            $this->assertStringNotContainsString('<script', $response->getContent());
        }
    }

    public function test_preview_and_help_are_for_planners_only(): void
    {
        $this->get(route('help.recurrence'))->assertOk()->assertSee('Eigen herhaling met een code')->assertSee('FREQ=MONTHLY;BYDAY=-1FR');

        $this->actingAs(User::factory()->create(['role' => 'medewerker', 'employee_id' => Employee::factory()->create()->id]));
        $this->get(route('help.recurrence'))->assertForbidden();
        $this->postJson(route('api.recurrence.preview'), ['rrule' => 'FREQ=DAILY', 'starts_on' => '2026-11-16', 'from' => '2026-11-16', 'to' => '2026-11-20'])->assertForbidden();
    }

    public function test_migration_turns_old_templates_into_one_pattern(): void
    {
        $migration = require database_path('migrations/2026_11_12_000100_task_template_patterns.php');
        $migration->down();

        $id = DB::table('task_templates')->insertGetId([
            'building_id' => $this->building->id, 'name' => 'Oud', 'start_time' => '18:00', 'end_time' => '20:00',
            'required_staff' => 1, 'recurrence' => 'weekly', 'repeat_every' => 4, 'weekdays' => json_encode([2]),
            'valid_from' => '2026-10-06', 'skip_holidays' => true, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shifts')->insert([
            'building_id' => $this->building->id, 'task_template_id' => $id, 'date' => '2026-10-06',
            'starts_at' => '2026-10-06 18:00:00', 'ends_at' => '2026-10-06 20:00:00', 'required_staff' => 1, 'title' => 'Oud',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $template = TaskTemplate::find($id);
        $this->assertSame('Elke 4 weken op di', $template->recurrenceLabel());
        $this->assertSame($template->patterns->first()->id, Shift::sole()->task_template_pattern_id);
        $this->assertTrue($template->occursOn(CarbonImmutable::parse('2026-11-03')));
        $this->assertFalse($template->occursOn(CarbonImmutable::parse('2026-10-13')));
    }
}
