<?php

namespace Tests\Feature\Http;

use App\Models\Building;
use App\Models\Employee;
use App\Models\User;
use App\Services\RecurrenceRule;
use App\Support\RecurrenceAiPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpAiPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_explains_every_allowed_part_and_the_rules(): void
    {
        $prompt = RecurrenceAiPrompt::base();

        foreach (RecurrenceRule::PARTS as $part) {
            $this->assertStringContainsString($part, $prompt);
        }
        $this->assertStringContainsString('Geen uren of minuten', $prompt);
        $this->assertStringContainsString('FREQ=WEEKLY;INTERVAL=2;BYDAY=TH', $prompt);
        // De voorbeelden in de vraag zijn geldige codes.
        preg_match_all('/^- (FREQ=\S+) =/m', $prompt, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $code) {
            $this->assertTrue(RecurrenceRule::isValid($code), $code);
        }
    }

    public function test_help_page_shows_the_ai_buttons_without_any_app_data(): void
    {
        Building::factory()->create(['name' => 'Geheim Kantoorpand']);
        Employee::factory()->create(['name' => 'Anna Vertrouwelijk']);
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('help.recurrence'))->assertOk()
            ->assertSee('Open in Claude')->assertSee('Open in ChatGPT')->assertSee('Vraag kopiëren')
            ->assertSee('https://claude.ai/new?q=', false)
            ->assertSee('https://chatgpt.com/?q=', false)
            ->getContent();

        $this->assertStringNotContainsString('Geheim Kantoorpand', $html);
        $this->assertStringNotContainsString('Anna Vertrouwelijk', $html);
        // De vraag gaat als JSON naar Alpine.
        $this->assertMatchesRegularExpression('/x-data="aiPrompt\(JSON\.parse\(/', $html);
    }

    public function test_prompt_stays_short_enough_for_a_link(): void
    {
        $encoded = rawurlencode(RecurrenceAiPrompt::base()."\n\nWat ik wil: ".str_repeat('x', RecurrenceAiPrompt::MAX_WISH));

        $this->assertLessThan(4000, strlen($encoded));
    }
}
