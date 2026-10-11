<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('color', 7)->default('#2563eb');
            $table->decimal('contract_hours_min', 5, 2)->nullable();
            $table->decimal('contract_hours_max', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('planner')->after('email');
            $table->foreignId('employee_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('employee_skill', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_id', 'skill_id']);
        });

        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('access_instructions')->nullable();
            $table->text('notes')->nullable();
            $table->string('color', 7)->default('#0d9488');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Pandkennis: medewerker is ingewerkt op dit pand.
        Schema::create('building_employee', function (Blueprint $table) {
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->primary(['building_id', 'employee_id']);
        });

        Schema::create('availability_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO: 1 = maandag ... 7 = zondag
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });

        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // verlof | ziek | overig
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'starts_at', 'ends_at']);
        });

        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('required_staff')->default(1);
            $table->string('recurrence'); // weekly | monthly_nth_weekday | monthly_day
            $table->unsignedSmallInteger('repeat_every')->default(1); // elke N weken / maanden
            $table->json('weekdays')->nullable(); // weekly: [1,3,5]
            $table->tinyInteger('nth')->nullable(); // monthly_nth_weekday: 1..4 of -1 (laatste)
            $table->unsignedTinyInteger('weekday')->nullable(); // monthly_nth_weekday
            $table->unsignedTinyInteger('day_of_month')->nullable(); // monthly_day
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('skip_holidays')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('skill_task_template', function (Blueprint $table) {
            $table->foreignId('task_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_template_id', 'skill_id']);
        });

        // Vaste bezetting per taaksjabloon.
        Schema::create('employee_task_template', function (Blueprint $table) {
            $table->foreignId('task_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->primary(['task_template_id', 'employee_id']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_template_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedTinyInteger('required_staff')->default(1);
            $table->string('title');
            $table->text('notes')->nullable();
            $table->boolean('is_modified')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['task_template_id', 'date']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('shift_skill', function (Blueprint $table) {
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['shift_id', 'skill_id']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('manual'); // fixed | manual | replacement
            $table->timestamps();
            $table->unique(['shift_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shift_skill');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('employee_task_template');
        Schema::dropIfExists('skill_task_template');
        Schema::dropIfExists('task_templates');
        Schema::dropIfExists('absences');
        Schema::dropIfExists('availability_patterns');
        Schema::dropIfExists('building_employee');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('employee_skill');
        Schema::dropIfExists('skills');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('employees');
    }
};
