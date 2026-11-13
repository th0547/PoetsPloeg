<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weekpatroon voor een periode, bijv. het rooster van een schoolblok.
        Schema::table('availability_patterns', function (Blueprint $table) {
            $table->date('valid_from')->nullable()->after('starts_on');
            $table->date('valid_until')->nullable()->after('valid_from');
        });

        // Beschikbaarheid op één datum: vervangt die dag het weekpatroon.
        Schema::create('availability_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('type'); // available | unavailable
            $table->time('start_time');
            $table->time('end_time'); // <= start_time: tot middernacht (00:00–00:00 = hele dag)
            $table->string('note', 100)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_days');
        Schema::table('availability_patterns', function (Blueprint $table) {
            $table->dropColumn(['valid_from', 'valid_until']);
        });
    }
};
