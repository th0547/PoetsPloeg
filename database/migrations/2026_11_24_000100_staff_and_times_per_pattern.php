<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tijden en vaste bezetting horen voortaan bij het patroon, niet bij de taak:
 * "ma, wo, vr 10:00–12:00 Henk" en "di, do 12:00–13:00 Jan" in één taak.
 *
 * - Patronen zonder eigen tijden krijgen de tijden van hun taak.
 * - Vaste krachten van een taak komen op alle patronen van die taak; voor bestaande taken verandert er dus niets.
 * - Wie vast op een taak staat, kent het pand (issue #70: via het taakformulier gebeurde dat niet).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_templates')->orderBy('id')->each(function ($t) {
            DB::table('task_template_patterns')
                ->where('task_template_id', $t->id)
                ->where(fn ($q) => $q->whereNull('start_time')->orWhereNull('end_time'))
                ->update(['start_time' => $t->start_time, 'end_time' => $t->end_time]);
        });

        Schema::create('employee_task_template_pattern', function (Blueprint $table) {
            $table->foreignId('task_template_pattern_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->primary(['task_template_pattern_id', 'employee_id'], 'employee_pattern_primary');
        });

        $rows = DB::table('employee_task_template')
            ->join('task_templates', 'task_templates.id', '=', 'employee_task_template.task_template_id')
            ->get(['employee_task_template.task_template_id', 'employee_task_template.employee_id', 'employee_task_template.position', 'task_templates.building_id']);
        $patterns = DB::table('task_template_patterns')->get(['id', 'task_template_id'])->groupBy('task_template_id');
        foreach ($rows as $row) {
            foreach ($patterns[$row->task_template_id] ?? [] as $pattern) {
                DB::table('employee_task_template_pattern')->insertOrIgnore([
                    'task_template_pattern_id' => $pattern->id,
                    'employee_id' => $row->employee_id,
                    'position' => $row->position,
                ]);
            }
            DB::table('building_employee')->insertOrIgnore(['building_id' => $row->building_id, 'employee_id' => $row->employee_id]);
        }

        Schema::dropIfExists('employee_task_template');
    }

    public function down(): void
    {
        Schema::create('employee_task_template', function (Blueprint $table) {
            $table->foreignId('task_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->primary(['task_template_id', 'employee_id']);
        });

        // Terug naar één bezetting per taak: iedereen die op een van de patronen stond.
        DB::table('employee_task_template_pattern')
            ->join('task_template_patterns', 'task_template_patterns.id', '=', 'employee_task_template_pattern.task_template_pattern_id')
            ->orderBy('employee_task_template_pattern.position')
            ->get(['task_template_patterns.task_template_id', 'employee_task_template_pattern.employee_id', 'employee_task_template_pattern.position'])
            ->each(fn ($row) => DB::table('employee_task_template')->insertOrIgnore([
                'task_template_id' => $row->task_template_id,
                'employee_id' => $row->employee_id,
                'position' => $row->position,
            ]));

        Schema::dropIfExists('employee_task_template_pattern');
    }
};
