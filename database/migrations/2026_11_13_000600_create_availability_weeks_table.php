<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Beschikbaarheid per week bevestigen (issue #45). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('week_start'); // maandag
            $table->timestamp('mailed_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->decimal('available_hours', 6, 2)->nullable(); // bij bevestigen
            $table->text('explanation')->nullable(); // verplicht onder de contracturen
            $table->string('other_employer')->nullable(); // cao art. 14 lid 2
            $table->boolean('changed_after_confirm')->default(false);
            $table->timestamps();
            $table->unique(['employee_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_weeks');
    }
};
