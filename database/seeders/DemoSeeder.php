<?php

namespace Database\Seeders;

use App\Models\Absence;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Skill;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demodata: 15 panden, 50 medewerkers, vaste taken met vaste bezetting en wat verlof.
 */
class DemoSeeder extends Seeder
{
    /** @var array<int, list<array{0:int,1:int,2:int}>> bezette blokken per medewerker: [weekdag, start, eind] in minuten */
    private array $busy = [];

    /** @var array<int, float> */
    private array $hours = [];

    public function run(): void
    {
        fake()->seed(42);
        mt_srand(42);

        User::firstOrCreate(['email' => 'planner@novon.test'], [
            'name' => 'Demo Planner', 'password' => 'password', 'role' => 'planner',
        ]);
        User::firstOrCreate(['email' => 'teamleider@novon.test'], [
            'name' => 'Demo Teamleider', 'password' => 'password', 'role' => 'teamleider',
        ]);

        $skills = collect(['Algemeen', 'Sanitair', 'Glasbewassing', 'Vloeronderhoud', 'Hoogwerker', 'Keuken/pantry'])
            ->mapWithKeys(fn ($name) => [$name => Skill::firstOrCreate(['name' => $name])]);

        $names = ['Kantoor De Linde', 'Gemeentehuis', 'Zorgcentrum Het Anker', 'Bedrijfspand Noord', 'Basisschool De Regenboog',
            'Huisartsenpraktijk Oost', 'Advocatenkantoor Van Dam', 'Sportcentrum De Vaart', 'Bibliotheek Centrum', 'Tandartspraktijk Smile',
            'Kinderopvang Klein Duimpje', 'Showroom AutoPlaza', 'Notariskantoor Jansen', 'Fysiotherapie Fit', 'Kerkgebouw De Hoeksteen'];
        $palette = ['#0d9488', '#2563eb', '#9333ea', '#db2777', '#ea580c', '#65a30d', '#0891b2', '#4f46e5', '#ca8a04', '#dc2626',
            '#059669', '#7c3aed', '#c026d3', '#0284c7', '#b45309'];

        $buildings = collect($names)->map(fn ($name, $i) => Building::factory()->create([
            'name' => $name,
            'color' => $palette[$i],
            'city' => fake()->randomElement(['Zwolle', 'Kampen', 'Deventer', 'Apeldoorn']),
            'access_instructions' => 'Sleutel in kluisje bij de achteringang. Alarmcode: zie map op kantoor.',
        ]));

        $employees = Employee::factory()->count(50)->create()->each(function (Employee $e, $i) use ($skills) {
            $max = fake()->randomElement([12, 16, 20, 24, 32]);
            $e->update(['contract_hours_min' => max(4, $max - 8), 'contract_hours_max' => $max]);
            $e->skills()->attach($skills['Algemeen']);
            $e->skills()->attach(collect($skills)->except('Algemeen')->random(mt_rand(0, 3))->pluck('id'));
            $this->busy[$e->id] = [];
            $this->hours[$e->id] = 0;

            // Weekpatroon: de meesten werken 's avonds, een deel 's ochtends.
            $evening = $i % 4 !== 0;
            $days = $i % 5 === 0 ? [1, 2, 3, 4, 5, 6] : [1, 2, 3, 4, 5];
            foreach ($days as $d) {
                $e->availabilityPatterns()->create($evening
                    ? ['weekday' => $d, 'start_time' => '16:00', 'end_time' => '23:00']
                    : ['weekday' => $d, 'start_time' => '05:30', 'end_time' => '12:00']);
            }
        });

        $slots = [['17:00', '19:00'], ['18:00', '20:30'], ['19:00', '21:00'], ['06:00', '08:00'], ['20:00', '22:00']];
        $validFrom = CarbonImmutable::today()->startOfWeek()->subWeeks(1);

        foreach ($buildings as $i => $building) {
            [$start, $end] = $slots[$i % count($slots)];
            $staff = [1, 2, 2, 3, 1][$i % 5];
            $days = $i % 3 === 0 ? [1, 2, 3, 4, 5] : ($i % 3 === 1 ? [1, 3, 5] : [2, 4]);

            $daily = TaskTemplate::create([
                'building_id' => $building->id, 'name' => 'Dagelijkse schoonmaak',
                'description' => 'Stofzuigen, sanitair, pantry, prullenbakken legen.',
                'start_time' => $start, 'end_time' => $end, 'required_staff' => $staff,
                'recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => $days,
                'valid_from' => $validFrom, 'skip_holidays' => true, 'active' => true,
            ]);
            $daily->skills()->attach([$skills['Algemeen']->id, $skills['Sanitair']->id]);
            $daily->load('skills');
            $this->assignFixed($daily, $employees, $building, $days, $start, $end, $staff);

            if ($i % 2 === 0) {
                $glass = TaskTemplate::create([
                    'building_id' => $building->id, 'name' => 'Glasbewassing',
                    'start_time' => '08:00', 'end_time' => '12:00', 'required_staff' => 1,
                    'recurrence' => 'weekly', 'repeat_every' => 4, 'weekdays' => [($i % 5) + 1],
                    'valid_from' => $validFrom->addDays($i % 14), 'skip_holidays' => true, 'active' => true,
                ]);
                $glass->skills()->attach($skills['Glasbewassing']->id);
            }

            if ($i % 3 === 1) {
                $floor = TaskTemplate::create([
                    'building_id' => $building->id, 'name' => 'Vloeronderhoud (periodiek)',
                    'start_time' => '09:00', 'end_time' => '13:00', 'required_staff' => 2,
                    'recurrence' => 'monthly_nth_weekday', 'repeat_every' => 1, 'nth' => ($i % 4) + 1, 'weekday' => 6,
                    'valid_from' => $validFrom, 'skip_holidays' => true, 'active' => true,
                ]);
                $floor->skills()->attach($skills['Vloeronderhoud']->id);
            }
        }

        // Wat verlof en een zieke medewerker.
        $today = CarbonImmutable::today();
        foreach ($employees->random(4) as $k => $e) {
            $start = $today->addDays(3 + $k * 4);
            Absence::create([
                'employee_id' => $e->id, 'type' => 'verlof',
                'starts_at' => $start, 'ends_at' => $start->addDays(6)->endOfDay()->startOfMinute(), 'note' => 'Vakantie',
            ]);
        }

        app(ShiftGenerator::class)->generateAhead();

        // Ziekmelding vandaag: wordt via de AbsenceService verwerkt zodat er open diensten ontstaan.
        $sick = $employees->first(fn ($e) => $e->fixedTemplates()->exists());
        if ($sick) {
            $absence = Absence::create([
                'employee_id' => $sick->id, 'type' => 'ziek',
                'starts_at' => $today, 'ends_at' => $today->addDays(2)->endOfDay()->startOfMinute(), 'note' => 'Griep',
            ]);
            app(\App\Services\AbsenceService::class)->apply($absence);
        }
    }

