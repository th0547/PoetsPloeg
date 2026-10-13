<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            // approved = geldt; pending = verlofverzoek in afwachting van vervanging; rejected = afgewezen verzoek.
            $table->string('status')->default('approved')->after('type');
            $table->timestamp('decided_at')->nullable()->after('note');
        });

        // Diensten waarvoor een verlofverzoek een vervanger nodig heeft.
        Schema::create('absence_shift', function (Blueprint $table) {
            $table->foreignId('absence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->primary(['absence_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_shift');
        Schema::table('absences', function (Blueprint $table) {
            $table->dropColumn(['status', 'decided_at']);
        });
    }
};
