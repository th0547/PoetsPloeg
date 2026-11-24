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
use Illuminate\Support\Facades\Schema;
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

    /** Formulierdata; patronen zonder tijden krijgen 18:00–20:00. */
    private function taskData(array $patterns, array $extra = []): array
    {
        return $extra + [
            'name' => 'Archief',
            'required_staff' => 1,
            'valid_from' => '2026-11-13',
            'skip_holidays' => '1',
            'active' => '1',
            'patterns' => array_map(fn (array $p) => $p + ['start_time' => '18:00', 'end_time' => '20:00'], $patterns),
        ];
    }

    public function test_saves_several_patterns_with_own_start_and_times(): void
    {
        $this->post(route('buildings.templates.store', $this->building), $this->taskData([
            ['recurrence' => 'weekly', 'repeat_every' => 2, 'weekdays' => [4], 'starts_on' => '2026-11-16'],
            ['recurrence' => 'weekly', 'repeat_every' => 2, 'weekdays' => [1], 'starts_on' => '2026-11-23', 'start_time' => '07:00', 'end_time' => '09:00'],
            ['recurrence' => 'rrule', 'rrule' => 'freq=monthly;byday=-1fr', 'starts_on' => '2026-11-13'],
        ]))->assertRedirect(route('buildings.show', $this->building))->assertSessionHasNoErrors();

        $template = TaskTemplate::sole();
        $patterns = $template->patterns;
        $this->assertCount(3, $patterns);
        $this->assertSame('18:00', substr($patterns[0]->start_time, 0, 5));
        $this->assertSame('07:00', substr($patterns[1]->start_time, 0, 5));
        $this->assertSame('18:00', substr($template->start_time, 0, 5), 'de taak volgt het eerste patroon');
        $this->assertSame('FREQ=MONTHLY;BYDAY=-1FR', $patterns[2]->rrule, 'opgeslagen in genormaliseerde vorm');

        $first = Shift::orderBy('starts_at')->take(4)->get()->map(fn ($s) => $s->starts_at->format('Y-m-d H:i'))->all();
        $this->assertSame(['2026-11-19 18:00', '2026-11-23 07:00', '2026-11-27 18:00', '2026-12-03 18:00'], $first);

        $this->get(route('buildings.show', $this->building))->assertOk()
            ->assertSee('Om de week op do')
            ->assertSee('Om de week op ma')
            ->assertSee('07:00–09:00')
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

    public function test_each_pattern_has_its_own_times_and_fixed_staff(): void
    {
        // "Om de dag": ma, wo en vr Henk van 10 tot 12, di en do Jan van 12 tot 13.
        [$henk, $jan] = [Employee::factory()->create(['name' => 'Henk']), Employee::factory()->create(['name' => 'Jan'])];
        $this->post(route('buildings.templates.store', $this->building), $this->taskData([
            ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1, 3, 5], 'start_time' => '10:00', 'end_time' => '12:00', 'employees' => [$henk->id]],
            ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [2, 4], 'start_time' => '12:00', 'end_time' => '13:00', 'employees' => [$jan->id]],
        ], ['valid_from' => '2026-11-16']))->assertSessionHasNoErrors();

        $template = TaskTemplate::sole();
        $this->assertSame([$henk->id], $this->fixedIds($template, 0));
        $this->assertSame([$jan->id], $this->fixedIds($template, 1));
        $week = Shift::with('assignments')->whereDate('date', '>=', '2026-11-16')->whereDate('date', '<=', '2026-11-20')->orderBy('starts_at')->get()
            ->map(fn (Shift $s) => $s->starts_at->format('D H:i').' '.$s->assignments->pluck('employee_id')->implode(','))->all();
        $this->assertSame([
            "Mon 10:00 {$henk->id}", "Tue 12:00 {$jan->id}", "Wed 10:00 {$henk->id}", "Thu 12:00 {$jan->id}", "Fri 10:00 {$henk->id}",
        ], $week);
        // Wie vast op een taak staat, kent het pand (issue #70).
        $this->assertEqualsCanonicalizing([$henk->id, $jan->id], $this->building->employees()->pluck('employees.id')->all());

        $this->get(route('buildings.show', $this->building))->assertOk()
            ->assertSee('Elke week op ma, wo, vr')->assertSee('10:00–12:00')->assertSee('Henk')
            ->assertSee('Elke week op di, do')->assertSee('12:00–13:00')->assertSee('Jan');
        $this->get(route('employees.show', $jan))->assertOk()->assertSee('Elke week op di, do 12:00–13:00')->assertDontSee('Elke week op ma, wo, vr');
        $this->get(route('buildings.templates.edit', [$this->building, $template]))->assertOk();
    }

    public function test_editing_keeps_pattern_ids_and_manually_changed_shifts(): void
    {
        $henk = Employee::factory()->create();
        $template = TaskTemplate::factory()->weekly([1])->weekly([3], 1, ['position' => 1])
            ->create(['building_id' => $this->building->id, 'valid_from' => '2026-11-01']);
        [$monday, $wednesday] = $template->patterns->values()->all();
        app(ShiftGenerator::class)->generateAhead();
        $changed = Shift::where('task_template_pattern_id', $wednesday->id)->whereDate('date', '2026-11-18')->sole();
        $changed->update(['is_modified' => true, 'title' => 'Aangepast']);

        // Maandag naar dinsdag, met Henk erop; woensdag blijft.
        $this->put(route('buildings.templates.update', [$this->building, $template]), $this->taskData([
            ['id' => $monday->id, 'recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [2], 'employees' => [$henk->id]],
            ['id' => $wednesday->id, 'recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [3]],
        ], ['valid_from' => '2026-11-01']))->assertSessionHasNoErrors();

        $this->assertSame([$monday->id, $wednesday->id], $template->patterns()->pluck('id')->all());
        $this->assertSame($wednesday->id, $changed->fresh()->task_template_pattern_id);
        $this->assertSame(1, Shift::whereDate('date', '2026-11-18')->count(), 'geen tweede dienst naast de aangepaste');
        $this->assertSame([$henk->id], Shift::whereDate('date', '2026-11-17')->sole()->assignments()->pluck('employee_id')->all());
    }

    public function test_patterns_are_required_and_limited(): void
    {
        $this->post(route('buildings.templates.store', $this->building), $this->taskData([]))->assertSessionHasErrors('patterns');
        $eleven = array_fill(0, 11, ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1]]);
        $this->post(route('buildings.templates.store', $this->building), $this->taskData($eleven))->assertSessionHasErrors('patterns');
        $this->post(route('buildings.templates.store', $this->building), $this->taskData([
            ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => []],
            ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1], 'start_time' => '', 'end_time' => ''],
            ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [2], 'start_time' => '09:00', 'end_time' => '09:00'],
        ]))->assertSessionHasErrors(['patterns.0.weekdays', 'patterns.1.start_time', 'patterns.1.end_time', 'patterns.2.end_time']);
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

    public function test_migration_moves_fixed_staff_to_every_pattern(): void
    {
        $migration = require database_path('migrations/2026_11_24_000100_staff_and_times_per_pattern.php');
        $migration->down();

        $template = TaskTemplate::factory()->weekly([1])->weekly([4], 1, ['position' => 1, 'start_time' => '07:00', 'end_time' => '09:00'])
            ->create(['building_id' => $this->building->id, 'start_time' => '18:00', 'end_time' => '20:00']);
        [$anna, $bram] = Employee::factory()->count(2)->create();
        DB::table('employee_task_template')->insert([
            ['task_template_id' => $template->id, 'employee_id' => $anna->id, 'position' => 0],
            ['task_template_id' => $template->id, 'employee_id' => $bram->id, 'position' => 1],
        ]);

        $migration->up();

        $template->refresh()->load('patterns.employees');
        $this->assertSame(['18:00', '07:00'], $template->patterns->map(fn ($p) => substr($p->start_time, 0, 5))->all());
        foreach ($template->patterns as $pattern) {
            $this->assertSame([$anna->id, $bram->id], $pattern->employees->pluck('id')->all());
        }
        // Issue #70: vaste krachten kennen het pand.
        $this->assertEqualsCanonicalizing([$anna->id, $bram->id], $this->building->employees()->pluck('employees.id')->all());
        $this->assertFalse(Schema::hasTable('employee_task_template'));
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
        $this->assertSame('Elke 4 weken op di 18:00–20:00', $template->recurrenceLabel());
        $this->assertSame($template->patterns->first()->id, Shift::sole()->task_template_pattern_id);
        $this->assertTrue($template->occursOn(CarbonImmutable::parse('2026-11-03')));
        $this->assertFalse($template->occursOn(CarbonImmutable::parse('2026-10-13')));
    }
}