    private function assignFixed(TaskTemplate $template, $employees, Building $building, array $days, string $start, string $end, int $staff): void
    {
        $s = $this->minutes($start);
        $e = $this->minutes($end);
        $weekly = count($days) * ($e - $s) / 60;
        $picked = 0;

        foreach ($employees->shuffle() as $employee) {
            if ($picked >= $staff) {
                break;
            }
            $fitsPattern = $employee->availabilityPatterns->where('weekday', $days[0])
                ->contains(fn ($p) => $this->minutes($p->start_time) <= $s && $this->minutes($p->end_time) >= $e);
            $free = collect($this->busy[$employee->id])->doesntContain(fn ($b) => in_array($b[0], $days) && $b[1] < $e && $b[2] > $s);
            if (! $fitsPattern || ! $free || $this->hours[$employee->id] + $weekly > $employee->contract_hours_max) {
                continue;
            }

            $template->employees()->attach($employee->id, ['position' => $picked]);
            $employee->skills()->syncWithoutDetaching($template->skills->pluck('id'));
            $building->employees()->syncWithoutDetaching([$employee->id]);
            foreach ($days as $d) {
                $this->busy[$employee->id][] = [$d, $s, $e];
            }
            $this->hours[$employee->id] += $weekly;
            $picked++;
        }

        // Een paar extra collega's kennen het pand ook (potentiële invallers).
        $building->employees()->syncWithoutDetaching($employees->random(3)->pluck('id'));
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}
