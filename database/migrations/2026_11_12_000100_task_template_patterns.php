<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Een vaste taak kan meerdere herhalingspatronen hebben, elk met een eigen startpunt (anker),
 * eventueel eigen tijden en optioneel een eigen code (iCalendar RRULE).
 * Bestaande taken krijgen één patroon met hun huidige herhaling.
 */
return new class extends Migration
{
    private const OLD_COLUMNS = ['recurrence', 'repeat_every', 'weekdays', 'nth', 'weekday', 'day_of_month'];

    public function up(): void
    {
        Schema::create('task_template_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('recurrence'); // weekly | monthly_nth_weekday | monthly_day | rrule
            $table->unsignedSmallInteger('repeat_every')->default(1);
            $table->json('weekdays')->nullable();
            $table->tinyInteger('nth')->nullable();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('rrule')->nullable();
            $table->date('starts_on')->nullable(); // anker voor de telling; leeg = Vanaf van de taak
            $table->time('start_time')->nullable(); // leeg = tijden van de taak
            $table->time('end_time')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('task_templates')->orderBy('id')->each(function ($t) use ($now) {
            DB::table('task_template_patterns')->insert([
                'task_template_id' => $t->id,
                'position' => 0,
                'recurrence' => $t->recurrence,
                'repeat_every' => $t->repeat_every ?: 1,
                'weekdays' => $t->weekdays,
                'nth' => $t->nth,
                'weekday' => $t->weekday,
                'day_of_month' => $t->day_of_month,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn(self::OLD_COLUMNS);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('task_template_pattern_id')->nullable()->after('task_template_id')
                ->constrained('task_template_patterns')->nullOnDelete();
            // Eén dienst per patroon per dag (was: per taak per dag). Eerst een losse index voor de
            // foreign key op task_template_id: MySQL laat de oude unieke index anders niet los.
            $table->index('task_template_id');
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique(['task_template_id', 'date']);
            $table->unique(['task_template_pattern_id', 'date']);
        });

        // Bestaande diensten horen bij het enige patroon van hun taak.
        DB::table('task_template_patterns')->orderBy('id')->each(function ($p) {
            DB::table('shifts')->where('task_template_id', $p->task_template_id)->update(['task_template_pattern_id' => $p->id]);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique(['task_template_pattern_id', 'date']);
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_template_pattern_id');
            $table->unique(['task_template_id', 'date']);
            $table->dropIndex(['task_template_id']);
        });

        Schema::table('task_templates', function (Blueprint $table) {
            $table->string('recurrence')->default('weekly');
            $table->unsignedSmallInteger('repeat_every')->default(1);
            $table->json('weekdays')->nullable();
            $table->tinyInteger('nth')->nullable();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
        });

        // Terug naar één patroon: het eerste (een eigen code wordt "elke week op maandag").
        DB::table('task_template_patterns')->orderBy('position')->orderBy('id')->get()
            ->unique('task_template_id')
            ->each(function ($p) {
                $isCode = $p->recurrence === 'rrule';
                DB::table('task_templates')->where('id', $p->task_template_id)->update([
                    'recurrence' => $isCode ? 'weekly' : $p->recurrence,
                    'repeat_every' => $isCode ? 1 : $p->repeat_every,
                    'weekdays' => $isCode ? json_encode([1]) : $p->weekdays,
                    'nth' => $p->nth,
                    'weekday' => $p->weekday,
                    'day_of_month' => $p->day_of_month,
                ]);
            });

        Schema::dropIfExists('task_template_patterns');
    }
};
